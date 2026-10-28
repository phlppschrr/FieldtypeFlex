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
$same('2026-10-14', $value->date, 'canonical date');
$assert($value->category instanceof Page && $value->category->id === 1, 'single Page hydration');
$assert($value->categories instanceof PageArray && count($value->categories) === 2, 'PageArray hydration');
$same('Full name', $value->label('name'), 'property label');
$same('Germany', $value->optionLabel('country', 'DE'), 'option label');
$assert(!array_key_exists('markup', $value->rawArray()), 'markup is presentation only');
$page->of(true);
$same('Hanna &lt;Flex&gt;', $page->flex_test->name, 'entity encoded output');
$same('14.10.2026', $page->flex_test->date, 'date output format');
$same('Hanna &lt;Flex&gt;', iterator_to_array($page->flex_test)['name'], 'formatted iteration');
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
$throws(fn() => $page->flex_test->set('age', 'twelve'), 'invalid numeric API input rejected');
