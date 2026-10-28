<?php namespace ProcessWire;

/** Read-only raw value used only to keep ProcessPageEdit available during a bad definition. */
final class FlexInvalidValue {
	private Page $page;
	private Field $field;
	private array $raw;
	private FlexDefinitionException $error;

	/**
	 * Keep existing raw values and the error that blocks editing or saving.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param array $raw Unformatted persisted values.
	 * @param FlexDefinitionException $error
	 */
	public function __construct(Page $page, Field $field, array $raw, FlexDefinitionException $error) {
		$this->page = $page;
		$this->field = $field;
		$this->raw = $raw;
		$this->error = $error;
	}

	/**
	 * Whether this value belongs to the supplied page and field context.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return bool
	 * @internal
	 */
	public function belongsTo(Page $page, Field $field): bool {
		return $this->page === $page && (int) $this->field->id === (int) $field->id && $this->field->name === $field->name;
	}

	/**
	 * Export unformatted values in the canonical persistence shape.
	 *
	 * @return array
	 */
	public function rawArray(): array {
		return $this->raw;
	}

	/**
	 * Get the definition error that prevents this raw value from being edited.
	 *
	 * @return FlexDefinitionException
	 */
	public function definitionError(): FlexDefinitionException {
		return $this->error;
	}
}

/** Flat, typed page value. Formatting is a view and never changes persisted values. */
class FlexValue extends WireData implements \JsonSerializable {
	private Page $flexPage;
	private Field $flexField;
	private FlexFieldDefs $definitions;
	private bool $formatted = false;
	private array $unknown = [];
	private array $unknownMediaCopies = [];
	private string $flexAddress;
	private string $expectedRevision = '';

	/**
	 * Get or replace the revision expected when this value is saved.
	 *
	 * @param string|null $hash Expected revision, or null to read it.
	 * @return string
	 * @internal
	 */
	public function expectedRevision(?string $hash = null): string {
		if($hash !== null) {
			$this->expectedRevision = $hash;
		}
		return $this->expectedRevision;
	}

	/**
	 * Hydrate raw leaf values and bind changes to their owning page and field.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param FlexFieldDefs $defs Schema used to hydrate the value.
	 * @param array $raw Unformatted persisted values.
	 * @param string $address Root or repeatable property address.
	 */
	public function __construct(Page $page, Field $field, FlexFieldDefs $defs, array $raw = [], string $address = '') {
		parent::__construct();
		$page->wire($this);
		$this->flexPage = $page;
		$this->flexField = $field;
		$this->definitions = $defs;
		$this->flexAddress = $address;
		$defs->assertSourceForValues($raw);
		$raw = $defs->migrate($raw);
		if($address === '') {
			$field->type->media()->prefetch($raw);
		}
		$this->unknown = array_diff_key($raw, $defs->properties());
		foreach($defs->properties() as $name => $def) {
			$default = $def['settings']['value'] ?? ($def['multiple'] ? [] : '');
			parent::set($name, $this->hydrate($name, array_key_exists($name, $raw) ? $raw[$name] : $default));
		}
		$this->resetTrackChanges();
	}

	/**
	 * Get the schema used to hydrate this value.
	 *
	 * @return FlexFieldDefs
	 */
	public function definitions(): FlexFieldDefs {
		return $this->definitions;
	}

	/**
	 * Get the page owning this typed value.
	 *
	 * @return Page
	 * @internal
	 */
	public function ownerPage(): Page {
		return $this->flexPage;
	}

	/**
	 * Get the Flex field owning this typed value.
	 *
	 * @return Field
	 * @internal
	 */
	public function ownerField(): Field {
		return $this->flexField;
	}

	/**
	 * Get the property address within the owning Flex field.
	 *
	 * @return string
	 * @internal
	 */
	public function address(): string {
		return $this->flexAddress;
	}

