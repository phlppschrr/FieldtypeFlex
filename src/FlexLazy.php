<?php namespace ProcessWire;

/** Internal transport for optional, scalar-only lazy row bodies. */
final class FlexLazy {
	/** Limit baseline-to-POST conversion to proven ordinary native transports. */
	public static function supports(FlexFieldDefs $defs): bool {
		foreach($defs->properties() as $definition) {
			$input = $definition['input'];
			if($definition['languages'] || !in_array($input->className(), ['InputfieldText', 'InputfieldTextarea', 'InputfieldEmail', 'InputfieldURL', 'InputfieldInteger', 'InputfieldFloat', 'InputfieldCheckbox', 'InputfieldSelect', 'InputfieldSelectMultiple', 'InputfieldCheckboxes', 'InputfieldRadios'], true)) return false;
			if($input instanceof InputfieldCheckbox && !in_array($input->uncheckedValue, ['', 0, '0'], true)) return false;
			if(in_array((int) $input->collapsed, [Inputfield::collapsedYesAjax, Inputfield::collapsedBlankAjax, Inputfield::collapsedTabAjax], true)) return false;
		}
		$walk = static function(array $nodes) use (&$walk): bool {
			foreach($nodes as $node) {
				if($node['kind'] !== 'fieldset') continue;
				if(!in_array($node['type'], ['InputfieldWrapper', 'InputfieldFieldset'], true) || !$walk($node['children'])) return false;
			}
			return true;
		};
		return $walk($defs->structure());
	}

	/** Fingerprint effective, page-aware schema settings, including inherited inputs. */
	private static function signature(FlexFieldDefs $defs): string {
		static $cache;
		$cache ??= new \WeakMap();
		if(isset($cache[$defs])) return $cache[$defs];
		$plain = static function($value) use (&$plain) {
			if(is_object($value)) return get_class($value);
			if(is_array($value)) return array_map($plain, $value);
			return $value;
		};
		$properties = [];
		foreach($defs->properties() as $name => $definition) {
			$input = $definition['input'];
			// Fingerprint semantic configuration, not render helpers, generated IDs or
			// module state that legitimately differs between initial and AJAX requests.
			$effective = [];
			foreach(['required', 'requiredIf', 'showIf', 'collapsed', 'disabled', 'useLanguages', 'checkedValue', 'uncheckedValue', 'minlength', 'maxlength', 'min', 'max', 'minValue', 'maxValue', 'pattern', 'stripTags', 'noTrim', 'precision', 'digits', 'initValue'] as $key) $effective[$key] = $plain($input->get($key));
			$effective['editable'] = $input->editable();
			if(method_exists($input, 'getOptions')) $effective['options'] = $plain($input->getOptions());
			$properties[$name] = [$plain($definition['settings']), $effective];
			foreach($definition['types'] ?? [] as $type => $entry) $properties[$name]['types'][$type] = self::signature($entry['schema']);
		}
		return $cache[$defs] = hash('sha256', json_encode([$defs->structure(), $properties], JSON_THROW_ON_ERROR));
	}

	/** Bind renders, later AJAX and POST to the same evaluated definitions. */
	public static function assertSchema(FlexValue $root, string $token): void {
		$admin = $root->ownerField()->type->admin();
		$draft = $admin->draft($token);
		if(isset($draft['lazySchema']) && $draft['lazySchema'] !== self::signature($root->definitions())) {
			throw new WireException(__('The Flex definition changed. Reload the editor before continuing.'));
		}
	}

	/** A successful server render issues a stable grant; retries never downgrade it. */
	public static function register(FlexValue $root, FlexRow $row, string $token, bool $loaded): array {
		self::assertSchema($root, $token);
		$admin = $root->ownerField()->type->admin();
		$draft = $admin->draft($token);
		$entry = $draft['lazy'][$row->address()] ?? ['type' => $row->rowType(), 'grant' => bin2hex(random_bytes(16)), 'loaded' => false];
		$entry['loaded'] = $entry['loaded'] || $loaded;
		$draft['lazySchema'] = self::signature($root->definitions());
		$draft['lazy'][$row->address()] = $entry;
		$admin->put($token, $draft);
		return $entry;
	}

