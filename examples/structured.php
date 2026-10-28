<?php namespace ProcessWire;

// Flex extensions: repeatable fieldsets, scoped row values and stable row identity.
return [
	'heading'   => [
		'type'  => 'text',
		'label' => __('Content heading'),
	],
	'hero'      => [
		'type'     => 'image',
		'label'    => __('Hero image'),
		'maxFiles' => 1,
	],
	'downloads' => [
		'type'       => 'file',
		'label'      => __('Downloads'),
		'extensions' => 'pdf txt zip',
		'maxFiles'   => 5,
	],
	'slides'    => [
		'type'             => 'fieldset',
		'repeatable'       => true,
		'label'            => __('Slides'),
		'maxItems'         => 20,
		'repeaterCollapse' => 0,
		'repeaterAddLabel' => __('Add slide'),
		'types'            => [
			'item' => [
				'label'         => __('Slide'),
				'repeaterTitle' => '#n: {heading}',
				'children'      => [
					'heading' => [
						'type'     => 'text',
						'label'    => __('Slide heading'),
						'required' => true,
					],
					'image'   => [
						'type'     => 'image',
						'label'    => __('Slide image'),
						'maxFiles' => 1,
					],
					'body'    => [
						'type'     => 'TinyMCE',
						'label'    => __('Slide text'),
						'lazyMode' => 0,
					],
					'summary' => [
						'type'         => 'text',
						'label'        => __('Summary'),
						'useLanguages' => true,
					],
					'price'   => [
						'type'  => 'float',
						'label' => __('Price'),
					],
					'enabled' => [
						'type'  => 'checkbox',
						'label' => __('Enable link'),
					],
					'link'    => [
						'type'   => 'url',
						'label'  => __('Link'),
						'showIf' => 'enabled=1',
					],
				],
			],
		],
	],
	'facts'     => [
		'type'             => 'fieldset',
		'repeatable'       => true,
		'label'            => __('Facts'),
		'repeaterCollapse' => 0,
		'repeaterAddLabel' => __('Add fact'),
		'types'            => [
			'item' => [
				'label'         => __('Fact'),
				'repeaterTitle' => '{heading}',
				'children'      => [
					'heading' => [
						'type'  => 'text',
						'label' => __('Fact'),
					],
					'number'  => [
						'type'  => 'integer',
						'label' => __('Number'),
					],
				],
			],
		],
	],
	'blocks'    => [
		'type'             => 'fieldset',
		'repeatable'       => true,
		'label'            => __('Content blocks'),
		'maxItems'         => 20,
		'repeaterCollapse' => 0,
		'repeaterAddLabel' => __('Add block'),
		'types'            => [
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
		],
	],
];
