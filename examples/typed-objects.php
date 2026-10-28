<?php namespace ProcessWire;

/** A typed repeatable fieldset configured through native Inputfield objects. */
$inputs = $wire->wire(new InputfieldWrapper());

$fieldset                   = $wire->modules->get('InputfieldFieldset');
$fieldset->name             = 'blocks';
$fieldset->label            = __('Content blocks');
$fieldset->repeatable       = true;
$fieldset->maxItems         = 20;
$fieldset->repeaterCollapse = 0;
$fieldset->repeaterAddLabel = __('Add block');
$fieldset->types = [
	'text'  => [
		'label'         => __('Text'),
		'repeaterTitle' => '#n: {heading}',
		'children'      => [
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
		'label'         => __('Image'),
		'repeaterTitle' => '#n: {caption}',
		'children'      => [
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
		'label'         => __('Quote'),
		'repeaterTitle' => '#n: {quote}',
		'children'      => [
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

$inputs->add($fieldset);

return $inputs;
