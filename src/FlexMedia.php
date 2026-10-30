<?php namespace ProcessWire;

/** Native objects with owned, staged physical mutations. */
trait FlexOwnedFile {
	private string $flexAssetId = '';
	private array $flexBaseline = [];
	private ?FlexValue $flexValues = null;
	private bool $flexHasValues = false;

	/** Read canonical descriptions for cloning, revision checks and persistence. @internal */
	public function rawDescription($language = null) {
		$source = $this->metadataOriginal();
		return $source !== $this ? $source->rawDescription($language) : parent::description($language);
	}

	/** Format description reads without changing native multilingual metadata. */
	public function description($language = null, $value = null) {
		if(is_array($language) || ($value !== null && !($language === true && $value === true))) {
			$this->assertMetadataWritable();
		}
		$source = $this->metadataOriginal();
		$description = $source !== $this ? $source->description($language, $value) : parent::description($language, $value);
		if($source !== $this) return $description;
		if($value !== null || is_array($language) || $language === true) return $description;
		return $this->pagefiles->formatDescription($description);
	}

	/** Apply the same output rules to native language-specific property reads. */
	public function get($key) {
		if($this->flexAssetId !== '' && $this->metadataSchema()->property((string) $key) && !in_array($key, ['description', 'tags'], true)) return $this->getFieldValue($key);
		$source = $this->metadataOriginal();
		if($source !== $this && ($key === 'tags' || preg_match('/^description\d*$/D', (string) $key))) return $source->get($key);
		$value = parent::get($key);
		return is_string($key) && preg_match('/^description\d+$/D', $key)
			? $this->pagefiles->formatDescription((string) $value) : $value;
	}

	/**
	 * Get or replace the last committed metadata used for conflict detection.
	 *
	 * @param array|null $meta
	 * @return array
	 */
	public function baseline(?array $meta = null): array {
		if($meta !== null) {
			$this->flexBaseline = $meta;
		}
		return $this->flexBaseline;
	}

	/**
	 * Get the stable media ownership identity.
	 *
	 * @return string
	 */
	public function assetId(): string {
		return $this->flexAssetId;
	}

	/**
	 * Bind a validated ownership identity to this native file.
	 *
	 * @param string $id Stable row or asset identity.
	 * @return void
	 */
	public function bindAsset(string $id): void {
		if(!preg_match('/^[a-f0-9]{32}$/D', $id)) {
			throw new WireException($this->_('Invalid Flex asset identity'));
		}
		$this->flexAssetId = $id;
	}

	/**
	 * Stage a new basename while retaining the logical asset identity.
	 *
	 * @param string $basename
	 * @return string
	 */
	public function rename($basename) {
		return $this->pagefiles->rename($this, $basename);
	}

	/**
	 * Stage replacement bytes while preserving the file’s logical metadata.
	 *
	 * @param string $filename
	 * @param bool $move Native compatibility argument; replacement always stages a copy.
	 * @return string
	 */
	public function replaceFile($filename, $move = true) {
		return $this->pagefiles->replaceOwned($this, $filename);
	}

	/**
	 * Remove the file from its collection and defer physical cleanup until commit.
	 *
	 * @return bool
	 */
	public function unlink() {
		$this->pagefiles->remove($this);
		return true;
	}

	/**
	 * Read or change native file data while tracking the containing Flex value.
	 *
	 * @param string|array|false|null $key Native file data key or bulk operation.
	 * @param mixed $value
	 * @return mixed
	 */
	public function filedata($key = '', $value = null) {
		if(is_array($key) || $key === false || $key === null || $value !== null) {
			$this->assertMetadataWritable();
		}
		return parent::filedata($key, $value);
	}

	/**
	 * Update native file metadata and track the containing collection when writable.
	 *
	 * @param string|int $key
	 * @param mixed $value
	 * @return $this
	 */
	public function set($key, $value) {
		if($this->flexAssetId !== '' && $this->metadataSchema()->property((string) $key) && !in_array($key, ['description', 'tags'], true)) {
			$this->setFieldValue($key, $value);
			return $this;
		}
		if($this->flexAssetId !== '' && (str_starts_with((string) $key, 'description') || in_array($key, ['tags', 'filedata'], true))) {
			$this->assertMetadataWritable();
		}
		return parent::set($key, $value);
	}

	/** Original metadata stays authoritative for existing variation instances. */
	private function metadataOriginal(): Pagefile {
		return $this instanceof Pageimage && $this->original ? $this->original : $this;
	}

	private function assertMetadataWritable(): void {
		$this->pagefiles->assertWritable();
		if($this->metadataOriginal() !== $this) throw new WireException($this->_('Edit Flex metadata on the original file'));
	}

	private function metadataSchema(): FlexFieldDefs {
		return $this->pagefiles->metadataSchema();
	}

	/** Hydrate once through Flex's existing scalar/date/language pipeline. @internal */
	public function hydrateCustomValues(array $values, bool $present = true): void {
		$this->flexHasValues = $present;
		$this->flexValues = new FlexValue($this->page, $this->field, $this->metadataSchema(), $values, $this->pagefiles->address() . '.' . $this->assetId());
	}

	/** Canonical ordinary metadata, including retained unknown properties. @internal */
	public function rawCustomValues(): ?array {
		$source = $this->metadataOriginal();
		if($source !== $this) return $source->rawCustomValues();
		$raw = $this->flexValues ? $this->flexValues->rawArray() : [];
		unset($raw['description'], $raw['tags']);
		return $raw || $this->flexHasValues ? $raw : null;
	}

	/** Read declared fields; unknown names match native getFieldValue() (null). */
	public function getFieldValue($name, $formatted = null) {
		$source = $this->metadataOriginal();
		if($source !== $this) {
			$value = $source->getFieldValue($name, $formatted ?? $this->pagefiles->metadataFormatted());
			return $value instanceof PageArray || $value instanceof LanguagesPageFieldValue ? clone $value : $value;
		}
		if(!$this->metadataSchema()->property((string) $name)) return null;
		$formatted = $formatted ?? $this->pagefiles->metadataFormatted();
		if($name === 'description') return $formatted ? $this->pagefiles->formatDescription($this->rawDescription(), true) : $this->rawDescription();
		if($name === 'tags') return (string) $this->tags;
		if(!$this->flexValues) $this->hydrateCustomValues([], false);
		// Unformatted language and PageArray objects stay live only on writable originals.
		if(!$formatted && !$this->formatted() && !$this->page->of()) return $this->flexValues->getUnformatted($name);
		$view = clone $this->flexValues;
		$view->of((bool) $formatted);
		return $view->get($name);
	}

	/** Set declared fields; unknown names match native setFieldValue() (false). */
	public function setFieldValue($name, $value, $changed = null) {
		if(!$this->metadataSchema()->property((string) $name)) return false;
		$this->assertMetadataWritable();
		if($name === 'description') {
			if($value instanceof LanguagesPageFieldValue || is_array($value)) $this->description($value instanceof LanguagesPageFieldValue ? $this->descriptionMap($value) : $value);
			else $this->description(null, (string) $value);
		} else if($name === 'tags') $this->tags = is_array($value) ? implode(' ', $value) : $value;
		else {
			if(!$this->flexValues) $this->hydrateCustomValues([], false);
			$this->flexValues->set($name, $value);
			$this->trackChange($name);
			$this->pagefiles->trackChange('item');
		}
		return true;
	}

	private function descriptionMap(LanguagesPageFieldValue $value): array {
		$map = [];
		foreach($this->wire()->languages as $language) $map[$language->isDefault() ? 0 : $language->id] = $value->getLanguageValue($language);
		return $map;
	}

	/** Detached native editor values combine ordinary metadata with native slots. @internal */
	public function metadataInputValue(): FlexValue {
		$raw = $this->rawCustomValues() ?? [];
		$schema = $this->metadataSchema();
		if($schema->property('description')) {
			$raw['description'] = $this->rawDescription();
			if($schema->property('description')['languages']) {
				$raw['description'] = [];
				foreach($this->wire()->languages as $language) $raw['description'][$language->id] = $this->rawDescription($language);
			}
		}
		if($schema->property('tags')) {
			$raw['tags'] = $schema->property('tags')['multiple'] ? $this->tags(true) : $this->tags();
		}
		return new FlexValue($this->page, $this->field, $schema, $raw, $this->pagefiles->address() . '.' . $this->assetId());
	}

	public function tags($value = null) {
		$source = $this->metadataOriginal();
		if($value !== null && !is_bool($value)) $this->assertMetadataWritable();
		return $source !== $this ? $source->tags($value) : parent::tags($value);
	}

	public function isChanged($what = '') {
		return parent::isChanged($what) || ($this->flexValues && $this->flexValues->isChanged($what));
	}

	public function resetTrackChanges($trackChanges = true) {
		if($this->flexValues) $this->flexValues->resetTrackChanges($trackChanges);
		return parent::resetTrackChanges($trackChanges);
	}

	public function __clone() {
		parent::__clone();
		if($this->flexValues) $this->flexValues = clone $this->flexValues;
	}

}

class FlexPagefile extends Pagefile {
	use FlexOwnedFile;
}

