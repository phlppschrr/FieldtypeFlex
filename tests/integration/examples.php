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
$exampleManifest = require $examplesRoot . 'manifest.php';
$same(2, $exampleManifest['version'], 'catalog manifest has the new reference version');
$same(10, count($exampleManifest['entries']), 'curated catalog has ten definitions');
$exampleIndex = file_get_contents($examplesRoot . 'README.md');
foreach($exampleManifest['entries'] as $entry) {
	$assert(str_contains($exampleIndex, '](' . $entry['file'] . ')'), 'example index links directly to ' . $entry['file']);
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
    $ids = [];
    foreach($exampleManifest['entries'] as $entry) {
        $assert(!isset($ids[$entry['id']]), 'unique example ID: ' . $entry['id']);
        $ids[$entry['id']] = true;
        $same('definition', $entry['kind'], 'example catalog lists definitions only: ' . $entry['id']);
        $assert(is_string($entry['category']) && is_string($entry['title']) && is_string($entry['description']), 'example catalog metadata is readable: ' . $entry['id']);
        foreach($entry['copyFiles'] as $filename) {
            if(!is_string($filename) || basename($filename) !== $filename) throw new \RuntimeException('Unsafe example filename');
            if(isset($copiedExamples[$filename])) continue;
            $destination = $exampleFixturePath . $filename;
            if(!copy($examplesRoot . $filename, $destination)) throw new \RuntimeException('Cannot copy example: ' . $filename);
            file_put_contents($destination, str_replace(
                ['rich_text_source', 'date_input_source'],
                [$sourceField->name, $dateSourceField->name],
                file_get_contents($destination)
            ));
            $copiedExamples[$filename] = true;
        }
    }

    $basicKeys = [];
    $loaded = [];
    foreach($exampleManifest['entries'] as $entry) {
        $exampleField = clone $field;
        $exampleField->name = 'flex_example_' . str_replace('-', '_', $entry['id']);
        $exampleField->flexDefinition = '';
        $exampleField->flexDefinitionFile = "$exampleFixture/{$entry['file']}";
        $defs = $type->getDefinitions($exampleField, $page);
        $loaded[$entry['id']] = $defs;
        $properties = $defs->properties();
        $assert(count($properties) > 0, 'schema loads: ' . $entry['id']);
        foreach($entry['expectedProperties'] as $property) {
            $assert($defs->property($property) !== null, "schema {$entry['id']} exposes $property");
        }
        if(in_array($entry['id'], ['basic-json', 'basic-php', 'basic-objects'], true)) {
            $basicKeys[$entry['id']] = array_keys($properties);
            sort($basicKeys[$entry['id']]);
            $same(50, (int) $defs->getPropertyInputfield('title')->columnWidth, 'basic examples demonstrate native 50-percent layout');
        }
    }

    $same($basicKeys['basic-json'], $basicKeys['basic-php'], 'JSON and PHP basic definitions expose identical keys');
    $same($basicKeys['basic-php'], $basicKeys['basic-objects'], 'InputfieldWrapper basic definition exposes the same keys');
    $same(['email', 'title'], $basicKeys['basic-php'], 'basic examples remain intentionally minimal');
    $same('InputfieldTinyMCE', $loaded['text']->getPropertyInputfield('text_body')->className(), 'text reference configures native TinyMCE');
    $same(['TextformatterEntities'], $loaded['text']->getPropertyInputfield('text_heading')->textformatters, 'text reference opts in to Entities');
    $same([], $loaded['text']->getPropertyInputfield('text_plain')->textformatters, 'text reference demonstrates no automatic escaping');
    $same(34, (int) $loaded['numbers-dates']->getPropertyInputfield('number_toggle')->columnWidth, 'numbers reference demonstrates three-column layout');
    $same(InputfieldDatetime::datepickerFocus, (int) $loaded['numbers-dates']->getPropertyInputfield('number_date')->datepicker, 'dates reference uses native focus datepicker');
    $same('d.m.Y', $loaded['numbers-dates']->getPropertyInputfield('number_date')->dateInputFormat, 'dates reference uses a native localized date format');
    $same('InputfieldAsmSelect', $loaded['choices']->getPropertyInputfield('choice_ordered')->className(), 'choices reference includes ordered multi-choice input');
    $same(0, (int) $loaded['references-media']->property('ref_related')['settings']['derefAsPage'], 'reference example has a multiple-Page mode');
    foreach(['media_cover', 'media_gallery', 'media_downloads'] as $name) {
        $same(['TextformatterEntities'], $loaded['references-media']->property($name)['settings']['textformatters'], 'media example explicitly encodes descriptions: ' . $name);
    }
    $same('Y-m-d H:i', $loaded['advanced']->getPropertyInputfield('advanced_date')->dateInputFormat, 'advanced source field inherits native Datetime configuration');
    $same(12, (int) $loaded['advanced']->getPropertyInputfield('advanced_body')->rows, 'advanced TinyMCE inherits native settings with a local override');
    $assert($loaded['rows']->property('sections.section.blocks.text.notes.note.text') !== null, 'rows example provides three nested levels');
    $assert($loaded['kitchen-sink']->property('variants.choices.choice_country') !== null, 'Kitchen Sink composes choice definitions in typed Rows');
    $assert($loaded['kitchen-sink']->property('variants.numbers.number_price') !== null, 'Kitchen Sink composes number definitions in typed Rows');
    $assert($loaded['kitchen-sink']->property('text_heading') !== null, 'Kitchen Sink includes independent root text properties');

	$readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
	preg_match_all('/```php\n(.*?)```/s', $readme, $readmeBlocks);
	$same(4, count($readmeBlocks[1]), 'all README PHP blocks have an execution context');
	$readmeDefinition = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-definition-' . bin2hex(random_bytes(4)) . '.php');
	$readmeSource = ltrim($readmeBlocks[1][0]);
	$inheritBody = "'inheritField' => 'body'";
	$same(1, substr_count($readmeSource, $inheritBody), 'README quick start inherits one existing TinyMCE body field');
	$readmeSource = str_replace($inheritBody, "'inheritField' => '" . $sourceField->name . "'", $readmeSource);
	file_put_contents($readmeDefinition, $readmeSource);
	$schema = require $readmeDefinition;
	$readmeField = $createField('flex_readme', $schema);
	$readmeName = $readmeField->name;
	$assert($type->getDefinitions($readmeField, $page)->getPropertyInputfield('blocks.text.body') instanceof InputfieldTinyMCE, 'README definition builds with inherited editor settings');
	$page->of(false);
	$page->set($readmeName, ['heading' => 'A & B']);
	$page->get($readmeName)->blocks->addRow('text', ['heading' => 'Introduction & text', 'body' => '<p>Section</p>']);
	$page->save($readmeName);
	$readmePage = $wire->pages->getFresh($id);
	$readmePage->of(true);
	foreach(array_slice($readmeBlocks[1], 1) as $index => $code) {
		$path = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-example-' . bin2hex(random_bytes(4)) . '.php');
		$code = str_replace(['$page->content', 'content.'], ['$page->' . $readmeName, "$readmeName."], $code);
		if(!str_starts_with(ltrim($code), '<?php')) $code = "<?php namespace ProcessWire;\n" . $code;
		file_put_contents($path, $code);
		$renderReadme = static function(Page $page, Pages $pages, string $path): array {
			ob_start();
			try {
				require $path;
				$output = ob_get_contents();
				return get_defined_vars();
			} finally {
				ob_end_clean();
			}
		};
		$result = $renderReadme($readmePage, $wire->pages, $path);
		if($index === 0) {
			$assert(str_contains($result['output'], '<h1>A &amp; B</h1>') && str_contains($result['output'], '<p>Section</p>'), 'README template renders formatted heading and row HTML');
		} else if($index === 2) {
			$same([], $result['images'], 'README row filter returns existing image rows');
			$same(1, $result['count'], 'README row count example uses saved text rows');
		}
	}
	$same([$id], $wire->pages->findIDs("id=$id, $readmeName.blocks.text.heading*=Introduction"), 'README selector matches saved text row');
	$same([], $wire->pages->findIDs("id=$id, $readmeName.blocks.count>=3"), 'README count selector uses collection count');

} finally {
	foreach(array_keys($copiedExamples) as $filename) {
		@unlink($exampleFixturePath . $filename);
	}
	@rmdir($exampleFixturePath);
}
