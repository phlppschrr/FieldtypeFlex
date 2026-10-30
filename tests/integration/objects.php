<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$object = ['type' => 'object', 'children' => [
	'label' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
	'number' => ['type' => 'integer'],
	'enabled' => ['type' => 'checkbox'],
	'detail' => ['type' => 'object', 'children' => ['label' => ['type' => 'text', 'showIf' => 'parent.enabled=1']]],
]];
$definition = [
	'link' => $object,
	'other' => $object,
	'empty' => ['type' => 'object', 'children' => []],
	'panel' => ['type' => 'object', 'children' => [
		'blocks' => ['type' => 'rows', 'types' => ['text' => ['children' => [
			'link' => $object,
			'box' => ['type' => 'object', 'children' => [
				'notes' => ['type' => 'rows', 'types' => ['note' => ['children' => ['text' => ['type' => 'text']]]]],
			]],
		]]]],
	]],
];
$f = $createField('flex_objects', $definition);
$name = $f->name;
$page = $wire->pages->getFresh($id); $page->of(false);
$page->$name = ['link' => ['label' => '<Link>', 'number' => 0, 'legacy' => 'retain'], 'other' => ['label' => 'Other']];
$v = $page->$name;
$assert($v->link instanceof FlexValue, 'object reuses FlexValue');
$same('<Link>', $v->link->label, 'object hydrates a property map');
$same('Other', $v->other->label, 'objects isolate identical child names');
$assert(!$v->hasValue('empty'), 'empty object has no logical content');
$assert($v->hasValue('link'), 'object content includes its leaves');
$row = $v->panel->blocks->addRow('text', ['link' => ['label' => 'Row link']]);
$note = $row->box->notes->addRow('note', ['text' => 'Deep']);
$page->save($name);
$page = $wire->pages->getFresh($id); $page->of(false); $v = $page->$name;
$same('Deep', $v->panel->blocks->first()->box->notes->first()->text, 'mixed objects and rows survive persistence');
$same(0, $v->link->number, 'object preserves numeric zero');
$same('retain', $v->rawArray()['link']['values']['legacy'], 'unknown object leaves survive save');
$document = $type->storage()->document($page, $f);
$assert($document->empty->values instanceof \stdClass, 'empty object map is stored as JSON object');
[$owner, $leaf] = FlexPath::resolve($v, 'panel.blocks.' . $row->rowId() . '.box.notes');
$same('notes', $leaf, 'mixed path resolves leaf');
$same($v->panel->blocks->first()->box, $owner, 'mixed path resolves actual owner');
$page->resetTrackChanges(); $v->resetTrackChanges(); $v->link->detail->label = 'Changed';
$assert($v->isChanged('link') && $page->isChanged($name), 'deep object mutation tracks root and owning page');
$copy = clone $v; $copy->link->label = 'Copy';
$same('<Link>', $v->link->label, 'cloned object values are independent');
$formatted = clone $v; $formatted->of(true);
$same('&lt;Link&gt;', $formatted->link->label, 'object propagates output formatting');
$throws(fn() => $formatted->link->set('label', 'Forbidden'), 'formatted object rejects writes');
$rawView = $formatted->getUnformatted('link');
$same('<Link>', $rawView->label, 'explicit unformatted object reads bypass formatters');
$throws(fn() => $rawView->set('label', 'Forbidden'), 'unformatted object view keeps write guard');
$throws(fn() => $v->set('link', ['bad', 'list']), 'object rejects list assignment');
$page->save($name);
$same([$id], $wire->pages->findIDs("id=$ids, $name.link.label=\"<Link>\""), 'object scalar selector');
$same([$id], $wire->pages->findIDs("id=$ids, $name.link.number=0"), 'object numeric selector');
$same([$id], $wire->pages->findIDs("id=$ids, $name.panel.blocks.text.link.label=Row link"), 'object leaf inside row selector');
$same([$id], $wire->pages->findIDs("id=$ids, $name.panel.blocks.text.box.notes.note.text=Deep"), 'mixed nested row selector');
$same([$id], $wire->pages->findIDs("id=$ids, @$name.panel.blocks.text.link.label=Row link, @$name.panel.blocks.text.box.notes.note.text=Deep"), 'same-row grouping through object scopes');
$same('number', $type->getSelectorInfo($f)['subfields']['link.number']['input'], 'selector discovery includes object leaves');
$same([$id], $wire->pages->findIDs("id=$id, sort=$name.link.number"), 'object scalar sorting');
$inputs = $type->getDefinitions($f, $page)->getInputfields($v, $name . '__', '', $v, null, $name);
$prefix = FlexPath::inputPrefix($name, 'link');
$assert($inputs->getChildByName($prefix . 'label') instanceof InputfieldText, 'native fieldset contains scoped child input');
$post = [$prefix . 'label' => 'Submitted', $prefix . 'number' => '42', $prefix . 'enabled' => '1'];
$data = new WireInputData($post);
$form = FlexDependencies::processInput($inputs, $page, $data);
InputfieldFlex::applyInputs($v, $form, $name . '__');
$same('Submitted', $v->link->label, 'native processing applies object child');
$same(42, $v->link->number, 'native processing normalizes child');
$page->save($name);
$same('Submitted', $wire->pages->getFresh($id)->getUnformatted($name)->link->label, 'processed object persists');

