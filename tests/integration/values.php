<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$value = $page->flex_test;
$assert($value instanceof FlexValue, 'typed runtime value');
$same('Hanna <Flex>', $value->name, 'JSON database roundtrip');
$same(12, $value->age, 'integer hydration');
$same(2.75, $value->price, 'float hydration');
$same(['a', 'b'], $value->tags, 'multiple option hydration');
$same(1, $value->enabled, 'checkbox integer');
$same('yes', $value->custom_check, 'custom checkbox value');
$same('Berlin', $value->city, 'fieldset data stays flat');
$same(null, $value->group, 'fieldset is not a stored property');
$same('', $value->default_text, 'explicit blank does not restore default');
$same('Default', $type->getBlankValue($page, $field)->default_text, 'default for absent key');
$same(strtotime('2026-10-14'), $value->date, 'canonical date');
$assert($value->category instanceof Page && $value->category->id === 1, 'single Page hydration');
$assert($value->categories instanceof PageArray && count($value->categories) === 2, 'PageArray hydration');
$same('Full name', $value->label('name'), 'property label');
$same('Germany', $value->optionLabel('country', 'DE'), 'option label');
$assert(!array_key_exists('markup', $value->rawArray()), 'markup is presentation only');
$page->of(true);
$same('Hanna <Flex>', $page->flex_test->name, 'unconfigured text has no implicit escaping');
$same('14.10.2026', $page->flex_test->date, 'date output format');
$same('Hanna <Flex>', iterator_to_array($page->flex_test)['name'], 'formatted iteration uses native text rules');
$throws(fn() => $page->flex_test->set('name', 'bad'), 'formatted writes rejected');
$page->of(false);
$same('Hanna <Flex>', $page->flex_test->name, 'output formatting never mutates raw data');
$page->flex_test->email = 'changed@example.test';
$assert($page->isChanged('flex_test'), 'nested mutation marks owner changed');
$page->save();
$wire->pages->uncache($page);
$page = $wire->pages->get($id);
$page->of(false);
$same('changed@example.test', $page->flex_test->email, 'nested mutation persisted by full page save');
$throws(fn() => $page->flex_test->set('unknown', 'x'), 'unknown API property rejected');
$page->flex_test->set('age', 'twelve');
$same('', $page->flex_test->age, 'invalid numeric API input sanitizes to blank');
$page->flex_test->set('date', 'not-a-date');
$same('', $page->flex_test->date, 'invalid date API input sanitizes to blank');
$page->flex_test->set('date', 0);
$same('', $page->flex_test->date, 'numeric 0 date becomes blank matching FieldtypeDatetime');
$prevTz = date_default_timezone_get();
date_default_timezone_set('UTC');
try {
	$page->flex_test->set('date', '1970-01-01');
	$same('', $page->flex_test->rawArray()['date'], 'Unix epoch 0 ISO date sanitizes to blank matching FieldtypeDatetime::sleepValue()');
	$page->flex_test->set('date', new \DateTime('@0'));
	$same('', $page->flex_test->rawArray()['date'], 'DateTime(@0) sanitizes to blank matching FieldtypeDatetime::sleepValue()');
} finally {
	date_default_timezone_set($prevTz);
}
