<?php namespace ProcessWire;

/**
 * Typed and nested Rows, media, default values, conditional visibility and
 * row titles. The root slide/fact/block collection is also used by DDEV demos.
 */
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
		'type'        => 'rows',
		'label'       => __('Slides'),
		'maxItems'    => 20,
		'rowCollapse' => 0,
		'addRowLabel' => __('Add slide'),
		'types'       => [
			'item' => [
				'label'    => __('Slide'),
				'rowTitle' => '#n: {heading}',
				'children' => [
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
						'useLanguages' => 'auto',
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
		'type'        => 'rows',
		'label'       => __('Facts'),
		'rowCollapse' => 0,
		'addRowLabel' => __('Add fact'),
		'types'       => [
			'item' => [
				'label'    => __('Fact'),
				'rowTitle' => '{heading}',
				'children' => [
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
		'type'        => 'rows',
		'label'       => __('Content blocks'),
		'maxItems'    => 20,
		'rowCollapse' => 0,
		'addRowLabel' => __('Add block'),
		'types'       => [
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
		],
	],

	'sections' => [
		'type'        => 'rows',
		'label'       => __('Sections'),
		'addRowLabel' => __('Add section'),
		'rowCollapse' => 3,
		'types'       => [
			'section' => [
				'label'    => __('Section'),
				'rowTitle' => '{heading}',
				'children' => [
					'heading' => [
						'type'     => 'text',
						'label'    => __('Section heading'),
						'required' => true,
					],
					'blocks'  => [
						'type'        => 'rows',
						'label'       => __('Blocks'),
						'addRowLabel' => __('Add block'),
						'rowCollapse' => 3,
						'types'       => [
							'text'  => [
								'label'    => __('Text'),
								'rowTitle' => '{heading}',
								'children' => [
									'heading' => [
										'type'  => 'text',
										'label' => __('Block heading'),
									],
									'body'    => [
										'type'  => 'TinyMCE',
										'label' => __('Body'),
									],
									'notes'   => [
										'type'        => 'rows',
										'label'       => __('Notes'),
										'addRowLabel' => __('Add note'),
										'rowCollapse' => 3,
										'types'       => [
											'note' => [
												'label'    => __('Note'),
												'rowTitle' => '{text}',
												'children' => [
													'text' => [
														'type'  => 'text',
														'label' => __('Note text'),
													],
												],
											],
										],
									],
								],
							],
							'image' => [
								'label'    => __('Image'),
								'rowTitle' => '{caption}',
								'children' => [
									'caption' => [
										'type'  => 'text',
										'label' => __('Caption'),
									],
									'image'   => [
										'type'     => 'image',
										'label'    => __('Image'),
										'maxFiles' => 1,
									],
								],
							],
						],
					],
				],
			],
		],
	],
];
