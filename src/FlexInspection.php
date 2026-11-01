<?php namespace ProcessWire;

/** Read-only diagnostics over the same raw schema, projection and media contracts. @internal */
final class FlexInspection {
	private FieldtypeFlex $type;

	public function __construct(FieldtypeFlex $type) { $this->type = $type; }

	public function finding(Field $field, int $id, string $category, string $severity, string $path = '', string $address = ''): array {
		$messages = [
			'owner' => 'Stored owner cannot be resolved or no longer has this field.',
			'canonical_json' => 'Canonical JSON is invalid.', 'definition' => 'Definition is missing or invalid.',
			'envelope' => 'Stored envelope or tree budget is invalid.', 'schema_data' => 'Stored data cannot be inspected against this schema.',
			'row_schema' => 'Stored row type or envelope is invalid for the current schema.',
			'rename_conflict' => 'Old and new property values conflict.', 'pending_rename' => 'Declared renames remain pending; raw stored paths are used for derived checks.',
			'search_projection' => 'Whole-field search differs from its canonical derivation.',
			'row_projection' => 'Row projection differs from its canonical derivation.',
			'media_reference' => 'Media reference has missing, duplicate or inconsistent ownership.',
			'media_unreferenced' => 'Live ownership has no reference in this canonical field.',
			'media_original' => 'Registered original file is missing.',
			'derived_unavailable' => 'A derived check could not be completed.',
		];
		return ['category' => $category, 'severity' => $severity, 'field' => $field->name, 'fieldId' => (int) $field->id,
			'pageId' => $id, 'path' => $path, 'address' => $address, 'message' => $messages[$category] ?? 'Inspection failed.'];
	}

	public function schema(Field $field, ?int $id): array {
		$page = $id === null ? null : (new FlexMaintenance($this->type))->owner($field, $id);
		$source = FlexFieldDefs::inspectSource($field);
		// Never include configured paths or arbitrary exception messages from trusted PHP.
		$safeSource = ['kind' => $source['kind'], 'origin' => $source['origin'], 'available' => $source['exists']];
		if($source['kind'] === 'file' && $source['exists']) $safeSource['file'] = $source['relativePath'];
		$result = ['operation' => 'schema', 'field' => $field->name, 'context' => $id === null ? 'discovery/NullPage' : 'page',
			'pageId' => $id, 'source' => $safeSource, 'properties' => [], 'selectors' => [], 'findings' => [], 'success' => true];
		try {
			$defs = $this->type->getDefinitions($field, $page);
			if($source['kind'] === 'missing') throw new WireException('Missing definition');
			$describe = function(FlexFieldDefs $schema) use (&$describe): array {
				$schema->migrate([]);
				$out = [];
				foreach($schema->properties() as $name => $def) {
					$item = ['kind' => $def['kind'], 'input' => $def['input']->className(), 'multiple' => $def['multiple'], 'languages' => (bool) ($def['languages'] ?? false), 'renamedFrom' => $def['renamedFrom']];
					foreach($def['types'] ?? [] as $key => $type) $item['types'][$key] = $describe($type['schema']);
					if($def['customFields'] ?? null) $item['customFields'] = $describe($def['customFields']);
					$out[$name] = $item;
				}
				return $out;
			};
			$result['properties'] = $describe($defs);
			$info = (new FlexQuery($this->type))->info($field, ['page' => $page, 'inspection' => true], $defs);
			foreach($info['subfields'] as $path => $descriptor) {
				$result['selectors'][$path] = ['input' => $descriptor['input'], 'operators' => $descriptor['operators']];
			}
			$result['diagnostics'] = $this->definitionDiagnostics($defs);
			$result['limitations'] = ['Selectors use this context; normal query discovery uses NullPage.',
				'No sorting of repeated values, Page traversal from rows, recursive Page hops or arbitrary JSON paths.',
				'Page traversal supports only the native target fields exposed here; grouping/negation restrictions still apply.'];
		} catch(\Throwable $e) {
			$result['success'] = false;
			$result['findings'][] = $this->finding($field, $id ?? 0, 'definition', 'error');
		}
		return $result;
	}

