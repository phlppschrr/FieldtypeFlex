<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$dependencySchema = [
	'enabled' => ['type' => 'checkbox'],
	'page_detail' => ['type' => 'text', 'showIf' => 'page.title=Show'],
	'forpage_detail' => ['type' => 'text', 'showIf' => 'forpage.title=Show'],
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => [
		'enabled' => ['type' => 'checkbox'],
		'parent_detail' => ['type' => 'text', 'showIf' => 'parent.enabled=1'],
		'root_detail' => ['type' => 'text', 'showIf' => 'root.enabled=1'],
		'page_detail' => ['type' => 'text', 'showIf' => 'page.title=Show'],
		'children' => ['type' => 'rows', 'types' => ['child' => ['children' => [
			'parent_detail' => ['type' => 'text', 'showIf' => 'parent.enabled=1'],
			'root_detail' => ['type' => 'text', 'showIf' => 'root.enabled=1'],
			'required_detail' => ['type' => 'text', 'required' => true, 'requiredIf' => 'parent.enabled=1'],
		]]]],
	]]]],
];
$dependencyField = $createField('flex_context_dependencies', $dependencySchema);
$dependencyValue = $type->getBlankValue($page, $dependencyField);
$dependencyRow = $dependencyValue->items->addRow('item', ['parent_detail' => 'Keep', 'root_detail' => 'Keep']);
$dependencyChild = $dependencyRow->children->addRow('child', ['parent_detail' => 'Keep', 'root_detail' => 'Keep']);
$page->{$dependencyField->name} = $dependencyValue;
$page->save($dependencyField->name);
$dependencyEditor = $dependencyField->getInputfield($page);
$dependencyEditor->value = $dependencyValue;
$dependencyHtml = $dependencyEditor->render();
$assert(str_contains($dependencyHtml, "data-show-if='title=Show'"), 'page and forpage dependencies use the native editor field name');
$assert(str_contains($dependencyHtml, "data-show-if='" . $dependencyField->name . "__enabled=1'"), 'root and first-level parent resolve to the Flex root');
$dependencyRowPrefix = FlexPath::inputPrefix($dependencyField->name, $dependencyRow->address());
$assert(str_contains($dependencyHtml, "data-show-if='" . $dependencyRowPrefix . "enabled=1'"), 'nested parent resolves the concrete parent row');

// Submit every transport control exactly as rendered, with changed ancestor gates.
$dependencyPost = [];
$dependencyDom = new \DOMDocument();
@$dependencyDom->loadHTML('<?xml encoding="UTF-8">' . $dependencyHtml);
foreach($dependencyDom->getElementsByTagName('input') as $control) {
	if($control->getAttribute('type') === 'hidden') $dependencyPost[$control->getAttribute('name')] = $control->getAttribute('value');
}
$dependencyPost['title'] = 'Show';
$dependencyPost[$dependencyField->name . '__enabled'] = '1';
$dependencyPost[$dependencyField->name . '__page_detail'] = 'Page visible';
$dependencyPost[$dependencyField->name . '__forpage_detail'] = 'Editor visible';
$dependencyPost[$dependencyRowPrefix . 'parent_detail'] = 'Parent visible';
$dependencyPost[$dependencyRowPrefix . 'root_detail'] = 'Root visible';
$dependencyChildPrefix = FlexPath::inputPrefix($dependencyField->name, $dependencyChild->address());
$dependencyPost[$dependencyChildPrefix . 'parent_detail'] = 'Hidden attack';
$dependencyPost[$dependencyChildPrefix . 'root_detail'] = 'Root descendant visible';
$dependencyPost[$dependencyChildPrefix . 'required_detail'] = '';
$dependencyEditor->processInput($wire->wire(new WireInputData($dependencyPost)));
$dependencyResult = $dependencyEditor->value;
$same('Page visible', $dependencyResult->page_detail, 'page condition uses the submitted native field value');
$same('Editor visible', $dependencyResult->forpage_detail, 'forpage condition uses the submitted native field value');
$same('Parent visible', $dependencyResult->items->first()->parent_detail, 'first-level parent uses the changed root checkbox');
$same('Root visible', $dependencyResult->items->first()->root_detail, 'root condition processes a row before its ancestor input has been applied');
$same('Keep', $dependencyResult->items->first()->children->first()->parent_detail, 'unchecked parent checkbox blocks submitted descendant edits');
$same('Root descendant visible', $dependencyResult->items->first()->children->first()->root_detail, 'root is independent of the immediate parent gate');
$same([], $dependencyEditor->getErrors(), 'requiredIf on an unchecked external checkbox does not require a value');

