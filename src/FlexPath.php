<?php namespace ProcessWire;

/** Internal instance addressing and bounded traversal shared by storage and editor code. */
final class FlexPath {
	/** Missing metadata is visible; reject malformed stored states everywhere. @internal */
	public static function rowHidden(array $row): bool {
		if(array_key_exists('hidden', $row) && !is_bool($row['hidden'])) {
			throw new WireException(__('Invalid Flex row visibility'));
		}
		return $row['hidden'] ?? false;
	}

	public const JSON_DEPTH = 512;
	public const MAX_JSON_DEPTH = 100;

	/**
	 * Return a bounded native input prefix; numeric scope tags cannot be property names.
	 *
	 * @param string $input Outer rendered field name, including native Repeater context.
	 * @param string $row Concrete row address, or blank for root leaves.
	 * @return string
	 * @internal
	 */
	public static function inputPrefix(string $input, string $row = ''): string {
		return $input . '__' . ($row === '' ? '' : '9' . hash('sha256', $row) . '__');
	}

	/**
	 * Return a collision-free collection/media transport control name.
	 *
	 * @param string $input Outer rendered field name.
	 * @param string $address Concrete property address.
	 * @param string $suffix Internal control identifier.
	 * @return string
	 * @internal
	 */
	public static function controlName(string $input, string $address, string $suffix): string {
		return $input . '__0' . hash('sha256', $address) . '__' . $suffix;
	}

	/**
	 * Resolve a property owner through concrete row identities.
	 *
	 * @param FlexValue $root Root field value.
	 * @param string $address Property or collection instance address.
	 * @param callable|null $loadRow Optional authenticated draft row loader.
	 * @return array{FlexValue,string}
	 * @internal
	 */
	public static function resolve(FlexValue $root, string $address, ?callable $loadRow = null): array {
		$parts = explode('.', $address);
		if(count($parts) % 2 !== 1) {
			throw new WireException(__('Invalid Flex instance address'));
		}
		$parent = $root;
		$prefix = '';
		while(count($parts) > 1) {
			$set = array_shift($parts);
			$id = array_shift($parts);
			$definition = $parent->definitions()->property($set);
			if(!$definition || $definition['kind'] !== 'rows' || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
				throw new WireException(sprintf(__('Invalid Flex row address: %s'), $address));
			}
			$setAddress = $prefix . $set;
			$row = $loadRow ? $loadRow($setAddress, $id, $parent, $definition) : $parent->getUnformatted($set)->getById($id);
			if(!$row instanceof FlexRow) {
				throw new WireException(sprintf(__('Flex row no longer exists: %s'), $setAddress . '.' . $id));
			}
			$parent = $row;
			$prefix = $setAddress . '.' . $id . '.';
		}
		$leaf = $parts[0];
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $leaf)) {
			throw new WireException(__('Invalid Flex property address'));
		}
		return [$parent, $leaf];
	}

	/**
	 * Resolve a canonical raw property by reference, without creating missing rows.
	 *
	 * @param array $raw Canonical field data.
	 * @param string $address Instance address.
	 * @return mixed
	 * @internal
	 */
	public static function &rawProperty(array &$raw, string $address) {
		$parts = explode('.', $address);
		if(count($parts) % 2 !== 1) {
			throw new WireException(__('Invalid Flex instance address'));
		}
		$scope =& $raw;
		while(count($parts) > 1) {
			$set = array_shift($parts);
			$id = array_shift($parts);
			$found = false;
			if(($scope[$set]['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
				foreach($scope[$set]['items'] as $n => $row) {
					if($row['id'] === $id) {
						$scope =& $scope[$set]['items'][$n]['values'];
						$found = true;
						break;
					}
				}
			}
			if(!$found) {
				throw new WireException(sprintf(__('Flex row no longer exists: %s'), $address));
			}
		}
		if(!array_key_exists($parts[0], $scope)) {
			throw new WireException(sprintf(__('Flex property no longer exists: %s'), $address));
		}
		return $scope[$parts[0]];
	}

	/**
	 * Enumerate raw rows, preserving full instance and type-qualified schema paths.
	 *
	 * @param array $raw Raw properties, including retained unknown sets.
	 * @param string $prefix Instance prefix.
	 * @param string $schema Schema prefix.
	 * @param int $depth Current repeatable depth.
	 * @return \Generator
	 * @internal
	 */
	public static function rows(array $raw, string $prefix = '', string $schema = '', int $depth = 0): \Generator {
		if($depth > 64) {
			throw new WireException(__('Flex tree exceeds the traversal safety limit'));
		}
		foreach($raw as $name => $value) {
			if(!is_array($value) || ($value['_flex'] ?? '') !== FlexStorage::ROWS_FORMAT) {
				continue;
			}
			if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', (string) $name) || !isset($value['items']) || !is_array($value['items']) || !array_is_list($value['items'])) {
				throw new WireException(__('Invalid retained Flex row collection'));
			}
			$identities = [];
			$set = $prefix . $name;
			$schemaPath = $schema . $name;
			foreach($value['items'] as $ordinal => $row) {
				if(!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $row['id']) || !is_string($row['type'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $row['type']) || $row['type'] === 'count' || !is_array($row['values'] ?? null) || isset($identities[$row['id']])) {
					throw new WireException(sprintf(__('Invalid retained Flex row at %s'), $set));
				}
				self::rowHidden($row);
				$identities[$row['id']] = true;
				$address = $set . '.' . $row['id'];
				$data = array_filter($row['values'], static fn($v) => !is_array($v) || ($v['_flex'] ?? '') !== FlexStorage::ROWS_FORMAT);
				yield [
					'set'     => $set,
					'schema'  => $schemaPath,
					'address' => $address,
					'parent'  => rtrim($prefix, '.'),
					'depth'   => $depth + 1,
					'ordinal' => $ordinal,
					'row'     => $row,
					'data'    => $data,
				];
				yield from self::rows($row['values'], $address . '.', $schemaPath . '.' . $row['type'] . '.', $depth + 1);
			}
		}
	}

	/**
	 * Check raw size, platform JSON depth and configurable field tree budgets before writes.
	 *
	 * @param array $raw Unformatted field data.
	 * @param Field $field Owning field; zero disables an additional field budget.
	 * @return void
	 * @internal
	 */
	public static function validate(array $raw, Field $field): void {
		$json = FlexStorage::encode($raw);
		$bytes = $field->flexMaxBytes === null ? 8388608 : (int) $field->flexMaxBytes;
		if($bytes > 0 && strlen($json) > $bytes) {
			throw new WireException(__('Flex value exceeds its byte budget'));
		}
		$platformDepth = stripos($field->wire()->database->getServerType(), 'maria') !== false ? 32 : self::MAX_JSON_DEPTH;
		try {
			json_decode($json, true, $platformDepth, JSON_THROW_ON_ERROR);
		} catch(\JsonException $error) {
			throw new WireException(sprintf(__('Flex JSON exceeds the database depth budget of %d'), $platformDepth), 0, $error);
		}
		$maxRows = $field->flexMaxRows === null ? 2000 : (int) $field->flexMaxRows;
		$maxDepth = (int) $field->flexMaxDepth;
		$count = 0;
		foreach(self::rows($raw) as $entry) {
			++$count;
			if(($maxRows > 0 && $count > $maxRows) || ($maxDepth > 0 && $entry['depth'] > $maxDepth)) {
				throw new WireException(sprintf(__('Flex tree budget exceeded at %s'), $entry['address']));
			}
		}
	}
}
