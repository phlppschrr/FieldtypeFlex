<?php namespace ProcessWire;
require __DIR__ . '/bootstrap.php';

$wire = wire();
$type = $wire->modules->get('FieldtypeFlex');
$directory = $wire->config->paths->templates . 'flex-fields/';
if(!is_dir($directory)) {
	mkdir($directory, 0755, true);
}
copy(dirname(__DIR__) . '/examples/08-rows.php', $directory . 'nested.php');
$fields = [];
foreach(['flex_nested', 'flex_nested_extra'] as $name) {
	$field = $wire->fields->get($name);
	if(!$field) {
		$field = $wire->wire(new FlexField());
		$field->name = $name;
		$field->type = $type;
		$field->label = $name === 'flex_nested'
			? __('Nested content')
			: __('Independent nested content');
		$field->flexDefinitionFile = 'flex-fields/nested.php';
		$field->save();
	}
	$fields[] = $field;
}
$template = $wire->templates->get('flex_nested_demo');
if(!$template) {
	$group = $wire->wire(new Fieldgroup());
	$group->name = 'flex_nested_demo';
	$group->add($wire->fields->get('title'));
	foreach($fields as $field) {
		$group->add($field);
	}
	$group->save();
	$template = $wire->wire(new Template());
	$template->name = $group->name;
	$template->fieldgroup = $group;
	$template->save();
}
$page = $wire->pages->get('name=flex-nested, parent=1, include=all');
if(!$page->id) {
	$page = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'flex-nested', 'title' => 'Nested Flex fieldsets']);
	$page->of(false);
	$page->save();
}
$populate = static function(Page $owner, Field $field, string $label): void {
	$owner->of(false);
	$value = $owner->getUnformatted($field->name);
	if(count($value->sections)) {
		return;
	}
	$section = $value->sections->addRow('section', ['heading' => $label . ' A']);
	$text = $section->blocks->addRow('text', ['heading' => 'Text A', 'body' => '<p>Nested rich text</p>']);
	$text->notes->addRow('note', ['text' => 'Third level']);
	$section->blocks->addRow('image', ['caption' => 'Nested image']);
	$value->sections->addRow('section', ['heading' => $label . ' B']);
	$owner->save($field->name);
};
foreach($fields as $field) {
	$populate($page, $field, $field->label);
}
echo "Nested demo: {$page->id}\n";
$repeaterPage = $wire->pages->get('name=flex-structured-repeaters, parent=1, include=all');
if($repeaterPage->id) {
	$repeaterPage->of(false);
	foreach(['flex_content_a', 'flex_content_b'] as $name) {
		$repeater = $wire->fields->get($name);
		$itemTemplate = $repeater->type->_getRepeaterTemplate($repeater);
		if(!$itemTemplate->fieldgroup->hasField($fields[0])) {
			$itemTemplate->fieldgroup->add($fields[0]);
			$itemTemplate->fieldgroup->save();
			$repeater->repeaterFields = array_values(array_unique(array_merge($repeater->repeaterFields, [$fields[0]->id])));
			$repeater->save();
		}
		foreach($repeaterPage->{$name} as $item) {
			$populate($item, $fields[0], $name);
		}
	}
	echo "Native Repeater demo: {$repeaterPage->id}\n";
}
