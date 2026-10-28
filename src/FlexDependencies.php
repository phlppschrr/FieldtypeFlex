<?php namespace ProcessWire;

/** Internal bridge from explicit Flex scopes to native Inputfield dependencies. */
final class FlexDependencies {
	/** Reconstruct ancestor scopes for standalone AJAX add/duplicate input trees. */
	public static function ancestors(FlexRow $row, FlexValue $root, string $inputName, string $draft): array {
		$ancestors = [];
		$parts = explode('.', $row->address());
		array_splice($parts, -2);
		$owner = $root;
		for($level = 0; ; $level += 2) {
			$inputs = $owner->definitions()->getInputfields($owner, FlexPath::inputPrefix($inputName, $owner->address()), $draft, $root, null, $inputName, $ancestors);
			array_unshift($ancestors, $inputs);
			if($level >= count($parts)) break;
			$address = implode('.', array_slice($parts, 0, $level + 2)) . '._dependency';
			[$owner] = FlexPath::resolve($root, $address, $draft === '' ? null : static function($set, $id) use ($root, $draft) {
				return $root->ownerField()->type->admin()->row($draft, $set, $id, $root);
			});
		}
		return $ancestors;
	}

	/** Resolve a selector field, leaving comparison syntax and values to the core. */
	public static function fieldName(string $name, ?FlexValue $value, string $inputName, string $prefix, array $names, Page $page, Field $field): string {
		[$scope, $path] = array_pad(explode('.', $name, 2), 2, '');
		if($path !== '' && in_array($scope, ['parent', 'root', 'page', 'forpage'], true)) {
			if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.count)?$/D', $path)) {
				throw self::error($field, sprintf(__('Unsupported Flex dependency path: %s'), $name));
			}
			if($scope === 'page' || $scope === 'forpage') {
				[$fieldName, $suffix] = array_pad(explode('.', $path, 2), 2, '');
				return $fieldName . ($scope === 'page' && $page instanceof RepeaterPage ? '_repeater' . $page->id : '') . ($suffix === '' ? '' : '.' . $suffix);
			}
			$address = '';
			if($scope === 'parent') {
				if(!$value instanceof FlexRow) throw self::error($field, __('A parent dependency requires a Flex row'));
				$address = implode('.', array_slice(explode('.', $value->address()), 0, -2));
			}
			return FlexPath::inputPrefix($inputName, $address) . $path;
		}
		return isset($names[$scope]) ? $prefix . $name : $name;
	}

	/** Fresh native copies also rebind helper objects owned by inputs such as Datetime. */
	public static function copyInput(Inputfield $source): Inputfield {
		$wire = $source->wire();
		$copy = $source->className() === 'InputfieldWrapper'
			? $wire->wire(new InputfieldWrapper())
			: $wire->modules->getModule($source->className(), ['noCache' => true]);
		foreach($source->getArray() as $key => $value) $copy->set($key, $value);
		foreach($source->getAttributes() as $key => $value) $copy->attr($key, $value);
		if(method_exists($source, 'getOptions') && method_exists($copy, 'addOptions')) $copy->addOptions($source->getOptions());
		$copy->label = $source->label;
		$copy->editable($source->editable());
		// An ancestor form may currently be evaluating processing aliases.
		foreach($source->flexDependencyConditions ?: [] as $key => $expression) $copy->set($key, $expression);
		$copy->flexDependencyConditions = [];
		return $copy;
	}

	/**
	 * Build a processing-only native form, including external dependency inputs.
	 * Copies are never rendered or persisted. The core processes their submitted
	 * values (including absent checkboxes), dependencies, options and validation.
	 */
	public static function form(InputfieldWrapper $inputs, Page $page): InputfieldWrapper {
		$wire = $inputs->wire();
		$form = $wire->modules->get('InputfieldForm');
		$form->protectCSRF = false; // The containing ProcessPageEdit form validates CSRF.
		foreach($inputs->children() as $child) $form->add($child);
		$ancestors = $inputs->flexDependencyAncestors ?: [];
		$root = $ancestors ? $ancestors[count($ancestors) - 1] : $inputs;
		$sources = array_merge([$inputs], $ancestors);
		$outer = $root->parent;
		while($outer instanceof InputfieldWrapper) {
			$sources[] = $outer;
			$outer = $outer->parent;
		}
		$pending = iterator_to_array($inputs->getAll(['withWrappers' => true]));
		$seen = [];
		foreach($pending as $child) $seen[$child->name] = true;
		$externalCount = 0;
		$aliases = [];
		// Appending external fields to this queue also resolves their dependencies.
		for($i = 0; $i < count($pending); ++$i) {
			$child = $pending[$i];
			foreach(['showIf', 'requiredIf'] as $key) {
				$expression = (string) $child->get($key);
				if($expression === '') continue;
				foreach($wire->wire(new Selectors($expression)) as $selector) {
					foreach($selector->fields() as $path) {
						$name = explode('.', $path, 2)[0];
						if($name === '1' || isset($seen[$name])) continue;
						if(++$externalCount > 4096) throw self::error($inputs->flexDependencyField, __('Flex dependency graph exceeds its safety limit'));
						$seen[$name] = true;
						$source = null;
						foreach($sources as $tree) {
							$source = $tree->getChildByName($name);
							if($source) break;
						}
						if(!$source) $source = self::pageInput($page, $name);
						if(!$source || $source instanceof InputfieldWrapper || $source instanceof InputfieldFlex || $source instanceof InputfieldFlexRows || $source instanceof InputfieldFile) {
							throw self::error($inputs->flexDependencyField, sprintf(__('Flex dependency field is unavailable or unsupported: %s'), $name));
						}
						$copy = self::copyInput($source);
						// Core errors are stored by class/name in the session. Isolate
						// proxies so processing cannot clear the real source's errors.
						$copy->name = $aliases[$name] = FlexPath::controlName($inputs->flexDependencyPrefix, $name, 'dependency');
						// A proxy supplies a condition, not another required input to fill.
						$copy->required = false;
						$copy->requiredIf = '';
						foreach(['showIfProcessed', 'showIfSkipped', 'requiredSkipped'] as $flag) $copy->set($flag, false);
						// Retain ancestor visibility/locking rather than accepting hidden POSTs.
						$container = $form;
						$parents = [];
						for($parent = $source->parent; $parent instanceof InputfieldWrapper; $parent = $parent->parent) {
							if($parent->showIf || !$parent->editable() || in_array((int) $parent->collapsed, [Inputfield::collapsedHidden, Inputfield::collapsedLocked, Inputfield::collapsedNoLocked, Inputfield::collapsedBlankLocked, Inputfield::collapsedYesLocked, Inputfield::collapsedTabLocked], true)) $parents[] = $parent;
						}
						foreach(array_reverse($parents) as $parent) {
							$wrapper = $wire->wire(new InputfieldWrapper());
							$wrapper->name = $name . '__dependency_' . count($pending);
							$wrapper->showIf = ($parent->flexDependencyConditions ?: [])['showIf'] ?? $parent->showIf;
							$wrapper->collapsed = $parent->collapsed;
							$wrapper->editable($parent->editable());
							$container->add($wrapper);
							$container = $wrapper;
							$pending[] = $wrapper;
						}
						$container->add($copy);
						$pending[] = $copy;
					}
				}
			}
		}
		if($aliases) {
			$restore = [];
			foreach($pending as $child) {
				foreach(['showIf', 'requiredIf'] as $key) {
					$expression = (string) $child->get($key);
					if($expression === '') continue;
					$restore[] = [$child, $key, $expression];
					$conditions = $child->flexDependencyConditions ?: [];
					$conditions[$key] = $expression;
					$child->flexDependencyConditions = $conditions;
					$selectors = $wire->wire(new Selectors($expression));
					foreach($selectors as $selector) {
						$fields = [];
						$not = $selector->not;
						foreach($selector->fields() as $path) {
							[$name, $suffix] = array_pad(explode('.', $path, 2), 2, '');
							$fields[] = ($aliases[$name] ?? $name) . ($suffix === '' ? '' : '.' . $suffix);
						}
						$selector->setField($fields);
						$selector->not = $not;
					}
					$child->set($key, (string) $selectors);
				}
			}
			$form->flexDependencyAliases = $aliases;
			$form->flexDependencyRestore = $restore;
		}
		return $form;
	}

	/** Process natively, restoring browser references even when validation throws. */
	public static function processInput(InputfieldWrapper $inputs, Page $page, WireInputData $input): InputfieldWrapper {
		$form = self::form($inputs, $page);
		$aliases = $form->flexDependencyAliases ?: [];
		if($aliases) {
			$data = $input->getArray();
			foreach($aliases as $source => $alias) {
				foreach($data as $name => $value) {
					if($name === $source || str_starts_with($name, $source . '__')) $data[$alias . substr($name, strlen($source))] = $value;
				}
			}
			$input = $inputs->wire(new WireInputData($data));
		}
		try {
			$form->processInput($input);
		} finally {
			foreach($form->flexDependencyRestore ?: [] as [$child, $key, $expression]) {
				$child->set($key, $expression);
				$child->flexDependencyConditions = [];
			}
		}
		return $form;
	}

	private static function error(Field $field, string $message): FlexDefinitionException {
		return new FlexDefinitionException($field->name, $message, FlexFieldDefs::inspectSource($field, $field->wire()));
	}

	/** Resolve native owner/editor fields when no outer form is attached (API/AJAX). */
	private static function pageInput(Page $page, string $name): ?Inputfield {
		$owner = $page;
		$suffix = '';
		$fieldName = $name;
		if(preg_match('/^(.*)(_repeater([0-9]+))$/D', $name, $match)) {
			if(!$page instanceof RepeaterPage || (int) $match[3] !== (int) $page->id) return null;
			$fieldName = $match[1];
			$suffix = $match[2];
		} else if($page instanceof RepeaterPage) {
			$owner = $page->getForPageRoot();
		}
		if(!$owner->hasField($fieldName)) return null;
		$fields = $owner->getFields();
		$field = $fields->get($fieldName);
		if(!$field || $field->type instanceof FieldtypeFlex) return null;
		if($fields instanceof Fieldgroup) $field = $fields->getFieldContext($field);
		$input = $field->getInputfield($owner, $suffix);
		if($input) {
			$value = $owner->getUnformatted($fieldName);
			if($value instanceof LanguagesPageFieldValue) $value->setToInputfield($input);
			else $input->value = $value;
		}
		return $input;
	}
}
