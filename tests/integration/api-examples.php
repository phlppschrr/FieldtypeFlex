<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Execute the actual reference snippets in the existing disposable suite fixture.
$api = file_get_contents(dirname(__DIR__, 2) . '/API.md');
preg_match_all('/```php\n(.*?)```/s', $api, $matches);
$same(32, count($matches[1]), 'all API PHP examples have an execution context');
$objectExamples = array_values(array_filter($matches[1], static fn($code) => str_starts_with($code, '// Fixed object definition.')));
$matches[1] = array_values(array_filter($matches[1], static fn($code) => !in_array($code, $objectExamples, true)));
$metadataExamples = array_values(array_filter($matches[1], static fn($code) => str_starts_with($code, '// Per-file metadata definition.') || str_starts_with($code, '// Existing image in the gallery.')));
$matches[1] = array_values(array_filter($matches[1], static fn($code) => !in_array($code, $metadataExamples, true)));
$blocks = array_combine(range(1, count($matches[1])), $matches[1]);
$loadedSearchExample = $blocks[15];
$nestedSameRowExample = $blocks[23];
$excludedRowsExample = $blocks[24];
unset($blocks[15], $blocks[23], $blocks[24]);
$blocks = array_combine(range(1, count($blocks)), array_values($blocks));
$rowTitleFragment = $blocks[9];
$visibilityExample = $blocks[11];
$allRowsExample = $blocks[12];
$dependencyFragment = $blocks[23];
unset($blocks[9], $blocks[11], $blocks[12], $blocks[23]);
$blocks = array_combine(range(1, count($blocks)), array_values($blocks));
$modules = $wire->modules;
$fields = $wire->fields;
$snippet = static function(string $code, array $vars = []) use ($trackFile): array {
	$path = $trackFile(dirname(__DIR__, 2) . '/.dev/api-example-' . bin2hex(random_bytes(5)) . '.php');
	if(!str_starts_with(ltrim($code), '<?php')) $code = "<?php namespace ProcessWire;\n" . $code;
	file_put_contents($path, $code);
	$run = static function(string $path, array $vars): array {
		extract($vars);
		ob_start();
		try {
			$result = require $path;
			$output = ob_get_contents();
			return get_defined_vars();
		} finally {
			ob_end_clean();
		}
	};
	return $run($path, $vars);
};
$fragment = static fn(string $code): string => "return [\n" . $code . "\n];";
$rowTitleSettings = $snippet($fragment($rowTitleFragment))['result'];
$same('[{@path} › {@type}] #n: _{heading|title}_', $rowTitleSettings['rowTitle'], 'API rowTitle fragment executes as native Rows settings');
$dependencySettings = $snippet($fragment($dependencyFragment))['result'];
$same('parent.show_details=1, forpage.feature_enabled=1', $dependencySettings['details']['showIf'], 'API dependency fragment declares containing and main Page contexts');
$same('root.details_required=1', $dependencySettings['details']['requiredIf'], 'API dependency fragment declares root requiredIf');
$base = $snippet($blocks[5])['result'];
$same(['heading', 'hero'], array_keys($base), 'API complete definition executes');
$rowSchema = $snippet($fragment($blocks[8]))['result'];
$mediaSchema = $snippet($fragment($blocks[14]))['result'];
$renameSchema = $snippet($fragment($blocks[7]))['result'];
$inheritSchema = $snippet($fragment(str_replace('rich_text_source', $sourceField->name, $blocks[20])))['result'];
$metadataSchema = $snippet($fragment($metadataExamples[0]))['result'];
$objectSchema = $snippet($fragment($objectExamples[0]))['result'];
$schema = $base + $objectSchema + $rowSchema + $mediaSchema + $inheritSchema + $metadataSchema + [
	'summary' => ['type' => 'text'],
	'appearance' => ['type' => 'select', 'label' => 'Appearance & style', 'options' => ['featured' => 'Featured & selected']],
	'starts_on' => ['type' => 'datetime', 'dateInputFormat' => 'd.m.Y', 'dateOutputFormat' => 'd.m.Y'],
];
$nestedReference = require dirname(__DIR__, 2) . '/examples/08-rows.php';
$schema['sections'] = $nestedReference['sections'];
$apiField = $createField('flex_api_examples', $schema);
$name = $apiField->name;
$page->of(false);
$page->$name = ['heading' => 'A & B', 'appearance' => 'featured', 'starts_on' => '2026-10-18'];
$value = $page->get($name);
$assert($value->link instanceof FlexValue, 'API object definition exposes a fixed FlexValue');
$row = $value->blocks->addRow('text', ['heading' => 'Introduction', 'body' => '<p>ProcessWire</p>']);
$page->save($name);
$context = compact('page', 'modules', 'fields', 'row') + ['field' => $apiField, 'pages' => $wire->pages];
$execute = static function(int $n, array $vars = []) use ($blocks, $snippet, $context, $name): array {
	$code = str_replace(['$page->content', "'content'", 'content.'], ['$page->' . $name, "'$name'", "$name."], $blocks[$n]);
	return $snippet($code, $vars + $context);
};
$r = $execute(1);
$assert($r['value'] instanceof FlexValue && $r['blocks'] instanceof FlexRows && $r['row'] === $row, 'API initial reads preserve runtime objects');
$r = $execute(2);
$same('A &amp; B', $r['output'], 'API formatted echo is encoded once');
$same('A & B', $r['rawHeading'], 'API getUnformatted returns raw text');
$same('18.10.2026', $r['display']['starts_on'], 'API getArray formats dates');
$same($r['raw'], json_decode($r['json'], true), 'API JSON serializes canonical data');
$same('A & B', $value->heading, 'retained unformatted value is independent of formatted view');
$execute(3);
$same('Introduction', $wire->pages->getFresh($id)->getUnformatted($name)->heading, 'API assignments actually save');
$execute(4);
$r = $execute(6);
$assert($r['input'] instanceof InputfieldText && $r['tree'] instanceof InputfieldWrapper, 'API schema inspection returns native inputs');
$assert($r['input'] !== $r['defs']->getPropertyInputfield('blocks.text.heading'), 'API metadata inputs are fresh');
$renameField = $createField('flex_api_rename', $renameSchema);
$renamed = $type->sanitizeValue($page, $renameField, ['title' => 'Old title']);
$same('Old title', $renamed->heading, 'API rename fragment hydrates old key');
$throws(fn() => $type->sanitizeValue($page, $renameField, ['title' => 'Old', 'heading' => 'New']), 'API rename conflicts are WireExceptions');
$execute(9);
$beforeCount = count($page->get($name)->blocks);
$execute(10);
$same($beforeCount + 1, count($wire->pages->getFresh($id)->getUnformatted($name)->blocks), 'API row add/copy/move/remove sequence saves one new row');
$r = $execute(11);
$same([], $r['images'], 'API type helper returns empty numeric array for absent type');
$same($beforeCount + 1, $r['count'], 'API type count includes current rows');
$execute(12);
$fresh = $wire->pages->getFresh($id)->getUnformatted($name);
$same('A note', $fresh->sections->first()->blocks->first()->notes->first()->text, 'API nested example persists third-level value');
$searchCode = str_replace('$page->content', '$page->' . $name, $loadedSearchExample);
$r = $snippet($searchCode, ['block' => $fresh->sections->first()->blocks->first()] + $context);
$assert($r['images'] instanceof FlexRows && $r['intro'] instanceof FlexRows, 'API loaded search returns Row collections');
$same(1, count($r['intro']), 'API loaded same-row search matches the existing row');
$same(0, count($r['notes']), 'API nested loaded search uses its concrete parent collection');
$inputs = $wire->wire(new InputfieldWrapper());
$execute(13, compact('inputs'));
$nativeDefs = new FlexFieldDefs($apiField, $page, $inputs);
$assert($nativeDefs->getPropertyInputfield('blocks.text.heading') instanceof InputfieldText, 'API native wrapper fragment builds typed schema');
$assert($type->getDefinitions($apiField, $page)->getPropertyInputfield('body') instanceof InputfieldTinyMCE, 'API inheritance fragment builds with same-type source');

