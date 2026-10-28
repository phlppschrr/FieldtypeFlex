<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Development-only experiment. No module endpoint or public API uses this class.
 *
 * Build a full native validation form on POST, substituting server baseline input
 * values only for bodies that have never been issued to this editor. Thus native
 * processInput(), pattern/length checks and InputfieldForm dependencies still run.
 * Unloaded canonical values are never replaced by their validation-only copies.
 * Grants must come from a trusted server render registry, never request data.
 */
final class PartialFlexSubmission {
	public static function process(FlexValue $baseline, string $draft, array $grants, array $post): array {
		$wire = $baseline->wire();
		$admin = $baseline->ownerField()->type->admin();
		$state = $admin->draft($draft);
		$inputName = $state['input'];
		$admin->open($baseline, $inputName, $draft);
		$admin->assertFresh($draft, $baseline->ownerPage(), $baseline->ownerField());
		if(!isset($grants['']) || ($post[$inputName . '__0draft'] ?? null) !== $draft || ($post[$inputName . '__0complete'] ?? null) !== $draft) {
			throw new WireException('Incomplete prototype root submission');
		}
		$effective = $post;
		$scopes = [];
		$collections = [];
		$seen = [];
		$allowed = [$inputName . '__0draft' => true, $inputName . '__0complete' => true];
		$root = clone $baseline;
		$root->of(false);
		$build = function(FlexValue $value) use (&$build, &$effective, &$scopes, &$collections, &$seen, &$allowed, $grants, $post, $wire, $admin, $draft, $root, $inputName): InputfieldWrapper {
			$address = $value->address();
			$loaded = array_key_exists($address, $grants);
			$seen[$address] = true;
			if($loaded) {
				foreach(['body', 'complete'] as $suffix) {
					$key = FlexPath::controlName($inputName, $address, $suffix);
					$allowed[$key] = true;
					if(!is_string($grants[$address]) || !preg_match('/^[a-f0-9]{32}$/D', $grants[$address]) || ($post[$key] ?? null) !== $grants[$address]) {
						throw new WireException('Incomplete prototype body: ' . $address);
					}
				}
			}
			$prefix = FlexPath::inputPrefix($inputName, $address);
			$view = $admin->overlay($draft, $value);
			$inputs = $view->definitions()->getInputfields($view, $prefix, $draft, $root, $root, $inputName);
			$scopes[$address] = [$value, $loaded, $prefix];
			$convert = function(InputfieldWrapper $source) use (&$convert, &$build, &$collections, &$effective, &$allowed, $grants, $post, $loaded, $wire, $value, $admin, $draft, $root, $inputName): InputfieldWrapper {
				$target = $wire->wire(new InputfieldWrapper());
				foreach($source->children() as $child) {
					if($child instanceof InputfieldFlexRows) {
						// Replacing this atomic input with a validation wrapper does not yet
						// model collection-level dependency/required semantics.
						if($child->showIf || $child->requiredIf || $child->required || !$child->editable() || $child->disabled || (int) $child->collapsed !== 0) {
							throw new WireException('Prototype requires eager collection validation');
						}
						$name = (string) $child->_flex_property;
						$set = $child->setName;
						$before = $value->getUnformatted($name);
						$ids = array_map(fn($row) => $row->rowId(), array_values($before->getArray()));
						if($loaded) {
							$present = FlexPath::controlName($inputName, $set, 'present');
							$order = FlexPath::controlName($inputName, $set, 'order');
							$allowed[$present] = $allowed[$order] = true;
							if(($post[$present] ?? null) !== '1' || !is_string($post[$order] ?? null)) {
								throw new WireException('Incomplete prototype collection: ' . $set);
							}
							$ids = $post[$order] === '' ? [] : explode(',', $post[$order]);
							if(count($ids) !== count(array_unique($ids))) throw new WireException('Duplicate prototype row');
						}
						$rows = new FlexRows($value->ownerPage(), $value->ownerField(), $set, $child->definition);
						$wrapper = $wire->wire(new InputfieldWrapper());
						foreach($ids as $id) {
							if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new WireException('Invalid prototype row ID');
							$row = $admin->row($draft, $set, $id, $root, false);
							if(!$loaded && array_key_exists($row->address(), $grants)) throw new WireException('Loaded body below unloaded parent');
							$wrapper->add($build($row));
							$rows->add($row);
						}
						$collections[] = [$value, $name, $rows];
						$target->add($wrapper);
					} else if($child instanceof InputfieldWrapper) {
						// Retain ordinary fieldset settings and the flat property scope.
						$children = $convert($child);
						foreach(array_values($child->children()->getArray()) as $old) $child->remove($old);
						foreach($children->children() as $leaf) $child->add($leaf);
						$target->add($child);
					} else {
						if($loaded) {
							$allowed[$child->name] = true;
						} else {
							$effective[$child->name] = self::baselineInput($child, $value->getUnformatted((string) $child->_flex_property));
						}
						$target->add($child);
					}
				}
				return $target;
			};
			return $convert($inputs);
		};
		$form = $wire->modules->get('InputfieldForm');
		$form->protectCSRF = false; // CLI only; a future endpoint must validate CSRF itself.
		foreach($build($root)->children() as $child) $form->add($child);
		foreach($grants as $address => $grant) {
			if(!isset($seen[$address])) throw new WireException('Unknown or removed prototype body grant');
		}
		foreach($post as $key => $unused) {
			if(!isset($allowed[$key])) throw new WireException('Unexpected prototype input: ' . $key);
		}
		$form->processInput($wire->wire(new WireInputData($effective)));
		$errors = $form->getErrors();
		if($errors) return ['value' => null, 'errors' => $errors];
		foreach($scopes as [$value, $loaded, $prefix]) {
			if(!$loaded) continue;
			$leaves = $wire->wire(new InputfieldWrapper());
			foreach($value->definitions()->properties() as $name => $definition) {
				if($definition['kind'] === 'rows') continue;
				$child = $form->getChildByName($prefix . $name);
				if($child) $leaves->add($child);
			}
			InputfieldFlex::applyInputs($value, $leaves, $prefix);
		}
		// Children have been assembled first; their objects remain in their parent rows.
		foreach($collections as [$value, $name, $rows]) $value->set($name, $rows);
		$root->validateTree();
		$root->expectedRevision($state['hash']);
		return ['value' => $root, 'errors' => []];
	}

	private static function baselineInput(Inputfield $input, $value) {
		// Exact classes only: subclasses and specialized native inputs may use
		// different transport (dates, languages, Page references, media, editors).
		if(!in_array($input->className(), ['InputfieldText', 'InputfieldTextarea', 'InputfieldEmail', 'InputfieldURL', 'InputfieldInteger', 'InputfieldFloat', 'InputfieldCheckbox', 'InputfieldSelect', 'InputfieldSelectMultiple', 'InputfieldCheckboxes', 'InputfieldRadios'], true) || $input->useLanguages) {
			throw new WireException('Prototype requires eager input: ' . $input->className());
		}
		if($input instanceof InputfieldCheckbox) {
			if(!in_array($input->uncheckedValue, ['', 0, '0'], true)) throw new WireException('Prototype requires eager custom checkbox transport');
			return $value === $input->checkedValue ? $input->checkedValue : null;
		}
		if(!is_scalar($value) && $value !== null && (!is_array($value) || count(array_filter($value, 'is_scalar')) !== count($value))) {
			throw new WireException('Unsupported prototype baseline value');
		}
		return $value;
	}
}
