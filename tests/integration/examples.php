<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$sourceField = $wire->wire(new Field());
$sourceField->name = 'flex_example_rich_' . bin2hex(random_bytes(4));
if($wire->fields->get($sourceField->name)) throw new \RuntimeException('Refusing to replace the example inheritance source field');
$sourceField->type = $wire->modules->get('FieldtypeTextarea');
$sourceField->inputfieldClass = 'InputfieldTinyMCE';
$sourceField->rows = 7;
$sourceField->toolbar = 'bold italic';
$sourceField->save();
$temporaryFields[] = $sourceField;

$dateSourceField = $wire->wire(new Field());
$dateSourceField->name = 'flex_example_date_' . bin2hex(random_bytes(4));
if($wire->fields->get($dateSourceField->name)) throw new \RuntimeException('Refusing to replace the date inheritance source field');
$dateSourceField->type = $wire->modules->get('FieldtypeDatetime');
$dateSourceField->dateInputFormat = 'Y-m-d H:i';
$dateSourceField->save();
$temporaryFields[] = $dateSourceField;

$examplesRoot = dirname(__DIR__, 2) . '/examples/';
$exampleDefinitions = [
    'basic-json' => ['01-basic.json', ['title', 'email']],
    'basic-php' => ['02-basic.php', ['title', 'email']],
    'basic-objects' => ['02-basic-objects.php', ['title', 'email']],
    'text' => ['03-text.php', ['heading', 'body', 'translation', 'icon']],
    'numbers-dates' => ['04-numbers-dates.php', ['quantity', 'price', 'toggle', 'status', 'date', 'datetime']],
    'choices' => ['05-choices.php', ['country', 'priority', 'features', 'topics', 'ordered', 'tags']],
    'references-media' => ['06-references-media.php', ['primary_page', 'related_pages', 'show_credits', 'cover', 'gallery', 'downloads']],
    'rows' => ['07-rows.php', ['heading', 'hero', 'downloads', 'notes_enabled', 'slides.item.heading', 'facts.item.number', 'blocks.text.heading', 'sections.section.show_notes', 'sections.section.blocks.text.notes.note.text']],
    'tables' => ['08-tables.php', ['prices.rows.row.service', 'prices.rows.row.price', 'comparison.headers.standard', 'comparison.rows.row.row_label', 'comparison.rows.row.premium']],
    'advanced' => ['09-advanced.php', ['heading', 'translation', 'date', 'body', 'note', 'link.url', 'link.label']],
];
$exampleIndex = file_get_contents($examplesRoot . 'README.md');
foreach($exampleDefinitions as [$filename]) {
	$assert(str_contains($exampleIndex, '](' . $filename . ')'), 'example index links directly to ' . $filename);
}
foreach(['README.md', 'API.md', 'examples/README.md'] as $documentPath) {
	$absolutePath = dirname(__DIR__, 2) . '/' . $documentPath;
	preg_match_all('/\]\(([^\s)]+)\)/', file_get_contents($absolutePath), $links);
	foreach(array_unique($links[1]) as $link) {
		if(preg_match('/^[a-z][a-z0-9+.-]*:/i', $link)) continue;
		$target = explode('#', $link, 2)[0];
		if($target === '') continue;
		$assert(file_exists(dirname($absolutePath) . '/' . $target), "$documentPath links to an existing local target: $target");
	}
}
$exampleFixture = 'flex-example-test-' . bin2hex(random_bytes(4));
$exampleFixturePath = $wire->config->paths->templates . $exampleFixture . '/';
if(!mkdir($exampleFixturePath, 0755, true)) throw new \RuntimeException('Unable to create the example fixture');
$copiedExamples = [];
try {
    $basicKeys = [];
    $loaded = [];
    foreach($exampleDefinitions as $exampleId => [$filename, $expectedProperties]) {
        $destination = $exampleFixturePath . $filename;
        if(!copy($examplesRoot . $filename, $destination)) throw new \RuntimeException('Cannot copy example: ' . $filename);
        $copiedExamples[$filename] = true;
        file_put_contents($destination, str_replace(
            ['rich_text_source', 'date_input_source'],
            [$sourceField->name, $dateSourceField->name],
            file_get_contents($destination)
        ));

        $exampleField = clone $field;
        $exampleField->name = 'flex_example_' . str_replace('-', '_', $exampleId);
        $exampleField->flexDefinition = '';
        $exampleField->flexDefinitionFile = "$exampleFixture/$filename";
        $defs = $type->getDefinitions($exampleField, $page);
        $loaded[$exampleId] = $defs;
        $properties = $defs->properties();
        $assert(count($properties) > 0, 'schema loads: ' . $exampleId);
        foreach($expectedProperties as $property) {
            $assert($defs->property($property) !== null, "schema $exampleId exposes $property");
        }
        if(in_array($exampleId, ['basic-json', 'basic-php', 'basic-objects'], true)) {
            $basicKeys[$exampleId] = array_keys($properties);
            sort($basicKeys[$exampleId]);
            $same(50, (int) $defs->getPropertyInputfield('title')->columnWidth, 'basic examples demonstrate native 50-percent layout');
        }
    }

    $same($basicKeys['basic-json'], $basicKeys['basic-php'], 'JSON and PHP basic definitions expose identical keys');
    $same($basicKeys['basic-php'], $basicKeys['basic-objects'], 'InputfieldWrapper basic definition exposes the same keys');
    $same(['email', 'title'], $basicKeys['basic-php'], 'basic examples remain intentionally minimal');
    $same('InputfieldTinyMCE', $loaded['text']->getPropertyInputfield('body')->className(), 'text reference configures native TinyMCE');
    $same(['TextformatterEntities'], $loaded['text']->getPropertyInputfield('heading')->textformatters, 'text reference opts in to Entities');
    $same([], $loaded['text']->getPropertyInputfield('plain')->textformatters, 'text reference demonstrates no automatic escaping');
    $same(34, (int) $loaded['numbers-dates']->getPropertyInputfield('toggle')->columnWidth, 'numbers reference demonstrates three-column layout');
    $same(InputfieldDatetime::datepickerFocus, (int) $loaded['numbers-dates']->getPropertyInputfield('date')->datepicker, 'dates reference uses native focus datepicker');
    $same('d.m.Y', $loaded['numbers-dates']->getPropertyInputfield('date')->dateInputFormat, 'dates reference uses a native localized date format');
    $same('InputfieldAsmSelect', $loaded['choices']->getPropertyInputfield('ordered')->className(), 'choices reference includes ordered multi-choice input');
    $same(0, (int) $loaded['references-media']->property('related_pages')['settings']['derefAsPage'], 'reference example has a multiple-Page mode');
    foreach(['cover', 'gallery', 'downloads'] as $name) {
        $same(['TextformatterEntities'], $loaded['references-media']->property($name)['settings']['textformatters'], 'media example explicitly encodes descriptions: ' . $name);
    }
    $same('Y-m-d H:i', $loaded['advanced']->getPropertyInputfield('date')->dateInputFormat, 'advanced source field inherits native Datetime configuration');
    $same(12, (int) $loaded['advanced']->getPropertyInputfield('body')->rows, 'advanced TinyMCE inherits native settings with a local override');
    $assert($loaded['rows']->property('sections.section.blocks.text.notes.note.text') !== null, 'rows example provides three nested levels');
    $same('parent.show_notes=1, root.notes_enabled=1', $loaded['rows']->getPropertyInputfield('sections.section.blocks.text.notes')->showIf, 'nested notes refer to defined section and root controls');
    $same(true, $loaded['rows']->getPropertyInputfield('facts')->rowLazyLoad, 'simple facts opt into lazy row bodies');
    $advancedInputs = $loaded['advanced']->getInputfields();
    foreach(['content_column', 'settings_column'] as $column) {
        $input = $advancedInputs->getChildByName($column);
        $assert($input instanceof InputfieldFieldset && (int) $input->skipLabel === Inputfield::skipLabelHeader && (int) $input->columnWidth === 50, 'advanced layout has an unlabelled half-width fieldset: ' . $column);
    }
    $assert($loaded['advanced']->property('heading') !== null && $loaded['advanced']->property('content_column.heading') === null, 'layout fieldsets keep their properties flat');
    $same('object', $loaded['advanced']->property('link')['kind'], 'advanced link is a fixed object inside a flat layout fieldset');
    $same('show_link=1', $loaded['advanced']->getPropertyInputfield('link')->showIf, 'link object condition uses its containing scope');
    $assert($loaded['advanced']->getPropertyInputfield('link.url')->required && $loaded['advanced']->getPropertyInputfield('link.label')->required, 'link object children use native required validation');
    $assert($loaded['advanced']->property('url') === null && $loaded['advanced']->property('label') === null, 'object children do not leak into the surrounding scope');
    $advancedValue = new FlexValue($page, $field, $loaded['advanced'], ['show_link' => 1, 'link' => ['url' => 'https://example.com/', 'label' => 'Read & explore']]);
    $same('https://example.com/', $advancedValue->link->url, 'advanced example exposes the documented object value path');
    $advancedValue->of(true);
    $same('Read &amp; explore', $advancedValue->link->label, 'advanced object label uses its configured textformatter');
	preg_match_all('/```php\n(.*?)```/s', $exampleIndex, $indexBlocks);
	$same(1, count($indexBlocks[1]), 'example index schema has an execution context');
	$indexDefinition = $trackFile(dirname(__DIR__, 2) . '/.dev/example-index-' . bin2hex(random_bytes(4)) . '.php');
	file_put_contents($indexDefinition, ltrim($indexBlocks[1][0]));
	$indexField = $createField('flex_example_index', require $indexDefinition);
	$indexDefs = $type->getDefinitions($indexField, $page);
	$assert($indexDefs->property('link.url') !== null && $indexDefs->property('url') === null, 'example index Object introduces a separate value scope');
	$assert($indexDefs->getPropertyInputfield('blocks.text.body') instanceof InputfieldTinyMCE, 'example index Rows build a native rich text input');

	$readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
	preg_match_all('/```php\n(.*?)```/s', $readme, $readmeBlocks);
	$same(3, count($readmeBlocks[1]), 'all README PHP blocks have an execution context');
	$readmeDefinition = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-definition-' . bin2hex(random_bytes(4)) . '.php');
	$readmeSource = ltrim($readmeBlocks[1][0]);
	file_put_contents($readmeDefinition, $readmeSource);
	$schema = require $readmeDefinition;
	$readmeField = $createField('flex_readme', $schema);
	$readmeName = $readmeField->name;
	$assert($type->getDefinitions($readmeField, $page)->getPropertyInputfield('blocks.text.body') instanceof InputfieldTinyMCE, 'README definition builds with a directly configured rich text editor');
	$readmeImage = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-image-' . bin2hex(random_bytes(4)) . '.png');
	$im = imagecreatetruecolor(1200, 720);
	imagepng($im, $readmeImage);
	imagedestroy($im);
	$page->of(false);
	$page->set($readmeName, ['intro' => '<p>A &amp; B</p>']);
	$readmeRows = $page->get($readmeName)->blocks;
	$readmeRows->addRow('text', [
		'heading' => 'Architecture & text', 'body' => '<p>Section</p>',
	]);
	$readmeGallery = $readmeRows->addRow('gallery', ['layout' => 'grid']);
	$readmeGallery->images->add($readmeImage);
	$readmeGallery->images->first()->description = 'Trees & light';
	$page->save($readmeName);
	$readmePage = $wire->pages->getFresh($id);
	$readmePage->of(true);
	foreach(array_slice($readmeBlocks[1], 1) as $index => $code) {
		$path = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-example-' . bin2hex(random_bytes(4)) . '.php');
		$code = str_replace(['$page->content', 'content.'], ['$page->' . $readmeName, "$readmeName."], $code);
		if(!str_starts_with(ltrim($code), '<?php')) $code = "<?php namespace ProcessWire;\n" . $code;
		file_put_contents($path, $code);
		$renderReadme = static function(Page $page, Pages $pages, Sanitizer $sanitizer, string $path): array {
			ob_start();
			try {
				require $path;
				$output = ob_get_contents();
				return get_defined_vars();
			} finally {
				ob_end_clean();
			}
		};
		$result = $renderReadme($readmePage, $wire->pages, $wire->sanitizer, $path);
		if($index === 0) {
			$assert(str_contains($result['output'], '<p>A &amp; B</p>') && str_contains($result['output'], 'Architecture &amp; text') && str_contains($result['output'], '<p>Section</p>'), 'README access example reads formatted rich text and heading');
			$assert(str_contains($result['output'], 'grid') && str_contains($result['output'], $readmeGallery->images->first()->size(800, 600)->url) && str_contains($result['output'], 'Trees &amp; light'), 'README access example reads gallery layout, image variation URL and formatted description');
		} else if($index === 1) {
			$assert($result['matches'] instanceof PageArray && $result['matches']->has($readmePage), 'README text selector finds the saved text block');
			$assert($result['withGalleries'] instanceof PageArray && !$result['withGalleries']->has($readmePage), 'README gallery count selector excludes a page with only one gallery');
			$page->get($readmeName)->blocks->addRow('gallery', ['layout' => 'stacked']);
			$page->save($readmeName);
			$wire->pages->uncacheAll();
			$result = $renderReadme($wire->pages->getFresh($id), $wire->pages, $wire->sanitizer, $path);
			$assert($result['withGalleries']->has($readmePage), 'README gallery count selector finds a page with two galleries');
		}
	}

} finally {
	foreach(array_keys($copiedExamples) as $filename) {
		@unlink($exampleFixturePath . $filename);
	}
	@rmdir($exampleFixturePath);
}