$dependencyProcess = static function(array $post) use ($dependencyField, $page, $dependencyValue, $wire): InputfieldFlex {
	$editor = $dependencyField->getInputfield($page);
	$editor->value = $dependencyValue;
	$editor->processInput($wire->wire(new WireInputData($post)));
	return $editor;
};
$dependencyOn = $dependencyPost;
$dependencyOn[$dependencyRowPrefix . 'enabled'] = '1';
$dependencyOn[$dependencyChildPrefix . 'parent_detail'] = 'Parent now enabled';
$dependencyOn[$dependencyChildPrefix . 'required_detail'] = 'Filled';
$dependencyOnEditor = $dependencyProcess($dependencyOn);
$same('Parent now enabled', $dependencyOnEditor->value->items->first()->children->first()->parent_detail, 'changed parent value enables its descendant during the same submit');
$same([], $dependencyOnEditor->getErrors(), 'requiredIf accepts a filled value with a true external condition');
$dependencyOn[$dependencyChildPrefix . 'required_detail'] = '';
$assert(count($dependencyProcess($dependencyOn)->getErrors()) > 0, 'requiredIf reports an empty value with a true external condition');
$dependencyOff = $dependencyPost;
unset($dependencyOff[$dependencyField->name . '__enabled']);
$dependencyOff['title'] = 'Hide';
$dependencyOffEditor = $dependencyProcess($dependencyOff);
$same('Keep', $dependencyOffEditor->value->items->first()->parent_detail, 'unchecked root blocks first-level parent dependencies');
$same('Keep', $dependencyOffEditor->value->items->first()->children->first()->root_detail, 'unchecked root blocks root dependencies at deeper levels');
$same('', $dependencyOffEditor->value->page_detail, 'page condition rejects edits when the submitted native value no longer matches');

// Standalone AJAX trees must reconstruct ancestors, including draft row identities.
$dependencyToken = $type->admin()->open($dependencyValue, $dependencyField->name);
$dependencyStandalone = $dependencyChild->definitions()->getInputfields($dependencyChild, $dependencyChildPrefix, $dependencyToken, $dependencyValue);
$dependencyStandaloneForm = FlexDependencies::processInput($dependencyStandalone, $page, $wire->wire(new WireInputData($dependencyPost)));
$same('Keep', $dependencyStandaloneForm->getChildByName($dependencyChildPrefix . 'parent_detail')->value, 'standalone AJAX tree resolves the immediate parent checkbox');
$same('Root descendant visible', $dependencyStandaloneForm->getChildByName($dependencyChildPrefix . 'root_detail')->value, 'standalone AJAX tree resolves the root checkbox');

// Native wrapper definitions and mixed selectors use exactly the same resolver.
$dependencyWrapper = $wire->wire(new InputfieldWrapper());
$dependencyNativeGate = $wire->modules->get('InputfieldCheckbox');
$dependencyNativeGate->name = 'enabled';
$dependencyWrapper->add($dependencyNativeGate);
$dependencyNativeDetail = $wire->modules->get('InputfieldText');
$dependencyNativeDetail->name = 'detail';
$dependencyNativeDetail->showIf = 'root.enabled|page.title=1, forpage.title!="alpha=1"';
$dependencyWrapper->add($dependencyNativeDetail);
$dependencyNativeDefs = new FlexFieldDefs($dependencyField, $page, $dependencyWrapper);
$dependencyNativeInputs = $dependencyNativeDefs->getInputfields(null, 'native__');
$same('native__enabled|title=1, title!="alpha=1"', (string) $dependencyNativeInputs->getChildByName('native__detail')->showIf, 'mixed OR fields, AND conditions and operator-containing values retain native selector semantics');

