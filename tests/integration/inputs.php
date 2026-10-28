<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Every publicly listed input type can be built and rendered in an actual native wrapper.
$allTypes = [
	'AsmSelect',
	'Checkbox',
	'Checkboxes',
	'Datetime',
	'Email',
	'Float',
	'Hidden',
	'Icon',
	'Integer',
	'Markup',
	'Page',
	'Radios',
	'Select',
	'SelectMultiple',
	'Text',
	'Textarea',
	'TextTags',
	'TinyMCE',
	'Toggle',
	'URL',
];
$allDefinitions = [
	'group' => [
		'type'     => 'fieldset',
		'label'    => 'Group',
		'children' => ['nested' => ['type' => 'text']],
	],
];
foreach($allTypes as $index => $inputType) {
	$definition = [
		'type'  => $inputType,
		'label' => $inputType,
	];
	if(in_array($inputType, ['AsmSelect', 'Checkboxes', 'Radios', 'Select', 'SelectMultiple', 'Toggle'], true)) {
		$definition['options'] = [
			'a' => 'Alpha',
			'b' => 'Beta',
		];
	}
	if($inputType === 'Page') {
		$definition += [
			'inputfield'     => 'InputfieldSelect',
			'derefAsPage'    => 2,
			'parent_id'      => 1,
			'labelFieldName' => 'title',
		];
	}
	if($inputType === 'Markup') {
		$definition['value'] = '<p>Markup example</p>';
	}
	$allDefinitions['input_' . $index] = $definition;
}
$kitchen = $createField('flex_kitchen', $allDefinitions);
$kitchenValue = $type->sanitizeValue($page, $kitchen, [
	'input_16' => 'code design',
	'input_17' => '<p>Hello <strong>world</strong></p>',
]);
$kitchenInput = $kitchen->getInputfield($page);
$kitchenInput->value = $kitchenValue;
$kitchenHtml = $kitchenInput->render();
foreach($allTypes as $index => $inputType) {
	$assert(str_contains($kitchenHtml, $kitchen->name . '__input_' . $index), "native $inputType input renders");
}
$same('code design', $kitchenValue->input_16, 'TextTags value is a string');
$formattedKitchen = $type->formatValue($page, $kitchen, $kitchenValue);
$same('<p>Hello <strong>world</strong></p>', $formattedKitchen->input_17, 'TinyMCE markup is preserved with output formatting');
$widgetDefs = [];
foreach(['PageListSelect', 'PageListSelectMultiple', 'PageAutocomplete'] as $widget) {
	$widgetDefs[strtolower($widget)] = [
		'type'           => 'page',
		'allowUnpub'     => 1,
		'inputfield'     => 'Inputfield' . $widget,
		'derefAsPage'    => $widget === 'PageListSelect' ? 2 : 0,
		'parent_id'      => 1,
		'labelFieldName' => 'title',
	];
}
$widgetField = $createField('flex_widgets', $widgetDefs);
$widgetInput = $widgetField->getInputfield($page);
$widgetInput->value = $type->getBlankValue($page, $widgetField);
$widgetHtml = $widgetInput->render();
foreach($widgetDefs as $name => $definition) {
	$assert(str_contains($widgetHtml, $widgetField->name . '__' . $name), "native {$definition['inputfield']} Page widget renders");
}
$second->addStatus(Page::statusUnpublished);
$second->save();
$references = $type->sanitizeValue($page, $widgetField, [
	'pagelistselect'         => $second->id,
	'pagelistselectmultiple' => [$second->id],
]);
$referencesFormatted = $type->formatValue($page, $widgetField, $references);
$same(0, $referencesFormatted->pagelistselect->id, 'formatted single Page excludes unpublished reference');
$same(0, count($referencesFormatted->pagelistselectmultiple), 'formatted PageArray excludes unpublished references');
$same($second->id, $references->pagelistselect->id, 'formatted Page filtering preserves raw references');
$second->removeStatus(Page::statusUnpublished);
$second->save();

$imageDefaults = $createField('flex_image_default_extensions', [
	'image' => ['type' => 'image'],
]);
$defaultImageExtensions = preg_split(
	'/\s+/',
	strtolower(trim((string) $type->getDefinitions($imageDefaults, $page)->property('image')['settings']['extensions'])),
	-1,
	PREG_SPLIT_NO_EMPTY
);
$same(['jpg', 'jpeg', 'gif', 'png'], $defaultImageExtensions, 'default Flex image uploads use the native ProcessWire image formats');
