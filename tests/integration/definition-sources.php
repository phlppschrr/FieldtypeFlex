<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// The global directory changes automatic lookup, while explicit/inline sources keep their base.
$directoryConfig = $wire->modules->getConfig('FieldtypeFlex');
$customDirectory = $wire->config->paths->templates . 'flex-directory-' . bin2hex(random_bytes(4)) . '/';
mkdir($customDirectory);
$directoryField = clone $field;
$directoryField->flexDefinition = '';
$directoryField->flexDefinitionFile = '';
$directoryFile = $customDirectory . $directoryField->name . '.php';
file_put_contents($directoryFile, '<?php namespace ProcessWire; return ["custom_directory" => ["type" => "text"]];');
try {
	$wire->modules->saveConfig('FieldtypeFlex', 'definitionsPath', basename($customDirectory) . '/');
	$assert(isset($type->getDefinitions($directoryField, $page)->properties()['custom_directory']), 'automatic definitions use the configured module directory');
	$same(basename($customDirectory) . '/' . $directoryField->name . '.php', FlexFieldDefs::inspectSource($directoryField, $wire)['relativePath'], 'automatic source identifies the configured directory');
	$same('flex-fields/', FlexFieldDefs::definitionDirectory($wire, ''), 'blank directory uses the documented default');
	$same(basename($customDirectory) . '/', FlexFieldDefs::definitionDirectory($wire, '  ./' . basename($customDirectory) . '//  '), 'relative directory separators are normalized');
	foreach(['../outside', '/tmp', 'php://filter', "bad\0path", 'C:\\definitions', '.', ['bad']] as $invalidDirectory) {
		$throws(fn() => FlexFieldDefs::definitionDirectory($wire, $invalidDirectory), 'invalid automatic directory is rejected');
	}
	$same('future/definitions/', FlexFieldDefs::definitionDirectory($wire, 'future/definitions'), 'missing safe directory can be configured without creation');
	$assert(!is_dir($wire->config->paths->templates . 'future/definitions'), 'directory validation does not create directories');
	$wire->modules->saveConfig('FieldtypeFlex', 'definitionsPath', '../outside');
	$throws(fn() => $type->getDefinitions($directoryField, $page), 'invalid configured directory cannot fall back to the default');
	$directoryField->flexDefinition = '{"inline_only":{"type":"text"}}';
	$assert(isset($type->getDefinitions($directoryField, $page)->properties()['inline_only']), 'inline definitions are independent of automatic directory errors');
	$directoryField->flexDefinition = '';
	$directoryField->flexDefinitionFile = basename($customDirectory) . '/' . $directoryField->name . '.php';
	$assert(isset($type->getDefinitions($directoryField, $page)->properties()['custom_directory']), 'explicit definition paths keep their templates-relative base');
	$directoryField->flexDefinitionFile = '';
	$wire->modules->saveConfig('FieldtypeFlex', 'definitionsPath', basename($customDirectory));
	$jsonDirectoryFile = $customDirectory . $directoryField->name . '.json';
	file_put_contents($jsonDirectoryFile, '{}');
	try {
		$throws(fn() => $type->getDefinitions($directoryField, $page), 'configured directory preserves PHP/JSON ambiguity errors');
	} finally {
		unlink($jsonDirectoryFile);
	}
	$outsideDirectory = $wire->config->paths->site . 'assets';
	$symlinkDirectory = $customDirectory . 'outside';
	symlink($outsideDirectory, $symlinkDirectory);
	try {
		$throws(fn() => FlexFieldDefs::definitionDirectory($wire, basename($customDirectory) . '/outside'), 'outside directory symlink is rejected');
		$throws(fn() => FlexFieldDefs::definitionDirectory($wire, basename($customDirectory) . '/outside/missing'), 'missing child cannot hide an outside directory symlink');
	} finally {
		unlink($symlinkDirectory);
	}
	chmod($directoryFile, 0000);
	clearstatcache(true, $directoryFile);
	try {
		if(!is_readable($directoryFile)) {
			$throws(fn() => $type->getDefinitions($directoryField, $page), 'unreadable definition is an explicit error', FlexDefinitionException::class, 'not readable');
		} else {
			echo "SKIP: runtime user bypasses unreadable-file permissions.\n";
		}
	} finally {
		chmod($directoryFile, 0644);
		clearstatcache(true, $directoryFile);
	}
	$otherWire = new ProcessWire($wire->config->paths->root);
	try {
		$otherWire->modules->getConfig('FieldtypeFlex');
		$wire->modules->saveConfig('FieldtypeFlex', 'definitionsPath', 'other-instance-missing-directory/');
		$same('missing', FlexFieldDefs::inspectSource($directoryField, $wire)['kind'], 'first instance uses its changed module configuration');
		$same('file', FlexFieldDefs::inspectSource($directoryField, $otherWire)['kind'], 'second native instance retains its own module configuration');
	} finally {
		$wire->modules->saveConfig('FieldtypeFlex', 'definitionsPath', basename($customDirectory));
		ProcessWire::removeInstance($otherWire);
		ProcessWire::setCurrentInstance($wire);
	}
	$configuration = $type->getModuleConfigInputfields();
	$directoryInput = $configuration->getChildByName('definitionsPath');
	$directoryPost = ['definitionsPath' => '../invalid'];
	$directoryInput->processInput($wire->wire(new WireInputData($directoryPost)));
	$same(basename($customDirectory), $directoryInput->value, 'invalid module form input retains the previous configured path');
	$assert(count($directoryInput->getErrors()) > 0, 'invalid module form input is reported');
	$directoryInput->getErrors(true);
	$directoryPost = ['definitionsPath' => '  ' . basename($customDirectory) . '// '];
	$directoryInput->processInput($wire->wire(new WireInputData($directoryPost)));
	$same(basename($customDirectory) . '/', $directoryInput->value, 'valid module form input is normalized before saving');

} finally {
	$wire->modules->saveConfig('FieldtypeFlex', $directoryConfig);
	unlink($directoryFile);
	rmdir($customDirectory);
}