class FlexPageimage extends Pageimage {
	use FlexOwnedFile;
}

/** Shared behavior for native File and Image collections, without early unlink hooks. */
trait FlexMediaCollection {
	private string $flexAddress;
	private array $flexDefinition;
	private bool $flexHydrating = true;
	private array $pendingCopies = [];
	private string $flexDraft = '';
	private bool $rawDescriptions = false;
	private ?FlexFieldDefs $flexMetadataSchema = null;

	/** Run native description formatters against a transient property Field. @internal */
	public function formatDescription(string $description, bool $force = false): string {
		if(!$force && (!$this->formatted() || $this->rawDescriptions)) return $description;
		$field = $this->wire(new Field());
		$field->type = $this->wire()->modules->get($this instanceof Pageimages ? 'FieldtypeImage' : 'FieldtypeFile');
		$leaf = substr(strrchr('.' . $this->flexAddress, '.'), 1);
		$field->name = $this->field->name . '_' . $leaf;
		foreach($this->flexDefinition['settings'] ?? [] as $key => $value) {
			if(!in_array($key, ['id', 'name', 'type', 'value', 'inheritField'], true)) $field->set($key, $value);
		}
		$names = $this->metadataSchema()->property('description')['settings']['textformatters'] ?? $field->textformatters ?? [];
		foreach($names as $name) {
			$formatter = $this->wire()->modules->get($name);
			if($formatter instanceof Textformatter) $formatter->formatValue($this->page, $field, $description);
		}
		return $description;
	}

	/**
	 * Bind the collection to its owning page without native temp-file handling.
	 *
	 * @param Page $page Owning page.
	 * @return void
	 */
	public function setPage(Page $page) {
		$this->page = $page;
		if($page->id) {
			$page->filesManager();
		}
	}

	/**
	 * Hydrate owned native files and defer copies for an unsaved target page.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param string $address Root or repeatable property address.
	 * @param array $definition
	 * @param array|Pagefiles|string|null $raw Unformatted persisted files.
	 */
	public function __construct(Page $page, Field $field, string $address, array $definition, $raw = []) {
		parent::__construct($page);
		$nativeField = clone $field;
		if(isset($definition['settings']['noLang'])) {
			$nativeField->noLang = (bool) $definition['settings']['noLang'];
		}
		$this->setField($nativeField);
		$this->flexAddress = $address;
		$this->flexDefinition = $definition;
		$this->flexMetadataSchema = $definition['customFields'] ?? null;
		if($raw instanceof Pagefiles) {
			$source = $raw;
			$raw = $raw instanceof self ? $raw->rawArray() : [
				'_flex' => $this->marker(),
				'items' => [],
			];
			if(!$source instanceof self) {
				foreach($source as $file) {
					$raw['items'][] = FlexMedia::describe($file, bin2hex(random_bytes(16)));
				}
			}
			$copy = $source->page !== $page || $source->field->id !== $field->id || !method_exists($source, 'address') || $source->address() !== $address;
			if($copy) {
				foreach($raw['items'] as $index => &$meta) {
					$meta['id'] = bin2hex(random_bytes(16));
					$sourceFile = array_values($source->getArray())[$index];
					$this->pendingCopies[$meta['id']] = $sourceFile->filename();
				}
			}
			unset($meta);
		}
		if($raw === null || $raw === '' || $raw === []) {
			$raw = [
				'_flex' => $this->marker(),
				'items' => [],
			];
		}
		if(!FlexMedia::validEnvelope($raw, $this->marker())) {
			throw new WireException(sprintf($this->_('Invalid Flex media value: %s'), $address));
		}
		$ids = [];
		foreach($raw['items'] as $meta) {
			FlexMedia::validateMeta($meta);
			if(isset($ids[$meta['id']])) {
				throw new WireException($this->_('Duplicate media identity'));
			}
			$ids[$meta['id']] = true;
			$owner = $field->type->media()->asset($meta['id']);
			if($owner && ((int) $owner['pages_id'] !== (int) $page->id || (int) $owner['fields_id'] !== (int) $field->id)) {
				$sourcePage = $this->wire()->pages->get((int) $owner['pages_id']);
				if(!$sourcePage->id) {
					throw new WireException($this->_('Media copy source no longer exists'));
				}
				$meta['id'] = bin2hex(random_bytes(16));
				$this->pendingCopies[$meta['id']] = $sourcePage->filesManager()->path() . $owner['basename'];
			}
			$item = $this->hydrateFile($meta);
			if(isset($this->data[$item->basename()])) {
				throw new WireException($this->_('Duplicate media basename'));
			}
			WireArray::add($item);
		}
		$this->checkLimit();
		$this->flexHydrating = false;
		$this->resetTrackChanges();
	}

	/**
	 * Get the property address within the owning Flex field.
	 *
	 * @return string
	 */
	public function address(): string {
		return $this->flexAddress;
	}

	/**
	 * Get the media property’s definition and native settings.
	 *
	 * @return array
	 */
	public function definition(): array {
		return $this->flexDefinition;
	}

	/** Shared evaluated schema; an absent definition still retains unknown values. @internal */
	public function metadataSchema(): FlexFieldDefs {
		return $this->flexMetadataSchema ??= new FlexFieldDefs($this->field, $this->page, []);
	}

	/** Formatting state shared by direct metadata reads and native bridges. @internal */
	public function metadataFormatted(): bool {
		return $this->formatted() && !$this->rawDescriptions;
	}

	/**
	 * Get the versioned File or Image envelope marker.
	 *
	 * @return string
	 */
	public function marker(): string {
		return $this instanceof Pageimages ? FlexStorage::IMAGES_FORMAT : FlexStorage::FILES_FORMAT;
	}

	/**
	 * Get or set the authenticated editor draft token for this collection.
	 *
	 * @param string|null $token
	 * @return string
	 */
	public function draft(?string $token = null): string {
		if($token !== null) {
			$this->flexDraft = $token;
		}
		return $this->flexDraft;
	}

	/**
	 * Whether persisted files are currently being loaded into the collection.
	 *
	 * @return bool
	 */
	public function isHydrating(): bool {
		return $this->flexHydrating;
	}

	/**
	 * Reject media mutation while formatted or attached to a page version.
	 *
	 * @return void
	 * @throws WireException
	 */
	public function assertWritable(): void {
		if(!$this->flexHydrating && ($this->formatted() || $this->page->of())) {
			throw new WireException($this->_('Disable output formatting before changing Flex files'));
		}
		if(!$this->flexHydrating && $this->page->get('_version')) {
			throw new WireException($this->_('Flex media cannot be changed on a page version'));
		}
	}

	private function hydrateFile(array $meta): Pagefile {
		$class = $this instanceof Pageimages ? FlexPageimage::class : FlexPagefile::class;
		$file = $this->wire(new $class($this, $meta['basename']));
		$description = $meta['description'] ?? '';
		$definition = $this->metadataSchema()->property('description');
		if($definition) $description = FlexMarkup::value($this->page, $this->field, 'description', $definition, $description, false);
		$file->description(true, $description);
		foreach(['tags', 'created', 'modified', 'created_users_id', 'modified_users_id', 'filesize', 'filedata'] as $key) {
			if(isset($meta[$key])) {
				$file->set($key, $meta[$key]);
			}
		}
		$file->bindAsset($meta['id']);
		$file->hydrateCustomValues($meta['values'] ?? [], array_key_exists('values', $meta));
		$file->isNew(false);
		$file->resetTrackChanges();
		$file->baseline($meta);
		return $file;
	}

	private function appendCopy(array $meta, string $source): Pagefile {
		if($this->page->id) {
			$record = $this->field->type->media()->copy($this, $source, $meta['id'], $meta['basename']);
			$meta['basename'] = $record['basename'];
		} else {
			$meta['basename'] = $meta['id'] . '.' . strtolower(pathinfo($meta['basename'], PATHINFO_EXTENSION));
			$this->pendingCopies[$meta['id']] = $source;
		}
		$copy = $this->hydrateFile($meta);
		WireArray::add($copy);
		return $copy;
	}

	/**
	 * Add an item after validating its identity, owner and collection limits.
	 *
	 * @param mixed $item
	 * @return $this
	 */
	public function add($item) {
		$this->assertWritable();
		if($item instanceof Pagefile && $item->pagefiles === $this && method_exists($item, 'assetId')) {
			return WireArray::add($item);
		}
		$this->checkLimit(true);
		if($item instanceof Pagefile) {
			$meta = FlexMedia::describe($item, bin2hex(random_bytes(16)));
			$this->appendCopy($meta, $item->filename());
			return $this;
		}
		if(!is_string($item)) {
			throw new WireException($this->_('Add a local file path or Pagefile to a Flex media collection'));
		}
		$record = $this->field->type->media()->assetByName($this->page, basename($item));
		if(
			$record
			&& in_array($record['state'], ['uploading', 'staged'], true)
			&& (int) $record['fields_id'] === (int) $this->field->id
			&& $record['address'] === $this->flexAddress
			&& $record['draft'] === $this->flexDraft
		) {
			$meta = [
				'id'       => $record['asset_id'],
				'basename' => $record['basename'],
			];
		} else {
			if(str_contains($item, '://') || !is_file($item) || !is_readable($item)) {
				throw new WireException($this->_('Flex imports require a readable local file'));
			}
			$meta = [
				'id'       => bin2hex(random_bytes(16)),
				'basename' => basename($item),
			];
			FlexMedia::validateMeta($meta);
			$this->appendCopy($meta, realpath($item));
			return $this;
		}
		$file = $this->hydrateFile($meta);
		WireArray::add($file);
		if($this->page->id) {
			$this->preparePending();
		}
		return $this;
	}

