<?php namespace ProcessWire;

require_once __DIR__ . '/src/FlexBridge.php';

/** Atomic repeatable set; every leaf is a fresh native Inputfield. */
class InputfieldFlexRows extends Inputfield implements BridgeComponent {
	use FlexBridgeComponent;
	private array $rowInputs = [];
	private array $removedRows = [];
	private ?array $renderRows = null;

	/** A deletion mark belongs to this form, never to canonical row metadata. @internal */
	public static function removalRequested(WireInputData $input, string $name, string $address): bool {
		$value = $input->{FlexPath::controlName($name, $address, 'remove')};
		if($value === null || $value === '0') return false;
		if($value !== '1') throw new WireException(__('Invalid Flex row removal'));
		return true;
	}

	/**
	 * Return module metadata and installation requirements.
	 *
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo() {
		return [
			'title'    => __('Flex rows'),
			'version'  => '0.7.0',
			'summary'  => __('Repeatable native inputs inside Flex'),
			'requires' => 'FieldtypeFlex',
		];
	}

	/** Header transport is independent of lazy body loading. @internal */
	public static function applyVisibility(FlexRow $row, WireInputData $input, string $name): void {
		$value = $input->{FlexPath::controlName($name, $row->address(), 'hidden')};
		// Preserve baseline state for older open forms without the new control.
		if($value === null) return;
		if($value !== '0' && $value !== '1') throw new WireException(__('Invalid Flex row visibility'));
		$row->setHidden($value === '1');
	}

	private function admin(): FlexAdmin {
		return $this->flexRoot->ownerField()->type->admin();
	}

	private function inputsFor(FlexRow $row): InputfieldWrapper {
		$id = $row->rowId();
		if(!isset($this->rowInputs[$id])) {
			$this->rowInputs[$id] = $row->definitions()->getInputfields($row, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address()), $this->flexDraft, $this->flexRoot, $this->flexBaseRoot, $this->flexInputName ?: $this->name, $this->flexDependencyAncestors ?: []);
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
		if(!is_a($this->wire()->adminTheme, __NAMESPACE__ . '\\AdminThemeBridge')) $this->wire()->modules->get('JqueryUI');
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
			$sampleInputs = $sample->definitions()->getInputfields($sample, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $sample->address()), '', $this->flexRoot, $this->flexBaseRoot, $this->flexInputName ?: $this->name, $this->flexDependencyAncestors ?: []);
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

	/** Supply plain values for custom @tokens. #pw-hooker */
	public function ___rowTitleValues(FlexRow $row, FlexValue $root, FlexValue $parent): array {
		return [];
	}

	/** Replace the title with text and optional CSS classes. Return null to use the template. #pw-hooker */
	public function ___rowTitleParts(FlexRow $row, array $parts, FlexValue $root, FlexValue $parent): ?array {
		return null;
	}

