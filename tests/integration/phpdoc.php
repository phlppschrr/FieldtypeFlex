<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$docField = clone $field;
$docField->name = 'flex_phpdoc_probe';
$docField->flexDefinition = json_encode([
	'heading' => ['type' => 'text', 'label' => 'Heading label', 'useLanguages' => true],
	'layout' => ['type' => 'fieldset', 'children' => ['flat' => ['type' => 'integer']]],
	'link' => ['type' => 'object', 'children' => ['url' => ['type' => 'url']]],
	'blocks' => ['type' => 'rows', 'types' => [
		'text' => ['children' => ['body' => ['type' => 'textarea']]],
		'gallery' => ['children' => ['nested' => ['type' => 'rows', 'types' => [
			'item' => ['children' => ['caption' => ['type' => 'text']]],
		]]]],
	]],
	'hero' => ['type' => 'image', 'maxFiles' => 1, 'customFields' => ['credit' => ['type' => 'text']]],
	'files' => ['type' => 'file', 'extensions' => 'pdf', 'outputFormat' => 1],
	'price' => ['type' => 'float'],
	'date' => ['type' => 'datetime'],
	'checked' => ['type' => 'checkbox'],
	'choices' => ['type' => 'checkboxes', 'options' => ['one' => 'One']],
	'targets' => ['type' => 'page', 'derefAsPage' => 0],
	'target' => ['type' => 'page', 'derefAsPage' => 1],
	'nullable' => ['type' => 'page', 'derefAsPage' => 2],
]);
$php = $type->getPhpDoc($docField);
$same($php, $type->getPhpDoc($docField), 'PHPDoc generation is deterministic');
foreach([
	'class FlexValue_flex_phpdoc_probe extends FlexValue {}',
	'@property string|LanguagesPageFieldValue $heading',
	'@property int|string $flat',
	'@property string $url',
	'@property string $caption',
	'@property string $credit',
	'@property FlexFiles|FlexPagefile[] $files',
	'@property float|string $price',
	'@property int|string $date',
	'@property string[] $choices',
	'@property PageArray $targets',
	'@property Page|false $target',
	'@property Page|NullPage $nullable',
	'extends FlexValue {}', 'extends FlexRow {}', 'extends FlexRows {}', 'extends FlexPageimage {}',
] as $fragment) $assert(str_contains($php, $fragment), 'PHPDoc includes ' . $fragment);
$emptyDocField = clone $docField;
$emptyDocField->flexDefinition = '{}';
$assert(str_contains($type->getPhpDoc($emptyDocField), 'No properties in this context.'), 'empty definitions produce a valid empty value stub');
$assert(str_starts_with($php, '<?php namespace ProcessWire;'), 'helpers use the standard ProcessWire namespace');
$assert(!str_contains($php, 'namespace ProcessWire\\FlexDoc'), 'helpers require no additional namespace');
$collisionDocField = clone $emptyDocField;
$collisionDocField->name = 'flex_phpdoc_other';
preg_match_all('/^class (\w+)/m', $php . $type->getPhpDoc($collisionDocField), $fieldClasses);
$same(count($fieldClasses[1]), count(array_unique(array_map('strtolower', $fieldClasses[1]))), 'field prefixes distinguish helper classes for separate fields');
$collisionDocField->flexDefinition = json_encode([
	'foo_bar' => ['type' => 'object', 'label' => "Label */\nwith <b>markup</b>", 'children' => []],
	'foo' => ['type' => 'object', 'children' => ['bar' => ['type' => 'object', 'children' => []]]],
	'page' => ['type' => 'object', 'children' => []],
	'class' => ['type' => 'object', 'children' => []],
	'string' => ['type' => 'object', 'children' => []],
	'_123' => ['type' => 'object', 'children' => []],
]);
$collisionPhp = $type->getPhpDoc($collisionDocField);
$assert(str_contains($collisionPhp, 'class FlexValue_flex_phpdoc_other_foo_bar extends FlexValue') && str_contains($collisionPhp, 'class FlexValue_flex_phpdoc_other_foo_bar_2 extends FlexValue'), 'only colliding structure names receive a numeric suffix');
$assert(!str_contains($php, 'extends Page {}'), 'PHPDoc contains no Page stub');
$assert(str_contains($collisionPhp, 'Label * / with markup'), 'property labels cannot terminate PHPDoc comments or introduce markup');
foreach(['FlexValue_flex_phpdoc_other_class', 'FlexValue_flex_phpdoc_other_string', 'FlexValue_flex_phpdoc_other__123'] as $className) {
	$assert(str_contains($collisionPhp, 'class ' . $className . ' extends FlexValue'), 'valid PHP class name for reserved or numeric property: ' . $className);
}
$collisionPath = tempnam(dirname(__DIR__, 2) . '/.dev', 'phpdoc-names-');
try {
	file_put_contents($collisionPath, $collisionPhp);
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($collisionPath), $nameLintOutput, $nameLintCode);
	$same(0, $nameLintCode, 'helpers with colliding and reserved names are valid PHP');
} finally {
	unlink($collisionPath);
}
preg_match_all('/^class (\w+)/m', $collisionPhp, $classNames);
$same(count($classNames[1]), count(array_unique(array_map('strtolower', $classNames[1]))), 'structure class names remain unique');
foreach(['FlexValue_flex_phpdoc_probe', 'FlexValue_flex_phpdoc_probe_link', 'FlexRows_flex_phpdoc_probe_blocks', 'FlexRow_flex_phpdoc_probe_blocks_text', 'FlexRow_flex_phpdoc_probe_blocks_gallery', 'FlexRows_flex_phpdoc_probe_blocks_gallery_nested', 'FlexRow_flex_phpdoc_probe_blocks_gallery_nested_item', 'FlexPageimage_flex_phpdoc_probe_hero'] as $className) {
	$assert(str_contains($php, 'class ' . $className . ' extends '), 'readable class name: ' . $className);
}
$assert(!str_contains($php, '$layout'), 'ordinary fieldsets stay flat in PHPDoc');
$assert((bool) preg_match('/@property FlexImages\|FlexPageimage_flex_phpdoc_probe_hero\[\]\|FlexPageimage_flex_phpdoc_probe_hero\|null \$hero/', $php), 'single image includes raw collection and formatted nullable custom item');
$assert((bool) preg_match('/@method FlexRow_flex_phpdoc_probe_blocks_text\|FlexRow_flex_phpdoc_probe_blocks_gallery\|false first\(\)/', $php), 'mixed row first() exposes each row type');
$assert(!str_contains($php, 'site/templates'), 'stubs contain no source paths');
$assert(!str_contains($type->getConfigInputfields($docField)->getChildByName('flexPhpDoc')->value, '<?php'), 'admin escapes generated PHP');
$assert($type->getConfigInputfields($docField)->getChildByName('flexStructure') === null, 'PHPDoc replaces the old structure element');