	/**
	 * Assign a value through the collection’s ownership and type checks.
	 *
	 * @param string|int $key
	 * @param mixed $value
	 * @return $this
	 */
	public function set($key, $value) {
		if(!$value instanceof Pagefile || $value->pagefiles !== $this || $key !== $value->basename()) {
			throw new WireException($this->_('A Flex file must belong to its collection'));
		}
		return $this->add($value);
	}

	/**
	 * Remove an item while retaining transaction-safe ownership cleanup.
	 *
	 * @param string|Pagefile $key File basename or owned native file.
	 * @return $this
	 */
	public function remove($key) {
		$this->assertWritable();
		$file = $key instanceof Pagefile ? $key : $this->get($key);
		if(!$file) {
			return $this;
		}
		if($file->pagefiles !== $this || !isset($this->data[$file->basename()])) {
			throw new WireException($this->_('File does not belong to this collection'));
		}
		return WireArray::remove($file->basename());
	}

	/**
	 * Remove every item through the normal mutation and ownership checks.
	 *
	 * @return $this
	 */
	public function removeAll() {
		$this->assertWritable();
		foreach(array_keys($this->data) as $key) {
			$this->remove($key);
		}
		return $this;
	}

	/**
	 * Remove an item through the same ownership checks as ordinary removal.
	 *
	 * @param string|Pagefile $key File basename or owned native file.
	 * @return $this
	 */
	public function removeQuietly($key) {
		return $this->remove($key);
	}

	/**
	 * Stage a new basename while retaining the logical asset identity.
	 *
	 * @param Pagefile $file
	 * @param string $basename
	 * @return string
	 */
	public function rename(Pagefile $file, $basename) {
		$this->assertWritable();
		if($file->pagefiles !== $this || $this->get($file->basename()) !== $file) {
			throw new WireException($this->_('Unknown media original'));
		}
		$basename = $this->cleanBasename((string) $basename, false, true, true);
		if($basename === $file->basename()) {
			return $file->basename();
		}
		$record = $this->field->type->media()->stageRename($file, $basename);
		$oldKey = $file->basename();
		$this->flexHydrating = true;
		try {
			$file->setFilename($record['basename']);
		} finally {
			$this->flexHydrating = false;
		}
		$data = [];
		foreach($this->data as $key => $item) {
			$data[$key === $oldKey ? $file->basename() : $key] = $item;
		}
		$this->data = $data;
		$this->trackChange('file');
		return $file->basename();
	}

	/**
	 * Stage independent replacement bytes and rebind the native file object.
	 *
	 * @param Pagefile $file
	 * @param string $source
	 * @param string|null $basename
	 * @return string
	 */
	public function replaceOwned(Pagefile $file, string $source, ?string $basename = null) {
		$this->assertWritable();
		if($file->pagefiles !== $this || !isset($this->data[$file->basename()])) {
			throw new WireException($this->_('Unknown media original'));
		}
		$oldKey = $file->basename();
		$meta = FlexMedia::describe($file, bin2hex(random_bytes(16)));
		$record = $this->field->type->media()->copy($this, $source, $meta['id'], $basename ?: basename($source));
		$meta['basename'] = $record['basename'];
		WireArray::remove($oldKey);
		$this->flexHydrating = true;
		try {
			$file->setFilename($meta['basename']);
			$file->bindAsset($meta['id']);
		} finally {
			$this->flexHydrating = false;
		}
		WireArray::add($file);
		$this->trackChange('file');
		return $file->basename();
	}

	/**
	 * Copy imports deferred until the owning page has a saved identity.
	 *
	 * @return void
	 */
	public function preparePending(): void {
		if(!$this->page->id && $this->pendingCopies) {
			throw new WireException($this->_('Save the owner page before committing its files'));
		}
		foreach($this->pendingCopies as $id => $source) {
			$file = null;
			foreach($this as $item) {
				if($item->assetId() === $id) {
					$file = $item;
					break;
				}
			}
			if(!$file) {
				unset($this->pendingCopies[$id]);
				continue;
			}
			if($this->page->get('_cloning') instanceof Page) {
				$this->field->type->media()->reserve($this, $id, $file->basename(), 'cloning');
			} else {
				$record = $this->field->type->media()->copy($this, $source, $id, $file->basename());
				$old = $file->basename();
				$this->flexHydrating = true;
				try {
					$file->setFilename($record['basename']);
				} finally {
					$this->flexHydrating = false;
				}
				unset($this->data[$old]);
				$this->data[$file->basename()] = $file;
			}
			unset($this->pendingCopies[$id]);
		}
	}

	/**
	 * Prepare media copies and ownership before canonical field persistence.
	 *
	 * @return void
	 */
	public function prepareCommit(): void {
		$this->preparePending();
		$this->checkLimit();
		foreach($this as $file) {
			if((int) $file->created === 10 || !$file->created) {
				$file->created = time();
			}
			if(!$file->modified || $file->isChanged()) {
				$file->modified = time();
			}
			if(!$file->created_users_id) {
				$file->created_users_id = $this->wire()->user->id;
			}
			if($file->isChanged()) {
				$file->modified_users_id = $this->wire()->user->id;
			}
		}
	}

	/**
	 * Check the file limit, optionally reserving room for one new original.
	 *
	 * @param bool $adding Include one pending addition in the limit check.
	 * @return void
	 * @throws WireException
	 */
	public function checkLimit(bool $adding = false): void {
		$max = (int) ($this->flexDefinition['settings']['maxFiles'] ?? 0);
		if($max && count($this) + ($adding ? 1 : 0) > $max) {
			throw new WireException($this->_('Maximum Flex file count reached'));
		}
	}

