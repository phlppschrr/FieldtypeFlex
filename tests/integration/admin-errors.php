<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$selectorErrorField = clone $field;
$selectorErrorField->name = 'flex_native_selector_error';
$selectorErrorField->flexDefinition = json_encode([
	'category' => [
		'type'              => 'page',
		'inputfield'        => 'InputfieldSelect',
		'derefAsPage'       => 2,
		'findPagesSelector' => 'flex_probe_unknown_property=1',
	],
]);
$selectorPage = $wire->pages->newNullPage();
$selectorInput = $wire->modules->get('InputfieldFlex');
$selectorInput->name = $selectorErrorField->name;
$selectorInput->hasPage = $selectorPage;
$selectorInput->hasField = $selectorErrorField;
$selectorInput->value = $type->getBlankValue($selectorPage, $selectorErrorField);
$previousProcess = $wire->process;
try {
	$wire->set('process', 'ProcessPageEdit');
	$selectorInput->renderReady();
	$selectorHtml = $selectorInput->render();
	$assert(str_contains($selectorHtml, 'Editing is disabled'), 'native Page selector configuration error shows a locked field instead of failing the editor');
	$same(1, count($selectorInput->getErrors()), 'preloading and rendering report one native configuration error');
	$selectorInput->renderValue();
	$same(1, count($selectorInput->getErrors()), 'read-only rendering does not repeat the same configuration error');
} finally {
	$wire->set('process', $previousProcess);
}
// Central recovery must not turn operational failures or unrelated bugs into definitions.
foreach([new \PDOException('Database unavailable'), new WirePermissionException('Permission denied'), new \TypeError('Unrelated input bug')] as $failure) {
	$operationalInput = $field->getInputfield($page);
	$operationalInput->value = $page->getUnformatted($field->name);
	$operationalInput->inputs()->addHookBefore('render', static function() use ($failure): void {
		throw $failure;
	});
	$previousProcess = $wire->process;
	try {
		$wire->set('process', 'ProcessPageEdit');
		$throws(fn() => $operationalInput->render(), 'editor recovery propagates ' . get_class($failure), get_class($failure), $failure->getMessage());
		$same([], $operationalInput->getErrors(), 'operational failures are not reported as definition errors');
	} finally {
		$wire->set('process', $previousProcess);
	}
}
$configErrorField = clone $field;
$configErrorField->name = 'flex_config_error_overview';
$configErrorField->flexDefinition = '{';
$configInputs = $type->getConfigInputfields($configErrorField);
$configPreview = $configInputs->getChildByName('flexDefinitionStatus');
$assert($configPreview instanceof InputfieldMarkup && str_contains($configPreview->value, 'Technical cause') &&
	str_contains($configPreview->value, 'JsonException'), 'field configuration shows the technical cause behind a definition error');

$validConfig = $type->getConfigInputfields($field);
$assert($validConfig->getChildByName('flexSource') instanceof InputfieldFieldset, 'definition settings use a native fieldset');
$same(Inputfield::collapsedBlank, $validConfig->getChildByName('flexDefinition')->collapsed, 'inline JSON uses native blank collapse behavior');
$same(Inputfield::collapsedYes, $validConfig->getChildByName('flexPhpDoc')->collapsed, 'PHPDoc is optional and collapsed');
$assert(!str_contains($validConfig->render(), 'FieldtypeFlex-example'), 'field settings render without embedded example source');
$assert(!str_contains($validConfig->getChildByName('flexDefinitionStatus')->value, 'flex-fields/flex_test.php'), 'inline definitions do not list unused automatic search paths');

