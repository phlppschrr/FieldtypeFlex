<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$children = ['heading' => ['type' => 'text', 'required' => true]];
$collection = ['type' => 'rows', 'rowLazyLoad' => true, 'maxItems' => 4, 'types' => ['item' => ['rowTitle' => '{heading}', 'children' => $children]]];
$visibilityField = $createField('flex_visibility', [
	'heading' => ['type' => 'text'],
	'items' => ['minItems' => 2] + $collection,
	'sections' => ['type' => 'rows', 'types' => ['section' => ['children' => ['heading' => ['type' => 'text'], 'children' => $collection]]]],
]);
$name = $visibilityField->name;
$value = $page->getUnformatted($name);
$value->heading = 'Rootneedle';
$a = $value->items->addRow('item', ['heading' => 'Visibleneedle']);
$b = $value->items->addRow('item', ['heading' => 'Hiddenneedle', 'legacy' => ['keep' => true]]);
$parent = $value->sections->addRow('section', ['heading' => 'Parentneedle']);
$child = $parent->children->addRow('item', ['heading' => 'Childneedle']);
$same(false, $a->isHidden(), 'legacy metadata absence means visible');
$page->resetTrackChanges();
$b->setHidden(true);
$parent->setHidden(true);
$assert($page->isChanged($name), 'visibility change marks owning field changed');
$same(false, $child->isHidden(), 'hiding parent does not change child state');
$page->save($name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($name);
$same(true, $value->items->getById($b->rowId())->isHidden(), 'hidden state survives save and reload');
$raw = $value->rawArray();
$formatted = clone $value;
$formatted->of(true);
$same([$a->rowId()], array_keys($formatted->items->getArray()), 'formatted collection exposes only visible rows in order');
$same(1, count($formatted->items), 'formatted count excludes hidden rows');
$same(1, $formatted->items->countOfType('item'), 'formatted type helper excludes hidden rows');
$formatted->items->checkLimit(true);
$assert(true, 'minimum count includes hidden backing rows even in formatted views');
$same(0, count($formatted->sections), 'formatted collection excludes hidden parents');
$same(true, $formatted->hasValue('sections'), 'canonical content check includes hidden rows even when formatted');
$same($raw, $formatted->rawArray(), 'formatted raw export retains hidden descendants and unknown values');
$throws(fn() => $formatted->items->first()->setHidden(true), 'formatted rows reject visibility mutations');
$copy = clone $formatted;
$copy->of(false);
$same(2, count($copy->items), 'unformatting cloned view restores all rows');
$same(1, count($copy->sections->first()->children), 'unformatting restores visible children of hidden parent');
$same($raw, $value->rawArray(), 'formatted and cloned reads leave original canonical data intact');
$page->of(true);
$same(1, count($page->get($name)->items), 'normal Page-formatted access filters row iteration');
$same(2, count($page->getUnformatted($name)->items), 'explicit Page unformatted access includes hidden rows');
$page->of(false);
$nestedView = clone $value;
$nestedView->sections->first()->setHidden(false);
$nestedView->sections->first()->children->first()->setHidden(true);
$nestedRaw = $nestedView->rawArray();
$nestedView->of(true);
$same(1, count($nestedView->sections), 'visible parent remains in formatted collection');
$same(0, count($nestedView->sections->first()->children), 'nested formatted collection filters its own hidden child');
$same($nestedRaw, $nestedView->rawArray(), 'recursive formatting retains full canonical child data');
$page->set($name, $formatted);
$page->save($name);
$same($raw, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'resaving a formatted view retains every hidden row and descendant');
$page = $wire->pages->getFresh($id); $page->of(false); $value = $page->getUnformatted($name);

$find = static fn(string $selector) => $wire->pages->findIDs("id=$id, $selector");
foreach([
	"$name.items.item.heading=Visibleneedle" => [$id],
	"$name.items.item.heading=Hiddenneedle" => [],
	"$name.items.item.heading!=Hiddenneedle" => [$id],
	"$name.items.count=1" => [$id],
	"$name.items.item.count=1" => [$id],
	"$name.items._type=item" => [$id],
	"$name.items.item=\"heading=Visibleneedle\"" => [$id],
	"$name.items.item=\"heading=Hiddenneedle\"" => [],
	"$name.sections.section.children.item.heading=Childneedle" => [],
	"$name.sections.section.children.count=0" => [$id],
	"$name.sections.section.children._type=item" => [],
	"$name.sections.section.children.item.heading!=Childneedle" => [$id],
	"$name.sections.section=\"children.item.heading=Childneedle\"" => [],
	"$name.sections.count=0" => [$id],
	"$name.sections=" => [$id],
	"$name.sections.section.heading=" => [$id],
	"$name.sections._type=" => [$id],
	"$name.sections._type!=section" => [$id],
	"$name*=Hiddenneedle" => [],
	"$name*=Childneedle" => [],
	"$name*=Visibleneedle" => [$id],
	"$name*=Rootneedle" => [$id],
] as $selector => $expected) $same($expected, $find($selector), 'visible projection: ' . $selector);
$same([], $find("$name.items.item.heading=Hiddenneedle, include=all"), 'native include=all does not override Flex row visibility');
$same([$second->id, $id], $wire->pages->findIDs("id=$ids, sort=$name.items.count, sort=id"), 'row count sorting uses visible projection');

// Toggling is transactional with search and row projection, including caller rollback.
$wire->database->beginTransaction();
try {
	$value->items->getById($b->rowId())->setHidden(false);
	$page->save($name);
	$same([$id], $find("$name*=Hiddenneedle"), 'show updates whole-field search immediately');
	$same([$id], $find("$name.items.count=2"), 'show updates row count immediately');
} finally { $wire->database->rollBack(); }
$same([], $find("$name*=Hiddenneedle"), 'rollback restores visibility search projection');
$same($raw, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'rollback restores canonical visibility');
$page = $wire->pages->getFresh($id); $page->of(false); $value = $page->getUnformatted($name);
$duplicate = $value->items->duplicateRow($b->rowId());
$same(true, $duplicate->isHidden(), 'duplicate inherits hidden state');
$same(['keep' => true], $duplicate->rawArray()['legacy'], 'hidden duplicate retains unknown properties');
$value->items->removeRow($duplicate->rowId());
$value->items->getById($a->rowId())->setHidden(true);
$page->save($name);
$same([$id], $find("$name.items="), 'collection containing only hidden rows is empty for queries');
$same([$id], $find("$name.items.item.heading="), 'hidden-only leaves satisfy blank query');
$same([$id], $find("$name.items.item.heading!=Visibleneedle"), 'negative query ignores hidden matching leaf');
$same(2, count($wire->pages->getFresh($id)->getUnformatted($name)->items), 'unformatted collection still contains every hidden row');

$bad = $raw; $bad['items']['items'][0]['hidden'] = 'yes';
$throws(fn() => $type->sanitizeValue($page, $visibilityField, $bad), 'hydration rejects nonboolean visibility');
$throws(fn() => iterator_to_array(FlexStorage::projection($bad)), 'raw projection rejects nonboolean visibility');
$throws(fn() => FlexStorage::search($bad), 'search rejects nonboolean visibility');
$limited = clone $visibilityField; $limited->flexMaxRows = 3;
$throws(fn() => FlexPath::validate($raw, $limited), 'resource budget still counts hidden rows and descendants');

// Real native input processing: toggle a still-unloaded body, then validate/recover.
$page = $wire->pages->getFresh($id); $page->of(false);
$editor = $visibilityField->getInputfield($page); $editor->value = $page->getUnformatted($name);
$html = $editor->render();
$dom = new \DOMDocument(); $old = libxml_use_internal_errors(true);
$dom->loadHTML('<html><body>' . $html . '</body></html>'); libxml_clear_errors(); libxml_use_internal_errors($old);
$post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
$post[$name . '__heading'] = 'Rootneedle';
$post[FlexPath::inputPrefix($name, $parent->address()) . 'heading'] = 'Parentneedle';
$post[FlexPath::controlName($name, $a->address(), 'hidden')] = '0';
$assert(str_contains($html, 'data-flex-loaded="0"'), 'visibility transport renders outside unopened lazy body');
$assert(str_contains($html, 'aria-pressed="true"'), 'hidden header exposes accessible pressed state');
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'unopened hidden row still validates via native baseline inputs');
$page->set($name, $editor->value); $page->save($name);
$same(false, $wire->pages->getFresh($id)->getUnformatted($name)->items->getById($a->rowId())->isHidden(), 'unopened row visibility is saved normally');

