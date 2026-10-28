<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Native discovery and runtime translation; no language files are written.
require_once dirname($wire->modules->getModuleFile('LanguageSupport')) . '/LanguageParser.php';
$translationLanguage = $wire->languages->get('de');
$translator = $translationLanguage->translator();
$previousLanguage = $wire->user->language;
$translationDomains = [];
$translationRoot = dirname(__DIR__, 2);
$translationSources = [
	'FieldtypeFlex.module.php' => [
		'PHP/JSON-defined compound fields with native inputs and selectors',
		'This Flex field changed before the save completed. Reload before saving.',
	],
	'InputfieldFlex.module.php'     => ['Schema-defined compound input', 'Unable to upload file', 'Item moved to position {position} of {count}'],
	'InputfieldFlexRows.module.php' => ['Repeatable native inputs inside Flex', 'Drag to reorder; use Up and Down arrow keys when focused'],
	'src/FlexFieldDefs.php'             => [
		'Invalid Flex definition for field "%1$s" from %2$s: %3$s',
		"Flex rename collision: '%1\$s' and '%2\$s' both contain different values",
	],
	'src/FlexMedia.php'           => ['Invalid Flex file metadata', 'Crop is outside the image'],
	'src/FlexAdmin.php'           => ['Uploads are disabled for this property'],
	'src/FlexQuery.php'           => ['Item count', 'Unsupported Flex selector operator: %s'],
	'src/FlexRows.php'            => ['Maximum repeatable item count reached', 'Invalid Flex row ID'],
	'src/FlexValue.php'           => ["Invalid date for Flex '%s'"],
	'src/InputfieldFlexMedia.php' => ['Rotate 90°', 'Flip vertically'],
	'examples/advanced.php'   => ['Summary', 'Heading'],
	'examples/options.php'    => ['Germany', 'Austria'],
	'examples/manifest.php'   => ['Contact form — PHP', 'Shared options helper'],
];

try {
	foreach($translationSources as $relativePath => $expectedPhrases) {
		$sourcePath = $translationRoot . '/' . $relativePath;
		$translationDomains[] = $translator->filenameToTextdomain($sourcePath);
		$parser = $wire->wire(new LanguageParser($translator, $sourcePath));
		$phrases = $parser->getUntranslated();
		$same([], array_values(array_diff($expectedPhrases, $phrases)), 'native language parser discovers public strings in ' . $relativePath);
	}

	$setTranslation = static function($domain, string $source, string $translated) use ($translator, &$translationDomains): void {
		$domain = $translator->textdomainString($domain);
		$translator->loadTextdomain($domain);
		$translationDomains[] = $domain;
		$translator->setTranslation($domain, $source, $translated);
	};
	$wire->user->language = $translationLanguage;

	$setTranslation($translationRoot . '/FieldtypeFlex.module.php', 'PHP/JSON-defined compound fields with native inputs and selectors', 'Zusammengesetzte Felder');
	$same('Zusammengesetzte Felder', FieldtypeFlex::getModuleInfo()['summary'], 'module summary uses the native file textdomain');
	$setTranslation($translationRoot . '/InputfieldFlex.module.php', 'Schema-defined compound input', 'Zusammengesetzte Eingabe');
	$same('Zusammengesetzte Eingabe', InputfieldFlex::getModuleInfo()['summary'], 'input module summary is translated');
	$setTranslation($translationRoot . '/InputfieldFlexRows.module.php', 'Repeatable native inputs inside Flex', 'Wiederholbare Eingaben');
	$same('Wiederholbare Eingaben', InputfieldFlexRows::getModuleInfo()['summary'], 'row module summary is translated');

	$setTranslation($translationRoot . '/examples/options.php', 'Germany', 'Deutschland');
	$translatedOptions = require $translationRoot . '/examples/options.php';
	$same('Deutschland', $translatedOptions['DE'], 'included definition helper discovers its own textdomain without __FILE__');

	$setTranslation($translationRoot . '/src/FlexMedia.php', 'Invalid Flex file metadata', 'Ungültige Dateimetadaten');
	try {
		FlexMedia::validateMeta(null);
		$assert(false, 'static validation rejects invalid file metadata');
	} catch(WireException $error) {
		$same('Ungültige Dateimetadaten', $error->getMessage(), 'static validation errors are translated');
	}

	$setTranslation($translationRoot . '/src/FlexRows.php', 'Invalid Flex row ID', 'Ungültige Zeilenkennung');
	try {
		new FlexRow($page, $field, $type->getDefinitions($field, $page), 'blocks', 'invalid', 'text');
		$assert(false, 'row constructor rejects an invalid identity');
	} catch(WireException $error) {
		$same('Ungültige Zeilenkennung', $error->getMessage(), 'row errors translate before the native object is wired');
	}

	$setTranslation($translationRoot . '/src/FlexFieldDefs.php', 'Invalid Flex definition for field "%1$s" from %2$s: %3$s', '%3$s — %1$s (%2$s)');
	$setTranslation($translationRoot . '/src/FlexFieldDefs.php', 'inline JSON', 'Inline-JSON');
	$error = new FlexDefinitionException('contact', 'Problem', ['kind' => 'inline-json']);
	$same('Problem — contact (Inline-JSON)', $error->getMessage(), 'definition errors support reordered translation placeholders');

	$setTranslation($translationRoot . '/src/FlexValue.php', 'Unknown Flex property: %s', 'Unbekannte Eigenschaft: %s');
	$translationValue = $type->sanitizeValue($page, $field, []);
	try {
		$translationValue->set('unknown_property', 'value');
		$assert(false, 'typed value rejects an unknown property');
	} catch(WireException $error) {
		$same('Unbekannte Eigenschaft: unknown_property', $error->getMessage(), 'Wire instance errors use their native class file textdomain');
	}
} finally {
	$wire->user->language = $previousLanguage;
	foreach(array_unique($translationDomains) as $domain) {
		$translator->unloadTextdomain($domain);
	}
}