// A real Repeater item distinguishes its Page from the main editor Page.
$dependencyRepeater = $wire->wire(new RepeaterField());
$dependencyRepeater->name = 'flex_dependencies_repeater_' . bin2hex(random_bytes(3));
$dependencyRepeater->type = $repeaterType;
$dependencyRepeater->repeaterFields = [$wire->fields->get('title')->id, $dependencyField->id];
$dependencyRepeater->save();
$temporaryFields[] = $dependencyRepeater;
$dependencyRepeaterTemplate = $repeaterType->_getRepeaterTemplate($dependencyRepeater);
foreach([$wire->fields->get('title'), $dependencyField] as $dependencySubfield) $dependencyRepeaterTemplate->fieldgroup->add($dependencySubfield);
$dependencyRepeaterTemplate->fieldgroup->save();
$template->fieldgroup->add($dependencyRepeater);
$template->fieldgroup->save();
$page = $wire->pages->getFresh($id);
$page->of(false);
$dependencyItem = $page->get($dependencyRepeater->name)->getNewItem();
$dependencyItem->of(false);
$dependencyItem->title = 'Item';
$dependencyItem->{$dependencyField->name} = [];
$dependencyItem->save();
$page->save($dependencyRepeater->name);
$dependencyItemInput = $dependencyField->getInputfield($dependencyItem, '_repeater' . $dependencyItem->id);
$dependencyItemInput->value = $dependencyItem->getUnformatted($dependencyField->name);
$dependencyItemInputs = $dependencyItemInput->inputs();
$dependencyItemPrefix = $dependencyItemInput->name . '__';
$same('title_repeater' . $dependencyItem->id . '=Show', (string) $dependencyItemInputs->getChildByName($dependencyItemPrefix . 'page_detail')->showIf, 'page targets the native Repeater item input');
$same('title=Show', (string) $dependencyItemInputs->getChildByName($dependencyItemPrefix . 'forpage_detail')->showIf, 'forpage targets the main Page input outside the Repeater');
$dependencyItemPost = [
	'title' => 'Show',
	'title_repeater' . $dependencyItem->id => 'Hide',
	$dependencyItemPrefix . 'page_detail' => 'Hidden attack',
	$dependencyItemPrefix . 'forpage_detail' => 'Main page enabled',
];
$dependencyItemInput->processInput($wire->wire(new WireInputData($dependencyItemPost)));
$same('', $dependencyItemInput->value->page_detail, 'Repeater item page condition uses the item value');
$same('Main page enabled', $dependencyItemInput->value->forpage_detail, 'Repeater forpage condition uses the main editor value');
$same([], $dependencyItemInput->getErrors(), 'Repeater external dependencies process without errors');

// Ancestor conditions must preserve native OR/count/zero and chained showIf rules.
$dependencyEdgeField = $createField('flex_dependencies_edges', [
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => [
		'count_detail' => ['type' => 'text', 'showIf' => 'root.tags.count>1'],
		'zero_detail' => ['type' => 'text', 'showIf' => 'root.number=0'],
		'or_detail' => ['type' => 'text', 'showIf' => 'root.enabled|page.title=1'],
		'chained_detail' => ['type' => 'text', 'showIf' => 'root.gated=1'],
	]]]],
	'enabled' => ['type' => 'checkbox'],
	'gated' => ['type' => 'checkbox', 'showIf' => 'enabled=1, page.title=1'],
	'tags' => ['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']],
	'number' => ['type' => 'integer'],
]);
$dependencyEdgeValue = $type->getBlankValue($page, $dependencyEdgeField);
$dependencyEdgeRow = $dependencyEdgeValue->items->addRow('item', ['chained_detail' => 'Keep']);
$dependencyEdgePrefix = FlexPath::inputPrefix($dependencyEdgeField->name, $dependencyEdgeRow->address());
$dependencyEdgeEditor = $dependencyEdgeField->getInputfield($page);
$dependencyEdgeEditor->value = $dependencyEdgeValue;
$dependencyDom = new \DOMDocument();
@$dependencyDom->loadHTML('<?xml encoding="UTF-8">' . $dependencyEdgeEditor->render());
$dependencyEdgePost = [];
foreach($dependencyDom->getElementsByTagName('input') as $control) {
	if($control->getAttribute('type') === 'hidden') $dependencyEdgePost[$control->getAttribute('name')] = $control->getAttribute('value');
}
$dependencyEdgePost += [
	'title' => '1',
	$dependencyEdgeField->name . '__tags' => ['a', 'b'],
	$dependencyEdgeField->name . '__number' => '0',
	$dependencyEdgeField->name . '__gated' => '1',
	$dependencyEdgePrefix . 'count_detail' => 'Count matched',
	$dependencyEdgePrefix . 'zero_detail' => 'Zero matched',
	$dependencyEdgePrefix . 'or_detail' => 'OR matched',
	$dependencyEdgePrefix . 'chained_detail' => 'Hidden attack',
];
$dependencyEdgeEditor->processInput($wire->wire(new WireInputData($dependencyEdgePost)));
$dependencyEdgeResult = $dependencyEdgeEditor->value->items->first();
$same('Count matched', $dependencyEdgeResult->count_detail, 'external multi-value count is evaluated by the core');
$same('Zero matched', $dependencyEdgeResult->zero_detail, 'external numeric zero remains a value');
$same('OR matched', $dependencyEdgeResult->or_detail, 'OR combines an unchecked root checkbox and a native Page field');
$same('Keep', $dependencyEdgeResult->chained_detail, 'a hidden ancestor dependency cannot enable a descendant with a forged POST');
$same([], $dependencyEdgeEditor->getErrors(), 'external sources declared after Rows process without errors');

