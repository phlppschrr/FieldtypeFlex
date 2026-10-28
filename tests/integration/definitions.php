<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Definitions: file sources, scope, wrapper, validation, and loss-aware rename.
$testPath = $wire->config->paths->templates . 'flex-fields/flex-integration.php';
file_put_contents($testPath, '<?php namespace ProcessWire; return ["context" => ["type" => "text", "value" => $field->name . ":" . $page->id]];');
$fileField = clone $field;
$fileField->flexDefinition = '';
$fileField->flexDefinitionFile = 'flex-fields/flex-integration.php';
$same("flex_test:$id", $type->getBlankValue($page, $fileField)->context, 'PHP receives page and field context');
copy(dirname(__DIR__, 2) . '/examples/objects.php', $testPath);
$optionsPath = $wire->config->paths->templates . 'flex-fields/options.php';
$createdOptionsHelper = !is_file($optionsPath);
if($createdOptionsHelper) {
	copy(dirname(__DIR__, 2) . '/examples/options.php', $optionsPath);
}
try {
	$objectDefs = $type->getDefinitions($fileField, $page);
	$assert(isset($objectDefs->properties()['email']), 'InputfieldWrapper definitions');
	$assert($objectDefs->getPropertyInputfield('email') instanceof InputfieldEmail, 'defs inputfield metadata API');
} finally {
	if($createdOptionsHelper) {
		unlink($optionsPath);
	}
}
unlink($testPath);
$fileField->flexDefinitionFile = '../config.php';
$throws(fn() => $type->getDefinitions($fileField, $page), 'definition path traversal rejected');
$bad = clone $field;
$bad->flexDefinition = '{';
$throws(fn() => $type->getDefinitions($bad, $page), 'invalid definition JSON rejected');
$bad->flexDefinition = json_encode([
	'a'     => ['type' => 'text'],
	'group' => [
		'type'     => 'fieldset',
		'children' => ['a' => ['type' => 'text']],
	],
]);
$throws(fn() => $type->getDefinitions($bad, $page), 'duplicate properties rejected');
$bad->flexDefinition = json_encode(['password' => ['type' => 'Password']]);
$throws(fn() => $type->getDefinitions($bad, $page), 'unsupported storage-coupled input rejected');

// Structure declarations share one contract across PHP arrays, JSON and native trees.
$rowsSchema = [
	'address' => ['type' => 'fieldset', 'children' => ['city' => ['type' => 'text']]],
	'blocks' => [
		'type' => 'rows', 'addRowLabel' => 'Add block', 'rowCollapse' => 3,
		'minItems' => 1, 'maxItems' => 2,
		'types' => ['text' => [
			'rowTitle' => '#n: {heading}',
			'children' => [
				'heading' => ['type' => 'text'],
				'notes' => ['type' => 'rows', 'types' => ['note' => ['children' => ['text' => ['type' => 'text']]]]],
			],
		]],
	],
];
$nativeRowsTree = $wire->wire(new InputfieldWrapper());
$nativeGroup = $wire->modules->get('InputfieldFieldset');
$nativeGroup->name = 'address';
$city = $wire->modules->get('InputfieldText');
$city->name = 'city';
$nativeGroup->add($city);
$nativeRowsTree->add($nativeGroup);
$nativeRows = $wire->modules->get('InputfieldFlexRows');
$nativeRows->name = 'blocks';
foreach($rowsSchema['blocks'] as $key => $setting) {
	if($key !== 'type') $nativeRows->set($key, $setting);
}
// Use native children and a native nested collection as well.
$nativeChildren = $wire->wire(new InputfieldWrapper());
$heading = $wire->modules->get('InputfieldText');
$heading->name = 'heading';
$nativeChildren->add($heading);
$notes = $wire->modules->get('InputfieldFlexRows');
$notes->name = 'notes';
$notes->types = $rowsSchema['blocks']['types']['text']['children']['notes']['types'];
$nativeChildren->add($notes);
$nativeRows->types = ['text' => ['rowTitle' => '#n: {heading}', 'children' => $nativeChildren]];
$nativeRowsTree->add($nativeRows);
$rowsField = clone $field;
$rowsField->flexDefinition = json_encode($rowsSchema, JSON_THROW_ON_ERROR);
foreach(['PHP' => $rowsSchema, 'JSON' => null, 'native' => $nativeRowsTree] as $format => $source) {
	$defs = new FlexFieldDefs($rowsField, $page, $source);
	$same(['city', 'blocks'], array_keys($defs->properties()), "$format fieldsets flatten and rows introduce a property");
	$same('rows', $defs->property('blocks.text.notes')['kind'], "$format supports nested rows");
	$metadata = $defs->getPropertyInputfield('blocks');
	$assert($metadata instanceof InputfieldFlexRows, "$format rows metadata uses the rows Inputfield");
	$settings = $defs->property('blocks')['settings'];
	$same([1, 2, 3, 'Add block'], [$settings['minItems'], $settings['maxItems'], $settings['rowCollapse'], $settings['addRowLabel']], "$format preserves collection settings");
	$same('#n: {heading}', $defs->rowType('blocks', 'text')['settings']['rowTitle'], "$format preserves type-specific rowTitle");
	$same('rows', $defs->structure()[1]['kind'], "$format configuration summary exposes rows");
	$value = new FlexValue($page, $rowsField, $defs, ['city' => 'Berlin']);
	$row = $value->blocks->addRow('text', ['heading' => 'Intro']);
	$row->notes->addRow('note', ['text' => 'Nested']);
	$same('rows:1', $value->rawArray()['blocks']['_flex'], "$format keeps canonical format");
	$same('Nested', $row->notes->first()->text, "$format native nested schema supplies runtime rows");
	$assert($defs->getInputfields($value, 'test__')->getChildByName('test__blocks') instanceof InputfieldFlexRows, "$format creates the rows editor");
}

