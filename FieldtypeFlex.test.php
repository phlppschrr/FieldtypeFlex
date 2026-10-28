<?php namespace ProcessWire;

/** Portable public API contract. Run only in an explicitly disposable site. */
class WireTest_FieldtypeFlex extends WireTest {
	private array $createdFields = [];
	private array $createdPages = [];
	private array $attachments = [];
	private array $temporaryFiles = [];
	private string $section = '';

	/** @return bool Whether the external runner opted into disposable writes. */
	public function allow() {
		return getenv('FLEX_WIRETEST_DISPOSABLE') === '1'
			&& $this->wire()->modules->isInstalled('FieldtypeFlex');
	}

	/** @return void Run independent sections, retaining failures for the native runner. */
	public function execute() {
		$sections = [
			'values'      => 'testValues',
			'definitions' => 'testDefinitions',
			'formatting'  => 'testFormatting',
			'rows'        => 'testRows',
			'selectors'   => 'testSelectors',
			'evolution'   => 'testEvolution',
		];
		if(getenv('FLEX_WIRETEST_MEDIA') === '1') {
			$sections['files'] = 'testFiles';
			if(function_exists('imagecreatefrompng') && function_exists('imagepng')) {
				$sections['images'] = 'testImages';
			} else {
				$this->li('SKIP images: GD PNG support unavailable');
			}
		} else {
			$this->li('SKIP files/images: set FLEX_WIRETEST_MEDIA=1 to enable filesystem tests');
		}
		foreach([
			'LanguageSupport'  => ['languages', 'testLanguages'],
			'InputfieldTinyMCE' => ['tinymce', 'testTinyMCE'],
			'FieldtypeRepeater' => ['repeater', 'testRepeater'],
		] as $module => [$label, $method]) {
			if(getenv('FLEX_WIRETEST_OPTIONAL') === '0') {
				$this->li("SKIP $label: FLEX_WIRETEST_OPTIONAL=0");
			} else if($this->wire()->modules->isInstalled($module)) {
				$sections[$label] = $method;
			} else {
				$this->li("SKIP $label: $module is not installed");
			}
		}
		$failures = [];
		foreach($sections as $section => $method) {
			$this->section = $section;
			try {
				$this->$method();
			} catch(\Throwable $error) {
				$failures[] = "$section: " . $error->getMessage();
				$this->li('FAILED ' . end($failures));
			}
		}
		if($failures) {
			$this->fail(implode("\n", $failures));
		}
	}

	private function expect(string $label, $expected, $actual): void {
		$this->check($this->section . ': ' . $label, $expected, $actual);
	}

	private function rejects(string $label, callable $action): void {
		try {
			$action();
		} catch(WireException $error) {
			$this->expect($label, true, true);
			return;
		}
		$this->expect($label, true, false);
	}

	private function createField(array $schema, bool $attach = true): FlexField {
		$field = $this->wire()->wire(new FlexField());
		$field->type = $this->wire()->modules->get('FieldtypeFlex');
		$field->flexDefinition = json_encode($schema, JSON_THROW_ON_ERROR);
		$this->saveField($field, $attach);
		return $field;
	}

	private function saveField(Field $field, bool $attach): void {
		$wire = $this->wire();
		$field->name = WireTests::fieldPrefix . 'flex_' . bin2hex(random_bytes(6));
		if($wire->fields->get($field->name)) {
			throw new WireException('Refusing to reuse an existing WireTest field');
		}
		$this->createdFields[] = $field;
		$wire->fields->save($field);
		if($attach) {
			$group = $this->getTestTemplate()->fieldgroup;
			$this->attachments[] = [$field, $group];
			$group->add($field);
			$group->save();
		}
	}

	private function createPage(): Page {
		$page = $this->wire()->pages->newPage([
			'template' => $this->getTestTemplate(),
			'parent'   => $this->getTestPage(),
			'name'     => 'flex-' . bin2hex(random_bytes(6)),
			'title'    => 'Flex API contract',
		]);
		$this->createdPages[] = $page;
		$page->of(false);
		$page->save();
		return $page;
	}