$imagePath = $trackFile(dirname(__DIR__, 2) . '/.dev/api-example-' . bin2hex(random_bytes(5)) . '.png');
$im = imagecreatetruecolor(16, 16);
imagepng($im, $imagePath);
imagedestroy($im);
$page->of(false);
$page->get($name)->hero->add($imagePath);
$page->get($name)->gallery->add($imagePath);
$page->save($name);
$page->of(true);
$r = $execute(15);
$assert($r['image'] instanceof Pageimage && $r['images'] instanceof FlexImages, 'API formatted single and raw collection example types');
$assert($r['output'] !== '' && count($r['images']) === 1, 'API image resize creates a usable variation URL');
$snippet(str_replace(['$page->content', "'content'"], ['$page->' . $name, "'$name'"], $metadataExamples[1]), $context);
$same('Example Photographer', $wire->pages->getFresh($id)->getUnformatted($name)->gallery->first()->copyright, 'API per-file custom metadata example persists direct values');
$page->of(false);
$localPDF = $trackFile(dirname(__DIR__, 2) . '/.dev/api-example-' . bin2hex(random_bytes(5)) . '.pdf');
file_put_contents($localPDF, "%PDF-1.4\n%%EOF\n");
$snippet(str_replace(['$page->content', "'content'", '/absolute/path/to/document.pdf'], ['$page->' . $name, "'$name'", $localPDF], $blocks[16]), $context);
$same(1, count($wire->pages->getFresh($id)->getUnformatted($name)->downloads), 'API local import persists with field save');
$execute(17);
$execute(18);
$snippet(str_replace('content.', "$name.", $nestedSameRowExample), $context);
$r = $snippet(str_replace('content.', "$name.", $excludedRowsExample), $context);
$assert($r['matches'] instanceof PageArray && !$r['matches']->has($page), 'API native owner-ID exclusion removes the matching Page');
$assert($wire->pages->findIDs("id=$id, @$name.blocks.text.heading=Introduction, @$name.blocks.text.body*=ProcessWire") === [$id], 'API same-row selector matches saved row');
// Prove the distinction between independent predicates and a single concrete row.
$page->of(false);
$page->get($name)->blocks->removeAll();
$page->get($name)->blocks->addRow('text', ['heading' => 'Introduction']);
$page->get($name)->blocks->addRow('text', ['body' => 'ProcessWire']);
$page->save($name);
$same([$id], $wire->pages->findIDs("id=$id, $name.blocks.text.heading=Introduction, $name.blocks.text.body*=ProcessWire"), 'separate API row predicates can match different rows');
$same([], $wire->pages->findIDs("id=$id, @$name.blocks.text.heading=Introduction, @$name.blocks.text.body*=ProcessWire"), 'API same-row selector rejects split matches');
$copy = $wire->pages->clone($page);
$pages[] = $copy;
$execute(21, compact('copy'));
$assert(!method_exists($type, 'collectFiles') && !method_exists($type, 'rebuildRowIndex'), 'public module has no manual projection or collection wrappers');
$r = $execute(22);
$assert($r['inventory']['success'] && $r['cleanup']['success'], 'API batched rename and selected cleanup examples execute');

