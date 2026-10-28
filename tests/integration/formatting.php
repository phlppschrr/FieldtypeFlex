<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Native language value objects, default fallback, all-language storage and nested mutations.
$wire->modules->get('LanguageSupport');
$wire->modules->get('LanguageSupportFields');
$language = $wire->languages->get('de');
if(!$language || !$language->id) {
	$language = $wire->languages->add('de');
	$language->title = 'Deutsch';
	$wire->languages->save($language);
}
$defaultLanguage = $wire->languages->getDefault();
$multilingual = $createField('flex_language', [
	'text' => [
		'type'                  => 'text',
		'textformatters'        => ['TextformatterEntities'],
		'useLanguages'          => true,
		'label'                 => 'Text',
		'label' . $language->id => 'Deutsch',
	],
	'body' => [
		'type'         => 'TinyMCE',
		'useLanguages' => true,
	],
]);
$page->set($multilingual->name, [
	'text' => [
		$defaultLanguage->id => 'Hello <world>',
		$language->id        => 'Hallo <Welt>',
	],
	'body' => [
		$defaultLanguage->id => '<p>English</p>',
		$language->id        => '<p>Deutsch</p>',
	],
]);
$page->save($multilingual->name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$multi = $page->get($multilingual->name);
$assert($multi->text instanceof LanguagesPageFieldValue, 'native multilingual value class');
$same('Hello <world>', (string) $multi->text, 'default language string');
$wire->user->language = $language;
$same('Hallo <Welt>', (string) $multi->text, 'current language string');
$same('Deutsch', $multi->label('text'), 'translated property label');
$page->of(true);
$same('Hallo &lt;Welt&gt;', (string) $page->get($multilingual->name)->text, 'multilingual entity encoding');
$same('<p>Deutsch</p>', (string) $page->get($multilingual->name)->body, 'multilingual TinyMCE HTML');
$page->of(false);
$same('Hallo <Welt>', (string) $page->get($multilingual->name)->text, 'multilingual formatting preserves raw translations');
$same([$id], $wire->pages->findIDs("id=$id, {$multilingual->name}.text*=Hallo"), 'current-language JSON selector');
$page->get($multilingual->name)->text->setLanguageValue($language, 'Changed');
$assert($page->isChanged($multilingual->name), 'nested language mutation is dirty');
$page->save();
$page = $wire->pages->getFresh($id);
$page->of(false);
$same('Changed', $page->get($multilingual->name)->text->getLanguageValue($language), 'nested language mutation persisted');
$langInput = $multilingual->getInputfield($page);
$langInput->value = $page->get($multilingual->name);
$langHtml = $langInput->render();
$assert(str_contains($langHtml, $multilingual->name . '__text__' . $language->id), 'native language editor input names');
$langPostData = [
	$multilingual->name . '__text'                   => 'Updated default',
	$multilingual->name . '__text__' . $language->id => 'Aktualisiert',
];
$langInput->processInput($wire->wire(new WireInputData($langPostData)));
$same('Aktualisiert', $langInput->value->text->getLanguageValue($language), 'native multilingual POST processing');
$same('Updated default', $langInput->value->text->getLanguageValue($defaultLanguage), 'native default-language POST processing');
$fallback = $type->sanitizeValue($page, $multilingual, [
	'text' => [
		$defaultLanguage->id => 'Fallback',
		$language->id        => '',
	],
]);
$same('Fallback', (string) $fallback->text, 'native blank translation falls back to default');
$page->set($multilingual->name, $fallback);
$page->save($multilingual->name);
$same([$id], $wire->pages->findIDs("id=$id, {$multilingual->name}.text=Fallback"), 'language selector uses the same default fallback');
$assert($page->get($multilingual->name)->hasValue('text'), 'hasValue detects populated multilingual translations');
$wire->user->language = $defaultLanguage;

$helperField = $createField('flex_output_helpers', [
	'choice' => [
		'type'    => 'select',
		'options' => [
			''      => 'Blank key',
			'0'     => 'Zero <value>',
			'first' => 'First <name>',
			'Group' => ['inside' => 'Inside <name>'],
			'---'   => '---',
		],
	],
	'multiple' => [
		'type'    => 'selectMultiple',
		'options' => [
			'a' => 'Alpha',
			'b' => 'Beta',
		],
	],
	'note' => [
		'type'        => 'textarea',
		'contentType' => 99,
	],
	'html' => [
		'type'        => 'textarea',
		'contentType' => FieldtypeTextarea::contentTypeHTML,
	],
	'zero'      => ['type' => 'integer'],
	'zero_text' => ['type' => 'text'],
	'reference' => [
		'type'        => 'page',
		'inputfield'  => 'InputfieldSelect',
		'derefAsPage' => 2,
		'parent_id'   => 1,
	],
	'rows' => [
		'type'       => 'rows',
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => ['caption' => ['type' => 'text']],
			],
		],
	],
]);
$helperValue = $type->sanitizeValue($page, $helperField, [
	'note'      => '<p>Literal</p>',
	'html'      => '<p>Trusted</p>',
	'zero'      => 0,
	'zero_text' => '0',
]);
$assert($helperValue->hasValue('note'), 'hasValue treats populated scalar text as content');
$assert($helperValue->hasValue('zero'), 'hasValue treats numeric zero as content');
$assert($helperValue->hasValue('zero_text'), 'hasValue treats string zero as content');
$assert(!$helperValue->hasValue('choice'), 'hasValue treats an unselected option as empty');
$assert(!$helperValue->hasValue('reference'), 'hasValue treats a NullPage reference as empty');
$assert(!$helperValue->hasValue('not_a_property'), 'hasValue returns false for an unknown property');
$assert(!$helperValue->hasValue('rows'), 'hasValue ignores an empty repeatable collection');
$blankRow = $helperValue->rows->addRow('item');
$assert(!$helperValue->hasValue('rows'), 'hasValue ignores a row with only blank leaves');
$blankRow->set('caption', 'Row content');
$assert($helperValue->hasValue('rows'), 'hasValue detects content in a repeatable row');
$helperValue->definitions()->property('choice')['input']->addOptionLabel('first', 'Übersetzt <Label>', $language);
$helperValue->definitions()->property('choice')['input']->addOptionLabel('inside', 'Innen <Gruppe>', $language);
$helperValue->definitions()->property('choice')['input']->addOptionLabel('0', '', $language);
$wire->user->language = $language;
$rawLabels = $helperValue->getOptions('choice');
$same('Blank key', $helperValue->optionLabel('choice', ''), 'optionLabel preserves an empty option key');
$same('Zero <value>', $helperValue->optionLabel('choice', 0), 'optionLabel falls back when a translated zero-key label is blank');
$same('Übersetzt <Label>', $helperValue->optionLabel('choice', 'first'), 'optionLabel uses native InputfieldSelect translations');
$helperValue->choice = 'first';
$same('Übersetzt <Label>', $helperValue->optionLabel('choice'), 'current selection uses native translated label');
$wire->user->language = $defaultLanguage;
$same('First <name>', $helperValue->optionLabel('choice'), 'current selection follows a language switch');
$wire->user->language = $language;
$same('Innen <Gruppe>', $helperValue->optionLabel('choice', 'inside'), 'optionLabel reads native translations for optgroup choices');
$assert(!array_key_exists('---', $rawLabels), 'getOptions omits native option separators');
$same([
	'a' => 'Alpha',
	'b' => 'Beta',
], $helperValue->getOptions('multiple'), 'getOptions supports multiple selects');
$same('', $helperValue->optionLabel('choice', 'unknown'), 'optionLabel returns empty for unknown options');
$same('', $helperValue->optionLabel('choice', 'unknown'), 'unknown option has no label');
$formattedHelpers = $type->formatValue($page, $helperField, $helperValue);
$same('&Uuml;bersetzt &lt;Label&gt;', $formattedHelpers->optionLabel('choice'), 'implicit translated label encodes once');
$same('&Uuml;bersetzt &lt;Label&gt;', $formattedHelpers->optionLabel('choice', 'first'), 'formatted option labels are entity encoded once');
$same('Innen &lt;Gruppe&gt;', $formattedHelpers->optionLabel('choice', 'inside'), 'formatted optgroup labels are entity encoded once');
$same('Zero &lt;value&gt;', $formattedHelpers->optionLabel('choice', 0), 'formatted zero-key option label is entity encoded once');
$same('Zero <value>', $helperValue->optionLabel('choice', 0), 'formatting does not mutate raw option metadata');
$same($rawLabels, $helperValue->getOptions('choice'), 'formatted label reads do not mutate option metadata');
$same('<p>Literal</p>', $formattedHelpers->note, 'unconfigured numeric textarea content is not implicitly encoded');
$same('<p>Trusted</p>', $formattedHelpers->html, 'native HTML textarea content type preserves markup');
$same('<p>Literal</p>', $helperValue->note, 'raw textarea value remains unformatted');
$same('0', $formattedHelpers->zero_text, 'unconfigured plain text retains numeric-string content');
$same('Zero &lt;value&gt;', $formattedHelpers->getOptions('choice')[0], 'formatted option-map display labels are HTML encoded');

