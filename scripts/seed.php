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
foreach(['contact.php', 'options.php'] as $exampleFile) {
	if(!is_file($path . $exampleFile)) {
		copy($examples . $exampleFile, $path . $exampleFile);
	}
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
		'first_name' => 'Hanna',
		'last_name'  => 'Flex',
		'email'      => 'hanna@example.test',
		'bio'        => 'ProcessWire expert from Berlin',
		'city'       => 'Berlin',
		'country'    => 'DE',
		'interests'  => ['code', 'design'],
		'newsletter' => 1,
		'notes'      => 'Open source news',
		'num_tours'  => 5,
		'date_from'  => '2026-10-14',
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
	$category->contact = ['first_name' => 'Category'];
	$category->save();
}
echo "FieldtypeFlex installed. Demo page: {$page->id} (/flex-demo/). ProcessWire {$wire->config->version}\n";
foreach(['advanced.php' => 'showcase.php'] as $exampleFile => $targetFile) {
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
		'summary' => [
			'default' => 'English summary',
			'de'      => 'Deutsche Zusammenfassung',
		],
		'body'    => '<p>Rich text from <strong>FieldtypeFlex</strong>.</p>',
		'heading' => 'Open source compound fields',
	];
	$page->save('showcase');
}
