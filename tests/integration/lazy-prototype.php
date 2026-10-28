<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}
require dirname(__DIR__) . '/prototypes/PartialFlexSubmission.php';

$children = [
	'heading' => ['type' => 'text', 'required' => true, 'minlength' => 3],
	'number' => ['type' => 'integer'],
	'group' => ['type' => 'fieldset', 'children' => ['flat' => ['type' => 'text']]],
	'enabled' => ['type' => 'checkbox'],
	'choice' => ['type' => 'select', 'options' => ['a' => 'Alpha', 'b' => 'Beta']],
	'tags' => ['type' => 'checkboxes', 'options' => ['x' => 'X', 'y' => 'Y']],
	'conditional' => ['type' => 'text', 'showIf' => 'enabled=1'],
	'needed' => ['type' => 'text', 'required' => true, 'requiredIf' => 'enabled=1'],
	'external' => ['type' => 'text', 'required' => true, 'requiredIf' => 'switch=1'],
	'patterned' => ['type' => 'text', 'pattern' => '[A-Z]+'],
];
$schema = [
	'switch' => ['type' => 'checkbox'],
	'blocks' => ['type' => 'rows', 'types' => ['item' => ['children' => $children + [
		'children' => ['type' => 'rows', 'types' => ['item' => ['children' => $children]]],
	]]]],
];
$probeField = $createField('flex_lazy_probe', $schema);
// A dependency outside a row uses its explicit outer scoped input name.
$probeField->flexDefinition = str_replace('"switch=1"', json_encode($probeField->name . '__switch=1'), $probeField->flexDefinition);
$wire->fields->save($probeField);
$base = $type->getBlankValue($page, $probeField);
$a = $base->blocks->addRow('item', ['heading' => 'First', 'number' => 0, 'flat' => 'Flat baseline', 'enabled' => 1, 'needed' => 'Present', 'conditional' => 'Keep', 'choice' => 'b', 'tags' => ['x', 'y']]);
$b = $base->blocks->addRow('item', ['heading' => 'Second', 'enabled' => 0, 'needed' => '', 'conditional' => 'Hidden']);
$c = $a->children->addRow('item', ['heading' => 'Nested', 'enabled' => 0]);
$raw = $base->rawArray();
$raw['unknown'] = ['retain' => ['zero' => 0]];
$raw['blocks']['items'][0]['values']['unknown'] = ['retain' => true];
$raw['blocks']['items'][0]['values']['children']['items'][0]['values']['legacy'] = 'Keep child';
$base = new FlexValue($page, $probeField, $type->getDefinitions($probeField, $page), $raw);
$page->set($probeField->name, $base);
$page->save($probeField->name);
$base = $wire->pages->getFresh($page->id)->getUnformatted($probeField->name);
$original = $base->rawArray();
$draft = $type->admin()->open($base, $probeField->name);
$rootGrant = bin2hex(random_bytes(16));
$grants = ['' => $rootGrant];
$prefix = FlexPath::inputPrefix($probeField->name);
$rowPrefix = FlexPath::inputPrefix($probeField->name, $a->address());
$post = [
	$probeField->name . '__0draft' => $draft,
	$probeField->name . '__0complete' => $draft,
	FlexPath::controlName($probeField->name, '', 'body') => $rootGrant,
	FlexPath::controlName($probeField->name, '', 'complete') => $rootGrant,
	FlexPath::controlName($probeField->name, 'blocks', 'present') => '1',
	FlexPath::controlName($probeField->name, 'blocks', 'order') => $b->rowId() . ',' . $a->rowId(),
];
$run = static fn(array $data, ?FlexValue $value = null, ?array $issued = null): array => PartialFlexSubmission::process($value ?: $base, $draft, $issued ?? $grants, $data);
try {
	$result = $run($post);
	$same([], $result['errors'], 'unloaded populated native required inputs validate against server baseline');
	$expected = $original;
	$expected['blocks']['items'] = array_reverse($expected['blocks']['items']);
	$same($expected, $result['value']->rawArray(), 'reordering unloaded rows retains all leaves, unknown data and nested identities');
	$same(0, $result['value']->blocks->getById($a->rowId())->number, 'unloaded numeric zero retains its canonical type');
	$same($original, $base->rawArray(), 'prototype leaves its baseline untouched');
	$same($type->admin()->draft($draft)['hash'], $result['value']->expectedRevision(), 'prototype retains the authenticated storage revision');
	$externalPost = $post + [$prefix . 'switch' => '1'];
	$assert(count($run($externalPost)['errors']) > 0, 'changed root dependency activates requiredIf inside unloaded rows');

	$invalidRaw = $original;
	$invalidRaw['blocks']['items'][0]['values']['heading'] = '';
	$invalid = new FlexValue($page, $probeField, $base->definitions(), $invalidRaw);
	$assert(count($run($post, $invalid)['errors']) > 0, 'unloaded empty required value blocks save');
	$invalidRaw['blocks']['items'][0]['values']['heading'] = 'No';
	$invalid = new FlexValue($page, $probeField, $base->definitions(), $invalidRaw);
	$same('No', $invalid->blocks->first()->heading, 'minlength probe starts with the intended invalid baseline');
	$same(3, (int) $invalid->blocks->first()->definitions()->property('heading')['input']->attr('minlength'), 'minlength probe retains native validation settings');
	$assert(count($run($post, $invalid)['errors']) > 0, 'unloaded baseline still runs native minlength validation');
	$invalidRaw['blocks']['items'][0]['values']['heading'] = str_repeat('x', 2050);
	$invalid = new FlexValue($page, $probeField, $base->definitions(), $invalidRaw);
	$assert(count($run($post, $invalid)['errors']) > 0, 'unloaded maxlength validation uses raw baseline before Inputfield value truncation');
	$invalidRaw['blocks']['items'][0]['values']['heading'] = 'Valid';
	$invalidRaw['blocks']['items'][0]['values']['patterned'] = 'lowercase';
	$invalid = new FlexValue($page, $probeField, $base->definitions(), $invalidRaw);
	$assert(count($run($post, $invalid)['errors']) > 0, 'unloaded baseline still runs native pattern validation');
	$invalidRaw = $original;
	$invalidRaw['blocks']['items'][0]['values']['needed'] = '';
	$invalid = new FlexValue($page, $probeField, $base->definitions(), $invalidRaw);
	$assert(count($run($post, $invalid)['errors']) > 0, 'unloaded sibling dependency activates native requiredIf');

	$loadedGrant = bin2hex(random_bytes(16));
	$loadedGrants = $grants + [$a->address() => $loadedGrant];
	$loadedPost = $post + [
		FlexPath::controlName($probeField->name, $a->address(), 'body') => $loadedGrant,
		FlexPath::controlName($probeField->name, $a->address(), 'complete') => $loadedGrant,
		FlexPath::controlName($probeField->name, $a->address() . '.children', 'present') => '1',
		FlexPath::controlName($probeField->name, $a->address() . '.children', 'order') => $c->rowId(),
		$rowPrefix . 'heading' => 'Edited parent',
		$rowPrefix . 'flat' => 'Edited flat leaf',
		$rowPrefix . 'conditional' => 'Ignore hidden tampering',
	];
	$result = $run($loadedPost, null, $loadedGrants);
	$same([], $result['errors'], 'mixed loaded parent and unloaded child validate in one native form');
	$edited = $result['value']->blocks->getById($a->rowId());
	$same('Edited parent', $edited->heading, 'loaded scalar edit is applied');
	$same('Edited flat leaf', $edited->flat, 'ordinary fieldset leaves stay in their row scope');
	$same(0, $edited->enabled, 'missing checkbox in a loaded body clears it');
	$same([], $edited->tags, 'missing multiselect in a loaded body clears it');
	$same('Keep', $edited->conditional, 'native showIf ignores hidden posted edits');
	$same($original['blocks']['items'][0]['values']['children'], $edited->rawArray()['children'], 'unloaded child remains canonical below edited parent');
	$same(['retain' => true], $edited->rawArray()['unknown'], 'unknown values survive a loaded parent edit');
	$same($original['blocks']['items'][1]['values'], $result['value']->blocks->getById($b->rowId())->rawArray(), 'untouched sibling retains unchecked checkbox and empty conditional values');
	$childGrant = bin2hex(random_bytes(16));
	$childPrefix = FlexPath::inputPrefix($probeField->name, $c->address());
	$childPost = $loadedPost + [
		FlexPath::controlName($probeField->name, $c->address(), 'body') => $childGrant,
		FlexPath::controlName($probeField->name, $c->address(), 'complete') => $childGrant,
		$childPrefix . 'heading' => 'Edited child',
	];
	$childResult = $run($childPost, null, $loadedGrants + [$c->address() => $childGrant]);
	$same([], $childResult['errors'], 'loaded descendants share the native validation context');
	$childValue = $childResult['value']->blocks->getById($a->rowId())->children->first();
	$same('Edited child', $childValue->heading, 'nested loaded edit is assembled before its parent');
	$same('Keep child', $childValue->rawArray()['legacy'], 'unknown nested values survive a loaded child edit');
	$page->set($probeField->name, $result['value']);
	$page->save($probeField->name);
	$saved = $wire->pages->getFresh($page->id)->getUnformatted($probeField->name);
	$same($result['value']->rawArray(), $saved->rawArray(), 'prototype result survives actual native field save and fresh readback');
	$projection = $wire->pages->findIDs('id=' . $page->id . ', ' . $probeField->name . '.blocks.item.heading=Edited parent, include=all');
	$same([(int) $page->id], array_map('intval', $projection), 'prototype save updates row selector projection');
	// The old draft is now stale after the real save.
	$throws(fn() => $run($post), 'concurrent canonical changes reject stale partial submissions');
	$type->admin()->forget($draft);
	$draft = $type->admin()->open($base, $probeField->name);
	// Arrow closures capture by value; use a new closure for the new draft.
	$run = static fn(array $data, ?FlexValue $value = null, ?array $issued = null): array => PartialFlexSubmission::process($value ?: $base, $draft, $issued ?? $grants, $data);
	$post[$probeField->name . '__0draft'] = $post[$probeField->name . '__0complete'] = $draft;

	$bad = $post;
	unset($bad[FlexPath::controlName($probeField->name, '', 'complete')]);
	$throws(fn() => $run($bad), 'truncated body is rejected before applying input');
	$bad = $post + [$rowPrefix . 'heading' => 'Unissued edit'];
	$throws(fn() => $run($bad), 'values posted for an unloaded body are rejected');
	$bad = $post;
	$bad[FlexPath::controlName($probeField->name, 'blocks', 'order')] = $a->rowId() . ',' . $a->rowId();
	$throws(fn() => $run($bad), 'duplicate row order is rejected');
	$bad[FlexPath::controlName($probeField->name, 'blocks', 'order')] = str_repeat('f', 32);
	$throws(fn() => $run($bad), 'unregistered row IDs are rejected');
	$throws(fn() => $run($post + [$rowPrefix . 'type' => 'other']), 'client cannot change an existing row type through extra transport keys');
	$foreign = new FlexValue($second, $probeField, $base->definitions(), $original);
	$throws(fn() => $run($post, $foreign), 'draft cannot be replayed against another Page');
	$state = $type->admin()->draft($draft);
	$expired = $state;
	$expired['expires'] = time() - 1;
	$type->admin()->put($draft, $expired);
	$throws(fn() => $run($post), 'expired partial editor draft is rejected');
	$type->admin()->put($draft, $state);
	$throws(fn() => $run($post, null, $grants + [$c->address() => bin2hex(random_bytes(16))]), 'loaded child below unloaded parent is rejected');
	$bad = $post;
	$bad[FlexPath::controlName($probeField->name, 'blocks', 'order')] = $b->rowId();
	$same(1, count($run($bad)['value']->blocks), 'explicit order removal can delete an unloaded subtree');
	$unsupported = clone $probeField;
	$unsupported->flexDefinition = json_encode(['blocks' => ['type' => 'rows', 'types' => ['item' => ['children' => ['heading' => ['type' => 'datetime']]]]]]);
	$unsupportedValue = new FlexValue($page, $probeField, $type->getDefinitions($unsupported, $page), ['blocks' => ['_flex' => FlexStorage::ROWS_FORMAT, 'items' => [['id' => $b->rowId(), 'type' => 'item', 'values' => ['heading' => '2026-10-21']]]]]);
	$unsupportedPost = $post;
	$unsupportedPost[FlexPath::controlName($probeField->name, 'blocks', 'order')] = $b->rowId();
	$throws(fn() => $run($unsupportedPost, $unsupportedValue), 'unsupported date transport fails closed instead of guessing baseline POST format', WireException::class, 'requires eager input');
	foreach([
		['type' => 'text', 'useLanguages' => true],
		['type' => 'image', 'maxFiles' => 1],
		['type' => 'TinyMCE'],
	] as $leaf) {
		$unsupported->flexDefinition = json_encode(['blocks' => ['type' => 'rows', 'types' => ['item' => ['children' => ['special' => $leaf]]]]]);
		$unsupportedValue = new FlexValue($page, $probeField, $type->getDefinitions($unsupported, $page), ['blocks' => ['_flex' => FlexStorage::ROWS_FORMAT, 'items' => [['id' => $b->rowId(), 'type' => 'item', 'values' => []]]]]);
		$throws(fn() => $run($unsupportedPost, $unsupportedValue), 'specialized unloaded transport fails closed: ' . $leaf['type'], WireException::class, 'requires eager input');
	}
} finally {
	$type->admin()->forget($draft);
}
