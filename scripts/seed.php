<?php namespace ProcessWire;
require __DIR__ . '/bootstrap.php';

$wire = wire();
if(is_file($wire->config->paths->siteModules . 'AgentTools/AgentTools.module.php')) {
	$wire->modules->get('AgentTools');
}
$wire->modules->get('LanguageSupport');
$wire->modules->get('LanguageSupportFields');
$language = $wire->languages->get('de');
if(!$language || !$language->id) {
	$language = $wire->languages->add('de');
	$language->title = 'Deutsch';
	$language->save();
}
$path = $wire->config->paths->templates . 'flex-fields/';
if(!is_dir($path)) {
	mkdir($path, 0755, true);
}
$examples = dirname(__DIR__) . '/examples/';
if(!is_file($path . 'contact.php')) {
	copy($examples . '02-basic.php', $path . 'contact.php');
}
$field = $wire->fields->get('contact');
if(!$field) {
	$field = new FlexField();
	$wire->wire($field);
	$field->name = 'contact';
	$field->label = 'Flex contact';
	$field->type = $wire->modules->get('FieldtypeFlex');
	$wire->fields->save($field);
}
$template = $wire->templates->get('basic-page');
if(!$template->fieldgroup->hasField($field)) {
	$template->fieldgroup->add($field);
	$template->fieldgroup->save();
}
$page = $wire->pages->get('template=basic-page, name=flex-demo, include=all');
if(!$page->id) {
	$page = $wire->pages->newPage([
		'template' => $template,
		'parent'   => 1,
		'name'     => 'flex-demo',
		'title'    => 'FieldtypeFlex demo',
	]);
	$page->of(false);
	$page->contact = [
		'title' => 'Hanna Flex',
		'email' => 'hanna@example.test',
	];
	$page->save();
}
$category = $wire->pages->get('template=basic-page, name=flex-category, include=all');
if(!$category->id) {
	$category = $wire->pages->newPage([
		'template' => $template,
		'parent'   => 1,
		'name'     => 'flex-category',
		'title'    => 'Example category',
	]);
	$category->of(false);
	$category->contact = ['title' => 'Category'];
	$category->save();
}
echo "FieldtypeFlex installed. Demo page: {$page->id} (/flex-demo/). ProcessWire {$wire->config->version}\n";
foreach(['03-text.php' => 'showcase.php'] as $exampleFile => $targetFile) {
	if(!is_file($path . $targetFile)) {
		copy($examples . $exampleFile, $path . $targetFile);
	}
}
$showcase = $wire->fields->get('showcase');
if(!$showcase) {
	$showcase = $wire->wire(new FlexField());
	$showcase->name = 'showcase';
	$showcase->label = 'Flex: languages, pages and rich text';
	$showcase->type = $field->type;
	$wire->fields->save($showcase);
	$page->of(false);
	if(!$template->fieldgroup->hasField($showcase)) {
		$template->fieldgroup->add($showcase);
		$template->fieldgroup->save();
	}
	$page->showcase = [
		'text_translation' => [
			'default' => 'English summary',
			'de' => 'Deutsche Zusammenfassung',
		],
		'text_body' => '<p>Rich text from <strong>FieldtypeFlex</strong>.</p>',
		'text_heading' => 'Open source compound fields',
	];
	$page->save('showcase');
}
