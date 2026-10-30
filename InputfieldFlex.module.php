<?php namespace ProcessWire;

/** An atomic page input containing native, independently namespaced ProcessWire inputs. */
class InputfieldFlex extends Inputfield {
	private ?InputfieldWrapper $flexInputs = null;
	private string $flexDraft = '';
	private bool $flexProcessed = false;

	/**
	 * Return module metadata and installation requirements.
	 *
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo() {
		return [
			'title'    => __('Flex'),
			'version'  => '0.4.0',
			'summary'  => __('Schema-defined compound input'),
			'requires' => ['ProcessWire>=3.0.259', 'PHP>=8.1.0', 'FieldtypeFlex'],
		];
	}

	/**
	 * Build or retrieve scoped native inputs with the authenticated draft overlay.
	 *
	 * @return InputfieldWrapper
	 */
	public function inputs(): InputfieldWrapper {
		if($this->flexInputs) {
			return $this->flexInputs;
		}
		$page = $this->hasPage;
		$field = $this->hasField;
		if(!$page instanceof Page || !$field instanceof Field) {
			throw new WireException($this->_('InputfieldFlex needs hasPage and hasField'));
		}
		$value = $this->value;
		if($value instanceof FlexInvalidValue) {
			throw $value->definitionError();
		}
		if(!$value instanceof FlexValue) {
			$value = $field->type->sanitizeValue($page, $field, $value);
		}
		if($value instanceof FlexInvalidValue) {
			throw $value->definitionError();
		}
		// Ready Repeater items can receive their concrete type after value hydration.
		// Bind the editor to current definitions, retaining the detached raw edits
		// and optimistic revision rather than replacing the Page's cached value.
		$current = new FlexValue($page, $field, $field->type->getDefinitions($field, $page), $value->rawArray());
		$current->expectedRevision($value->expectedRevision());
		$this->value = $value = $current;
		if($this->flexDraft === '') {
			$this->flexDraft = $field->type->admin()->open($value, $this->name, $this->wire()->input->post($this->name . '__0draft'));
		}
		$value = $field->type->admin()->overlay($this->flexDraft, $value);
		try {
			$this->flexInputs = $value->definitions()->getInputfields($value, $this->name . '__', $this->flexDraft);
		} catch(\Throwable $error) {
			throw $this->definitionError($error);
		}
		foreach($this->flexInputs->getAll() as $child) {
			if($child instanceof InputfieldFlexRows) {
				$child->flexBaseRoot = $this->value instanceof FlexValue ? $this->value : $value;
			}
		}
		if($this->parent instanceof InputfieldWrapper) {
			$this->flexInputs->setParent($this->parent);
		}
		return $this->flexInputs;
	}

	private function reportDefinitionError(\Throwable $error): void {
		$error = $this->definitionError($error);
		if($this->hasField instanceof Field && !$this->hasField->type->isPageEditRequest()) {
			throw $error;
		}
		if($this->hasPage instanceof Page && $this->hasField instanceof Field) {
			$this->hasField->type->markAdminDefinitionError($this->hasPage, $this->hasField, $error);
			$this->hasField->type->logDefinitionError($this->hasPage, $this->hasField, $error);
		}
		$message = $error->getMessage();
		if(!in_array($message, $this->getErrors(), true)) {
			$this->error($message);
		}
	}

	private function lockedDefinitionMarkup(): string {
		$message = $this->_('Editing is disabled while the Flex definition has an error. Existing values are preserved.');
		return '<p class="notice-error">' . $this->wire()->sanitizer->entities($message) . '</p>';
	}

	/** Preserve definition errors and classify only known native configuration failures. */
	private function definitionError(\Throwable $error): FlexDefinitionException {
		if($error instanceof FlexDefinitionException) {
			return $error;
		}
		if($this->hasPage instanceof Page && $this->hasField instanceof Field) {
			$definitionError = $this->hasField->type->nativeInputDefinitionError($this->hasPage, $this->hasField, $error);
			if($definitionError) {
				return $definitionError;
			}
		}
		throw $error;
	}

	/** Do not turn storage failures into recoverable form validation errors. */
	private function isDatabaseInputFailure(\Throwable $error): bool {
		return $error instanceof WireDatabaseException || $error instanceof WireDatabaseQueryException || $error instanceof \PDOException;
	}

	/** Record ordinary admin input failures separately from Flex definition failures. */
	private function reportAdminInputError(string $message): void {
		if($this->hasPage instanceof Page && $this->hasField instanceof Field) {
			$this->hasField->type->markAdminInputError($this->hasPage, $this->hasField, $message);
		}
		$this->error($message);
	}

