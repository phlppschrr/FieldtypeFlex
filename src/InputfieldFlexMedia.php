<?php namespace ProcessWire;

/** Native rendering/validation; Flex owns transport, draft state and physical mutations. */
trait FlexMediaInput {
	private array $flexItemInputs = [];

	/**
	 * Load native input assets before rendering root or AJAX Repeater inputs.
	 *
	 * @param Inputfield|null $parent
	 * @param bool $renderValueMode Prepare read-only value rendering.
	 * @return bool
	 */
	public function renderReady(?Inputfield $parent = null, $renderValueMode = false) {
		$modules = $this->wire()->modules;
		$loaded = $modules->loadModuleFileAssets('InputfieldFile') > 0;
		if($this instanceof InputfieldImage && $modules->loadModuleFileAssets('InputfieldImage')) {
			$loaded = true;
		}
		return parent::renderReady($parent, $renderValueMode) || $loaded;
	}

	protected function getUploadAttrs() {
		$attrs = parent::getUploadAttrs();
		$files = $this->value;
		if(!$files instanceof Pagefiles) {
			return $attrs;
		}
		$attrs['data-fieldname'] = $files->field->name;
		$url = $files->page->editUrl();
		$attrs['data-posturl'] = $url . (str_contains($url, '?') ? '&' : '?') . 'InputfieldFileAjax=1';
		$attrs['data-flex-draft'] = $this->flexDraft;
		$attrs['data-flex-path'] = $files->address();
		$attrs['data-flex-upload'] = '1';
		return $attrs;
	}

	/**
	 * Render the editable native inputs and their scoped transport controls.
	 *
	 * @return string
	 */
	public function ___render() {
		$html = parent::___render();
		if($this instanceof InputfieldImage) {
			$html = str_replace("class='InputfieldImageUpload'", "class='InputfieldImageUpload InputfieldImageInitUpload'", $html);
		}
		return '<input type="hidden" name="' . $this->wire()->sanitizer->entities(FlexPath::controlName($this->flexInputName ?: $this->name, $this->value->address(), 'present')) . '" value="1">' . $html;
	}

	/**
	 * Validate submitted native inputs while preserving draft and error recovery state.
	 *
	 * @param WireInputData $input
	 * @return $this
	 */
	public function ___processInput(WireInputData $input) {
		if(!$this->isAjax && isset($_FILES[$this->name])) {
			foreach((array) ($_FILES[$this->name]['error'] ?? []) as $error) {
				if((int) $error !== UPLOAD_ERR_NO_FILE) {
					$this->error($this->_('Upload Flex files with the editor upload control before saving the page.'));
					return $this;
				}
			}
		}
		if(!$this->isAjax && !$input->{FlexPath::controlName($this->flexInputName ?: $this->name, $this->value->address(), 'present')}) {
			return $this;
		}
		$result = parent::___processInput($input);
		if(!$this->isAjax && $this->value->metadataSchema()->properties()) {
			foreach($this->value as $file) {
				if($input->{'sort_' . $this->name . '_' . $file->hash} === null) $this->error($this->_('Incomplete Flex metadata submission; reload and check the PHP input limits'));
			}
		}
		if(!$this->isAjax && !$this->getErrors()) {
			$this->trackChange('value');
		}
		return $result;
	}

	/** Native per-item rendering with stable asset scope, also for AJAX uploads. */
	public function getItemInputfields(?Pagefile $item = null) {
		$files = $this->value;
		if(!$files instanceof FlexFiles && !$files instanceof FlexImages) return false;
		$schema = $files->metadataSchema();
		if(!$schema->structure()) return false;
		$id = $item ? $item->assetId() : '';
		if(isset($this->flexItemInputs[$id])) return $this->flexItemInputs[$id];
		$value = $item ? $item->metadataInputValue() : null;
		$prefix = FlexPath::inputPrefix($this->flexInputName ?: $this->name, $files->address() . '.' . $id);
		$inputs = $schema->getInputfields($value, $prefix, (string) $this->flexDraft, $this->flexRoot, null, $this->flexInputName ?: $this->name, $this->flexDependencyAncestors ?: [], (string) $this->flexParentAddress);
		foreach($inputs->getAll() as $child) {
			$child->wrapAttr('data-flex-metadata', $id);
			$child->wrapAttr('data-flex-metadata-property', (string) $child->_flex_property);
			if($child instanceof InputfieldHidden) {
				$child->attr('data-flex-metadata', $id);
				$child->attr('data-flex-metadata-property', (string) $child->_flex_property);
			}
			$child->requiredAttr = 0;
			$child->removeAttr('required');
			if(!$item) $child->renderReady();
		}
		if($item) {
			$complete = $this->wire()->modules->get('InputfieldHidden');
			$complete->name = FlexPath::controlName($this->flexInputName ?: $this->name, $value->address(), 'metadata');
			$complete->value = 1;
			$inputs->add($complete);
		}
		return $this->flexItemInputs[$id] = $inputs;
	}

