<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$media = $createField('flex_output_media', ['asset' => ['type' => 'image']]);
$fixtureFile = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-output-' . bin2hex(random_bytes(4)) . '.txt');
file_put_contents($fixtureFile, 'Output fixture');
$imageFixture = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-output-' . bin2hex(random_bytes(4)) . '.png');
$fixtureImage = imagecreatetruecolor(16, 16);
imagepng($fixtureImage, $imageFixture);
imagedestroy($fixtureImage);
$inherit = clone $field;
$inherit->flexDefinition = json_encode([
	'body' => [
		'type'         => 'TinyMCE',
		'inheritField' => $sourceField->name,
		'rows'         => 12,
	],
]);
$inherited = $type->getDefinitions($inherit, $page)->getPropertyInputfield('body');
$same(12, (int) $inherited->rows, 'inheritField local settings override inherited input settings');
$same('bold italic', $inherited->toolbar, 'inheritField reuses native editor settings');
foreach(['missing_flex_source', 'title'] as $source) {
	$inherit->flexDefinition = json_encode([
		'body' => [
			'type'         => 'TinyMCE',
			'inheritField' => $source,
		],
	]);
	$throws(fn() => $type->getDefinitions($inherit, $page), "inheritField rejects missing or mismatched source: $source");
}
$settings = clone $field;
$settings->flexDefinition = json_encode([
	'body' => [
		'type'          => 'TinyMCE',
		'settingsField' => $sourceField->name,
	],
]);
$editor = $type->getDefinitions($settings, $page)->getPropertyInputfield('body');
$editor->attr('name', 'flex_settings_body');
$editor->renderReady();
$assert(str_contains($editor->render(), 'flex_settings_body'), 'native settingsField editor renders');
$same('bold italic', $editor->toolbar, 'native settingsField applies toolbar at render time');
$settingsPost = ['flex_settings_body' => '<p><strong>Settings input</strong></p>'];
$editor->processInput($wire->wire(new WireInputData($settingsPost)));
$assert(str_contains($editor->value, 'Settings input'), 'native settingsField processes rich text');
$same([], $editor->getErrors(), 'native settingsField render/input has no validation errors');

$page = $wire->pages->getFresh($id);
$page->of(false);
foreach(['file', 'image'] as $kind) {
	foreach([null, 0, 1, 2] as $format) {
		foreach([0, 1, 2] as $limit) {
			$definition = [
				'type'       => $kind,
				'extensions' => $kind === 'image' ? 'png' : 'txt',
				'maxFiles'   => $limit,
			];
			if($format !== null) {
				$definition['outputFormat'] = $format;
			}
			$viewField = clone $media;
			$viewField->flexDefinition = json_encode(['asset' => $definition]);
			$value = $type->getBlankValue($page, $viewField);
			$value->of(true);
			$single = $format === 2 || (($format ?? 0) === 0 && $limit === 1);
			$same($single, $value->asset === null, "$kind format/limit $format/$limit empty output shape");
			$value->of(false);
			$value->asset->add($kind === 'image' ? $imageFixture : $fixtureFile);
			if($limit !== 1) {
				$value->asset->add($kind === 'image' ? $imageFixture : $fixtureFile);
			}
			$before = $value->rawArray();
			$value->of(true);
			$output = $value->asset;
			$assert($single ? $output instanceof Pagefile : $output instanceof Pagefiles, "$kind format/limit $format/$limit output type");
			if(!$single) {
				$same($limit === 1 ? 1 : 2, count($output), 'formatted collection respects maxFiles');
			}
			$same($limit === 1 ? 1 : 2, count($value->getUnformatted('asset')), 'raw media access retains full collection');
			$same($before, $value->rawArray(), 'media output never mutates canonical envelope');
			$assert($value->definitions()->property('asset')['multiple'], 'media descriptor stays multiple');
			$limited = $value->getUnformatted('asset')->outputCollection(1);
			$same(1, count($limited), 'detached output collection can limit visible files');
			$same($before, $value->rawArray(), 'detached limit retains canonical entries');
			$value->of(false);
			$value->asset->removeAll();
		}
	}
}
$rowField = clone $media;
$rowField->flexDefinition = json_encode([
	'blocks' => [
		'type'       => 'rows',
		'types'      => [
			'image' => [
				'children' => [
					'photo' => [
						'type'         => 'image',
						'maxFiles'     => 1,
						'outputFormat' => 2,
					],
				],
			],
		],
	],
]);
$rowValue = $type->getBlankValue($page, $rowField);
$row = $rowValue->blocks->addRow('image');
$row->photo->add($imageFixture);
$rowRaw = $rowValue->rawArray();
$rowValue->of(true);
$assert($rowValue->blocks->first()->photo instanceof Pageimage, 'typed row media applies formatted single-image output');
$throws(fn() => $rowValue->blocks->first()->photo->description = 'Forbidden', 'formatted single-image metadata is read-only');
$same(1, count($rowValue->blocks->first()->getUnformatted('photo')), 'typed row raw media remains a collection');
$same($rowRaw, $rowValue->rawArray(), 'typed row output preserves canonical envelope');
$rowValue->of(false);
$row->photo->removeAll();

foreach([30, -1, 3, 'auto', false, 1.5] as $invalidFormat) {
	$viewField->flexDefinition = json_encode([
		'asset' => [
			'type'         => 'image',
			'outputFormat' => $invalidFormat,
		],
	]);
	$throws(fn() => $type->getDefinitions($viewField, $page), 'unsupported media outputFormat rejected');
}
$type->media()->collect(200);
