<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__, 2);
$cases = json_decode(file_get_contents($root . '/tests/fixtures/row-titles.json'), true, 512, JSON_THROW_ON_ERROR);
$process = proc_open(['node', $root . '/tests/js/row-title-parts.cjs', '--json'], [
	0 => ['pipe', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
], $pipes, $root);
if(!is_resource($process)) throw new \RuntimeException('Unable to run production JavaScript title parser');
fclose($pipes[0]);
$json = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$same(0, proc_close($process), 'production JavaScript title vectors pass: ' . $errors);
$javascript = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$same(count($cases), count($javascript['parts']), 'both parsers run every shared title vector');
$input = $wire->modules->get('InputfieldFlexRows');
$parser = new \ReflectionMethod($input, 'titleParts');
foreach($cases as $index => $case) {
	$parts = $parser->invoke($input, $case['format'], $case['values'] ?? [], $case['number'] ?? 1, $case['type'] ?? '', $case['path'] ?? '');
	$same($case['text'], implode('', array_column($parts, 'text')), 'PHP title text: ' . $case['name']);
	$badges = [];
	$muted = [];
	foreach($parts as $part) {
		if($part['text'] === '') continue;
		if($part['badge']) $badges[] = $part['text'];
		if(!empty($part['muted'])) $muted[] = $part['text'];
	}
	$same($case['badges'] ?? [], $badges, 'PHP title badges: ' . $case['name']);
	$same($case['muted'] ?? [], $muted, 'PHP title de-emphasis: ' . $case['name']);
	$same($parts, $javascript['parts'][$index], 'complete PHP/JS title parts agree: ' . $case['name']);
}

// Exercise the final native renderer and the actual JS rowTitle fallback as well.
$titleField = $createField('flex_title_parity', [
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => ['heading' => ['type' => 'text']]]]],
]);
foreach($cases as $index => $case) {
	if(!isset($case['titleText'])) continue;
	$value = $type->getBlankValue($page, $titleField);
	$row = $value->items->addRow('item', $case['values'] ?? []);
	$editor = $titleField->getInputfield($page);
	$editor->value = $value;
	$input = $editor->inputs()->get($titleField->name . '__items');
	$definition = $input->definition;
	$definition['types']['item']['label'] = $case['type'] ?? 'Item';
	$definition['types']['item']['settings']['rowTitle'] = $case['format'];
	$input->definition = $definition;
	$dom = new \DOMDocument();
	$before = libxml_use_internal_errors(true);
	$dom->loadHTML('<html><head><meta charset="utf-8"></head><body>' . $input->renderRow($row, $case['number'] ?? 1) . '</body></html>');
	libxml_clear_errors();
	libxml_use_internal_errors($before);
	$xpath = new \DOMXPath($dom);
	$title = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " flex-row-title ")]')->item(0);
	$assert($title instanceof \DOMElement, 'native rendered title exists: ' . $case['name']);
	$same($case['titleText'], $title->textContent, 'native rendered title/fallback: ' . $case['name']);
	$same(implode('', array_column($javascript['titles'][$index], 'text')), $title->textContent, 'final PHP/JS title agrees: ' . $case['name']);
}