	/** Non-blocking checks for likely definition mistakes; external Page inputs remain context-dependent. @internal */
	private function definitionDiagnostics(FlexFieldDefs $root): array {
		$diagnostics = [];
		$add = static function(string $code, string $path, string $property, string $setting, string $message) use (&$diagnostics): void {
			$key = "$code\0$path\0$property\0$setting";
			$diagnostics[$key] = ['code' => $code, 'severity' => 'warning', 'path' => $path,
				'property' => $property, 'setting' => $setting, 'message' => $message];
		};
		$walk = function(FlexFieldDefs $schema, ?FlexFieldDefs $parent = null, string $prefix = '') use (&$walk, $root, $add): void {
			foreach($schema->properties() as $name => $definition) {
				$path = $prefix . $name;
				$input = $definition['input'];
				foreach(['showIf', 'requiredIf'] as $setting) {
					$expression = (string) $input->get($setting);
					if($expression === '') continue;
					try {
						$selectors = $this->type->wire()->wire(new Selectors($expression));
						foreach($selectors as $selector) {
							foreach($selector->fields() as $reference) {
								[$scope, $target] = array_pad(explode('.', $reference, 2), 2, '');
								if(!in_array($scope, ['parent', 'root', 'page', 'forpage'], true) || $target === '') continue;
								if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\.count)?$/D', $target)) {
									$add('dependency_path_invalid', $path, $reference, $setting, 'Dependency path has unsupported syntax.');
									continue;
								}
								if($scope === 'root' || $scope === 'parent') {
									$targetSchema = $scope === 'root' ? $root : $parent;
									$property = explode('.', $target, 2)[0];
									if(!$targetSchema) {
										$add('dependency_path_invalid', $path, $reference, $setting, 'Dependency scope is unavailable here.');
									} else if(!$targetSchema->property($property)) {
										$add('dependency_target_unknown', $path, $reference, $setting, 'Dependency target is not defined in this Flex scope.');
									}
								}
							}
						}
					} catch(\Throwable $e) {
						$add('dependency_expression_invalid', $path, '', $setting, 'Dependency expression could not be parsed.');
					}
				}
				if(($definition['kind'] ?? '') === 'rows') {
					foreach($definition['types'] as $type => $typeDefinition) {
						$rowPath = $path . '.' . $type;
						$format = (string) ($typeDefinition['settings']['rowTitle'] ?? '');
						if($format !== '' && preg_match_all('/_?\\{([^{}]+)\\}_?/', $format, $matches)) {
							$children = $typeDefinition['schema'];
							foreach($matches[1] as $token) {
								$known = false;
								foreach(explode('|', $token) as $candidate) {
									if(in_array($candidate, ['@type', '@path'], true) || $children->property($candidate)) { $known = true; break; }
								}
								if(!$known) $add('row_title_unresolved', $rowPath, $token, 'rowTitle', 'rowTitle token does not resolve to a property or built-in token.');
							}
						}
						$walk($typeDefinition['schema'], $schema, $rowPath . '.');
					}
				}
				if(($definition['kind'] ?? '') === 'object') $walk($definition['schema'], $schema, $path . '.');
				if($definition['customFields'] ?? null) $walk($definition['customFields'], $schema, $path . '.');
			}
		};
		$walk($root);
		return array_values($diagnostics);
	}

	public function checkDerived(Field $field, Page $page, array $raw, array &$findings, array &$skipped): void {
		$db = $this->type->wire()->database;
		$bind = ['field' => $field->id, 'page' => $page->id];
		foreach(['search', 'rows', 'media'] as $check) {
			try {
				if($check === 'search') {
					$table = $db->escapeTable($field->table);
					$q = $db->prepare("SELECT search FROM `$table` WHERE pages_id=:page"); $q->execute(['page' => $page->id]);
					// JSON databases may reorder object members after search was derived.
					// The flat projection has no property boundaries; compare content counts.
					$terms = static function(string $search): array {
						$parts = explode(' ', $search);
						sort($parts, SORT_STRING);
						return $parts;
					};
					$search = $q->fetchColumn();
					if(!is_string($search) || $terms($search) !== $terms(FlexStorage::search($raw))) $findings[] = $this->finding($field, $page->id, 'search_projection', 'error');
				} else if($check === 'rows') {
					$q = $db->prepare('SELECT set_key AS `key`,set_name AS name,schema_key,schema_path AS `schema`,row_key,parent_row_key AS parent,row_id AS id,row_type AS type,ordinal AS sort,data FROM fieldtype_flex_rows WHERE fields_id=:field AND pages_id=:page');
					$q->execute($bind);
					$actual = []; $expected = [];
					$normalize = static function(array $row): array {
						$row['sort'] = (int) $row['sort'];
						$data = json_decode($row['data'], true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
						if(!is_array($data)) throw new \JsonException('Invalid row projection payload');
						$row['data'] = FlexStorage::revision($data);
						return $row;
					};
					foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row) $actual[$row['row_key']] = $normalize($row);
					foreach(FlexStorage::projection($raw) as $row) $expected[$row['row_key']] = $normalize($row);
					ksort($actual); ksort($expected);
					if($actual !== $expected) $findings[] = $this->finding($field, $page->id, 'row_projection', 'error');
				} else {
					$q = $db->prepare('SELECT * FROM fieldtype_flex_assets WHERE fields_id=:field AND pages_id=:page'); $q->execute($bind);
					$owners = $q->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC); $seen = [];
					foreach(FlexMedia::records($raw) as $record) {
						$meta = $record['meta']; $id = $meta['id']; $owner = $owners[$id] ?? null;
						if(isset($seen[$id]) || !$owner || $owner['basename'] !== $meta['basename'] || $owner['address'] !== $record['address'] || !in_array($owner['state'], ['committed', 'cloning'], true)) {
							$findings[] = $this->finding($field, $page->id, 'media_reference', 'error', '', $record['address']);
						} else {
							$data = json_decode($owner['data'], true, 64, JSON_THROW_ON_ERROR);
							if(!is_array($data) || !is_array($data['meta'] ?? null) || (isset($data['directory']) && !is_string($data['directory'])) || ($data['kind'] ?? '') !== $record['kind'] || FlexStorage::revision($data['meta']) !== FlexStorage::revision($meta)) $findings[] = $this->finding($field, $page->id, 'media_reference', 'error', '', $record['address']);
						}
						$seen[$id] = true;
					}
					foreach($owners as $id => $owner) {
						if(!isset($seen[$id]) && in_array($owner['state'], ['committed', 'cloning'], true)) $findings[] = $this->finding($field, $page->id, 'media_unreferenced', 'warning', '', $owner['address']);
						// Do not instantiate native file objects: their path initialization can mutate directories.
						if(isset($seen[$id])) {
							$exists = $this->type->media()->originalExists($page, ['asset_id' => $id] + $owner);
							if($exists === false) $findings[] = $this->finding($field, $page->id, 'media_original', 'error', '', $owner['address']);
							if($exists === null) $skipped[] = ['pageId' => (int) $page->id, 'check' => 'original_path'];
						}
					}
				}
			} catch(\JsonException $e) {
				$findings[] = $this->finding($field, $page->id, $check === 'rows' ? 'row_projection' : 'media_reference', 'error');
				$skipped[] = ['pageId' => (int) $page->id, 'check' => 'remaining_' . $check];
			} catch(\Throwable $e) {
				$skipped[] = ['pageId' => (int) $page->id, 'check' => $check];
				$findings[] = $this->finding($field, $page->id, 'derived_unavailable', 'warning');
			}
		}
	}

	public function status(): array {
		$wire = $this->type->wire(); $db = $wire->database;
		$result = ['operation' => 'status', 'moduleVersion' => FieldtypeFlex::getModuleInfo()['version'], 'processWireVersion' => $wire->config->version,
			'phpVersion' => PHP_VERSION, 'databaseVersion' => $db->getAttribute(\PDO::ATTR_SERVER_VERSION),
			'nativeCli' => interface_exists(CliModule::class), 'tables' => [], 'mediaStates' => null, 'expiredTemporary' => null,
			'cleanupCursor' => null, 'tableValidation' => 'required columns and InnoDB engine; index definitions not audited', 'lastCleanup' => 'unknown', 'success' => true];
		$required = [
			'fieldtype_flex_rows' => ['fields_id','pages_id','set_key','set_name','row_id','row_type','schema_path','schema_key','row_key','parent_row_key','ordinal','data'],
			'fieldtype_flex_assets' => ['asset_id','fields_id','pages_id','address','basename','state','draft','expires','data'],
		];
		foreach($required as $table => $columns) {
			try {
				$present = $db->getColumns($table); $missing = array_values(array_diff($columns, $present));
				$q = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name'); $q->execute(['name' => $table]);
				$engine = $q->fetchColumn();
				$result['tables'][$table] = ['available' => true, 'missingColumns' => $missing, 'engine' => $engine];
				if($missing || strcasecmp((string) $engine, 'InnoDB') !== 0) $result['success'] = false;
			} catch(\Throwable $e) { $result['tables'][$table] = ['available' => false]; $result['success'] = false; }
		}
		if($result['tables']['fieldtype_flex_assets']['available'] && !$result['tables']['fieldtype_flex_assets']['missingColumns']) {
			$result['mediaStates'] = $db->query('SELECT state,COUNT(*) FROM fieldtype_flex_assets GROUP BY state')->fetchAll(\PDO::FETCH_KEY_PAIR);
			$result['mediaStates'] = array_map('intval', $result['mediaStates']);
			$q = $db->prepare("SELECT COUNT(*) FROM fieldtype_flex_assets WHERE state IN ('staged','uploading','cloning') AND expires>0 AND expires<:now"); $q->execute(['now' => time()]);
			$result['expiredTemporary'] = (int) $q->fetchColumn();
		}
		$cursor = $wire->cache->get('FlexMedia.collectCursor');
		if(is_string($cursor) && preg_match('/^[a-f0-9]{32}$/D', $cursor)) $result['cleanupCursor'] = $cursor;
		return $result;
	}
}
