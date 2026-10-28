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
