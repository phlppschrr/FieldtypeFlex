<?php namespace ProcessWire;

/** A typed rows collection configured through native Inputfield objects. */
$inputs = $wire->wire(new InputfieldWrapper());

$rows               = $wire->modules->get('InputfieldFlexRows');
$rows->name         = 'blocks';
$rows->label        = __('Content blocks');
$rows->maxItems     = 20;
$rows->rowCollapse  = 0;
$rows->addRowLabel  = __('Add block');
$rows->types        = [
	'text'  => [
		'label'    => __('Text'),
		'rowTitle' => '#n: {heading}',
		'children' => [
			'heading' => [
				'type'     => 'text',
				'label'    => __('Heading'),
				'required' => true,
			],
			'body'    => [
				'type'     => 'TinyMCE',
				'label'    => __('Body'),
				'lazyMode' => 0,
			],
		],
	],
	'image' => [
		'label'    => __('Image'),
		'rowTitle' => '#n: {caption}',
		'children' => [
			'image'   => [
				'type'     => 'image',
				'label'    => __('Image'),
				'maxFiles' => 1,
			],
			'caption' => [
				'type'  => 'text',
				'label' => __('Caption'),
			],
		],
	],
	'quote' => [
		'label'    => __('Quote'),
		'rowTitle' => '#n: {quote}',
		'children' => [
			'quote'  => [
				'type'  => 'textarea',
				'label' => __('Quote'),
			],
			'author' => [
				'type'  => 'text',
				'label' => __('Author'),
			],
		],
	],
];

$inputs->add($rows);

return $inputs;
