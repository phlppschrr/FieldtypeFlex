<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$sourceField = $wire->wire(new Field());
$sourceField->name = 'rich_text_source';
if($wire->fields->get($sourceField->name)) {
	throw new \RuntimeException('Refusing to replace the example inheritance source field');
}
$sourceField->type = $wire->modules->get('FieldtypeTextarea');
$sourceField->inputfieldClass = 'InputfieldTinyMCE';
$sourceField->rows = 7;
$sourceField->toolbar = 'bold italic';
$sourceField->save();
$temporaryFields[] = $sourceField;
$dateSourceField = $wire->wire(new Field());
$dateSourceField->name = 'date_input_source';
if($wire->fields->get($dateSourceField->name)) {
	throw new \RuntimeException('Refusing to replace the example Datetime inheritance source field');
}
$dateSourceField->type = $wire->modules->get('FieldtypeDatetime');
$dateSourceField->dateInputFormat = 'Y-m-d H:i';
$dateSourceField->save();
$temporaryFields[] = $dateSourceField;
$examplesRoot = dirname(__DIR__, 2) . '/examples/';
$exampleManifest = require $examplesRoot . 'manifest.php';
$structuredEntry = null;
foreach($exampleManifest['entries'] as $entry) {
	if($entry['id'] === 'structured') {
		$structuredEntry = $entry;
	}
}
$assert(
	is_array($structuredEntry) && in_array('LanguageSupport', $structuredEntry['requires'], true),
	'structured example declares its LanguageSupport requirement'
);
$assert(
	is_string($structuredEntry['category']) && is_string($structuredEntry['title']) && is_string($structuredEntry['description']),
	'catalog metadata comes from translatable manifest data'
);
$exampleFixture = 'flex-example-test-' . bin2hex(random_bytes(4));
$exampleFixturePath = $wire->config->paths->templates . $exampleFixture . '/';
if(!mkdir($exampleFixturePath, 0755, true)) {
	throw new \RuntimeException('Unable to create example manifest fixture');
}
$copiedExamples = [];
try {
	foreach($exampleManifest['entries'] as $entry) {
		foreach($entry['copyFiles'] as $filename) {
			if(!is_string($filename) || basename($filename) !== $filename) {
				throw new \RuntimeException('Unsafe file in example manifest');
			}
			$destination = $exampleFixturePath . $filename;
			if(!is_file($destination) && !copy($examplesRoot . $filename, $destination)) {
				throw new \RuntimeException("Unable to copy example $filename");
			}
			$copiedExamples[$filename] = true;
		}
	}
	$exampleKeys = [];
	$inheritDefs = null;
	foreach($exampleManifest['entries'] as $entry) {
		if(($entry['kind'] ?? '') !== 'definition') {
			continue;
		}
		$exampleField = clone $field;
		$exampleField->name = 'flex_example_' . str_replace('-', '_', $entry['id']);
		$exampleField->flexDefinition = '';
		$exampleField->flexDefinitionFile = "$exampleFixture/{$entry['file']}";
		$defs = $type->getDefinitions($exampleField, $page);
		$properties = $defs->properties();
		$assert(count($properties) > 0, "manifest definition loads: {$entry['id']}");
		foreach($entry['expectedProperties'] as $property) {
			$assert($defs->property($property) !== null, "manifest definition {$entry['id']} exposes $property");
		}
		if(in_array($entry['id'], ['contact-php', 'contact-json', 'contact-objects'], true)) {
			$exampleKeys[$entry['id']] = array_keys($properties);
		} else if($entry['id'] === 'inherit-field') {
			$inheritDefs = $defs;
		} else if($entry['id'] === 'dates') {
			$dateInput = $defs->getPropertyInputfield('starts_on');
			$dateInput->name = 'flex_example_starts_on';
			$dateInput->value = strtotime('2026-10-18');
			$same(InputfieldDatetime::datepickerFocus, (int) $dateInput->datepicker, 'date example enables native focus datepicker');
			$assert(str_contains($dateInput->render(), 'data-datepicker='), 'date example renders datepicker configuration');
			$datePost = ['flex_example_starts_on' => '18.10.2026'];
			$dateInput->processInput($wire->wire(new WireInputData($datePost)));
			$same('2026-10-18', date('Y-m-d', (int) $dateInput->value), 'date example parses its configured editor format');
			$dateValue = $type->getBlankValue($page, $exampleField);
			$dateValue->starts_on = $dateInput->value;
			$datetimeInput = $defs->getPropertyInputfield('starts_at');
			$datetimeInput->name = 'flex_example_starts_at';
			$datetimePost = ['flex_example_starts_at' => '2026-10-18 14:30'];
			$datetimeInput->processInput($wire->wire(new WireInputData($datetimePost)));
			$dateValue->starts_at = $datetimeInput->value;
			$same('2026-10-18', $dateValue->starts_on, 'date example stores canonical date');
			$same('2026-10-18 14:30:00', $dateValue->starts_at, 'date and time example preserves native parsed time');
			$dateValue->starts_at = '2026-10-18 14:30';
			$same('2026-10-18 14:30:00', $dateValue->starts_at, 'canonical API date and time retains time with separate editor formats');
			$dateValue->of(true);
			$same('18.10.2026', $dateValue->starts_on, 'date example formats date output');
			$same('18.10.2026 14:30', $dateValue->starts_at, 'date example formats date and time output');
		} else if($entry['id'] === 'rich-text') {
			$directEditor = $defs->getPropertyInputfield('body');
			$directEditor->name = 'flex_example_body';
			$directEditor->renderReady();
			$same('bold italic | bullist numlist | link', $directEditor->toolbar, 'rich-text example applies direct toolbar settings');
			$assert(str_contains($directEditor->render(), 'flex_example_body'), 'rich-text example renders with direct settings');
		}
	}
	sort($exampleKeys['contact-php']);
	sort($exampleKeys['contact-json']);
	sort($exampleKeys['contact-objects']);
	$same($exampleKeys['contact-php'], $exampleKeys['contact-json'], 'PHP and JSON contact examples expose the same properties');
	$same($exampleKeys['contact-php'], $exampleKeys['contact-objects'], 'PHP and Inputfield object contact examples expose the same properties');
	$assert($inheritDefs instanceof FlexFieldDefs, 'inheritField example loads with both native source fields');
	$same('Y-m-d H:i', $inheritDefs->getPropertyInputfield('starts_at')->dateInputFormat, 'inheritField reuses Datetime field configuration');
	$same(12, (int) $inheritDefs->getPropertyInputfield('body')->rows, 'inheritField applies local TinyMCE overrides after source settings');

	$readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
	preg_match_all('/```php\n(.*?)```/s', $readme, $readmeBlocks);
	$readmeContent = null;
	$readmeOutput = null;
	foreach($readmeBlocks[1] as $index => $code) {
		if(str_contains($code, 'return [')) {
			$snippet = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-definition-' . bin2hex(random_bytes(4)) . '.php');
			file_put_contents($snippet, $code);
			$schema = require $snippet;
			$readmeField = $createField('flex_readme_' . $index, $schema);
			$assert(count($type->getDefinitions($readmeField, $page)->properties()) > 0, 'README definition builds: block ' . ($index + 1));
			if(isset($schema['sections'])) {
				$readmeContent = $readmeField;
			}
		} else if(str_contains($code, '$content = $page->content;')) {
			$readmeOutput = $code;
		}
	}
	$assert($readmeContent instanceof Field && is_string($readmeOutput), 'README supplies a combined definition and output example');
	$readmeName = $readmeContent->name;
	$page->of(false);
	$page->set($readmeName, [
		'heading' => 'A & B',
		'body' => '<p>Body</p>',
		'starts_on' => '18.10.2026',
		'appearance' => 'featured',
	]);
	$page->get($readmeName)->sections->addRow('text', ['heading' => 'Introduction & text', 'body' => '<p>Section</p>']);
	$page->save($readmeName);
	$readmeSnippet = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-output-' . bin2hex(random_bytes(4)) . '.php');
	file_put_contents($readmeSnippet, str_replace('$page->content', '$page->' . $readmeName, $readmeOutput));
	$readmePage = $wire->pages->getFresh($id);
	$renderReadme = static function(Page $page, string $snippet): string {
		ob_start();
		try {
			require $snippet;
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	};
	$same('<h1>A &amp; B</h1><p>Body</p><p>Featured</p><p>18.10.2026</p><section><h2>Introduction &amp; text</h2><p>Section</p></section>', $renderReadme($readmePage, $readmeSnippet), 'README combined output uses formatted values and row content');
	$same([$id], $wire->pages->findIDs("id=$id, $readmeName.appearance=featured"), 'README appearance selector matches stored key');
	$same([$id], $wire->pages->findIDs("id=$id, $readmeName.sections.text.heading*=Introduction"), 'README section selector includes set and type');
	$same([], $wire->pages->findIDs("id=$id, $readmeName.sections.count>=3"), 'README count selector uses collection count');
} finally {
	foreach(array_keys($copiedExamples) as $filename) {
		@unlink($exampleFixturePath . $filename);
	}
	@rmdir($exampleFixturePath);
}