// Definition files: JSON and PHP sources; dynamically changing PHP must never share a page cache.
$definitionPath = $wire->config->paths->templates . 'flex-fields/definition-test.json';
copy(dirname(__DIR__, 2) . '/examples/01-basic.json', $definitionPath);
$jsonField = clone $field;
$jsonField->flexDefinition = '';
$jsonField->flexDefinitionFile = 'flex-fields/definition-test.json';
$assert(isset($type->getDefinitions($jsonField, $page)->properties()['title']), 'JSON file definitions');
unlink($definitionPath);
$dynamicPath = $wire->config->paths->templates . 'flex-fields/dynamic-test.php';
file_put_contents($dynamicPath, '<?php namespace ProcessWire; return ["context_" . $page->id => ["type" => "text"]];');
$jsonField->flexDefinitionFile = 'flex-fields/dynamic-test.php';
$assert(isset($type->getDefinitions($jsonField, $page)->properties()['context_' . $page->id]), 'dynamic definition first page');
$assert(isset($type->getDefinitions($jsonField, $second)->properties()['context_' . $second->id]), 'dynamic definitions are isolated by page');
unlink($dynamicPath);

$apiPath = $wire->config->paths->templates . 'flex-fields/api-scope-test.php';
file_put_contents(
	$apiPath,
	<<<'PHP'
<?php namespace ProcessWire;
return [
    'api' => ['type' => 'text', 'value' => isset($pages, $templates, $config, $modules, $sanitizer) ? 'available' : 'missing'],
    'languages' => ['type' => 'text', 'value' => $languages === null ? 'null' : 'available'],
    'context' => ['type' => 'text', 'value' => $page->id . ':' . $field->name . ':' . ($wire instanceof ProcessWire ? 'wire' : 'other')],
    'translation' => ['type' => 'text', 'value' => is_string(__('Flex scope', __FILE__)) && is_string(_x('Flex scope', 'API test', __FILE__)) && is_string(_n('one', 'many', 2, __FILE__)) ? 'available' : 'missing'],
];
PHP
);
$apiField = clone $field;
$apiField->flexDefinition = '';
$apiField->flexDefinitionFile = 'flex-fields/api-scope-test.php';
$apiValue = $type->getBlankValue($page, $apiField);
$same('available', $apiValue->api, 'PHP definition receives APIs from its owning ProcessWire instance');
$same($wire->languages ? 'available' : 'null', $apiValue->languages, 'PHP definition always receives an explicit languages value');
$same($page->id . ':' . $apiField->name . ':wire', $apiValue->context, 'definition context overrides same-named API entries');
$same('available', $apiValue->translation, 'PHP translation helpers remain available in definitions');
$same('file', $apiValue->definitions()->sourceInfo()['kind'], 'definition source provenance is available after evaluation');
unlink($apiPath);

