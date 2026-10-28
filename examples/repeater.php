<?php namespace ProcessWire;

// The same Flex field can be assigned to several native Repeater templates.
return [
	'heading' => [
		'type'  => 'Text',
		'label' => __('Item heading'),
	],
	'body'    => [
		'type'     => 'TinyMCE',
		'label'    => __('Body'),
		'height'   => 180,
		'lazyMode' => 1,
		'toolbar'  => 'bold italic link code',
		'menubar'  => false,
	],
	'details' => [
		'type'     => 'Fieldset',
		'label'    => __('Translated details'),
		'children' => [
			'translation' => [
				'type'         => 'TinyMCE',
				'label'        => __('Translated rich text'),
				'useLanguages' => true,
				'height'       => 180,
				'lazyMode'     => 1,
				'toolbar'      => 'bold italic code',
				'menubar'      => false,
			],
		],
	],
];
