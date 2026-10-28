<?php namespace ProcessWire;

$countryOptions = require __DIR__.'/options.php';

return [
	'summary'    => [
		'type'         => 'textarea',
		'label'        => __('Summary'),
		'useLanguages' => true,
	],
	'category'   => [
		'type'              => 'page',
		'label'             => __('Category'),
		'inputfield'        => 'InputfieldSelect',
		'derefAsPage'       => 2,
		'findPagesSelector' => 'parent=1, template=basic-page',
		'labelFieldName'    => 'title',
	],
	'categories' => [
		'type'              => 'page',
		'label'             => __('Categories'),
		'inputfield'        => 'InputfieldAsmSelect',
		'derefAsPage'       => 0,
		'findPagesSelector' => 'parent=1, template=basic-page',
		'labelFieldName'    => 'title',
	],
	'body'       => [
		'type'  => 'TinyMCE',
		'label' => __('Body'),
		'rows'  => 8,
	],
	'country'    => [
		'type'    => 'select',
		'label'   => __('Country'),
		'options' => $countryOptions,
	],
	'enabled'    => [
		'type'  => 'checkbox',
		'label' => __('Enable link'),
	],
	'link'       => [
		'type'   => 'url',
		'label'  => __('Link'),
		'showIf' => 'enabled=1',
	],
	'heading'    => [
		'type'        => 'text',
		'label'       => __('Heading'),
		'renamedFrom' => 'old_heading',
	],
];
