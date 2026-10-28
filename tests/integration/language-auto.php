<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Capability masks keep the fixture's installed modules and language data intact.
$maskedScope = static function(bool $languages, bool $fields) use ($wire): ProcessWire {
	$modules = new class($wire->modules, $fields) {
		private Modules $delegate;
		private bool $fields;

		public function __construct(Modules $delegate, bool $fields) {
			$this->delegate = $delegate;
			$this->fields = $fields;
		}

		public function isInstalled($name) {
			return $name === 'LanguageSupportFields' && !$this->fields ? false : $this->delegate->isInstalled($name);
		}

		public function __call($name, $arguments) {
			return $this->delegate->$name(...$arguments);
		}
	};
	return new class($wire, $languages, $modules) extends ProcessWire {
		private ProcessWire $delegate;
		private bool $withLanguages;
		private object $maskedModules;

		public function __construct(ProcessWire $delegate, bool $languages, object $modules) {
			$this->delegate = $delegate;
			$this->withLanguages = $languages;
			$this->maskedModules = $modules;
		}

		public function __destruct() {
		}

		public function wire($name = '', $value = null, $lock = false) {
			if($name === '') {
				return $this;
			}
			if($name === 'languages' || $name === 'modules') {
				return $this->__get($name);
			}
			return $this->delegate->wire($name, $value, $lock);
		}

		public function fuel($name = '') {
			return $name === '' ? $this->delegate->fuel() : $this->delegate->fuel($name);
		}

		public function __get($name) {
			if($name === 'languages' && !$this->withLanguages) {
				return null;
			}
			return $name === 'modules' ? $this->maskedModules : $this->delegate->wire($name);
		}
	};
};

$schema = [
	'heading' => ['type' => 'text', 'useLanguages' => 'auto'],
	'summary' => ['type' => 'textarea', 'useLanguages' => 'auto'],
	'body' => ['type' => 'TinyMCE', 'useLanguages' => 'auto'],
	'plain' => ['type' => 'text', 'useLanguages' => false],
	'rows' => [
		'type' => 'fieldset',
		'repeatable' => true,
		'types' => ['item' => ['children' => ['text' => ['type' => 'text', 'useLanguages' => 'auto']]]],
	],
];
$autoField = $createField('flex_language_auto', $schema);
$page->of(false);
$value = $type->sanitizeValue($page, $autoField, ['heading' => 'Default & text', 'plain' => 'Single']);
$assert($value->heading instanceof LanguagesPageFieldValue, 'auto enables native language values when both modules are available');
$same('Default & text', $value->heading->getLanguageValue($defaultLanguage), 'enabling auto imports a plain string into the default language');
$value->heading->setLanguageValue($language, 'Deutsch & Text');
$value->rows->addRow('item', ['text' => [$defaultLanguage->id => 'Default row', $language->id => 'Deutsche Zeile']]);
$page->set($autoField->name, $value);
$page->save($autoField->name);
$same((int) $defaultLanguage->id, (int) $wire->modules->getConfig('FieldtypeFlex', 'defaultLanguageId'), 'Flex saves remember the default language centrally');
$same('Single', $value->plain, 'false stays single-language on a multilingual site');
$defs = $type->getDefinitions($autoField, $page);
foreach(['heading', 'summary', 'body', 'rows.item.text'] as $name) {
	$same(true, $defs->getPropertyInputfield($name)->useLanguages, 'auto passes a boolean to native input ' . $name);
}
$same(true, $defs->structure()[0]['useLanguages'], 'discovery exposes resolved multilingual flag');
$same(true, $type->getDefinitions($autoField)->property('heading')['languages'], 'discovery without a Page resolves auto consistently');
$same(true, $defs->getInputfields()->getChildByName('heading')->useLanguages, 'fresh editor tree uses resolved language mode');
$before = $value->rawArray();
$wire->user->language = $language;
$same('Deutsch & Text', (string) $value->heading, 'auto follows current user language');
$formatted = $type->formatValue($page, $autoField, $value);
$same('Deutsch &amp; Text', (string) $formatted->heading, 'auto uses normal output encoding');
$same($before, $formatted->rawArray(), 'formatted auto values preserve language maps');
$same([$id], $wire->pages->findIDs("id=$id, {$autoField->name}.heading=Deutsch & Text"), 'selectors use resolved multilingual schema');
$wire->user->language = $defaultLanguage;