	/**
	 * Track a nested change on the owning Flex field as well as this collection.
	 *
	 * @param string $what
	 * @param mixed $old
	 * @param mixed $new
	 * @return $this
	 */
	public function trackChange($what, $old = null, $new = null) {
		if(!$this->flexHydrating) {
			$this->assertWritable();
			$this->page->trackChange($this->field->name);
		}
		return WireArray::trackChange($what, $old, $new);
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
		foreach($this as $file) {
			if($file->isChanged()) {
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
		foreach($this as $file) {
			$file->resetTrackChanges($trackChanges);
		}
		$this->unlinkQueue = [];
		$this->renameQueue = [];
		$this->unTempQueue = [];
		return WireArray::resetTrackChanges($trackChanges);
	}

	/**
	 * Keep file lifecycle handling with Flex instead of native page-save hooks.
	 *
	 * @return $this
	 */
	public function hookPageSave() {
		return $this;
	}

	protected function addSaveHook() {
	}

	/**
	 * Leave temporary file cleanup to the Flex ownership collector.
	 *
	 * @return int
	 */
	public function deleteAllTemp() {
		return 0;
	}

	/**
	 * Clear the native temporary flag without bypassing Flex ownership.
	 *
	 * @param Pagefile $file
	 * @return $this
	 */
	public function unTempQueue(Pagefile $file) {
		$file->isTemp(false);
		return $this;
	}

	/**
	 * Create an empty collection with the same owner and definition.
	 *
	 * @return static
	 */
	public function makeNew() {
		return new static($this->page, $this->field, $this->flexAddress, $this->flexDefinition);
	}

	/**
	 * Return a limited output collection without triggering owned-file removal.
	 *
	 * @param int $limit Maximum visible files; zero means unlimited.
	 * @return static
	 * #pw-internal
	 * @internal
	 */
	public function outputCollection(int $limit = 0) {
		$view = clone $this;
		$view->rawDescriptions = false;
		if($limit > 0) {
			$view->data = array_slice($view->data, 0, $limit, true);
		}
		$view->formatted(true);
		return $view;
	}

	/** Bypass description formatting while retaining the view's mutation guards. @internal */
	public function unformattedCollection() {
		$view = clone $this;
		$view->rawDescriptions = true;
		return $view;
	}

	/**
	 * Clone this collection and its contained native files.
	 *
	 * @return static
	 */
	public function makeCopy() {
		return clone $this;
	}

	/**
	 * Clone nested objects so subsequent edits remain independent.
	 *
	 * @return void
	 */
	public function __clone() {
		$metadata = [];
		foreach($this as $file) {
			$metadata[] = FlexMedia::describe($file, $file->assetId());
		}
		$this->data = [];
		$this->flexHydrating = true;
		$this->hookIDs = [];
		foreach($metadata as $meta) {
			WireArray::add($this->hydrateFile($meta));
		}
		$this->flexHydrating = false;
	}

	/**
	 * Export unformatted values in the canonical persistence shape.
	 *
	 * @return array
	 */
	public function rawArray(): array {
		$items = [];
		foreach($this as $file) {
			$items[] = FlexMedia::describe($file, $file->assetId());
		}
		return [
			'_flex' => $this->marker(),
			'items' => $items,
		];
	}

	/**
	 * Append, prepend or insert an independent copy of an owned file.
	 *
	 * @param Pagefile $file
	 * @param array $options Native action options.
	 * @return Pagefile
	 */
	public function ___clone(Pagefile $file, array $options = []) {
		foreach(array_keys($options) as $key) {
			if(!in_array($key, ['action', 'pagefiles'], true)) {
				throw new WireException($this->_('Unknown Flex file clone option'));
			}
		}
		if(isset($options['pagefiles']) && $options['pagefiles'] !== $this) {
			throw new WireException($this->_('Add the source file to the target Flex collection when copying between collections'));
		}
		$action = $options['action'] ?? 'after';
		if(!in_array($action, ['append', 'prepend', 'before', 'after'], true)) {
			throw new WireException($this->_('Unknown Flex file clone action'));
		}
		$this->assertWritable();
		$this->checkLimit(true);
		$meta = FlexMedia::describe($file, bin2hex(random_bytes(16)));
		$copy = $this->appendCopy($meta, $file->filename());
		if($action === 'prepend') {
			WireArray::prepend($copy);
		} else if($file->pagefiles === $this && $this->get($file->basename()) === $file) {
			if($action === 'before') {
				WireArray::insertBefore($copy, $file);
			} else if($action === 'after') {
				WireArray::insertAfter($copy, $file);
			}
		}
		return $copy;
	}
}
class FlexFiles extends Pagefiles {
	use FlexMediaCollection;
}

class FlexImages extends Pageimages {
	use FlexMediaCollection;
}

/** Durable ownership, staged imports and post-commit cleanup. @internal */
class FlexMedia extends Wire {
	private FieldtypeFlex $type;
	private ?WireDatabasePDO $stagingDatabase = null;
	private array $manifest = [];

	/**
	 * Initialize the object with its owning ProcessWire context.
	 *
	 * @param FieldtypeFlex $type Owning fieldtype service.
	 */
	public function __construct(FieldtypeFlex $type) {
		parent::__construct();
		$type->wire($this);
		$this->type = $type;
	}

	/** Reserve native methods, documented properties and technical state. @internal */
	public static function reservedName(string $name): bool {
		if(preg_match('/^(?:description[0-9]+|_.*|flex.*)$/iD', $name) || in_array(strtolower($name), ['id', 'sort', 'values', 'customfields'], true)) return true;
		static $reserved = null;
		if($reserved === null) {
			$reserved = [];
			foreach([FlexPagefile::class, FlexPageimage::class] as $class) {
				$reflect = new \ReflectionClass($class);
				foreach($reflect->getMethods() as $method) $reserved[strtolower(ltrim($method->name, '_'))] = true;
				foreach($reflect->getProperties() as $property) $reserved[strtolower($property->name)] = true;
				// Native magic properties are part of the public contract too.
				for($r = $reflect; $r; $r = $r->getParentClass()) {
					preg_match_all('/@property(?:-read|-write)?\s+\S+\s+\$(\w+)/', $r->getDocComment() ?: '', $matches);
					foreach($matches[1] as $property) $reserved[strtolower($property)] = true;
				}
			}
		}
		return isset($reserved[strtolower($name)]);
	}

	/** Shared envelope shape used before hydration and by raw diagnostics. @internal */
	public static function validEnvelope($raw, string $marker): bool {
		return is_array($raw) && ($raw['_flex'] ?? '') === $marker
			&& isset($raw['items']) && is_array($raw['items']) && array_is_list($raw['items']);
	}

	/**
	 * Validate the persisted media identity and safe original basename.
	 *
	 * @param mixed $meta
	 * @return void
	 */
	public static function validateMeta($meta): void {
		if(
			!is_array($meta)
			|| !isset($meta['id'], $meta['basename'])
			|| !is_string($meta['id'])
			|| !preg_match('/^[a-f0-9]{32}$/D', $meta['id'])
			|| !is_string($meta['basename'])
			|| in_array($meta['basename'], ['', '.', '..'], true)
			|| strlen($meta['basename']) > 255
			|| $meta['basename'] !== basename($meta['basename'])
			|| preg_match('/[\\\\\/\x00-\x1f]/', $meta['basename'])
		) {
			throw new WireException(__('Invalid Flex file metadata'));
		}
		if(array_key_exists('values', $meta) && (!is_array($meta['values']) || ($meta['values'] && array_is_list($meta['values'])) || array_key_exists('description', $meta['values']) || array_key_exists('tags', $meta['values']))) throw new WireException(__('Invalid Flex custom metadata property map'));
	}

	/**
	 * Export native file metadata, including language descriptions and image state.
	 *
	 * @param Pagefile $file
	 * @param string $id Stable row or asset identity.
	 * @return array
	 */
	public static function describe(Pagefile $file, string $id): array {
		$descriptions = [];
		$languages = $file->wire()->languages;
		if($languages) {
			foreach($languages as $language) {
				$descriptions[$language->isDefault() ? '0' : (string) $language->id] = $file instanceof FlexPagefile || $file instanceof FlexPageimage
					? $file->rawDescription($language) : $file->description($language);
			}
		} else {
			$descriptions['0'] = $file instanceof FlexPagefile || $file instanceof FlexPageimage
				? $file->rawDescription() : $file->description();
		}
		$meta = [
			'id'                => $id,
			'basename'          => $file->basename(),
			'description'       => $descriptions,
			'tags'              => (string) $file->tags,
			'created'           => (int) $file->created,
			'modified'          => (int) $file->modified,
			'created_users_id'  => (int) $file->created_users_id,
			'modified_users_id' => (int) $file->modified_users_id,
			'filesize'          => (int) ($file->page->id && !$file->page->get('_cloning') ? $file->get('filesize') : ($file->getArray()['filesize'] ?? 0)),
			'filedata'          => $file->filedata(),
		];
		if(($file instanceof FlexPagefile || $file instanceof FlexPageimage) && $file->rawCustomValues() !== null) $meta['values'] = $file->rawCustomValues();
		self::validateMeta($meta);
		return $meta;
	}

	private function journal(): WireDatabasePDO {
		if(!$this->wire()->database->inTransaction()) {
			return $this->wire()->database;
		}
		if(!$this->stagingDatabase) {
			$config = clone $this->wire()->config;
			$options = is_array($config->dbOptions) ? $config->dbOptions : [];
			$options[\PDO::ATTR_PERSISTENT] = false;
			$config->dbOptions = $options;
			$this->stagingDatabase = WireDatabasePDO::getInstance($config);
			if($this->stagingDatabase->inTransaction()) {
				throw new WireException($this->_('Media staging requires an independent connection'));
			}
		}
		return $this->stagingDatabase;
	}

	/**
	 * Load an ownership record, optionally locking it within the caller’s transaction.
	 *
	 * @param string $id Stable row or asset identity.
	 * @param bool $lock Lock the record within the current transaction.
	 * @return array|null
	 */
	public function asset(string $id, bool $lock = false): ?array {
		if(!$lock && array_key_exists($id, $this->manifest)) {
			return $this->manifest[$id];
		}
		$db = $lock ? $this->wire()->database : $this->journal();
		$q = $db->pdo('writer')->prepare('SELECT * FROM fieldtype_flex_assets WHERE asset_id=:id' . ($lock ? ' FOR UPDATE' : ''));
		$q->execute(['id' => $id]);
		$record = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
		if(!$lock) {
			$this->manifest[$id] = $record;
		}
		return $record;
	}

	/** Probe original paths without constructing PagefilesManager (which can mkdir/rename). @internal */
	public function originalExists(Page $page, array $owner): ?bool {
		if($this->wire()->hooks->isHooked('PagefilesManager::path()')) return null;
		self::validateMeta(['id' => $owner['asset_id'], 'basename' => $owner['basename']]);
		$config = $this->wire()->config;
		$root = realpath($config->paths->files);
		if(!$root) return null;
		$prefixes = array_unique(['', PagefilesManager::defaultSecurePathPrefix, (string) $config->pagefileSecurePathPrefix]);
		$directories = [];
		foreach($prefixes as $prefix) {
			$directories[] = $prefix . $page->id . '/';
			$directories[] = PagefilesManager::_dirExtended($page->id, $prefix);
		}
		$data = json_decode($owner['data'], true, 64, JSON_THROW_ON_ERROR);
		if(isset($data['directory'])) {
			if(!is_string($data['directory'])) throw new WireException('Invalid registered assets directory');
			$directories[] = $data['directory'];
		}
		foreach(array_unique($directories) as $directory) {
			if(!is_string($directory) || str_contains($directory, '..') || str_starts_with($directory, '/') || PagefilesManager::dirToPageID($directory) !== (int) $page->id) continue;
			$folder = realpath($root . '/' . $directory);
			if(!$folder || !str_starts_with($folder . '/', $root . '/')) continue;
			$path = $folder . '/' . $owner['basename'];
			clearstatcache(true, $path);
			if(is_file($path)) return true;
		}
		return false;
	}

	/**
	 * Batch ownership records needed to hydrate the canonical field value.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @return void
	 */
	public function prefetch(array $raw): void {
		$ids = [];
		foreach(self::records($raw) as $record) {
			if(!array_key_exists($record['meta']['id'], $this->manifest)) {
				$ids[$record['meta']['id']] = true;
			}
		}
		foreach(array_chunk(array_keys($ids), 500) as $batch) {
			$q = $this->journal()->pdo('writer')->prepare('SELECT * FROM fieldtype_flex_assets WHERE asset_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')');
			$q->execute($batch);
			foreach($batch as $id) {
				$this->manifest[$id] = null;
			}
			foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $record) {
				$this->manifest[$record['asset_id']] = $record;
			}
		}
	}

	/**
	 * Find an owned original by page and basename.
	 *
	 * @param Page $page Owning page.
	 * @param string $basename
	 * @return array|null
	 */
	public function assetByName(Page $page, string $basename): ?array {
		$q = $this->journal()->pdo('writer')->prepare('SELECT * FROM fieldtype_flex_assets WHERE pages_id=:page AND basename=:name');
		$q->execute([
			'page' => $page->id,
			'name' => $basename,
		]);
		return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Durably reserve an original filename and ownership record before byte writes.
	 *
	 * @param Pagefiles $files
	 * @param string $id Stable row or asset identity.
	 * @param string $basename
	 * @param string $state
	 * @return array
	 */
	public function reserve(Pagefiles $files, string $id, string $basename, string $state = 'staged'): array {
		$meta = [
			'id'       => $id,
			'basename' => $basename,
		];
		self::validateMeta($meta);
		if(!$files->page->id || !$files->field->id) {
			throw new WireException($this->_('Media staging needs a saved owner and field'));
		}
		$directory = substr($files->page->filesManager()->path(), strlen($this->wire()->config->paths->files));
		if(str_contains($directory, '..') || str_starts_with($directory, '/')) {
			throw new WireException($this->_('Invalid page assets directory'));
		}
		if(PagefilesManager::dirToPageID($directory) !== (int) $files->page->id) {
			throw new WireException($this->_('Page assets directory does not match the Flex owner'));
		}
		$q = $this->journal()->pdo('writer')->prepare('INSERT INTO fieldtype_flex_assets (asset_id,fields_id,pages_id,address,basename,state,draft,expires,data) VALUES (:id,:field,:page,:address,:name,:state,:draft,:expires,:data)');
		$data = [
			'kind'      => $files instanceof Pageimages ? 'image' : 'file',
			'directory' => $directory,
			'meta'      => $meta,
		];
		if($state === 'cloning' && $files->page->get('_cloning') instanceof Page) {
			$data['source_page'] = (int) $files->page->get('_cloning')->id;
		}
		$q->execute([
			'id'      => $id,
			'field'   => $files->field->id,
			'page'    => $files->page->id,
			'address' => $files->address(),
			'name'    => $basename,
			'state'   => $state,
			'draft'   => $files->draft(),
			'expires' => time() + 21600,
			'data'    => json_encode($data, JSON_THROW_ON_ERROR),
		]);
		unset($this->manifest[$id]);
		return $this->asset($id);
	}

	/**
	 * Choose a safe unused basename for an independently owned original.
	 *
	 * @param Page $page Owning page.
	 * @param string $requested
	 * @param string $id Stable row or asset identity.
	 * @return string
	 */
	public function uniqueName(Page $page, string $requested, string $id): string {
		// Match WireUpload before reserving: its translated filename sanitizer
		// also collapses repeated underscores and preserves long-name extensions.
		$requested = $this->wire()->sanitizer->filename($requested, Sanitizer::translate);
		$ext = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
		if(!preg_match('/^[a-z0-9]{1,10}$/D', $ext)) {
			throw new WireException($this->_('Invalid file extension'));
		}
		$stem = strtolower(pathinfo($requested, PATHINFO_FILENAME));
		$stem = substr(preg_replace('/[^a-z0-9_-]/', '-', $stem), 0, 80) ?: 'file';
		$stem = trim($stem, '-_') ?: 'file';
		$name = $stem . '-' . substr($id, 0, 12) . '.' . $ext;
		if(file_exists($page->filesManager()->path() . $name) || $this->assetByName($page, $name)) {
			throw new WireException($this->_('Cannot reserve unique media filename'));
		}
		return $name;
	}

	/**
	 * Validate and stage a readable local original in the target collection.
	 *
	 * @param Pagefiles $files
	 * @param string $source
	 * @param string $id Stable row or asset identity.
	 * @param string $requested
	 * @param bool $exactName Use an already selected rename target.
	 * @return array
	 */
	public function copy(Pagefiles $files, string $source, string $id, string $requested, bool $exactName = false): array {
		if(str_contains($source, '://') || !is_file($source) || !is_readable($source)) {
			throw new WireException($this->_('Media copy source is not a readable local file'));
		}
		$extensions = preg_split('/[\s,]+/', strtolower((string) ($files->definition()['settings']['extensions'] ?? ($files instanceof Pageimages ? 'jpg jpeg gif png' : ''))), -1, PREG_SPLIT_NO_EMPTY);
		if(!in_array(strtolower(pathinfo($requested, PATHINFO_EXTENSION)), $extensions, true)) {
			throw new WireException($this->_('Unsupported file extension'));
		}
		if($files instanceof Pageimages && !getimagesize($source)) {
			throw new WireException($this->_('Invalid image file'));
		}
		$max = (int) ($files->definition()['settings']['maxFilesize'] ?? 0);
		if($max && filesize($source) > $max) {
			throw new WireException($this->_('File exceeds maxFilesize'));
		}
		$name = $exactName ? $requested : $this->uniqueName($files->page, $requested, $id);
		$record = $this->reserve($files, $id, $name);
		if($exactName) {
			try {
				$this->copyExclusive($source, $files->path() . $name);
			} catch(\Throwable $error) {
				$this->journal()->prepare('DELETE FROM fieldtype_flex_assets WHERE asset_id=:id')->execute(['id' => $id]);
				unset($this->manifest[$id]);
				throw $error;
			}
		} else if(!copy($source, $files->page->filesManager()->path() . $name)) {
			throw new WireException($this->_('Unable to stage media copy'));
		}
		$this->wire()->files->chmod($files->page->filesManager()->path() . $name);
		return $record;
	}

	/** Stage a rename under separate physical ownership until the field commits. */
	public function stageRename(Pagefile $file, string $requested): array {
		$files = $file->pagefiles;
		$owner = $this->asset($file->assetId());
		if(!$owner || (int) $owner['pages_id'] !== (int) $files->page->id || (int) $owner['fields_id'] !== (int) $files->field->id) {
			throw new WireException($this->_('Unknown media original'));
		}
		$requested = $files->cleanBasename($requested, false, true, true);
		$oldStem = pathinfo($file->basename(), PATHINFO_FILENAME);
		// Use fresh native variation discovery rather than a retained image cache.
		$native = $this->wire(new ($file instanceof Pageimage ? Pageimage::class : Pagefile::class)($files, $file->basename()));
		$sources = array_filter($native->getFiles(), 'is_file');
		$stageId = bin2hex(random_bytes(16));
		$stem = pathinfo($requested, PATHINFO_FILENAME);
		$ext = pathinfo($requested, PATHINFO_EXTENSION);
		$name = $requested;
		for($n = 0; ; ++$n) {
			$targets = [];
			$newStem = pathinfo($name, PATHINFO_FILENAME);
			foreach($sources as $source) {
				$targets[$source] = $source === $file->filename() ? $name : $newStem . substr(basename($source), strlen($oldStem));
			}
			$conflict = false;
			if($file instanceof Pageimage) {
				$candidate = $this->wire(new Pageimage($files, $name));
				// An existing derivative of any size makes this stem unsafe: later
				// native cleanup would otherwise mistake it for our own variation.
				$conflict = (bool) array_filter($candidate->getFiles(), 'file_exists');
			}
			foreach(array_unique([$name, ...array_values($targets)]) as $target) {
				if(file_exists($files->path() . $target) || $this->assetByName($files->page, $target)) {
					$conflict = true;
					break;
				}
			}
			if(!$conflict) break;
			$name = $stem . '-' . substr($stageId, 0, 12) . ($n ? "-$n" : '') . '.' . $ext;
		}
		$record = $this->copy($files, $file->filename(), $stageId, $name, true);
		$copied = [$files->path() . $name];
		try {
			foreach($targets as $source => $target) {
				if($source === $file->filename()) continue;
				$this->copyExclusive($source, $files->path() . $target);
				$copied[] = $files->path() . $target;
			}
		} catch(\Throwable $error) {
			// Only discard paths we created exclusively. A racing native file is
			// not ours and must never become eligible for the staged collector.
			foreach($copied as $path) unlink($path);
			$this->journal()->prepare('DELETE FROM fieldtype_flex_assets WHERE asset_id=:id')->execute(['id' => $stageId]);
			unset($this->manifest[$stageId]);
			throw $error;
		}
		$data = json_decode($record['data'], true, 64, JSON_THROW_ON_ERROR);
		$data['renameFrom'] = ['id' => $file->assetId(), 'basename' => $owner['basename']];
		$this->journal()->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute([
			'data' => json_encode($data, JSON_THROW_ON_ERROR),
			'id' => $stageId,
		]);
		unset($this->manifest[$stageId]);
		return $record;
	}

	/** Never overwrite a native original or derivative that appeared after reservation. */
	private function copyExclusive(string $source, string $target): void {
		$input = @fopen($source, 'rb');
		$output = $input ? @fopen($target, 'xb') : false;
		try {
			if(!$input || !$output || stream_copy_to_stream($input, $output) !== filesize($source)) {
				throw new WireException($this->_('Unable to stage media copy'));
			}
		} catch(\Throwable $error) {
			if($output) unlink($target);
			throw $error;
		} finally {
			if($input) fclose($input);
			if($output) fclose($output);
		}
		$this->wire()->files->chmod($target);
	}

	/**
	 * Validate an upload reservation and mark its bytes as staged.
	 *
	 * @param Pagefile $file
	 * @return void
	 */
	public function finishUpload(Pagefile $file): void {
		$record = $this->asset($file->assetId());
		if(!$record || $record['state'] !== 'uploading' || $record['draft'] !== $file->pagefiles->draft()) {
			throw new WireException($this->_('Invalid upload reservation'));
		}
		$data = json_decode($record['data'], true, 64, JSON_THROW_ON_ERROR);
		$data['meta'] = self::describe($file, $file->assetId());
		$q = $this->journal()->pdo('writer')->prepare("UPDATE fieldtype_flex_assets SET state='staged',data=:data WHERE asset_id=:id");
		$q->execute([
			'data' => json_encode($data, JSON_THROW_ON_ERROR),
			'id'   => $file->assetId(),
		]);
		unset($this->manifest[$file->assetId()]);
	}

	/**
	 * Expire a staged asset after checking any supplied owner and draft context.
	 *
	 * @param string $id Stable row or asset identity.
	 * @param Page|null $page Owning page.
	 * @param Field|null $field Owning Flex field.
	 * @param string $draft Editor draft state or token.
	 * @return void
	 */
	public function abandon(string $id, ?Page $page = null, ?Field $field = null, string $draft = ''): void {
		$record = $this->asset($id);
		if(!$record || !in_array($record['state'], ['uploading', 'staged'], true)) {
			return;
		}
		if(
			$page
			&& ((int) $record['pages_id'] !== (int) $page->id || (int) $record['fields_id'] !== (int) $field->id || $record['draft'] !== $draft)
		) {
			return;
		}
		$q = $this->journal()->pdo('writer')->prepare("UPDATE fieldtype_flex_assets SET state='pending_delete' WHERE asset_id=:id");
		$q->execute(['id' => $id]);
		if(!$this->wire()->database->inTransaction()) {
			$this->sweep($page, $field);
		}
	}

	/**
	 * Transform a registered staging copy; never modify the committed original.
	 *
	 * @param Pageimage $file
	 * @param string $action
	 * @param array $options Native action options.
	 * @return Pageimage
	 */
	public function transform(Pageimage $file, string $action, array $options = []): Pageimage {
		$files = $file->pagefiles;
		if(!$files instanceof FlexImages) {
			throw new WireException($this->_('Image must belong to Flex'));
		}
		$files->replaceOwned($file, $file->filename(), $file->basename());
		$sizer = $this->wire(new ImageSizer($file->filename()));
		$ok = false;
		if($action === 'rotate90') {
			$ok = $sizer->rotate(90);
		} else if($action === 'rotate180') {
			$ok = $sizer->rotate(180);
		} else if($action === 'flipH') {
			$ok = $sizer->flipHorizontal();
		} else if($action === 'flipV') {
			$ok = $sizer->flipVertical();
		} else if(in_array($action, ['crop', 'resize'], true)) {
			$width = (int) ($options['width'] ?? 0);
			$height = (int) ($options['height'] ?? 0);
			if($width < 1 || $width > 10000 || $height < 0 || $height > 10000) {
				throw new WireException($this->_('Invalid image dimensions'));
			}
			if($action === 'crop') {
				$x = (int) ($options['x'] ?? 0);
				$y = (int) ($options['y'] ?? 0);
				if($x < 0 || $y < 0 || !$height || $x + $width > $file->width() || $y + $height > $file->height()) {
					throw new WireException($this->_('Crop is outside the image'));
				}
				$variation = $file->crop($x, $y, $width, $height, ['forceNew' => true]);
			} else {
				$variation = $file->size($width, $height, [
					'cropping' => false,
					'forceNew' => true,
				]);
			}
			if($variation && $variation->filename() !== $file->filename() && is_file($variation->filename())) {
				$ok = copy($variation->filename(), $file->filename());
				if($ok) {
					unlink($variation->filename());
				}
			}
		} else {
			throw new WireException($this->_('Unsupported Flex image action'));
		}
		if(!$ok) {
			throw new WireException($this->_('Unable to transform staged image'));
		}
		clearstatcache(true, $file->filename());
		$file->getImageInfo(true);
		$file->filesize(true);
		$file->trackChange('file');
		return $file;
	}

	/**
	 * Prepare root and row media for ownership-safe persistence.
	 *
	 * @param FlexValue $value
	 * @return void
	 */
	public function prepare(FlexValue $value): void {
		FlexPath::validate($value->rawArray(), $value->ownerField());
		if($value->address() === '' && $value->ownerPage()->get('_cloning') instanceof Page) {
			// Page::__clone() initially retains the source manager path. Initialize
			// it with the final target ID before journaling any physical directory.
			$value->ownerPage()->filesManager()->init($value->ownerPage());
		}
		$value->prepareUnknownMedia();
		foreach($value->definitions()->properties() as $name => $definition) {
			$item = $value->getUnformatted($name);
			if($item instanceof FlexValue) {
				$this->prepare($item);
			} else if($item instanceof FlexRows) {
				foreach($item as $row) {
					$this->prepare($row);
				}
			} else if($item instanceof FlexFiles || $item instanceof FlexImages) {
				$item->prepareCommit();
			}
		}
		if($value->address() === '' && $value->ownerPage()->get('_cloning') instanceof Page) {
			$this->prepareCloneExtras($value);
		}
	}

	/** Reserve copied draft orphans before the canonical transaction takes asset locks. */
	private function prepareCloneExtras(FlexValue $value): void {
		$copy = $value->ownerPage();
		$field = $value->ownerField();
		$source = $copy->get('_cloning');
		$names = [];
		foreach(self::records($value->rawArray()) as $record) {
			$names[$record['meta']['basename']] = true;
		}
		$q = $this->journal()->pdo('writer')->prepare("SELECT * FROM fieldtype_flex_assets WHERE pages_id=:page AND fields_id=:field AND state<>'committed'");
		$q->execute([
			'page'  => $source->id,
			'field' => $field->id,
		]);
		foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $asset) {
			if(isset($names[$asset['basename']]) || $this->assetByName($copy, $asset['basename'])) {
				continue;
			}
			$data = json_decode($asset['data'], true, 64, JSON_THROW_ON_ERROR);
			$class = $data['kind'] === 'image' ? FlexImages::class : FlexFiles::class;
			$files = new $class($copy, $field, $asset['address'], ['settings' => []]);
			$this->reserve($files, bin2hex(random_bytes(16)), $asset['basename'], 'pending_delete');
		}
	}

	/**
	 * Apply the authenticated draft token to root and row media collections.
	 *
	 * @param FlexValue $value
	 * @param string $token
	 * @return void
	 */
	public function setDraft(FlexValue $value, string $token): void {
		foreach($value->definitions()->properties() as $name => $definition) {
			$item = $value->getUnformatted($name);
			if($item instanceof FlexValue) {
				$this->setDraft($item, $token);
			} else if($item instanceof FlexRows) {
				foreach($item as $row) {
					$this->setDraft($row, $token);
				}
			} else if($item instanceof FlexFiles || $item instanceof FlexImages) {
				$item->draft($token);
			}
		}
	}

	/**
	 * Update conflict baselines after canonical metadata has been saved.
	 *
	 * @param FlexValue $value
	 * @param array $raw Unformatted persisted values.
	 * @return void
	 */
	public function baselines(FlexValue $value, array $raw): void {
		$metadata = [];
		foreach(self::records($raw) as $record) {
			$metadata[$record['meta']['id']] = $record['meta'];
		}
		$walk = function(FlexValue $item) use (&$walk, $metadata): void {
			foreach($item->definitions()->properties() as $name => $definition) {
				$child = $item->getUnformatted($name);
				if($child instanceof FlexValue) {
					$walk($child);
				} else if($child instanceof FlexRows) {
					foreach($child as $row) {
						$walk($row);
					}
				} else if($child instanceof FlexFiles || $child instanceof FlexImages) {
					foreach($child as $file) {
						if(isset($metadata[$file->assetId()])) {
							$file->baseline($metadata[$file->assetId()]);
						}
					}
				}
			}
		};
		$walk($value);
	}

	/**
	 * Flatten root and repeatable media records with their property addresses.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @param string $prefix Scope prefix for property names.
	 * @return array
	 */
	public static function records(array $raw, string $prefix = ''): array {
		$records = [];
		foreach($raw as $name => $value) {
			if(!is_array($value)) {
				continue;
			}
			$address = $prefix === '' ? $name : "$prefix.$name";
			if(($value['_flex'] ?? '') === FlexStorage::OBJECT_FORMAT) {
				$records = array_merge($records, self::records($value['values'], $address));
			} else if(($value['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
				foreach($value['items'] as $row) {
					$records = array_merge($records, self::records($row['values'], "$address.{$row['id']}"));
				}
			} else if(in_array($value['_flex'] ?? '', [FlexStorage::FILES_FORMAT, FlexStorage::IMAGES_FORMAT], true)) {
				foreach($value['items'] as $meta) {
					self::validateMeta($meta);
					$records[] = [
						'address' => $address,
						'kind'    => $value['_flex'] === FlexStorage::IMAGES_FORMAT ? 'image' : 'file',
						'meta'    => $meta,
					];
				}
			}
		}
		return $records;
	}

	/**
	 * Synchronize ownership records with canonical media in the current transaction.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param array $raw Unformatted persisted values.
	 * @return void
	 */
	public function sync(Page $page, Field $field, array $raw): void {
		$db = $this->wire()->database;
		$seen = [];
		foreach(self::records($raw) as $record) {
			$meta = $record['meta'];
			$id = $meta['id'];
			if(isset($seen[$id])) {
				throw new WireException($this->_('Two properties cannot share a Flex asset'));
			}
			$seen[$id] = true;
			$owner = $this->asset($id, true);
			if($owner && $owner['basename'] !== $meta['basename']) {
				$owner = $this->commitRename($page, $field, $record, $owner);
			}
			if(
				!$owner
				|| (int) $owner['pages_id'] !== (int) $page->id
				|| (int) $owner['fields_id'] !== (int) $field->id
				|| $owner['basename'] !== $meta['basename']
			) {
				throw new WireException($this->_('Flex asset ownership does not match canonical value'));
			}
			if($owner['state'] === 'uploading') {
				throw new WireException($this->_('Upload has not completed'));
			}
			$data = json_decode($owner['data'], true, 64, JSON_THROW_ON_ERROR);
			$oldFiledata = $data['meta']['filedata'] ?? [];
			$data['kind'] = $record['kind'];
			$data['meta'] = $meta;
			if(
				$record['kind'] === 'image'
				&& $owner['state'] === 'committed'
				&& FlexStorage::revision($oldFiledata) !== FlexStorage::revision($meta['filedata'] ?? [])
			) {
				$data['cleanupVariations'] = true;
			}
			$q = $db->prepare('UPDATE fieldtype_flex_assets SET address=:address,state=:state,draft=\'\',expires=:expires,data=:data WHERE asset_id=:id');
			$q->execute([
				'address' => $record['address'],
				'state'   => $owner['state'] === 'cloning' ? 'cloning' : 'committed',
				'expires' => $owner['state'] === 'cloning' ? time() + 21600 : 0,
				'data'    => json_encode($data, JSON_THROW_ON_ERROR),
				'id'      => $id,
			]);
			unset($this->manifest[$id]);
		}
		$q = $db->prepare("SELECT asset_id FROM fieldtype_flex_assets WHERE fields_id=:field AND pages_id=:page AND state IN ('committed','cloning')");
		$q->execute([
			'field' => $field->id,
			'page'  => $page->id,
		]);
		$mark = $db->prepare("UPDATE fieldtype_flex_assets SET state='pending_delete' WHERE asset_id=:id");
		foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
			if(!isset($seen[$id])) {
				$mark->execute(['id' => $id]);
				unset($this->manifest[$id]);
			}
		}
	}

	/** Transfer physical staging ownership to the stable ID inside the field transaction. */
	private function commitRename(Page $page, Field $field, array $record, array $owner): array {
		$db = $this->wire()->database;
		$q = $db->prepare('SELECT * FROM fieldtype_flex_assets WHERE pages_id=:page AND basename=:name FOR UPDATE');
		$q->execute(['page' => $page->id, 'name' => $record['meta']['basename']]);
		$stage = $q->fetch(\PDO::FETCH_ASSOC);
		$data = $stage ? json_decode($stage['data'], true, 64, JSON_THROW_ON_ERROR) : [];
		if(!$stage || $stage['state'] !== 'staged'
			|| (int) $owner['pages_id'] !== (int) $page->id || (int) $owner['fields_id'] !== (int) $field->id
			|| (int) $stage['fields_id'] !== (int) $field->id || $stage['address'] !== $record['address']
			|| ($data['renameFrom'] ?? null) !== ['id' => $owner['asset_id'], 'basename' => $owner['basename']]
		) {
			throw new WireException($this->_('Flex asset ownership does not match canonical value'));
		}
		$id = $owner['asset_id'];
		$discardId = bin2hex(random_bytes(16));
		// Keep the former original/variations registered for conservative post-commit cleanup.
		$discardData = json_decode($owner['data'], true, 64, JSON_THROW_ON_ERROR);
		$discardData['meta']['id'] = $discardId;
		$db->prepare("UPDATE fieldtype_flex_assets SET asset_id=:discard,state='pending_delete',data=:data WHERE asset_id=:id")->execute([
			'discard' => $discardId, 'id' => $id, 'data' => json_encode($discardData, JSON_THROW_ON_ERROR),
		]);
		unset($data['renameFrom']);
		$db->prepare('UPDATE fieldtype_flex_assets SET asset_id=:id,data=:data WHERE asset_id=:stage')->execute([
			'id' => $id, 'stage' => $stage['asset_id'], 'data' => json_encode($data, JSON_THROW_ON_ERROR),
		]);
		unset($this->manifest[$id], $this->manifest[$stage['asset_id']], $this->manifest[$discardId]);
		$stage['asset_id'] = $id;
		$stage['data'] = json_encode($data, JSON_THROW_ON_ERROR);
		return $stage;
	}

	/**
	 * Core copies the page directory once, after saving field values.
	 *
	 * @param Page $source
	 * @param Page $copy
	 * @return void
	 */
	public function cloned(Page $source, Page $copy): void {
		foreach($copy->fieldgroup as $field) {
			if(!$field->type instanceof FieldtypeFlex) {
				continue;
			}
			$records = self::records($this->type->storage()->persisted($copy, $field));
			foreach($records as $record) {
				$meta = $record['meta'];
				$path = $copy->filesManager()->path() . $meta['basename'];
				if(!is_file($path) || filesize($path) !== (int) $meta['filesize']) {
					$actualSize = is_file($path) ? (string) filesize($path) : $this->_('missing');
					$message = $this->_('Page clone %1$d did not copy a complete Flex original (%2$s: expected %3$d, actual %4$s); repair it before use');
					throw new WireException(sprintf($message, $copy->id, $meta['basename'], $meta['filesize'], $actualSize));
				}
			}
			// An empty owner-range UPDATE takes gap locks under REPEATABLE READ,
			// blocking later inserts through the independent staging journal.
			$q = $this->wire()->database->prepare(
				"UPDATE fieldtype_flex_assets SET state='committed',expires=0 " .
				"WHERE asset_id=:id AND pages_id=:page AND fields_id=:field AND state='cloning'"
			);
			foreach($records as $record) {
				$id = $record['meta']['id'];
				$q->execute([
					'id'    => $id,
					'page'  => $copy->id,
					'field' => $field->id,
				]);
				unset($this->manifest[$id]);
			}
			if(!$this->wire()->database->inTransaction()) {
				$this->sweep($copy, $field);
			}
		}
	}

	/**
	 * Repair incomplete native file copying and finalize the saved clone’s ownership.
	 *
	 * @param Page $copy
	 * @return int
	 */
	public function repairClone(Page $copy): int {
		if(!$copy->id || $this->wire()->database->inTransaction()) {
			throw new WireException($this->_('Repair a saved Flex clone outside a transaction'));
		}
		$q = $this->wire()->database->prepare("SELECT * FROM fieldtype_flex_assets WHERE pages_id=:page AND state='cloning'");
		$q->execute(['page' => $copy->id]);
		$repaired = 0;
		$source = null;
		foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $record) {
			$data = json_decode($record['data'], true, 64, JSON_THROW_ON_ERROR);
			$source = $this->wire()->pages->get((int) ($data['source_page'] ?? 0));
			$owned = $source->id ? $this->assetByName($source, $record['basename']) : null;
			if(!$owned || (int) $owned['fields_id'] !== (int) $record['fields_id']) {
				throw new WireException($this->_('Clone source ownership is no longer available'));
			}
			$target = $copy->filesManager()->path() . $record['basename'];
			if(!is_file($target) || filesize($target) !== (int) ($data['meta']['filesize'] ?? 0)) {
				$path = $source->filesManager()->path() . $record['basename'];
				if(!is_file($path) || !copy($path, $target)) {
					throw new WireException($this->_('Unable to repair cloned original'));
				}
				$this->wire()->files->chmod($target);
				++$repaired;
			}
		}
		if($source) {
			$this->cloned($source, $copy);
		}
		return $repaired;
	}

	/**
	 * Internal best-effort lifecycle work, including requests that never save Flex.
	 * Open caller transactions are left to a later finished event/request.
	 */
	public function sweep(?Page $owner = null, ?Field $field = null): void {
		try {
			if($this->wire()->database->inTransaction()) return;
			if(!$this->wire()->database->tableExists('fieldtype_flex_assets')) return;
			$result = $this->collect(100, $owner, $field);
			foreach($result['failed'] as $id => $message) {
				$this->wire()->log->save('flex-media', "$id: $message");
			}
		} catch(\Throwable $error) {
			// Cleanup must never turn a committed save or completed response into a failure.
			try {
				$this->wire()->log->save('flex-media', $error->getMessage());
			} catch(\Throwable $ignored) {
			}
		}
	}

	/**
	 * Collect a bounded batch of expired or removed assets outside caller transactions.
	 *
	 * @param int $limit Maximum number of records to process.
	 * @param Page|null $owner
	 * @param Field|null $field Owning Flex field.
	 * @return array
	 */
	public function collect(int $limit = 100, ?Page $owner = null, ?Field $field = null): array {
		$db = $this->wire()->database;
		if($db->inTransaction()) {
			throw new WireException($this->_('Run Flex file cleanup after the outer database transaction commits'));
		}
		if($limit < 1 || $limit > 1000) {
			throw new WireException($this->_('Invalid cleanup limit'));
		}
		$where = "(state='pending_delete' OR (state IN ('staged','uploading','cloning') AND expires>0 AND expires<:now) OR (state='committed' AND data LIKE '%\"cleanupVariations\":true%'))";
		$bind = ['now' => time()];
		if($owner) {
			$where .= ' AND pages_id=:page';
			$bind['page'] = $owner->id;
		}
		if($field) {
			$where .= ' AND fields_id=:field';
			$bind['field'] = $field->id;
		}
		// A durable keyset cursor advances past failed/protected records as well as
		// deletions. Scoped eager cleanup must not move the global request cursor.
		$global = !$owner && !$field;
		$cursor = $global ? (string) $this->wire()->cache->get('FlexMedia.collectCursor') : '';
		if(!preg_match('/^[a-f0-9]{32}$/D', $cursor)) $cursor = '';
		$select = "SELECT asset_id FROM fieldtype_flex_assets WHERE $where AND asset_id>:cursor ORDER BY asset_id LIMIT $limit";
		$bind['cursor'] = $cursor;
		$q = $db->prepare($select);
		$q->execute($bind);
		$ids = $q->fetchAll(\PDO::FETCH_COLUMN);
		if($global && !$ids && $cursor !== '') {
			$bind['cursor'] = '';
			$q->execute($bind);
			$ids = $q->fetchAll(\PDO::FETCH_COLUMN);
		}
		if($global) {
			$this->wire()->cache->save('FlexMedia.collectCursor', $ids ? end($ids) : '', WireCache::expireNever);
		}
		$result = [
			'removed'    => 0,
			'variations' => 0,
			'failed'     => [],
		];
		foreach($ids as $id) {
			try {
				$outcome = $this->type->storage()->atomic(function() use ($id, $db) {
					$record = $this->asset($id, true);
					if(!$record) {
						return '';
					}
					$page = $this->wire()->pages->get((int) $record['pages_id']);
					$field = $this->wire()->fields->get((int) $record['fields_id']);
					$raw = [];
					if($page->id && $field && $field->type instanceof FieldtypeFlex) {
						if($db->tableExists($field->table)) {
							$raw = $this->type->storage()->persisted($page, $field, true);
						}
					}
					$lock = $db->prepare('SELECT * FROM fieldtype_flex_assets WHERE asset_id=:id FOR UPDATE');
					$lock->execute(['id' => $id]);
					$record = $lock->fetch(\PDO::FETCH_ASSOC);
					if(!$record) {
						return '';
					}
					$referenced = false;
					foreach(self::records($raw) as $current) {
						if($current['meta']['id'] === $id || $current['meta']['basename'] === $record['basename']) {
							$referenced = true;
						}
					}
					$data = json_decode($record['data'], true, 64, JSON_THROW_ON_ERROR);
					if(
						$page->id
						&& $this->wire()->modules->isInstalled('PagesVersions')
						&& $this->wire()->modules->get('PagesVersions')->hasPageVersions($page)
					) {
						return '';
					}
					if($record['state'] === 'committed' && $referenced && !empty($data['cleanupVariations'])) {
						if($page->id) {
							$images = $this->wire(new Pageimages($page));
							$image = $this->wire(new Pageimage($images, $record['basename']));
							$paths = array_filter($image->getFiles(), fn($path) => $path !== $image->filename());
							$image->removeVariations();
							foreach($paths as $path) {
								if(file_exists($path) || is_link($path)) {
									throw new WireException($this->_('Unable to remove owned image variation'));
								}
							}
						}
						unset($data['cleanupVariations']);
						$update = $db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id');
						$update->execute([
							'data' => json_encode($data, JSON_THROW_ON_ERROR),
							'id'   => $id,
						]);
						return 'variations';
					}
					if(
						$referenced
						|| !($record['state'] === 'pending_delete' || (in_array($record['state'], ['staged', 'uploading', 'cloning'], true) && $record['expires'] < time()))
					) {
						return '';
					}
					if($page->id) {
						// Also retain originals referenced by another real native/Flex field.
						foreach($page->fieldgroup as $other) {
							if((int) $other->id === (int) $record['fields_id']) {
								continue;
							}
							if($other->type instanceof FieldtypeFile) {
								$files = $other->type->getPagefiles($page, $other);
								foreach($files as $file) {
									if($file->basename() === $record['basename']) {
										return '';
									}
								}
							} else if($other->type instanceof FieldtypeFlex) {
								foreach(self::records($this->type->storage()->persisted($page, $other)) as $item) {
									if($item['meta']['basename'] === $record['basename']) {
										return '';
									}
								}
							}
						}
						$files = $data['kind'] === 'image' ? $this->wire(new Pageimages($page)) : $this->wire(new Pagefiles($page));
						$class = $data['kind'] === 'image' ? Pageimage::class : Pagefile::class;
						$file = $this->wire(new $class($files, $record['basename']));
						$paths = $file instanceof Pageimage ? $file->getFiles() : [$file->filename()];
						$file->unlink();
						clearstatcache();
						foreach($paths as $path) {
							if(file_exists($path) || is_link($path)) {
								throw new WireException($this->_('Unable to remove owned file or variation'));
							}
						}
					} else if(isset($data['directory'])) {
						// A caller may roll back creation of the owner Page after staging.
						$directory = $data['directory'];
						if(str_contains($directory, '..') || str_starts_with($directory, '/')) {
							throw new WireException($this->_('Invalid staged assets directory'));
						}
						if(PagefilesManager::dirToPageID($directory) !== (int) $record['pages_id']) {
							throw new WireException($this->_('Staged assets directory does not match the recorded owner'));
						}
						$root = realpath($this->wire()->config->paths->files);
						$folder = $this->wire()->config->paths->files . $directory;
						$actualFolder = realpath($folder);
						if($actualFolder && !str_starts_with($actualFolder . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
							throw new WireException($this->_('Staged path escaped page assets'));
						}
						$paths = [$folder . $record['basename']];
						if($actualFolder && $data['kind'] === 'image') {
							// A rolled-back owner has no usable PagefilesManager. Native
							// variation discovery uses only this recorded, guarded folder.
							$images = $this->wire(new class($page, $folder) extends Pageimages {
								private string $folder;

								/**
								 * Bind orphan cleanup to its recorded staging directory.
								 *
								 * @param Page $page Owning page.
								 * @param string $folder
								 */
								public function __construct(Page $page, string $folder) {
									$this->folder = $folder;
									parent::__construct($page);
								}

								/**
								 * Bind the cleanup collection without initializing another asset directory.
								 *
								 * @param Page $page Owning page.
								 * @return void
								 */
								public function setPage(Page $page) {
									$this->page = $page;
								}

								/**
								 * Return the recorded staging directory for orphan cleanup.
								 *
								 * @return string
								 */
								public function path() {
									return $this->folder;
								}

								/**
								 * Return no public URL for the orphan cleanup collection.
								 *
								 * @return string
								 */
								public function url() {
									return '';
								}
							});
							$image = $this->wire(new Pageimage($images, $record['basename']));
							$paths = $image->getFiles();
						}
						foreach($paths as $path) {
							if(is_file($path) && !unlink($path)) {
								throw new WireException($this->_('Unable to remove staged orphan'));
							}
							if(file_exists($path) || is_link($path)) {
								throw new WireException($this->_('Unable to remove staged orphan'));
							}
						}
					}
					$delete = $db->prepare('DELETE FROM fieldtype_flex_assets WHERE asset_id=:id');
					$delete->execute(['id' => $id]);
					unset($this->manifest[$id]);
					return 'removed';
				});
				if($outcome !== '') {
					++$result[$outcome];
				}
			} catch(\Throwable $error) {
				$result['failed'][$id] = $error->getMessage();
			}
		}
		return $result;
	}
}
