<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$arrayField = $createField('flex_array_views', [
	'heading' => ['type' => 'text'],
	'starts_on' => ['type' => 'datetime', 'dateInputFormat' => 'd.m.Y', 'dateOutputFormat' => 'd.m.Y'],
	'address' => ['type' => 'fieldset', 'children' => ['city' => ['type' => 'text']]],
	'translation' => ['type' => 'text', 'useLanguages' => true],
	'reference' => ['type' => 'page', 'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2],
	'download' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 1],
	'hero' => ['type' => 'image', 'maxFiles' => 1],
	'rows' => [
		'type' => 'rows',
		'maxItems' => 1,
		'types' => [
			'item' => [
				'children' => [
					'heading' => ['type' => 'text'],
					'notes' => [
						'type' => 'rows',
						'types' => ['note' => ['children' => ['text' => ['type' => 'text']]]],
					],
				],
			],
		],
	],
]);
$page->of(false);
$value = $type->sanitizeValue($page, $arrayField, [
	'heading' => 'A & B',
	'starts_on' => '2026-10-18',
	'city' => 'Berlin',
	'translation' => [$defaultLanguage->id => 'Default & text', $language->id => 'Deutsch & Text'],
	'reference' => 1,
]);
$same(0, count($value->rows), 'maxItems one does not create an implicit row');
$same(false, $value->rows->first(), 'empty single-row collection returns false from first');
$row = $value->rows->addRow('item', ['heading' => 'Row & title']);
$note = $row->notes->addRow('note', ['text' => 'Nested & text']);
$textPath = $trackFile(dirname(__DIR__, 2) . '/.dev/array-views-' . bin2hex(random_bytes(4)) . '.txt');
file_put_contents($textPath, 'Array view fixture');
$imagePath = $trackFile(dirname(__DIR__, 2) . '/.dev/array-views-' . bin2hex(random_bytes(4)) . '.png');
$image = imagecreatetruecolor(8, 8);
imagepng($image, $imagePath);
imagedestroy($image);
$value->download->add($textPath);
$value->hero->add($imagePath);
$page->set($arrayField->name, $value);
$page->save($arrayField->name);
$value = $wire->pages->getFresh($id)->getUnformatted($arrayField->name);
$before = $value->rawArray();
$array = $value->getArray();
$same('Berlin', $array['city'], 'ordinary fieldset leaves stay flat in getArray');
$assert(!array_key_exists('address', $array), 'ordinary fieldset adds no stored array key');
$same('A & B', $array['heading'], 'unformatted getArray uses raw text');
$same(strtotime('2026-10-18'), $array['starts_on'], 'unformatted getArray uses native timestamp');
$assert($array['rows'] instanceof FlexRows, 'getArray retains FlexRows even with maxItems one');
$same([$row->rowId()], array_keys($array['rows']->getArray()), 'collection getArray is keyed by stable row IDs');
$assert($array['reference'] instanceof Page, 'getArray retains Page object');
$assert($array['translation'] instanceof LanguagesPageFieldValue, 'getArray retains language object');
$assert($array['download'] instanceof FlexFiles && $array['hero'] instanceof FlexImages, 'unformatted getArray retains media collections');
$same(1, $before['reference'], 'rawArray converts Page to ID');
$same('Deutsch & Text', $before['translation'][$language->id], 'rawArray retains all raw language values');
$same('rows:1', $before['rows']['_flex'], 'single-row rawArray keeps rows envelope');
$same('item', $before['rows']['items'][0]['type'], 'single-row rawArray keeps row type');
$same('Nested & text', $before['rows']['items'][0]['values']['notes']['items'][0]['values']['text'], 'rawArray recursively converts nested rows');
$same('files:1', $before['download']['_flex'], 'rawArray uses file metadata envelope');
$same('images:1', $before['hero']['_flex'], 'rawArray uses image metadata envelope');

$formatted = $type->formatValue($page, $arrayField, $value);
$display = $formatted->getArray();
$same('A &amp; B', $display['heading'], 'formatted getArray encodes eligible text');
$same('18.10.2026', $display['starts_on'], 'formatted getArray formats date');
$assert($display['download'] instanceof Pagefile && $display['hero'] instanceof Pageimage, 'formatted getArray applies single-media output');
$same('Row &amp; title', $display['rows']->first()->getArray()['heading'], 'formatted getArray retains rows with formatted leaves');
$same('Nested &amp; text', $display['rows']->first()->notes->first()->text, 'formatted nested rows keep their runtime objects');
$same($display['heading'], iterator_to_array($formatted)['heading'], 'iteration follows getArray formatting');
$same($before, $formatted->rawArray(), 'formatted rawArray is unchanged canonical data');
$same($before, json_decode(json_encode($formatted), true), 'JSON serialization uses rawArray with formatting on');
$same($before, json_decode((string) $formatted, true), 'string conversion uses canonical JSON');
$same($before, $value->rawArray(), 'formatted exports preserve the raw source');
$value->heading = 'Unsaved';
$same('Unsaved', $value->rawArray()['heading'], 'rawArray reads unsaved in-memory edits');
$same('A & B', $wire->pages->getFresh($id)->getUnformatted($arrayField->name)->heading, 'exporting arrays does not save edits');

$savedPage = $wire->pages->getFresh($id);
$savedPage->of(false);
$editor = $arrayField->getInputfield($savedPage);
$editor->value = $savedPage->getUnformatted($arrayField->name);
$html = $editor->render();
$assert(str_contains($html, $editor->name . '__city'), 'ordinary fieldset input uses current scope');
$assert(str_contains($html, FlexPath::inputPrefix($editor->name, 'rows.' . $row->rowId()) . 'heading'), 'maxItems one input keeps row scope');
$same([$id], $wire->pages->findIDs("id=$id, {$arrayField->name}.city=Berlin"), 'ordinary fieldset selector uses flat leaf path');
$same([$id], $wire->pages->findIDs("id=$id, {$arrayField->name}.rows.item.heading=Row & title"), 'maxItems one selector keeps set and type path');
