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
if(!is_resource($process)) throw new \RuntimeException('Unable to run production JavaScript title evaluator');
fclose($pipes[0]);
$json = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$same(0, proc_close($process), 'production JavaScript title vectors pass: ' . $errors);
$javascript = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$same(count($cases), count($javascript), 'both evaluators run every shared title vector');
foreach($cases as $index => $case) {
	$nodes = FlexRowTitle::compile($case['format']);
	$same($case['nodes'], $nodes, 'PHP compiles the nodes consumed by JavaScript: ' . $case['name']);
	$parts = FlexRowTitle::parts($nodes, $case['values'] + ['@index' => $case['number']]);
	$same($javascript[$index], $parts, 'complete PHP/JS title parts agree: ' . $case['name']);
}
$same('&lt;img src=x&gt; {@index} [literal]', FlexRowTitle::markup($javascript[count($javascript) - 2]), 'rendered title values cannot introduce HTML');

$children = ['heading' => ['type' => 'text']];
$sections = [
	'type' => 'rows',
	'types' => [
		'normal' => ['label' => 'Ordinary', 'rowTitle' => '{heading}{.flex-strong}[ · {image.description}]', 'children' => $children + ['image' => ['type' => 'image']]],
		'hooked' => ['label' => 'Hooked', 'rowTitle' => '#{@index} {heading}[ · {@details|"Default"}]{.custom} {details.caption}[ · {@asset}]', 'children' => $children + [
			'details' => ['type' => 'object', 'children' => ['caption' => ['type' => 'text', 'showIf' => 'page.title=Title gate']]],
			'image' => ['type' => 'image', 'customFields' => ['description' => ['type' => 'text']]],
			'children' => ['type' => 'rows', 'rowLazyLoad' => true, 'types' => [
				'child' => ['label' => 'Child', 'rowTitle' => '[{@path} / ]{heading}[ · {parent.heading} / {root.heading}]{.flex-muted}', 'children' => $children],
			]],
		]],
		'override' => ['label' => 'Override', 'rowTitle' => '[#{@index}]{.flex-muted}', 'children' => $children],
	],
];
$titleField = $createField('flex_title_hooks', $children + ['items' => $sections]);
$name = $titleField->name;
$value = $page->getUnformatted($name);
$value->heading = 'Stored root';
$ordinary = $value->items->addRow('normal', ['heading' => 'Ordinary caption']);
$hooked = $value->items->addRow('hooked', ['heading' => 'Stored heading']);
$hooked->details->caption = 'Stored detail';
$child = $hooked->children->addRow('child', ['heading' => 'Stored child']);
$override = $value->items->addRow('override', ['heading' => '<em>Plain text</em>']);
$conditional = $value->items->addRow('override');
$imagePath = $trackFile($root . '/.dev/title-preview-' . bin2hex(random_bytes(5)) . '.png');
$imageData = imagecreatetruecolor(16, 16);
imagepng($imageData, $imagePath);
$ordinary->image->add($imagePath);
$ordinary->image->first()->description = 'Ordinary image';
$hooked->image->add($imagePath);
$asset = $hooked->image->first();
$asset->description = 'Stored image';
$page->save($name);
$before = $type->storage()->persisted($page, $titleField);
$previewStates = [];
$valuesHook = $wire->addHookAfter('InputfieldFlexRows::rowTitleValues', static function(HookEvent $event) use ($name, $type, &$previewStates) {
	[$row, $root, $parent] = $event->arguments();
	if($row->ownerField()->name !== $name || $row->rowType() !== 'hooked') return;
	$previewStates[] = $type->admin()->previewingRowTitles();
	$child = $row->children->first();
	$event->return = ['@details' => $root->heading . ' / ' . $row->details->caption . ' / ' . ($child ? $child->heading : ''), '@index' => 'invalid', '@asset' => $row->image->count() ? $row->image->first()->description() : 'No image'];
});
$partsHook = $wire->addHookAfter('InputfieldFlexRows::rowTitleParts', static function(HookEvent $event) use ($name) {
	[$row, $parts, $root, $parent] = $event->arguments();
	if($row->ownerField()->name !== $name || $row->rowType() !== 'override') return;
	$event->return = $row->heading === '' ? $parts : array_merge($parts, [['text' => ' ' . $row->heading,
		'classes' => ['custom', 'bad" onclick="alert(1)', 'flex-strong']]]);
});
$savedPost = $wire->input->post->getArray();
$savedServer = $_SERVER;
$draft = '';
try {
	$editor = $titleField->getInputfield($page);
	$editor->value = $value;
	$html = $editor->render();
	$document = new \DOMDocument();
	$previous = libxml_use_internal_errors(true);
	$document->loadHTML('<html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	$xpath = new \DOMXPath($document);
	$model = static fn(FlexRow $row) => $xpath->query('//*[@data-flex-row="' . $row->rowId() . '"]')->item(0);
	$assert(!$model($ordinary)->hasAttribute('data-flex-title-refresh'), 'ordinary rows never opt in to title requests');
	$assert(str_contains($html, '<span class="flex-strong">Ordinary caption</span> · Ordinary image'), 'the first image description resolves as a declared media property');
	$assert($xpath->query('//*[@data-flex-external-dependencies="title"]')->length > 0, 'external title preview dependencies are declared from native conditions');
	$assert($model($hooked)->hasAttribute('data-flex-title-refresh') && $model($override)->hasAttribute('data-flex-title-refresh'), 'both PHP hooks opt in to editor refresh');
	$assert($model($conditional)->hasAttribute('data-flex-title-refresh'), 'an override may keep the computed parts until a field changes');
	$assert(str_contains($html, '#2 Stored heading<span class="custom"> · Stored root / Stored detail / Stored child</span> Stored detail'), 'server renders custom values and keeps the reserved index');
	$assert(str_contains($html, '<span class="flex-muted">#3</span><span class="custom flex-strong"> &lt;em&gt;Plain text&lt;/em&gt;</span>'), 'server escapes override text and validates each class');
	$assert(str_contains($html, 'Hooked / Stored child<span class="flex-muted"> · Stored heading / Stored root</span>'), 'nested locally rendered title resolves scopes and ancestor labels');
	$same('0', $model($child)->getAttribute('data-flex-loaded'), 'title rendering leaves the child body lazy');
	$form = [];
	foreach($xpath->query('//input[@name]') as $input) {
		if(in_array($input->getAttribute('type'), ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) continue;
		$form[$input->getAttribute('name')] = $input->getAttribute('value');
	}
	$draft = $form[$name . '__0draft'];
	$csrf = $wire->session->CSRF;
	$ajax = static function(array $data, bool $withCsrf = true) use ($wire, $type, $page, $name, $draft, $csrf): array {
		$_SERVER['HTTP_X_FLEX_DRAFT'] = $draft;
		$_SERVER['HTTP_X_FIELDNAME'] = $name;
		if($withCsrf) $data[$csrf->getTokenName()] = $csrf->getTokenValue();
		$wire->input->post->removeAll()->setArray($data);
		ob_start();
		try {
			$type->admin()->ajax(new HookEvent(['arguments' => [$page]]));
			return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
		} finally { ob_end_clean(); }
	};
	$post = array_replace($form, [
		$name . '__heading' => 'Pending root',
		'title' => 'Title gate',
		FlexPath::inputPrefix($name, $asset->metadataInputValue()->address()) . 'description' => 'Pending image description',
		FlexPath::inputPrefix($name, $hooked->address()) . 'heading' => 'Pending heading',
		FlexPath::inputPrefix($name, $hooked->details->address()) . 'caption' => 'Pending detail',
		FlexPath::inputPrefix($name, $override->address()) . 'heading' => 'Pending override',
		FlexPath::inputPrefix($name, $conditional->address()) . 'heading' => 'Activate override',
	]);
	$request = ['flex_action' => 'row-titles', 'flex_complete' => $draft, 'flex_title_rows' => json_encode([
		['items', $hooked->rowId()], ['items', $override->rowId()], ['items', $conditional->rowId()],
	], JSON_THROW_ON_ERROR)];
	$same(true, $ajax($request + $post, false)['error'], 'title previews require CSRF');
	$same(true, $ajax($request + array_diff_key($post, [$name . '__0complete' => true]))['error'], 'incomplete title snapshots are rejected');
	$same(true, $ajax(array_replace($request, ['flex_title_rows' => json_encode([['items', str_repeat('f', 32)]])]) + $post)['error'], 'title previews reject unknown row identities');
	$same($type->admin(), $type->admin(), 'request-local admin service is reused across callers');
	$same([false], $previewStates, 'initial render runs outside title preview mode');
	$previewStates = [];
	$draftBefore = $type->admin()->draft($draft);
	$result = $ajax($request + $post);
	$same(false, $result['error'], 'authenticated title preview accepts unsaved form values: ' . ($result['message'] ?? ''));
	$same([true], $previewStates, 'row-titles AJAX runs with previewingRowTitles active across shared admin service');
	$same(false, $type->admin()->previewingRowTitles(), 'previewingRowTitles resets after row-titles AJAX');
	$same(['@details' => 'Pending root / Pending detail / Stored child', '@asset' => 'Pending image description'], $result['titles'][$hooked->address()]['custom'], 'preview uses detached root, object and unloaded descendant values');
	$same([
		['children' => [['text' => '#'], ['text' => '3', 'index' => true]], 'classes' => ['flex-muted']],
		['text' => ' Pending override', 'classes' => ['custom', 'flex-strong']],
	], $result['titles'][$override->address()]['override'], 'preview returns a safe override and keeps the computed index dynamic');
	$same(' Activate override', $result['titles'][$conditional->address()]['override'][1]['text'], 'a conditional override takes effect after editing');
	$same($before, $type->storage()->persisted($page, $titleField), 'title previews never persist page values');
	$same($draftBefore, $type->admin()->draft($draft), 'title previews do not change the editor draft');
	$delete = 'delete_' . FlexPath::inputPrefix($name, $hooked->address()) . 'image_' . $asset->hash;
	$removePreview = $ajax($request + $post + [$delete => '1']);
	$same('No image', $removePreview['titles'][$hooked->address()]['custom']['@asset'], 'preview-only removal is reflected in the hook');
	$assert(is_file($asset->filename()), 'preview-only removal keeps the physical original');
	$same($before, $type->storage()->persisted($page, $titleField), 'preview-only removal keeps the stored file');
	$addition = $ajax(['flex_action' => 'add', 'flex_set' => 'items', 'flex_type' => 'hooked', 'flex_order' => $form[FlexPath::controlName($name, 'items', 'order')], 'flex_complete' => $draft]);
	$same(false, $addition['error'], 'the normal editor action can add a row with custom title tokens');
	$post[FlexPath::controlName($name, 'items', 'order')] .= ',' . $addition['id'];
	$post[FlexPath::inputPrefix($name, 'items.' . $addition['id']) . 'heading'] = 'Unsaved new item';
	$result = $ajax(array_replace($request, ['flex_title_rows' => json_encode([['items', $addition['id']]])]) + $post);
	$same(false, $result['error'], 'title previews accept rows registered in the draft only: ' . ($result['message'] ?? ''));
	$same(['@details' => 'Pending root /  / ', '@asset' => 'No image'], $result['titles']['items.' . $addition['id']]['custom'], 'new rows receive their own values and live root context');
} finally {
	$wire->removeHook($valuesHook);
	$wire->removeHook($partsHook);
	$wire->input->post->removeAll()->setArray($savedPost);
	$_SERVER = $savedServer;
	if($draft !== '') $type->admin()->forget($draft);
}
