<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

if(PHP_SAPI !== 'cli' || !isset($same, $page, $createField)) {
	http_response_code(404);
	exit;
}

$rowUiPreviousLanguage = $wire->user->language;
$wire->user->language = $wire->languages->getDefault();

$rowUiSchema = [
	'slides' => [
		'type'        => 'rows',
		'label'       => 'Slides',
		'rowCollapse' => 0,
		'addRowLabel' => 'Add slide',
		'maxItems'    => 3,
		'types'       => [
			'item' => [
				'label'    => 'Item',
				'rowTitle' => '#n: {heading} ({price})',
				'children' => [
					'heading' => [
						'type'     => 'text',
						'required' => true,
					],
					'price' => ['type' => 'integer'],
					'mode'  => [
						'type'    => 'select',
						'options' => ['a' => 'Alpha', 'b' => 'Beta'],
					],
					'summary' => [
						'type'         => 'text',
						'useLanguages' => true,
					],
				],
			],
		],
	],
];
$rowUiField = $createField('flex_row_ui', $rowUiSchema);
$page = $wire->pages->getFresh($id);
$page->of(false);
$rowUiValue = $type->getBlankValue($page, $rowUiField);
$rowUiFirst = $rowUiValue->slides->addRow('item', [
	'heading' => 'Fish & Chips',
	'price'   => 0,
	'mode'    => 'b',
	'summary' => ['default' => 'Default label', 'de' => 'Deutsches Label'],
]);
$rowUiSecond = $rowUiValue->slides->addRow('item', ['heading' => 'Second']);
$page->{$rowUiField->name} = $rowUiValue;
$page->save($rowUiField->name);

$rowUiEditor = $rowUiField->getInputfield($page);
$rowUiEditor->value = $page->getUnformatted($rowUiField->name);
$rowUiName = $rowUiEditor->name;
$rowUiHtml = $rowUiEditor->render();
$assert(str_contains($rowUiHtml, '#1: Fish &amp; Chips (0)'), 'row label formats a position, text and numeric zero without double encoding');
$assert(str_contains($rowUiHtml, '> Add slide</button>'), 'repeatable add label is configurable');
$assert(str_contains($rowUiHtml, 'data-flex-max="3"'), 'row limits are exposed to the editor controls');
$assert(str_contains($rowUiHtml, 'class="Inputfields flex-rows-items"'), 'rows use a native Inputfields list for collapse and validation');
$assert(!str_contains($rowUiHtml, 'data-flex-row-action="up"') && !str_contains($rowUiHtml, 'data-flex-row-action="down"'), 'icon bar replaces the old move buttons');

$rowUiInput = $rowUiEditor->inputs()->get($rowUiName . '__slides');
$rowUiExisting = $rowUiInput->renderRow($rowUiFirst, 2);
$rowUiNew = $rowUiInput->renderRow($rowUiFirst, 2, true);
$assert(str_contains($rowUiExisting, 'flex-row InputfieldStateCollapsed'), 'collapse mode zero closes existing rows');
$assert(!str_contains($rowUiNew, 'flex-row InputfieldStateCollapsed'), 'collapse mode zero opens newly registered rows');
$assert(str_contains($rowUiNew, '#2: Fish &amp; Chips (0)'), 'AJAX row labels receive the requested one-based position');
$assert(str_contains($rowUiExisting, 'aria-expanded="false"') && str_contains($rowUiNew, 'aria-expanded="true"'), 'row disclosure exposes the initial state to assistive technology');

