<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($sameJson, $createField)) { http_response_code(404); exit; }

foreach([
	['{"a":1,"b":2}', '{"b":2,"a":1}'],
	['{"rows":[{"id":"first","values":{"a":0,"b":false}},{"id":"second"}]}', '{"rows":[{"values":{"b":false,"a":0},"id":"first"},{"id":"second"}]}'],
	['{"0":{},"1":[]}', '{"1":[],"0":{}}'],
	['{"01":1,"1":2,"00":3,"0":4}', '{"0":4,"00":3,"1":2,"01":1}'],
] as $index => [$left, $right]) {
	$sameJson($left, $right, "JSON comparison ignores only object key order ($index)");
}
foreach([
	['{}', '[]'], ['{"0":"a"}', '["a"]'],
	['{"nested":[{}]}', '{"nested":[[]]}'],
	['[1,2]', '[2,1]'], ['[{"id":"a"},{"id":"b"}]', '[{"id":"b"},{"id":"a"}]'],
	['1', '"1"'], ['1', '1.0'], ['0', 'false'], ['null', '""'],
	['{"unknown":null}', '{}'], ['{"unknown":{"keep":true}}', '{"unknown":{}}'],
	['{"media":{"asset":"a"}}', '{"media":{"asset":"b"}}'],
] as $index => [$left, $right]) {
	$assert(!flexTestJsonEquals($left, $right), "JSON comparison rejects changed order, shape, type or value ($index)");
}
$throws(fn() => flexTestJsonEquals('{', '{}'), 'JSON comparison rejects invalid expected JSON', \JsonException::class);
$throws(fn() => flexTestJsonEquals('{}', '{'), 'JSON comparison rejects invalid actual JSON', \JsonException::class);

// Exercise the database's real JSON representation, including nested unknown values.
$document = '{"unknown":{"empty":{},"list":[],"numeric":{"0":"keep"},"nested":[{},[],null,false,0,1.0,1.5,"0"]},"rows":{"_flex":"rows:1","items":[{"id":"first","type":"text","values":{"media":{"_flex":"files:1","items":[{"asset":"keep","values":{}}]},"unknown":{"b":2,"a":1}}},{"id":"second","type":"text","values":{}}]}}';
$db = $wire->database;
$db->exec('CREATE TEMPORARY TABLE flex_json_comparison (data JSON NOT NULL)');
try {
	$db->prepare('INSERT INTO flex_json_comparison (data) VALUES (?)')->execute([$document]);
	$stored = $db->query('SELECT data FROM flex_json_comparison')->fetchColumn();
	$sameJson($document, $stored, 'database JSON roundtrip retains nested Rows, media, unknown values and object/list shapes');
} finally {
	$db->exec('DROP TEMPORARY TABLE flex_json_comparison');
}