$helperKey = bin2hex(random_bytes(3));
$helperDefinitionRelative = "flex-fields/helper-provenance-$helperKey.php";
$helperFileRelative = "flex-fields/definition-helper-$helperKey.php";
$helperDefinitionPath = $wire->config->paths->templates . $helperDefinitionRelative;
$helperFilePath = $wire->config->paths->templates . $helperFileRelative;
file_put_contents($helperDefinitionPath, "<?php namespace ProcessWire;\nrequire __DIR__ . '/" . basename($helperFilePath) . "';\nreturn ['value' => ['type' => 'text']];\n");
file_put_contents($helperFilePath, "<?php namespace ProcessWire;\nthrow new WireException('helper definition failure');\n");
$helperErrorField = clone $field;
$helperErrorField->name = 'flex_helper_error_' . $helperKey;
$helperErrorField->flexDefinition = '';
$helperErrorField->flexDefinitionFile = $helperDefinitionRelative;
try {
	$helperError = null;
	try {
		$type->getDefinitions($helperErrorField, $page);
	} catch(FlexDefinitionException $error) {
		$helperError = $error;
	}
	$assert($helperError instanceof FlexDefinitionException, 'definition helper exception retains definition context');
	$same($helperDefinitionRelative, $helperError->sourceInfo()['relativePath'], 'definition provenance stays on the configured top-level file');
	$same(null, $helperError->sourceLine(), 'helper-file exception does not invent a top-level source line');
	$type->logDefinitionError($page, $helperErrorField, $helperError);
	$helperLog = $wire->log->getEntries('errors', [
		'text'  => $helperErrorField->name,
		'limit' => 10,
	]);
	$helperLogText = implode("\n", array_column($helperLog, 'text'));
	$assert(str_contains($helperLogText, $helperFilePath . ':2'), 'technical cause log records the helper exception throw file and line');

	$helperConfig = $type->getConfigInputfields($helperErrorField);
	$helperPreview = $helperConfig->getChildByName('flexDefinitionStatus');
	$assert(
		$helperPreview instanceof InputfieldMarkup && str_contains($helperPreview->value, $helperDefinitionRelative),
		'field configuration identifies the configured top-level definition source'
	);
	$assert(
		!str_contains($helperPreview->value, $helperFilePath) && !str_contains($helperPreview->value, '(line 2)'),
		'field configuration hides helper absolute paths and does not claim its line as the definition line'
	);
} finally {
	@unlink($helperDefinitionPath);
	@unlink($helperFilePath);
}
$noLanguagePath = $wire->config->paths->templates . 'flex-fields/no-language-api-test.php';
file_put_contents(
	$noLanguagePath,
	<<<'PHP'
<?php namespace ProcessWire;
return ['language_api' => ['type' => 'text', 'value' => $languages === null ? 'null' : 'available']];
PHP
);
$noLanguageWire = new class($wire) extends ProcessWire {
	private ProcessWire $delegate;

	public function __construct(ProcessWire $delegate) {
		$this->delegate = $delegate;
	}

	// This is an API lookup proxy, not a booted ProcessWire runtime.
	public function __destruct() {
	}

	public function wire($name = '', $value = null, $lock = false) {
		if($name === 'languages') {
			return null;
		}
		if($name === '') {
			return $this;
		}
		return $this->delegate->wire($name, $value, $lock);
	}

	public function fuel($name = '') {
		return $name === '' ? $this->delegate->fuel() : $this->delegate->fuel($name);
	}

	public function __get($name) {
		return $name === 'languages' ? null : $this->delegate->wire($name);
	}
};
$noLanguageField = clone $field;
$noLanguageField->name = 'flex_no_language_api';
$noLanguageField->flexDefinition = '';
$noLanguageField->flexDefinitionFile = 'flex-fields/no-language-api-test.php';
$noLanguageField->setWire($noLanguageWire);
try {
	$noLanguageDefs = new FlexFieldDefs($noLanguageField, $page);
	$same(
		'null',
		$noLanguageDefs->getPropertyInputfield('language_api')->value,
		'PHP definitions receive explicit null when the API context lacks LanguageSupport'
	);
} finally {
	unlink($noLanguagePath);
}
$assert($wire->languages instanceof Languages, 'no-LanguageSupport API probe leaves installed language support untouched');