$singleField = clone $autoField;
$singleSchema = $schema;
foreach(['heading', 'summary', 'body'] as $name) $singleSchema[$name]['useLanguages'] = false;
$singleSchema['rows']['types']['item']['children']['text']['useLanguages'] = false;
$singleField->flexDefinition = json_encode($singleSchema);
$singleDefs = new FlexFieldDefs($singleField, $page);
$wire->user->language = $language;
$single = new FlexValue($page, $singleField, $singleDefs, $before);
$same('Default & text', $single->heading, 'explicit false reads the default even for a translated user');
$same('Default row', $single->rows->first()->text, 'explicit false applies to nested rows');
$same('Default & text', (new FlexValue($page, $singleField, $singleDefs, ['heading' => $value->heading]))->heading, 'language objects also resolve to the default');
$same('', (new FlexValue($page, $singleField, $singleDefs, ['heading' => [$defaultLanguage->id => '', $language->id => 'Deutsch']]))->heading, 'blank default does not inherit another translation');
$same('', (new FlexValue($page, $singleField, $singleDefs, ['heading' => [$language->id => 'Deutsch']]))->heading, 'absent default stays blank');
$throws(fn() => new FlexValue($page, $singleField, $singleDefs, ['heading' => ['unexpected' => 'array']]), 'ordinary arrays still fail scalar validation');
$wire->user->language = $defaultLanguage;
$rememberedId = $wire->modules->getConfig('FieldtypeFlex', 'defaultLanguageId');
try {
	$wire->modules->saveConfig('FieldtypeFlex', 'defaultLanguageId', null);
	$missingField = clone $singleField;
	$missingField->setWire($maskedScope(false, false));
	$missingDefs = new FlexFieldDefs($missingField, $page);
	$throws(fn() => new FlexValue($page, $missingField, $missingDefs, $before), 'missing metadata never guesses another language');
	$wire->modules->saveConfig('FieldtypeFlex', 'defaultLanguageId', $language->id);
	$same('Default & text', (new FlexValue($page, $singleField, $singleDefs, $before))->heading, 'live default language takes precedence over remembered metadata');
} finally {
	$wire->modules->saveConfig('FieldtypeFlex', 'defaultLanguageId', $rememberedId);
}