// Unknown object subtrees retain media ownership and row identities without a schema.
$mediaDefinition = ['box' => ['type' => 'object', 'children' => [
	'file' => ['type' => 'file', 'extensions' => 'txt'],
	'label' => ['type' => 'text'],
]]];
$mf = $createField('flex_object_media', $mediaDefinition); $mn = $mf->name;
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/object-media.txt'); file_put_contents($fixture, 'Object media');
$page = $wire->pages->getFresh($id); $page->of(false);
$page->$mn->box->file->add($fixture); $page->$mn->box->label = 'Keep'; $page->save($mn);
$files = $wire->pages->getFresh($id)->getUnformatted($mn)->box->file;
$asset = $files->first()->assetId(); $filename = $files->first()->filename();
$same('box.file', $type->media()->asset($asset)['address'], 'object media uses complete property address');
$assert(is_file($filename), 'object media original survives commit');
$files->first()->description = 'Selective metadata'; $files->first()->save();
$same('Selective metadata', $wire->pages->getFresh($id)->getUnformatted($mn)->box->file->first()->description, 'selective media save resolves object path');
$mediaDefinition['box']['children']['caption'] = ['type' => 'text', 'renamedFrom' => 'label'];
unset($mediaDefinition['box']['children']['label']); $mf->flexDefinition = json_encode($mediaDefinition); $mf->save();
$preview = $type->checkRenames($mf);
$assert($preview['success'], 'object rename preview succeeds');
$same(1, $type->applyRenames($mf, $preview)['changed'], 'raw maintenance renames object child');
$same('Keep', $wire->pages->getFresh($id)->getUnformatted($mn)->box->caption, 'renamed object child reads back');
$mf->flexDefinition = json_encode(['note' => ['type' => 'text']]); $mf->save();
$page = $wire->pages->getFresh($id); $page->of(false); $page->$mn->note = 'Retain unknown object'; $page->save($mn);
$assert(is_file($filename) && $type->media()->asset($asset) !== null, 'unknown object retains owned media on ordinary save');
$orphans = $type->listOrphans($mf);
$preview = $type->cleanupProperties($mf, ['box'], $orphans);
$same(1, $type->cleanupProperties($mf, ['box'], $preview, true)['changed'], 'explicit cleanup removes unknown object');
$wire->finished(); $wire->finished();
clearstatcache();
$assert($type->media()->asset($asset) === null && !is_file($filename), 'object cleanup collects only removed owned asset');

