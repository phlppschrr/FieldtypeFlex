<?php namespace ProcessWire;

/** Thin native CliModule adapter. @internal */
final class FlexMaintenanceCli {
	private FieldtypeFlex $type;

	public function __construct(FieldtypeFlex $type) { $this->type = $type; }

	public static function help(): string {
		return <<<'HELP'
Flex inspection and data maintenance (one bounded batch by default)
php index.php flex schema FIELD [--page=ID] [--json]
php index.php flex lint FIELD [--page=ID] [--json]
php index.php flex status [--json]
php index.php flex check FIELD [--page=ID | --all] [--limit=100] [--after=0] [--json]
php index.php flex rename FIELD [--dry-run | --apply] [--page=ID | --all] [--limit=100] [--after=0] [--json]
php index.php flex orphans FIELD [--details] [--page=ID | --all] [--limit=100] [--after=0] [--json]
php index.php flex cleanup FIELD --path=old_summary [--path=blocks.text.old] [--dry-run | --apply] [--page=ID | --all] [--limit=100] [--after=0] [--json]

Schema uses discovery/NullPage unless --page selects an exact Page context.
Unknown properties are only POTENTIALLY orphaned: dynamic definitions can omit them intentionally.
Paths identify typed schema properties, never concrete row IDs. Cleanup requires explicit unique paths.
Without --apply handlers make no content changes. Apply checks a fresh preview under lock.
The independent automatic module lifecycle can collect eligible media at process completion.
--limit=1..1000 bounds each batch. --after is a nonnegative page-ID cursor.
--page requires an existing owner with field assignment; absent storage is a regular empty result.
--page excludes --all, --after and --limit. Hidden/unpublished/Repeater owners are included.
--all permits --after, streams batches and stops after the first failed batch; earlier writes remain.
Repair failures and resume with resumeAfterPageId (retryPageIds identify failures).
finished covers only the selected range; scans are not global snapshots.
Remove renamedFrom only after a complete successful scan from zero finds no pending renames.
Existing rename/orphans/cleanup output remains JSON. New commands default to readable text.
--json produces one JSON object; --all --json produces JSON-Lines batch records and a final summary.
--all without --json prints batch JSON for existing commands, text for check, then a summary.
Progress goes to stderr; --details adds orphan page IDs and concrete addresses per batch.
Exit: 0 success (check and lint warnings allowed), 1 findings/errors/conflicts, 2 invalid request/adapter failure,
3 incomplete check without known errors. Check never repairs; see API.md for inspection boundaries.
HELP;
	}

