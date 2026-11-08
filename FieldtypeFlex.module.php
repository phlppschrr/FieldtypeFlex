<?php namespace ProcessWire;

require_once __DIR__ . '/src/FlexField.php';
require_once __DIR__ . '/src/FlexFieldDefs.php';
require_once __DIR__ . '/src/FlexPhpDoc.php';
require_once __DIR__ . '/src/FlexValue.php';
require_once __DIR__ . '/src/FlexMarkup.php';
require_once __DIR__ . '/src/FlexRows.php';
require_once __DIR__ . '/src/FlexPath.php';
require_once __DIR__ . '/src/FlexDependencies.php';
require_once __DIR__ . '/src/FlexLazy.php';
require_once __DIR__ . '/src/FlexStorage.php';
require_once __DIR__ . '/src/FlexMaintenance.php';
require_once __DIR__ . '/src/FlexMaintenanceCli.php';
require_once __DIR__ . '/src/FlexInspection.php';
require_once __DIR__ . '/src/FlexMaintenanceAdmin.php';
require_once __DIR__ . '/src/FlexQuery.php';
require_once __DIR__ . '/src/FlexAdmin.php';
require_once __DIR__ . '/InputfieldFlexRows.module.php';
require_once __DIR__ . '/src/FlexMedia.php';

// Native CLI also discovers metadata/methods without an implements declaration.
// This is the upstream-recommended compatibility path for cores without CliModule.
/**
 * Structured ProcessWire values with native inputs, typed rows and owned media.
 * @method string getPhpDoc(Field $field) Generate IDE stubs in NullPage context.
 */
class FieldtypeFlex extends Fieldtype implements ConfigurableModule, FieldtypeHasFiles, FieldtypeHasPagefiles, FieldtypeHasPageimages {
	/** Full sort paths carried past core’s two-part field parsing. */
	private array $flexSortPaths = [];
	/** Existing rows start collapsed; newly added rows start open. */
	public const ROW_COLLAPSE_EXISTING = 0;
	/** Existing and newly added rows start collapsed. */
	public const ROW_COLLAPSE_ALL = 1;
	/** Existing and newly added rows start open. */
	public const ROW_EXPAND_ALL = 3;

	private ?FlexMedia $mediaService = null;
	private array $adminDefinitionErrors = [];
	private array $adminInputErrors = [];
	private array $adminFormErrors = [];

	/**
	 * Build native module settings; field-specific file paths retain their base.
	 * @return InputfieldWrapper
	 */
	public function getModuleConfigInputfields() {
		$wrapper = $this->wire(new InputfieldWrapper());
		$input = $this->wire()->modules->get('InputfieldText');
		$input->name = 'definitionsPath';
		$input->label = $this->_('Definitions directory');
		$input->description = $this->_('Relative to site/templates. Automatic definitions use FIELD.php or FIELD.json in this directory.');
		$input->notes = $this->_('Make definitions available at the new location before changing this setting. Explicit field paths and inline JSON are unaffected.');
		$previous = $this->wire()->modules->getConfig($this, 'definitionsPath') ?? '';
		$input->value = $previous;
		try {
			$directory = FlexFieldDefs::definitionDirectory($this->wire());
			$input->value = $directory;
			$input->notes .= "\n" . sprintf($this->_('Current directory: %s'), $this->wire()->config->urls->templates . $directory);
			if(!is_dir($this->wire()->config->paths->templates . $directory)) {
				$input->notes .= "\n" . $this->_('This directory does not exist yet. No directory or files will be created.');
			}
		} catch(WireException $error) {
			$input->error($error->getMessage());
		}
		$input->notes .= "\n[" . $this->_('Documentation and examples') . '](https://github.com/phlppschrr/FieldtypeFlex/blob/main/README.md)';
		$input->addHookAfter('processInput', function(HookEvent $event) use ($previous): void {
			try {
				$event->object->value = FlexFieldDefs::definitionDirectory($this->wire(), $event->object->value);
			} catch(WireException $error) {
				// ProcessModule saves even when an Inputfield reports errors.
				$event->object->value = $previous;
				$event->object->error($error->getMessage());
			}
		});
		$wrapper->add($input);
		return $wrapper;
	}

	/** Preview declared renames in an ID-ordered batch. See API.md for the result contract. */
	public function checkRenames(Field $field, int $afterPageId = 0, int $limit = 100): array {
		return (new FlexMaintenance($this))->scan($field, 'rename', $afterPageId, $limit);
	}

	/** Apply exactly one checked batch; stale data or schema decisions fail per page. */
	public function applyRenames(Field $field, array $preview): array {
		return (new FlexMaintenance($this))->apply($field, 'rename', [], $preview);
	}

	/** List potentially orphaned properties, with bounded page IDs and concrete addresses. */
	public function listOrphans(Field $field, int $afterPageId = 0, int $limit = 100): array {
		return (new FlexMaintenance($this))->scan($field, 'orphans', $afterPageId, $limit);
	}

	/** Preview or apply an explicit selection from a checked orphan batch. */
	public function cleanupProperties(Field $field, array $paths, array $preview, bool $apply = false): array {
		return (new FlexMaintenance($this))->apply($field, 'cleanup', $paths, $preview, $apply);
	}

	/** Native ProcessWire CLI help. */
	public function getCliCommands() {
		return FlexMaintenanceCli::help();
	}

	/** Execute native CLI commands, explicitly propagating failures past core's exception handler. */
	public function executeCli(array $args) {
		$status = (new FlexMaintenanceCli($this))->run($args);
		if($status) exit($status);
	}

	/**
	 * Return module metadata and installation requirements.
	 *
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo() {
		return [
			'title'    => __('Flex'),
			'version'  => '0.6.1',
			'summary'  => __('PHP/JSON-defined compound fields with native inputs and selectors'),
			'author'   => __('FieldtypeFlex contributors'),
			'href'     => 'https://github.com/phlppschrr/FieldtypeFlex',
			'autoload' => true,
			'cli'      => 'flex',
			'requires' => ['ProcessWire>=3.0.259', 'PHP>=8.1.0'],
			'installs' => ['InputfieldFlex', 'InputfieldFlexRows'],
		];
	}

	/**
	 * Install the shared row and media storage tables.
	 *
	 * @return void
	 */
	public function ___install() {
		$this->storage()->install();
	}

