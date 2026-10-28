<?php namespace ProcessWire;

require_once __DIR__ . '/src/FlexField.php';
require_once __DIR__ . '/src/FlexFieldDefs.php';
require_once __DIR__ . '/src/FlexValue.php';
require_once __DIR__ . '/src/FlexRows.php';
require_once __DIR__ . '/src/FlexPath.php';
require_once __DIR__ . '/src/FlexDependencies.php';
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
/** Structured ProcessWire values with native inputs, typed rows and owned media. */
class FieldtypeFlex extends Fieldtype implements FieldtypeHasFiles, FieldtypeHasPagefiles, FieldtypeHasPageimages {
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
			'version'  => '0.3.0',
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
		$this->addHookAfter('ProcessWire::finished', function() {
			$this->media()->sweep();
		});
		// Core discards a third sort component before getMatchQuerySort(). Validate
		// its meaning while the original selectors still exist; root collections sort by count.
		$this->addHookBefore('PageFinder::getQuery', $this, 'validateSortPaths');
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
				if($item instanceof FlexRows) {
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
			|| $file->formatted()
			|| $page->of()
			|| $page->get('_version')
		) {
			throw new WireException($this->_('Save an unformatted, owned Flex file on a live page'));
		}
		$allowed = ['description', 'tags', 'filedata', 'modified', 'modified_users_id'];
		foreach($columns as $column) {
			if(!in_array($column, $allowed, true)) {
				throw new WireException($this->_('Unsupported Flex file metadata column'));
			}
		}
		$meta = FlexMedia::describe($file, $file->assetId());
		$own = !$this->wire()->database->inTransaction();
		$this->storage()->requireTransactional($field);
		$committed = $this->storage()->atomic(function() use ($page, $field, $file, $meta, $columns, $allowed) {
			$raw = $this->storage()->persisted($page, $field, true);
			$collection =& FlexPath::rawProperty($raw, $file->pagefiles->address());
			$found = false;
			foreach($collection['items'] ?? [] as $n => $stored) {
				if($stored['id'] === $file->assetId()) {
					if(FlexStorage::revision($stored) !== FlexStorage::revision($file->baseline())) {
						throw new WireException($this->_('Flex file changed; reload before saving metadata'));
					}
					foreach($columns ?: $allowed as $key) {
						$collection['items'][$n][$key] = $meta[$key];
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
			return $collection['items'][$n];
		});
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
			foreach($selector->values() as $value) {
				$parts = explode('.', trim((string) $value, '-+'));
				if(count($parts) < 3) {
					continue;
				}
				$field = $this->wire()->fields->get($parts[0]);
				if(!$field || !$field->type instanceof self) {
					continue;
				}
				$definition = $this->getDefinitions($field)->property($parts[1]);
				if(count($parts) !== 3 || $parts[2] !== 'count' || !$definition || !in_array($definition['kind'], ['rows', 'file', 'image'], true)) {
					throw new PageFinderSyntaxException($this->_('Sort only a Flex collection count; repeated leaf sorting is ambiguous'));
				}
			}
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
		return (new FlexQuery($this))->sort($field, $query, $table, $subfield, $desc);
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
	 * Build field settings, definition diagnostics and the source example catalog.
	 *
	 * @param Field $field Owning Flex field.
	 * @return InputfieldWrapper
	 */
	public function ___getConfigInputfields(Field $field) {
		$wrapper = parent::___getConfigInputfields($field);
		$settings = [
			[
				'InputfieldText',
				'flexDefinitionFile',
				$this->_('Definition file'),
				$this->_('Relative to site/templates. Automatic: flex-fields/NAME.php or .json. Configure exactly one source.'),
			],
			[
				'InputfieldTextarea',
				'flexDefinition',
				$this->_('Inline JSON definition'),
				$this->_('Optional alternative to a definition file. PHP is only loaded from trusted template files.'),
			],
			[
				'InputfieldCheckbox',
				'flexHideWrap',
				$this->_('Hide outer field label'),
				$this->_('Display the child inputs without the Flex heading.'),
			],
		];
		foreach($settings as [$class, $name, $label, $description]) {
			$input = $this->wire()->modules->get($class);
			$input->name = $name;
			$input->label = $label;
			$input->description = $description;
			if($input instanceof InputfieldCheckbox) {
				$default = 0;
				$input->checked($field->get($name) === null ? (bool) $default : (bool) $field->get($name));
				$input->uncheckedValue = 0;
			} else {
				$input->value = $field->get($name) ?? '';
			}
			$wrapper->add($input);
		}
		$preview = $this->wire()->modules->get('InputfieldMarkup');
		$preview->label = $this->_('Definition source and structure');
		$source = FlexFieldDefs::inspectSource($field, $this->wire());
		$escape = fn($text) => $this->wire()->sanitizer->entities((string) $text);
		$sourceOrigin = $source['origin'] === 'automatic'
			? $this->_('Automatic')
			: $this->_('Configured');
		$sourceText = match($source['kind']) {
			'inline-json' => $this->_('Inline JSON is active.'),
			'file'        => sprintf($this->_('%1$s file: %2$s'), $sourceOrigin, $source['relativePath']),
			default       => $this->_('No definition file is active; automatic lookup is allowed.'),
		};
		$previewHtml = '<p><strong>' . $escape($this->_('Active source')) . ':</strong> ' . $escape($sourceText) . '</p>';
		if($source['error']) {
			$previewHtml .= '<p class="notice-error">' . $escape($source['error']) . '</p>';
		}
		$previewHtml .= '<p><strong>' . $escape($this->_('Automatic search paths')) . ':</strong></p><pre>' . $escape(implode("\n", $source['searchPaths'])) . '</pre>';
		try {
			$defs = $this->getDefinitions($field);
			$lines = [];
			$walk = function(array $nodes, int $depth = 0) use (&$walk, &$lines): void {
				foreach($nodes as $node) {
					$kind = match($node['kind']) {
						'fieldset' => $this->_('Fieldset'),
						'rows'     => $this->_('Repeatable set'),
						'row-type' => $this->_('Row type'),
						default    => $node['type'],
					};
					$line = str_repeat('  ', $depth) . sprintf('%s [%s] — %s', $node['label'] ?: $node['name'], $node['name'], $kind);
					if($node['required']) {
						$line .= ' · ' . $this->_('required');
					}
					if($node['useLanguages']) {
						$line .= ' · ' . $this->_('multilingual');
					}
					if($node['showIf'] !== '') {
						$line .= ' · ' . sprintf($this->_('showIf: %s'), $node['showIf']);
					}
					if($node['requiredIf'] !== '') {
						$line .= ' · ' . sprintf($this->_('requiredIf: %s'), $node['requiredIf']);
					}
					if($node['kind'] === 'rows') {
						$line .= ' · ' . sprintf($this->_('min %1$d, max %2$d'), $node['minItems'], $node['maxItems']);
					}
					$lines[] = $line;
					$walk($node['children'], $depth + 1);
				}
			};
			$walk($defs->structure());
			if(!$lines) {
				$lines[] = $this->_('No properties yet. See the module examples directory.');
			}
			$previewHtml .= '<p><strong>' . $escape($this->_('Structure in the NullPage context')) . ':</strong></p><pre>' . $escape(implode("\n", $lines)) . '</pre>';
		} catch(FlexDefinitionException $e) {
			$this->logDefinitionError($this->wire()->pages->newNullPage(), $field, $e);
			$previewHtml .= '<p class="notice-error">' . $escape($e->getMessage()) . '</p>';
			$causes = [];
			for($cause = $e->getPrevious(); $cause; $cause = $cause->getPrevious()) {
				$causes[] = get_class($cause) . ': ' . $cause->getMessage();
			}
			if($causes) {
				$previewHtml .= '<details><summary>' . $escape($this->_('Technical cause')) . '</summary><ul>';
				foreach($causes as $cause) {
					$previewHtml .= '<li>' . $escape($cause) . '</li>';
				}
				$previewHtml .= '</ul></details>';
			}
		}
		$preview->value = $previewHtml;
		$wrapper->add($preview);

		$catalog = $this->exampleCatalogHtml($escape);
		if($catalog !== '') {
			$examples = $this->wire()->modules->get('InputfieldMarkup');
			$examples->label = $this->_('Example catalog');
			$examples->description = $this->_('Browse and copy source files. Viewing examples does not execute or install them.');
			$examples->value = $catalog;
			$wrapper->add($examples);
		}
		(new FlexMaintenanceAdmin($this))->addInput($wrapper, $field);
		return $wrapper;
	}

	/** Render source from the fixed examples manifest as escaped, non-executable text. */
	private function exampleCatalogHtml(callable $escape): string {
		$root = realpath(__DIR__ . '/examples');
		if(!$root) {
			return '';
		}
		$manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.php';
		if(!is_file($manifestPath)) {
			return '';
		}
		try {
			// This trusted file contains only catalog data and translatable strings;
			// example definitions remain display-only and are never included here.
			$manifest = (static function(string $path) {
				return include $path;
			})($manifestPath);
		} catch(\Throwable $e) {
			$this->wire()->log->save('errors', 'FieldtypeFlex example manifest: ' . $e->getMessage());
			return '';
		}
		if(!is_array($manifest['entries'] ?? null)) {
			return '';
		}
		$html = '';
		foreach($manifest['entries'] as $entry) {
			if(!is_array($entry) || !in_array($entry['kind'] ?? '', ['definition', 'helper'], true)) {
				continue;
			}
			$category = $entry['category'] ?? null;
			$title = $entry['title'] ?? null;
			$description = $entry['description'] ?? '';
			if(!is_string($category) || !is_string($title) || !is_string($description)) {
				continue;
			}
			$relative = $entry['file'] ?? '';
			if(!is_string($relative) || $relative === '' || basename($relative) !== $relative) {
				continue;
			}
			$path = realpath($root . DIRECTORY_SEPARATOR . $relative);
			if(!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
				continue;
			}
			$source = file_get_contents($path);
			if($source === false) {
				continue;
			}
			$copies = array_values(array_filter($entry['copyFiles'] ?? [], fn($file) => is_string($file) && basename($file) === $file));
			$requires = array_values(array_filter($entry['requires'] ?? [], 'is_string'));
			$html .= '<details class="FieldtypeFlex-example"><summary>' . $escape($title) . '</summary>';
			$html .= '<p><strong>' . $escape($this->_('Category')) . ':</strong> ' . $escape($category) . '</p>';
			if($description !== '') {
				$html .= '<p>' . $escape($description) . '</p>';
			}
			if($copies) {
				$html .= '<p><strong>' . $escape($this->_('Copy beside this definition')) . ':</strong> <code>' . $escape(implode(', ', $copies)) . '</code></p>';
			}
			if($requires) {
				$html .= '<p><strong>' . $escape($this->_('Optional modules')) . ':</strong> ' . $escape(implode(', ', $requires)) . '</p>';
			}
			if(($entry['kind'] ?? '') === 'helper') {
				$html .= '<p>' . $escape($this->_('Helper file only; this is not a field definition.')) . '</p>';
			}
			$html .= '<pre><code>' . $escape($source) . '</code></pre></details>';
		}
		return $html;
	}
}
