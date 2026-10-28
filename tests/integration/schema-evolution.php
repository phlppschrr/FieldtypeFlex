<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$renamed = clone $field;
$renamed->flexDefinition = json_encode([
	'new_name' => [
		'type'        => 'text',
		'renamedFrom' => 'old_name',
	],
]);
$renamedValue = $type->sanitizeValue($page, $renamed, [
	'old_name' => 'Preserved',
	'removed'  => 'Obsolete',
]);
$same(['removed' => 'Obsolete', 'new_name' => 'Preserved'], $renamedValue->rawArray(), 'rename and permanent unknown preservation');
$throws(fn() => $type->sanitizeValue($page, $renamed, [
	'old_name' => 'Old',
	'new_name' => 'New',
]), 'rename collision prevents data loss');
$renamed->flexPrune = 0;
$same('Keep', $type->sanitizeValue($page, $renamed, ['removed' => 'Keep'])->rawArray()['removed'], 'dynamic-schema preservation mode');

echo "\n$passed assertions passed on ProcessWire {$wire->config->version}, PHP " . PHP_VERSION . ".\n";
