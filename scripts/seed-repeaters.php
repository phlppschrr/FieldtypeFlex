<?php namespace ProcessWire;
require __DIR__ . '/bootstrap.php';

// Dedicated disposable browser fixture; do not attach regression fields to a site's normal templates.
$wire = wire();
$wire->modules->get('LanguageSupport');
$wire->modules->get('LanguageSupportFields');
$rich = $wire->fields->get('repeater_content');
if(!$rich) {
	$rich = $wire->wire(new FlexField());
	$rich->name = 'repeater_content';
	$rich->label = 'Flex rich text';
	$rich->type = $wire->modules->get('FieldtypeFlex');
	$rich->flexDefinition = json_encode([
		'heading' => ['type' => 'text'],
		'body' => ['type' => 'TinyMCE', 'height' => 180],
		'translation' => ['type' => 'TinyMCE', 'useLanguages' => true, 'height' => 180],
	], JSON_THROW_ON_ERROR);
	$wire->fields->save($rich);
}
$template = $wire->templates->get('flex-repeaters');
if(!$template) {
	$group = $wire->wire(new Fieldgroup());
	$group->name = 'flex-repeaters';
	$group->add($wire->fields->get('title'));
	$group->save();
	$template = $wire->wire(new Template());
	$template->name = $group->name;
	$template->fieldgroup = $group;
	$wire->templates->save($template);
}
$type = $wire->modules->get('FieldtypeRepeater');
$type->getFieldClass();
foreach([
	'flex_blocks_a' => 'Repeater A',
	'flex_blocks_b' => 'Repeater B',
] as $name => $label) {
	$field = $wire->fields->get($name);
	if(!$field) {
		$field = $wire->wire(new RepeaterField());
		$field->name = $name;
		$field->label = $label;
		$field->type = $type;
		$field->repeaterFields = [$rich->id];
		$field->repeaterTitle = '#n';
		$field->repeaterLoading = FieldtypeRepeater::loadingAll;
		$field->repeaterCollapse = FieldtypeRepeater::collapseAll;
		$wire->fields->save($field);
		$itemTemplate = $type->_getRepeaterTemplate($field);
		if(!$itemTemplate->fieldgroup->hasField($rich)) {
			$itemTemplate->fieldgroup->add($rich);
			$itemTemplate->fieldgroup->save();
		}
	}
	if(!$template->fieldgroup->hasField($field)) {
		$template->fieldgroup->add($field);
		$template->fieldgroup->save();
	}
}
foreach([
	'flex-repeaters'       => false,
	'flex-repeaters-empty' => true,
] as $name => $empty) {
	$page = $wire->pages->get("template=flex-repeaters, name=$name, include=all");
	if(!$page->id) {
		$page = $wire->pages->newPage([
			'template' => $template,
			'parent'   => 1,
			'name'     => $name,
			'title'    => $empty ? 'Flex: empty Repeaters' : 'Flex: multiple Repeaters and TinyMCE',
		]);
		$page->of(false);
		$page->save();
		if(!$empty) {
			foreach([
				'flex_blocks_a' => 'A',
				'flex_blocks_b' => 'B',
			] as $fieldName => $prefix) {
				foreach([1, 2] as $n) {
					$item = $page->get($fieldName)->getNewItem();
					$item->of(false);
					$item->repeater_content = [
						'heading'     => "$prefix$n",
						'body'        => "<p>$prefix$n body</p>",
						'translation' => [
							'default' => "<p>$prefix$n English</p>",
							'de'      => "<p>$prefix$n Deutsch</p>",
						],
					];
					$item->save();
				}
				$page->save($fieldName);
			}
		}
	}
	echo "Repeater browser fixture: $page->id (/$name/)\n";
}