	private function reload(Page $page): Page {
		$fresh = $this->wire()->pages->getFresh($page->id);
		$fresh->of(false);
		return $fresh;
	}

	private function testValues(): void {
		$field = $this->createField([
			'number' => ['type' => 'integer'],
			'price'  => ['type' => 'float'],
			'flag'   => ['type' => 'checkbox'],
			'choices' => [
				'type'    => 'checkboxes',
				'options' => ['a' => 'Alpha', 'b' => 'Beta'],
			],
			'date' => [
				'type'            => 'datetime',
				'dateInputFormat' => 'Y-m-d',
			],
		]);
		$page = $this->createPage();
		$name = $field->name;
		$page->$name = [
			'number'  => '42',
			'price'   => '3.25',
			'flag'    => 1,
			'choices' => ['a', 'b'],
			'date'    => '2026-10-15',
		];
		$page->save($name);
		$value = $this->reload($page)->getUnformatted($name);
		$this->expect('integer roundtrip', 42, $value->number);
		$this->expect('float roundtrip', 3.25, $value->price);
		$this->expect('checkbox checked', 1, $value->flag);
		$this->expect('multiple choices', ['a', 'b'], $value->choices);
		$this->expect('runtime date timestamp', strtotime('2026-10-15'), $value->date);
		foreach(['', 0] as $number) {
			$page = $this->reload($page);
			$page->$name = [
				'number'  => $number,
				'price'   => $number,
				'flag'    => '',
				'choices' => [],
				'date'    => '',
			];
			$page->save($name);
			$value = $this->reload($page)->getUnformatted($name);
			$this->expect('integer blank/zero ' . var_export($number, true), $number, $value->number);
			$this->expect('float blank/zero ' . var_export($number, true), $number === '' ? '' : 0.0, $value->price);
			$this->expect('content distinguishes zero', $number === 0, $value->hasValue('number'));
			$this->expect('unchecked checkbox', 0, $value->flag);
			$this->expect('empty choices', [], $value->choices);
			$this->expect('empty date', '', $value->date);
		}
		$page = $this->reload($page);
		$value = $page->getUnformatted($name);
		$page->resetTrackChanges();
		$value->number = 17;
		$this->expect('property mutation marks owner', true, $page->isChanged($name));
		$page->save();
		$this->expect('full page save persists mutation', 17, $this->reload($page)->getUnformatted($name)->number);
	}

	private function testDefinitions(): void {
		$field = $this->createField([
			'group' => [
				'type'     => 'fieldset',
				'children' => [
					'heading' => ['type' => 'text', 'label' => 'Heading', 'value' => 'Default'],
				],
			],
			'notice' => ['type' => 'markup', 'value' => '<p>Notice</p>'],
		]);
		$page = $this->createPage();
		$defs = $field->defs($page);
		$this->expect('fieldset leaves are flat', ['heading'], array_keys($defs->properties()));
		$input = $defs->getPropertyInputfield('heading');
		$this->expect('native input metadata', true, $input instanceof InputfieldText);
		$this->expect('metadata label', 'Heading', $input->label);
		$input->label = 'Changed';
		$this->expect('metadata instances are independent', 'Heading', $defs->getPropertyInputfield('heading')->label);
		$this->expect('default for absent key', 'Default', $page->getUnformatted($field->name)->heading);
		$page->set($field->name, ['heading' => '']);
		$page->save($field->name);
		$this->expect('explicit blank overrides default', '', $this->reload($page)->getUnformatted($field->name)->heading);
		$invalid = clone $field;
		$invalid->flexDefinition = '{';
		$this->rejects('invalid JSON rejected', fn() => $invalid->defs($page));
		$invalid->flexDefinition = json_encode(['bad.name' => ['type' => 'text']]);
		$this->rejects('invalid property rejected', fn() => $invalid->defs($page));
		$invalid->flexDefinition = json_encode([
			'a'     => ['type' => 'text'],
			'group' => ['type' => 'fieldset', 'children' => ['a' => ['type' => 'text']]],
		]);
		$this->rejects('duplicate flat property rejected', fn() => $invalid->defs($page));
	}