	/**
	 * Load native input assets before rendering root or AJAX Repeater inputs.
	 *
	 * @param Inputfield|null $parent
	 * @param bool $renderValueMode Prepare read-only value rendering.
	 * @return bool
	 */
	public function renderReady(?Inputfield $parent = null, $renderValueMode = false) {
		// A preloaded editor may be reused after a ready item's type is assigned.
		// Once processed, retain native input errors and submitted values.
		if(!$this->flexProcessed) $this->flexInputs = null;
		$loaded = parent::renderReady($parent, $renderValueMode);
		$base = dirname($this->wire()->modules->getModuleFile('FieldtypeFlex', ['getURL' => true])) . '/';
		$this->wire()->config->scripts->add($base . 'FieldtypeFlex.js');
		$this->wire()->config->styles->add($base . 'FieldtypeFlex.css');
		$this->wire()->config->js('FieldtypeFlex', [
			'unableToAdd'           => $this->_('Unable to add item'),
			'unableToLoad'          => $this->_('Unable to load item'),
			'loading'               => $this->_('Loading…'),
			'requestPending'        => $this->_('Wait for the current request to finish before saving.'),
			'uploading'             => $this->_('Uploading {name}…'),
			'unableToUpload'        => $this->_('Unable to upload file'),
			'unableToRefreshImage'  => $this->_('Unable to refresh image'),
			'itemMoved'             => $this->_('Item moved to position {position} of {count}'),
			'removeAll'             => $this->_('Remove all items?'),
			'inputVarsExceeded'     => $this->_('This form contains {count} input values, exceeding PHP max_input_vars={max}. Reduce the content or ask the administrator to raise the PHP limit before saving.'),
		]);
		// Repeaters preload only their direct subfields. Flex is atomic to the outer form,
		// so preload its native children even when the item HTML will arrive later via AJAX.
		try {
			// Core's Repeater asset preload uses an unsuffixed field name. It must
			// not consume a root field's POST draft when both use the same Field.
			if($parent === null && $this->hasPage instanceof RepeaterPage && $this->name === $this->hasField->name) {
				$value = $this->hasPage->getUnformatted($this->hasField->name);
				$name = $this->name . '_repeater' . $this->hasPage->id;
				$assets = $this->hasField->type->getDefinitions($this->hasField, $this->hasPage)->getInputfields($value, $name . '__', '', $value, null, $name);
			} else {
				$assets = $this->inputs();
			}
			foreach($assets->getAll(['withWrappers' => true]) as $child) {
				if($child->renderReady($child->parent, $renderValueMode)) {
					$loaded = true;
				}
			}
		} catch(\Throwable $e) {
			$this->reportDefinitionError($e);
		}
		return $loaded;
	}

	/**
	 * Render the editable native inputs and their scoped transport controls.
	 *
	 * @return string
	 */
	public function ___render() {
		try {
			$html = $this->inputs()->render();
			return '<input type="hidden" name="' . $this->wire()->sanitizer->entities($this->name . '__0draft') . '" value="' . $this->flexDraft . '">' . $html . '<input type="hidden" name="' . $this->wire()->sanitizer->entities($this->name . '__0complete') . '" value="' . $this->flexDraft . '">';
		} catch(\Throwable $e) {
			$this->reportDefinitionError($e);
			return $this->lockedDefinitionMarkup();
		}
	}

	/**
	 * Render native child values without editor controls.
	 *
	 * @return string
	 */
	public function ___renderValue() {
		try {
			return $this->inputs()->renderValue();
		} catch(\Throwable $e) {
			$this->reportDefinitionError($e);
			return $this->lockedDefinitionMarkup();
		}
	}