	/** Prepare one title for initial rendering or a detached AJAX preview. @internal */
	public function titleData(FlexRow $row, int $index): array {
		$root = $this->flexRoot;
		$path = [];
		[$parent] = FlexPath::resolve($root, FlexPath::rowSet($row), function($set, $id, $owner) use ($root, &$path) {
			$ancestor = $owner->getUnformatted(substr($set, strrpos('.' . $set, '.')))->getById($id);
			$ancestor ??= $this->admin()->row($this->flexDraft, $set, $id, $root);
			$path[] = $ancestor->rowTypeLabel();
			return $ancestor;
		});
		$nodes = FlexRowTitle::compile($this->typeDefinition($row)['settings']['rowTitle'] ?? '');
		$values = ['@type' => $row->rowTypeLabel(), '@index' => (string) $index, '@path' => implode(' › ', $path)];
		$sources = $custom = [];
		$references = array_unique(iterator_to_array(FlexRowTitle::references($nodes), false));
		$customRefs = array_filter($references, static fn($ref) => $ref[0] === '@' && !in_array($ref, FlexRowTitle::BUILT_INS, true));
		$usesValues = $customRefs && $this->hasHook('rowTitleValues()');
		if($usesValues) {
			$provided = $this->rowTitleValues($row, $root, $parent);
			foreach($customRefs as $reference) {
				$value = $provided[$reference] ?? '';
				if(is_scalar($value)) $custom[$reference] = (string) $value;
			}
		}
		foreach($references as $reference) {
			if($reference[0] === '@') continue;
			$property = FlexRowTitle::property($reference, $row, $root, $parent, $this->flexInputName ?: $this->name);
			if($property) [$values[$reference], $sources[$reference]] = $property;
		}
		$parts = FlexRowTitle::parts($nodes, $values + $custom);
		$override = $this->rowTitleParts($row, $parts, $root, $parent);
		if(is_array($override)) $override = FlexRowTitle::override($override, $index);
		else $override = null;
		// The index remains local even when a template contains server-provided values.
		unset($values['@index']);
		return ['nodes' => $nodes, 'values' => (object) $values, 'sources' => (object) $sources, 'custom' => (object) $custom,
			'override' => $override, 'refresh' => $usesValues || $override !== null,
			'parts' => $override === null ? $parts : ($override ?: [['text' => '#' . $index]])];
	}

	/**
	 * Render one row’s scoped native inputs and accessible action buttons.
	 *
	 * @param FlexRow $row
	 * @param int $number One-based position within this set.
	 * @param bool $isNew Whether this is a newly registered editor row.
	 * @return string
	 */
	public function renderRow(FlexRow $row, int $number = 1, bool $isNew = false, bool $forceLoad = false): string {
		if(isset($this->definition['settings']['_flexTable'])) return $this->renderTableRow($row);
		$id = $row->rowId();
		$removed = isset($this->removedRows[$id]);
		$inputs = $this->rowInputs[$id] ?? null;
		$mode = $this->definition['settings']['rowCollapse'] ?? 0;
		$collapsed = $removed || ($mode === 1 || ($mode === 0 && !$isNew)) && (!$inputs || !$inputs->getErrors());
		$lazy = ($this->definition['settings']['rowLazyLoad'] ?? false) && $this->flexDraft !== '' && FlexLazy::supports($row->definitions());
		$entry = null;
		if($lazy) {
			$previous = $this->admin()->draft($this->flexDraft)['lazy'][$row->address()]['loaded'] ?? false;
			$entry = FlexLazy::register($this->flexRoot, $row, $this->flexDraft, $forceLoad || $isNew || !$collapsed || $previous || $this->wire()->input->get('flex_eager') === '1');
		}
		$loaded = !$entry || $entry['loaded'];
		if($loaded) $inputs = $this->inputsFor($row);
		$type = $row->rowType();
		$typeLabel = $row->rowTypeLabel();
		$title = $this->titleData($row, $number);
		$label = FlexRowTitle::markup($title['parts']);
		$refreshTitle = $title['refresh'];
		unset($title['parts'], $title['refresh']);
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$bodyId = 'flex_row_' . $this->name . '__' . $id;
		$buttons = $this->rowActions($row, $removed);
		$sortLabel = $entities($this->_('Drag to reorder; use Up and Down arrow keys when focused'));
		if($loaded) {
			$body = $inputs->render();
			if($entry) $body = FlexLazy::marker($this->flexInputName ?: $this->name, $row->address(), 'body', $entry['grant']) . $body . FlexLazy::marker($this->flexInputName ?: $this->name, $row->address(), 'complete', $entry['grant']);
		} else {
			$url = $this->flexRoot->ownerPage()->editUrl() . '&flex_eager=1';
			$retryLabel = $entities($this->_('Retry'));
			$fallbackLabel = $entities($this->_('Load all items'));
			$body = '<p class="flex-row-load-status" role="status"></p><button type="button" class="ui-button flex-row-retry" hidden>' . $retryLabel . '</button><noscript><a href="' . $entities($url) . '">' . $fallbackLabel . '</a></noscript>';
		}
		return '<li class="Inputfield InputfieldFieldset InputfieldNoFocus flex-row' . ($collapsed ? ' InputfieldStateCollapsed' : '') . ($row->isHidden() ? ' flex-row-hidden' : '') . ($removed ? ' flex-row-remove-pending' : '') . '" id="wrap_' . $entities($bodyId) . '" data-flex-row="' . $entities($id) . '"' . ($entry ? ' data-flex-loaded="' . ($loaded ? '1' : '0') . '"' : '') . ' data-flex-type="' . $entities($type) . '" data-flex-type-label="' . $entities($typeLabel) . '" data-flex-title="' . $entities(json_encode($title, JSON_THROW_ON_ERROR)) . '"' . ($refreshTitle ? ' data-flex-title-refresh' : '') . '>' .
			'<input type="hidden" class="flex-row-remove-value" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $row->address(), 'remove')) . '" value="' . ($removed ? '1' : '0') . '">' .
			'<input type="hidden" class="flex-row-hidden-value" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $row->address(), 'hidden')) . '" value="' . ($row->isHidden() ? '1' : '0') . '">' .
			'<div class="InputfieldHeader flex-row-header' . ($removed ? ' ui-state-error' : '') . '"><div class="flex-row-bar uk-background-primary uk-light">' .
			'<button type="button" class="flex-row-sort" title="' . $sortLabel . '" aria-label="' . $sortLabel . '"><i class="fa fa-fw fa-arrows" aria-hidden="true"></i></button>' .
			'<button type="button" class="flex-row-toggle" aria-controls="' . $entities($bodyId) . '" aria-expanded="' . ($collapsed ? 'false' : 'true') . '"><span class="flex-row-label"><span class="flex-row-title">' . $label . '</span></span><i class="fa fa-fw fa-angle-down flex-row-chevron" aria-hidden="true"></i></button>' .
			'<span class="flex-row-controls">' . $buttons . '</span></div></div>' .
			'<div class="InputfieldContent flex-row-content" id="' . $entities($bodyId) . '">' . $body . '</div></li>';
	}

