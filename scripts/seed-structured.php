<?php namespace ProcessWire;
require __DIR__ . '/bootstrap.php';

$wire = wire();
$browserFiles = dirname(__DIR__) . '/.dev/browser-files';
if(!is_dir($browserFiles)) {
	mkdir($browserFiles, 0755, true);
}
if(!is_file($browserFiles . '/flex-hero.png')) {
	$image = imagecreatetruecolor(320, 180);
	imagefill($image, 0, 0, imagecolorallocate($image, 25, 90, 160));
	imagefilledrectangle($image, 160, 0, 319, 179, imagecolorallocate($image, 230, 160, 55));
	imagepng($image, $browserFiles . '/flex-hero.png');
	imagedestroy($image);
}
file_put_contents($browserFiles . '/flex-download.txt', 'Disposable Flex upload fixture');
$definitions = $wire->config->paths->templates . 'flex-fields/structured.php';
copy(dirname(__DIR__) . '/examples/structured.php', $definitions);
$type = $wire->modules->get('FieldtypeFlex');
$field = $wire->fields->get('flex_content');
if(!$field) {
	$field = $wire->wire(new FlexField());
	$field->name = 'flex_content';
	$field->label = 'Flex content';
	$field->type = $type;
	$field->flexDefinitionFile = 'flex-fields/structured.php';
	$field->save();
}
$template = $wire->templates->get('flex_structured_demo');
if(!$template) {
	$group = $wire->wire(new Fieldgroup());
	$group->name = 'flex_structured_demo';
	$group->add($wire->fields->get('title'));
	$group->add($field);
	$group->save();
	$template = $wire->wire(new Template());
	$template->name = $group->name;
	$template->fieldgroup = $group;
	$template->save();
}
$page = $wire->pages->get('name=flex-structured, parent=1, include=all');
if(!$page->id) {
	$page = $wire->pages->newPage([
		'template' => $template,
		'parent'   => 1,
		'name'     => 'flex-structured',
		'title'    => 'Flex repeatable content',
	]);
	$page->of(false);
	$page->flex_content = ['heading' => 'Repeatable content'];
	foreach(['First slide', 'Second slide'] as $heading) {
		$page->flex_content->slides->addRow('item', [
			'heading' => $heading,
			'body'    => '<p>' . $heading . '</p>',
			'summary' => [
				'default' => $heading,
				'de'      => 'Deutsch: ' . $heading,
			],
			'price' => 5,
		]);
	}
	$page->flex_content->facts->addRow('item', [
		'heading' => 'First fact',
		'number'  => 0,
	]);
	$page->save();
}
$page->of(false);
$blocks = $page->flex_content->blocks;
if(!count($blocks)) {
	$imageRow = $blocks->addRow('image', [
		'caption' => 'Fixture image block',
	]);
	$blocks->addRow('quote', [
		'quote'  => 'A mixed repeatable set keeps each row type in its own schema.',
		'author' => 'FieldtypeFlex fixture',
	]);
	$page->save('flex_content');
	$imageRow->image->add($browserFiles . '/flex-hero.png');
	$page->save('flex_content');
}
echo "Structured demo: {$page->id} (/flex-structured/)\n";
$extra = $wire->fields->get('flex_extra');
if(!$extra) {
	$extra = $wire->wire(new FlexField());
	$extra->name = 'flex_extra';
	$extra->label = 'Independent Flex field';
	$extra->type = $type;
	$extra->flexDefinition = json_encode([
		'heading'  => ['type' => 'text'],
		'document' => [
			'type'       => 'file',
			'extensions' => 'txt',
			'maxFiles'   => 1,
		],
		'facts' => [
			'type'        => 'rows',
			'label'       => 'Facts',
			'rowCollapse' => 0,
			'addRowLabel' => 'Add fact',
			'types'       => [
				'item' => [
					'label'    => 'Fact',
					'rowTitle' => '{heading}',
					'children' => ['heading' => ['type' => 'text']],
				],
			],
		],
	]);
	$extra->save();
}
if(!$template->fieldgroup->hasField($extra)) {
	$template->fieldgroup->add($extra);
	$template->fieldgroup->save();
}
$repeaterTemplate = $wire->templates->get('flex_structured_repeaters_demo');
if(!$repeaterTemplate) {
	$group = $wire->wire(new Fieldgroup());
	$group->name = 'flex_structured_repeaters_demo';
	$group->add($wire->fields->get('title'));
	$group->save();
	$repeaterTemplate = $wire->wire(new Template());
	$repeaterTemplate->name = $group->name;
	$repeaterTemplate->fieldgroup = $group;
	$repeaterTemplate->save();
}
$repeaterType = $wire->modules->get('FieldtypeRepeater');
$repeaterType->getFieldClass();
foreach([
	'flex_content_a' => 'Structured Repeater A',
	'flex_content_b' => 'Structured Repeater B',
] as $name => $label) {
	$repeater = $wire->fields->get($name);
	if(!$repeater) {
		$repeater = $wire->wire(new RepeaterField());
		$repeater->name = $name;
		$repeater->label = $label;
		$repeater->type = $repeaterType;
		$repeater->repeaterFields = [$field->id];
		$repeater->repeaterTitle = '#n';
		$repeater->repeaterLoading = FieldtypeRepeater::loadingAll;
		$repeater->repeaterCollapse = FieldtypeRepeater::collapseAll;
		$repeater->save();
		$itemTemplate = $repeaterType->_getRepeaterTemplate($repeater);
		if(!$itemTemplate->fieldgroup->hasField($field)) {
			$itemTemplate->fieldgroup->add($field);
			$itemTemplate->fieldgroup->save();
		}
	}
	if(!$repeaterTemplate->fieldgroup->hasField($repeater)) {
		$repeaterTemplate->fieldgroup->add($repeater);
		$repeaterTemplate->fieldgroup->save();
	}
}
$repeaterPage = $wire->pages->get('name=flex-structured-repeaters, parent=1, include=all');
if(!$repeaterPage->id) {
	$repeaterPage = $wire->pages->newPage([
		'template' => $repeaterTemplate,
		'parent'   => 1,
		'name'     => 'flex-structured-repeaters',
		'title'    => 'Repeatable sets and media in native Repeaters',
	]);
	$repeaterPage->of(false);
	$repeaterPage->save();
}
$repeaterPage->of(false);
foreach([
	'flex_content_a' => 'A',
	'flex_content_b' => 'B',
] as $repeaterName => $prefix) {
	if(count($repeaterPage->{$repeaterName})) {
		continue;
	}
	foreach([1, 2] as $n) {
		$item = $repeaterPage->{$repeaterName}->getNewItem();
		$item->of(false);
		$item->flex_content = ['heading' => "$prefix$n"];
		$item->flex_content->slides->addRow('item', [
			'heading' => "$prefix$n slide",
			'body'    => "<p>$prefix$n text</p>",
		]);
		$item->flex_content->blocks->addRow('image', [
			'caption' => "$prefix$n image block",
		]);
		$item->flex_content->blocks->addRow('quote', [
			'quote'  => "$prefix$n quote block",
			'author' => "Author $prefix$n",
		]);
		$item->save();
	}
	$repeaterPage->save($repeaterName);
}
echo "Structured Repeater demo: {$repeaterPage->id} (/flex-structured-repeaters/)\n";