$dependencyInvalidDefs = new FlexFieldDefs($dependencyField, $page, ['detail' => ['type' => 'text', 'showIf' => 'parent.enabled=1']]);
$throws(static fn() => $dependencyInvalidDefs->getInputfields($dependencyValue, 'invalid__'), 'parent at the Flex root reports a configuration error');
$dependencyInvalidDefs = new FlexFieldDefs($dependencyField, $page, ['detail' => ['type' => 'text', 'showIf' => 'page.parent.template=1']]);
$throws(static fn() => $dependencyInvalidDefs->getInputfields($dependencyValue, 'invalid__'), 'arbitrary Page traversal is rejected');
$dependencyMissingDefs = new FlexFieldDefs($dependencyField, $page, ['detail' => ['type' => 'text', 'showIf' => 'page.flex_missing_dependency=1']]);
$dependencyMissingInputs = $dependencyMissingDefs->getInputfields($dependencyValue, 'invalid__');
$throws(static fn() => FlexDependencies::form($dependencyMissingInputs, $page), 'missing external dependency sources retain definition error context', FlexDefinitionException::class, $dependencyField->name);

// Core stores errors by input class/name; proxy processing must not clear them.
$dependencyOuter = $wire->wire(new InputfieldWrapper());
$dependencyTitleInput = $wire->fields->get('title')->getInputfield($page);
$dependencyTitleInput->value = 'Show';
$dependencyOuter->add($dependencyTitleInput);
$dependencyErrorInputs = $dependencyField->defs($page)->getInputfields($dependencyValue, $dependencyField->name . '__');
$dependencyErrorInputs->setParent($dependencyOuter);
$dependencyTitleInput->error('Existing native validation error');
$dependencyErrorForm = FlexDependencies::processInput($dependencyErrorInputs, $page, $wire->wire(new WireInputData($dependencyPost)));
$same(['Existing native validation error'], $dependencyTitleInput->getErrors(), 'external dependency processing preserves validation errors on the real native input');
$dependencyTitleInput->getErrors(true);
$same('Page visible', $dependencyErrorForm->getChildByName($dependencyField->name . '__page_detail')->value, 'isolated proxy names still evaluate submitted native source values');

$same('title=Show', (string) $dependencyErrorInputs->getChildByName($dependencyField->name . '__page_detail')->showIf, 'processing restores native browser references after using private proxy names');

$dependencyEdgePost[$dependencyEdgeField->name . '__enabled'] = '1';
$dependencyEdgePost[$dependencyEdgePrefix . 'chained_detail'] = 'Chained Page condition matched';
$dependencyEdgeEditor = $dependencyEdgeField->getInputfield($page);
$dependencyEdgeEditor->value = $dependencyEdgeValue;
$dependencyEdgeEditor->processInput($wire->wire(new WireInputData($dependencyEdgePost)));
$same('Chained Page condition matched', $dependencyEdgeEditor->value->items->first()->chained_detail, 'nested proxy resolves an ancestor condition whose Page reference is already being processed');

$dependencyThrowInputs = $dependencyField->defs($page)->getInputfields($dependencyValue, $dependencyField->name . '__');
$dependencyThrowInput = $dependencyThrowInputs->getChildByName($dependencyField->name . '__page_detail');
$dependencyThrowHook = $dependencyThrowInput->addHookBefore('processInput', static function() { throw new \RuntimeException('Expected native input exception'); });
$throws(static fn() => FlexDependencies::processInput($dependencyThrowInputs, $page, $wire->wire(new WireInputData($dependencyPost))), 'native input exceptions propagate from dependency processing', \RuntimeException::class, 'Expected native input exception');
$dependencyThrowInput->removeHook($dependencyThrowHook);
$same('title=Show', (string) $dependencyThrowInput->showIf, 'native input exceptions still restore browser dependency references');
