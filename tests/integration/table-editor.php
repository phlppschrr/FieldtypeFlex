<?php namespace ProcessWire;
if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$definition = ['grid' => ['type' => 'table', 'editableHeaders' => true, 'rowLabels' => true, 'minItems' => 1, 'maxItems' => 3, 'columns' => [
	'text' => ['type' => 'text', 'required' => true, 'minlength' => 3, 'description' => 'Cell description'],
	'enabled' => ['type' => 'checkbox'],
	'detail' => ['type' => 'text', 'showIf' => 'enabled=1'],
]]];
$definition['sections'] = ['type' => 'rows', 'types' => ['section' => ['children' => [
	'fixed' => ['type' => 'table', 'columns' => ['text' => ['type' => 'text']]],
	'editable' => ['type' => 'table', 'editableHeaders' => true, 'columns' => ['text' => ['type' => 'text']]],
]]]];
$f = $createField('flex_table_editor', $definition); $name = $f->name;
$value = $type->getBlankValue($page, $f);
$a = $value->grid->rows->addRow('row', ['text' => 'First', 'enabled' => 1, 'detail' => 'Keep', 'row_label' => 'One']);
$b = $value->grid->rows->addRow('row', ['text' => 'Second']);
$page->set($name, $value); $page->save($name);
$baseline = $wire->pages->getFresh($id)->getUnformatted($name)->rawArray();
$makeEditor = static function() use ($wire, $id, $f, $name): InputfieldFlex {
	$owner = $wire->pages->getFresh($id); $owner->of(false);
	$input = $f->getInputfield($owner); $input->value = $owner->getUnformatted($name); return $input;
};
$postFrom = static function(string $html): array {
	$dom = new \DOMDocument(); $old = libxml_use_internal_errors(true); $dom->loadHTML('<html><body>' . $html . '</body></html>'); libxml_clear_errors(); libxml_use_internal_errors($old);
	$post = [];
	foreach((new \DOMXPath($dom))->query('//input[@name]') as $input) {
		if(in_array($input->getAttribute('type'), ['checkbox', 'radio']) && !$input->hasAttribute('checked')) continue;
		$post[$input->getAttribute('name')] = $input->getAttribute('value');
	}
	return $post;
};
$editor = $makeEditor(); $html = $editor->render(); $post = $postFrom($html);
$ap = FlexPath::inputPrefix($name, $a->address()); $bp = FlexPath::inputPrefix($name, $b->address());
$hp = FlexPath::inputPrefix($name, 'grid.headers');
$post[$hp . 'text'] = ''; $post[$hp . 'enabled'] = '0';
$post[$ap . 'text'] = 'Unsaved first'; $post[$bp . 'text'] = 'Unsaved second';
$post[FlexPath::controlName($name, 'grid.rows', 'order')] = $b->rowId() . ',' . $a->rowId();
$post[FlexPath::controlName($name, $b->address(), 'hidden')] = '1';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'table native form processing validates headers and row cells together');
$same('', $editor->value->grid->headers->text, 'native header processing preserves empty text');
$same('0', $editor->value->grid->headers->enabled, 'native header processing preserves zero text');
$same('Unsaved second', $editor->value->grid->rows->first()->text, 'submitted table row order and edits are applied');
$assert($editor->value->grid->rows->first()->isHidden(), 'native table processing retains row visibility');
$same($baseline, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'form processing does not write before owner save');

// A failed cell retains native errors, other inputs and recoverable deletion marks.
$invalid = $makeEditor(); $badPost = $postFrom($invalid->render());
$badPost[$ap . 'text'] = ''; $badPost[$bp . 'text'] = 'Other pending edit';
$badPost[$hp . 'text'] = 'Pending header';
$invalid->processInput($wire->wire(new WireInputData($badPost)));
$assert(count($invalid->getErrors()) > 0, 'table cell uses native required validation');
$recovery = $invalid->render();
$assert(str_contains($recovery, 'flex-table-grid') && str_contains($recovery, 'InputfieldError'), 'table renders native cell errors after failed validation');
$assert(str_contains($recovery, 'Other pending edit') && str_contains($recovery, 'Pending header'), 'failed validation retains other cells and header edits');
$invalid->getErrors(true); $invalid->inputs()->getErrors(true);
$removed = $makeEditor(); $removePost = $postFrom($removed->render());
$removePost[$ap . 'text'] = ''; $removePost[$bp . 'text'] = 'Remaining row';
$removePost[FlexPath::controlName($name, $a->address(), 'remove')] = '1';
$removed->processInput($wire->wire(new WireInputData($removePost)));
$same([], $removed->getErrors(), 'removed table row does not block required cell validation');
$same([$b->rowId()], array_keys($removed->value->grid->rows->getArray()), 'processed table omits only the pending removal');
$assert(str_contains($removed->render(), 'flex-row-remove-pending') && str_contains($removed->render(), 'Undo removal'), 'pending table removal remains recoverable in the rendered form');
$removePost[FlexPath::controlName($name, $a->address(), 'remove')] = '0'; $removePost[$ap . 'text'] = 'Undo restored';
$undo = $makeEditor(); $undo->processInput($wire->wire(new WireInputData($removePost)));
$same([], $undo->getErrors(), 'table undo restores normal native validation');
$same(2, count($undo->value->grid->rows), 'table undo retains both row identities');

