<?php namespace ProcessWire;

/** Native rendering/validation; Flex owns transport, draft state and physical mutations. */
trait FlexMediaInput {
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
		if(!$this->isAjax && !$this->getErrors()) {
			$this->trackChange('value');
		}
		return $result;
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
		$changed = InputfieldFile::___processInputFile($input, $file, $n);
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