	public static function unloaded(FlexValue $root, string $token, string $address): bool {
		if($token === '') return false;
		$entry = $root->ownerField()->type->admin()->draft($token)['lazy'][$address] ?? null;
		return $entry !== null && !$entry['loaded'];
	}

	/** Body markers enclose the actual inputs, independently of root completeness. */
	public static function marker(string $name, string $address, string $suffix, string $grant): string {
		return '<input type="hidden" name="' . htmlspecialchars(FlexPath::controlName($name, $address, $suffix), ENT_QUOTES, 'UTF-8') . '" value="' . $grant . '">';
	}

	/**
	 * Supply only unloaded scalar baselines to the existing native processing path.
	 * All row validators still execute; callers must not apply validation-only leaves.
	 * Removed rows have no body obligation. Unknown keys are retained by FlexValue.
	 */
	public static function prepare(FlexValue $root, string $token, WireInputData $input): WireInputData {
		$admin = $root->ownerField()->type->admin();
		$draft = $admin->draft($token);
		if(empty($draft['lazy'])) return $input;
		self::assertSchema($root, $token);
		$data = $input->getArray();
		$name = $draft['input'];
		$postedScopes = [];
		foreach($data as $key => $unused) {
			if(preg_match('/^' . preg_quote($name, '/') . '__9([a-f0-9]{64})__/', $key, $match)) $postedScopes[$match[1]] = true;
		}
		$walk = function(FlexValue $owner) use (&$walk, &$data, $input, $root, $token, $admin, $draft, $name, $postedScopes): void {
			foreach($owner->definitions()->properties() as $property => $definition) {
				if($definition['kind'] !== 'rows') continue;
				$set = ($owner->address() === '' ? '' : $owner->address() . '.') . $property;
				$present = FlexPath::controlName($name, $set, 'present');
				if((string) $input->$present !== '1') {
					foreach($draft['lazy'] as $address => $entry) {
						if(str_starts_with($address, $set . '.')) throw new WireException(__('Incomplete Flex form submission; reload and check the PHP input limits'));
					}
					continue;
				}
				$order = $input->{FlexPath::controlName($name, $set, 'order')};
				if(!is_string($order)) throw new WireException(__('Missing repeatable order'));
				$ids = $order === '' ? [] : explode(',', $order);
				if(count(array_unique($ids)) !== count($ids)) throw new WireException(__('Duplicate repeatable row'));
				foreach($ids as $id) {
					$row = $admin->row($token, $set, $id, $root, false);
					$address = $row->address();
					if(InputfieldFlexRows::removalRequested($input, $name, $address)) continue;
					$entry = $draft['lazy'][$address] ?? null;
					if(($definition['settings']['rowLazyLoad'] ?? false) && self::supports($row->definitions())) {
						if(!$entry || $entry['type'] !== $row->rowType()) throw new WirePermissionException(__('Invalid Flex editor context'));
					}
					if($entry) {
						foreach(['body', 'complete'] as $suffix) {
							$posted = $input->{FlexPath::controlName($name, $address, $suffix)};
							if(($entry['loaded'] && $posted !== $entry['grant']) || (!$entry['loaded'] && $posted !== null)) throw new WireException(__('Incomplete Flex form submission; reload and check the PHP input limits'));
						}
						if(!$entry['loaded']) {
							if(isset($postedScopes[hash('sha256', $address)])) throw new WirePermissionException(__('Invalid Flex editor context'));
							$prefix = FlexPath::inputPrefix($name, $address);
							foreach($row->definitions()->properties() as $leaf => $def) {
								$value = $row->getUnformatted($leaf);
								$data[$prefix . $leaf] = $def['input'] instanceof InputfieldCheckbox ? ($value === $def['input']->checkedValue ? $value : null) : $value;
							}
						}
					}
					$walk($row);
				}
			}
		};
		$walk($root);
		return $root->wire(new WireInputData($data));
	}
}
