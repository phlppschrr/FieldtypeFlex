<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$leaves = [
	'caption' => ['type' => 'text'],
	'number' => ['type' => 'integer'],
	'enabled' => ['type' => 'checkbox', 'checkedValue' => 'yes', 'uncheckedValue' => 'no'],
	'reference' => ['type' => 'page', 'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2],
	'translation' => ['type' => 'text', 'useLanguages' => true],
];
$children = ['type' => 'rows', 'types' => ['item' => ['children' => $leaves]]];
$contentField = $createField('flex_content_checks', [
	'items' => [
		'type' => 'rows',
		'required' => true,
		'types' => ['item' => ['children' => $leaves + ['children' => $children]]],
	],
]);
$rowSet = static function(array $values, bool $hidden = false): array {
	return [
		'_flex' => FlexStorage::ROWS_FORMAT,
		'items' => [[
			'id' => bin2hex(random_bytes(16)),
			'type' => 'item',
			'hidden' => $hidden,
			'values' => $values,
		]],
	];
};
$cases = [
	'populated' => ['x', true],
	'numeric zero' => [0, true],
	'string zero' => ['0', true],
	'whitespace' => [' ', true],
	'blank' => ['', false],
	'null' => [null, false],
	'false' => [false, false],
	'empty array' => [[], false],
	'nested blanks' => [['empty' => ['', null, false, []]], false],
	'nested content' => [['deep' => ['value' => 'x']], true],
	'unknown empty rows' => [$rowSet([]), false],
	'unknown populated rows' => [$rowSet(['future' => 'x'], true), true],
];
foreach($cases as $label => [$unknown, $populated]) {
	foreach(['root', 'row', 'nested hidden row'] as $scope) {
		$raw = ['future' => $unknown];
		if($scope !== 'root') {
			$raw = ['items' => $rowSet($scope === 'row' ? $raw : ['children' => $rowSet($raw, true)], $scope !== 'row')];
		}
		$value = $type->sanitizeValue($page, $contentField, $raw);
		$before = $value->rawArray();
		$revision = $value->expectedRevision();
		$same(!$populated, $type->isEmptyValue($contentField, $value), "$scope $label uses canonical content");
		if($scope !== 'root') {
			$same($populated, $value->hasValue('items'), "$scope $label counts toward its known collection");
			$same(false, $value->items->first()->hasValue('future'), "$scope $label does not declare unknown properties");
			if($scope !== 'row') {
				$same($populated, $value->items->first()->hasValue('children'), "$scope $label counts toward its direct parent");
			}
		}
		$formatted = clone $value;
		$formatted->of(true);
		$same(!$populated, $type->isEmptyValue($contentField, $formatted), "$scope $label is independent of formatting and visibility");
		$same($before, $formatted->rawArray(), "$scope $label content reads preserve formatted backing data");
		$same($before, $value->rawArray(), "$scope $label content reads preserve the original");
		$same(false, $value->isChanged(), "$scope $label content reads do not mark changes");
		$same($revision, $value->expectedRevision(), "$scope $label content reads preserve the expected revision");
	}
}

// Known native values retain their schema-specific content rules.
foreach([
	'blank typed leaves' => [[], false],
	'numeric zero' => [['number' => 0], true],
	'checked checkbox' => [['enabled' => true], true],
	'non-default language' => [['translation' => [$language->id => 'Deutsch']], true],
] as $label => [$values, $populated]) {
	$value = $type->sanitizeValue($page, $contentField, ['items' => $rowSet($values)]);
	$same($populated, $value->hasValue('items'), "$label retains native typed content semantics");
}

// Unknown-only rows satisfy collection/whole-field emptiness checks and survive saving.
$page->of(false);
$page->set($contentField->name, ['items' => $rowSet(['children' => $rowSet(['future' => 'Retained'], true)])]);
$page->save($contentField->name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($contentField->name);
$before = $value->rawArray();
$editor = $contentField->getInputfield($page);
$editor->value = $value;
$same(false, $editor->isEmpty(), 'Flex editor includes unknown descendant content');
$inputs = $editor->inputs();
$collection = $inputs->getChildByName($contentField->name . '__items');
$same(false, $collection->isEmpty(), 'required Rows editor includes unknown descendant content');
$same(false, $value->hasValue('future'), 'unknown data remains outside the declared property API');
$value->items->first()->caption = 'Saved known edit';
$page->save($contentField->name);
$fresh = $wire->pages->getFresh($id)->getUnformatted($contentField->name)->rawArray();
$before['items']['items'][0]['values']['caption'] = 'Saved known edit';
$same(FlexStorage::revision($before), FlexStorage::revision($fresh), 'normal save retains unknown descendant content, identities and hidden state');

// Retained content must not bypass native validation of a declared required leaf.
$requiredField = clone $contentField;
$requiredSchema = json_decode($requiredField->flexDefinition, true, 512, JSON_THROW_ON_ERROR);
$requiredSchema['items']['types']['item']['children']['caption']['required'] = true;
$requiredField->flexDefinition = json_encode($requiredSchema, JSON_THROW_ON_ERROR);
$requiredEditor = $requiredField->getInputfield($page);
$requiredEditor->value = $value;
$html = $requiredEditor->render();
$dom = new \DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);
$post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $input) {
	$post[$input->getAttribute('name')] = $input->getAttribute('value');
}
$post[FlexPath::inputPrefix($contentField->name, $value->items->first()->address()) . 'caption'] = '';
$requiredEditor->processInput($wire->wire(new WireInputData($post)));
$assert(count($requiredEditor->getErrors()) > 0, 'unknown descendant content does not satisfy a declared required caption');
$same(FlexStorage::revision($fresh), FlexStorage::revision($wire->pages->getFresh($id)->getUnformatted($contentField->name)->rawArray()), 'failed native validation leaves saved known and unknown values unchanged');
