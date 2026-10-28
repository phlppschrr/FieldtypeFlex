<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$choice = [
	'type' => 'select',
	'label' => 'Unit <label>',
	'options' => ['' => 'No unit', 0 => 'Zero', 'a&b' => 'A & B'],
];
$labelField = $createField('flex_label_helpers', [
	'unit' => $choice,
	'multiple' => ['type' => 'selectMultiple', 'options' => ['a' => 'Alpha', 'b' => 'Beta']],
	'rows' => [
		'type' => 'rows',
		'types' => [
			'item' => [
				'children' => [
					'unit' => $choice,
					'notes' => [
						'type' => 'rows',
						'types' => ['note' => ['children' => ['unit' => $choice]]],
					],
				],
			],
			'other' => ['children' => ['unit' => ['type' => 'select', 'options' => ['a&b' => 'Other label']]]],
		],
	],
]);
$page->of(false);
$value = $type->sanitizeValue($page, $labelField, ['unit' => 'a&b', 'multiple' => ['b', 'a']]);
$first = $value->rows->addRow('item', ['unit' => 'a&b']);
$sibling = $value->rows->addRow('item', ['unit' => '0']);
$other = $value->rows->addRow('other', ['unit' => 'a&b']);
$note = $first->notes->addRow('note', ['unit' => 'a&b']);
$before = $value->rawArray();
$assert(!method_exists($value, 'optionLabels'), 'only getOptions exposes the complete option map');
$same('Unit <label>', $first->label('unit'), 'row property label differs from selected option label');
$same('A & B', $value->optionLabel('unit'), 'root current selection');
$same('A & B', $first->optionLabel('unit'), 'row current selection');
$same('Zero', $sibling->optionLabel('unit'), 'sibling row uses its own selection');
$same('Other label', $other->optionLabel('unit'), 'same property name uses its row type option map');
$same('A & B', $note->optionLabel('unit'), 'nested row current selection');
$same('Zero', $value->optionLabel('unit', 0), 'explicit integer zero key');
$same('No unit', $value->optionLabel('unit', null), 'explicit null resolves empty key instead of current selection');
$same('No unit', $value->optionLabel('unit', ''), 'explicit empty key');
$same('', $value->optionLabel('unit', 'unknown'), 'unknown explicit option');
$same('', $value->optionLabel('missing'), 'unknown property');
$same([], $value->getOptions('missing'), 'unknown property has no option map');
$same('', $value->label('missing'), 'unknown property has no property label');
$same('', $value->optionLabel('multiple'), 'multiple selections have no singular label');
$same('Beta', $value->optionLabel('multiple', 'b'), 'explicit key lookup works for multiple selects');
$same(['a' => 'Alpha', 'b' => 'Beta'], $value->getOptions('multiple'), 'getOptions returns all choices independent of selection order');
$same('A & B', $value->optionLabel('rows.item.unit', 'a&b'), 'schema path supports an explicit option key');
$same('', $value->optionLabel('rows.item.unit'), 'schema path has no concrete current row');
$same($before, $value->rawArray(), 'label reads preserve canonical values');

$formatted = $type->formatValue($page, $labelField, $value);
$same('a&b', $formatted->unit, 'formatted selection key remains raw data');
$same('A &amp; B', $formatted->optionLabel('unit'), 'implicit lookup uses raw key and encodes label once');
$formattedFirst = $formatted->rows->first();
$same('Unit &lt;label&gt;', $formattedFirst->label('unit'), 'formatted row property label');
$same('A &amp; B', $formattedFirst->optionLabel('unit'), 'formatted row selected label');
$same('A &amp; B', $formattedFirst->notes->first()->optionLabel('unit'), 'formatted nested row selected label');
$same($before, $value->rawArray(), 'formatted label reads preserve canonical values');
$same('a&b', $formattedFirst->unit, 'formatted nested choice key remains unescaped');
$same('A &amp; B', $formatted->optionLabel('unit'), 'display labels stay encoded without a field-wide switch');

$value->unit = 0;
$same('Zero', $value->optionLabel('unit'), 'implicit lookup preserves zero selection');
$value->unit = '';
$same('No unit', $value->optionLabel('unit'), 'implicit lookup preserves blank selection');
$value->unit = 'unknown';
$same('', $value->optionLabel('unit'), 'unknown stored choice has no label');
$first->unit = '0';
$same('Zero', $first->optionLabel('unit'), 'row lookup follows current mutations');
$same('A &amp; B', $formattedFirst->optionLabel('unit'), 'formatted clone keeps independent row selection');

$page->set($labelField->name, $value);
$page->save($labelField->name);
$reloaded = $wire->pages->getFresh($id)->getUnformatted($labelField->name);
$same('Zero', $reloaded->rows->first()->optionLabel('unit'), 'row label lookup after actual field save and reload');
$same('A & B', $reloaded->rows->first()->notes->first()->optionLabel('unit'), 'nested label lookup after save and reload');