	/** Shared actions for cards and table rows. */
	private function rowActions(FlexRow $row, bool $removed): string {
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$hideLabel = $this->_('Hide item');
		$showLabel = $this->_('Show item');
		$removeLabel = $this->_('Remove');
		$undoLabel = $this->_('Undo removal');
		$buttons = '';
		foreach([
			'duplicate' => ['copy', $this->_('Duplicate')],
			'visibility' => [$row->isHidden() ? 'eye-slash' : 'eye', $row->isHidden() ? $showLabel : $hideLabel],
			'remove' => ['trash-o', $removed ? $undoLabel : $removeLabel],
		] as $action => [$icon, $actionLabel]) {
			$state = '';
			if($action === 'remove') $state = ' aria-pressed="' . ($removed ? 'true' : 'false') . '" data-flex-remove-label="' . $entities($removeLabel) . '" data-flex-undo-label="' . $entities($undoLabel) . '"';
			if($action === 'visibility') $state = ' aria-pressed="' . ($row->isHidden() ? 'true' : 'false') . '" data-flex-hide-label="' . $entities($hideLabel) . '" data-flex-show-label="' . $entities($showLabel) . '"';
			$buttons .= '<button type="button" class="flex-row-action" data-flex-row-action="' . $action . '" title="' . $entities($actionLabel) . '" aria-label="' . $entities($actionLabel) . '"' . $state . '><i class="fa fa-fw fa-' . $icon . '" aria-hidden="true"></i></button>';
		}
		return $buttons;
	}

	/** Render a native cell without changing global markup or its processing parent. */
	private function tableCell(Inputfield $input, bool $readOnly = false): string {
		$parent = $input->parent;
		$wrapper = $this->wire(new InputfieldWrapper());
		$input->addClass('flex-table-input', 'wrapClass');
		if(!$readOnly && ($input instanceof InputfieldText || $input instanceof InputfieldInteger)) {
			$input->addClass('flex-table-text', 'wrapClass');
		}
		$wrapper->add($input);
		try {
			return $readOnly ? $wrapper->renderValue() : $wrapper->render();
		} finally {
			$input->setParent($parent);
		}
	}