	/**
	 * Validate submitted native inputs while preserving draft and error recovery state.
	 *
	 * @param WireInputData $input
	 * @return $this
	 */
	public function ___processInput(WireInputData $input) {
		if(!$this->flexProcessed) $this->flexInputs = null;
		$this->flexProcessed = true;
		if($this->value instanceof FlexInvalidValue) {
			$this->reportDefinitionError($this->value->definitionError());
			return $this;
		}
		try {
			$postedDraft = $input->{$this->name . '__0draft'};
			if(!$postedDraft) {
				foreach($this->hasField->type->getDefinitions($this->hasField, $this->hasPage)->properties() as $property => $definition) {
					if(in_array($definition['kind'], ['rows', 'file', 'image'], true) && $input->{FlexPath::controlName($this->name, $property, 'present')}) {
						throw new WirePermissionException($this->_('Missing Flex editor draft'));
					}
				}
			}
			if($postedDraft) {
				if($input->{$this->name . '__0complete'} !== $postedDraft) {
					throw new WireException($this->_('Incomplete Flex form submission; reload and check the PHP input limits'));
				}
				if($this->flexDraft !== $postedDraft) {
					$this->flexDraft = '';
					$this->flexInputs = null;
				}
				$value = $this->value instanceof FlexValue ? $this->value : $this->hasField->type->sanitizeValue($this->hasPage, $this->hasField, $this->value);
				$this->flexDraft = $this->hasField->type->admin()->open($value, $this->name, (string) $postedDraft);
				$this->hasField->type->admin()->assertFresh($this->flexDraft, $this->hasPage, $this->hasField);
			}
		} catch(FlexDefinitionException $error) {
			$this->reportDefinitionError($error);
			return $this;
		} catch(\Throwable $error) {
			if($this->isDatabaseInputFailure($error)) {
				throw $error;
			}
			$this->reportAdminInputError($error->getMessage());
			return $this;
		}
		try {
			$inputs = $this->inputs();
			if($this->flexDraft !== '') $input = FlexLazy::prepare($this->value, $this->flexDraft, $input);
		} catch(FlexDefinitionException $error) {
			$this->reportDefinitionError($error);
			return $this;
		} catch(WireException $error) {
			if($this->isDatabaseInputFailure($error)) throw $error;
			$this->reportAdminInputError($error->getMessage());
			return $this;
		}
		try {
			$form = FlexDependencies::processInput($inputs, $this->hasPage, $input);
		} catch(\Throwable $error) {
			$this->reportDefinitionError($error);
			return $this;
		}
		$before = $this->value;
		if(!$before instanceof FlexValue) {
			$before = $this->hasField->type->sanitizeValue($this->hasPage, $this->hasField, $before);
		}
		$value = clone $before;
		$value->of(false);
		self::applyInputs($value, $form, $this->name . '__');
		$inputErrors = $form->getErrors();
		foreach($inputErrors as $error) {
			$this->error($error);
		}
		if($inputErrors) {
			$this->hasField->type->markAdminInputError($this->hasPage, $this->hasField, $inputErrors[0]);
			return $this;
		}
		try {
			$value->validateTree();
		} catch(\Throwable $error) {
			if($this->isDatabaseInputFailure($error)) {
				throw $error;
			}
			$this->reportAdminInputError($error->getMessage());
			return $this;
		}
		if($this->flexDraft !== '') {
			$value->expectedRevision($this->hasField->type->admin()->draft($this->flexDraft)['hash']);
		}
		if($before->rawArray() !== $value->rawArray()) {
			$this->trackChange('value', $before, $value);
			$this->attr('value', $value);
		}
		return $this;
	}

	/**
	 * Copy processed native leaf values into a typed Flex value.
	 *
	 * @param FlexValue $value
	 * @param InputfieldWrapper $form
	 * @param string $prefix Scope prefix for property names.
	 * @return void
	 */
	public static function applyInputs(FlexValue $value, InputfieldWrapper $form, string $prefix): void {
		foreach($value->definitions()->properties() as $name => $def) {
			$child = $form->getChildByName($prefix . $name);
			if($def['kind'] === 'object') {
				if($child instanceof InputfieldWrapper) {
					$object = $value->getUnformatted($name);
					self::applyInputs($object, $child, $child->flexObjectPrefix);
				}
				continue;
			}
			if(!$child || !$child->isChanged()) {
				continue;
			}
			if($child->getErrors()) {
				continue;
			}
			if($def['languages']) {
				$languageValue = clone $value->getUnformatted($name);
				$languageValue->setFromInputfield($child);
				$value->set($name, $languageValue);
			} else {
				$selection = $child->value;
				if($def['kind'] === 'page' && !$selection instanceof Page && !$selection instanceof PageArray) {
					$selection = $value->wire()->pages->getById(array_map('intval', (array) $selection));
				}
				$value->set($name, $selection);
			}
		}
	}

	/**
	 * Whether the input contains no logical user content.
	 *
	 * @return bool
	 */
	public function isEmpty() {
		$value = $this->value;
		if($value instanceof FlexInvalidValue) {
			return false;
		}
		return $value instanceof FlexValue ? $this->hasField->type->isEmptyValue($this->hasField, $value) : true;
	}
}
