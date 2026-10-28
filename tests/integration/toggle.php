<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$options = [-128 => 'Low', 0 => 'Draft & pending', 5 => 'Approved', 127 => 'High'];
$schema = ['state' => ['type' => 'Toggle', 'value' => 5, 'options' => $options]];
$toggleField = $createField('flex_toggle', $schema);
$name = $toggleField->name;
$native = $wire->modules->get('InputfieldToggle');
$native->hasFieldtype = false;
$native->setOptions($options);
$wrapper = $wire->wire(new InputfieldWrapper());
$native->name = 'state';
$wrapper->add($native);
foreach(['PHP' => $schema, 'JSON' => null, 'wrapper' => $wrapper] as $format => $source) {
	$defs = new FlexFieldDefs($toggleField, $page, $source);
	$value = new FlexValue($page, $toggleField, $defs);
	if($format !== 'wrapper') $same(5, $value->state, "$format default value is independent of settings order");
	$same($options, $value->getOptions('state'), "$format custom option labels");
	foreach([-128, '-128', 0, '0', 5, '5', 127, '', null, 6, 'draft'] as $input) {
		$value->state = $input;
		$expected = $native->sanitizeValue($input);
		$same($expected === '' ? '' : (int) $expected, $value->state, "$format Toggle matches native keys with canonical integer type for " . var_export($input, true));
	}
	$value->state = 0;
	$same('Draft & pending', $value->optionLabel('state'), "$format zero option label");
	$formatted = $type->formatValue($page, $toggleField, $value);
	$same(0, $formatted->state, "$format formatted Toggle keeps integer key");
	$same('Draft &amp; pending', $formatted->optionLabel('state'), "$format formatted option label is encoded");
}

foreach([['draft' => 'Draft'], ['yes' => 'Invalid alias'], [-129 => 'Too low'], [128 => 'Too high']] as $invalid) {
	$throws(fn() => new FlexFieldDefs($toggleField, $page, ['state' => ['type' => 'Toggle', 'options' => $invalid]]), 'invalid Toggle option keys fail definition validation', FlexDefinitionException::class);
	$throws(static function() use ($wire, $toggleField, $page, $invalid): void {
		$input = $wire->modules->get('InputfieldToggle');
		$input->hasFieldtype = false;
		$input->name = 'state';
		$input->setOptions($invalid);
		$source = $wire->wire(new InputfieldWrapper());
		$source->add($input);
		new FlexFieldDefs($toggleField, $page, $source);
	}, 'native wrapper cannot bypass Toggle option validation');
}
$numericDefs = new FlexFieldDefs($toggleField, $page, ['state' => ['type' => 'Toggle', 'options' => ['05' => 'Five', '-0' => 'Zero']]]);
$numericValue = new FlexValue($page, $toggleField, $numericDefs);
$same([5 => 'Five', 0 => 'Zero'], $numericValue->getOptions('state'), 'numeric string option keys normalize consistently across cores');
$numericValue->state = '5';
$same(5, $numericValue->state, 'normalized string option key accepts canonical value');

$inherited = $wire->wire(new Field());
$inherited->name = 'flex_toggle_source_' . bin2hex(random_bytes(3));
$inherited->type = $wire->modules->get('FieldtypeToggle');
$temporaryFields[] = $inherited;
$wire->fields->save($inherited);
$defs = new FlexFieldDefs($toggleField, $page, ['state' => ['type' => 'Toggle', 'inheritField' => $inherited->name, 'value' => 5, 'options' => $options]]);
$same(5, (new FlexValue($page, $toggleField, $defs))->state, 'custom Toggle options override inherited Fieldtype configuration');

// Copies of already rendered delegated inputs must bind fresh native widgets.
$native->inputfieldClass = 'InputfieldSelect';
$native->value = 5;
$native->render();
$copy = FlexDependencies::copyInput($native);
$copy->name = 'copied_state';
$assert(str_contains($copy->render(), 'name="copied_state"'), 'rendered Toggle copy binds a fresh delegated input');
$same(5, $copy->value, 'rendered Toggle copy retains its custom selection');
$copy->setOptions([7 => 'Independent']);
$same($options, $native->getOptions(), 'custom option changes do not mutate source input');

foreach([-128, 0, 5, 127, ''] as $key) {
	$page->getUnformatted($name)->state = $key;
	$page->save($name);
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$same($key, $page->getUnformatted($name)->state, 'custom Toggle key survives save/reload');
	$same($key, $page->getUnformatted($name)->rawArray()['state'], 'canonical Toggle key retains its type');
}