	private function testFormatting(): void {
		$field = $this->createField([
			'text' => ['type' => 'text'],
			'date' => [
				'type'             => 'datetime',
				'dateInputFormat'  => 'Y-m-d',
				'dateOutputFormat' => 'd.m.Y',
			],
			'choice' => ['type' => 'select', 'options' => ['a' => 'A & B']],
		]);
		$page = $this->createPage();
		$name = $field->name;
		$page->$name = ['text' => '<b>A & B</b>', 'date' => '2026-10-15', 'choice' => 'a'];
		$page->save($name);
		$raw = $page->getUnformatted($name)->rawArray();
		$page->of(true);
		$value = $page->get($name);
		$this->expect('entity encoding', '&lt;b&gt;A &amp; B&lt;/b&gt;', $value->text);
		$this->expect('date output format', '15.10.2026', $value->date);
		$this->expect('formatted option label', 'A &amp; B', $value->optionLabel('choice', 'a'));
		$this->expect('raw values preserved', $raw, $value->rawArray());
		$this->expect('JSON uses canonical values', $raw, json_decode(json_encode($value), true));
		$this->rejects('formatted writes rejected', fn() => $value->set('text', 'Bad'));
		$page = $this->reload($page);
		$this->expect('raw text survives formatted reads', '<b>A & B</b>', $page->getUnformatted($name)->text);
	}

	private function rowSchema(): array {
		return [
			'items' => [
				'type'       => 'rows',
				'types'      => [
					'text' => [
						'children' => [
							'heading' => ['type' => 'text'],
							'price'   => ['type' => 'float'],
						],
					],
					'quote' => [
						'children' => [
							'heading' => ['type' => 'text'],
							'author'  => ['type' => 'text'],
						],
					],
				],
			],
		];
	}

	private function testRows(): void {
		$field = $this->createField($this->rowSchema());
		$page = $this->createPage();
		$name = $field->name;
		$rows = $page->getUnformatted($name)->items;
		$text = $rows->addRow('text', ['heading' => 'Intro', 'price' => 5.5]);
		$quote = $rows->addRow('quote', ['heading' => 'Quote', 'author' => 'Ada']);
		$textId = $text->rowId();
		$quoteId = $quote->rowId();
		$rows->moveRow($quoteId, $textId);
		$page->save($name);
		$page = $this->reload($page);
		$rows = $page->getUnformatted($name)->items;
		$this->expect('order and identities survive reload', [$quoteId, $textId], array_keys($rows->getArray()));
		$this->expect('row type survives reload', 'quote', $rows->first()->rowType());
		$this->expect('typed leaf survives reload', 5.5, $rows->getById($textId)->price);
		$this->expect('canonical rows version', 'rows:1', $page->getUnformatted($name)->rawArray()['items']['_flex']);
		$page->resetTrackChanges();
		$rows->getById($textId)->heading = 'Changed';
		$this->expect('nested mutation marks owner', true, $page->isChanged($name));
		$page->save();
		$this->expect('nested full save roundtrip', 'Changed', $this->reload($page)->getUnformatted($name)->items->getById($textId)->heading);
		$copy = $rows->duplicateRow($textId);
		$this->expect('duplicate gets new ID', true, $copy->rowId() !== $textId);
		$this->expect('duplicate retains type', 'text', $copy->rowType());
		$copy->heading = 'Independent';
		$this->expect('duplicate is independent', 'Changed', $rows->getById($textId)->heading);
		$rows->removeRow($quoteId);
		$page->save($name);
		$rows = $this->reload($page)->getUnformatted($name)->items;
		$this->expect('remove persists', null, $rows->getById($quoteId));
		$this->expect('duplicate persists', 'Independent', $rows->getById($copy->rowId())->heading);
		$this->rejects('unknown row type rejected', fn() => $rows->addRow('missing'));
		$schema = $this->rowSchema();
		$middle = $this->rowSchema();
		$middle['items']['types']['text']['children']['nested'] = $schema['items'];
		$schema['items']['types']['text']['children']['nested'] = $middle['items'];
		$nestedField = $this->createField($schema);
		$nestedName = $nestedField->name;
		$page = $this->createPage();
		$parent = $page->getUnformatted($nestedName)->items->addRow('text', ['heading' => 'Parent']);
		$child = $parent->nested->addRow('text', ['heading' => 'Child']);
		$grandchild = $child->nested->addRow('text', ['heading' => 'Grandchild']);
		$page->save($nestedName);
		$page = $this->reload($page);
		$value = $page->getUnformatted($nestedName);
		$this->expect('three-level roundtrip', 'Grandchild', $value->items->first()->nested->first()->nested->first()->heading);
		$this->expect('full nested identity', $parent->address() . '.nested.' . $child->rowId(), $value->items->first()->nested->first()->address());
		$this->expect('deep selector', [$page->id], $this->wire()->pages->findIDs("id=$page->id, $nestedName.items.text.nested.text.nested.text.heading=Grandchild"));
		$this->expect('parent-correlated count', [$page->id], $this->wire()->pages->findIDs("id=$page->id, $nestedName.items.text=\"heading=Parent, nested.text.count=1\""));
		$copy = $value->items->duplicateRow($parent->rowId());
		$this->expect('nested duplicate regenerates child ID', true, $copy->nested->first()->rowId() !== $child->rowId());
		$this->expect('nested duplicate regenerates grandchild ID', true, $copy->nested->first()->nested->first()->rowId() !== $grandchild->rowId());
		$page->save($nestedName);
		$value = $this->reload($page)->getUnformatted($nestedName);
		$this->expect('nested copies persist', 2, count($value->items));

	}