// The traversal snippet queries actual native target fields and both reference modes.
$price = $wire->wire(new Field());
$price->name = 'flex_api_price_' . bin2hex(random_bytes(4));
$price->type = $modules->get('FieldtypeFloat');
$temporaryFields[] = $price;
$price->save();
$template->fieldgroup->add($price);
$template->fieldgroup->save();
$second->of(false);
$second->title = 'Flour';
$second->set($price->name, 2.75);
$second->save();
$recipe = $createField('flex_api_recipe', [
	'ingredient' => ['type' => 'page', 'derefAsPage' => 2],
	'ingredients' => ['type' => 'page', 'derefAsPage' => 0],
]);
$page->of(false);
$page->set($recipe->name, ['ingredient' => $second->id, 'ingredients' => [$second->id]]);
$page->save($recipe->name);
$snippet(str_replace(['recipe.', '.price'], [$recipe->name . '.', '.' . $price->name], $blocks[19]), $context);
foreach(['ingredient.title=Flour', 'ingredients.title~=Flour', 'ingredient.' . $price->name . '>=2.5'] as $condition) {
	$same([$id], $wire->pages->findIDs($recipe->name . '.' . $condition), 'API traversal snippet finds the recipe through ' . $condition);
}

// JSON views preserve logical data; the database encoder additionally preserves map shape.
$emptyField = clone $apiField;
$emptyField->flexDefinition = '{}';
$emptyValue = $type->getBlankValue($page, $emptyField);
$same([], $emptyValue->rawArray(), 'empty API value has an empty PHP property array');
$same('[]', json_encode($emptyValue), 'empty API JSON view follows PHP array shape');
$same('{}', FlexStorage::encode($emptyValue->rawArray()), 'database JSON preserves an empty property object');

// Visibility examples use the same live owner and save through the public API.
$visibilityCode = str_replace(['$page->content', "'content'"], ['$page->' . $name, "'$name'"], $visibilityExample);
$snippet($visibilityCode, $context);
$same(true, $wire->pages->getFresh($id)->getUnformatted($name)->blocks->first()->isHidden(), 'API visibility example persists hidden state');
$r = $snippet(str_replace("'content'", "'$name'", $allRowsExample), $context);
$same(2, count($r['allRows']), 'API explicit unformatted access includes hidden rows');