	public function run(array $args): int {
		try {
			if(in_array($args, [[], ['help'], ['--help'], ['-h']], true)) { echo self::help(); return 0; }
			$operation = array_shift($args);
			if(!in_array($operation, ['rename', 'orphans', 'cleanup', 'check', 'schema', 'lint', 'status'], true)) throw new WireException('Unknown Flex command');
			$name = $operation === 'status' ? null : array_shift($args);
			if($name !== null && (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name))) throw new WireException('Specify a Flex field name');
			if($operation !== 'status' && $name === null) throw new WireException('Specify a Flex field name');
			$options = []; $paths = [];
			$allowed = ['json'];
			if(in_array($operation, ['schema', 'lint'], true)) $allowed[] = 'page';
			if(!in_array($operation, ['schema', 'lint', 'status'], true)) $allowed = array_merge($allowed, ['page', 'all', 'limit', 'after']);
			if(in_array($operation, ['rename', 'cleanup'], true)) $allowed = array_merge($allowed, ['apply', 'dry-run']);
			if($operation === 'orphans') $allowed[] = 'details';
			foreach($args as $arg) {
				if($operation === 'cleanup' && preg_match('/^--path=([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)$/D', $arg, $m)) {
					if(in_array($m[1], $paths, true)) throw new WireException('Duplicate cleanup path');
					$paths[] = $m[1]; continue;
				}
				if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m) || !in_array($m[1], $allowed, true)) throw new WireException('Unknown or inapplicable argument');
				$key = $m[1];
				if(isset($options[$key])) throw new WireException('Duplicate option: ' . $key);
				if(in_array($key, ['page', 'limit', 'after'], true)) {
					if(!isset($m[2]) || !preg_match('/^(0|[1-9][0-9]*)$/D', $m[2]) || filter_var($m[2], FILTER_VALIDATE_INT) === false) throw new WireException('Invalid numeric option: ' . $key);
					$value = (int) $m[2];
					if(($key !== 'after' && $value < 1) || ($key === 'limit' && $value > 1000)) throw new WireException('Numeric option out of range: ' . $key);
					$options[$key] = $value;
				} else {
					if(isset($m[2])) throw new WireException('Flag does not accept a value');
					$options[$key] = true;
				}
			}
			if(isset($options['apply'], $options['dry-run']) || (isset($options['page']) && array_intersect(['all', 'after', 'limit'], array_keys($options)))) throw new WireException('Contradictory options');
			if($operation === 'cleanup' && !$paths) throw new WireException('Cleanup requires explicit paths');
			$inspection = new FlexInspection($this->type);
			if($operation === 'status') { $result = $inspection->status(); $this->output($result, isset($options['json'])); return $result['success'] ? 0 : 1; }
			$field = $this->type->wire()->fields->get($name);
			if(!$field || !$field->type instanceof FieldtypeFlex) throw new WireException('Unknown Flex field');
			if(in_array($operation, ['schema', 'lint'], true)) {
				$result = $inspection->schema($field, $options['page'] ?? null);
				if($operation === 'lint') {
					$result = ['operation' => 'lint', 'field' => $result['field'], 'context' => $result['context'], 'pageId' => $result['pageId'],
						'diagnostics' => $result['diagnostics'] ?? [], 'findings' => $result['findings'], 'success' => $result['success']];
				}
				$this->output($result, isset($options['json'])); return $result['success'] ? 0 : 1;
			}
			$maintenance = new FlexMaintenance($this->type);
			$after = $options['after'] ?? 0; $limit = $options['limit'] ?? 100;
			$all = isset($options['all']); $json = isset($options['json']);
			$summary = ['record' => 'summary', 'operation' => $operation, 'fieldId' => (int) $field->id, 'afterPageId' => $after,
				'errorsCount' => 0, 'warningsCount' => 0, 'skippedChecksCount' => 0,
				'batches' => 0, 'checked' => 0, 'changed' => 0, 'unchanged' => 0, 'wouldChange' => 0, 'properties' => [], 'success' => true, 'complete' => false, 'finished' => false];
			$status = 0;
			do {
				$scanOperation = $operation === 'cleanup' ? 'orphans' : $operation;
				$preview = isset($options['page']) ? $maintenance->page($field, $scanOperation, $options['page']) : $maintenance->scan($field, $scanOperation, $after, $limit);
				$result = $preview;
				if($operation === 'cleanup') $result = $this->type->cleanupProperties($field, $paths, $preview);
				if(isset($options['apply'])) $result = $operation === 'rename' ? $this->type->applyRenames($field, $preview) : $this->type->cleanupProperties($field, $paths, $result, true);
				foreach(['pageId', 'stored'] as $key) if(array_key_exists($key, $preview)) $result[$key] = $preview[$key];
				unset($result['pages']);
				// PHP definition exceptions can contain arbitrary source text/paths; terminal diagnostics stay value-free.
				foreach($result['errors'] as &$error) $error['message'] = 'Owner, canonical data or definition could not be processed; inspect the page and field configuration.';
				unset($error);
				if(!isset($options['details'])) {
					foreach($result['properties'] as &$property) unset($property['pageIds'], $property['locations']);
					unset($property);
				}
				$batchStatus = $result['success'] ? (!empty($result['skippedChecks']) ? 3 : 0) : 1;
				if($operation === 'check' && array_filter($result['findings'], static fn($f) => $f['severity'] === 'error')) $batchStatus = 1;
				$status = $status === 1 || $batchStatus === 1 ? 1 : max($status, $batchStatus);
				if($all) $result = ['record' => 'batch'] + $result;
				$this->output($result, $json || $operation !== 'check', $all && $json);
				if(!$all) return $status;
				++$summary['batches'];
				$summary['errorsCount'] += $operation === 'check' ? count(array_filter($result['findings'], static fn($f) => $f['severity'] === 'error')) : count($result['errors']) + count($result['conflicts']);
				$summary['warningsCount'] += count(array_filter($result['findings'], static fn($f) => $f['severity'] === 'warning'));
				$summary['skippedChecksCount'] += count($result['skippedChecks']);
				foreach(['checked', 'changed', 'unchanged', 'wouldChange'] as $key) $summary[$key] += $result[$key];
				foreach($result['properties'] as $path => $property) {
					$summary['properties'][$path] ??= ['pageCount' => 0, 'occurrences' => 0, 'media' => 0];
					// ID-keyset batches are disjoint; each batch already deduplicates owners per path.
					foreach(['pageCount', 'occurrences', 'media'] as $key) $summary['properties'][$path][$key] += $property[$key];
				}
				foreach(['lastPageId', 'resumeAfterPageId', 'retryPageIds', 'complete'] as $key) $summary[$key] = $result[$key];
				$summary['success'] = $status === 0;
				$summary['finished'] = $result['complete'] && $status === 0;
				fwrite(STDERR, 'Flex: ' . $summary['checked'] . ' owners checked; cursor ' . $result['lastPageId'] . "\n");
				if($status !== 0 || $result['complete']) break;
				if($result['lastPageId'] <= $after) throw new WireException('Batch cursor did not advance');
				$after = $result['lastPageId'];
			} while(true);
			$this->output($summary, $json || $operation !== 'check', $json);
			return $status;
		} catch(\Throwable $error) {
			// Only explicitly raised adapter request errors are safe to print verbatim.
			fwrite(STDERR, 'Flex: request or adapter failed (' . ($error instanceof WireException ? $this->safeRequestMessage($error->getMessage()) : 'inspection unavailable') . ")\n");
			return 2;
		}
	}

	private function safeRequestMessage(string $message): string {
		foreach(['Unknown Flex command', 'Specify a Flex field name', 'Duplicate cleanup path', 'Unknown or inapplicable argument', 'Duplicate option:', 'Invalid numeric option:', 'Numeric option out of range:', 'Flag does not accept a value', 'Contradictory options', 'Cleanup requires explicit paths', 'Unknown Flex field', 'Page does not exist', 'Page template does not have this Flex field'] as $safe) {
			if(str_starts_with($message, $safe)) return $message;
		}
		return 'inspection unavailable';
	}

	private function output(array $result, bool $json, bool $lines = false): void {
		if($json) { echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | ($lines ? 0 : JSON_PRETTY_PRINT)) . "\n"; return; }
		// A readable tree, retaining every machine field without exposing stored contents.
		$walk = function(array $data, string $indent = '') use (&$walk): void {
			foreach($data as $key => $value) {
				if(is_array($value)) { echo $indent . $key . ":\n"; $walk($value, $indent . '  '); }
				else echo $indent . $key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : ($value === null ? 'unknown' : $value)) . "\n";
			}
		};
		$walk($result);
	}
}
