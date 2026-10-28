<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Definitions: file sources, scope, wrapper, validation, and loss-aware rename.
$testPath = $wire->config->paths->templates . 'flex-fields/flex-integration.php';
file_put_contents($testPath, '<?php namespace ProcessWire; return ["context" => ["type" => "text", "value" => $field->name . ":" . $page->id]];');
$fileField = clone $field;
$fileField->flexDefinition = '';
$fileField->flexDefinitionFile = 'flex-fields/flex-integration.php';
$same("flex_test:$id", $type->getBlankValue($page, $fileField)->context, 'PHP receives page and field context');
copy(dirname(__DIR__, 2) . '/examples/objects.php', $testPath);
$optionsPath = $wire->config->paths->templates . 'flex-fields/options.php';
$createdOptionsHelper = !is_file($optionsPath);
if($createdOptionsHelper) {
	copy(dirname(__DIR__, 2) . '/examples/options.php', $optionsPath);
}
try {
	$objectDefs = $type->getDefinitions($fileField, $page);
	$assert(isset($objectDefs->properties()['email']), 'InputfieldWrapper definitions');
	$assert($objectDefs->getPropertyInputfield('email') instanceof InputfieldEmail, 'defs inputfield metadata API');
} finally {
	if($createdOptionsHelper) {
		unlink($optionsPath);
	}
}
unlink($testPath);
$fileField->flexDefinitionFile = '../config.php';
$throws(fn() => $type->getDefinitions($fileField, $page), 'definition path traversal rejected');
$bad = clone $field;
$bad->flexDefinition = '{';
$throws(fn() => $type->getDefinitions($bad, $page), 'invalid definition JSON rejected');
$bad->flexDefinition = json_encode([
	'a'     => ['type' => 'text'],
	'group' => [
		'type'     => 'fieldset',
		'children' => ['a' => ['type' => 'text']],
	],
]);
$throws(fn() => $type->getDefinitions($bad, $page), 'duplicate properties rejected');
$bad->flexDefinition = json_encode(['password' => ['type' => 'Password']]);
$throws(fn() => $type->getDefinitions($bad, $page), 'unsupported storage-coupled input rejected');