	private function testSelectors(): void {
		$field = $this->createField(['color' => ['type' => 'text']] + $this->rowSchema());
		$name = $field->name;
		$full = $this->createPage();
		$blank = $this->createPage();
		$full->$name = ['color' => 'red'];
		$rows = $full->getUnformatted($name)->items;
		$rows->addRow('text', ['heading' => 'Intro', 'price' => 5]);
		$rows->addRow('text', ['heading' => 'Other', 'price' => 20]);
		$rows->addRow('quote', ['heading' => 'Intro', 'author' => 'Ada']);
		$full->save($name);
		$blank->$name = ['color' => ''];
		$blank->save($name);
		$ids = $full->id . '|' . $blank->id;
		foreach([
			'color=""'                            => [$blank->id],
			'color!=red'                           => [$blank->id],
			'items._type=text'                     => [$full->id],
			'items._type!=text'                    => [$blank->id],
			'items.count=3'                        => [$full->id],
			'items.text.count=2'                   => [$full->id],
			'items.quote.count=1'                  => [$full->id],
			'items.text.heading=Intro'             => [$full->id],
			'items.text.heading!=Intro'            => [$blank->id],
			'items.text.heading=""'               => [$blank->id],
			'items.text="heading=Intro, price>10"' => [],
			'items.text="heading=Other, price>10"' => [$full->id],
			'items.quote.heading=Other'            => [],
		] as $selector => $expected) {
			$this->expect($selector, $expected, $this->wire()->pages->findIDs("id=$ids, include=all, $name.$selector"));
		}
	}

	private function testEvolution(): void {
		$field = $this->createField(['old_name' => ['type' => 'text'], 'obsolete' => ['type' => 'text']]);
		$page = $this->createPage();
		$name = $field->name;
		$page->$name = ['old_name' => 'Keep', 'obsolete' => 'Retain when configured'];
		$page->save($name);
		$field->flexDefinition = json_encode(['heading' => ['type' => 'text', 'renamedFrom' => 'old_name']]);
		$field->flexPrune = 0;
		$field->save();
		$page = $this->reload($page);
		$this->expect('lazy rename read', 'Keep', $page->getUnformatted($name)->heading);
		$page->save($name);
		$raw = $this->reload($page)->getUnformatted($name)->rawArray();
		$this->expect('prune disabled preserves unknown key', 'Retain when configured', $raw['obsolete']);
		$this->expect('renamed key canonicalized', false, array_key_exists('old_name', $raw));
		$this->rejects('conflicting rename rejected', fn() => $field->type->sanitizeValue($page, $field, ['old_name' => 'Old', 'heading' => 'New']));
		$field->flexPrune = 1;
		$field->save();
		$page = $this->reload($page);
		$page->save($name);
		$this->expect('prune removes obsolete key', ['heading' => 'Keep'], $this->reload($page)->getUnformatted($name)->rawArray());
	}