	/**
	 * Ensure shared storage exists after a module upgrade.
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 * @return void
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$this->storage()->install();
	}

	/**
	 * Initialize storage and register selector, editor and media hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->addHookAfter('AdminThemeBridge::nativeMap', function(HookEvent $event): void {
			$map = $event->return;
			$map['InputfieldFlexFile'] = ['kind' => 'field', 'widget' => 'InputfieldFile'];
			$map['InputfieldFlexImage'] = ['kind' => 'field', 'widget' => 'InputfieldImage'];
			$event->return = $map;
		});
		$this->addHookAfter('ProcessWire::finished', function() {
			$this->media()->sweep();
		});
		// Core discards a third sort component before getMatchQuerySort(). Validate
		// its meaning while the original selectors still exist; root collections sort by count.
		$this->addHookBefore('PageFinder::getQuery', $this, 'validateSortPaths');
		$this->addHookBefore('PageFinder::getQuery', function(HookEvent $event) {
			(new FlexQuery($this))->prepareRowGroups($event->arguments(0));
		});
		$this->addHookBefore('InputfieldSelector::render', function() {
			$names = [];
			foreach($this->wire()->fields as $field) {
				if($field->type instanceof self) {
					$names[] = $field->name;
				}
			}
			if(!$names) {
				return;
			}
			$this->wire()->config->js('FieldtypeFlexSelectorFields', $names);
			$this->wire()->config->scripts->add($this->wire()->config->urls->get('FieldtypeFlex') . 'FieldtypeFlex.js');
		});
		$this->addHookAfter('ProcessField::buildEditFormActions', function(HookEvent $event) {
			$field = $event->object->getField();
			if($field instanceof Field && $field->type instanceof self && $event->return instanceof InputfieldWrapper) {
				(new FlexMaintenanceAdmin($this))->addInput($event->return, $field);
			}
		});
		$this->addHookBefore('ProcessField::executeEdit', function() {
			(new FlexMaintenanceAdmin($this))->dispatch();
		});
		$this->addHookBefore('ProcessPageEdit::ajaxSave', $this, 'dispatchAdmin');
		$this->addHookAfter('ProcessPageEdit::buildForm', $this, 'addPageEditFormGuard');
		$this->addHookBefore('ProcessPageEdit::processInput', $this, 'checkPageEditFormGuard');
		$this->addHookBefore('Pages::save', $this, 'validatePageTree');
		$this->addHookBefore('Pages::save', $this, 'preventPageEditSaveWithInvalidFlexDefinition');
		$this->addHookBefore('ProcessPageEdit::processSaveRedirect', $this, 'preventPageEditRedirectAfterFlexInputError');
		$this->addHookBefore('ProcessPageEditImageSelect::executeEdit', $this, 'dispatchImage');
		$this->addHookAfter('ProcessPageEditImageSelect::executeEdit', $this, 'decorateImage');
		$this->addHookBefore('ProcessPageEditImageSelect::executeResize', $this, 'dispatchImage');
		$this->addHookBefore('ProcessPageEditImageSelect::executeVariations', $this, 'dispatchImage');
		$this->addHookAfter('ProcessPageEditImageSelect::executeVariations', $this, 'decorateImage');
		$this->addHookAfter('Pages::cloned', function(HookEvent $event) {
			$this->media()->cloned($event->arguments(0), $event->arguments(1));
		});
	}

	/** Detect Flex schemas without hydrating deferred native Repeater items. */
	private function pageHasFlex(Page $page): bool {
		$seen = [];
		$check = function(Field $field) use (&$check, &$seen): bool {
			if(isset($seen[$field->id])) {
				return false;
			}
			$seen[$field->id] = true;
			if($field->type instanceof self) {
				return true;
			}
			if($field->type instanceof FieldtypeRepeater) {
				foreach((array) $field->repeaterFields as $id) {
					$child = $this->wire()->fields->get((int) $id);
					if($child && $check($child)) {
						return true;
					}
				}
			}
			return false;
		};
		foreach($page->fieldgroup as $field) {
			if($check($field)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append a whole-form sentinel after all PageEdit tabs, including deferred Repeaters.
	 *
	 * @param HookEvent $event ProcessPageEdit::buildForm event.
	 * @return void
	 */
	public function addPageEditFormGuard(HookEvent $event): void {
		$page = $event->object->getPage();
		$form = $event->return;
		if(!$page instanceof Page || !$form instanceof InputfieldForm || !$this->pageHasFlex($page)) {
			return;
		}
		$marker = $this->wire()->modules->get('InputfieldHidden');
		$marker->name = '_flex_complete';
		$marker->value = '1';
		$form->add($marker);
		$form->attr('data-flex-max-input-vars', (int) ini_get('max_input_vars'));
	}

	/**
	 * Stop a truncated PageEdit POST before any native field is processed or saved.
	 *
	 * @param HookEvent $event ProcessPageEdit::processInput event.
	 * @return void
	 */
	public function checkPageEditFormGuard(HookEvent $event): void {
		if((int) $event->arguments(1) !== 0) {
			return;
		}
		$page = $event->object->getPage();
		if(!$page instanceof Page || !$this->pageHasFlex($page) || $this->wire()->input->post('_flex_complete') === '1') {
			return;
		}
		$message = $this->_('The page form is incomplete. No changes were saved. Check PHP max_input_vars and post_max_size, then reload the editor.');
		$this->adminFormErrors[$page->id] = $message;
		$event->arguments(0)->error($message);
		$event->object->error($message);
		$event->replace = true;
		$event->return = null;
	}

	/**
	 * Reject excessive Flex trees before native full-page saving can catch a field error.
	 * Includes actual native Repeater owners; no fields or rows are created here.
	 *
	 * @param HookEvent $event Pages::save event.
	 * @return void
	 */
	public function validatePageTree(HookEvent $event): void {
		$page = $event->arguments(0);
		$walk = function(Page $owner) use (&$walk): void {
			foreach($owner->getFields() as $field) {
				if($field->type instanceof self && $owner->isChanged($field->name)) {
					try {
						$this->getDefinitions($field, $owner);
					} catch(FlexDefinitionException $error) {
						if($this->isPageEditRequest()) {
							throw new WireException($error->getMessage(), 0, $error);
						}
						throw $error;
					}
					$value = $owner->getUnformatted($field->name);
					if($value instanceof FlexValue) {
						$value->validateTree();
					}
				} else if($field->type instanceof FieldtypeRepeater) {
					$items = $owner->getUnformatted($field->name);
					if($items instanceof Page) {
						$walk($items);
					} else if(is_iterable($items)) {
						foreach($items as $item) {
							if($item instanceof Page) $walk($item);
						}
					}
				}
			}
		};
		if($page instanceof Page && $page->template) {
			$walk($page);
		}
	}

	/**
	 * Create the editor draft and request service.
	 *
	 * @return FlexAdmin
	 * #pw-internal
	 * @internal
	 */
	public function admin(): FlexAdmin {
		return new FlexAdmin($this);
	}

	/**
	 * Get the request-local media ownership service.
	 *
	 * @return FlexMedia
	 * #pw-internal
	 * @internal
	 */
	public function media(): FlexMedia {
		return $this->mediaService ??= new FlexMedia($this);
	}

	/**
	 * Return no separate metadata template for Flex files.
	 *
	 * @param Field $field Owning Flex field.
	 * @return null
	 */
	public function getFieldsTemplate(Field $field) {
		return null;
	}

	/**
	 * Return native file objects from root properties and repeatable rows.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return Pagefile[]
	 */
	public function getPagefiles(Page $page, Field $field) {
		$files = [];
		$walk = function(FlexValue $value) use (&$walk, &$files): void {
			foreach($value->definitions()->properties() as $name => $definition) {
				$item = $value->getUnformatted($name);
				if($item instanceof FlexValue) {
					$walk($item);
				} else if($item instanceof FlexRows) {
					foreach($item as $row) {
						$walk($row);
					}
				} else if($item instanceof Pagefiles) {
					foreach($item as $file) {
						$files[] = $file;
					}
				}
			}
		};
		$value = $page->getUnformatted($field->name);
		$token = (string) $this->wire()->input->get('flex_draft');
		if($token !== '') {
			$value = $this->admin()->discovery($token, $value);
		}
		$walk($value);
		return $files;
	}

	/**
	 * Return only image objects from the field’s owned files.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return Pageimage[]
	 */
	public function getPageimages(Page $page, Field $field) {
		return array_values(array_filter($this->getPagefiles($page, $field), fn($file) => $file instanceof Pageimage));
	}

	/**
	 * Whether the field contains any files or images.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return bool
	 */
	public function hasFiles(Page $page, Field $field) {
		return count($this->getPagefiles($page, $field)) > 0;
	}

	/**
	 * Get the owning page’s native asset directory.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return string
	 */
	public function getFilesPath(Page $page, Field $field) {
		return $page->filesManager()->path();
	}

	/**
	 * List owned original files and their native image variations.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return string[]
	 */
	public function getFiles(Page $page, Field $field) {
		$paths = [];
		foreach($this->getPagefiles($page, $field) as $file) {
			$paths[] = $file->filename();
			if($file instanceof Pageimage) {
				foreach($file->getFiles() as $path) {
					$paths[] = $path;
				}
			}
		}
		return array_values(array_unique($paths));
	}

	/**
	 * Repair and finalize missing originals on a saved page clone.
	 *
	 * @param Page $copy
	 * @return int
	 */
	public function repairClonedFiles(Page $copy): int {
		return $this->media()->repairClone($copy);
	}

	/**
	 * Save one existing file's metadata without publishing other pending edits.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param Pagefile $file
	 * @param array $columns Metadata columns to save; an empty list saves all supported columns.
	 * @return bool
	 * @throws WireException
	 */
	public function saveFile(Page $page, Field $field, Pagefile $file, array $columns = []) {
		if(
			!($file instanceof FlexPagefile || $file instanceof FlexPageimage)
			|| $file->page !== $page
			|| (int) $file->field->id !== (int) $field->id
			|| ($file instanceof Pageimage && $file->getOriginal() !== null)
			|| $file->formatted()
			|| $page->of()
			|| $page->get('_version')
		) {
			throw new WireException($this->_('Save an unformatted, owned Flex file on a live page'));
		}
		$allowed = ['description', 'tags', 'filedata', 'values', 'modified', 'modified_users_id'];
		foreach($columns as $column) {
			if(!in_array($column, $allowed, true)) {
				throw new WireException($this->_('Unsupported Flex file metadata column'));
			}
		}
		// A retained file can outlive pending removal of its containing Row.
		// Resolve optional root tracking before writing, never after commit.
		$root = $page->getUnformatted($field->name);
		$attached = false;
		if($root instanceof FlexValue) {
			try {
				[$owner, $leaf] = FlexPath::resolve($root, $file->pagefiles->address());
				$attached = $owner->getUnformatted($leaf)->get($file->basename()) === $file;
			} catch(WireException $error) {
				// Unrelated pending tree edits are not part of this file save.
			}
		}
		$meta = FlexMarkup::sleepFile($page, $field, $file->pagefiles->metadataSchema(), FlexMedia::describe($file, $file->assetId()));
		$own = !$this->wire()->database->inTransaction();
		$this->storage()->requireTransactional($field);
		$committed = $this->storage()->atomic(function() use ($page, $field, $file, $meta, $columns, $allowed) {
			$raw = $this->storage()->persisted($page, $field, true);
			$previousRevision = FlexStorage::revision($raw);
			$collection =& FlexPath::rawProperty($raw, $file->pagefiles->address());
			$found = false;
			foreach($collection['items'] ?? [] as $n => $stored) {
				if($stored['id'] === $file->assetId()) {
					if(FlexStorage::revision($stored) !== FlexStorage::revision($file->baseline())) {
						throw new WireException($this->_('Flex file changed; reload before saving metadata'));
					}
					foreach($columns ?: $allowed as $key) {
						if(array_key_exists($key, $meta)) $collection['items'][$n][$key] = $meta[$key];
						else unset($collection['items'][$n][$key]);
					}
					$found = true;
					break;
				}
			}
			if(!$found) {
				throw new WireException($this->_('Save new or replaced files with the containing Flex field'));
			}
			FlexPath::validate($raw, $field);
			$table = $this->wire()->database->escapeTable($field->table);
			$q = $this->wire()->database->prepare("UPDATE `$table` SET data=:data,search=:search WHERE pages_id=:page");
			$q->execute([
				'data'   => FlexStorage::encode($raw),
				'search' => FlexStorage::search($raw),
				'page'   => $page->id,
			]);
			$this->storage()->projectRows($page, $field, $raw);
			$this->media()->sync($page, $field, $raw);
			return ['item' => $collection['items'][$n], 'previous' => $previousRevision, 'revision' => FlexStorage::revision($raw)];
		});
		if($attached && $root->expectedRevision() === $committed['previous']) $root->expectedRevision($committed['revision']);
		$committed = $committed['item'];
		$file->baseline($committed);
		if(FlexStorage::revision($committed) === FlexStorage::revision($meta)) {
			$file->resetTrackChanges();
		}
		if($own) {
			$this->media()->sweep($page, $field);
		}
		return true;
	}

	/**
	 * Handle Flex AJAX requests before the native page editor.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function dispatchAdmin(HookEvent $event): void {
		$this->admin()->ajax($event);
	}

	/**
	 * Route native image editor requests through Flex ownership checks.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function dispatchImage(HookEvent $event): void {
		$this->admin()->image($event);
	}

	/**
	 * Add the Flex draft context to the native image editor form.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function decorateImage(HookEvent $event): void {
		$this->admin()->decorateImage($event);
	}

	/**
	 * Reject ambiguous nested sorts before PageFinder truncates their paths.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function validateSortPaths(HookEvent $event): void {
		foreach($event->arguments(0) as $selector) {
			if($selector->field !== 'sort') {
				continue;
			}
			$sortValues = $selector->values();
			foreach($sortValues as $index => $value) {
				$parts = explode('.', trim((string) $value, '-+'));
				if(count($parts) < 3) {
					continue;
				}
				$field = $this->wire()->fields->get($parts[0]);
				if(!$field || !$field->type instanceof self) {
					continue;
				}
				$schema = $this->getDefinitions($field);
				$fixedParts = array_slice($parts, 1);
				while(($schema->property($fixedParts[0])['kind'] ?? '') === 'object' && count($fixedParts) > 1) {
					$schema = $schema->property(array_shift($fixedParts))['schema'];
				}
				$leaf = $schema->property($fixedParts[0]);
				$scalar = count($fixedParts) === 1 && $leaf && !in_array($leaf['kind'], ['object', 'rows', 'file', 'image'], true) && !$leaf['multiple'];
				$count = count($fixedParts) === 2 && $fixedParts[1] === 'count' && $leaf && in_array($leaf['kind'], ['rows', 'file', 'image'], true);
				if(!$scalar && !$count) {
					throw new PageFinderSyntaxException($this->_('Sort only a Flex collection count; repeated leaf sorting is ambiguous'));
				}
				$path = implode('.', array_slice($parts, 1));
				$alias = '_flexsort_' . hash('sha256', $path);
				$this->flexSortPaths[$alias] = $path;
				$sortValues[$index] = (str_starts_with((string) $value, '-') || str_ends_with((string) $value, '-') ? '-' : '') . $field->name . '.' . $alias;
			}
			$selector->value = count($sortValues) === 1 ? reset($sortValues) : $sortValues;
		}
	}

	/**
	 * Create the canonical persistence and row projection service.
	 *
	 * @return FlexStorage
	 * #pw-internal
	 * @internal
	 */
	public function storage(): FlexStorage {
		return new FlexStorage($this);
	}

	/**
	 * Return the Field subclass providing the definitions API.
	 *
	 * @param array $a
	 * @return string
	 */
	public function getFieldClass(array $a = []) {
		return 'FlexField';
	}

	/**
	 * Evaluate the field definition for a page or the NullPage query context.
	 *
	 * @param Field $field Owning Flex field.
	 * @param Page|null $page Owning page.
	 * @return FlexFieldDefs
	 */
	public function getDefinitions(Field $field, ?Page $page = null): FlexFieldDefs {
		$page = $page ?: $this->wire()->pages->newNullPage();
		$defs = new FlexFieldDefs($field, $page);
		if(($defs->sourceInfo()['kind'] ?? '') === 'missing' && $page->id && $field->id) {
			$defs->assertSourceForValues($this->storage()->persisted($page, $field));
		}
		return $defs;
	}

	/**
	 * Remember errors discovered while building native inputs for this request's admin form.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param FlexDefinitionException $error
	 * @return void
	 */
	public function markAdminDefinitionError(Page $page, Field $field, FlexDefinitionException $error): void {
		if(!$this->isPageEditRequest()) {
			return;
		}
		$key = $page->id ? 'page:' . $page->id : 'object:' . spl_object_id($page);
		$this->adminDefinitionErrors[$key] = [$field, $error];
	}

	/**
	 * Remember a non-definition input error from one Flex editor in this PageEdit request.
	 *
	 * @param Page $page Owning page, including a native Repeater item page.
	 * @param Field $field Owning Flex field.
	 * @param string $message User-facing validation or ownership message.
	 * @return void
	 */
	public function markAdminInputError(Page $page, Field $field, string $message): void {
		if(!$this->isPageEditRequest()) {
			return;
		}
		$pageKey = $page->id ? 'page:' . $page->id : 'object:' . spl_object_id($page);
		$fieldKey = $field->id ? 'field:' . $field->id : 'object:' . spl_object_id($field);
		$this->adminInputErrors[$pageKey][$fieldKey] = [$field, $message];
	}

	/**
	 * Prevent ProcessPageEdit from saving the page after any Flex input error.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function preventPageEditSaveWithInvalidFlexDefinition(HookEvent $event): void {
		if(!$this->isPageEditRequest()) {
			return;
		}
		$page = $event->arguments(0);
		if(!$page instanceof Page) {
			return;
		}
		$invalid = $this->findAdminPageEditInputError($page);
		if(!$invalid) {
			return;
		}
		throw new WireException($invalid['kind'] === 'definition'
			? $invalid['error']->getMessage()
			: $invalid['error']);
	}

	/**
	 * Keep ProcessPageEdit on the submitted form when the Flex save guard rejects it.
	 *
	 * Core catches Pages::save() exceptions and then redirects unconditionally;
	 * replacing only this redirect preserves the processed native inputs in memory.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function preventPageEditRedirectAfterFlexInputError(HookEvent $event): void {
		if(!$this->isPageEditRequest()) {
			return;
		}
		$process = $event->object;
		if(!is_object($process) || !method_exists($process, 'getPage')) {
			return;
		}
		$page = $process->getPage();
		if(!$page instanceof Page || !$this->findAdminPageEditInputError($page)) {
			return;
		}
		$event->replace = true;
		$event->return = null;
	}

	/**
	 * Find the first request-local definition or input error on this page and its native Repeater items.
	 *
	 * @param Page $page
	 * @return array{kind: string, field: Field|null, error: FlexDefinitionException|string}|null
	 */
	private function findAdminPageEditInputError(Page $page): ?array {
		if(isset($this->adminFormErrors[$page->id])) {
			return ['kind' => 'input', 'field' => null, 'error' => $this->adminFormErrors[$page->id]];
		}
		$visited = [];
		$find = function(Page $owner) use (&$find, &$visited): ?array {
			$id = spl_object_id($owner);
			if(isset($visited[$id])) {
				return null;
			}
			$visited[$id] = true;
			$key = $owner->id ? 'page:' . $owner->id : 'object:' . $id;
			if(isset($this->adminDefinitionErrors[$key])) {
				[$field, $error] = $this->adminDefinitionErrors[$key];
				return ['kind' => 'definition', 'field' => $field, 'error' => $error];
			}
			if(!empty($this->adminInputErrors[$key])) {
				$inputErrors = $this->adminInputErrors[$key];
				[$field, $message] = reset($inputErrors);
				return ['kind' => 'input', 'field' => $field, 'error' => $message];
			}
			foreach($owner->template->fieldgroup as $field) {
				if($field->type instanceof self) {
					$value = $owner->getUnformatted($field->name);
					if($value instanceof FlexInvalidValue) {
						return ['kind' => 'definition', 'field' => $field, 'error' => $value->definitionError()];
					}
					continue;
				}
				if($field->type->className() !== 'FieldtypeRepeater') {
					continue;
				}
				$items = $owner->getUnformatted($field->name);
				if(!$items instanceof PageArray) {
					continue;
				}
				foreach($items as $item) {
					if(!$item instanceof Page) {
						continue;
					}
					$invalid = $find($item);
					if($invalid) {
						return $invalid;
					}
				}
			}
			return null;
		};
		return $find($page);
	}

	/**
	 * Whether the active request belongs to ProcessPageEdit.
	 *
	 * @return bool
	 */
	public function isPageEditRequest(): bool {
		$process = $this->wire()->process;
		return $process === 'ProcessPageEdit' || (is_object($process) && method_exists($process, 'className') && $process->className() === 'ProcessPageEdit');
	}

	/**
	 * Classify only known native Inputfield configuration failures during PageEdit.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param \Throwable $error
	 * @return FlexDefinitionException|null
	 */
	public function nativeInputDefinitionError(Page $page, Field $field, \Throwable $error): ?FlexDefinitionException {
		if(!$this->isPageEditRequest()) {
			return null;
		}
		if($error instanceof WireDatabaseException || $error instanceof WireDatabaseQueryException ||
					$error instanceof WirePermissionException || $error instanceof \PDOException) {
			return null;
		}
		if(!$error instanceof WireException && !$error instanceof \TypeError && !$error instanceof \ValueError &&
					!$error instanceof \InvalidArgumentException && !$error instanceof \RuntimeException) {
			return null;
		}
		$path = str_replace('\\', '/', $error->getFile());
		$native = $error instanceof PageFinderSyntaxException
			? $this->isNativePageSelectorConfigurationError($error)
			: (str_contains($path, '/wire/modules/Inputfield/') || str_contains($path, '/wire/core/Inputfield/'));
		if(!$native) {
			$modulesPath = realpath($this->wire()->config->paths->siteModules);
			if($modulesPath && str_starts_with($path, str_replace('\\', '/', $modulesPath) . '/')) {
				$relative = substr($path, strlen(str_replace('\\', '/', $modulesPath)) + 1);
				$module = explode('/', $relative, 2)[0];
				$native = str_starts_with(strtolower($module), 'inputfield');
			}
		}
		if(!$native) {
			return null;
		}
		return new FlexDefinitionException(
			$field->name,
			$error->getMessage(),
			FlexFieldDefs::inspectSource($field, $this->wire()),
			null,
			$error
		);
	}

	/** PageFinder moved between core versions; identify this config error by its InputfieldPage call path. */
	private function isNativePageSelectorConfigurationError(PageFinderSyntaxException $error): bool {
		foreach($error->getTrace() as $frame) {
			$framePath = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
			$class = (string) ($frame['class'] ?? '');
			if(str_contains($framePath, '/wire/modules/Inputfield/InputfieldPage/') && str_contains($class, 'InputfieldPage')) {
				return true;
			}
		}
		return false;
	}

	private function invalidValue(Page $page, Field $field, array $raw, FlexDefinitionException $error): FlexInvalidValue {
		$this->logDefinitionError($page, $field, $error);
		return new FlexInvalidValue($page, $field, $raw, $error);
	}

	/**
	 * Log definition provenance and the complete exception cause chain.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param FlexDefinitionException $error
	 * @return void
	 */
	public function logDefinitionError(Page $page, Field $field, FlexDefinitionException $error): void {
		static $logged = [];
		$key = $page->id . ':' . $field->id . ':' . hash('sha256', $error->getMessage());
		if(isset($logged[$key])) {
			return;
		}
		$logged[$key] = true;
		$details = ["FieldtypeFlex definition error on page {$page->id}, field {$field->name} ({$field->id}): {$error->getMessage()}"];
		for($cause = $error->getPrevious(); $cause; $cause = $cause->getPrevious()) {
			$details[] = get_class($cause) . ': ' . $cause->getMessage();
			$causeFile = $cause->getFile();
			$causeLine = $cause->getLine();
			if($causeFile !== '' && $causeLine > 0) {
				$details[] = "Thrown at $causeFile:$causeLine";
			}
			$details[] = $cause->getTraceAsString();
		}
		$this->wire()->log->save('errors', implode("\n", $details));
	}

	/**
	 * Create an empty typed value, retaining an admin error sentinel when needed.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return FlexValue|FlexInvalidValue
	 */
	public function getBlankValue(Page $page, Field $field) {
		try {
			return new FlexValue($page, $field, $this->getDefinitions($field, $page));
		} catch(FlexDefinitionException $e) {
			if(!$this->isPageEditRequest()) {
				throw $e;
			}
			return $this->invalidValue($page, $field, [], $e);
		}
	}

	/**
	 * Normalize an assigned value for this page, field and current definition.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return FlexValue|FlexInvalidValue
	 */
	public function sanitizeValue(Page $page, Field $field, $value) {
		if($value instanceof FlexInvalidValue) {
			if($value->belongsTo($page, $field) && $this->isPageEditRequest()) {
				return $value;
			}
			throw $value->definitionError();
		}
		if($value instanceof FlexValue) {
			if($value->belongsTo($page, $field)) {
				return $value;
			}
			$value = $value->rawArray();
		}
		if($value === '' || $value === null) {
			$value = [];
		}
		if(!is_array($value)) {
			throw new WireException($this->_('Flex page values must be arrays or FlexValue objects'));
		}
		try {
			return new FlexValue($page, $field, $this->getDefinitions($field, $page), $value);
		} catch(FlexDefinitionException $e) {
			if(!$this->isPageEditRequest()) {
				throw $e;
			}
			return $this->invalidValue($page, $field, $value, $e);
		}
	}

	/**
	 * Hydrate stored JSON into a page-owned typed value.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return FlexValue|FlexInvalidValue
	 */
	public function ___wakeupValue(Page $page, Field $field, $value) {
		if(is_array($value) && array_key_exists('data', $value)) {
			$value = $value['data'];
		}
		if(is_string($value) && $value !== '') {
			try {
				$value = json_decode($value, true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
			} catch(\JsonException $e) {
				throw new WireException(sprintf($this->_('Corrupt Flex JSON in %1$s on page %2$d'), $field->name, $page->id), 0, $e);
			}
			if(!is_array($value)) {
				throw new WireException($this->_('Stored Flex data must be a JSON object'));
			}
		}
		return $this->sanitizeValue($page, $field, $value);
	}

	/**
	 * Prepare canonical JSON after applying current definitions and media ownership.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return array{data: string, search: string}
	 */
	public function ___sleepValue(Page $page, Field $field, $value) {
		if($value instanceof FlexInvalidValue) {
			throw $value->definitionError();
		}
		$value = $this->sanitizeValue($page, $field, $value);
		// Re-evaluate at save time so removed properties/renames reflect the current schema.
		$current = new FlexValue($page, $field, $this->getDefinitions($field, $page), $value->rawArray());
		$raw = $current->rawArray();
		$current->validateTree();
		$raw = FlexMarkup::sleep($page, $field, $current->definitions(), $raw);
		FlexPath::validate($raw, $field);
		return [
			'data'   => FlexStorage::encode($raw),
			'search' => FlexStorage::search($raw),
		];
	}

	/**
	 * Enable output formatting without changing persisted raw values.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return FlexValue|FlexInvalidValue
	 */
	public function ___formatValue(Page $page, Field $field, $value) {
		$sanitized = $this->sanitizeValue($page, $field, $value);
		if($sanitized instanceof FlexInvalidValue) {
			return $sanitized;
		}
		$copy = clone $sanitized;
		$copy->of(true);
		return $copy;
	}

	/**
	 * Keep an explicit field row even when its compound value is empty.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return bool
	 */
	public function isDeleteValue(Page $page, Field $field, $value) {
		return false;
	}

	/**
	 * Save canonical values, row projections and media ownership atomically.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return bool
	 * @throws WireException
	 */
	public function ___savePageField(Page $page, Field $field) {
		$storedValue = $page->getUnformatted($field->name);
		if($storedValue instanceof FlexInvalidValue) {
			throw $storedValue->definitionError();
		}
		// Recheck the source even when a previously hydrated value is still cached.
		$this->getDefinitions($field, $page);
		if(!$page->isChanged($field->name)) {
			return true;
		}
		if($page->get('_version')) {
			throw new WireException($this->_('Saving Flex page versions is not supported'));
		}
		$store = $this->storage();
		$store->requireTransactional($field);
		$own = !$this->wire()->database->inTransaction();
		$value = $this->sanitizeValue($page, $field, $page->getUnformatted($field->name));
		// Page::__clone() sanitizes while it still has the source ID. Rebind
		// once the native clone has its final ID, before preparing ownership.
		if($page->get('_cloning') instanceof Page) {
			$value = new FlexValue($page, $field, $this->getDefinitions($field, $page), $value->rawArray());
		}
		$page->set($field->name, $value);
		$value->validateTree();
		$this->media()->prepare($value);
		$committed = [];
		$result = $store->atomic(function() use ($page, $field, $store, $value, &$committed) {
			$persisted = $store->persisted($page, $field, true);
			if($value->expectedRevision() !== '' && $value->expectedRevision() !== FlexStorage::revision($persisted)) {
				throw new WireException($this->_('This Flex field changed before the save completed. Reload before saving.'));
			}
			$result = parent::___savePageField($page, $field);
			if(!$result) {
				throw new WireException($this->_('Unable to save canonical Flex value'));
			}
			$raw = $store->persisted($page, $field);
			// Stable identity includes its type, even when callers replace a raw
			// envelope rather than using the immutable FlexRow API.
			$previous = [];
			foreach(FlexPath::rows($persisted) as $entry) {
				$previous[$entry['address']] = $entry['row']['type'];
			}
			foreach(FlexPath::rows($raw) as $entry) {
				if(isset($previous[$entry['address']]) && $previous[$entry['address']] !== $entry['row']['type']) {
					throw new WireException($this->_('An existing Flex row cannot change type'));
				}
			}
			$store->projectRows($page, $field, $raw);
			$this->media()->sync($page, $field, $raw);
			// Remember installation metadata only on writes, for reads after LanguageSupport removal.
			$languages = $this->wire()->languages;
			if($languages) {
				$defaultId = (int) $languages->getDefault()->id;
				$modules = $this->wire()->modules;
				if($defaultId !== (int) $modules->getConfig($this, 'defaultLanguageId')) {
					$modules->saveConfig($this, 'defaultLanguageId', $defaultId);
				}
			}
			$committed = $raw;
			$value->expectedRevision(FlexStorage::revision($raw));
			return true;
		});
		$this->media()->baselines($value, $committed);
		if($own && !$page->get('_cloning')) {
			$this->media()->sweep($page, $field);
		}
		return $result;
	}

	/**
	 * Delete a page’s canonical field row and schedule owned media cleanup.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return bool
	 */
	public function ___deletePageField(Page $page, Field $field) {
		// Keep a native override so template-wide deletion uses the per-page path.
		return parent::___deletePageField($page, $field);
	}

	/** Keep both native deletePageField() and emptyPageField() transactional. */
	protected function emptyPageFieldTable(Page $page, Field $field) {
		$this->storage()->requireRowSchema();
		$own = !$this->wire()->database->inTransaction();
		$result = $this->storage()->atomic(function() use ($page, $field) {
			$result = parent::emptyPageFieldTable($page, $field);
			if(!$result) return false;
			$this->storage()->deleteProjection($page, $field);
			$this->media()->sync($page, $field, []);
			return $result;
		});
		if($own) {
			$this->media()->sweep($page, $field);
		}
		return $result;
	}

	/**
	 * Delete the field storage and release its row and media records.
	 *
	 * @param Field $field Owning Flex field.
	 * @return bool
	 */
	public function ___deleteField(Field $field) {
		$this->storage()->requireRowSchema();
		$result = parent::___deleteField($field);
		if($result) {
			$q = $this->wire()->database->prepare('DELETE FROM fieldtype_flex_rows WHERE fields_id=:id');
			$q->execute(['id' => $field->id]);
			$q = $this->wire()->database->prepare("UPDATE fieldtype_flex_assets SET state='pending_delete' WHERE fields_id=:id");
			$q->execute(['id' => $field->id]);
			$this->media()->sweep(null, $field);
		}
		return $result;
	}

	/**
	 * Refuse module removal while saved Flex fields still exist.
	 *
	 * @return void
	 */
	public function ___uninstall() {
		foreach($this->wire()->fields as $field) {
			if($field->type instanceof self) {
				throw new WireException($this->_('Remove saved Flex fields before uninstalling FieldtypeFlex'));
			}
		}
	}

	/**
	 * Check logical user content rather than row or media envelope metadata.
	 *
	 * @param Field $field Owning Flex field.
	 * @param mixed $value
	 * @return bool
	 */
	public function isEmptyValue(Field $field, $value) {
		if($value instanceof Selector) {
			return true;
		}
		if($value instanceof FlexInvalidValue) {
			return false;
		}
		if($value instanceof FlexValue) {
			$hasRawValue = static function($v) use (&$hasRawValue): bool {
				if(is_array($v) && ($v['_flex'] ?? '') === FlexStorage::ROWS_FORMAT) {
					foreach($v['items'] as $row) {
						if($hasRawValue($row['values'])) {
							return true;
						}
					}
					return false;
				}
				if(is_array($v)) {
					foreach($v as $item) {
						if($hasRawValue($item)) {
							return true;
						}
					}
					return false;
				}
				return $v !== '' && $v !== null && $v !== false;
			};
			foreach($value->rawArray() as $name => $v) {
				$def = $value->definitions()->property($name);
				if($def) {
					if($value->hasValue($name)) {
						return false;
					}
				} else if($hasRawValue($v)) {
					return false;
				}
			}
			return true;
		}
		return $value === '' || $value === null;
	}

	/**
	 * Build the native Flex editor for a page and field.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return InputfieldFlex
	 */
	public function getInputfield(Page $page, Field $field) {
		$input = $this->wire()->modules->get('InputfieldFlex');
		$input->hasPage = $page;
		$input->hasField = $field;
		if($field->get('flexHideWrap')) {
			$input->skipLabel = Inputfield::skipLabelHeader;
		}
		return $input;
	}

	/**
	 * Describe canonical JSON storage on MySQL or MariaDB.
	 *
	 * @param Field $field Owning Flex field.
	 * @return array
	 */
	public function getDatabaseSchema(Field $field) {
		if($this->wire()->config->dbType && $this->wire()->config->dbType !== 'mysql') {
			throw new WireException($this->_('FieldtypeFlex currently requires MySQL or MariaDB'));
		}
		$schema = parent::getDatabaseSchema($field);
		$schema['xtra']['append'] = 'ENGINE=InnoDB DEFAULT CHARSET=' . $this->wire()->config->dbCharset;
		$schema['xtra']['all'] = false;
		$schema['data'] = 'JSON NOT NULL';
		$schema['search'] = 'LONGTEXT NOT NULL';
		unset($schema['keys']['data']);
		return $schema;
	}

	/**
	 * Add a parameterized Flex property predicate to a PageFinder query.
	 *
	 * @param DatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param string $operator
	 * @param mixed $value
	 * @return DatabaseQuerySelect
	 */
	public function getMatchQuery($query, $table, $subfield, $operator, $value) {
		return (new FlexQuery($this))->match($query, $table, $subfield, $operator, $value);
	}

	/**
	 * Build a validated scalar or collection-count sort expression.
	 *
	 * @param Field $field Owning Flex field.
	 * @param DatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param bool $desc
	 * @return string
	 */
	public function getMatchQuerySort(Field $field, $query, $table, $subfield, $desc) {
		return (new FlexQuery($this))->sort($field, $query, $table, $this->flexSortPaths[$subfield] ?? $subfield, $desc);
	}

	/**
	 * Expose queryable property metadata to native selector discovery.
	 *
	 * @param Field $field Owning Flex field.
	 * @param array $data
	 * @return array
	 */
	public function ___getSelectorInfo(Field $field, array $data = []) {
		return (new FlexQuery($this))->info($field, $data);
	}

	/**
	 * Allow only Flex when selecting compatible field types.
	 *
	 * @param Field $field Owning Flex field.
	 * @return Fieldtypes
	 */
	public function ___getCompatibleFieldtypes(Field $field) {
		$types = $this->wire(new Fieldtypes());
		$types->add($this);
		return $types; // A JSON value must not be silently converted to a scalar field.
	}

	/**
	 * Generate IDE-only PHPDoc class stubs using the field's NullPage definitions.
	 *
	 * Hook after this method to customize the returned PHP or save it to a helper file.
	 * Generated classes must not be loaded at runtime.
	 *
	 * @param Field $field Owning Flex field.
	 * @return string Complete PHP helper source, including the opening tag and namespace.
	 */
	public function ___getPhpDoc(Field $field): string {
		return FlexPhpDoc::render($field, $this->getDefinitions($field));
	}

	/**
	 * Build native field settings with a source summary and optional PHPDoc stubs.
	 * @param Field $field Owning Flex field.
	 * @return InputfieldWrapper
	 */
	public function ___getConfigInputfields(Field $field) {
		$wrapper = parent::___getConfigInputfields($field);
		$modules = $this->wire()->modules;
		$escape = fn($text) => $this->wire()->sanitizer->entities((string) $text);
		$this->wire()->config->styles->add($this->wire()->config->urls->get('FieldtypeFlex') . 'FieldtypeFlex.css');
		$source = FlexFieldDefs::inspectSource($field, $this->wire());
		$group = $modules->get('InputfieldFieldset');
		$group->name = 'flexSource';
		$group->label = $this->_('Definition');
		$status = $modules->get('InputfieldMarkup');
		$status->name = 'flexDefinitionStatus';
		$status->label = $this->_('Active source');
		$status->description = $this->_('Source and PHPDoc reflect saved settings, evaluated without a specific page. Page-dependent definitions may differ.');
		$sourceOrigin = $source['origin'] === 'automatic'
			? $this->_('Automatic')
			: $this->_('Configured');
		$sourceText = match($source['kind']) {
			'inline-json' => $this->_('Inline JSON'),
			'file' => $sourceOrigin . ': ' . ($source['relativePath'] ?? ''),
			default => $this->_('No definition yet.'),
		};
		$html = '<p>' . $escape($sourceText) . '</p>';
		$phpdocSource = null;
		try {
			$phpdocSource = $this->getPhpDoc($field);
		} catch(FlexDefinitionException $error) {
			$this->logDefinitionError($this->wire()->pages->newNullPage(), $field, $error);
			$html .= '<p class="notice-error">' . $escape($this->wire()->sanitizer->unentities($error->getMessage())) . '</p>';
			$causes = [];
			for($cause = $error->getPrevious(); $cause; $cause = $cause->getPrevious()) {
				$causes[] = get_class($cause) . ': ' . $cause->getMessage();
			}
			if($causes) {
				$html .= '<details><summary>' . $escape($this->_('Technical cause')) . '</summary><pre>' . $escape(implode("\n", $causes)) . '</pre></details>';
			}
		}
		if($source['origin'] === 'automatic' && ($source['kind'] === 'missing' || $source['error'])) {
			$html .= '<p>' . $escape($this->_('Expected definition files')) . ':</p><pre>' . $escape(implode("\n", $source['searchPaths'])) . '</pre>';
		}
		if($this->wire()->user->isSuperuser()) {
			$html .= '<p><a href="' . $escape($this->wire()->config->urls->admin . 'module/edit?name=FieldtypeFlex') . '">' . $escape($this->_('Configure definitions directory')) . '</a></p>';
		}
		$status->value = $html;
		$group->add($status);
		$file = $modules->get('InputfieldText');
		$file->name = 'flexDefinitionFile';
		$file->label = $this->_('Override definition file');
		$file->description = $this->_('Optional PHP/JSON path relative to site/templates. Leave blank for automatic lookup or inline JSON.');
		$file->value = $field->flexDefinitionFile ?? '';
		$group->add($file);
		$json = $modules->get('InputfieldTextarea');
		$json->name = 'flexDefinition';
		$json->label = $this->_('Inline JSON definition');
		$json->description = $this->_('Alternative to a definition file. Configure exactly one source.');
		$json->value = $field->flexDefinition ?? '';
		$json->rows = 8;
		$json->collapsed = Inputfield::collapsedBlank;
		$group->add($json);
		$wrapper->add($group);

		$hide = $modules->get('InputfieldCheckbox');
		$hide->name = 'flexHideWrap';
		$hide->label = $this->_('Hide outer field label');
		$hide->description = $this->_('Display the child inputs without the Flex heading.');
		$hide->checked((bool) $field->flexHideWrap);
		$hide->uncheckedValue = 0;
		$wrapper->add($hide);
		if($phpdocSource !== null && $source['kind'] !== 'missing') {
			$phpdoc = $modules->get('InputfieldMarkup');
			$phpdoc->name = 'flexPhpDoc';
			$phpdoc->label = 'PHPDoc';
			$phpdoc->description = $this->_('These helpers describe the NullPage definition for your IDE. Keep them out of runtime loading. Types cover formatted and unformatted reads; page-dependent definitions may differ.');
			$phpdoc->collapsed = Inputfield::collapsedYes;
			$rootClass = preg_match('/^class (\w+) extends FlexValue\b/m', $phpdocSource, $match) ? $match[1] : 'FlexValue';
			$variable = '$' . $field->name;
			$readCode = '/** @var ' . $rootClass . ' ' . $variable . ' */' . "\n" . $variable . " = \$page->get('" . $field->name . "');";
			$steps = '<ol><li><p>' . $escape($this->_('Save the following helper classes in a PHP file indexed by your IDE:')) . '</p><pre><code>' . $escape($phpdocSource) . '</code></pre></li>';
			$steps .= '<li><p>' . $escape($this->_('Assign the field value and declare its type for code completion:')) . '</p><pre><code>' . $escape($readCode) . '</code></pre></li>';
			if(preg_match('/^ \* @property \S+ \$(\w+)/m', $phpdocSource, $match)) {
				$steps .= '<li><p>' . $escape($this->_('Use the typed variable to access a defined property:')) . '</p><pre><code>' . $escape('$value = ' . $variable . '->' . $match[1] . ';') . '</code></pre></li>';
			}
			$phpdoc->value = $steps . '</ol>';
			$wrapper->add($phpdoc);
		}
		$help = $modules->get('InputfieldMarkup');
		$help->value = '<p><a href="https://github.com/phlppschrr/FieldtypeFlex/blob/main/README.md">' . $escape($this->_('Documentation and examples')) . '</a></p>';
		$wrapper->add($help);
		return $wrapper;
	}
}