	/**
	 * Retained media also participates in owner reassignment, even without a schema leaf.
	 *
	 * @return void
	 * @internal
	 */
	public function prepareUnknownMedia(): void {
		$walk = function(array &$raw, string $prefix) use (&$walk): void {
			foreach($raw as $name => &$item) {
				if(!is_array($item)) {
					continue;
				}
				$address = $prefix === '' ? (string) $name : "$prefix.$name";
				if(($item['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
					foreach($item['items'] as &$row) {
						$walk($row['values'], "$address.{$row['id']}");
					}
					unset($row);
				} else if(in_array($item['_flex'] ?? '', [FlexStorage::FILES_FORMAT, FlexStorage::IMAGES_FORMAT], true)) {
					$extensions = [];
					foreach($item['items'] as $meta) {
						$extensions[] = pathinfo($meta['basename'], PATHINFO_EXTENSION);
					}
					$definition = ['settings' => ['extensions' => implode(' ', array_unique($extensions))]];
					$class = $item['_flex'] === FlexStorage::IMAGES_FORMAT ? FlexImages::class : FlexFiles::class;
					$files = $this->unknownMediaCopies[$address] ?? new $class($this->flexPage, $this->flexField, $address, $definition, $item);
					$files->prepareCommit();
					$item = $files->rawArray();
				}
			}
			unset($item);
		};
		$walk($this->unknown, $this->flexAddress);
		$this->unknownMediaCopies = [];
	}

	/**
	 * Validate all collection limits and total tree budgets before persistence side effects.
	 *
	 * @param bool $minimum Also require collection minima; false for incomplete drafts.
	 * @return void
	 * @internal
	 */
	public function validateTree(bool $minimum = true): void {
		FlexPath::validate($this->rawArray(), $this->flexField);
		foreach($this->definitions->properties() as $name => $definition) {
			$item = $this->getUnformatted($name);
			if($item instanceof FlexRows) {
				$item->checkLimit($minimum);
				foreach($item as $row) {
					$row->validateTree($minimum);
				}
			}
		}
	}

	/**
	 * Copy a subtree to a new instance, retaining unknown values in preservation mode.
	 * Reading or formatting never calls this operation; media copies remain staged.
	 *
	 * @param FlexValue $source Unformatted source subtree.
	 * @return void
	 * @internal
	 */
	public function copyValuesFrom(FlexValue $source): void {
		foreach($source->definitions()->properties() as $name => $definition) {
			$this->set($name, $source->getUnformatted($name));
		}
		if($this->flexField->flexPrune === null || (bool) $this->flexField->flexPrune) {
			return;
		}
		$walk = function(array $raw, string $from, string $to) use (&$walk, $source): array {
			foreach($raw as $name => &$item) {
				if(!is_array($item)) {
					continue;
				}
				$sourceAddress = $from === '' ? (string) $name : "$from.$name";
				$targetAddress = $to === '' ? (string) $name : "$to.$name";
				if(($item['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
					foreach($item['items'] as &$row) {
						$oldId = $row['id'];
						$row['id'] = bin2hex(random_bytes(16));
						$row['values'] = $walk($row['values'], "$sourceAddress.$oldId", "$targetAddress.{$row['id']}");
					}
					unset($row);
				} else if(in_array($item['_flex'] ?? '', [FlexStorage::FILES_FORMAT, FlexStorage::IMAGES_FORMAT], true)) {
					$class = $item['_flex'] === FlexStorage::IMAGES_FORMAT ? FlexImages::class : FlexFiles::class;
					$extensions = array_map(static fn($meta) => pathinfo($meta['basename'], PATHINFO_EXTENSION), $item['items']);
					$definition = ['settings' => ['extensions' => implode(' ', array_unique($extensions))]];
					$files = $source->unknownMediaCopies[$sourceAddress] ?? new $class($source->ownerPage(), $source->ownerField(), $sourceAddress, $definition, $item);
					$copy = new $class($this->flexPage, $this->flexField, $targetAddress, $definition, $files);
					$this->unknownMediaCopies[$targetAddress] = $copy;
					$item = $copy->rawArray();
				}
			}
			unset($item);
			return $raw;
		};
		$this->unknown = $walk($source->unknown, $source->address(), $this->address());
	}

	/**
	 * Whether this value belongs to the supplied page and field context.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return bool
	 */
	public function belongsTo(Page $page, Field $field): bool {
		return $this->flexPage === $page && $this->flexField->name === $field->name;
	}

	/**
	 * Get or change output formatting and return its previous state.
	 *
	 * @param bool|null $formatted New formatting state, or null to read without changing it.
	 * @return bool
	 */
	public function of(?bool $formatted = null): bool {
		$previous = $this->formatted;
		if($formatted !== null) {
			$this->formatted = $formatted;
			foreach($this->data as $value) {
				if($value instanceof FlexRows) {
					$value->of($formatted);
				} else if($value instanceof Pagefiles) {
					$value->formatted($formatted);
				}
			}
		}
		return $previous;
	}

	/**
	 * Read a native typed property without applying output formatting.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function getUnformatted(string $name) {
		return parent::get($name);
	}

	/**
	 * Whether a known property contains logical content, independent of output formatting.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function hasValue(string $name): bool {
		if(!array_key_exists($name, $this->definitions->properties())) {
			return false;
		}
		return $this->containsContent(parent::get($name));
	}

	/**
	 * Return available option keys and translated labels, without optgroup headings.
	 *
	 * @param string $name Property name.
	 * @return array
	 */
	public function getOptions(string $name): array {
		$definition = $this->definitions->property($name);
		if(!$definition) {
			return [];
		}
		$input = $definition['input'];
		if(!method_exists($input, 'getOptions')) {
			return [];
		}
		$options = $input->getOptions();
		if(!is_array($options) || !$options) {
			return [];
		}
		$language = $this->wire()->user->language;
		$suffix = $language && !$language->isDefault() ? (string) $language->id : '';
		// The core getter initializes a missing per-language map. Read a clone so
		// discovering labels never mutates the reusable Inputfield metadata.
		$translatedInput = clone $input;
		$translated = $language && method_exists($translatedInput, 'optionLanguageLabel')
			? $translatedInput->optionLanguageLabel($language)
			: [];
		if(!is_array($translated)) {
			$translated = [];
		}
		// Keep the old setting-based translation shape available for custom
		// Inputfields while preferring InputfieldSelect's native label API.
		$legacy = $suffix ? $input->get('options' . $suffix) : null;
		if(is_array($legacy)) {
			$translated += $legacy;
		}
		$labels = [];
		$flatten = function(array $items, array $translations = []) use (&$flatten, &$labels, $input): void {
			foreach($items as $key => $label) {
				$translatedLabel = array_key_exists($key, $translations) ? $translations[$key] : null;
				if(is_array($label)) {
					// Native InputfieldSelect stores optionLanguageLabel entries
					// by option key, including keys nested inside optgroups.
					$nestedTranslations = is_array($translatedLabel) ? $translatedLabel : $translations;
					$flatten($label, $nestedTranslations);
					continue;
				}
				if(method_exists($input, 'isOption') && !$input->isOption($key)) {
					continue;
				}
				if(is_array($translatedLabel)) {
					$translatedLabel = $translatedLabel['label'] ?? null;
				}
				// InputfieldSelect ignores blank language labels and displays the
				// default label. Preserve that native fallback for this API too.
				$labels[$key] = (string) ($translatedLabel !== null && $translatedLabel !== '' ? $translatedLabel : $label);
			}
		};
		$flatten($options, $translated);
		if($this->shouldEntityEncode($definition)) {
			foreach($labels as $key => $label) {
				$labels[$key] = $this->wire()->sanitizer->entities($label);
			}
		}
		return $labels;
	}

	/**
	 * Return one translated option label using the current output-formatting state.
	 * Omit the key to read this value's current scalar selection. Arrays and
	 * unknown options return an empty string; explicit null resolves the empty key.
	 *
	 * @param string $name Property name.
	 * @param string|int|null $option Selected option key; defaults to this property's current value.
	 * @return string
	 */
	public function optionLabel(string $name, $option = null): string {
		// Omission reads the selection; explicit null preserves the empty-key lookup.
		if(func_num_args() < 2) {
			// A schema path can expose options without identifying a concrete row.
			if(!array_key_exists($name, $this->definitions->properties())) {
				return '';
			}
			$option = $this->getUnformatted($name);
		}
		if($option === null) {
			$option = '';
		} else if(!is_string($option) && !is_int($option)) {
			return '';
		}
		$labels = $this->getOptions($name);
		if(!array_key_exists($option, $labels)) {
			return '';
		}
		return $labels[$option];
	}

	private function containsContent($value): bool {
		if($value === null || $value === '' || $value === false) {
			return false;
		}
		if($value instanceof Page) {
			return (int) $value->id > 0;
		}
		if($value instanceof PageArray) {
			foreach($value as $page) {
				if($page instanceof Page && (int) $page->id > 0) {
					return true;
				}
			}
			return false;
		}
		if($value instanceof LanguagesPageFieldValue) {
			foreach($this->wire()->languages as $language) {
				if($this->containsContent($value->getLanguageValue($language))) {
					return true;
				}
			}
			return false;
		}
		if($value instanceof FlexRows) {
			foreach($value as $row) {
				foreach($row->definitions()->properties() as $property => $_) {
					if($row->hasValue($property)) {
						return true;
					}
				}
			}
			return false;
		}
		if($value instanceof Pagefiles) {
			return count($value) > 0;
		}
		if($value instanceof WireArray) {
			return count($value) > 0;
		}
		if(is_array($value)) {
			foreach($value as $item) {
				if($this->containsContent($item)) {
					return true;
				}
			}
			return false;
		}
		return is_scalar($value) || $value instanceof \Stringable;
	}

	private function shouldEntityEncode(array $definition): bool {
		if(!$this->formatted) {
			return false;
		}
		$setting = $this->flexField->get('flexEntityEncode');
		if($setting !== null && !(bool) $setting) {
			return false;
		}
		$input = $definition['input'];
		if($input instanceof InputfieldTinyMCE) {
			return false;
		}
		return !($input instanceof InputfieldTextarea && $input->isContentTypeHTML());
	}

	/**
	 * Read a typed property using the current output-formatting state.
	 *
	 * @param string|int $key
	 * @return mixed
	 */
	public function get($key) {
		$value = parent::get($key);
		$def = $this->definitions->property((string) $key);
		if($def && $def['kind'] === 'page') {
			if($this->formatted && $value instanceof Page && $value->hasStatus(Page::statusUnpublished)) {
				return (int) $def['input']->derefAsPage === 1 ? false : $this->wire()->pages->newNullPage();
			}
			if($this->formatted && $value instanceof PageArray) {
				$published = $this->wire()->pages->newPageArray();
				foreach($value as $item) {
					if(!$item->hasStatus(Page::statusUnpublished)) {
						$published->add($item);
					}
				}
				$published->resetTrackChanges();
				$value = $published;
			}
			if($value instanceof Page) {
				$value->of($this->formatted);
			} else if($value instanceof PageArray) {
				foreach($value as $item) {
					$item->of($this->formatted);
				}
			}
			return $value;
		}
		if(!$this->formatted || !$def) {
			return $value;
		}
		if(in_array($def['kind'], ['file', 'image'], true) && $value instanceof Pagefiles) {
			$format = $def['settings']['outputFormat'];
			$limit = $def['settings']['maxFiles'];
			if($format === 2 || ($format === 0 && $limit === 1)) {
				return $value->first() ?: null;
			}
			return $value->outputCollection($limit);
		}
		if($def['kind'] === 'date' && $value !== '') {
			$format = $def['input']->dateOutputFormat ?: $def['input']->dateInputFormat;
			return $this->wire()->datetime->date($format ?: 'Y-m-d', strtotime($value));
		}
		if($this->shouldEntityEncode($def)) {
			$encode = function($v) use (&$encode) {
				if(is_string($v)) {
					return $this->wire()->sanitizer->entities($v);
				}
				if(is_array($v)) {
					return array_map($encode, $v);
				}
				return $v;
			};
			if($value instanceof LanguagesPageFieldValue) {
				$copy = clone $value;
				foreach($this->wire()->languages as $language) {
					$copy->setLanguageValue($language, $encode($value->getLanguageValue($language)));
				}
				return $copy;
			}
			if(is_array($value)) {
				return array_map($encode, $value);
			}
			return $encode($value);
		}
		return $value;
	}

	/**
	 * Hydrate an unformatted property and mark its owning field changed.
	 *
	 * @param string|int $key
	 * @param mixed $value
	 * @return $this
	 */
	public function set($key, $value) {
		if(!isset($this->definitions) || !$this->definitions->property((string) $key)) {
			throw new WireException(sprintf($this->_('Unknown Flex property: %s'), $key));
		}
		if($this->formatted || $this->flexPage->of()) {
			throw new WireException($this->_('Disable page output formatting before changing Flex values'));
		}
		$value = $this->hydrate((string) $key, $value);
		$before = $this->encodeProperty((string) $key, parent::get($key));
		parent::set($key, $value);
		if($before !== $this->encodeProperty((string) $key, $value)) {
			$this->flexPage->trackChange($this->flexField->name);
		}
		return $this;
	}

	/**
	 * Check this value and its nested native objects for changes.
	 *
	 * @param string $what
	 * @return bool
	 */
	public function isChanged($what = '') {
		if(parent::isChanged($what)) {
			return true;
		}
		foreach(parent::getArray() as $name => $value) {
			if($what && $what !== $name) {
				continue;
			}
			if(
				($value instanceof LanguagesPageFieldValue || $value instanceof PageArray || $value instanceof FlexRows || $value instanceof Pagefiles)
				&& $value->isChanged()
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reset change tracking on this value and its nested native objects.
	 *
	 * @param bool|int $trackChanges
	 * @return $this
	 */
	public function resetTrackChanges($trackChanges = true) {
		foreach($this->data as $value) {
			if($value instanceof LanguagesPageFieldValue || $value instanceof PageArray || $value instanceof FlexRows || $value instanceof Pagefiles) {
				$value->resetTrackChanges($trackChanges);
			}
		}
		return parent::resetTrackChanges($trackChanges);
	}

	/**
	 * Clone nested objects so subsequent edits remain independent.
	 *
	 * @return void
	 */
	public function __clone() {
		foreach($this->data as $name => $value) {
			if($value instanceof LanguagesPageFieldValue || $value instanceof PageArray || $value instanceof FlexRows || $value instanceof Pagefiles) {
				$this->data[$name] = clone $value;
			}
		}
	}

	/**
	 * Get a translated property label using the current output-formatting state.
	 *
	 * @param string $property Property name.
	 * @return string
	 */
	public function label(string $property): string {
		$def = $this->definitions->property($property);
		if(!$def) {
			return '';
		}
		$input = $def['input'];
		$language = $this->wire()->user->language;
		$suffix = $language && !$language->isDefault() ? (string) $language->id : '';
		$label = (string) ($input->get('label' . $suffix) ?: $input->label);
		$encode = $this->flexField->get('flexEntityEncode');
		return $this->formatted && ($encode === null || (bool) $encode)
			? $this->wire()->sanitizer->entities($label) : $label;
	}

	/**
	 * Iterate known properties using the current output-formatting state.
	 *
	 * @return \ArrayObject
	 */
	public function getIterator(): \ArrayObject {
		return new \ArrayObject($this->getArray());
	}

	/**
	 * Export known properties using the current output-formatting state.
	 *
	 * @return array
	 */
	public function getArray() {
		$values = [];
		foreach($this->definitions->properties() as $name => $def) {
			$values[$name] = $this->get($name);
		}
		return $values;
	}

	/**
	 * Export canonical unformatted values for JSON serialization.
	 *
	 * @return array
	 */
	public function jsonSerialize(): array {
		return $this->rawArray();
	}

	/**
	 * Encode this value as canonical unformatted JSON.
	 *
	 * @return string
	 */
	public function __toString() {
		return json_encode($this->rawArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * Export unformatted values in the canonical persistence shape.
	 *
	 * @return array
	 */
	public function rawArray(): array {
		$prune = $this->flexField->get('flexPrune');
		$raw = $prune !== null && !(bool) $prune ? $this->unknown : [];
		foreach($this->definitions->properties() as $name => $def) {
			$raw[$name] = $this->encodeProperty($name, parent::get($name));
		}
		return $raw;
	}

	/**
	 * Convert one native typed property to its unformatted persistence value.
	 *
	 * @param string $name Property name.
	 * @param mixed $value
	 * @return mixed
	 * @internal
	 */
	public function encodeProperty(string $name, $value) {
		if($value instanceof FlexRows) {
			return $value->rawArray();
		}
		if($value instanceof FlexFiles || $value instanceof FlexImages) {
			return $value->rawArray();
		}
		if($value instanceof LanguagesPageFieldValue) {
			$raw = [];
			foreach($this->wire()->languages as $language) {
				$raw[(string) $language->id] = $value->getLanguageValue($language);
			}
			return $raw;
		}
		if($value instanceof Page) {
			return (int) $value->id;
		}
		if($value instanceof PageArray) {
			return array_map('intval', $value->explode('id'));
		}
		return $value;
	}

	private function hydrate(string $name, $value) {
		$def = $this->definitions->property($name);
		$input = $def['input'];
		if(in_array($def['kind'], ['file', 'image'], true)) {
			$class = $def['kind'] === 'image' ? FlexImages::class : FlexFiles::class;
			return new $class($this->flexPage, $this->flexField, $this->flexAddress === '' ? $name : "$this->flexAddress.$name", $def, $value);
		}
		if($def['kind'] === 'rows') {
			if($value instanceof FlexRows && $value->belongsTo($this->flexPage, $this->flexField, $this->flexAddress === '' ? $name : "$this->flexAddress.$name")) {
				return clone $value;
			}
			return new FlexRows($this->flexPage, $this->flexField, $this->flexAddress === '' ? $name : "$this->flexAddress.$name", $def, $value);
		}
		if($def['languages']) {
			if($value instanceof LanguagesPageFieldValue) {
				return clone $value;
			}
			$synthetic = clone $this->flexField;
			$synthetic->langBlankInherit = $input->langBlankInherit;
			return new LanguagesPageFieldValue($this->flexPage, $synthetic, $value);
		}
		if($def['kind'] === 'page') {
			$pages = $this->wire()->pages;
			if($value instanceof PageArray) {
				$ids = $value->explode('id');
			} else if($value instanceof Page) {
				$ids = [$value->id];
			} else if(is_array($value)) {
				$ids = $value;
			} else {
				$ids = $value === '' || $value === false || $value === null ? [] : preg_split('/[|, ]+/', (string) $value);
			}
			$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
			if(!$def['multiple']) {
				$page = $ids ? $pages->get($ids[0]) : $pages->newNullPage();
				return !$page->id && (int) $input->derefAsPage === 1 ? false : $page;
			}
			$result = $pages->newPageArray();
			foreach($ids as $id) {
				$item = $pages->get($id);
				if($item->id) {
					$result->add($item);
				}
			}
			$result->resetTrackChanges();
			return $result;
		}
		if($def['multiple']) {
			if($value === '' || $value === null) {
				return [];
			}
			return array_values(array_map('strval', is_array($value) ? $value : [$value]));
		}
		if($def['kind'] === 'tags' && is_array($value)) {
			return implode(' ', array_map('strval', $value));
		}
		if(($input instanceof InputfieldText || $input instanceof InputfieldTextarea)
			&& (is_array($value) || $value instanceof LanguagesPageFieldValue)) {
			// Resolve the installation's default, never the current user's language.
			$scope = $this->definitions->wire();
			$defaultId = $scope->languages ? (int) $scope->languages->getDefault()->id
				: (int) $scope->modules->getConfig('FieldtypeFlex', 'defaultLanguageId');
			$isLanguageMap = is_array($value) && !array_filter(array_keys($value),
				static fn($key) => !ctype_digit((string) $key) || (int) $key < 1);
			if($defaultId > 0 && ($isLanguageMap || $value instanceof LanguagesPageFieldValue)) {
				$value = $value instanceof LanguagesPageFieldValue
					? $value->getLanguageValue($defaultId) : ($value[$defaultId] ?? '');
			}
		}
		if(is_array($value) || (is_object($value) && !$value instanceof \Stringable)) {
			throw new WireException(sprintf($this->_("Flex '%s' requires a scalar value"), $name));
		}
		if($def['kind'] === 'date') {
			if($value === '' || $value === null || $value === 0) {
				return '';
			}
			// Persisted ISO values must not be reinterpreted using the editor format.
			$canonical = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?$/D', $value);
			$timestamp = is_int($value) ? $value : ($canonical
				? strtotime($value)
				: $this->wire()->datetime->stringToTimestamp((string) $value, $input->dateInputFormat));
			if(!$timestamp) {
				$timestamp = strtotime((string) $value);
			}
			if($timestamp === false || !$timestamp) {
				throw new WireException(sprintf($this->_("Invalid date for Flex '%s'"), $name));
			}
			$time = $input->timeInputFormat || in_array($input->htmlType, ['datetime', 'time'], true)
							|| preg_match('/[HGhis]/', (string) $input->dateInputFormat) || preg_match('/\d{2}:\d{2}/', (string) $value);
			return date($time ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);
		}
		if($def['kind'] === 'checkbox') {
			return $value === '' || $value === null || $value === false || $value === 0 || $value === '0' ? $input->uncheckedValue : $input->checkedValue;
		}
		if($value === '' || $value === null) {
			return '';
		}
		if($def['kind'] === 'integer' || $def['kind'] === 'float') {
			if(!is_numeric($value)) {
				throw new WireException(sprintf($this->_("Flex '%s' requires a number"), $name));
			}
			return $def['kind'] === 'integer' ? (int) $value : (float) $value;
		}
		return (string) $value;
	}
}
