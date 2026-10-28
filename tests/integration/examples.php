<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$sourceField = $wire->wire(new Field());
$sourceField->name = 'flex_example_rich_' . bin2hex(random_bytes(4));
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
$dateSourceField->name = 'flex_example_date_' . bin2hex(random_bytes(4));
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
			file_put_contents($destination, str_replace(
				['rich_text_source', 'date_input_source'],
				[$sourceField->name, $dateSourceField->name],
				file_get_contents($destination)
			));
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
			$same(strtotime('2026-10-18'), $dateValue->starts_on, 'date example stores canonical date');
			$same(strtotime('2026-10-18 14:30:00'), $dateValue->starts_at, 'date and time example preserves native parsed time');
			$dateValue->starts_at = '2026-10-18 14:30';
			$same(strtotime('2026-10-18 14:30:00'), $dateValue->starts_at, 'canonical API date and time retains time with separate editor formats');
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
	$same(4, count($readmeBlocks[1]), 'all README PHP blocks have an execution context');
	$readmeDefinition = $trackFile(dirname(__DIR__, 2) . '/.dev/readme-definition-' . bin2hex(random_bytes(4)) . '.php');
	file_put_contents($readmeDefinition, str_replace('rich_text_source', $sourceField->name, ltrim($readmeBlocks[1][0])));
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
