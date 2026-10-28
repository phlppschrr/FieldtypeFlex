<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$toggle = $createField('flex_toggle_format', ['state' => ['type' => 'Toggle']]);
$nativeType = $wire->modules->get('FieldtypeToggle');
$labels = ['labelType' => 100, 'yesLabel' => 'Yes & <ready> icon-check', 'noLabel' => 'No & waiting', 'otherLabel' => 'Other <state>', 'useOther' => true];
foreach([0, 1, 2, 3, '0', '1', '2', '3'] as $mode) {
	$schema = ['state' => ['type' => 'Toggle', 'formatType' => $mode] + $labels];
	$jsonField = clone $toggle;
	$jsonField->flexDefinition = json_encode($schema);
	$wrapper = $wire->wire(new InputfieldWrapper());
	$input = $wire->modules->get('InputfieldToggle');
	$input->name = 'state';
	foreach($schema['state'] as $key => $setting) if($key !== 'type') $input->set($key, $setting);
	$wrapper->add($input);
	$nativeField = $wire->wire(new Field());
	$nativeField->name = 'toggle_comparison';
	$nativeField->type = $nativeType;
	foreach(['formatType' => $mode] + $labels as $key => $setting) $nativeField->set($key, $setting);
	foreach(['PHP' => $schema, 'JSON' => null, 'wrapper' => $wrapper] as $source => $definition) {
		$defs = new FlexFieldDefs($jsonField, $page, $definition);
		foreach([0, 1, 2, ''] as $key) {
			$value = new FlexValue($page, $toggle, $defs, ['state' => $key]);
			$value->resetTrackChanges();
			$formatted = $type->formatValue($page, $toggle, $value);
			$expected = $nativeType->formatValue($page, $nativeField, $key);
			$same($expected, $formatted->state, "$source mode $mode matches native output for " . var_export($key, true));
			$same($expected, $formatted->state, 'repeated Toggle reads do not double encode');
			$same($key, $formatted->getUnformatted('state'), 'explicit unformatted Toggle read stays raw');
			$same(['state' => $key], $formatted->rawArray(), 'formatted rawArray retains canonical key');
			$same(['state' => $key], json_decode(json_encode($formatted), true), 'JSON serialization retains canonical key');
			$same($key, $value->state, 'formatted clone leaves source unformatted');
			$same(false, $value->isChanged(), 'formatted reads do not dirty raw value');
			$editor = $defs->getInputfields($formatted, 'toggle__')->getChildByName('toggle__state');
			$nativeInput = $nativeField->getInputfield($page);
			$nativeInput->value = $key;
			$same($nativeInput->renderValue(), $editor->renderValue(), 'read-only Toggle matches native safe label display in every output mode');
		}
	}
}

foreach([-1, 4, true, false, null, 1.0, 'yes', '01', [], new \stdClass()] as $invalid) {
	$throws(fn() => new FlexFieldDefs($toggle, $page, ['state' => ['type' => 'Toggle', 'formatType' => $invalid]]), 'invalid Toggle formatType fails definition validation', FlexDefinitionException::class);
}
$invalidInput = $wire->modules->get('InputfieldToggle');
$invalidInput->name = 'state';
$invalidInput->formatType = 4;
$invalidWrapper = $wire->wire(new InputfieldWrapper());
$invalidWrapper->add($invalidInput);
$throws(fn() => new FlexFieldDefs($toggle, $page, $invalidWrapper), 'native wrapper also validates formatType', FlexDefinitionException::class);

$options = [-128 => 'Low <state>', -1 => 'Negative', 0 => 'Draft & waiting', 1 => 'Yes', 2 => 'Other', 5 => 'Approved icon-check', 127 => 'High'];
foreach([0, 1, 2, 3] as $mode) {
	$defs = new FlexFieldDefs($toggle, $page, ['state' => ['type' => 'Toggle', 'formatType' => $mode, 'options' => $options]]);
	foreach(array_keys($options) as $key) {
		$value = new FlexValue($page, $toggle, $defs, ['state' => $key]);
		$value->of(true);
		$expected = $mode === 0 ? $key : ($mode === 1 ? ($key === 0 ? false : ($key === 1 ? true : $key)) : ($mode === 2 ? $options[$key] : $defs->property('state')['input']->formatLabel($options[$key], false)));
		$same($expected, $value->state, "custom key $key survives output mode $mode");
		$same($key, $value->rawArray()['state'], 'custom key remains raw after formatting');
	}
}

$inherited = $wire->wire(new Field());
$inherited->name = 'flex_toggle_format_source';
$inherited->type = $nativeType;
$inherited->formatType = 3;
$inherited->labelType = 100;
$inherited->yesLabel = 'Inherited & approved';
$temporaryFields[] = $inherited;
$wire->fields->save($inherited);
foreach([null, 0, 2] as $override) {
	$settings = ['type' => 'Toggle', 'inheritField' => $inherited->name];
	if($override !== null) $settings['formatType'] = $override;
	$defs = new FlexFieldDefs($toggle, $page, ['state' => $settings]);
	$value = new FlexValue($page, $toggle, $defs, ['state' => 1]);
	$value->of(true);
	$same($override === 0 ? 1 : ($override === 2 ? 'Inherited & approved' : 'Inherited &amp; approved'), $value->state, 'formatType inherits from Field and accepts local override');
}

$persisted = $createField('flex_toggle_format_saved', [
	'state' => ['type' => 'Toggle', 'formatType' => 2, 'options' => $options],
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => [
		'state' => ['type' => 'Toggle', 'formatType' => 1, 'useOther' => true],
	]]]],
]);
$page->of(false);
$page->{$persisted->name} = ['state' => -128];
$page->get($persisted->name)->items->addRow('item', ['state' => 1]);
$page->save($persisted->name);
$page = $wire->pages->getFresh($id);
$page->of(true);
$saved = $page->get($persisted->name);
$same('Low <state>', $saved->state, 'saved Toggle applies string formatting');
$same(true, $saved->items->first()->state, 'nested Row applies boolean formatting');
$same(-128, $saved->rawArray()['state'], 'saved formatted Toggle retains raw custom key');
$same([$id], $wire->pages->findIDs("id=$id, {$persisted->name}.state=-128"), 'selector still matches raw custom key');
$same([$id], $wire->pages->findIDs("id=$id, {$persisted->name}.items.item.state=1"), 'nested selector still matches raw boolean key');
$page->of(false);