$pageSelectorSchema = [
	'items' => [
		'type'       => 'rows',
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => [
					'target' => [
						'type'           => 'page',
						'inputfield'     => 'InputfieldSelect',
						'derefAsPage'    => 2,
						'parent_id'      => 1,
						'labelFieldName' => 'title',
					],
				],
			],
		],
	],
];
$repeatableSelectorField = $createField('flex_repeatable_native_selector_error', $pageSelectorSchema);
$repeatableSelectorPageId = $second->id;
$repeatableSelectorPage = $wire->pages->getFresh($repeatableSelectorPageId);
$repeatableSelectorPage->of(false);
$repeatableRowId = bin2hex(random_bytes(16));
$repeatableSelectorPage->set($repeatableSelectorField->name, [
	'items' => [
		'_flex' => 'rows:1',
		'items' => [
			[
				'id'     => $repeatableRowId,
				'type'   => 'item',
				'values' => ['target' => 1],
			],
		],
	],
]);
$repeatableSelectorPage->save($repeatableSelectorField->name);
$repeatableSelectorBaseline = $wire->pages->getFresh($repeatableSelectorPageId)->getUnformatted($repeatableSelectorField->name)->rawArray();
$pageSelectorSchema['items']['types']['item']['children']['target']['findPagesSelector'] = ['bad'];
$repeatableSelectorField->flexDefinition = json_encode($pageSelectorSchema);
$wire->fields->save($repeatableSelectorField);
$repeatableSelectorPage = $wire->pages->getFresh($repeatableSelectorPageId);
$repeatableSelectorPage->of(false);
$repeatableSelectorInput = $repeatableSelectorField->getInputfield($repeatableSelectorPage);
$repeatableSelectorInput->value = $repeatableSelectorPage->getUnformatted($repeatableSelectorField->name);
$repeatableSelectorDraft = $type->admin()->open($repeatableSelectorInput->value, $repeatableSelectorInput->name);
$repeatableSelectorPrefix = FlexPath::inputPrefix($repeatableSelectorInput->name, 'items.' . $repeatableRowId);
$repeatableSelectorPost = [
	$repeatableSelectorInput->name . '__0draft'          => $repeatableSelectorDraft,
	$repeatableSelectorInput->name . '__0complete'          => $repeatableSelectorDraft,
	FlexPath::controlName($repeatableSelectorInput->name, 'items', 'present') => '1',
	FlexPath::controlName($repeatableSelectorInput->name, 'items', 'order')   => $repeatableRowId,
	$repeatableSelectorPrefix . 'target'                => '1',
];
$previousProcess = $wire->process;
$repeatableSelectorTitle = $repeatableSelectorPage->title;
try {
	$wire->set('process', 'ProcessPageEdit');
	$repeatableSelectorInput->processInput($wire->wire(new WireInputData($repeatableSelectorPost)));
	$assert(count($repeatableSelectorInput->getErrors()) > 0, 'repeatable native Page configuration error reaches the containing field error path');
	$same($repeatableSelectorBaseline, $repeatableSelectorInput->value->rawArray(), 'repeatable native configuration POST preserves raw rows');
	$repeatableSelectorPage->title = 'Must not save after repeatable configuration error';
	try {
		$wire->pages->save($repeatableSelectorPage);
		$assert(false, 'repeatable native configuration error blocks the ProcessPageEdit save');
	} catch(WireException $error) {
		$same($repeatableSelectorInput->getErrors()[0], $error->getMessage(), 'repeatable configuration save reuses the editor definition error');
	}
	$savedRepeatableSelectorPage = $wire->pages->getFresh($repeatableSelectorPageId);
	$same($repeatableSelectorTitle, $savedRepeatableSelectorPage->title, 'repeatable native configuration error blocks unrelated page changes');
	$same(
		$repeatableSelectorBaseline,
		$savedRepeatableSelectorPage->getUnformatted($repeatableSelectorField->name)->rawArray(),
		'repeatable native configuration error leaves persisted JSON untouched'
	);
} finally {
	$wire->set('process', $previousProcess);
	$template->fieldgroup->remove($repeatableSelectorField);
	$template->fieldgroup->save();
}
$autoField = clone $field;
$autoField->name = 'flex_auto_symlink_' . bin2hex(random_bytes(3));
$autoField->flexDefinition = '';
$autoField->flexDefinitionFile = '';
$autoPath = $wire->config->paths->templates . 'flex-fields/' . $autoField->name . '.php';
$outsidePath = $wire->config->paths->site . 'assets/flex-auto-symlink-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($outsidePath, '<?php namespace ProcessWire; return ["outside" => ["type" => "text"]];');
if(!symlink($outsidePath, $autoPath)) {
	throw new \RuntimeException('Unable to create definition symlink test fixture');
}
try {
	$source = FlexFieldDefs::inspectSource($autoField, $wire);
	$same('file', $source['kind'], 'automatic lookup selects an existing symlink before path validation');
	$assert(str_contains($source['error'], 'inside site/templates'), 'automatic definition symlink outside templates is rejected');
	$throws(fn() => $type->getDefinitions($autoField, $page), 'automatic definition symlink cannot become an empty schema');
} finally {
	unlink($autoPath);
	unlink($outsidePath);
}
$guardField = $createField('flex_invalid_save', ['stored' => ['type' => 'text']]);
$guardField->flexDefinition = '{';
$guardPage = $wire->pages->getFresh($id);
$guardPage->of(false);
$previousProcess = $wire->process;
$originalTitle = $guardPage->title;
try {
	$wire->set('process', 'ProcessPageEdit');
	$invalid = $type->getBlankValue($guardPage, $guardField);
	$assert($invalid instanceof FlexInvalidValue, 'ProcessPageEdit retains a read-only invalid value');
	$guardPage->set($guardField->name, $invalid);
	$guardPage->title = 'Must not persist while Flex is invalid';
	try {
		$wire->pages->save($guardPage);
		$assert(false, 'invalid Flex definition blocks the page-level ProcessPageEdit save');
	} catch(WireException $error) {
		$same($invalid->definitionError()->getMessage(), $error->getMessage(), 'page save surfaces the same field error without a success path');
	}
	$same($originalTitle, $wire->pages->getFresh($id)->title, 'invalid Flex definition prevents unrelated page values from being saved');
} finally {
	$wire->set('process', $previousProcess);
}
$template->fieldgroup->remove($guardField);
$template->fieldgroup->save();
