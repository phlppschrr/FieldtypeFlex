<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$helperField = $createField('flex_row_helpers', [
	'blocks' => [
		'type' => 'rows',
		'types' => [
			'text' => [
				'label' => 'Text & prose',
				'children' => [
					'heading' => ['type' => 'text'],
					'group' => ['type' => 'fieldset', 'children' => ['caption' => ['type' => 'text']]],
					'notes' => [
						'type' => 'rows',
						'types' => [
							'text' => ['label' => 'Nested text', 'children' => ['body' => ['type' => 'text']]],
							'foot_note' => ['children' => ['number' => ['type' => 'integer']]],
						],
					],
				],
			],
			'image' => ['label' => 'Image block', 'children' => ['alt' => ['type' => 'text']]],
			'plain_text' => ['children' => ['body' => ['type' => 'text']]],
		],
	],
]);
$page->of(false);
$value = $type->getBlankValue($page, $helperField);
$rows = $value->blocks;
$same([], $rows->rowsOfType('text'), 'empty collection has no matching rows');
$same(0, $rows->countOfType('text'), 'empty collection has zero matching rows');
$text = $rows->addRow('text');
$same([$text], $rows->rowsOfType('text'), 'single type filter returns the existing row');
$same(1, $rows->countOfType('text'), 'single matching row count');
$image = $rows->addRow('image');
$secondText = $rows->addRow('text', ['heading' => 'Second <heading>']);
$plain = $rows->addRow('plain_text');
$note = $text->notes->addRow('text', ['body' => 'Nested']);
$footnote = $text->notes->addRow('foot_note');

$same('Text & prose', $text->rowTypeLabel(), 'configured row type label');
$same('Image block', $image->rowTypeLabel(), 'different row types have independent labels');
$same('Plain Text', $plain->rowTypeLabel(), 'missing type label uses existing title-case fallback');
$same('Nested text', $note->rowTypeLabel(), 'nested type label uses its own definition');
$same('Foot Note', $footnote->rowTypeLabel(), 'nested type label uses the same fallback');
$same(true, $text->hasProperty('heading'), 'new empty row recognizes a defined property');
$same(false, $text->hasValue('heading'), 'defined empty property remains unpopulated');
$same(true, $secondText->hasValue('heading'), 'populated property retains hasValue behavior');
$same(true, $text->hasProperty('caption'), 'ordinary fieldset leaves are flat row properties');
$same(false, $text->hasProperty('group'), 'ordinary fieldset name is not a stored property');
$same(false, $text->hasProperty('alt'), 'another row type does not contribute properties');
$same(true, $image->hasProperty('alt'), 'other type recognizes its own schema');
$same(false, $image->hasProperty('heading'), 'other type does not inherit sibling schema');
$same(false, $text->hasProperty('missing'), 'unknown property is absent');
$same(false, $text->hasValue('missing'), 'unknown property remains unpopulated');
$same(true, $text->hasProperty('notes'), 'nested collection itself is a direct property');
$same(false, $text->hasProperty('body'), 'parent does not search nested row schemas');
$same(false, $text->hasProperty('notes.text.body'), 'schema paths are not direct properties');
$same(true, $note->hasProperty('body'), 'nested row recognizes its own property');
$same(false, $note->hasProperty('heading'), 'nested row does not inherit parent properties');
$same(true, $footnote->hasProperty('number'), 'empty nested row recognizes its own schema');
$same(false, $footnote->hasValue('number'), 'empty nested numeric property remains unpopulated');