// Eager invalid leaf must keep the submitted hidden state and native errors.
$eager = clone $visibilityField;
$def = json_decode($eager->flexDefinition, true); $def['items']['rowLazyLoad'] = false; $eager->flexDefinition = json_encode($def);
$page = $wire->pages->getFresh($id); $page->of(false);
$editor = $eager->getInputfield($page); $editor->value = $page->getUnformatted($name);
$html = $editor->render();
$dom->loadHTML('<html><body>' . $html . '</body></html>'); $post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
$post[$name . '__heading'] = 'Rootneedle';
$post[FlexPath::inputPrefix($name, $parent->address()) . 'heading'] = 'Parentneedle';
$post[FlexPath::inputPrefix($name, $b->address()) . 'heading'] = 'Hiddenneedle';
$post[FlexPath::controlName($name, $a->address(), 'hidden')] = '1';
$post[FlexPath::inputPrefix($name, $a->address()) . 'heading'] = '';
$editor->processInput($wire->wire(new WireInputData($post)));
$assert(count($editor->getErrors()) > 0, 'hiding row does not bypass required validation');
$recovery = $editor->render();
$assert(str_contains($recovery, 'name="' . FlexPath::controlName($name, $a->address(), 'hidden') . '" value="1"'), 'invalid-save recovery retains submitted hidden state');
$same(false, $wire->pages->getFresh($id)->getUnformatted($name)->items->getById($a->rowId())->isHidden(), 'failed validation leaves stored visibility intact');

// Controlled internal repair derives both indexes from locked canonical data.
$rawBeforeRepair = $wire->pages->getFresh($id)->getUnformatted($name)->rawArray();
$wire->database->exec("UPDATE `{$visibilityField->table}` SET search='stale hidden projection' WHERE pages_id=$id");
$type->storage()->rebuildRows($visibilityField);
$same($rawBeforeRepair, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'projection repair never changes canonical visibility');
$same([], $find("$name*=stale hidden projection"), 'bounded repair rebuilds search together with rows');
$same([$id], $find("$name*=Visibleneedle"), 'bounded repair restores visible whole-field terms');
