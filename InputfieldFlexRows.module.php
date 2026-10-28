<?php namespace ProcessWire;

/** Atomic repeatable set; every leaf is a fresh native Inputfield. */
class InputfieldFlexRows extends Inputfield {
	private array $rowInputs = [];

	/**
	 * Return module metadata and installation requirements.
	 *
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo() {
		return [
			'title'    => __('Flex rows'),
			'version'  => '0.3.0',
			'summary'  => __('Repeatable native inputs inside Flex'),
			'requires' => 'FieldtypeFlex',
		];
	}

	private function admin(): FlexAdmin {
		return $this->flexRoot->ownerField()->type->admin();
	}

	private function inputsFor(FlexRow $row): InputfieldWrapper {
		$id = $row->rowId();
		if(!isset($this->rowInputs[$id])) {
			$this->rowInputs[$id] = $row->definitions()->getInputfields($row, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address()), $this->flexDraft, $this->flexRoot, $this->flexBaseRoot, $this->flexInputName ?: $this->name);
			if($this->parent instanceof InputfieldWrapper) {
				$this->rowInputs[$id]->setParent($this->parent);
			}
		}
		return $this->rowInputs[$id];
	}

	/**
	 * Load native input assets before rendering root or AJAX Repeater inputs.
	 *
	 * @param Inputfield|null $parent
	 * @param bool $renderValueMode Prepare read-only value rendering.
	 * @return bool
	 */
	public function renderReady(?Inputfield $parent = null, $renderValueMode = false) {
		$loaded = parent::renderReady($parent, $renderValueMode);
		$this->wire()->modules->get('JqueryUI');
		$rows = $this->value;
		$existingIds = [];
		if($rows instanceof FlexRows) {
			foreach($rows as $row) {
				$existingIds[$row->rowId()] = true;
			}
		}
		$sampleIds = [];
		foreach($this->definition['types'] as $type => $typeDefinition) {
			$seed = hash('sha256', $this->name . "\0" . $this->setName . "\0" . $type);
			$id = substr($seed, 0, 32);
			$collision = 0;
			while(isset($sampleIds[$id]) || isset($existingIds[$id])) {
				$id = substr(hash('sha256', $seed . "\0" . ++$collision), 0, 32);
			}
			$sampleIds[$id] = true;
			$sample = new FlexRow(
				$this->flexRoot->ownerPage(),
				$this->flexRoot->ownerField(),
				$typeDefinition['schema'],
				$this->setName,
				$id,
				(string) $type,
				[]
			);
			$sampleInputs = $sample->definitions()->getInputfields($sample, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $sample->address()), '', $this->flexRoot, $this->flexBaseRoot, $this->flexInputName ?: $this->name);
			foreach($sampleInputs->getAll(['withWrappers' => true]) as $child) {
				if($child->renderReady($child->parent, $renderValueMode)) {
					$loaded = true;
				}
			}
		}
		return $loaded;
	}

	/** Return the current row type's registry entry. */
	private function typeDefinition(FlexRow $row): array {
		return $this->definition['types'][$row->rowType()];
	}

	/** Resolve a bare property name or the core-style label format for one row type. */
	private function labelFormat(FlexRow $row): string {
		$format = trim($this->typeDefinition($row)['settings']['rowTitle'] ?? '');
		if($row->definitions()->property($format)) {
			$format = '{' . $format . '}';
		}
		return $format;
	}

	/** Return plain display values for the known, flat properties used by the label. */
	private function labelValues(FlexRow $row): array {
		preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $this->labelFormat($row), $matches);
		$values = [];
		foreach(array_unique($matches[1]) as $name) {
			$property = $row->definitions()->property($name);
			if(!$property) {
				continue;
			}
			$value = $row->getUnformatted($name);
			$options = $row->getOptions($name);
			if($options) {
				$labels = [];
				foreach(is_array($value) ? $value : [$value] as $key) {
					$labels[] = $options[$key] ?? (string) $key;
				}
				$value = implode(', ', $labels);
			} else if($value instanceof Page) {
				$value = $value->id ? $value->get('title|name') : '';
			} else if($value instanceof PageArray) {
				$value = implode(', ', array_map(fn($page) => $page->get('title|name'), $value->getArray()));
			} else if($value instanceof Pagefiles) {
				$value = implode(', ', array_map(fn($file) => $file->basename(), $value->getArray()));
			} else if(is_array($value)) {
				$value = implode(', ', array_filter($value, 'is_scalar'));
			}
			$values[$name] = $this->wire()->sanitizer->text((string) $value, ['maxLength' => 160]);
		}
		return $values;
	}

	/**
	 * Render one row’s scoped native inputs and accessible action buttons.
	 *
	 * @param FlexRow $row
	 * @param int $number One-based position within this set.
	 * @param bool $isNew Whether this is a newly registered editor row.
	 * @return string
	 */
	public function renderRow(FlexRow $row, int $number = 1, bool $isNew = false): string {
		$id = $row->rowId();
		$inputs = $this->inputsFor($row);
		$type = $row->rowType();
		$typeDefinition = $this->typeDefinition($row);
		$typeLabel = (string) ($typeDefinition['label'] ?? $type);
		$values = $this->labelValues($row);
		$format = $this->labelFormat($row);
		$fallback = $typeLabel . ' #n';
		$label = str_ireplace('#n', '#' . $number, $format);
		$label = trim(preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', fn($match) => $values[$match[1]] ?? '', $label));
		if($label === '') {
			$label = str_ireplace('#n', '#' . $number, $fallback);
		}
		foreach($inputs->getAll() as $input) {
			$name = (string) $input->_flex_property;
			if(array_key_exists($name, $values)) {
				$input->wrapAttr('data-flex-label-property', $name);
				$input->wrapAttr('data-flex-label-kind', $row->definitions()->property($name)['kind']);
				$options = $row->getOptions($name);
				if($options) {
					$input->wrapAttr('data-flex-label-options', json_encode((object) $options, JSON_THROW_ON_ERROR));
				}
			}
		}
		$mode = $this->definition['settings']['rowCollapse'] ?? 0;
		$collapsed = ($mode === 1 || ($mode === 0 && !$isNew)) && !$inputs->getErrors();
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$bodyId = 'flex_row_' . $this->name . '__' . $id;
		$buttons = '';
		foreach([
			'duplicate' => ['copy', $this->_('Duplicate')],
			'remove'    => ['trash-o', $this->_('Remove')],
		] as $action => [$icon, $actionLabel]) {
			$buttons .= '<button type="button" class="flex-row-action" data-flex-row-action="' . $action . '" title="' . $entities($actionLabel) . '" aria-label="' . $entities($actionLabel) . '"><i class="fa fa-fw fa-' . $icon . '" aria-hidden="true"></i></button>';
		}
		$sortLabel = $entities($this->_('Drag to reorder; use Up and Down arrow keys when focused'));
		return '<li class="Inputfield InputfieldFieldset InputfieldNoFocus flex-row' . ($collapsed ? ' InputfieldStateCollapsed' : '') . '" id="wrap_' . $entities($bodyId) . '" data-flex-row="' . $entities($id) . '" data-flex-type="' . $entities($type) . '" data-flex-label-format="' . $entities($format) . '" data-flex-label-fallback="' . $entities($fallback) . '" data-flex-label-values="' . $entities(json_encode((object) $values, JSON_THROW_ON_ERROR)) . '">' .
			'<div class="InputfieldHeader flex-row-header"><div class="flex-row-bar uk-background-primary uk-light">' .
			'<button type="button" class="flex-row-sort" title="' . $sortLabel . '" aria-label="' . $sortLabel . '"><i class="fa fa-fw fa-arrows" aria-hidden="true"></i></button>' .
			'<button type="button" class="flex-row-toggle" aria-controls="' . $entities($bodyId) . '" aria-expanded="' . ($collapsed ? 'false' : 'true') . '"><span class="flex-row-label"><span class="flex-row-type-badge">' . $entities($typeLabel) . '</span><span class="flex-row-title">' . $entities($label) . '</span></span><i class="fa fa-fw fa-angle-down flex-row-chevron" aria-hidden="true"></i></button>' .
			'<span class="flex-row-controls">' . $buttons . '</span></div></div>' .
			'<div class="InputfieldContent flex-row-content" id="' . $entities($bodyId) . '">' . $inputs->render() . '</div></li>';
	}

	/**
	 * Render the editable native inputs and their scoped transport controls.
	 *
	 * @return string
	 */
	public function ___render() {
		$rows = $this->value;
		if(!$rows instanceof FlexRows) {
			return '';
		}
		$ids = [];
		$html = '';
		$newRows = $this->flexDraft !== '' ? ($this->admin()->draft($this->flexDraft)['rows'][$this->setName] ?? []) : [];
		foreach($rows as $row) {
			$ids[] = $row->rowId();
			$html .= $this->renderRow($row, count($ids), isset($newRows[$row->rowId()]));
		}
		$entities = fn($v) => $this->wire()->sanitizer->entities((string) $v);
		$settings = $this->definition['settings'];
		$addLabel = $settings['addRowLabel'] ?? $this->_('Add item');
		$types = $this->definition['types'];
		$attributes = '';
		$typeControl = '';
		if(count($types) === 1) {
			$attributes .= ' data-flex-type="' . $entities((string) array_key_first($types)) . '"';
		} else {
			$typeId = 'Inputfield_' . $this->name . '__type';
			$options = '';
			foreach($types as $type => $typeDefinition) {
				$options .= '<option value="' . $entities((string) $type) . '">' . $entities((string) ($typeDefinition['label'] ?? $type)) . '</option>';
			}
			$typeLabel = $entities($this->_('New item type'));
			$typeControl = '<label class="flex-row-type-control" for="' . $entities($typeId) . '"><span>' . $typeLabel . '</span><select class="flex-row-type" id="' . $entities($typeId) . '" aria-label="' . $typeLabel . '">' . $options . '</select></label>';
		}
		return '<div class="flex-rows" data-flex-set="' . $entities($this->setName) . '" data-flex-draft="' . $entities($this->flexDraft) . '" data-flex-page-url="' . $entities($this->flexRoot->ownerPage()->editUrl()) . '" data-flex-field="' . $entities($this->flexRoot->ownerField()->name) . '" data-flex-min="' . (int) ($settings['minItems'] ?? 0) . '" data-flex-max="' . (int) ($settings['maxItems'] ?? 0) . '"' . $attributes . '>' .
			'<input type="hidden" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'present')) . '" value="1">' .
			'<input type="hidden" class="flex-rows-order" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'order')) . '" value="' . implode(',', $ids) . '">' .
			'<ul class="Inputfields flex-rows-items">' . $html . '</ul>' . $typeControl . '<button type="button" class="ui-button flex-row-add"><i class="fa fa-plus-circle" aria-hidden="true"></i> ' . $entities($addLabel) . '</button>' .
			'<p class="flex-rows-status uk-hidden-visually" role="status" aria-live="polite"></p><p class="flex-rows-error" role="alert"></p></div>';
	}

	/**
	 * Render native child values without editor controls.
	 *
	 * @return string
	 */
	public function ___renderValue() {
		$html = '';
		foreach($this->value as $row) {
			$html .= $this->inputsFor($row)->renderValue();
		}
		return $html;
	}

	/**
	 * Validate submitted native inputs while preserving draft and error recovery state.
	 *
	 * @param WireInputData $input
	 * @return $this
	 */
	public function ___processInput(WireInputData $input) {
		if($input->{FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'present')} !== '1' && $input->{FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'present')} !== 1) {
			return $this;
		}
		try {
			$before = $this->value;
			$rawOrder = $input->{FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'order')};
			if(!is_string($rawOrder)) {
				throw new WireException($this->_('Missing repeatable order'));
			}
			$ids = $rawOrder === '' ? [] : explode(',', $rawOrder);
			if(count(array_unique($ids)) !== count($ids)) {
				throw new WireException($this->_('Duplicate repeatable row'));
			}
			$rows = new FlexRows($this->flexRoot->ownerPage(), $this->flexRoot->ownerField(), $this->setName, $this->definition);
			foreach($ids as $id) {
				$row = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexBaseRoot ?: $this->flexRoot, false);
				if(!isset($this->rowInputs[$id])) {
					$view = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexRoot);
					$this->inputsFor($view);
				}
				$form = $this->wire()->modules->get('InputfieldForm');
				$form->protectCSRF = false;
				foreach($this->inputsFor($row)->children() as $child) {
					$form->add($child);
				}
				$form->processInput($input);
				InputfieldFlex::applyInputs($row, $form, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address()));
				foreach($form->getErrors() as $error) {
					$this->error($error);
				}
				$rows->add($row);
			}
			$this->attr('value', $rows);
			$rows->checkLimit(true);
			if($before->rawArray() !== $rows->rawArray()) {
				$this->trackChange('value', $before, $rows);
			}
		} catch(FlexDefinitionException $error) {
			throw $error;
		} catch(\Throwable $error) {
			// A native child can throw before it has a chance to add a normal
			// validation message. Classify it with the same narrow boundary as
			// root inputs so the containing editor can lock and block the save.
			$page = $this->flexRoot->ownerPage();
			$field = $this->flexRoot->ownerField();
			$definitionError = $field->type->nativeInputDefinitionError($page, $field, $error);
			if($definitionError) {
				throw $definitionError;
			}
			// Keep storage/database failures strict. Row ownership and draft
			// permission errors remain ordinary field errors as before; they are
			// never converted to definition errors by the classifier above.
			$path = str_replace('\\', '/', $error->getFile());
			if($error instanceof WireDatabaseException || $error instanceof WireDatabaseQueryException ||
				$error instanceof \PDOException || $error instanceof PageFinderSyntaxException ||
				preg_match('#/(?:FlexMedia|FlexStorage)\.php$#', $path)) {
				throw $error;
			}
			if(isset($rows) && count($rows)) {
				$this->attr('value', $rows);
			}
			$this->error($error->getMessage());
		}
		return $this;
	}

	/**
	 * Whether the input contains no logical user content.
	 *
	 * @return bool
	 */
	public function isEmpty() {
		foreach($this->value as $row) {
			if(!$this->flexRoot->ownerField()->type->isEmptyValue($this->flexRoot->ownerField(), $row)) {
				return false;
			}
		}
		return true;
	}
}
