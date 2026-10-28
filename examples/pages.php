<?php namespace ProcessWire;

/** Native Page and PageArray values discovered below the home page. */
return [
	'category'      => [
		'type'              => 'page',
		'label'             => __('Category'),
		'inputfield'        => 'InputfieldSelect',
		'derefAsPage'       => 2,
		'findPagesSelector' => 'parent=1, template=basic-page',
		'labelFieldName'    => 'title',
	],
	'related_pages' => [
		'type'              => 'page',
		'label'             => __('Related pages'),
		'inputfield'        => 'InputfieldAsmSelect',
		'derefAsPage'       => 0,
		'findPagesSelector' => 'parent=1, template=basic-page',
		'labelFieldName'    => 'title',
	],
];
