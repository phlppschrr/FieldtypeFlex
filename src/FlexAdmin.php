<?php namespace ProcessWire;

/** Server-owned editor drafts and narrowly scoped admin requests. * @internal
 */
class FlexAdmin extends Wire {
	private const DRAFT_FORMAT = 1;
	private FieldtypeFlex $type;

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

	/**
	 * Open or validate an authenticated editor draft for one field input.
	 *
	 * @param FlexValue $value
	 * @param string $inputName
	 * @param string|null $token
	 * @return string
	 */
	public function open(FlexValue $value, string $inputName, ?string $token = null): string {
		if($token) {
			$draft = $this->draft($token);
			if($draft['page'] !== (int) $value->ownerPage()->id || $draft['field'] !== (int) $value->ownerField()->id || $draft['input'] !== $inputName) {
				throw new WirePermissionException($this->_('Invalid Flex editor context'));
			}
			return $token;
		}
		$token = bin2hex(random_bytes(32));
		$raw = $value->ownerPage()->id && $value->ownerField()->id ? $this->type->storage()->persisted($value->ownerPage(), $value->ownerField()) : [];
		$this->put($token, [
			'format'  => self::DRAFT_FORMAT,
			'page'    => (int) $value->ownerPage()->id,
			'field'   => (int) $value->ownerField()->id,
			'input'   => $inputName,
			'user'    => (int) $this->wire()->user->id,
			'expires' => time() + 21600,
			'hash'    => FlexStorage::revision($raw),
			'rows'    => [],
			'media'   => [],
			'orders'  => [],
		]);
		return $token;
	}

	/**
	 * Load a current draft belonging to the authenticated user.
	 *
	 * @param string $token
	 * @return array
	 */
	public function draft(string $token): array {
		if(!preg_match('/^[a-f0-9]{64}$/D', $token)) {
			throw new WirePermissionException($this->_('Invalid Flex draft token'));
		}
		$drafts = $this->wire()->session->getFor('FieldtypeFlex', 'drafts') ?: [];
		$draft = $drafts[$token] ?? null;
		if(!$draft || ($draft['format'] ?? 0) !== self::DRAFT_FORMAT || $draft['expires'] < time() || $draft['user'] !== (int) $this->wire()->user->id) {
			throw new WirePermissionException($this->_('Flex editor draft expired; reload the editor'));
		}
		return $draft;
	}

	/**
	 * Store authenticated draft state and discard expired session entries.
	 *
	 * @param string $token
	 * @param array $draft Editor draft state, including its expiry timestamp.
	 * @return void
	 */
	public function put(string $token, array $draft): void {
		$drafts = $this->wire()->session->getFor('FieldtypeFlex', 'drafts') ?: [];
		foreach($drafts as $key => $item) {
			if($item['expires'] < time()) {
				unset($drafts[$key]);
			}
		}
		if(count($drafts) >= 512 && !isset($drafts[$token])) {
			array_shift($drafts);
		}
		$drafts[$token] = $draft;
		$this->wire()->session->setFor('FieldtypeFlex', 'drafts', $drafts);
	}

	/**
	 * Reject a draft whose canonical field value changed in another request.
	 *
	 * @param string $token
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @return void
	 * @throws WireException
	 */
	public function assertFresh(string $token, Page $page, Field $field): void {
		$draft = $this->draft($token);
		$raw = $this->type->storage()->persisted($page, $field);
		if($draft['hash'] !== FlexStorage::revision($raw)) {
			throw new WireException($this->_('This Flex field changed in another request. Reload before saving.'));
		}
	}

	/**
	 * Load an existing or registered draft row, optionally overlaying staged media.
	 *
	 * @param string $token
	 * @param string $set Repeatable property name.
	 * @param string $id Stable row or asset identity.
	 * @param FlexValue $value
	 * @param bool $includeMedia Overlay staged media for existing or draft-created rows.
	 * @return FlexRow
	 */
	public function row(string $token, string $set, string $id, FlexValue $value, bool $includeMedia = true): FlexRow {
		$draft = $this->draft($token);
		if($draft['page'] !== (int) $value->ownerPage()->id || $draft['field'] !== (int) $value->ownerField()->id) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		[$parent, $name, $definition] = $this->collection($token, $set, $value);
		return $this->loadRow($token, $set, $id, $parent, $name, $includeMedia);
	}