$renderRowSetting = static function(array $settings) use ($rowUiInput, $rowUiFirst): string {
	$input = clone $rowUiInput;
	$definition = $input->definition;
	if(isset($settings['rowTitle'])) {
		$definition['types']['item']['settings'] = $settings + $definition['types']['item']['settings'];
		unset($settings['rowTitle']);
	}
	$definition['settings'] = $settings + $definition['settings'];
	$input->definition = $definition;
	return $input->renderRow($rowUiFirst, 1, true);
};
$assert(str_contains($renderRowSetting(['rowCollapse' => 1]), 'flex-row InputfieldStateCollapsed'), 'collapse mode one also closes new rows');
$assert(!str_contains($renderRowSetting(['rowCollapse' => 3]), 'flex-row InputfieldStateCollapsed'), 'collapse mode three leaves rows open');
$assert(str_contains($renderRowSetting(['rowTitle' => 'heading']), '>Fish &amp; Chips</span>'), 'a bare property name supplies the row label');
$assert(str_contains($renderRowSetting(['rowTitle' => 'price']), '>0</span>'), 'a bare numeric property retains zero in its row label');
$assert(str_contains($renderRowSetting(['rowTitle' => '{mode}']), '>Beta</span>'), 'row labels use native selection labels');
$rowUiChoiceHtml = $renderRowSetting(['rowTitle' => '{mode}']);
preg_match('/data-flex-label-options=([\'"])(.*?)\1/', $rowUiChoiceHtml, $rowUiChoiceOptions);
$same(['a' => 'Alpha', 'b' => 'Beta'], json_decode(html_entity_decode($rowUiChoiceOptions[2], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR), 'live choice labels receive escaped native option names');
$assert(str_contains($renderRowSetting(['rowTitle' => '{unknown}']), '>Item #1</span>'), 'an empty label falls back to the row type label and index');
$assert(str_contains($renderRowSetting(['rowTitle' => '{summary}']), '>Default label</span>'), 'row labels resolve native language values');
$wire->user->language = $wire->languages->get('de');
$assert(str_contains($renderRowSetting(['rowTitle' => '{summary}']), '>Deutsches Label</span>'), 'row labels follow the current editor language');
$wire->user->language = $wire->languages->getDefault();

$rowUiFirst->heading = '<img src=x onerror=alert(1)>Safe #n {price}';
$safeRow = $rowUiInput->renderRow($rowUiFirst, 3, true);
$assert(str_contains($safeRow, '#3: Safe #n {price} (0)'), 'row values cannot inject new label tokens or row numbering');
$assert(!str_contains($safeRow, '<img src=x'), 'rich text and markup are excluded from the header label');
$rowUiFirst->heading = 'Fish & Chips';

// Presentation mistakes must not make existing content uneditable.
foreach(['0' => 0, '1' => 1, '3' => 3, '2' => 0, 'invalid' => 0] as $collapse => $expectedCollapse) {
	$fallbackSchema = $rowUiSchema;
	$fallbackSchema['slides']['rowCollapse'] = (string) $collapse;
	$fallbackSchema['slides']['addRowLabel'] = ['invalid'];
	$fallbackSchema['slides']['types']['item']['label'] = ['invalid'];
	$fallbackSchema['slides']['types']['item']['rowTitle'] = ['invalid'];
	$fallbackField = clone $rowUiField;
	$fallbackField->flexDefinition = json_encode($fallbackSchema, JSON_THROW_ON_ERROR);
	$nativeSource = $wire->wire(new InputfieldWrapper());
	$nativeSet = $wire->modules->get('InputfieldFlexRows');
	$nativeSet->name = 'slides';
	foreach($fallbackSchema['slides'] as $key => $setting) {
		if($key !== 'type') $nativeSet->set($key, $setting);
	}
	$nativeSource->add($nativeSet);
	foreach(['array' => $fallbackSchema, 'JSON' => null, 'native' => $nativeSource] as $format => $source) {
		$defs = new FlexFieldDefs($fallbackField, $page, $source);
		$settings = $defs->property('slides')['settings'];
		$same($expectedCollapse, $settings['rowCollapse'], "$format normalizes collapse setting $collapse");
		$assert(!isset($settings['addRowLabel']), "$format uses the default add label for an invalid setting");
		$typeSettings = $defs->rowType('slides', 'item');
		$same('Item', $typeSettings['label'], "$format uses the default row type label");
		$assert(!isset($typeSettings['settings']['rowTitle']), "$format uses the default title for an invalid setting");
	}
}
$fallbackField = $createField('flex_row_fallback', $fallbackSchema);
$fallbackValue = $type->getBlankValue($page, $fallbackField);
$fallbackValue->slides->addRow('item', ['heading' => 'Preserved fallback content']);
$page->{$fallbackField->name} = $fallbackValue;
$page->save($fallbackField->name);
$fallbackFresh = $wire->pages->getFresh($id)->getUnformatted($fallbackField->name);
$same('Preserved fallback content', $fallbackFresh->slides->first()->heading, 'fallback settings preserve row values through save and reload');
$fallbackEditor = $fallbackField->getInputfield($page);
$fallbackEditor->value = $fallbackFresh;
$fallbackHtml = $fallbackEditor->render();
$assert(str_contains($fallbackHtml, '> Add item</button>') && str_contains($fallbackHtml, 'Item #1'), 'invalid presentation settings render default controls and row titles');
$same([], $fallbackEditor->getErrors(), 'presentation fallbacks do not produce editor errors');
foreach([
	['minItems' => -1],
	['minItems' => 4, 'maxItems' => 3],
	['types' => []],
] as $invalidSettings) {
	$invalidField = clone $rowUiField;
	$invalidSchema = $rowUiSchema;
	$invalidSchema['slides'] = $invalidSettings + $invalidSchema['slides'];
	$invalidField->flexDefinition = json_encode($invalidSchema, JSON_THROW_ON_ERROR);
	$throws(fn() => $type->getDefinitions($invalidField, $page), 'invalid structural row settings still block editing');
}

preg_match('/name="' . preg_quote($rowUiName . '__0draft', '/') . '" value="([a-f0-9]{64})"/', $rowUiHtml, $rowUiToken);
$rowUiPost = [
	$rowUiName . '__0draft'           => $rowUiToken[1],
	$rowUiName . '__0complete'           => $rowUiToken[1],
	FlexPath::controlName($rowUiName, 'slides', 'present') => '1',
	FlexPath::controlName($rowUiName, 'slides', 'order')   => $rowUiSecond->rowId() . ',' . $rowUiFirst->rowId(),
	FlexPath::inputPrefix($rowUiName, 'slides.' . $rowUiFirst->rowId()) . 'heading'  => '',
	FlexPath::inputPrefix($rowUiName, 'slides.' . $rowUiSecond->rowId()) . 'heading' => 'Unsaved second edit',
];
$rowUiPreviousProcess = $wire->process;
$rowUiInputErrorsProperty = new \ReflectionProperty($type, 'adminInputErrors');
$rowUiInputErrorsProperty->setAccessible(true);
$rowUiPreviousInputErrors = $rowUiInputErrorsProperty->getValue($type);
try {
	$wire->set('process', 'ProcessPageEdit');
	$rowUiEditor->processInput($wire->wire(new WireInputData($rowUiPost)));
	$rowUiErrors = $rowUiEditor->getErrors();
	$assert(count($rowUiErrors) > 0, 'collapsed row inputs retain native required validation');
	$invalidRowHtml = $rowUiInput->renderRow($rowUiFirst);
	$assert(!str_contains($invalidRowHtml, 'flex-row InputfieldStateCollapsed'), 'a row containing an input error is rendered open');
	$invalidEditorHtml = $rowUiEditor->render();
	$assert(str_contains($invalidEditorHtml, 'value="' . $rowUiSecond->rowId() . ',' . $rowUiFirst->rowId() . '"'), 'failed validation keeps the submitted row order in the rendered input');
	$assert(str_contains($invalidEditorHtml, 'value="Unsaved second edit"'), 'failed validation keeps another row’s submitted text in the rendered input');
	$same('Fish & Chips', $wire->pages->getFresh($id)->getUnformatted($rowUiField->name)->slides->first()->heading, 'failed row validation leaves canonical values intact');
	$rowUiSaveEvent = new HookEvent(['arguments' => [$page]]);
	try {
		$type->preventPageEditSaveWithInvalidFlexDefinition($rowUiSaveEvent);
		$assert(false, 'PageEdit save guard rejects native Flex row validation errors');
	} catch(WireException $error) {
		$same($rowUiErrors[0], $error->getMessage(), 'save guard reports the native child validation error without converting it to a definition error');
	}
	$rowUiOriginalTitle = $page->title;
	$page->title = 'Must not persist while Flex input is invalid';
	try {
		$wire->pages->save($page);
		$assert(false, 'registered save hook rejects a page save after native Flex validation fails');
	} catch(WireException $error) {
		$same($rowUiErrors[0], $error->getMessage(), 'Pages::save uses the native Flex input error as its rejection');
	}
	$same($rowUiOriginalTitle, $wire->pages->getFresh($id)->title, 'native Flex validation prevents unrelated PageEdit fields from being saved');
	$page->title = $rowUiOriginalTitle;
	$page->resetTrackChanges();
	$rowUiProcess = new class($page) {
		private Page $page;

		/** @param Page $page Edited fixture page. */
		public function __construct(Page $page) {
			$this->page = $page;
		}

		/** @return Page Edited fixture page. */
		public function getPage(): Page {
			return $this->page;
		}
	};
	$rowUiRedirectEvent = new HookEvent(['object' => $rowUiProcess, 'arguments' => ['']]);
	$type->preventPageEditRedirectAfterFlexInputError($rowUiRedirectEvent);
	$assert($rowUiRedirectEvent->replace === true, 'PageEdit keeps the failed form open instead of redirecting away from its processed inputs');
} finally {
	$wire->set('process', $rowUiPreviousProcess);
}

// A caught draft ownership error must use the same save guard, while remaining
// distinguishable from definition errors and database failures.
$rowUiOwnershipEditor = $rowUiField->getInputfield($page);
$rowUiOwnershipEditor->value = $page->getUnformatted($rowUiField->name);
$rowUiOwnershipName = $rowUiOwnershipEditor->name;
$rowUiPreviousProcess = $wire->process;
try {
	$wire->set('process', 'ProcessPageEdit');
	$rowUiOwnershipPost = [
		$rowUiOwnershipName . '__0draft' => 'invalid-draft-token',
		$rowUiOwnershipName . '__0complete' => 'invalid-draft-token',
	];
	$rowUiOwnershipEditor->processInput($wire->wire(new WireInputData($rowUiOwnershipPost)));
	$rowUiOwnershipEvent = new HookEvent(['arguments' => [$page]]);
	try {
		$type->preventPageEditSaveWithInvalidFlexDefinition($rowUiOwnershipEvent);
		$assert(false, 'PageEdit save guard rejects a caught Flex draft ownership error');
	} catch(WireException $error) {
		$assert(str_contains(strtolower($error->getMessage()), 'draft token'), 'draft ownership failure remains an ordinary input error');
	}
} finally {
	$wire->set('process', $rowUiPreviousProcess);
}

// Two independent native Repeater editors must be found through their owner
// page tree, as happens when each AJAX-loaded Flex editor reports a validation error.
$rowUiRepeaterType = $wire->modules->get('FieldtypeRepeater');
$rowUiRepeaterType->getFieldClass();
$rowUiRepeaters = [];
foreach(['one', 'two'] as $suffix) {
	$repeater = $wire->wire(new RepeaterField());
	$repeater->name = 'flex_row_ui_' . $suffix . '_' . bin2hex(random_bytes(3));
	$repeater->type = $rowUiRepeaterType;
	$repeater->repeaterFields = [$rowUiField->id];
	$wire->fields->save($repeater);
	$temporaryFields[] = $repeater;
	$repeaterTemplate = $rowUiRepeaterType->_getRepeaterTemplate($repeater);
	if(!$repeaterTemplate->fieldgroup->hasField($rowUiField)) {
		$repeaterTemplate->fieldgroup->add($rowUiField);
		$repeaterTemplate->fieldgroup->save();
	}
	$template->fieldgroup->add($repeater);
	$template->fieldgroup->save();
	$rowUiRepeaters[] = $repeater;
}
$rowUiRepeaterParent = $wire->pages->newPage([
	'template' => $template,
	'parent'   => 1,
	'name'     => 'flex-row-ui-repeaters-' . bin2hex(random_bytes(4)),
	'title'    => 'Flex row validation in native Repeaters',
]);
$rowUiRepeaterParent->of(false);
$rowUiRepeaterParent->save();
$pages[] = $rowUiRepeaterParent;
foreach($rowUiRepeaters as $repeater) {
	$item = $rowUiRepeaterParent->get($repeater->name)->getNewItem();
	$item->of(false);
	$value = $type->getBlankValue($item, $rowUiField);
	$value->slides->addRow('item', ['heading' => 'Saved repeater value']);
	$item->set($rowUiField->name, $value);
	$item->save();
	$rowUiRepeaterParent->save($repeater->name);
}
$rowUiRepeaterParent = $wire->pages->getFresh($rowUiRepeaterParent->id);
$rowUiRepeaterParent->of(false);
$rowUiRepeaterItems = [];
foreach($rowUiRepeaters as $repeater) {
	$item = $rowUiRepeaterParent->get($repeater->name)->first();
	$rowUiRepeaterItems[] = $item;
	$editor = $rowUiField->getInputfield($item, '_repeater' . $item->id);
	$editor->value = $item->getUnformatted($rowUiField->name);
	$name = $editor->name;
	$html = $editor->render();
	preg_match('/name="' . preg_quote($name . '__0draft', '/') . '" value="([a-f0-9]{64})"/', $html, $draftToken);
	$row = $item->getUnformatted($rowUiField->name)->slides->first();
	$previousProcess = $wire->process;
	try {
		$wire->set('process', 'ProcessPageEdit');
		$repeaterPost = [
			$name . '__0draft' => $draftToken[1],
			$name . '__0complete' => $draftToken[1],
			FlexPath::controlName($name, 'slides', 'present') => '1',
			FlexPath::controlName($name, 'slides', 'order') => $row->rowId(),
			FlexPath::inputPrefix($name, 'slides.' . $row->rowId()) . 'heading' => '',
		];
		$editor->processInput($wire->wire(new WireInputData($repeaterPost)));
		$assert(count($editor->getErrors()) > 0, 'each AJAX-scoped native Repeater Flex editor retains its own validation error');
	} finally {
		$wire->set('process', $previousProcess);
	}
}
$rowUiPreviousProcess = $wire->process;
try {
	$wire->set('process', 'ProcessPageEdit');
	foreach($rowUiRepeaterItems as $item) {
		$repeaterSaveEvent = new HookEvent(['arguments' => [$item]]);
		try {
			$type->preventPageEditSaveWithInvalidFlexDefinition($repeaterSaveEvent);
			$assert(false, 'each native Repeater owner rejects its own invalid Flex input');
		} catch(WireException $error) {
			$assert(str_contains(strtolower($error->getMessage()), 'required'), 'native Repeater validation stays attached to its own Flex editor');
		}
	}
	$rowUiParentSaveEvent = new HookEvent(['arguments' => [$rowUiRepeaterParent]]);
	try {
		$type->preventPageEditSaveWithInvalidFlexDefinition($rowUiParentSaveEvent);
		$assert(false, 'parent PageEdit save guard recursively checks both native Repeater owners');
	} catch(WireException $error) {
		$assert(str_contains(strtolower($error->getMessage()), 'required'), 'recursive Repeater guard reports a child input error');
	}
	$rowUiOriginalRepeaterTitle = $rowUiRepeaterParent->title;
	$rowUiRepeaterParent->title = 'Must not persist while a native Repeater Flex input is invalid';
	try {
		$wire->pages->save($rowUiRepeaterParent);
		$assert(false, 'registered save hook rejects a parent save after native Repeater validation fails');
	} catch(WireException $error) {
		$assert(str_contains(strtolower($error->getMessage()), 'required'), 'recursive native Repeater input error reaches Pages::save');
	}
	$same($rowUiOriginalRepeaterTitle, $wire->pages->getFresh($rowUiRepeaterParent->id)->title, 'recursive validation guard prevents unrelated parent fields from being saved');
	$rowUiRepeaterParent->title = $rowUiOriginalRepeaterTitle;
	$rowUiRepeaterParent->resetTrackChanges();
	$rowUiProcess = new class($rowUiRepeaterParent) {
		private Page $page;

		/** @param Page $page Edited fixture page. */
		public function __construct(Page $page) {
			$this->page = $page;
		}

		/** @return Page Edited fixture page. */
		public function getPage(): Page {
			return $this->page;
		}
	};
	$rowUiRedirectEvent = new HookEvent(['object' => $rowUiProcess, 'arguments' => ['']]);
	$type->preventPageEditRedirectAfterFlexInputError($rowUiRedirectEvent);
	$assert($rowUiRedirectEvent->replace === true, 'recursive guard also keeps a native multi-Repeater PageEdit form open');
} finally {
	$wire->set('process', $rowUiPreviousProcess);
	$rowUiInputErrorsProperty->setValue($type, $rowUiPreviousInputErrors);
}
$wire->user->language = $rowUiPreviousLanguage;