	private function temporaryFile(string $suffix, string $contents): string {
		$path = tempnam(sys_get_temp_dir(), 'flex-wiretest-');
		if($path === false) {
			throw new WireException('Cannot create temporary media fixture');
		}
		$this->temporaryFiles[] = $path;
		$target = $path . $suffix;
		$this->temporaryFiles[] = $target;
		if(!rename($path, $target) || file_put_contents($target, $contents) === false) {
			throw new WireException('Cannot write temporary media fixture');
		}
		return $target;
	}

	private function testFiles(): void {
		$this->testMedia('file', $this->temporaryFile('.txt', 'Portable Flex media'));
	}

	private function testImages(): void {
		// A tiny generated PNG avoids external assets and image-engine dependencies.
		$image = imagecreatetruecolor(8, 6);
		try {
			$path = $this->temporaryFile('.png', '');
			if(!imagepng($image, $path)) {
				throw new WireException('Cannot write PNG fixture');
			}
		} finally {
			imagedestroy($image);
		}
		$this->testMedia('image', $path);
	}

	private function testMedia(string $kind, string $path): void {
		$field = $this->createField([
			'asset' => [
				'type'       => $kind,
				'extensions' => $kind === 'image' ? 'png' : 'txt',
				'maxFiles'   => 1,
			],
		]);
		$page = $this->createPage();
		$name = $field->name;
		$page->getUnformatted($name)->asset->add($path);
		$page->getUnformatted($name)->asset->first()->description = 'Portable description';
		$page->save($name);
		$page = $this->reload($page);
		$files = $page->getUnformatted($name)->asset;
		$file = $files->first();
		$this->expect('native collection', true, $files instanceof Pagefiles);
		$this->expect('original roundtrip', hash_file('sha256', $path), hash_file('sha256', $file->filename()));
		$this->expect('metadata roundtrip', 'Portable description', $file->description);
		$original = $file->filename();
		if($kind === 'image') {
			$this->expect('native image', true, $file instanceof Pageimage);
			$this->expect('width', 8, $file->width());
		}
		foreach([0, 1, 2] as $mode) {
			$view = clone $field;
			$schema = json_decode($view->flexDefinition, true);
			$schema['asset']['outputFormat'] = $mode;
			$view->flexDefinition = json_encode($schema);
			$raw = $page->getUnformatted($name)->rawArray();
			$formatted = $field->type->formatValue($page, $view, $raw);
			$this->expect("format $mode shape", true, $mode === 1 ? $formatted->asset instanceof Pagefiles : $formatted->asset instanceof Pagefile);
			$this->expect("format $mode preserves raw envelope", $raw, $formatted->rawArray());
		}
		$files->removeAll();
		$page->save($name);
		$this->expect('removed original collected', false, is_file($original));
		$page = $this->reload($page);
		$this->expect('empty collection reload', 0, count($page->getUnformatted($name)->asset));
		foreach([0, 1, 2] as $mode) {
			$schema['asset']['outputFormat'] = $mode;
			$view->flexDefinition = json_encode($schema);
			$formatted = $field->type->formatValue($page, $view, $page->getUnformatted($name)->rawArray());
			$this->expect("empty format $mode shape", true, $mode === 1 ? $formatted->asset instanceof Pagefiles : $formatted->asset === null);
		}
	}

	private function testLanguages(): void {
		$field = $this->createField(['text' => ['type' => 'text', 'useLanguages' => true]]);
		$page = $this->createPage();
		$name = $field->name;
		$values = [];
		foreach($this->wire()->languages as $language) {
			$values[$language->id] = 'Language ' . $language->id . ' & content';
		}
		$page->$name = ['text' => $values];
		$page->save($name);
		$page = $this->reload($page);
		$value = $page->getUnformatted($name);
		$this->expect('native language object', true, $value->text instanceof LanguagesPageFieldValue);
		foreach($values as $id => $text) {
			$this->expect('translation ' . $id, $text, $value->text->getLanguageValue($id));
		}
		$page->of(true);
		$this->expect('formatted language string', true, is_string($page->get($name)->text));
		$this->expect('language raw values preserved', $value->rawArray(), $page->get($name)->rawArray());
	}

