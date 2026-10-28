<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}
$copyField = $createField('flex_copy_limits', [
	'heading' => ['type' => 'text', 'minlength' => 3, 'maxlength' => 0],
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => [
		'heading' => ['type' => 'text', 'minlength' => 3, 'maxlength' => 0],
	]]]],
]);
$value = $type->getBlankValue($page, $copyField);
$value->heading = 'Valid';
$row = $value->items->addRow('item', ['heading' => 'Valid']);
$page->set($copyField->name, $value);
$page->save($copyField->name);
$source = $value->definitions()->property('heading')['input'];
$copy = FlexDependencies::copyInput($source);
$same(3, (int) $copy->attr('minlength'), 'fresh native copy retains semantic minlength');
$same(0, (int) $copy->attr('maxlength'), 'fresh native copy retains unlimited maxlength');
$input = $copyField->getInputfield($page);
$input->value = $value;
$html = $input->render();
preg_match('/name="' . $copyField->name . '__0draft" value="([a-f0-9]{64})"/', $html, $match);
$draft = $match[1];
try {
	$post = [
		$copyField->name . '__0draft' => $draft,
		$copyField->name . '__0complete' => $draft,
		$copyField->name . '__heading' => 'No',
		FlexPath::controlName($copyField->name, 'items', 'present') => '1',
		FlexPath::controlName($copyField->name, 'items', 'order') => $row->rowId(),
		FlexPath::inputPrefix($copyField->name, $row->address()) . 'heading' => 'No',
	];
	$input->processInput($wire->wire(new WireInputData($post)));
	$assert(count($input->getErrors()) >= 2, 'native processing rejects too-short root and row values');
	$same('Valid', $wire->pages->getFresh($page->id)->getUnformatted($copyField->name)->heading, 'failed native length validation leaves storage unchanged');
	$input->inputs()->getErrors(true);
	$input->getErrors(true);
	$post[$copyField->name . '__heading'] = str_repeat('x', 3000);
	$post[FlexPath::inputPrefix($copyField->name, $row->address()) . 'heading'] = str_repeat('y', 3000);
	$input = $copyField->getInputfield($page);
	$input->value = $value;
	$input->processInput($wire->wire(new WireInputData($post)));
	$same([], $input->getErrors(), 'unlimited maximum remains unlimited during native processing');
	$same(3000, strlen($input->value->heading), 'unlimited native copy does not truncate root text');
	$same(3000, strlen($input->value->items->first()->heading), 'unlimited native copy does not truncate row text');
} finally {
	$type->admin()->forget($draft);
}