$rows->resetTrackChanges();
$page->resetTrackChanges();
$before = $value->rawArray();
$selection = $rows->rowsOfType('text');
$same([$text, $secondText], $selection, 'mixed filter is an ordered numeric list of original objects');
$same(2, $rows->countOfType('text'), 'count excludes matching types in descendants');
$same(1, $rows->countOfType('image'), 'count matches a different direct type');
$same([$note], $text->notes->rowsOfType('text'), 'nested collection filters only its direct rows');
$same(1, $text->notes->countOfType('text'), 'nested collection counts its own direct rows');
foreach(['missing', '', 'TEXT', 'text|image', 'notes.text'] as $unknown) {
	$same([], $rows->rowsOfType($unknown), 'unknown type string returns an empty filter: ' . $unknown);
	$same(0, $rows->countOfType($unknown), 'unknown type string returns zero: ' . $unknown);
}
foreach([null, 1, 1.5, true, [], new \stdClass(), new class {
	public function __toString(): string { return 'text'; }
}] as $invalid) {
	$throws(fn() => $rows->rowsOfType($invalid), 'filter rejects non-string arguments without scalar coercion', \TypeError::class);
	$throws(fn() => $rows->countOfType($invalid), 'count rejects non-string arguments without scalar coercion', \TypeError::class);
}
$text->rowTypeLabel();
$text->hasProperty('caption');
$same($before, $value->rawArray(), 'helper reads preserve canonical data, row types and every identity');
$same(false, $value->isChanged(), 'helper reads preserve nested change tracking');
$same(false, $page->isChanged($helperField->name), 'helper reads do not mark the owning page changed');
$same(false, $rows->of(), 'helper reads preserve collection formatting state');
$same(true, $rows->belongsTo($page, $helperField, 'blocks'), 'filter preserves ownership');

$copy = $rows->duplicateRow($text->rowId());
$same(3, $rows->countOfType('text'), 'count sees unsaved duplicateRow');
$same('Text & prose', $copy->rowTypeLabel(), 'duplicate retains its resolved type label');
$same('Nested text', $copy->notes->first()->rowTypeLabel(), 'duplicated subtree retains nested labels');
$rows->moveRow($secondText->rowId(), $text->rowId());
$same([$secondText, $text, $copy], $rows->rowsOfType('text'), 'filter follows current collection order after moveRow');
$same(3, $rows->countOfType('text'), 'moveRow preserves type count');
$rows->removeRow($text->rowId());
$same(2, $rows->countOfType('text'), 'count sees unsaved removeRow');
$same([$text, $secondText], $selection, 'earlier selection retains its original objects and order');
$selection[1]->heading = 'Changed through selection';
$same('Changed through selection', $rows->getById($secondText->rowId())->heading, 'selected objects remain the actual owned rows');
$selection[] = $image;
$same(2, $rows->countOfType('text'), 'editing the result array does not mutate the collection');

$page->set($helperField->name, $value);
$page->save($helperField->name);
$fresh = $wire->pages->getFresh($id);
$fresh->of(false);
$saved = $fresh->getUnformatted($helperField->name);
$same(2, $saved->blocks->countOfType('text'), 'type helpers work after real save and reload');
$same('Nested text', $saved->blocks->getById($copy->rowId())->notes->first()->rowTypeLabel(), 'nested labels survive save and reload');
$fresh->of(true);
$formatted = $fresh->get($helperField->name);
$formattedRows = $formatted->blocks;
$formattedBefore = $formatted->rawArray();
$formattedRows->resetTrackChanges();
$fresh->resetTrackChanges();
$same(2, $formattedRows->countOfType('text'), 'count works with page output formatting enabled');
$formattedSelection = $formattedRows->rowsOfType('text');
$assert($formattedSelection[0] === $formattedRows->getById($secondText->rowId()), 'formatted filter preserves identity within the formatted collection');
$same('Text & prose', $formattedSelection[0]->rowTypeLabel(), 'type label returns resolved definition text independently of formatting');
$same(true, $formattedSelection[0]->hasProperty('caption'), 'formatted empty property still belongs to schema');
$same(false, $formattedSelection[0]->hasValue('caption'), 'formatted empty property remains unpopulated');
$same($formattedBefore, $formatted->rawArray(), 'formatted helper reads preserve canonical data');
$same(false, $formatted->isChanged(), 'formatted helper reads preserve change tracking');
$same(false, $fresh->isChanged($helperField->name), 'formatted helper reads preserve owner change tracking');
$same(true, $formattedRows->of(), 'formatted helper reads retain formatting state');