$assert(str_contains($php, '@property string|LanguagesPageFieldValue $heading Heading label'), 'property PHPDoc includes the configured label');
$guide = $type->getConfigInputfields($docField)->getChildByName('flexPhpDoc')->value;
$same(3, substr_count($guide, '<li>'), 'PHPDoc guidance uses three numbered steps');
$assert(str_contains($guide, '@var FlexValue_flex_phpdoc_probe $flex_phpdoc_probe'), 'guidance annotates the field value rather than its Page');
$assert(str_contains($wire->sanitizer->unentities($guide), "$" . "flex_phpdoc_probe = \$page->get('flex_phpdoc_probe');"), 'guidance reads the owning field');
$assert(str_contains($wire->sanitizer->unentities($guide), '$value = $flex_phpdoc_probe->heading;'), 'guidance uses a real root property');
$same(2, substr_count($type->getConfigInputfields($emptyDocField)->getChildByName('flexPhpDoc')->value, '<li>'), 'empty definitions omit the property access step');

$helperPath = tempnam(dirname(__DIR__, 2) . '/.dev', 'phpdoc-test-');
$hook = $type->addHookAfter('getPhpDoc', function(HookEvent $event) use ($docField, $helperPath, $same): void {
	$same($docField, $event->arguments(0), 'PHPDoc hook receives the requested field');
	$event->return .= "\n// Export hook\n";
	file_put_contents($helperPath, $event->return, LOCK_EX);
});
try {
	$exported = $type->getPhpDoc($docField);
	$same($exported, file_get_contents($helperPath), 'after-hook can customize and save the complete PHP helper');
	$markup = $type->getConfigInputfields($docField)->getChildByName('flexPhpDoc')->value;
	$assert(str_contains($markup, '// Export hook'), 'Details tab uses the hookable PHPDoc method');
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($helperPath), $lintOutput, $lintCode);
	$same(0, $lintCode, 'exported helper is syntactically valid PHP');
} finally {
	$type->removeHook($hook);
	unlink($helperPath);
}

$definitionPath = $wire->config->paths->templates . 'flex-phpdoc-probe-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($definitionPath, '<?php namespace ProcessWire; if(!$page instanceof NullPage) throw new WireException("Expected NullPage"); return ["discovery" => ["type" => "text"]];');
try {
	$docField->flexDefinition = '';
	$docField->flexDefinitionFile = basename($definitionPath);
	$assert(str_contains($type->getPhpDoc($docField), '@property string $discovery'), 'public PHPDoc method evaluates PHP definitions with NullPage');
	$assert(str_contains($type->getConfigInputfields($docField)->getChildByName('flexPhpDoc')->value, '$discovery'), 'Details tab evaluates PHP definitions with NullPage');
} finally {
	unlink($definitionPath);
}
$docField->flexDefinitionFile = '';
$docField->flexDefinition = '{';
$throws(fn() => $type->getPhpDoc($docField), 'PHPDoc API propagates definition errors', FlexDefinitionException::class);
$assert($type->getConfigInputfields($docField)->getChildByName('flexPhpDoc') === null, 'invalid definitions retain source diagnostics without PHPDoc');
