<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
if(!isset($same, $assert, $throws)) {
	require dirname(__DIR__, 2) . '/scripts/bootstrap.php';
	$wire = wire();
	$type = $wire->modules->get('FieldtypeFlex');
	$passed = 0;
	$assert = static function(bool $ok, string $message) use (&$passed): void {
		if(!$ok) {
			throw new \RuntimeException('FAIL: ' . $message);
		}
		++$passed;
		echo 'PASS: ' . $message . "\n";
	};
	$same = static fn($expected, $actual, string $message) => $assert($expected === $actual, $message . ($expected === $actual ? '' : ' actual=' . var_export($actual, true)));
	$throws = static function(callable $action, string $message) use ($assert): void {
		try {
			$action();
		} catch(WireException $error) {
			$assert(true, $message);
			return;
		}
		$assert(false, $message);
	};
}

(static function() use ($wire, $type, $same, $assert, $throws) {
	$schemaFor = static function(int $depth) use (&$schemaFor): array {
		$children = [
			'flag'    => ['type' => 'checkbox'],
			'conditional' => ['type' => 'text', 'showIf' => 'flag=1'],
			'heading' => ['type' => 'text'],
			'number'  => ['type' => 'integer'],
			'photo'   => ['type' => 'image', 'maxFiles' => 1],
		];
		if($depth > 1) {
			$children += $schemaFor($depth - 1);
		}
		return ['nodes' => [
			'type'       => 'rows',
			'types'      => [
				'item' => ['label' => 'Item', 'children' => $children],
				'other' => ['label' => 'Other', 'children' => ['heading' => ['type' => 'text']]],
			],
		]];
	};
	$field = $wire->wire(new FlexField());
	$field->name = 'flex_nested_' . bin2hex(random_bytes(4));
	$field->type = $type;
	$field->flexDefinition = json_encode($schemaFor(5), JSON_THROW_ON_ERROR);
	$wire->fields->save($field);
	$group = $wire->wire(new Fieldgroup());
	$group->name = $field->name;
	$group->add($wire->fields->get('title'));
	$group->add($field);
	$group->save();
	$template = $wire->wire(new Template());
	$template->name = $group->name;
	$template->fieldgroup = $group;
	$wire->templates->save($template);
	$pages = [];
	try {
		$page = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => $field->name, 'title' => 'Nested tests']);
		$page->of(false);
		$pages[] = $page;
		$value = $type->getBlankValue($page, $field);
		$parent = $value;
		$addresses = [];
		for($level = 1; $level <= 5; ++$level) {
			$parent = $parent->nodes->addRow('item', ['heading' => 'Level ' . $level, 'number' => $level]);
			$addresses[] = $parent->address();
			$same((float) $level, substr_count($parent->address(), '.') / 2 + 0.5, 'row address includes every ancestor at level ' . $level);
		}
		$page->{$field->name} = $value;
		$page->save();
		$id = $page->id;
		$read = $wire->pages->getFresh($id);
		$read->of(false);
		$raw = $type->storage()->persisted($read, $field);
		$same(5, count(iterator_to_array(FlexPath::rows($raw), false)), 'five nested rows roundtrip through canonical JSON');
		$rows = $wire->database->query('SELECT * FROM fieldtype_flex_rows WHERE fields_id=' . (int) $field->id . ' AND pages_id=' . $id . ' ORDER BY LENGTH(schema_path)')->fetchAll(\PDO::FETCH_ASSOC);
		$same(5, count($rows), 'projection contains every descendant once');
		$same(hash('sha256', $addresses[0]), $rows[1]['parent_row_key'], 'projection records concrete parent identity');
		$assert(!isset(json_decode($rows[0]['data'], true)['nodes']), 'ancestor projection omits nested envelopes');
		$deep = $read->getUnformatted($field->name)->nodes->first()->nodes->first()->nodes->first()->nodes->first()->nodes->first();
		$same('Level 5', $deep->heading, 'fifth-level typed value is hydrated');
		$deep->heading = 'Deep edit';
		$assert($read->isChanged($field->name), 'deepest mutation marks root page field changed');
		$read->save($field->name);
		$find = static fn(string $selector) => $wire->pages->findIDs('id=' . $id . ', ' . $field->name . '.' . $selector);
		$same([$id], $find('nodes.item.nodes.item.nodes.item.nodes.item.nodes.item.heading="Deep edit"'), 'PageFinder accepts five-level leaf paths');
		$same([$id], $find('nodes.item.nodes.item.nodes.item.nodes.item.nodes.item.number=5'), 'deep selectors retain numeric typing');
		$same([$id], $find('nodes.item.nodes.item.nodes.item.count=1'), 'global count reaches a third-level collection');
		$same([$id], $find('nodes.item.nodes.item.nodes._type=item'), 'deep type selector matches');
		$same([], $find('nodes.item.nodes.item.heading="Missing"'), 'positive deep selector does not invent rows');
		$same([$id], $find('nodes.item.nodes.item.heading!=Missing'), 'negative deep selector uses NOT EXISTS');
		$same([], $find('nodes.item.nodes.item.heading=""'), 'blank excludes populated descendants');
		$same([$id], $find('nodes.item.nodes.other.heading=""'), 'blank includes absent descendant types');
		$path = 'nodes.item.nodes.item.nodes.item.nodes.item.nodes.item.heading';
		$same('text', $type->getDefinitions($field)->property($path)['kind'], 'metadata resolves fifth-level paths');
		$info = $type->getSelectorInfo($field);
		$assert(isset($info['subfields'][$path]), 'native selector discovery offers full deep suffixes');

		$read = $wire->pages->getFresh($id);
		$read->of(false);
		$value = $read->getUnformatted($field->name);
		$a = $value->nodes->first();
		$a->heading = 'A';
		$a->nodes->first()->heading = 'X';
		$b = $value->nodes->addRow('item', ['heading' => 'B']);
		$b->nodes->addRow('item', ['heading' => 'Y', 'number' => 2]);
		$b->nodes->addRow('item', ['heading' => 'Z', 'number' => 3]);
		$read->save($field->name);
		$same([$id], $find('nodes.item.nodes.count=3'), 'global child count aggregates across parents');
		$same([], $find('nodes.item="heading=A, nodes.item.heading=Y"'), 'same-row query cannot combine parent A and children of B');
		$same([$id], $find('nodes.item="heading=B, nodes.item.heading=Y, nodes.item.count=2"'), 'same-row child predicates correlate with their parent');
		$same([], $find('nodes.item.nodes.item="heading=Y, number=3"'), 'terminal same-row predicates cannot combine different children');
		$same([$id], $find('nodes.item.nodes.item="heading=Y, number=2"'), 'terminal same-row predicates match one child');
		$copy = $value->nodes->duplicateRow($a->rowId());
		$assert($copy->rowId() !== $a->rowId(), 'subtree duplication creates a new parent ID');
		$assert($copy->nodes->first()->rowId() !== $a->nodes->first()->rowId(), 'subtree duplication creates new child IDs');
		$same('X', $copy->nodes->first()->heading, 'subtree duplication retains child values');
		$copy->nodes->first()->heading = 'Independent';
		$same('X', $a->nodes->first()->heading, 'duplicated children remain independent');
		$read->save($field->name);
		$same([$id], $find('nodes.item.nodes.item.heading=Independent'), 'saving duplicated subtrees projects descendants automatically');

		$admin = $type->admin();
		$draft = $admin->open($value, $field->name);
		$new = $value->nodes->addRow('item', ['heading' => 'Draft parent']);
		$admin->registerRow($draft, 'nodes', $new);
		$child = $new->nodes->addRow('item', ['heading' => 'Draft child']);
		$set = $new->address() . '.nodes';
		$admin->registerRow($draft, $set, $child);
		$same('Draft child', $admin->row($draft, $set, $child->rowId(), $type->getBlankValue($read, $field))->heading, 'new draft parent resolves new child before any save');
		$discovery = $admin->discovery($draft, $type->getBlankValue($read, $field));
		$same(1, count($discovery->nodes->first()->nodes), 'discovery includes unsaved draft descendants');
		$throws(fn() => $admin->row($draft, 'nodes.' . str_repeat('a', 32) . '.nodes', $child->rowId(), $value), 'draft rejects a forged parent chain');
		$admin->forget($draft);

		$same([$id], $find('nodes.item="heading=B, nodes.item=\'heading=Y, number=2\'"'), 'recursive quoted same-row expression keeps both parent and child scope');
		$same([], $find('nodes.item="heading=A, nodes.item=\'heading=Y, number=2\'"'), 'recursive quoted same-row rejects a child of another parent');

		$read = $wire->pages->getFresh($id);
		$read->of(false);
		$value = $read->getUnformatted($field->name);
		$a = $value->nodes->first();
		$imagePath = dirname(__DIR__, 2) . '/.dev/nested-' . bin2hex(random_bytes(4)) . '.png';
		$image = imagecreatetruecolor(24, 24);
		imagepng($image, $imagePath);
		imagedestroy($image);
		try {
			$a->nodes->first()->photo->add($imagePath);
			$read->save($field->name);
		} finally {
			unlink($imagePath);
		}
		$read = $wire->pages->getFresh($id);
		$read->of(false);
		$value = $read->getUnformatted($field->name);
		$a = $value->nodes->first();
		$photo = $a->nodes->first()->photo->first();
		$photo->description = 'Deep metadata';
		$type->saveFile($read, $field, $photo, ['description']);
		$same('Deep metadata', $wire->pages->getFresh($id)->getUnformatted($field->name)->nodes->first()->nodes->first()->photo->first()->description, 'saveFile updates nested media metadata under canonical lock');
		$photoName = $photo->filename();
		$copy = $value->nodes->duplicateRow($a->rowId());
		$copyId = $copy->rowId();
		$read->save($field->name);
		$copiedPhoto = $copy->nodes->first()->photo->first();
		$assert($copiedPhoto->assetId() !== $photo->assetId(), 'parent duplicate allocates independent descendant assets');
		$assert($copiedPhoto->filename() !== $photoName && is_file($copiedPhoto->filename()) && is_file($photoName), 'parent duplicate owns an independent physical image');
		$copiedName = $copiedPhoto->filename();
		$value->nodes->removeRow($copyId);
		$read->save($field->name);
		$assert(!is_file($copiedName) && is_file($photoName), 'removing copied parent cleans only its descendant media after commit');
		$same([$id], $find('nodes.item.nodes.item.photo.count>0'), 'deep media count uses projected direct metadata');

		$before = $type->storage()->persisted($read, $field);
		$changed = $before;
		$changed['nodes']['items'][0]['values']['nodes']['items'][0]['type'] = 'other';
		$read->{$field->name} = $changed;
		$throws(fn() => $read->save($field->name), 'raw type changes on descendant identities are rejected');
		$same(FlexStorage::revision($before), FlexStorage::revision($type->storage()->persisted($read, $field)), 'descendant type failure rolls back canonical data');

		$read = $wire->pages->getFresh($id);
		$read->of(false);
		$editor = $field->getInputfield($read);
		$editor->value = $read->getUnformatted($field->name);
		$html = $editor->render();
		$assert(str_contains($html, FlexPath::inputPrefix($field->name, $value->nodes->first()->nodes->first()->address())), 'dependent sibling before nested set retains the typed root context');
		preg_match('/name="' . preg_quote($field->name . '__0draft', '/') . '" value="([a-f0-9]{64})"/', $html, $match);
		$token = $match[1];
		$rootRows = $editor->value->nodes;
		$parent = $rootRows->first();
		$child = $parent->nodes->first();
		$prefix = $field->name . '__' . str_replace('.', '__', $parent->address()) . '__nodes';
		$post = [
			$field->name . '__0draft' => $token,
			$field->name . '__0complete' => $token,
			FlexPath::controlName($field->name, 'nodes', 'present') => '1',
			FlexPath::controlName($field->name, 'nodes', 'order') => implode(',', array_map(static fn($r) => $r->rowId(), iterator_to_array($rootRows))),
			FlexPath::controlName($field->name, $parent->address() . '.nodes', 'present') => '1',
			FlexPath::controlName($field->name, $parent->address() . '.nodes', 'order') => $child->rowId(),
			FlexPath::inputPrefix($field->name, $child->address()) . 'heading' => 'Native nested POST',
		];
		$editor->processInput($wire->wire(new WireInputData($post)));
		$same([], $editor->getErrors(), 'native nested form processing has no errors');
		$same('Native nested POST', $editor->value->nodes->first()->nodes->first()->heading, 'native form applies deepest processed values');
		$same(2, count($editor->value->nodes->eq(1)->nodes), 'omitted child collection preserves canonical rows');
		$read->{$field->name} = $editor->value;
		$read->save($field->name);
		$truncated = $field->getInputfield($read);
		$truncated->value = $read->getUnformatted($field->name);
		unset($post[$field->name . '__0complete']);
		$truncated->processInput($wire->wire(new WireInputData($post)));
		$assert(count($truncated->getErrors()) > 0, 'missing tail marker rejects truncated native submissions');
		$type->admin()->forget($token);

		$preservedField = clone $field;
		$preservedSchema = $schemaFor(5);
		unset($preservedSchema['nodes']['types']['item']['children']['nodes']);
		$preservedField->flexDefinition = json_encode($preservedSchema);
		$preservedField->flexPrune = 0;
		$preserved = new FlexValue($read, $preservedField, $type->getDefinitions($preservedField, $read), $before);
		$preservedSource = $preserved->nodes->first();
		$unknownChildId = $preservedSource->rawArray()['nodes']['items'][0]['id'];
		$preservedCopy = $preserved->nodes->duplicateRow($preservedSource->rowId());
		$assert($preservedCopy->rawArray()['nodes']['items'][0]['id'] !== $unknownChildId, 'preservation-mode duplicate regenerates unknown descendant IDs');
		$type->media()->prepare($preserved);
		$sourceRecords = FlexMedia::records($preservedSource->rawArray());
		$copyRecords = FlexMedia::records($preservedCopy->rawArray());
		$assert($copyRecords[0]['meta']['id'] !== $sourceRecords[0]['meta']['id'], 'preservation-mode duplicate independently copies unknown descendant media');
		$type->media()->collect(100);

		$sharedIdRaw = $before;
		$sharedIdRaw['nodes']['items'][1]['values']['nodes']['items'][0]['id'] = $sharedIdRaw['nodes']['items'][0]['values']['nodes']['items'][0]['id'];
		$sharedIds = new FlexValue($read, $field, $type->getDefinitions($field, $read), $sharedIdRaw);
		$same('Y', $sharedIds->nodes->eq(1)->nodes->first()->heading, 'same local child identity under another parent hydrates independently');
		$sharedDraft = $type->admin()->open($sharedIds, $field->name);
		$addressA = $sharedIds->nodes->first()->address() . '.nodes';
		$addressB = $sharedIds->nodes->eq(1)->address() . '.nodes';
		$childId = $sharedIds->nodes->first()->nodes->first()->rowId();
		$same('X', $type->admin()->row($sharedDraft, $addressA, $childId, $sharedIds)->heading, 'draft lookup resolves duplicate local IDs under parent A');
		$same('Y', $type->admin()->row($sharedDraft, $addressB, $childId, $sharedIds)->heading, 'draft lookup resolves duplicate local IDs under parent B');
		$type->admin()->forget($sharedDraft);

		$collisionName = 'nodes__' . $sharedIds->nodes->first()->rowId() . '__heading';
		$collisionField = clone $field;
		$collisionField->flexDefinition = json_encode($schemaFor(5) + [$collisionName => ['type' => 'text']]);
		$collisionValue = new FlexValue($read, $collisionField, $type->getDefinitions($collisionField, $read), $before + [$collisionName => 'Root collision fixture']);
		$collisionEditor = $collisionField->getInputfield($read);
		$collisionEditor->value = $collisionValue;
		$collisionHtml = $collisionEditor->render();
		$assert(str_contains($collisionHtml, 'value="Root collision fixture"') && str_contains($collisionHtml, FlexPath::inputPrefix($field->name, $collisionValue->nodes->first()->address()) . 'heading'), 'native rendering isolates a root property resembling a former row prefix');


		$empty = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => $field->name . '-empty', 'title' => 'No rows']);
		$empty->of(false);
		$pages[] = $empty;
		$empty->save();
		$wire->database->exec('DELETE FROM `' . $field->table . '` WHERE pages_id=' . (int) $empty->id);
		$findEmpty = static fn(string $selector) => $wire->pages->findIDs('id=' . $empty->id . ', ' . $field->name . '.' . $selector);
		$same([$empty->id], $findEmpty('nodes.item.nodes.item.heading!=Missing'), 'negative deep selector includes missing canonical field row');
		$same([$empty->id], $findEmpty('nodes.item.nodes.item.heading=""'), 'blank deep selector includes missing canonical field row');
		$same([$empty->id], $findEmpty('nodes.item.nodes.item.count=0'), 'zero deep count includes missing ancestors');
		$same([], $findEmpty('nodes.item="nodes.item.count=0"'), 'same-row count needs an existing parent');

		$replaceField = clone $field;
		$replaceSchema = $schemaFor(2);
		$replaceSchema['nodes']['maxItems'] = 2;
		$replaceField->flexDefinition = json_encode($replaceSchema);
		$replaceValue = new FlexValue($empty, $replaceField, $type->getDefinitions($replaceField, $empty));
		$keep = $replaceValue->nodes->addRow('item', ['heading' => 'Keep']);
		$replaceValue->nodes->addRow('item', ['heading' => 'Removed in editor']);
		$empty->{$field->name} = $replaceValue;
		$replaceDraft = $admin->open($replaceValue, $field->name);
		$replacementRows = new FlexRows($empty, $replaceField, 'nodes', $replaceValue->definitions()->property('nodes'));
		$replacement = $replacementRows->addRow('item', ['heading' => 'Replacement']);
		$draftData = $admin->draft($replaceDraft);
		$draftData['orders'] = ['nodes' => [$keep->rowId()]];
		$admin->put($replaceDraft, $draftData);
		$admin->registerRow($replaceDraft, 'nodes', $replacement);
		$same(2, count($admin->discovery($replaceDraft, $replaceValue)->nodes), 'remove then add respects the current collection maximum');
		$draftData = $admin->draft($replaceDraft);
		$draftData['orders'] = ['nodes' => [$keep->rowId()]];
		$admin->put($replaceDraft, $draftData);
		$secondReplacement = $replacementRows->addRow('item', ['heading' => 'Next replacement']);
		$admin->registerRow($replaceDraft, 'nodes', $secondReplacement);
		$same(2, count($admin->discovery($replaceDraft, $replaceValue)->nodes), 'removed historical draft rows do not consume active collection limits');
		$admin->forget($replaceDraft);

		$collision = 'nodes__' . $a->rowId() . '__heading';
		$assert(FlexPath::inputPrefix($field->name, $a->address()) . 'heading' !== FlexPath::inputPrefix($field->name) . $collision, 'allowed double-underscore property cannot collide with a concrete row input');
		$process = $wire->wire(new class($read) extends Wire {
			private Page $page;

			/** @param Page $page Actual page supplied to the native editor hook. */
			public function __construct(Page $page) {
				parent::__construct();
				$this->page = $page;
			}

			/** @return Page Native editor's owning page. */
			public function getPage(): Page {
				return $this->page;
			}
		});
		$form = $wire->modules->get('InputfieldForm');
		$form->id = 'ProcessPageEdit';
		$buildEvent = new HookEvent(['object' => $process, 'return' => $form]);
		$type->addPageEditFormGuard($buildEvent);
		$assert($form->getChildByName('_flex_complete') instanceof InputfieldHidden, 'PageEdit guard appends a full-form tail marker');
		$same((int) ini_get('max_input_vars'), (int) $form->attr('data-flex-max-input-vars'), 'PageEdit exposes the actual PHP input variable budget');
		$previousProcess = $wire->process;
		$formErrorsProperty = new \ReflectionProperty($type, 'adminFormErrors');
		$formErrorsProperty->setAccessible(true);
		$previousFormErrors = $formErrorsProperty->getValue($type);
		$oldMarker = $wire->input->post('_flex_complete');
		try {
			$wire->set('process', 'ProcessPageEdit');
			unset($wire->input->post->_flex_complete);
			$guardEvent = new HookEvent(['object' => $process, 'arguments' => [$form, 0]]);
			$type->checkPageEditFormGuard($guardEvent);
			$assert($guardEvent->replace, 'truncated entire PageEdit is rejected before native field processing');
			$read->title = 'Must not save a partial form';
			$throws(fn() => $read->save(), 'whole-form guard prevents unrelated field persistence');
			$same('Nested tests', $wire->pages->getFresh($id)->title, 'whole-form truncation retains canonical page title');
			$wire->input->post->_flex_complete = '1';
			$completeEvent = new HookEvent(['object' => $process, 'arguments' => [$form, 0]]);
			$type->checkPageEditFormGuard($completeEvent);
			$assert(!$completeEvent->replace, 'complete PageEdit passes the whole-form guard');
		} finally {
			$formErrorsProperty->setValue($type, $previousFormErrors);
			$wire->set('process', $previousProcess);
			if($oldMarker === null) {
				unset($wire->input->post->_flex_complete);
			} else {
				$wire->input->post->_flex_complete = $oldMarker;
			}
		}

		$snapshotTable = 'fieldtype_flex_rows_test_' . bin2hex(random_bytes(4));
		$db = $wire->database;
		$db->exec("RENAME TABLE fieldtype_flex_rows TO $snapshotTable");
		try {
			$db->exec("CREATE TABLE fieldtype_flex_rows LIKE $snapshotTable");
			$db->exec('ALTER TABLE fieldtype_flex_rows DROP KEY row_identity, DROP KEY schema_owner, DROP KEY parent_owner, DROP schema_path, DROP schema_key, DROP row_key, DROP parent_row_key');
			$throws(fn() => $type->storage()->requireRowSchema(), 'invalid projection schema blocks indexed queries and writes');
		} finally {
			$db->exec('DROP TABLE fieldtype_flex_rows');
			$db->exec("RENAME TABLE $snapshotTable TO fieldtype_flex_rows");
		}

		$limited = clone $field;
		$limited->flexMaxDepth = 2;
		$throws(fn() => FlexPath::validate($raw, $limited), 'depth budget rejects an oversized tree');
		$limited->flexMaxDepth = 0;
		$limited->flexMaxRows = 2;
		$throws(fn() => FlexPath::validate($raw, $limited), 'row budget counts descendants');
		$limited->flexMaxRows = 0;
		$limited->flexMaxBytes = 10;
		$throws(fn() => FlexPath::validate($raw, $limited), 'byte budget rejects excessive data');
	} finally {
		foreach($pages as $created) {
			if($created->id) {
				$wire->pages->delete($created, true);
			}
		}
		$wire->templates->delete($template);
		$wire->fieldgroups->delete($group);
		$wire->fields->delete($field);
	}
})();
echo "Nested repeatables checks complete.\n";