// Existing authenticated add/duplicate endpoint also returns table rows.
$ajaxEditor = $makeEditor(); $ajaxPost = $postFrom($ajaxEditor->render()); $draft = $ajaxPost[$name . '__0draft'];
$savedPost = $wire->input->post->getArray(); $savedServer = $_SERVER;
$ajax = static function(array $data, bool $csrf = true) use ($wire, $type, $page, $f, $draft): array {
	$_SERVER['HTTP_X_FLEX_DRAFT'] = $draft; $_SERVER['HTTP_X_FIELDNAME'] = $f->name;
	$orders = $type->admin()->draft($draft)['orders'];
	$orders[$data['flex_set']] = $data['flex_order'] === '' ? [] : explode(',', $data['flex_order']);
	$data['flex_orders'] = json_encode($orders);
	if($csrf) $data[$wire->session->CSRF->getTokenName()] = $wire->session->CSRF->getTokenValue();
	$wire->input->post->removeAll()->setArray($data); ob_start();
	try { $type->admin()->ajax(new HookEvent(['arguments' => [$page]])); return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR); }
	finally { ob_end_clean(); }
};
try {
	$request = ['flex_action' => 'duplicate', 'flex_set' => 'grid.rows', 'flex_source' => $a->rowId(), 'flex_order' => $a->rowId() . ',' . $b->rowId(), 'flex_complete' => $draft];
	$ajaxPost[$ap . 'text'] = 'Duplicated pending edit'; $ajaxPost[$ap . 'detail'] = 'Pending conditional';
	$same(true, $ajax($request + $ajaxPost, false)['error'], 'table AJAX retains CSRF protection');
	$result = $ajax($request + $ajaxPost);
	$same(false, $result['error'], 'table duplication uses the existing authenticated endpoint');
	$assert(str_starts_with($result['html'], '<tr ') && str_contains($result['html'], 'Duplicated pending edit'), 'AJAX table duplication renders current unsaved native values');
	$assert(str_contains($result['html'], 'Pending conditional'), 'AJAX duplication applies native row dependencies');
	$copy = $type->admin()->row($draft, 'grid.rows', $result['id'], $page->getUnformatted($name));
	$same('Duplicated pending edit', $copy->text, 'duplicate draft contains the processed unsaved cell value');
	$add = $ajax(['flex_action' => 'add', 'flex_set' => 'grid.rows', 'flex_type' => 'row', 'flex_order' => '', 'flex_complete' => $draft]);
	$same(false, $add['error'], 'adding a table row reuses the normal add action');
	$assert(str_starts_with($add['html'], '<tr '), 'AJAX add returns semantic table-row markup');
	$limit = $ajax(array_replace($request, ['flex_order' => $a->rowId() . ',' . $b->rowId() . ',' . $result['id']]) + $ajaxPost);
	$same(true, $limit['error'], 'table AJAX enforces the existing maximum count');
	$outer = $ajax(['flex_action' => 'add', 'flex_set' => 'sections', 'flex_type' => 'section', 'flex_order' => '', 'flex_complete' => $draft]);
	$same(false, $outer['error'], 'AJAX adds an outer draft Row containing tables' . ($outer['message'] ?? ''));
	$same(2, substr_count($outer['html'], 'class="flex-table-grid"'), 'new draft parent renders both fixed and editable table headers');
	$set = 'sections.' . $outer['id'] . '.fixed.rows';
	$nested = $ajax(['flex_action' => 'add', 'flex_set' => $set, 'flex_type' => 'row', 'flex_order' => '', 'flex_complete' => $draft]);
	$same(false, $nested['error'], 'AJAX adds a table row beneath a new unsaved parent');
	$nestedPost = [
		FlexPath::controlName($name, $set, 'present') => '1',
		FlexPath::controlName($name, $set, 'order') => $nested['id'],
		FlexPath::inputPrefix($name, $set . '.' . $nested['id']) . 'text' => 'Nested unsaved cell',
	];
	$outerCopy = $ajax(['flex_action' => 'duplicate', 'flex_set' => 'sections', 'flex_source' => $outer['id'], 'flex_order' => $outer['id'], 'flex_complete' => $draft] + $nestedPost);
	$same(false, $outerCopy['error'], 'AJAX duplicates an unsaved outer Row containing tables');
	$assert(str_contains($outerCopy['html'], 'Nested unsaved cell'), 'duplicating an outer Row preserves its unsaved nested table values');
} finally {
	$wire->input->post->removeAll()->setArray($savedPost); $_SERVER = $savedServer;
}

$page->set($name, $editor->value); $page->save($name);
$saved = $wire->pages->getFresh($id)->getUnformatted($name)->grid;
$same('Unsaved second', $saved->rows->first()->text, 'processed table persists through the normal owner save');
$same('', $saved->headers->text, 'processed empty header survives independent readback');

echo "\n$passed assertions passed on ProcessWire {$wire->config->version}, PHP " . PHP_VERSION . ".\n";
