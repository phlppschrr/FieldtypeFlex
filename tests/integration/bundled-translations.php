<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

require_once dirname($wire->modules->getModuleFile('LanguageSupport')) . '/LanguageParser.php';
$root = dirname(__DIR__, 2);
$previousLanguage = $wire->user->language;
$languageFiles = $wire->modules->getModuleLanguageFiles('FieldtypeFlex');
$same(['de', 'es', 'fr'], array_keys($languageFiles), 'module discovers all three bundled languages');
$sources = array_merge(glob($root . '/*.module.php'), glob($root . '/src/*.php'));
$placeholders = static function(string $text): array {
	preg_match_all('/%(?:\d+\$)?[sdf]|\{\w+\}/', $text, $matches);
	sort($matches[0]);
	return $matches[0];
};
foreach($languageFiles as $code => $csvFile) {
	$testLanguage = $wire->languages->add('flex-translations-' . $code . '-' . bin2hex(random_bytes(3)));
	try {
		$translator = $testLanguage->translator();
		$phrases = [];
		foreach($sources as $source) {
			$parser = $wire->wire(new LanguageParser($translator, $source));
			foreach($parser->getUntranslated() as $hash => $text) {
				$path = 'site/modules/FieldtypeFlex/' . substr($source, strlen($root) + 1);
				$phrases[$path . ':' . $hash] = $text;
			}
		}
		$handle = fopen($csvFile, 'r');
		$rows = [];
		$errors = [];
		try {
			$same(['text', $code, 'description', 'file', 'hash'], fgetcsv($handle), "$code CSV has native export columns");
			while(($row = fgetcsv($handle)) !== false) {
				if(count($row) !== 5) {
					$errors[] = 'Invalid column count';
					continue;
				}
				[$text, $translated, , $path, $hash] = $row;
				$key = $path . ':' . $hash;
				if(isset($rows[$key]) || ($phrases[$key] ?? null) !== $text || $translated === '' || $placeholders($text) !== $placeholders($translated)) {
					$errors[] = $key;
				}
				$rows[$key] = $translated;
			}
		} finally {
			fclose($handle);
		}
		$same([], $errors, "$code translations match native hashes, paths and placeholders without duplicates");
		$missing = array_diff_key($phrases, $rows);
		$same([], $missing, "$code covers every runtime phrase");
		$same(count($phrases), count($rows), "$code has no obsolete translations");
		$same(count($rows), $wire->languages->importTranslationsFile($testLanguage, $csvFile, true), "$code imports with the native module translation API");
		$wire->user->language = $testLanguage;
		$translator = $testLanguage->translator();
		$resolved = [];
		foreach($rows as $key => $translated) {
			[$path, $hash] = explode(':', $key, 2);
			$translator->unloadTextdomain($translator->textdomainString($path));
			if($translator->getTranslation($path, $phrases[$key]) !== $translated) $resolved[] = $key;
		}
		$same([], $resolved, "$code imported translations resolve from every source file");
	} finally {
		$wire->user->language = $previousLanguage;
		$wire->languages->delete($testLanguage);
	}
}
