<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$stored = $createField('flex_maintenance_repeater', ['old_heading' => ['type' => 'text'], 'obsolete' => ['type' => 'text']]);
$repeater = $wire->wire(new RepeaterField());
$repeater->name = 'flex_maintenance_items_' . bin2hex(random_bytes(3));
$repeater->type = $repeaterType;
$repeater->repeaterFields = [$stored->id];
$repeater->save(); $temporaryFields[] = $repeater;
$itemTemplate = $repeaterType->_getRepeaterTemplate($repeater);
if(!$itemTemplate->fieldgroup->hasField($stored)) { $itemTemplate->fieldgroup->add($stored); $itemTemplate->fieldgroup->save(); }
$template->fieldgroup->add($repeater); $template->fieldgroup->save();
$page = $wire->pages->getFresh($id); $page->of(false);
$itemIds = [];
foreach(['First', 'Second'] as $label) {
	$item = $page->get($repeater->name)->getNewItem(); $item->of(false);
	$item->set($stored->name, ['old_heading' => $label, 'obsolete' => 'Remove selected']);
	$item->save(); $itemIds[] = (int) $item->id;
}
$page->save($repeater->name);
$stored->flexDefinition = json_encode(['heading' => ['type' => 'text', 'renamedFrom' => 'old_heading']]); $stored->save();
$preview = $type->checkRenames($stored, 0, 1);
$same(1, $preview['checked'], 'native Repeater maintenance respects batch boundaries');
$same(false, $preview['complete'], 'native Repeater inventory exposes continuation');
$same(1, $type->applyRenames($stored, $preview)['changed'], 'native Repeater first item migrates');
$preview = $type->checkRenames($stored, $preview['lastPageId'], 1);
$same(1, $type->applyRenames($stored, $preview)['changed'], 'native Repeater continuation migrates second item');
$same(true, $preview['complete'], 'native Repeater continuation completes');
$inventory = $type->listOrphans($stored);
$same($itemIds, $inventory['properties']['obsolete']['pageIds'], 'orphan inventory includes actual native Repeater owner IDs');
$same(2, $inventory['properties']['obsolete']['pageCount'], 'orphan inventory aggregates native Repeater owners');
$cleanup = $type->cleanupProperties($stored, ['obsolete'], $inventory);
$same(2, $type->cleanupProperties($stored, ['obsolete'], $cleanup, true)['changed'], 'selected cleanup updates native Repeater field storage');
foreach($itemIds as $n => $itemId) {
	$raw = $type->storage()->persisted($wire->pages->getFresh($itemId), $stored);
	$same(['heading' => ['First', 'Second'][$n]], $raw, 'native Repeater maintenance readback retains the renamed value only');
}
$cli = new FlexMaintenanceCli($type);
foreach($itemIds as $itemId) {
	ob_start(); $status = $cli->run(['check', $stored->name, '--page=' . $itemId, '--json']); $json = ob_get_clean();
	$same(0, $status, 'CLI exact selection supports a native Repeater item');
	$same($itemId, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['pageId'], 'CLI retains exact Repeater owner identity');
}