	/** Hydrate one row from an already resolved owner, without rewalking its ancestors. */
	private function loadRow(string $token, string $set, string $id, FlexValue $parent, string $name, bool $includeMedia = true): FlexRow {
		$draft = $this->draft($token);
		$row = $parent->getUnformatted($name)->getById($id);
		$raw = $row ? ['id' => $id, 'type' => $row->rowType(), 'values' => $row->rawArray()] : ($draft['rows'][$set][$id] ?? null);
		if(!is_array($raw) || ($raw['id'] ?? '') !== $id || !is_string($raw['type'] ?? null) || !is_array($raw['values'] ?? null)) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		$schema = $parent->definitions()->rowType($name, $raw['type'])['schema'];
		$copy = new FlexRow($parent->ownerPage(), $parent->ownerField(), $schema, $set, $id, $raw['type'], $raw['values']);
		return $includeMedia ? $this->overlay($token, $copy) : $copy;
	}

	/** Resolve a collection including new, server-registered ancestors. */
	private function collection(string $token, string $set, FlexValue $root, bool $editable = false): array {
		[$parent, $name] = FlexPath::resolve($root, $set, function($address, $id, $owner, $definition) use ($token, $root, $editable) {
			if($editable) {
				$this->editableInput($definition['input']);
			}
			return $this->loadRow($token, $address, $id, $owner, substr($address, strrpos('.' . $address, '.')));
		});
		$definition = $parent->definitions()->property($name);
		if(!$definition || $definition['kind'] !== 'rows') {
			throw new WireException($this->_('Unknown repeatable set'));
		}
		if($editable) {
			$this->editableInput($definition['input']);
		}
		return [$parent, $name, $definition];
	}

