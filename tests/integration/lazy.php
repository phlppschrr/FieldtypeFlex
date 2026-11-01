<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$leaf = [
	'heading' => ['type' => 'text', 'required' => true, 'minlength' => 3],
	'enabled' => ['type' => 'checkbox'],
	'conditional' => ['type' => 'text', 'showIf' => 'enabled=1'],
	'needed' => ['type' => 'text', 'required' => true, 'requiredIf' => 'root.enabled=1'],
	'number' => ['type' => 'integer'],
	'tags' => ['type' => 'checkboxes', 'options' => ['a' => 'Alpha', 'b' => 'Beta']],
];
$collection = ['type' => 'rows', 'rowLazyLoad' => true, 'types' => ['item' => ['rowTitle' => '{heading}', 'children' => $leaf]]];
$schema = [
	'enabled' => ['type' => 'checkbox'],
	'items' => $collection,
	'sections' => ['type' => 'rows', 'rowLazyLoad' => true, 'types' => ['section' => ['children' => ['heading' => ['type' => 'text'], 'children' => $collection]]]],
];
$lazyField = $createField('flex_lazy', $schema);
$value = $type->getBlankValue($page, $lazyField);
$a = $value->items->addRow('item', ['heading' => 'First', 'enabled' => 1, 'conditional' => 'Retained', 'number' => 0, 'tags' => ['a', 'b']]);
$b = $value->items->addRow('item', ['heading' => 'Second']);
$section = $value->sections->addRow('section', ['heading' => 'Parent']);
$child = $section->children->addRow('item', ['heading' => 'Child']);
$raw = $value->rawArray();
$raw['unknown'] = ['keep' => true];
$raw['items']['items'][0]['values']['legacy'] = 'Retain unknown';
$page->set($lazyField->name, $raw);
$page->save($lazyField->name);
$value = $wire->pages->getFresh($page->id)->getUnformatted($lazyField->name);
$baseline = $value->rawArray();
$storedBaseline = json_encode($type->storage()->document($page, $lazyField), JSON_THROW_ON_ERROR);
$editor = $lazyField->getInputfield($page);
$editor->value = $value;
$html = $editor->render();
$hidden = static function(string $html): array {
	$dom = new \DOMDocument();
	$old = libxml_use_internal_errors(true);
	$dom->loadHTML('<html><body>' . $html . '</body></html>');
	libxml_clear_errors();
	libxml_use_internal_errors($old);
	$data = [];
	foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
	return $data;
};
$post = $hidden($html);
$draft = $post[$editor->name . '__0draft'];
$name = $editor->name;
$aPrefix = FlexPath::inputPrefix($name, $a->address());
$post[FlexPath::inputPrefix($name, $section->address()) . 'heading'] = 'Parent';
$assert(!str_contains($html, 'name="' . $aPrefix . 'heading"'), 'closed scalar row renders a header without native inputs');
$same(3, substr_count($html, 'data-flex-loaded="0"'), 'root and nested scalar row bodies defer independently');
$assert(str_contains($html, 'name="' . FlexPath::inputPrefix($name, $section->address()) . 'heading"'), 'row containing a child collection renders directly');
$assert(str_contains($html, 'flex_eager=1'), 'lazy placeholders offer an ordinary no-JavaScript full-render URL');
$rowInputs = new \ReflectionProperty(InputfieldFlexRows::class, 'rowInputs');
$rowInputs->setAccessible(true);
$itemsInput = $editor->inputs()->getChildByName($name . '__items');
$same([], $rowInputs->getValue($itemsInput), 'initial lazy render creates no concrete scalar row input trees');
$tokens = [$draft];
$savedPost = $wire->input->post->getArray();
$savedHeaders = $_SERVER;
$ajax = static function(array $data, ?Page $owner = null, bool $csrf = true) use ($wire, $type, $page, $lazyField, $draft): array {
	$_SERVER['HTTP_X_FLEX_DRAFT'] = $draft;
	$_SERVER['HTTP_X_FIELDNAME'] = $lazyField->name;
	if($csrf) $data[$wire->session->CSRF->getTokenName()] = $wire->session->CSRF->getTokenValue();
	$wire->input->post->removeAll()->setArray($data);
	$event = new HookEvent(['arguments' => [$owner ?: $page]]);
	ob_start();
	try {
		$type->admin()->ajax($event);
		return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
	} finally {
		ob_end_clean();
	}
};
$renderRequest = ['flex_action' => 'row-render', 'flex_set' => 'items', 'flex_source' => $a->rowId(), 'flex_complete' => $draft];
try {
	$prepared = FlexLazy::prepare($value, $draft, $wire->wire(new WireInputData($post)));
	$same(0, $prepared->{$aPrefix . 'number'}, 'validation-only POST retains canonical numeric zero');
	$editor->processInput($wire->wire(new WireInputData($post)));
	$same([], $editor->getErrors(), 'unchanged partly loaded form passes native validation');
	$same($baseline, $editor->value->rawArray(), 'unloaded root and nested values plus unknown keys remain unchanged');

	// The existing native dependency bridge must see changed ancestor sources.
	$required = $lazyField->getInputfield($page);
	$required->value = $value;
	$requiredPost = $post + [$name . '__enabled' => '1'];
	$required->processInput($wire->wire(new WireInputData($requiredPost)));
	$assert(count($required->getErrors()) > 0, 'requiredIf validates unloaded rows against changed root input');
	$recovery = $required->render();
	$assert(str_contains($recovery, 'name="' . $aPrefix . 'needed"'), 'native error recovery renders the affected deferred body');
	$required->inputs()->getErrors(true);
	$required->getErrors(true);
	// Recovery issued grants. Reuse its transport, retaining submitted checkbox.
	$recoveryPost = $hidden($recovery);
	$assert(!FlexLazy::unloaded($value, $draft, $a->address()), 'error recovery upgrades a deferred body to a protected issued body');
	$throws(fn() => FlexLazy::prepare($value, $draft, $wire->wire(new WireInputData($post))), 'previous incomplete body transport cannot bypass recovery grants');

	$same(true, $ajax($renderRequest, null, false)['error'], 'lazy endpoint rejects missing CSRF');
	$same(true, $ajax($renderRequest, $second)['error'], 'lazy endpoint rejects a different owner Page');
	$same(true, $ajax(array_replace($renderRequest, ['flex_source' => str_repeat('f', 32)]))['error'], 'lazy endpoint rejects unknown row IDs');
	$loaded = $ajax($renderRequest);
	$same(false, $loaded['error'], 'authenticated row-render returns a native scalar body');
	$assert(str_contains($loaded['html'], 'name="' . $aPrefix . 'heading"'), 'row-render uses the stable original scoped input name');
	$same(false, $ajax($renderRequest)['error'], 'repeated row-render safely reissues the same protected body');
	$same($storedBaseline, json_encode($type->storage()->document($page, $lazyField), JSON_THROW_ON_ERROR), 'row-render never saves canonical data');
	// Fill the three issued scalar bodies after recovery; normal absent checkboxes clear.
	$editPost = $recoveryPost + $post;
	$editPost[$aPrefix . 'heading'] = 'Edited first';
	$editPost[$aPrefix . 'conditional'] = 'Hidden attack';
	$editPost[FlexPath::inputPrefix($name, $b->address()) . 'heading'] = 'Second';
	$editPost[FlexPath::inputPrefix($name, $child->address()) . 'heading'] = 'Child';
	$editPost[FlexPath::controlName($name, 'items', 'order')] = $b->rowId() . ',' . $a->rowId();
	$success = $lazyField->getInputfield($page);
	$success->value = $value;
	$success->processInput($wire->wire(new WireInputData($editPost)));
	$same([], $success->getErrors(), 'loaded edits process after lazy error recovery');
	$changed = $success->value->items->getById($a->rowId());
	$same('Edited first', $changed->heading, 'loaded scalar edit is applied');
	$same(0, $changed->enabled, 'unchecked checkbox in a loaded row clears normally');
	$same([], $changed->tags, 'unselected choices in a loaded row clear normally');
	$same('Retained', $changed->conditional, 'showIf preserves a hidden leaf in a loaded row');
	$same('Retain unknown', $changed->rawArray()['legacy'], 'loaded edits retain unknown stored row keys');
	$page->set($name, $success->value);
	$page->save($name);
	$saved = $wire->pages->getFresh($page->id)->getUnformatted($name);
	$sameJson(json_encode($success->value->rawArray()), json_encode($saved->rawArray()), 'partly loaded field saves and survives fresh readback');
	$same([(int) $page->id], array_map('intval', $wire->pages->findIDs('id=' . $page->id . ', ' . $name . '.items.item.heading=Edited first, include=all')), 'lazy edits update row selector projections');
	$same(true, $ajax($renderRequest)['error'], 'lazy endpoint rejects a stale canonical revision');

	// Source changes are checked independently from canonical storage revisions.
	$fresh = $lazyField->getInputfield($page);
	$fresh->value = $saved;
	$freshHtml = $fresh->render();
	$freshPost = $hidden($freshHtml);
	$freshToken = $freshPost[$name . '__0draft'];
	$tokens[] = $freshToken;
	$bad = $freshPost;
	$bad[$aPrefix . 'heading'] = 'Unissued attack';
	$throws(fn() => FlexLazy::prepare($saved, $freshToken, $wire->wire(new WireInputData($bad))), 'unsolicited values for a deferred row are rejected');
	$changedField = clone $lazyField;
	$changedSchema = $schema;
	$changedSchema['items']['types']['item']['children']['heading']['minlength'] = 12;
	$changedField->flexDefinition = json_encode($changedSchema);
	$changedValue = new FlexValue($page, $lazyField, $type->getDefinitions($changedField, $page), $saved->rawArray());
	$throws(fn() => FlexLazy::prepare($changedValue, $freshToken, $wire->wire(new WireInputData($freshPost))), 'changed validation definitions reject an older lazy draft');
	$bad = $freshPost;
	$bad[FlexPath::controlName($name, 'items', 'order')] = $b->rowId();
	$candidate = $lazyField->getInputfield($page);
	$candidate->value = $saved;
	$bad[FlexPath::inputPrefix($name, $section->address()) . 'heading'] = 'Parent';
	$candidate->processInput($wire->wire(new WireInputData($bad)));
	$same([], $candidate->getErrors(), 'removing an unopened row requires no body load');
	$same([$b->rowId()], array_keys($candidate->value->items->getArray()), 'explicit order removal removes the requested unopened row');
	foreach(['TinyMCE', 'datetime', 'page', 'image'] as $inputType) {
		$defs = new FlexFieldDefs($lazyField, $page, ['special' => ['type' => $inputType]]);
		$assert(!FlexLazy::supports($defs), 'specialized ' . $inputType . ' rows use direct rendering');
	}
	$defs = new FlexFieldDefs($lazyField, $page, ['text' => ['type' => 'text', 'useLanguages' => true]]);
	$assert(!FlexLazy::supports($defs), 'multilingual rows use direct rendering');
} finally {
	foreach($tokens as $token) $type->admin()->forget($token);
	$wire->input->post->removeAll()->setArray($savedPost);
	$_SERVER = $savedHeaders;
}