// The same built-in leaf pipelines run at object scope.
$sf = $createField('flex_object_scalars', ['outer' => ['type' => 'object', 'children' => [
	'inner' => ['type' => 'object', 'children' => [
		'localized' => ['type' => 'text', 'useLanguages' => true],
		'body' => ['type' => 'textarea', 'contentType' => FieldtypeTextarea::contentTypeHTML],
		'ref' => ['type' => 'Page', 'derefAsPage' => 2],
		'files' => ['type' => 'file', 'extensions' => 'txt'],
		'rows' => ['type' => 'rows', 'types' => ['item' => ['children' => ['text' => ['type' => 'text']]]]],
	]],
]]]); $sn = $sf->name;
$page = $wire->pages->getFresh($id); $page->of(false);
$page->$sn->outer->inner->localized = 'Default';
$page->$sn->outer->inner->localized->setLanguageValue($language, 'Translated');
$page->$sn->outer->inner->body = '<p><a href="' . $page->url . '">Internal</a></p>';
$page->$sn->outer->inner->ref = $second;
$page->$sn->outer->inner->files->add($fixture);
$page->$sn->outer->inner->rows->addRow('item', ['text' => 'Nested group']);
$page->save($sn);
$v = $wire->pages->getFresh($id)->getUnformatted($sn);
$same('Translated', $v->outer->inner->localized->getLanguageValue($language), 'nested object multilingual text survives');
$same($second->id, $v->outer->inner->ref->id, 'nested object native Page reference survives');
$same([$id], $wire->pages->findIDs("id=$ids, $sn.outer.inner.ref=$second->id"), 'nested object Page ID selector');
$same([$id], $wire->pages->findIDs("id=$ids, $sn.outer.inner.ref.title=" . $wire->sanitizer->selectorValue($second->title)), 'nested object Page traversal selector');
$same([$id], $wire->pages->findIDs("id=$ids, $sn.outer.inner.files.count=1"), 'nested object media count selector');
$same([$id], $wire->pages->findIDs("id=$ids, @$sn.outer.inner.rows.item.text=Nested group"), 'same-row group beneath multiple objects');
$throws(fn() => $wire->pages->find("id=$id, sort=$sn.outer.inner.rows.item.text"), 'object ancestors cannot make repeated leaf sorting unambiguous');

$same([$id], $wire->pages->findIDs("id=$id, sort=$sn.outer.inner.files.count"), 'object media count sorting');
$same([$id], $wire->pages->findIDs("id=$id, sort=$sn.outer.inner.rows.count"), 'object row count sorting');

$page = $wire->pages->getFresh($id); $page->of(false); $page->$name->link->number = 10; $page->save($name);
$second = $wire->pages->getFresh($second->id); $second->of(false); $second->$name = ['link' => ['number' => 2]]; $second->save($name);
$same([$second->id, $id], $wire->pages->findIDs("id=$ids, sort=$name.link.number"), 'object sorting compares numeric leaf rather than serialized container');
$same([$id, $second->id], $wire->pages->findIDs("id=$ids, sort=-$name.link.number"), 'descending object scalar sorting');

$same(1, $wire->pages->getFresh($id)->getUnformatted($name)->panel->blocks->find('link.label=Row link')->count(), 'in-memory row search traverses fixed object leaves');

$source = $wire->pages->getFresh($id)->getUnformatted($sn);
$target = $wire->pages->getFresh($second->id); $target->of(false);
$target->$sn->outer = $source->outer; $target->save($sn);
$copy = $wire->pages->getFresh($second->id)->getUnformatted($sn)->outer;
$assert($copy->inner->files->first()->assetId() !== $source->outer->inner->files->first()->assetId(), 'assigned object copies media to independent ownership');
$assert($copy->inner->rows->first()->rowId() !== $source->outer->inner->rows->first()->rowId(), 'assigned object copies descendant row identities');
$same(file_get_contents($fixture), file_get_contents($copy->inner->files->first()->filename()), 'assigned object preserves file bytes');
$wire->database->beginTransaction();
try {
	$target->$sn->outer->inner->rows->addRow('item', ['text' => 'Rollback']); $target->save($sn);
} finally { $wire->database->rollBack(); }
$same(1, $wire->pages->getFresh($target->id)->getUnformatted($sn)->outer->inner->rows->count(), 'object descendants retain atomic persistence on rollback');
$throws(fn() => new FlexFieldDefs($f, $page, ['bad' => ['type' => 'object']]), 'object requires explicit children');
$throws(fn() => new FlexFieldDefs($f, $page, ['bad' => ['type' => 'object', 'children' => [], 'types' => []]]), 'object rejects row-specific settings');
$throws(fn() => $source->outer->set('inner', ['_flex' => FlexStorage::OBJECT_FORMAT, 'values' => null]), 'malformed object envelope is rejected without defaulting away data');