	/** Only presentation differs; row processing and draft identities stay shared. */
	private function renderTableRow(FlexRow $row, bool $readOnly = false): string {
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$id = $row->rowId();
		$removed = isset($this->removedRows[$id]);
		$inputs = $this->inputsFor($row);
		$prefix = FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address());
		$table = $this->definition['settings']['_flexTable'];
		$cells = '';
		foreach($table['columns'] as $name) {
			$tag = $name === 'row_label' ? 'th' : 'td';
			$input = $inputs->getChildByName($prefix . $name);
			$cells .= '<' . $tag . ' class="flex-table-cell"' . ($tag === 'th' ? ' scope="row"' : '') . '>' . $this->tableCell($input, $readOnly) . '</' . $tag . '>';
		}
		if($readOnly) return '<tr>' . $cells . '</tr>';
		$sortLabel = $entities($this->_('Drag to reorder; use Up and Down arrow keys when focused'));
		$transport = '';
		foreach(['remove' => $removed, 'hidden' => $row->isHidden()] as $control => $state) {
			$transport .= '<input type="hidden" class="flex-row-' . $control . '-value" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $row->address(), $control)) . '" value="' . ($state ? '1' : '0') . '">';
		}
		return '<tr class="flex-row' . ($row->isHidden() ? ' flex-row-hidden' : '') . ($removed ? ' flex-row-remove-pending' : '') . '" data-flex-row="' . $id . '" data-flex-type="' . $entities($row->rowType()) . '">' . $cells .
			'<td class="flex-row-header flex-table-actions"><div class="flex-row-controls"><button type="button" class="flex-row-sort" title="' . $sortLabel . '" aria-label="' . $sortLabel . '"><i class="fa fa-fw fa-arrows" aria-hidden="true"></i></button>' . $this->rowActions($row, $removed) . '</div><span class="flex-row-transport">' . $transport . '</span></td></tr>';
	}

	/** Headers use the same prepared inputs as normal Object form processing. */
	private function renderTable(string $rows, bool $readOnly = false): string {
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$table = $this->definition['settings']['_flexTable'];
		$headers = $this->flexTableHeaders;
		$head = '';
		$cols = '';
		foreach($table['columns'] as $name) {
			$width = $table['widths'][$name] ?? '';
			$cols .= '<col' . ($width !== '' ? ' style="width:' . $entities($width) . '"' : '') . '>';
			if($headers instanceof InputfieldWrapper) {
				$input = $headers->getChildByName($headers->flexObjectPrefix . $name);
				$text = $this->tableCell($input, $readOnly);
			} else {
				$input = $this->definition['types']['row']['schema']->getPropertyInputfield($name);
				$language = $this->wire()->user->language;
				$text = $entities(($language && !$language->isDefault() ? $input->get('label' . $language->id) : '') ?: $input->label);
			}
			if(!$readOnly) {
				$label = $entities(sprintf($this->_('Resize column %s'), $input->label));
				$text .= '<button type="button" class="flex-table-resize" title="' . $label . '" aria-label="' . $label . '" aria-keyshortcuts="ArrowLeft ArrowRight"></button>';
			}
			$head .= '<th scope="col"' . ($readOnly ? '' : ' class="flex-table-resizable"') . '>' . $text . '</th>';
		}
		$actions = $readOnly ? '' : '<th class="flex-table-actions" scope="col"><span class="flex-table-sr">' . $entities($this->_('Row actions')) . '</span></th>';
		return '<div class="flex-table-scroll" role="region" aria-label="' . $entities($this->label ?: $this->_('Table')) . '" tabindex="0"><table class="flex-table-grid"><colgroup>' . $cols . ($readOnly ? '' : '<col>') . '</colgroup><thead><tr>' . $head . $actions . '</tr></thead><tbody class="flex-rows-items">' . $rows . '</tbody></table></div>';
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
		foreach($this->renderRows ?? $rows as $row) {
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
			// Native column-width rules must not stretch this compact select to 100%.
			$typeControl = '<label class="flex-row-type-control" for="' . $entities($typeId) . '"><span>' . $typeLabel . '</span><select class="flex-row-type InputfieldSetWidth" id="' . $entities($typeId) . '" aria-label="' . $typeLabel . '">' . $options . '</select></label>';
		}
		$table = isset($settings['_flexTable']);
		$items = $table ? $this->renderTable($html) : '<ul class="Inputfields flex-rows-items">' . $html . '</ul>';
		return '<div class="flex-rows' . ($table ? ' flex-table' : '') . '" data-flex-set="' . $entities($this->setName) . '" data-flex-draft="' . $entities($this->flexDraft) . '" data-flex-page-url="' . $entities($this->flexRoot->ownerPage()->editUrl()) . '" data-flex-field="' . $entities($this->flexRoot->ownerField()->name) . '" data-flex-min="' . (int) ($settings['minItems'] ?? 0) . '" data-flex-max="' . (int) ($settings['maxItems'] ?? 0) . '"' . $attributes . '>' .
			'<input type="hidden" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'present')) . '" value="1">' .
			'<input type="hidden" class="flex-rows-order" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $this->setName, 'order')) . '" value="' . implode(',', $ids) . '">' .
			$items . $typeControl . '<button type="button" class="ui-button flex-row-add"><i class="fa fa-plus-circle" aria-hidden="true"></i> ' . $entities($addLabel) . '</button>' .
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
			$html .= isset($this->definition['settings']['_flexTable']) ? $this->renderTableRow($row, true) : $this->inputsFor($row)->renderValue();
		}
		return isset($this->definition['settings']['_flexTable']) ? $this->renderTable($html, true) : $html;
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
		$preview = $this->admin()->previewingRowTitles();
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
			$this->renderRows = [];
			$this->removedRows = [];
			foreach($ids as $id) {
				$row = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexBaseRoot ?: $this->flexRoot, $preview);
				if(!isset($this->rowInputs[$id])) {
					$view = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexRoot);
					$this->inputsFor($view);
				}
				self::applyVisibility($row, $input, $this->flexInputName ?: $this->name);
				$this->renderRows[$id] = $row;
				$removed = self::removalRequested($input, $this->flexInputName ?: $this->name, $row->address());
				if($removed) {
					if($preview) continue;
					$this->removedRows[$id] = true;
					// Processing copies retain submitted inputs for undo after a failed save.
					// Removed rows contribute no validation errors or values to persistence.
					if(!FlexLazy::unloaded($this->flexRoot, $this->flexDraft, $row->address())) {
						$form = FlexDependencies::processInput($this->inputsFor($row), $this->flexRoot->ownerPage(), $input);
						if(!$form->getErrors(true)) InputfieldFlex::applyInputs($row, $form, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address()));
					}
					continue;
				}
				$form = FlexDependencies::processInput($this->inputsFor($row), $this->flexRoot->ownerPage(), $input);
				if(!FlexLazy::unloaded($this->flexRoot, $this->flexDraft, $row->address())) {
					InputfieldFlex::applyInputs($row, $form, FlexPath::inputPrefix($this->flexInputName ?: $this->name, $row->address()));
				}
				foreach($preview ? [] : $form->getErrors() as $error) {
					$this->error($error);
				}
				$rows->add($row);
			}
			$this->attr('value', $rows);
			if(!$preview) $rows->checkLimit(true);
			if($before->rawArray() !== $rows->rawArray()) {
				$this->trackChange('value', $before, $rows);
			}
		} catch(FlexDefinitionException $error) {
			throw $error;
		} catch(\Throwable $error) {
			if($preview) throw $error;
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