	/** Replace each standard native control independently. */
	protected function renderItemDescriptionField(Pagefile $file, $id, $n) {
		if($this->value->metadataSchema()->property('description')) return $this->useTags ? $this->renderItemTagsField($file, $id, $n) : '';
		return parent::renderItemDescriptionField($file, $id, $n);
	}

	protected function renderItemTagsField(Pagefile $file, $id, $n) {
		return $this->value->metadataSchema()->property('tags') ? '' : parent::renderItemTagsField($file, $id, $n);
	}

	/** Core must not also process the replaced native POST controls. */
	private function nativeMetadataPost(WireInputData $input, Pagefile $file): WireInputData {
		$data = $input->getArray();
		$id = $this->name . '_' . $file->hash;
		foreach(['description', 'tags'] as $name) {
			if(!$this->value->metadataSchema()->property($name)) continue;
			foreach(array_keys($data) as $key) {
				if(preg_match('/^' . $name . '(?:[0-9]+)?_' . preg_quote($id, '/') . '$/D', $key)) unset($data[$key]);
			}
		}
		return $this->wire(new WireInputData($data));
	}

	protected function ___processInputFile(WireInputData $input, Pagefile $file, $n) {
		return parent::___processInputFile($this->nativeMetadataPost($input, $file), $file, $n);
	}

	/** Use native InputfieldForm validation/dependencies without metadata Templates. */
	protected function ___processItemInputfields(Pagefile $file, InputfieldWrapper $inputs, $id, WireInputData $input) {
		// Uploads may be incomplete until the successful containing-field save.
		if($this->isAjax || (int) $input->{'delete_' . $id}) return false;
		if(!$input->{FlexPath::controlName($this->flexInputName ?: $this->name, $file->pagefiles->address() . '.' . $file->assetId(), 'metadata')}) {
			$this->error($this->_('Incomplete Flex metadata submission; reload and check the PHP input limits'));
			return false;
		}
		$before = FlexMedia::describe($file, $file->assetId());
		$form = FlexDependencies::processInput($inputs, $file->page, $input);
		$value = $file->metadataInputValue();
		$prefix = FlexPath::inputPrefix($this->flexInputName ?: $this->name, $value->address());
		InputfieldFlex::applyInputs($value, $form, $prefix);
		foreach($form->getErrors() as $error) $this->error($file->basename() . ': ' . $error);
		foreach($value->definitions()->properties() as $name => $definition) {
			$child = $form->getChildByName($prefix . $name);
			if($child && $child->isChanged() && !$child->getErrors()) $file->setFieldValue($name, $value->getUnformatted($name));
		}
		return $before !== FlexMedia::describe($file, $file->assetId());
	}

	protected function ___processInputAddFile($filename) {
		$metadata = null;
		if((int) $this->maxFiles === 1 && count($this->value)) {
			$metadata = FlexMedia::describe($this->value->first(), $this->value->first()->assetId());
			$this->value->removeAll();
		}
		$file = parent::___processInputAddFile($filename);
		if($file && $metadata) {
			$file->description(true, $metadata['description']);
			$file->tags = $metadata['tags'];
			$file->hydrateCustomValues($metadata['values'] ?? [], array_key_exists('values', $metadata));
		}
		return $file;
	}
}

class InputfieldFlexFile extends InputfieldFile {
	use FlexMediaInput;
}

class InputfieldFlexImage extends InputfieldImage {
	use FlexMediaInput;

	protected function getEditUrl(Pagefile $file, $pageID) {
		return parent::getEditUrl($file, $pageID) . '&flex_draft=' . rawurlencode($this->flexDraft);
	}

	/**
	 * Expose supported staged image transformations as translated native actions.
	 *
	 * @param Pagefile $file
	 * @return array<string, string>
	 */
	public function ___getFileActions(Pagefile $file) {
		return [
			'flex-r90'  => $this->_('Rotate 90°'),
			'flex-r180' => $this->_('Rotate 180°'),
			'flex-fh'   => $this->_('Flip horizontally'),
			'flex-fv'   => $this->_('Flip vertically'),
		];
	}

	protected function ___processUnknownFileAction(Pageimage $file, $action, $label) {
		$map = [
			'flex-r90'  => 'rotate90',
			'flex-r180' => 'rotate180',
			'flex-fh'   => 'flipH',
			'flex-fv'   => 'flipV',
		];
		if(!isset($map[$action])) {
			return false;
		}
		$this->value->field->type->media()->transform($file, $map[$action]);
		return true;
	}

	protected function ___processInputFile(WireInputData $input, Pagefile $file, $n) {
		$changed = InputfieldFile::___processInputFile($this->nativeMetadataPost($input, $file), $file, $n);
		$focus = $input->{'focus_' . $this->name . '_' . $file->hash};
		if($focus !== null && $file instanceof Pageimage && $file->focus()['str'] !== ($focus ?: '50 50 0')) {
			$file->focus($focus ?: '50 50 0');
			$changed = true;
		}
		return $changed;
	}

	protected function getVariationUrl($pagefile, $id) {
		return parent::getVariationUrl($pagefile, $id) . '&flex_draft=' . rawurlencode($this->flexDraft);
	}
}
