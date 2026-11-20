<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$find = static fn(string $selector) => $wire->pages->findIDs("id=$id, $selector");
foreach([
	'flex_test.name="Hanna <Flex>"',
	'flex_test.name*=anna',
	'flex_test.name^=Hanna',
	'flex_test.name$="<Flex>"',
	'flex_test.age>10',
	'flex_test.age<100',
	'flex_test.price>=2.5',
	'flex_test.country=DE',
	'flex_test.tags=a',
	'flex_test.category=1',
	'flex_test.categories=2',
	'flex_test.date>2020-09-01',
	'flex_test*=Berlin',
	'flex_test.name~=Hanna',
	'flex_test.default_text=',
	'flex_test.email!=other@example.test',
] as $selector) {
	$same([$id], $find($selector), "selector: $selector");
}
$same([], $find('flex_test.tags!=a'), 'array inequality means non-membership');
$same([], $find('flex_test.country!=DE'), 'scalar inequality excludes matching row');
$same([], $find('flex_test.country='), 'empty selector excludes populated property');
$same([], $find('flex_test.default_text!='), 'non-empty selector excludes blank property');
$same([], $find('flex_test.name*=100%'), 'literal LIKE percent');
$same([], $find('flex_test.age>100'), 'numeric SQL comparison');
$throws(fn() => $find('flex_test.unknown=x'), 'unknown selector property rejected');
$info = $type->getSelectorInfo($field);
$same('number', $info['subfields']['age']['input'], 'Lister numeric discovery');
$same('page', $info['subfields']['category']['input'], 'Lister page discovery');
$assert(isset($info['subfields']['city']) && !isset($info['subfields']['group']), 'Lister sees flattened properties');

$same([$second->id], $wire->pages->findIDs("id=$ids, flex_test.tags="), 'array blank matches only empty array');
$same([$id], $wire->pages->findIDs("id=$ids, flex_test.tags!="), 'array non-blank excludes empty array');
$same([$second->id], $wire->pages->findIDs("id=$ids, flex_test.category="), 'blank Page reference');
$same([$second->id], $wire->pages->findIDs("id=$ids, flex_test.age=0"), 'zero is numerically queryable');
$same([$second->id, $id], $wire->pages->findIDs("id=$ids, sort=flex_test.age"), 'numeric subproperty sorting');
$same([$id], $wire->pages->findIDs("id=$id, flex_test.country=DE|US"), 'selector OR values');
$same([], $wire->pages->findIDs("id=$id, flex_test.country!=DE|US"), 'inequality OR excludes either value');
$same([], $wire->pages->findIDs("id=$id, flex_test*=default_text"), 'whole-field search ignores JSON key names');
$same([], $wire->pages->findIDs("id=$id, !flex_test.country=DE"), 'native negated equality excludes matching values');

$delete = $wire->database->prepare('DELETE FROM `' . $field->table . '` WHERE pages_id=:id');
$delete->execute(['id' => $second->id]);
$same([$second->id], $wire->pages->findIDs("id=$second->id, flex_test.country="), 'empty matches absent field row');
$same([$second->id], $wire->pages->findIDs("id=$second->id, flex_test.country!=DE"), 'inequality matches absent field row');
$same([], $wire->pages->findIDs("id=$second->id, flex_test.country!="), 'non-blank excludes absent row');
$second->flex_test = $page->flex_test;
$second->resetTrackChanges();
$page->resetTrackChanges();
$second->flex_test->name = 'Rebound owner';
$assert($second->isChanged('flex_test') && !$page->isChanged('flex_test'), 'assigning FlexValue rebinds the owning page');
$same('Hanna <Flex>', $page->flex_test->name, 'cross-page value assignment keeps source independent');
$selectorInput = $wire->modules->get('InputfieldSelector');
$selectorInput->name = 'flex_selector';
$selectorInput->value = 'flex_test.category=2, flex_test.age>0';
$selectorHtml = $selectorInput->render();
$assert(str_contains($selectorHtml, 'category') && str_contains($selectorHtml, 'age'), 'real InputfieldSelector renders logical Page and numeric properties');

// Each query predicate and sort expression evaluates definitions once instead of repeatedly.
$defFile = $wire->config->paths->templates . 'flex-query-count-' . bin2hex(random_bytes(4)) . '.php';
$trackFile($defFile);
file_put_contents($defFile, '<?php namespace ProcessWire;
$GLOBALS["__flex_query_def_loads"] = ($GLOBALS["__flex_query_def_loads"] ?? 0) + 1;
return [
	"name" => ["type" => "text"],
	"meta" => ["type" => "object", "children" => ["note" => ["type" => "text"]]],
	"photo" => ["type" => "image"],
	"blocks" => ["type" => "rows", "types" => ["text" => ["children" => ["heading" => ["type" => "text"]]]]],
];');
$countedField = $createField('flex_qcount', []);
$countedField->flexDefinition = '';
$countedField->flexDefinitionFile = basename($defFile);
$countedField->save();
try {
	foreach([
		[$countedField->name . '.name=Alpha', 1, 'scalar equality evaluates definitions once'],
		[$countedField->name . '.name!=Alpha', 1, 'scalar inequality with inner recursion evaluates definitions once'],
		[$countedField->name . '.name=', 1, 'scalar blank check with inner recursion evaluates definitions once'],
		// Multi-dot selectors also trigger one PageFinder::isPageField() getBlankValue() probe outside FlexQuery.
		[$countedField->name . '.meta.note!=Alpha', 2, 'nested object inequality evaluates definitions once in FlexQuery'],
		[$countedField->name . '.photo.count>0', 2, 'media count evaluates definitions once in FlexQuery'],
		[$countedField->name . '.blocks.text.heading=Alpha', 2, 'rows predicate evaluates definitions once in FlexQuery'],
		// PageFinder::getQuerySort() also calls getBlankValue() once before getMatchQuerySort().
		['sort=' . $countedField->name . '.name', 2, 'scalar sort evaluates definitions once in FlexQuery'],
		['sort=' . $countedField->name . '.meta.note', 3, 'nested object sort evaluates once in path validation and once in FlexQuery::sort'],
	] as [$expr, $expected, $label]) {
		$GLOBALS['__flex_query_def_loads'] = 0;
		$wire->pages->findIDs("id=$id, $expr");
		$same($expected, $GLOBALS['__flex_query_def_loads'], $label);
	}
} finally {
	unset($GLOBALS['__flex_query_def_loads']);
}