	/**
	 * Register a server-created row and prepare its independent draft media.
	 *
	 * @param string $token
	 * @param string $set Repeatable property name.
	 * @param FlexRow $row
	 * @return void
	 */
	public function registerRow(string $token, string $set, FlexRow $row): void {
		$draft = $this->draft($token);
		if($draft['page'] !== (int) $row->ownerPage()->id || $draft['field'] !== (int) $row->ownerField()->id || $row->address() !== "$set.{$row->rowId()}") {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		$before = $draft;
		if(isset($draft['orders'][$set]) && !in_array($row->rowId(), $draft['orders'][$set], true)) {
			$draft['orders'][$set][] = $row->rowId();
		}
		$draft['rows'][$set][$row->rowId()] = [
			'id'     => $row->rowId(),
			'type'   => $row->rowType(),
			'values' => $row->rawArray(),
		];
		$this->put($token, $draft);
		try {
			$root = $row->ownerPage()->getUnformatted($row->ownerField()->name);
			FlexPath::validate($this->discovery($token, $root)->rawArray(), $row->ownerField());
			$this->type->media()->setDraft($row, $token);
			$this->type->media()->prepare($row);
			$draft = $this->draft($token);
			$draft['rows'][$set][$row->rowId()]['values'] = $row->rawArray();
			$this->put($token, $draft);
			foreach($row as $item) {
				if($item instanceof FlexRows) {
					foreach($item as $child) {
						$this->registerRow($token, substr($child->address(), 0, -33), $child);
					}
				}
			}
		} catch(\Throwable $error) {
			$this->put($token, $before);
			throw $error;
		}
	}

	/**
	 * Remove a completed editor draft from the session.
	 *
	 * @param string $token
	 * @return void
	 */
	public function forget(string $token): void {
		$drafts = $this->wire()->session->getFor('FieldtypeFlex', 'drafts') ?: [];
		unset($drafts[$token]);
		$this->wire()->session->setFor('FieldtypeFlex', 'drafts', $drafts);
	}

	/**
	 * Resolve an editable native media input from a scoped draft address.
	 *
	 * @param string $token
	 * @param string $address Root or repeatable property address.
	 * @param FlexValue $value
	 * @return array
	 */
	public function target(string $token, string $address, FlexValue $value): array {
		$draft = $this->draft($token);
		$value = $this->overlay($token, $value);
		[$parent, $leaf] = FlexPath::resolve($value, $address, function($set, $id, $owner, $definition) use ($token, $value) {
			$this->editableInput($definition['input']);
			return $this->loadRow($token, $set, $id, $owner, substr($set, strrpos('.' . $set, '.')));
		});
		$prefix = FlexPath::inputPrefix($draft['input'], $parent->address());
		$definition = $parent->definitions()->property($leaf);
		if(!$definition || !in_array($definition['kind'], ['file', 'image'], true)) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		$files = $parent->getUnformatted($leaf);
		$files->draft($token);
		$input = $parent->definitions()->getInputfields($parent, $prefix, $token, $value)->getChildByName($prefix . $leaf);
		if(!$input instanceof InputfieldFile) {
			throw new WireException($this->_('Unable to create native media input'));
		}
		$input->value = clone $files;
		$input->destinationPath = $files->path();
		return [$parent, $leaf, $input];
	}

	/**
	 * Record staged native file metadata for one draft property.
	 *
	 * @param string $token
	 * @param string $address Root or repeatable property address.
	 * @param Pagefiles $files
	 * @return void
	 */
	public function stageMedia(string $token, string $address, Pagefiles $files): void {
		$draft = $this->draft($token);
		$draft['media'][$address] = $files->rawArray();
		$this->put($token, $draft);
	}

	/**
	 * Create an editor value with registered rows and staged media applied.
	 *
	 * @param string $token
	 * @param FlexValue $value
	 * @return FlexValue
	 */
	public function overlay(string $token, FlexValue $value): FlexValue {
		$draft = $this->draft($token);
		if($draft['page'] !== (int) $value->ownerPage()->id || $draft['field'] !== (int) $value->ownerField()->id) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		$walk = function(array $raw, FlexFieldDefs $defs, string $prefix) use (&$walk, $draft): array {
			foreach($defs->properties() as $name => $definition) {
				$address = $prefix . $name;
				if($definition['kind'] === 'rows') {
					foreach($raw[$name]['items'] ?? [] as $n => $row) {
						$schema = $defs->rowType($name, $row['type'])['schema'];
						$raw[$name]['items'][$n]['values'] = $walk($row['values'], $schema, $address . '.' . $row['id'] . '.');
					}
				} else if(in_array($definition['kind'], ['file', 'image'], true) && array_key_exists($address, $draft['media'])) {
					$raw[$name] = $draft['media'][$address];
				}
			}
			return $raw;
		};
		$raw = $walk($value->rawArray(), $value->definitions(), $value->address() === '' ? '' : $value->address() . '.');
		if($value instanceof FlexRow) {
			return new FlexRow($value->ownerPage(), $value->ownerField(), $value->definitions(), substr($value->address(), 0, -33), $value->rowId(), $value->rowType(), $raw);
		}
		return new FlexValue($value->ownerPage(), $value->ownerField(), $value->definitions(), $raw);
	}

	/**
	 * Expose registered draft descendants and media without modifying the canonical value.
	 *
	 * @param string $token Authenticated draft.
	 * @param FlexValue $value Root value.
	 * @return FlexValue Detached discovery tree.
	 */
	public function discovery(string $token, FlexValue $value): FlexValue {
		$draft = $this->draft($token);
		if($draft['page'] !== (int) $value->ownerPage()->id || $draft['field'] !== (int) $value->ownerField()->id) {
			return $value;
		}
		$walk = function(array $raw, FlexFieldDefs $defs, string $prefix) use (&$walk, $draft): array {
			foreach($defs->properties() as $name => $definition) {
				if($definition['kind'] !== 'rows') {
					continue;
				}
				$address = $prefix . $name;
				$items = $raw[$name]['items'] ?? [];
				$ids = array_column($items, 'id');
				foreach($draft['rows'][$address] ?? [] as $id => $row) {
					if(!in_array($id, $ids, true)) {
						$items[] = $row;
					}
				}
				if(isset($draft['orders'][$address])) {
					$byId = array_column($items, null, 'id');
					$items = [];
					foreach($draft['orders'][$address] as $id) {
						if(!isset($byId[$id])) {
							throw new WirePermissionException($this->_('Invalid Flex editor context'));
						}
						$items[] = $byId[$id];
					}
				}
				foreach($items as &$row) {
					$row['values'] = $walk($row['values'], $defs->rowType($name, $row['type'])['schema'], $address . '.' . $row['id'] . '.');
				}
				unset($row);
				$raw[$name] = ['_flex' => FlexStorage::ROWS_FORMAT, 'items' => $items];
			}
			return $raw;
		};
		$copy = new FlexValue($value->ownerPage(), $value->ownerField(), $value->definitions(), $walk($value->rawArray(), $value->definitions(), ''));
		return $this->overlay($token, $copy);
	}

	private function editable(Page $page, Field $field): void {
		if(
			$this->wire()->config->demo
			|| $page->hasStatus(Page::statusLocked)
			|| !$page->editable($field->name)
			|| in_array((int) $field->collapsed, [4, 6, 7, 8, 22], true)
		) {
			throw new WirePermissionException($this->_('This Flex field is not editable'));
		}
	}

	private function editableInput(Inputfield $input): void {
		for($item = $input; $item instanceof Inputfield; $item = $item->parent) {
			if(!$item->editable() || in_array((int) $item->collapsed, [4, 6, 7, 8, 22], true) || $item->disabled) {
				throw new WirePermissionException($this->_('This Flex property is not editable'));
			}
		}
	}

	/** Resolve only an actual owned original/variation; a filename grants no authority. */
	private function imageContext(): ?array {
		$input = $this->wire()->input;
		$pageId = (int) $input->get('id');
		$requested = (string) $input->get('file');
		if(preg_match('/(?:^|[\/,])(\d+)[\/,]([^\/,]+)$/D', $requested, $match)) {
			$pageId = (int) $match[1];
			$requested = $match[2];
		}
		$page = $this->wire()->pages->get($pageId);
		if(!$page->id || $requested === '') {
			return null;
		}
		foreach($page->fieldgroup as $base) {
			if(!$base->type instanceof FieldtypeFlex) {
				continue;
			}
			$field = $page->fieldgroup->getFieldContext($base);
			foreach($this->type->getPageimages($page, $field) as $file) {
				if($file->basename() !== $requested && !$file->isVariation($requested)) {
					continue;
				}
				$this->editable($page, $field);
				return [$page, $field, $file];
			}
		}
		$field = $this->wire()->fields->get((string) $input->get('field'));
		if($field && $field->type instanceof FieldtypeFlex) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		return null;
	}

	/**
	 * Handle native image selection and edits through the authenticated Flex draft.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function image(HookEvent $event): void {
		$context = $this->imageContext();
		if(!$context) {
			return;
		}
		[$page, $field, $original] = $context;
		$input = $this->wire()->input;
		$token = (string) $input->get('flex_draft');
		$master = $page;
		while($master instanceof RepeaterPage) {
			$master = $master->getForPage();
		}
		if(!$this->wire()->user->hasPermission('page-edit-images', $master)) {
			throw new WirePermissionException($this->_('Image editing is not permitted'));
		}
		if($event->method === 'executeVariations') {
			if($token === '') {
				$token = $this->open($page->getUnformatted($field->name), $field->name);
				$input->get->flex_draft = $token;
			}
			$draft = $this->draft($token);
			if($draft['page'] !== (int) $page->id || $draft['field'] !== (int) $field->id) {
				throw new WirePermissionException($this->_('Invalid Flex editor context'));
			}
			$this->assertFresh($token, $page, $field);
			[, , $native] = $this->target($token, $original->pagefiles->address(), $page->getUnformatted($field->name));
			$this->editableInput($native);
			if(count($input->post)) {
				$this->wire()->session->CSRF->validate();
				// Native deletion is immediate. Preserve discovery of draft-only
				// images and the parent counter across its post/redirect/get cycle.
				$varcnt = (string) $input->get('varcnt');
				$this->wire()->session->addHookBefore('redirect', function(HookEvent $redirect) use ($page, $original, $token, $varcnt) {
					$url = (string) $redirect->arguments(0);
					parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
					if(str_starts_with($url, './?') && (int) ($query['id'] ?? 0) === (int) $page->id && ($query['file'] ?? '') === $original->basename()) {
						$redirect->arguments(0, $url . '&' . http_build_query(['flex_draft' => $token, 'varcnt' => $varcnt]));
					}
				});
			}
			return;
		}
		if($event->method === 'executeResize') {
			// RTE requests have no Flex draft. They may create a derivative of a
			// currently committed original, after the same field/property checks.
			if($token === '') {
				$asset = $this->type->media()->asset($original->assetId(), true);
				if(!$asset || $asset['state'] !== 'committed') {
					throw new WirePermissionException($this->_('Only committed images can be selected'));
				}
				$token = $this->open($page->getUnformatted($field->name), $field->name);
			}
			$this->assertFresh($token, $page, $field);
			[, , $native] = $this->target($token, $original->pagefiles->address(), $page->getUnformatted($field->name));
			$this->editableInput($native);
			return;
		}
		if($token === '' && (string) $input->get('rte') !== '0' && !count($input->post)) {
			return;
		}
		$draft = $this->draft($token);
		if($draft['page'] !== (int) $page->id || $draft['field'] !== (int) $field->id) {
			throw new WirePermissionException($this->_('Invalid Flex editor context'));
		}
		$this->assertFresh($token, $page, $field);
		$page->of(false);
		$address = $original->pagefiles->address();
		[, , $native] = $this->target($token, $address, $page->getUnformatted($field->name));
		$this->editableInput($native);
		if(!count($input->post)) {
			return;
		}
		$this->wire()->session->CSRF->validate();
		$event->replace = true;
		$files = $native->value;
		$file = $files->get($original->basename());
		if(!$file instanceof Pageimage) {
			throw new WireException($this->_('Image no longer belongs to this draft'));
		}
		if($input->post('submit_crop')) {
			$this->type->media()->transform($file, 'crop', [
				'x'      => (int) $input->post('crop_x'),
				'y'      => (int) $input->post('crop_y'),
				'width'  => (int) $input->post('crop_w'),
				'height' => (int) $input->post('crop_h'),
			]);
			$this->stageMedia($token, $address, $files);
			$url = $this->wire()->config->urls->admin . 'page/image/edit/?' . http_build_query([
				'id'         => $page->id,
				'file'       => $page->id . ',' . $file->basename(),
				'field'      => $field->name,
				'rte'        => 0,
				'modal'      => 1,
				'flex_draft' => $token,
			]);
			$this->wire()->session->location($url);
			$event->return = '';
			return;
		}
		if(!$input->post('submit_save_replace') && !$input->post('submit_save_copy')) {
			throw new WireException($this->_('Unsupported Flex image request'));
		}
		if($input->post('submit_save_copy')) {
			$meta = FlexMedia::describe($file, $file->assetId());
			$file = $files->clone($file);
			$file->description(true, $meta['description']);
			$file->tags = $meta['tags'];
			$file->filedata($meta['filedata']);
		}
		if((int) $input->post('use_resize') === 1) {
			$this->type->media()->transform($file, 'resize', ['width' => (int) $input->post('width')]);
		}
		$this->stageMedia($token, $address, $files);
		$event->return = '<p>' . $this->_('Image change prepared. Save the page to apply it.') . '</p><script>parent.jQuery(parent.document).trigger("flexMediaRefresh",' . json_encode([$token, $address], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . ');</script>';
	}

	/**
	 * Add the Flex draft context to the native image editor form.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function decorateImage(HookEvent $event): void {
		$token = (string) $this->wire()->input->get('flex_draft');
		if($token === '' || $event->replace || !is_string($event->return)) {
			return;
		}
		$this->draft($token);
		$event->return = preg_replace_callback('/(<form[^>]+action=)([\x22\x27])([^\x22\x27]+)\2([^>]*>)/', function($m) use ($token) {
			return $m[1] . $m[2] . $m[3] . '&flex_draft=' . $token . $m[2] . $m[4] . $this->wire()->session->CSRF->renderInput();
		}, $event->return);
	}

	/**
	 * Validate and dispatch row and media editor AJAX requests.
	 *
	 * @param HookEvent $event
	 * @return void
	 */
	public function ajax(HookEvent $event): void {
		$token = $_SERVER['HTTP_X_FLEX_DRAFT'] ?? '';
		if($token === '') {
			$field = $this->wire()->fields->get((string) ($_SERVER['HTTP_X_FIELDNAME'] ?? ''));
			if(!$field || !$field->type instanceof FieldtypeFlex) {
				return;
			}
			$event->replace = true;
			$event->return = '';
			echo json_encode([
				'error'   => true,
				'message' => 'Missing Flex editor draft',
			], JSON_THROW_ON_ERROR);
			return;
		}
		$event->replace = true;
		try {
			$draft = $this->draft($token);
			$page = $event->arguments(0);
			$field = $this->wire()->fields->get($draft['field']);
			if(
				!$page instanceof Page
				|| (int) $page->id !== $draft['page']
				|| !$field
				|| !$field->type instanceof FieldtypeFlex
				|| !$page->hasField($field)
				|| ($_SERVER['HTTP_X_FIELDNAME'] ?? '') !== $field->name
			) {
				throw new WirePermissionException($this->_('Invalid Flex editor context'));
			}
			$field = $page->fieldgroup->getFieldContext($field);
			$this->editable($page, $field);
			$this->wire()->session->CSRF->validate();
			$this->assertFresh($token, $page, $field);
			$page->of(false);
			$value = $page->getUnformatted($field->name);
			$action = (string) $this->wire()->input->post('flex_action');
			if(in_array($action, ['add', 'duplicate'], true)) {
				if($this->wire()->input->post('flex_complete') !== $token) {
					throw new WireException($this->_('Incomplete Flex row request; check the PHP input limits'));
				}
				$set = (string) $this->wire()->input->post('flex_set');
				[$parent, $setName, $definition] = $this->collection($token, $set, $value, true);
				$order = (string) $this->wire()->input->post('flex_order');
				$ids = $order === '' ? [] : explode(',', $order);
				foreach($ids as $rowId) {
					$this->row($token, $set, $rowId, $value);
				}
				if(count(array_unique($ids)) !== count($ids)) {
					throw new WireException($this->_('Duplicate row in request'));
				}
				$max = (int) ($definition['settings']['maxItems'] ?? 0);
				if($max && count($ids) >= $max) {
					throw new WireException($this->_('Maximum repeatable item count reached'));
				}
				$orders = $this->wire()->input->post('flex_orders');
				$orders = is_string($orders) ? json_decode($orders, true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR) : [$set => $ids];
				if(!is_array($orders) || count($orders) > 2000) {
					throw new WirePermissionException($this->_('Invalid Flex editor context'));
				}
				foreach($orders as $address => $orderIds) {
					if(!is_string($address) || !is_array($orderIds) || !array_is_list($orderIds) || count(array_unique($orderIds, SORT_REGULAR)) !== count($orderIds)) {
						throw new WirePermissionException($this->_('Invalid Flex editor context'));
					}
					$this->collection($token, $address, $value, true);
					foreach($orderIds as $rowId) {
						if(!is_string($rowId)) {
							throw new WirePermissionException($this->_('Invalid Flex editor context'));
						}
						$this->row($token, $address, $rowId, $value, false);
					}
				}
				$orders[$set] = $ids;
				$draft['orders'] = $orders;
				$this->put($token, $draft);
				$rows = new FlexRows($page, $field, $set, $definition);
				if($action === 'duplicate') {
					$source = $this->row($token, $set, (string) $this->wire()->input->post('flex_source'), $value);
					$postedType = $this->wire()->input->post('flex_type');
					if($postedType !== null && $postedType !== $source->rowType()) {
						throw new WirePermissionException($this->_('An existing Flex row cannot change type'));
					}
					$prefix = FlexPath::inputPrefix($draft['input'], $source->address());
					$form = $this->wire()->modules->get('InputfieldForm');
					$form->protectCSRF = false;
					foreach($source->definitions()->getInputfields($source, $prefix, $token, $value)->children() as $child) {
						$form->add($child);
					}
					$form->processInput($this->wire()->input->post);
					InputfieldFlex::applyInputs($source, $form, $prefix);
					if($form->getErrors()) {
						throw new WireException(implode('; ', $form->getErrors()));
					}
					$rows->add($source);
					$row = $rows->duplicateRow($source->rowId());
				} else {
					$row = $rows->addRow((string) $this->wire()->input->post('flex_type'));
				}
				$this->registerRow($token, $set, $row);
				$input = $this->wire(new InputfieldFlexRows());
				$input->name = FlexPath::inputPrefix($draft['input'], $parent->address()) . $setName;
				$input->definition = $definition;
				$input->label = $definition['input']->label;
				$input->flexDraft = $token;
				$input->flexRoot = $value;
				$input->setName = $set;
				$input->flexInputName = $draft['input'];
				$response = [
					'error' => false,
					'id'    => $row->rowId(),
					'html'  => $input->renderRow($row, count($ids) + 1, true),
				];
			} else if($action === 'media-render') {
				$address = (string) $this->wire()->input->post('flex_path');
				[, , $input] = $this->target($token, $address, $value);
				$this->editableInput($input);
				$response = [
					'error' => false,
					'html'  => $input->render(),
				];
			} else if(isset($_SERVER['HTTP_X_FILENAME'])) {
				$address = (string) ($_SERVER['HTTP_X_FLEX_PATH'] ?? '');
				[$parent, $leaf, $input] = $this->target($token, $address, $value);
				$this->editableInput($input);
				if($input->noUpload) {
					throw new WirePermissionException($this->_('Uploads are disabled for this property'));
				}
				$files = $input->value;
				$files->draft($token);
				$assetId = bin2hex(random_bytes(16));
				$input->noAjax = 0; // Enable the native raw-body reader only for this isolated request.
				$original = rawurldecode((string) $_SERVER['HTTP_X_FILENAME']);
				$basename = $this->type->media()->uniqueName($page, basename($original), $assetId);
				$this->type->media()->reserve($files, $assetId, $basename, 'uploading');
				$_SERVER['HTTP_X_FILENAME'] = $basename;
				ob_start();
				try {
					$input->processInput($this->wire()->input->post);
					$native = ob_get_contents();
				} finally {
					ob_end_clean();
				}
				if($input->getErrors()) {
					throw new WireException(implode('; ', $input->getErrors()));
				}
				$uploaded = $files->get($basename);
				if(!$uploaded || !is_file($uploaded->filename())) {
					throw new WireException($this->_('Native upload did not produce the reserved file'));
				}
				$this->type->media()->finishUpload($uploaded);
				$this->stageMedia($token, $address, $files);
				$response = json_decode($native, true, 64, JSON_THROW_ON_ERROR);
				if(!is_array($response)) {
					throw new WireException($this->_('Invalid native upload response'));
				}
			} else {
				throw new WireException($this->_('Invalid Flex editor context'));
			}
			$event->return = '';
			echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch(\Throwable $error) {
			if(isset($assetId)) {
				$this->type->media()->abandon($assetId, $page, $field, $token);
			}
			$event->return = '';
			echo json_encode([
				'error'   => true,
				'message' => $error->getMessage(),
			], JSON_THROW_ON_ERROR);
		}
	}
}
