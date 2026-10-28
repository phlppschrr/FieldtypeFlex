<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
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
	$helperPreview = null;
	foreach($helperConfig->children() as $child) {
		if($child->label === 'Definition source and structure') {
			$helperPreview = $child;
		}
	}
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
