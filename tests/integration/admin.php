<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$input = $field->getInputfield($page);
$input->value = $page->flex_test;
$html = $input->render();
$assert(str_contains($html, 'flex_test__name') && str_contains($html, 'flex_test__city'), 'native editor renders namespaced inputs');
$assert(str_contains($html, 'name="flex_test__date"') && str_contains($html, '2026-10-14'), 'datetime helper uses fresh input owner and value');
$assert(str_contains($html, 'flex_test__enabled=1'), 'dependency references are namespaced');
$postData = [
	'flex_test__name'                 => 'New name',
	'flex_test__email'                => 'new@example.test',
	'flex_test__age'                  => '9',
	'flex_test__price'                => '1.25',
	'flex_test__country'              => 'US',
	'flex_test__tags'                 => ['b'],
	'flex_test__custom_check'         => 'yes',
	'flex_test__conditional'          => 'Hidden attack',
	'flex_test__required_conditional' => '',
	'flex_test__date'                 => '2026-10-15',
	'flex_test__city'                 => 'Paris',
	'flex_test__category'             => '2',
	'flex_test__categories'           => ['2'],
	'flex_test__default_text'         => '',
];
$post = $wire->wire(new WireInputData($postData));
$input->processInput($post);
$same('New name', $input->value->name, 'editor processes text');
$same(strtotime('2026-10-15'), $input->value->date, 'editor processes namespaced date');
$same(2, $input->value->category->id, 'editor validates selectable Page references');
$same(0, $input->value->enabled, 'unchecked checkbox clears');
$same('Keep', $input->value->conditional, 'hidden showIf ignores submitted value');
$same([], $input->getErrors(), 'requiredIf false is not required');
$page->flex_test = $input->value;
$page->save('flex_test');
$same('New name', $page->flex_test->name, 'processed value saves');

require __DIR__ . '/dependencies.php';