$table = $wire->database->escapeTable($autoField->table);
foreach([[false, false], [true, false]] as [$withLanguages, $withFields]) {
	$scope = $maskedScope($withLanguages, $withFields);
	$fallbackField = clone $autoField;
	$fallbackField->setWire($scope);
	$fallbackDefs = new FlexFieldDefs($fallbackField, $page);
	$same(false, $fallbackDefs->getPropertyInputfield('heading')->useLanguages, 'auto falls back when a required language capability is missing');
	$same(false, $fallbackDefs->structure()[0]['useLanguages'], 'fallback discovery exposes a boolean false');
	$same(false, $fallbackDefs->property('rows.item.text')['languages'], 'nested schema resolves auto in the same instance');
	$configBefore = $wire->modules->getConfig('FieldtypeFlex');
	$dataBefore = $wire->database->query("SELECT data FROM `$table` WHERE pages_id=$id")->fetchColumn();
	$previous = new FlexValue($page, $fallbackField, $fallbackDefs, $before);
	$same('Default & text', $previous->heading, 'missing language capability reads the previous default text');
	$same('Default row', $previous->rows->first()->text, 'missing language capability reads nested default text');
	$previous->of(true);
	$same('Default &amp; text', $previous->heading, 'previous default text receives normal encoding');
	$previous->rawArray();
	$same($configBefore, $wire->modules->getConfig('FieldtypeFlex'), 'fallback reads do not write module configuration');
	$same($dataBefore, $wire->database->query("SELECT data FROM `$table` WHERE pages_id=$id")->fetchColumn(), 'fallback reads leave stored translations unchanged');
	$fallback = new FlexValue($page, $fallbackField, $fallbackDefs, ['heading' => 'Portable & text']);
	$same('Portable & text', $fallback->heading, 'auto fallback exposes a plain string');
	$fallback->of(true);
	$same('Portable &amp; text', $fallback->heading, 'auto fallback uses single-language encoding');
	$same('Portable & text', $fallback->rawArray()['heading'], 'auto fallback persists a scalar');
	$required = clone $fallbackField;
	$required->flexDefinition = json_encode(['heading' => ['type' => 'text', 'useLanguages' => true]]);
	$throws(fn() => new FlexFieldDefs($required, $page), 'true still requires complete language support', FlexDefinitionException::class, 'requires LanguageSupport');
	// Exercise native saves and selectors with the resolved single-language schema.
	$autoField->setWire($scope);
	try {
		$converted = new FlexValue($second, $autoField, $type->getDefinitions($autoField, $second), $before);
		$second->set($autoField->name, $converted);
		$second->save($autoField->name);
		$convertedStored = json_decode($wire->database->query("SELECT data FROM `$table` WHERE pages_id=" . (int) $second->id)->fetchColumn(), true);
		$same('Default & text', $convertedStored['heading'], 'saving fallback replaces the language map with the default scalar');
		$same('Default row', $convertedStored['rows']['items'][0]['values']['text'], 'saving fallback replaces nested language maps');
		$plainValue = $type->getBlankValue($second, $autoField);
		$plainValue->heading = 'Single project';
		$plainValue->rows->addRow('item', ['text' => 'Single row']);
		$second->set($autoField->name, $plainValue);
		$second->save($autoField->name);
		$plainStored = json_decode($wire->database->query("SELECT data FROM `$table` WHERE pages_id=" . (int) $second->id)->fetchColumn(), true);
		$same('Single project', $plainStored['heading'], 'fallback save commits a scalar text value');
		$same('Single row', $plainStored['rows']['items'][0]['values']['text'], 'fallback save commits scalar text in nested rows');
		$same('Single project', $wire->pages->getFresh($second->id)->getUnformatted($autoField->name)->heading, 'fallback save reloads as plain text');
		$same([$second->id], $wire->pages->findIDs("id=$second->id, {$autoField->name}.heading=Single project"), 'fallback selectors use scalar JSON paths');
	} finally {
		$autoField->setWire($wire);
	}
}

$wrapper = $wire->wire(new InputfieldWrapper());
$input = $wire->modules->get('InputfieldText');
$input->name = 'heading';
$input->useLanguages = 'auto';
$wrapper->add($input);
$same(true, (new FlexFieldDefs($autoField, $page, $wrapper))->getPropertyInputfield('heading')->useLanguages, 'native object definitions resolve auto');
$same('auto', $input->useLanguages, 'normalization leaves supplied source objects unchanged');
$objectFallback = clone $autoField;
$objectFallback->setWire($maskedScope(false, false));
$same(false, (new FlexFieldDefs($objectFallback, $page, $wrapper))->getPropertyInputfield('heading')->useLanguages, 'native object definitions also fall back');
foreach(['automatic', [], 'false'] as $invalid) {
	$bad = clone $autoField;
	$bad->flexDefinition = json_encode(['heading' => ['type' => 'text', 'useLanguages' => $invalid]]);
	$throws(fn() => new FlexFieldDefs($bad, $page), 'invalid language mode is rejected', FlexDefinitionException::class, 'useLanguages must be');
}
$bad = clone $autoField;
$bad->flexDefinition = json_encode(['image' => ['type' => 'image', 'useLanguages' => 'auto']]);
$throws(fn() => new FlexFieldDefs($bad, $page), 'auto does not enable unsupported media translations', FlexDefinitionException::class, 'requires a Text or Textarea');
$same($before, $wire->pages->getFresh($id)->getUnformatted($autoField->name)->rawArray(), 'capability probes leave the original multilingual value unchanged');
$assert($wire->languages instanceof Languages && $wire->modules->isInstalled('LanguageSupportFields'), 'capability probes leave real language support installed');
