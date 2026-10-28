<?php namespace ProcessWire;

/** Thin native CliModule adapter. @internal */
final class FlexMaintenanceCli {
	private FieldtypeFlex $type;

	public function __construct(FieldtypeFlex $type) {
		$this->type = $type;
	}

	public static function help(): string {
		return <<<'HELP'
Flex data maintenance (one bounded batch per invocation)
php index.php flex rename FIELD [--dry-run | --apply] [--limit=100] [--after=0]
php index.php flex orphans FIELD [--details] [--limit=100] [--after=0]
php index.php flex cleanup FIELD --path=old_summary [--path=blocks.text.old] [--dry-run | --apply] [--limit=100] [--after=0]

Unknown properties are only POTENTIALLY orphaned: page-dependent definitions may omit them intentionally.
Paths identify schema properties (blocks.text.old), never concrete row IDs.
Without --apply every operation is read-only. Cleanup requires explicit paths.
--apply rechecks a fresh preview under lock. Admin/PHP can retain a preview between requests.
Output is JSON with per-batch aggregated paths/page counts; --details adds page IDs and concrete addresses.
Continue with --after=lastPageId while complete=false. On errors, fix them and use resumeAfterPageId;
retryPageIds are never reported as successfully processed. A finished report covers this scan, not earlier batches.
Remove renamedFrom only after a complete error-free scan from --after=0 finds no pending renames.
HELP;
	}

	/** Return an exit status; the module boundary propagates it through ProcessWire. */
	public function run(array $args): int {
		try {
			if(in_array($args, [[], ['help'], ['--help'], ['-h']], true)) {
				echo self::help();
				return 0;
			}
			$operation = array_shift($args);
			$name = array_shift($args);
			if(!in_array($operation, ['rename', 'orphans', 'cleanup'], true) || !is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
				throw new WireException('Specify rename, orphans or cleanup followed by a Flex field name');
			}
			$options = []; $paths = [];
			foreach($args as $arg) {
				if(preg_match('/^--path=(.+)$/D', $arg, $match) && $operation === 'cleanup') {
					$paths[] = $match[1];
					continue;
				}
				if(!preg_match('/^--(apply|dry-run|details|limit|after)(?:=(.*))?$/D', $arg, $match)) throw new WireException('Unknown argument: ' . $arg);
				$key = $match[1];
				if(isset($options[$key])) throw new WireException('Duplicate option: ' . $key);
				if(in_array($key, ['limit', 'after'], true)) {
					if(!isset($match[2]) || !preg_match('/^(0|[1-9][0-9]*)$/D', $match[2]) || filter_var($match[2], FILTER_VALIDATE_INT) === false) throw new WireException('Invalid numeric option: ' . $key);
					$options[$key] = (int) $match[2];
				} else {
					if(isset($match[2])) throw new WireException('Flag does not accept a value: ' . $key);
					$options[$key] = true;
				}
			}
			if(isset($options['apply'], $options['dry-run']) || ($operation === 'orphans' && (isset($options['apply']) || isset($options['dry-run']))) || ($operation !== 'orphans' && isset($options['details']))) {
				throw new WireException('Contradictory or inapplicable options');
			}
			$field = $this->type->wire()->fields->get($name);
			if(!$field || !$field->type instanceof FieldtypeFlex) throw new WireException('Unknown Flex field');
			$after = $options['after'] ?? 0; $limit = $options['limit'] ?? 100;
			$preview = $operation === 'rename' ? $this->type->checkRenames($field, $after, $limit) : $this->type->listOrphans($field, $after, $limit);
			$result = $preview;
			if($operation === 'cleanup') $result = $this->type->cleanupProperties($field, $paths, $preview);
			if(isset($options['apply'])) {
				$result = $operation === 'rename' ? $this->type->applyRenames($field, $preview) : $this->type->cleanupProperties($field, $paths, $result, true);
			}
			unset($result['pages']); // Tokens are for PHP and browser previews, not terminal output.
			if(!isset($options['details'])) {
				foreach($result['properties'] as &$property) unset($property['pageIds'], $property['locations']);
				unset($property);
			}
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			return $result['success'] ? 0 : 1;
		} catch(\Throwable $error) {
			fwrite(STDERR, 'Flex: ' . $error->getMessage() . "\n");
			return 2;
		}
	}
}