	private function testTinyMCE(): void {
		$field = $this->createField(['body' => ['type' => 'TinyMCE']]);
		$page = $this->createPage();
		$name = $field->name;
		$input = $field->defs($page)->getPropertyInputfield('body');
		$input->attr('name', $name . '__body');
		$input->renderReady();
		$this->expect('native editor renders', true, str_contains($input->render(), $name . '__body'));
		$post = [$name . '__body' => '<p>Portable <strong>rich text</strong></p>'];
		$input->processInput($this->wire()->wire(new WireInputData($post)));
		$this->expect('native editor validation', [], $input->getErrors());
		$page->$name = ['body' => $input->value];
		$page->save($name);
		$page = $this->reload($page);
		$this->expect('rich text persisted', true, str_contains($page->getUnformatted($name)->body, '<strong>rich text</strong>'));
		$page->of(true);
		$this->expect('rich text is not entity encoded', true, str_contains($page->get($name)->body, '<strong>rich text</strong>'));
	}

	private function testRepeater(): void {
		$wire = $this->wire();
		$flex = $this->createField(['heading' => ['type' => 'text']], false);
		$repeaterType = $wire->modules->get('FieldtypeRepeater');
		$repeater = $wire->wire(new RepeaterField());
		$repeater->type = $repeaterType;
		$repeater->repeaterFields = [$flex->id];
		$this->saveField($repeater, true);
		$group = $repeaterType->_getRepeaterTemplate($repeater)->fieldgroup;
		$group->add($flex);
		$group->save();
		$page = $this->createPage();
		$name = $repeater->name;
		foreach(['First', 'Second'] as $heading) {
			$item = $page->get($name)->getNewItem();
			$item->of(false);
			$item->set($flex->name, ['heading' => $heading]);
			$item->save();
		}
		$page->save($name);
		$items = $this->reload($page)->getUnformatted($name);
		$this->expect('native repeater count', 2, count($items));
		$this->expect('first item owned value', 'First', $items->first()->getUnformatted($flex->name)->heading);
		$this->expect('second item isolated value', 'Second', $items->last()->getUnformatted($flex->name)->heading);
		$items->first()->getUnformatted($flex->name)->heading = 'Changed';
		$items->first()->save();
		$items = $this->reload($page)->getUnformatted($name);
		$this->expect('item mutation roundtrip', 'Changed', $items->first()->getUnformatted($flex->name)->heading);
		$this->expect('other item remains independent', 'Second', $items->last()->getUnformatted($flex->name)->heading);
	}

	/** @return void Attempt every owned cleanup even after a section or cleanup fails. */
	public function finish() {
		$errors = [];
		foreach(array_reverse($this->createdPages) as $page) {
			try {
				if($page->id) {
					$this->wire()->pages->delete($page, true);
				}
			} catch(\Throwable $error) {
				$errors[] = $error->getMessage();
			}
		}
		foreach(array_reverse($this->attachments) as [$field, $group]) {
			try {
				$group->remove($field);
				$group->save();
			} catch(\Throwable $error) {
				$errors[] = $error->getMessage();
			}
		}
		foreach(array_reverse($this->createdFields) as $field) {
			try {
				if($field->id) {
					$this->wire()->fields->delete($field);
				}
			} catch(\Throwable $error) {
				$errors[] = $error->getMessage();
			}
		}
		foreach($this->temporaryFiles as $path) {
			if(is_file($path) && !unlink($path)) {
				$errors[] = 'Cannot remove temporary fixture: ' . $path;
			}
		}
		if($errors) {
			throw new WireException(implode("\n", $errors));
		}
		$this->createdPages = [];
		$this->createdFields = [];
		$this->attachments = [];
		$this->temporaryFiles = [];
	}
}
