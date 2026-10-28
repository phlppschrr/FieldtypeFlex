<?php namespace ProcessWire;

/** Raw, page-aware data maintenance shared by API, CLI and field configuration. @internal */
final class FlexMaintenance extends Wire {
	private FieldtypeFlex $type;

	public function __construct(FieldtypeFlex $type) {
		parent::__construct();
		$type->wire($this);
		$this->type = $type;
	}

	private function validate(Field $field, int $after, int $limit): void {
		if(!$field->id || !$field->type instanceof FieldtypeFlex || $after < 0 || $limit < 1 || $limit > 1000) {
			throw new WireException('Invalid Flex maintenance field, cursor or limit (1..1000)');
		}
	}

	/** Read the field table directly: no PageFinder status, access or Repeater exclusions. */
	public function scan(Field $field, string $operation, int $after, int $limit): array {
		$this->validate($field, $after, $limit);
		$table = $this->wire()->database->escapeTable($field->table);
		$q = $this->wire()->database->prepare("SELECT pages_id FROM `$table` WHERE pages_id>:after ORDER BY pages_id LIMIT " . ($limit + 1));
		$q->execute(['after' => $after]);
		$ids = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
		$complete = count($ids) <= $limit;
		$ids = array_slice($ids, 0, $limit);
		return $this->batch($field, $operation, [], $ids, [], false, $after, $complete);
	}

	/** Exact selection shares the batch transformer; absent storage is a regular empty result. */
	public function page(Field $field, string $operation, int $id): array {
		$this->validate($field, 0, 1);
		$this->owner($field, $id);
		$table = $this->wire()->database->escapeTable($field->table);
		$q = $this->wire()->database->prepare("SELECT pages_id FROM `$table` WHERE pages_id=:id");
		$q->execute(['id' => $id]);
		$stored = $q->fetchColumn() !== false;
		return $this->batch($field, $operation, [], $stored ? [$id] : [], [], false, 0, true)
			+ ['pageId' => $id, 'stored' => $stored];
	}

	/** Resolve owner without frontend status or access filters. */
	public function owner(Field $field, int $id): Page {
		$page = $this->wire()->pages->getFresh($id);
		if(!$page->id) throw new WireException('Page does not exist');
		if(!$page->hasField($field->name)) throw new WireException('Page template does not have this Flex field');
		return $page;
	}