// The native form must accept custom values and preserve their integer type.
$page->getUnformatted($name)->state = 5;
$page->save($name);
$editor = $toggleField->getInputfield($page);
$editor->value = $page->getUnformatted($name);
$html = $editor->render();
$dom = new \DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<html><body>' . $html . '</body></html>');
libxml_clear_errors(); libxml_use_internal_errors($previous);
$post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"][@name]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
$post[$name . '__state'] = '-128';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'native form accepts a negative custom Toggle key');
$same(-128, $editor->value->state, 'native form processes custom Toggle key');
$page->$name = $editor->value;
$page->save($name);
$same(-128, $wire->pages->getFresh($id)->getUnformatted($name)->state, 'native form value persists');

// A rendered blank/deselected custom Toggle must replace its previous selection.
foreach(['blank' => '', 'deselect' => null] as $case => $submitted) {
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$page->getUnformatted($name)->state = 5;
	$page->save($name);
	$editor = $toggleField->getInputfield($page);
	$editor->value = $page->getUnformatted($name);
	$dom->loadHTML('<html><body>' . $editor->render() . '</body></html>');
	$post = [];
	foreach((new \DOMXPath($dom))->query('//input[@type="hidden"][@name]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
	if($submitted !== null) $post[$name . '__state'] = $submitted;
	else $post['_' . $name . '__state_'] = '1'; // Native Toggle's rendered presence helper.
	$editor->processInput($wire->wire(new WireInputData($post)));
	$same([], $editor->getErrors(), "$case custom Toggle clears without validation errors");
	$same('', $editor->value->state, "$case custom Toggle clears a previous selection");
	$page->$name = $editor->value;
	$page->save($name);
	$same('', $wire->pages->getFresh($id)->getUnformatted($name)->state, "$case cleared Toggle persists");
}

$required = clone $toggleField;
$required->flexDefinition = json_encode(['state' => ['type' => 'Toggle', 'required' => true, 'options' => $options]]);
$editor = $required->getInputfield($page);
$editor->value = $type->getBlankValue($page, $required);
$editor->value->state = 5;
$dom->loadHTML('<html><body>' . $editor->render() . '</body></html>');
$post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"][@name]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
$post[$name . '__state'] = '';
$editor->processInput($wire->wire(new WireInputData($post)));
$assert((bool) $editor->getErrors(), 'Required custom Toggle rejects clearing a previous selection');
$editor->getErrors(true); $editor->inputs()->getErrors(true);
$post[$name . '__state'] = '0';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'Required custom Toggle accepts zero during recovery');
$same(0, $editor->value->state, 'Required recovery retains integer zero');

foreach(['absent' => [], 'hidden' => ['collapsed' => Inputfield::collapsedHidden], 'locked' => ['collapsed' => Inputfield::collapsedLocked], 'dependency' => ['showIf' => 'enabled=1']] as $case => $settings) {
	$guardDefs = new FlexFieldDefs($toggleField, $page, [
		'enabled' => ['type' => 'checkbox'],
		'state' => $settings + ['type' => 'Toggle', 'options' => $options],
	]);
	$value = new FlexValue($page, $toggleField, $guardDefs, ['enabled' => 0, 'state' => 5]);
	$inputs = $guardDefs->getInputfields($value, $name . '__');
	$post = $case === 'absent' ? [] : [$name . '__state' => ''];
	$form = FlexDependencies::processInput($inputs, $page, $wire->wire(new WireInputData($post)));
	$same(5, $form->getChildByName($name . '__state')->value, "$case custom Toggle retains its guarded selection");
}

// A surrounding native Toggle is processed through a dependency alias.
$dependencyDefs = new FlexFieldDefs($toggleField, $page, ['details' => ['type' => 'text', 'showIf' => 'external_state=5']]);
$inputs = $dependencyDefs->getInputfields(new FlexValue($page, $toggleField, $dependencyDefs), $name . '__');
$outer = $wire->wire(new InputfieldWrapper());
$external = FlexDependencies::copyInput($native);
$external->name = 'external_state';
$external->value = 5;
$outer->add($external);
$outer->add($inputs);
$post = ['_external_state_' => '1'];
$form = FlexDependencies::processInput($inputs, $page, $wire->wire(new WireInputData($post)));
$alias = $form->flexDependencyAliases['external_state'];
$same('', $form->getChildByName($alias)->value, 'dependency alias forwards custom Toggle deselection helper');
$same(5, $external->value, 'dependency proxy does not mutate the original Toggle');

$defaultDefs = new FlexFieldDefs($toggleField, $page, ['state' => ['type' => 'Toggle', 'useOther' => true]]);
$defaultValue = new FlexValue($page, $toggleField, $defaultDefs);
foreach([0, 1, 2, ''] as $key) {
	$defaultValue->state = $key;
	$same($key, $defaultValue->state, 'default Toggle retains built-in three-state values');
}
$same(1, $defaultDefs->getPropertyInputfield('state')->sanitizeValue('yes'), 'fresh built-in Toggle copy retains native yes alias');