foreach(['repeatable', 'repeaterTitle', 'repeaterCollapse', 'repeaterAddLabel'] as $legacyKey) {
	foreach(['fieldset', 'rows', 'text'] as $inputType) {
		$settings = ['type' => $inputType, $legacyKey => false];
		$throws(fn() => new FlexFieldDefs($field, $page, ['invalid' => $settings]), "rejects $legacyKey on $inputType", FlexDefinitionException::class, $legacyKey);
	}
	$invalidNative = $wire->wire(new InputfieldWrapper());
	$legacyInput = $wire->modules->get('InputfieldFlexRows');
	$legacyInput->name = 'invalid';
	$legacyInput->set($legacyKey, false);
	$invalidNative->add($legacyInput);
	$throws(fn() => new FlexFieldDefs($field, $page, $invalidNative), "native rows reject $legacyKey", FlexDefinitionException::class, $legacyKey);
}
foreach([
	['type' => 'rows'],
	['type' => 'fieldset', 'types' => ['text' => ['children' => []]]],
	['types' => ['text' => ['children' => []]]],
	['type' => 'text', 'rowCollapse' => 0],
	['type' => 'fieldset', 'addRowLabel' => 'Add'],
	['type' => 'rows', 'children' => []],
	['type' => 'rows', 'rowTitle' => '{heading}'],
	['type' => 'rows', 'inheritField' => 'title'],
	['type' => 'FlexRows'],
	['type' => 'InputfieldFlexRows'],
] as $invalid) {
	$throws(fn() => new FlexFieldDefs($field, $page, ['invalid' => $invalid]), 'ambiguous structure declaration rejected', FlexDefinitionException::class);
}
$invalidType = $rowsSchema;
$invalidType['blocks']['types']['text']['repeaterTitle'] = '{heading}';
$throws(fn() => new FlexFieldDefs($field, $page, $invalidType), 'legacy type title rejected', FlexDefinitionException::class, 'repeaterTitle');
$legacySchema = ['blocks' => ['type' => 'fieldset', 'repeatable' => true, 'types' => ['text' => ['children' => []]]]];
$legacyField = clone $field;
$legacyField->flexDefinition = json_encode($legacySchema, JSON_THROW_ON_ERROR);
$legacyWrapper = $wire->wire(new InputfieldWrapper());
$legacyFieldset = $wire->modules->get('InputfieldFieldset');
$legacyFieldset->name = 'blocks';
$legacyFieldset->repeatable = true;
$legacyFieldset->types = $legacySchema['blocks']['types'];
$legacyWrapper->add($legacyFieldset);
foreach(['PHP' => $legacySchema, 'JSON' => null, 'native' => $legacyWrapper] as $format => $source) {
	$throws(fn() => new FlexFieldDefs($legacyField, $page, $source), "$format rejects repeatable fieldsets", FlexDefinitionException::class, 'repeatable');
}
