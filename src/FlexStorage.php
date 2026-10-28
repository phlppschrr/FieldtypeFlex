<?php namespace ProcessWire;

/** Canonical field persistence and rebuildable, portable row projection. * @internal
 */
class FlexStorage extends Wire {
	public const ROWS_FORMAT = 'rows:1';
	public const FILES_FORMAT = 'files:1';
	public const IMAGES_FORMAT = 'images:1';
	private static int $savepoint = 0;
	private FieldtypeFlex $type;

	/**
	 * Initialize the object with its owning ProcessWire context.
	 *
	 * @param FieldtypeFlex $type Owning fieldtype service.
	 */
	public function __construct(FieldtypeFlex $type) {
		parent::__construct();
		$type->wire($this);
		$this->type = $type;
	}

	/**
	 * Module installation/upgrade only: never run DDL inside a field-save transaction.
	 *
	 * @return void
	 */
	public function install(): void {
		$db = $this->wire()->database;
		if($db->inTransaction()) {
			throw new WireException($this->_('Install Flex storage outside a database transaction'));
		}
		$db->exec('CREATE TABLE IF NOT EXISTS fieldtype_flex_rows (
            fields_id INT UNSIGNED NOT NULL, pages_id INT UNSIGNED NOT NULL,
            set_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            set_name LONGTEXT NOT NULL, row_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            row_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            schema_path LONGTEXT NOT NULL, schema_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            row_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            parent_row_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ordinal INT UNSIGNED NOT NULL, data LONGTEXT NOT NULL,
            PRIMARY KEY(fields_id,pages_id,set_key,row_id), KEY page_owner(pages_id,fields_id),
            KEY row_type_owner(fields_id,pages_id,set_key,row_type),
            UNIQUE KEY row_identity(fields_id,pages_id,row_key),
            KEY schema_owner(fields_id,pages_id,schema_key,row_type),
            KEY parent_owner(fields_id,pages_id,parent_row_key,schema_key,row_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
		$db->exec('CREATE TABLE IF NOT EXISTS fieldtype_flex_assets (
            asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            fields_id INT UNSIGNED NOT NULL, pages_id INT UNSIGNED NOT NULL,
            address LONGTEXT NOT NULL, basename VARCHAR(255) NOT NULL,
            state VARCHAR(16) CHARACTER SET ascii NOT NULL,
            draft CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\',
            expires INT UNSIGNED NOT NULL DEFAULT 0, data LONGTEXT NOT NULL,
            PRIMARY KEY(asset_id), UNIQUE KEY page_basename(pages_id,basename),
            KEY owner_state(fields_id,pages_id,state), KEY expiry(state,expires)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	/**
	 * Run an operation in an owned transaction or a nested savepoint.
	 *
	 * @template T
	 * @param callable(): T $operation Operation performed in the transaction or savepoint.
	 * @return T
	 * @throws WireException
	 */
	public function atomic(callable $operation) {
		$db = $this->wire()->database;
		$own = !$db->inTransaction();
		$point = 'flex_' . ++self::$savepoint;
		if($own) {
			$db->beginTransaction();
		} else {
			$db->exec("SAVEPOINT $point");
		}
		try {
			$result = $operation();
			if($own) {
				$db->commit();
			} else {
				$db->exec("RELEASE SAVEPOINT $point");
			}
			return $result;
		} catch(\Throwable $error) {
			if($db->inTransaction()) {
				if($own) {
					$db->rollBack();
				} else {
					$db->exec("ROLLBACK TO SAVEPOINT $point");
					$db->exec("RELEASE SAVEPOINT $point");
				}
			}
			throw $error;
		}
	}

	/**
	 * Require an InnoDB table for atomic structured field persistence.
	 *
	 * @param Field $field Owning Flex field.
	 * @return void
	 * @throws WireException
	 */
	public function requireTransactional(Field $field): void {
		$this->requireRowSchema();
		$query = $this->wire()->database->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
		$query->execute(['table' => $field->table]);
		if(strcasecmp((string) $query->fetchColumn(), 'InnoDB') !== 0) {
			throw new WireException($this->_('Structured Flex values require an InnoDB field table'));
		}
	}

	/**
	 * Read canonical field JSON, optionally locking the field row.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param bool $lock Lock the record within the current transaction.
	 * @return array
	 */
	public function persisted(Page $page, Field $field, bool $lock = false): array {
		$table = $this->wire()->database->escapeTable($field->table);
		$query = $this->wire()->database->prepare("SELECT data FROM `$table` WHERE pages_id=:page" . ($lock ? ' FOR UPDATE' : ''));
		$query->execute(['page' => $page->id]);
		$data = $query->fetchColumn();
		if($data === false || $data === '') {
			return [];
		}
		$raw = json_decode($data, true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
		if(!is_array($raw)) {
			throw new WireException($this->_('Invalid canonical Flex JSON'));
		}
		return $raw;
	}

	/**
	 * Replace searchable row projections from canonical values in the same transaction.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param array $raw Unformatted persisted values.
	 * @return void
	 */
	public function projectRows(Page $page, Field $field, array $raw): void {
		$this->requireRowSchema();
		$db = $this->wire()->database;
		$delete = $db->prepare("DELETE FROM fieldtype_flex_rows WHERE fields_id=:field AND pages_id=:page");
		$delete->execute(['field' => $field->id, 'page' => $page->id]);
		$insert = $db->prepare("INSERT INTO fieldtype_flex_rows (fields_id,pages_id,set_key,set_name,schema_key,schema_path,row_key,parent_row_key,row_id,row_type,ordinal,data) VALUES (:field,:page,:key,:name,:schema_key,:schema,:row_key,:parent,:id,:type,:sort,:data)");
		foreach(FlexPath::rows($raw) as $entry) {
			$insert->execute([
				'field'      => $field->id,
				'page'       => $page->id,
				'key'        => hash('sha256', $entry['set']),
				'name'       => $entry['set'],
				'schema_key' => hash('sha256', $entry['schema']),
				'schema'     => $entry['schema'],
				'row_key'    => hash('sha256', $entry['address']),
				'parent'     => $entry['parent'] === '' ? '' : hash('sha256', $entry['parent']),
				'id'         => $entry['row']['id'],
				'type'       => $entry['row']['type'],
				'sort'       => $entry['ordinal'],
				'data'       => self::encode($entry['data']),
			]);
		}
	}

	/**
	 * Validate the installed row schema before indexed queries and writes.
	 *
	 * @return void
	 */
	public function requireRowSchema(): void {
		$columns = $this->wire()->database->query('SHOW COLUMNS FROM fieldtype_flex_rows')->fetchAll(\PDO::FETCH_COLUMN);
		foreach(['row_type', 'schema_path', 'schema_key', 'row_key', 'parent_row_key'] as $column) {
			if(!in_array($column, $columns, true)) {
				throw new WireException($this->_('The Flex row index schema is invalid. Repair the module installation before saving or querying Flex fields.'));
			}
		}
	}

	/**
	 * Remove searchable row projections for one page and field.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return void
	 */
	public function deleteProjection(Page $page, Field $field): void {
		$q = $this->wire()->database->prepare('DELETE FROM fieldtype_flex_rows WHERE fields_id=:field AND pages_id=:page');
		$q->execute([
			'field' => $field->id,
			'page'  => $page->id,
		]);
	}

	/**
	 * Rebuild row projections for a bounded batch of saved pages.
	 *
	 * @param Field $field Owning Flex field.
	 * @param int $after Resume after this page ID.
	 * @param int $limit Maximum number of records to process.
	 * @return array
	 */
	public function rebuildRows(Field $field, int $after = 0, int $limit = 100): array {
		if(!$field->id || !$field->type instanceof FieldtypeFlex || $limit < 1 || $limit > 1000) {
			throw new WireException($this->_('Invalid Flex repair request'));
		}
		$table = $this->wire()->database->escapeTable($field->table);
		$q = $this->wire()->database->prepare("SELECT pages_id FROM `$table` WHERE pages_id>:after ORDER BY pages_id LIMIT $limit");
		$q->execute(['after' => $after]);
		$count = 0;
		$last = $after;
		foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
			$page = $this->wire()->pages->get((int) $id);
			if(!$page->id) {
				continue;
			}
			$this->atomic(function() use ($page, $field) {
				$this->projectRows($page, $field, $this->persisted($page, $field, true));
			});
			$last = (int) $id;
			++$count;
		}
		return [
			'count'      => $count,
			'lastPageId' => $last,
		];
	}

	/**
	 * Encode canonical JSON while retaining object-shaped property maps.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @return string
	 */
	public static function encode(array $raw): string {
		$convert = static function($value, int $depth = 0) use (&$convert) {
			if($depth > FlexPath::MAX_JSON_DEPTH) {
				throw new WireException(__('Flex JSON tree is cyclic or exceeds the platform depth budget'));
			}
			if(!is_array($value)) {
				return $value;
			}
			if(($value['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
				foreach($value['items'] as &$row) {
					$row['values'] = (object) $convert($row['values'], $depth + 3);
				}
				unset($row);
				return $value;
			}
			foreach($value as $key => $item) {
				$value[$key] = $convert($item, $depth + 1);
			}
			return $value;
		};
		return json_encode((object) array_map($convert, $raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, FlexPath::JSON_DEPTH);
	}

	/**
	 * Object key order is insignificant; collection order and JSON value types are not.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @return string
	 */
	public static function revision(array $raw): string {
		$sort = static function($value) use (&$sort) {
			if($value instanceof \stdClass) {
				$properties = get_object_vars($value);
				ksort($properties, SORT_STRING);
				return (object) array_map($sort, $properties);
			}
			if(is_array($value)) {
				return array_map($sort, $value);
			}
			return $value;
		};
		$document = json_decode(self::encode($raw), false, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
		return hash('sha256', json_encode($sort($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/**
	 * Collect logical content for whole-field text search without including schema keys.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @return string
	 */
	public static function search(array $raw): string {
		$terms = [];
		$walk = static function($value) use (&$walk, &$terms): void {
			if(is_array($value)) {
				if(($value['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
					foreach($value['items'] as $row) {
						$walk($row['values']);
					}
					return;
				}
				if(in_array($value['_flex'] ?? '', [FlexStorage::FILES_FORMAT, FlexStorage::IMAGES_FORMAT], true)) {
					foreach($value['items'] as $item) {
						foreach(['basename', 'description', 'tags'] as $key) {
							$walk($item[$key] ?? '');
						}
					}
					return;
				}
				foreach($value as $item) {
					$walk($item);
				}
			} else if(is_scalar($value) && (string) $value !== '') {
				$terms[] = (string) $value;
			}
		};
		$walk($raw);
		return implode(' ', $terms);
	}
}