	/** Apply only the pages and revisions represented by a bounded preview. */
	public function apply(Field $field, string $operation, array $paths, array $preview, bool $apply = true): array {
		$ids = array_keys($preview['pages'] ?? []);
		$this->validate($field, (int) ($preview['afterPageId'] ?? -1), max(1, count($ids)));
		if((int) ($preview['fieldId'] ?? 0) !== (int) $field->id ||
			!in_array($preview['operation'] ?? '', $operation === 'rename' ? ['rename'] : ['orphans', 'cleanup'], true)) {
			throw new WireException('The preview does not match this field or operation');
		}
		foreach($ids as $id) {
			if(!ctype_digit((string) $id) || (int) $id < 1) throw new WireException('Invalid preview page ID');
		}
		foreach($paths as $path) {
			if(!is_string($path) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $path)) {
				throw new WireException('Invalid typed schema path');
			}
		}
		if($operation === 'cleanup' && !$paths) throw new WireException('Select explicit potentially orphaned property paths');
		$paths = array_values(array_unique($paths));
		if($operation === 'cleanup' && $apply && (($preview['operation'] ?? '') !== 'cleanup' || ($preview['paths'] ?? []) !== $paths)) {
			throw new WireException('Preview the selected cleanup paths before applying');
		}
		sort($ids, SORT_NUMERIC);
		$result = $this->batch($field, $operation, $paths, $ids, $preview['pages'], $apply,
			(int) $preview['afterPageId'], (bool) ($preview['complete'] ?? false));
		foreach(['errors', 'conflicts', 'retryPageIds'] as $key) $result[$key] = array_merge($preview[$key] ?? [], $result[$key]);
		// Preview failures are still examined owners, even though they cannot enter apply.
		$result['checked'] = max($result['checked'], (int) ($preview['checked'] ?? 0));
		$result['lastPageId'] = (int) ($preview['lastPageId'] ?? $result['lastPageId']);
		$result['success'] = !$result['errors'] && !$result['conflicts'];
		$result['finished'] = $result['complete'] && $result['success'];
		$result['resumeAfterPageId'] = $result['retryPageIds'] ? min($result['retryPageIds']) - 1 : $result['lastPageId'];
		return $result;
	}

	private function batch(Field $field, string $operation, array $paths, array $ids, array $expected, bool $apply, int $after, bool $complete): array {
		$result = [
			'fieldId' => (int) $field->id, 'operation' => $operation, 'paths' => $paths,
			'checked' => 0, 'changed' => 0, 'unchanged' => 0, 'wouldChange' => 0,
			'conflicts' => [], 'errors' => [], 'pages' => [], 'properties' => [], 'renames' => [],
			'findings' => [], 'skippedChecks' => [],
			'afterPageId' => $after, 'lastPageId' => $after, 'retryPageIds' => [], 'complete' => $complete,
		];
		$store = $this->type->storage();
		foreach($ids as $id) {
			$id = (int) $id;
			++$result['checked'];
			$result['lastPageId'] = $id;
			try {
				$stage = 'owner';
				$process = function() use ($field, $operation, $paths, $id, $expected, $apply, $store, &$result, &$stage): void {
					$page = $this->owner($field, $id);
					$stage = 'canonical_json';
					$document = $store->document($page, $field, $apply);
					$raw = json_decode(json_encode($document, JSON_THROW_ON_ERROR, FlexPath::JSON_DEPTH), true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
					if($raw && array_is_list($raw)) throw new WireException('Stored Flex data must be a property map');
					$stage = 'definition';
					$defs = $this->type->getDefinitions($field, $page);
					if($operation === 'check' && ($defs->sourceInfo()['kind'] ?? '') === 'missing') throw new WireException('Definition missing');
					$defs->assertSourceForValues($raw);
					// Validate envelopes without hydrating or adding defaults.
					$stage = 'envelope';
					$this->validateEnvelopes($raw);
					FlexPath::validate($raw, $field);
					FlexMedia::records($raw);
					$stage = 'definition';
					$schema = $this->schema($defs);
					$token = hash('sha256', $this->documentRevision($document) . json_encode($schema, JSON_THROW_ON_ERROR));
					if($expected && (!isset($expected[$id]['token']) || !hash_equals($token, (string) $expected[$id]['token']))) {
						throw new WireException('Stale preview: stored data or relevant schema changed; inspect a new preview');
					}
					$issues = []; $orphans = []; $renames = [];
					$stage = 'schema_data';
					$next = $this->walk($raw, $document, $defs, '', '', '', '', $operation, $paths, $issues, $orphans, $renames);
					if($operation === 'check') {
						$inspection = new FlexInspection($this->type);
						foreach($issues as $issue) {
							$category = $issue['category'] ?? ($issue['kind'] === 'conflicts' ? 'rename_conflict' : 'row_schema');
							$result['findings'][] = $inspection->finding($field, $id, $category, 'error', $issue['path'], $issue['address'] ?? '');
						}
						if($issues) $result['skippedChecks'][] = ['pageId' => $id, 'check' => 'schema_validation_after_issue'];
						if($renames) $result['findings'][] = $inspection->finding($field, $id, 'pending_rename', 'warning');
						$inspection->checkDerived($field, $page, $raw, $result['findings'], $result['skippedChecks']);
						if(array_filter($result['findings'], static fn($f) => $f['pageId'] === $id && $f['severity'] === 'error') || array_filter($result['skippedChecks'], static fn($s) => $s['pageId'] === $id)) $result['retryPageIds'][] = $id;
						$orphans = []; // Unknown properties are not consistency failures.
					}
					foreach($issues as $issue) {
						$result[$issue['kind']][] = ['pageId' => $id] + $issue;
					}
					foreach($orphans as $found) {
						$key = $found['path'];
						$result['properties'][$key] ??= ['path' => $key, 'pageIds' => [], 'pageCount' => 0, 'occurrences' => 0, 'media' => 0, 'locations' => []];
						$aggregate =& $result['properties'][$key];
						$aggregate['pageIds'][$id] = $id;
						$aggregate['pageCount'] = count($aggregate['pageIds']);
						++$aggregate['occurrences'];
						$aggregate['media'] += $found['media'];
						$aggregate['locations'][] = ['pageId' => $id, 'address' => $found['address']];
						unset($aggregate);
					}
					foreach($renames as $rename) {
						unset($rename['patchAddress']);
						$result['renames'][] = ['pageId' => $id] + $rename;
					}
					if($issues) {
						$result['retryPageIds'][] = $id;
						return;
					}
					$result['pages'][$id] = ['token' => $token];
					$changed = FlexStorage::revision($raw) !== FlexStorage::revision($next);
					if($changed && $operation !== 'orphans') {
						++$result['wouldChange'];
						if($apply) {
							FlexPath::validate($next, $field);
							$table = $this->wire()->database->escapeTable($field->table);
							$q = $this->wire()->database->prepare("UPDATE `$table` SET data=:data,search=:search WHERE pages_id=:page");
							$document = $this->transformDocument($document, $operation, $paths, $renames, $orphans);
							$q->execute(['data' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, FlexPath::JSON_DEPTH), 'search' => FlexStorage::search($next), 'page' => $id]);
							$store->projectRows($page, $field, $next);
							$this->type->media()->sync($page, $field, $next);
						}
					} else {
						++$result['unchanged'];
					}
				};
				if($apply) {
					$store->requireTransactional($field);
					$before = $result['wouldChange'];
					$store->atomic($process);
					$result['changed'] += $result['wouldChange'] - $before;
				} else {
					$process();
				}
			} catch(\Throwable $error) {
				if($operation === 'check') {
					$result['findings'][] = (new FlexInspection($this->type))->finding($field, $id, $stage, 'error');
					$result['skippedChecks'][] = ['pageId' => $id, 'check' => 'remaining_after_' . $stage];
				}
				unset($result['pages'][$id]);
				$result['errors'][] = ['pageId' => $id, 'path' => '', 'message' => $operation === 'check' ? 'Consistency inspection could not complete this page' : $error->getMessage()];
				$result['retryPageIds'][] = $id;
			}
		}
		$result['retryPageIds'] = array_values(array_unique($result['retryPageIds']));
		$result['success'] = !$result['errors'] && !$result['conflicts'] && !array_filter($result['findings'], static fn($f) => $f['severity'] === 'error');
		$result['finished'] = $complete && $result['success'] && !$result['skippedChecks'];
		// The forward cursor is a scan position, never a success claim. Retry IDs are explicit.
		$result['resumeAfterPageId'] = $result['retryPageIds'] ? min($result['retryPageIds']) - 1 : $result['lastPageId'];
		ksort($result['properties']);
		foreach($result['properties'] as &$property) $property['pageIds'] = array_values($property['pageIds']);
		unset($property);
		return $result;
	}

	/** Reject malformed retained envelopes before generic encoders can issue PHP warnings. */
	private function validateEnvelopes(array $raw): void {
		$media = static function(array $scope, string $prefix): void {
			foreach($scope as $name => $value) {
				if(!is_array($value) || !in_array($value['_flex'] ?? '', [FlexStorage::FILES_FORMAT, FlexStorage::IMAGES_FORMAT], true)) continue;
				if(!FlexMedia::validEnvelope($value, $value['_flex'])) {
					throw new WireException('Invalid stored media envelope at ' . $prefix . $name);
				}
			}
		};
		$media($raw, '');
		foreach(FlexPath::rows($raw) as $entry) $media($entry['row']['values'], $entry['address'] . '.');
	}

	/** Fingerprint JSON values without losing object/list distinctions in unknown subtrees. */
	private function documentRevision(mixed $document): string {
		$sort = static function($value) use (&$sort) {
			if($value instanceof \stdClass) {
				$map = get_object_vars($value); ksort($map, SORT_STRING);
				return (object) array_map($sort, $map);
			}
			return is_array($value) ? array_map($sort, $value) : $value;
		};
		return hash('sha256', json_encode($sort($document), JSON_THROW_ON_ERROR, FlexPath::JSON_DEPTH));
	}

	/** Patch only checked keys in the original JSON document, without reserializing runtime values. */
	private function transformDocument(\stdClass $document, string $operation, array $paths, array $renames, array $orphans): \stdClass {
		$edits = $operation === 'rename' ? $renames : array_filter($orphans, static fn($item) => in_array($item['path'], $paths, true));
		foreach($edits as $edit) {
			$parts = explode('.', $edit['patchAddress'] ?? $edit['address']);
			$scope = $document;
			while(count($parts) > 1) {
				$set = array_shift($parts); $id = array_shift($parts);
				$found = false;
				foreach($scope->{$set}->items as $row) {
					if($row->id === $id) { $scope = $row->values; $found = true; break; }
				}
				if(!$found) throw new WireException('Checked JSON row address no longer exists');
			}
			$name = $parts[0];
			if($operation === 'rename') {
				$target = substr($edit['to'], strrpos('.' . $edit['to'], '.'));
				$scope->{$target} = $scope->{$name};
			}
			unset($scope->{$name});
		}
		return $document;
	}

	/** Fingerprint only decisions relevant to raw transforms, including absent row types. */
	private function schema(FlexFieldDefs $defs): array {
		$defs->migrate([]); // Validate ambiguous mappings even when no source is stored.
		$schema = [];
		foreach($defs->properties() as $name => $def) {
			$item = ['kind' => $def['kind'], 'renamedFrom' => $def['renamedFrom']];
			foreach($def['types'] ?? [] as $key => $type) $item['types'][$key] = $this->schema($type['schema']);
			$schema[$name] = $item;
		}
		return $schema;
	}

	private function walk(array $raw, \stdClass $document, FlexFieldDefs $defs, string $schema, string $address, string $storedSchema, string $storedAddress, string $operation, array $paths, array &$issues, array &$orphans, array &$renames): array {
		$properties = $defs->properties();
		$sources = [];
		$storedNames = [];
		foreach($properties as $name => $def) {
			foreach($def['renamedFrom'] as $old) {
				$sources[$old] = $name;
				if(!array_key_exists($old, $raw)) continue;
				if(!array_key_exists($name, $raw)) $storedNames[$name] ??= $old;
				$previous = array_key_exists($name, $raw) ? $name : $storedNames[$name];
				$renames[] = ['from' => $storedSchema . $old, 'to' => $schema . $name, 'address' => $storedAddress . $old, 'patchAddress' => $address . $old];
				if($old !== $previous && ($raw[$old] !== $raw[$previous]
					|| $this->documentRevision($document->{$old}) !== $this->documentRevision($document->{$previous}))) {
					$issues[] = ['kind' => 'conflicts', 'path' => $schema . $old, 'address' => $storedAddress . $old, 'message' => 'Different old and new values'];
				}
			}
		}
		if($issues) return $raw;
		// For orphan operations, old keys are examined under their target schema but never offered for deletion.
		$migrated = $defs->migrate($raw);
		if($operation === 'rename') $raw = $migrated;
		foreach($raw as $name => &$value) {
			$targetName = $sources[$name] ?? $name;
			$path = $schema . $targetName;
			$patchLocation = $address . $name;
			$storedName = $operation === 'rename' ? ($storedNames[$name] ?? $name) : $name;
			$location = $storedAddress . $storedName;
			if(!isset($properties[$targetName])) {
				$orphans[] = ['path' => $path, 'address' => $location, 'media' => count(FlexMedia::records([$name => $value]))];
				if($operation === 'cleanup' && in_array($path, $paths, true)) unset($raw[$name]);
				continue;
			}
			$def = $properties[$targetName];
			if($operation === 'check' && in_array($def['kind'], ['file', 'image'], true) && $value !== null && $value !== '' && $value !== []) {
				$marker = $def['kind'] === 'image' ? FlexStorage::IMAGES_FORMAT : FlexStorage::FILES_FORMAT;
				if(!FlexMedia::validEnvelope($value, $marker)) $issues[] = ['kind' => 'errors', 'category' => 'envelope', 'path' => $path, 'address' => $location, 'message' => 'Invalid stored media envelope'];
			}
			if($def['kind'] !== 'rows') continue;
			if(!is_array($value) || ($value['_flex'] ?? '') !== FlexStorage::ROWS_FORMAT) {
				$issues[] = ['kind' => 'errors', 'path' => $path, 'address' => $location, 'message' => 'Invalid stored row envelope'];
				continue;
			}
			$documentRows = (array) $document->{$storedName}->items;
			foreach($value['items'] as $ordinal => &$row) {
				$rowType = $row['type'];
				if(!isset($def['types'][$rowType])) {
					$issues[] = ['kind' => 'errors', 'path' => "$path.$rowType", 'address' => "$location.{$row['id']}", 'message' => 'Unknown stored row type; retained'];
					continue;
				}
				$rowDocument = (object) $documentRows[$ordinal]->values;
				$row['values'] = $this->walk($row['values'], $rowDocument, $def['types'][$rowType]['schema'], "$path.$rowType.", "$patchLocation.{$row['id']}.", "$storedSchema$storedName.$rowType.", "$location.{$row['id']}.", $operation, $paths, $issues, $orphans, $renames);
			}
			unset($row);
		}
		unset($value);
		return $raw;
	}
}
