<?php namespace ProcessWire;

/** Generates IDE-only class stubs from an evaluated definition. @internal */
final class FlexPhpDoc {
	/** Include both formatted and unformatted runtime types without reading stored values. */
	public static function render(Field $field, FlexFieldDefs $defs): string {
		$classes = [];
		$usedNames = [];
		$name = static function(string $base, array $path = []) use ($field, &$usedNames): string {
			$stem = $base . '_' . $field->name . ($path ? '_' . implode('_', $path) : '');
			$candidate = $stem;
			for($suffix = 2; isset($usedNames[strtolower($candidate)]) || class_exists(__NAMESPACE__ . '\\' . $candidate); $suffix++) {
				$candidate = $stem . '_' . $suffix;
			}
			$usedNames[strtolower($candidate)] = true;
			return $candidate;
		};
		$root = $name('FlexValue');
		$walk = function(FlexFieldDefs $schema, string $class, string $base, array $path = []) use (&$walk, &$classes, $name, $root, $field): void {
			$properties = [];
			foreach($schema->properties() as $property => $def) {
				$input = $def['input'];
				$propertyPath = array_merge($path, [$property]);
				if($def['kind'] === 'object') {
					$type = $name('FlexValue', $propertyPath);
					$walk($def['schema'], $type, 'FlexValue', $propertyPath);
				} elseif($def['kind'] === 'rows') {
					$collection = $name('FlexRows', $propertyPath);
					$rows = [];
					foreach($def['types'] as $key => $row) {
						$rowPath = array_merge($propertyPath, [$key]);
						$rows[] = $rowClass = $name('FlexRow', $rowPath);
						$walk($row['schema'], $rowClass, 'FlexRow', $rowPath);
					}
					$rowType = implode('|', $rows);
					$classes[] = "/**\n * @method $rowType|false first()\n * @method $rowType|false last()\n */\nclass $collection extends FlexRows {}";
					$type = $collection . '|' . implode('|', array_map(static fn($row) => $row . '[]', $rows));
				} elseif(in_array($def['kind'], ['file', 'image'], true)) {
					$image = $def['kind'] === 'image';
					$item = $image ? 'FlexPageimage' : 'FlexPagefile';
					$collection = $image ? 'FlexImages' : 'FlexFiles';
					if($def['customFields']->properties()) {
						$itemClass = $name($item, $propertyPath);
						$walk($def['customFields'], $itemClass, $item, $propertyPath);
						$item = $itemClass;
					}
					$type = $collection . '|' . $item . '[]';
					$settings = $def['settings'];
					if($settings['outputFormat'] === 2 || ($settings['outputFormat'] === 0 && $settings['maxFiles'] === 1)) {
						$type .= '|' . $item . '|null';
					}
				} elseif($input instanceof InputfieldToggle) {
					$type = match($def['settings']['formatType']) {
						1 => 'int|string|bool',
						default => 'int|string',
					};
				} else {
					$type = match($def['kind']) {
						'integer', 'date' => 'int|string',
						'float' => 'float|string',
						'checkbox' => self::scalarType($input->checkedValue) . '|' . self::scalarType($input->uncheckedValue === '' ? 0 : $input->uncheckedValue),
						'array' => 'string[]',
						'page' => match((int) $input->derefAsPage) {
							1 => 'Page|false',
							2 => 'Page|NullPage',
							default => 'PageArray',
						},
						default => !empty($def['languages']) ? 'string|LanguagesPageFieldValue' : 'string',
					};
				}
				$label = html_entity_decode(strip_tags((string) $input->label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$label = str_replace('*/', '* /', trim(preg_replace('/\s+/', ' ', $label)));
				$properties[] = ' * @property ' . implode('|', array_unique(explode('|', $type))) . ' $' . $property . ($label !== '' ? ' ' . $label : '');
			}
			$title = $class === $root ? " * Flex field: \$page->" . $field->name . "\n *\n" : '';
			$classes[] = "/**\n" . $title . ($properties ? implode("\n", $properties) . "\n" : " * No properties in this context.\n") . " */\nclass $class extends $base {}";
		};
		$walk($defs, $root, 'FlexValue');
		return "<?php namespace ProcessWire;\n\n" . implode("\n\n", array_reverse($classes)) . "\n";
	}

	private static function scalarType($value): string {
		return match(true) {
			is_int($value) => 'int',
			is_float($value) => 'float',
			is_bool($value) => 'bool',
			$value === null => 'null',
			default => 'string',
		};
	}
}
