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
			'version'  => '0.5.0',
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
		preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*(?:\|[a-zA-Z_][a-zA-Z0-9_]*)*)\}/', $this->labelFormat($row), $matches);
		$matches[1] = explode('|', implode('|', $matches[1]));
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

	/** Render a text-only format, with optional badges and no dangling edge separators. */
	private function titleParts(string $format, array $values, int $number, string $type, string $path, bool $cleanup = true): array {
		$parts = preg_split('/(\[[^\[\]]*\]|_\{[^{}]+\}_|\{[^{}]+\}|#n)/i', $format, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$result = [];
		foreach($parts as $part) {
			if(str_starts_with($part, '[') && str_ends_with($part, ']')) {
				$details = $this->titleParts(substr($part, 1, -1), $values, $number, $type, $path, false);
				if(!$details) $details[] = ['text' => '', 'badge' => false, 'literal' => false];
				foreach($details as $detail) {
					$detail['muted'] = true;
					$result[] = $detail;
				}
				continue;
			}
			$badge = str_starts_with($part, '_{') && str_ends_with($part, '}_');
			$token = $badge ? substr($part, 1, -1) : $part;
			$value = $part;
			$literal = false;
			if(strcasecmp($token, '#n') === 0) {
				$value = '#' . $number;
			} else if(str_starts_with($token, '{') && str_ends_with($token, '}')) {
				$value = '';
				foreach(explode('|', substr($token, 1, -1)) as $name) {
					$candidate = $name === '@type' ? $type : ($name === '@path' ? $path : ($values[$name] ?? ''));
					if(trim($candidate) !== '') {
						$value = $candidate;
						break;
					}
				}
			} else {
				$literal = true;
			}
			$result[] = ['text' => $value, 'badge' => $badge, 'literal' => $literal];
		}
		if(!$cleanup) return $result;
		// Separator-only literals need visible content on both sides. Field values
		// themselves are never cleaned as punctuation or interpreted as new tokens.
		foreach($result as $i => &$part) {
			if(!$part['literal'] || !preg_match('/^[\s›|\/:;,–—-]+$/u', $part['text'])) continue;
			$before = $after = false;
			foreach($result as $j => $other) {
				if(trim($other['text']) !== '' && (!$other['literal'] || !preg_match('/^[\s›|\/:;,–—-]+$/u', $other['text']))) {
					if($j < $i) $before = true;
					if($j > $i) $after = true;
				}
			}
			if(!$before || !$after) $part['text'] = '';
		}
		unset($part);
		$separator = null;
		$omitted = false;
		foreach($result as $i => $part) {
			if($part['text'] === '') {
				if(!$part['literal']) $omitted = true;
				continue;
			}
			if($part['literal'] && preg_match('/^[\s›|\/:;,–—-]+$/u', $part['text'])) {
				if($separator !== null && $omitted) $result[$separator]['text'] = '';
				$separator = $i;
			} else {
				$separator = null;
			}
			$omitted = false;
		}
		return $result;
	}

	/** Use only ancestor type labels, independent of their titles and values. */
	private function contextPath(): string {
		$parts = explode('.', $this->setName);
		$owner = $this->flexRoot;
		$labels = [];
		while(count($parts) > 1) {
			$set = array_shift($parts);
			if($owner->definitions()->property($set)['kind'] === 'object') {
				$owner = $owner->getUnformatted($set);
				continue;
			}
			$id = array_shift($parts);
			$rows = $owner->getUnformatted($set);
			if(!$rows instanceof FlexRows) break;
			$ancestor = $rows->getById($id);
			if(!$ancestor) break;
			$labels[] = $ancestor->rowTypeLabel();
			$owner = $ancestor;
		}
		return implode(' › ', $labels);
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
		$typeDefinition = $this->typeDefinition($row);
		$typeLabel = (string) ($typeDefinition['label'] ?? $type);
		$values = $this->labelValues($row);
		$format = $this->labelFormat($row);
		$parts = $this->titleParts($format, $values, $number, $typeLabel, str_contains($format, '@path') ? $this->contextPath() : '');
		if(trim(implode('', array_column($parts, 'text'))) === '') {
			$parts = [['text' => ($format === '' ? $typeLabel . ' ' : '') . '#' . $number, 'badge' => false]];
		}
		$entities = fn($value) => $this->wire()->sanitizer->entities((string) $value);
		$label = '';
		foreach($parts as $part) {
			if($part['text'] === '') continue;
			$classes = [];
			if($part['badge']) $classes[] = 'flex-row-type-badge';
			if(!empty($part['muted'])) $classes[] = 'flex-row-deemphasized';
			$text = $entities($part['text']);
			$label .= $classes ? '<span class="' . implode(' ', $classes) . '">' . $text . '</span>' : $text;
		}
		// Preserve the established default header, including its type badge.
		if($format === '') $label = '<span class="flex-row-type-badge">' . $entities($typeLabel) . '</span> ' . $label;
		foreach($loaded ? $inputs->getAll() : [] as $input) {
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
		$bodyId = 'flex_row_' . $this->name . '__' . $id;
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
		return '<li class="Inputfield InputfieldFieldset InputfieldNoFocus flex-row' . ($collapsed ? ' InputfieldStateCollapsed' : '') . ($row->isHidden() ? ' flex-row-hidden' : '') . ($removed ? ' flex-row-remove-pending' : '') . '" id="wrap_' . $entities($bodyId) . '" data-flex-row="' . $entities($id) . '"' . ($entry ? ' data-flex-loaded="' . ($loaded ? '1' : '0') . '"' : '') . ' data-flex-type="' . $entities($type) . '" data-flex-type-label="' . $entities($typeLabel) . '" data-flex-label-format="' . $entities($format) . '" data-flex-label-values="' . $entities(json_encode((object) $values, JSON_THROW_ON_ERROR)) . '">' .
			'<input type="hidden" class="flex-row-remove-value" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $row->address(), 'remove')) . '" value="' . ($removed ? '1' : '0') . '">' .
			'<input type="hidden" class="flex-row-hidden-value" name="' . $entities(FlexPath::controlName($this->flexInputName ?: $this->name, $row->address(), 'hidden')) . '" value="' . ($row->isHidden() ? '1' : '0') . '">' .
			'<div class="InputfieldHeader flex-row-header' . ($removed ? ' ui-state-error' : '') . '"><div class="flex-row-bar uk-background-primary uk-light">' .
			'<button type="button" class="flex-row-sort" title="' . $sortLabel . '" aria-label="' . $sortLabel . '"><i class="fa fa-fw fa-arrows" aria-hidden="true"></i></button>' .
			'<button type="button" class="flex-row-toggle" aria-controls="' . $entities($bodyId) . '" aria-expanded="' . ($collapsed ? 'false' : 'true') . '"><span class="flex-row-label"><span class="flex-row-title">' . $label . '</span></span><i class="fa fa-fw fa-angle-down flex-row-chevron" aria-hidden="true"></i></button>' .
			'<span class="flex-row-controls">' . $buttons . '</span></div></div>' .
			'<div class="InputfieldContent flex-row-content" id="' . $entities($bodyId) . '">' . $body . '</div></li>';
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
			$this->renderRows = [];
			$this->removedRows = [];
			foreach($ids as $id) {
				$row = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexBaseRoot ?: $this->flexRoot, false);
				if(!isset($this->rowInputs[$id])) {
					$view = $this->admin()->row($this->flexDraft, $this->setName, $id, $this->flexRoot);
					$this->inputsFor($view);
				}
				self::applyVisibility($row, $input, $this->flexInputName ?: $this->name);
				$this->renderRows[$id] = $row;
				$removed = self::removalRequested($input, $this->flexInputName ?: $this->name, $row->address());
				if($removed) {
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
