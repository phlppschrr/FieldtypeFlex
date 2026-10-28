<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// A ready Repeater item may acquire its concrete type after Flex was loaded.
$contextField = $createField('flex_context', []);
$definitionPath = $trackFile($wire->config->paths->templates . 'flex-fields/editor-context.php');
file_put_contents($definitionPath, <<<'DEF'
<?php namespace ProcessWire;
return [
	'layout' => ['type' => 'radios', 'options' => ['cards' => 'Cards'], 'collapsed' => $page->repeater_matrix_type === 1 ? Inputfield::collapsedNo : Inputfield::collapsedHidden],
	'asset' => ['type' => 'file', 'extensions' => 'txt'],
	'blocks' => ['type' => 'rows', 'types' => ['text' => ['children' => ['heading' => ['type' => 'text']]]]],
];
DEF
);
$contextField->flexDefinition = '';
$contextField->flexDefinitionFile = 'flex-fields/editor-context.php';
$wire->fields->save($contextField);
$page->setQuietly('repeater_matrix_type', 2);
$value = $type->sanitizeValue($page, $contextField, ['layout' => 'cards', 'unknown' => ['keep' => true]]);
$value->blocks->addRow('text', ['heading' => 'Unsaved']);
$value->expectedRevision(str_repeat('a', 64));
$assetPath = $trackFile(dirname(__DIR__, 2) . '/.dev/editor-context.txt');
file_put_contents($assetPath, 'Context media');
$value->asset->add($assetPath);
$raw = $value->rawArray();
$input = $contextField->getInputfield($page);
$input->value = $value;
$page->setQuietly('repeater_matrix_type', 1);
$inputs = $input->inputs();
$same(Inputfield::collapsedNo, $inputs->getChildByName($input->name . '__layout')->collapsed, 'editor reevaluates a value loaded before the owner type was assigned');
$same($raw, $input->value->rawArray(), 'editor rebind preserves unsaved rows, identity and unknown properties');
$same(str_repeat('a', 64), $input->value->expectedRevision(), 'editor rebind preserves expected revision');
$same(Inputfield::collapsedHidden, $value->definitions()->property('layout')['input']->collapsed, 'editor rebind leaves the original value definitions intact');
// Native renderReady is the boundary for rebuilding an already prepared editor.
$page->setQuietly('repeater_matrix_type', 2);
$input->renderReady();
$same(Inputfield::collapsedHidden, $input->inputs()->getChildByName($input->name . '__layout')->collapsed, 'renderReady rebuilds cached inputs in the current owner context');
$same($raw, $input->value->rawArray(), 'repeated editor construction does not alter canonical data');

$asset = $input->value->asset->first();
$same('Context media', file_get_contents($asset->filename()), 'editor rebind retains unsaved owned media bytes');
// Obtain the authenticated draft and stage a metadata change as native media editing does.
$html = $input->render();
preg_match('/name="' . preg_quote($input->name, '/') . '__0draft" value="([a-f0-9]{64})"/', $html, $match);
$draftToken = $match[1];
$staged = clone $input->value->asset;
$staged->first()->description = 'Draft description';
$type->admin()->stageMedia($draftToken, 'asset', $staged);
$page->setQuietly('repeater_matrix_type', 1);
$input->renderReady();
$same('Draft description', $input->inputs()->getChildByName($input->name . '__asset')->value->first()->description, 'context refresh reapplies staged media to the fresh editor tree');
$same($raw, $input->value->rawArray(), 'draft overlay remains detached from canonical media metadata');

$page->setQuietly('repeater_matrix_type', 2);
$input->renderReady();
$page->setQuietly('repeater_matrix_type', 1);
$post = [
	$input->name . '__0draft' => $draftToken,
	$input->name . '__0complete' => $draftToken,
	$input->name . '__layout' => '',
];
$input->processInput($wire->wire(new WireInputData($post)));
$same([], $input->getErrors(), 'processing accepts the authenticated draft after a context change');
$same('', $input->value->layout, 'processing rebuilds a cached hidden input after its context becomes visible');
$same($raw['unknown'], $input->value->rawArray()['unknown'], 'processing retains unknown raw data');
$same($raw['blocks'], $input->value->rawArray()['blocks'], 'processing retains unsent row identities and values');
$same($type->admin()->draft($draftToken)['hash'], $input->value->expectedRevision(), 'processed value uses the authenticated draft revision');
$type->admin()->forget($draftToken);