$validation = $field->getInputfield($page);
$validation->value = $page->flex_test;
$requiredPost = [
	'flex_test__name'                 => 'Required',
	'flex_test__enabled'              => '1',
	'flex_test__required_conditional' => '',
];
$validation->processInput($wire->wire(new WireInputData($requiredPost)));
$assert(count($validation->getErrors()) > 0, 'requiredIf true reports validation errors on the containing field');
$dependencyField = $createField('flex_dependencies', [
	'alpha' => ['type' => 'checkbox'],
	'beta'  => ['type' => 'checkbox'],
	'gate'  => [
		'type'  => 'text',
		'value' => 'alpha=1',
	],
	'detail' => [
		'type'   => 'text',
		'showIf' => 'alpha|beta=1',
	],
	'quoted' => [
		'type'   => 'text',
		'showIf' => 'gate="alpha=1"',
	],
]);
$dependencyInput = $dependencyField->getInputfield($page);
$dependencyInput->value = $type->getBlankValue($page, $dependencyField);
$dependencyPost = [];
foreach([
	'alpha'  => '1',
	'gate'   => 'alpha=1',
	'detail' => 'Visible',
	'quoted' => 'Literal matched',
] as $name => $v) {
	$dependencyPost[$dependencyField->name . '__' . $name] = $v;
}
$dependencyInput->processInput($wire->wire(new WireInputData($dependencyPost)));
$same('Visible', $dependencyInput->value->detail, 'dependency field OR expressions are namespaced');
$same('Literal matched', $dependencyInput->value->quoted, 'dependency values containing operators remain literal');
$requiredLanguage = $multilingual->getInputfield($page);
$requiredLanguage->required = true;
$requiredLanguage->value = $type->getBlankValue($page, $multilingual);
$requiredForm = $wire->modules->get('InputfieldForm');
$requiredForm->protectCSRF = false;
$requiredForm->add($requiredLanguage);
$emptyPost = [];
$requiredForm->processInput($wire->wire(new WireInputData($emptyPost)));
$assert(count($requiredForm->getErrors()) > 0, 'native required Flex field rejects all-empty language values');
$numericOnly = $createField('flex_zero', ['n' => ['type' => 'integer']]);
$numericInput = $numericOnly->getInputfield($page);
$numericInput->value = $type->sanitizeValue($page, $numericOnly, ['n' => 0]);
$assert(!$numericInput->isEmpty(), 'zero is a populated numeric input');

$current = $wire->pages->getFresh($id);
$current->of(false);
$current->flex_test->categories->add($wire->pages->get(3));
$assert($current->isChanged('flex_test'), 'nested PageArray mutation is dirty');
$current->save();
$assert(in_array(3, $wire->pages->getFresh($id)->getUnformatted('flex_test')->categories->explode('id'), true), 'nested PageArray mutation persists');
