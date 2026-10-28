<?php namespace ProcessWire;

/**
 * Slides, facts, mixed content blocks and three levels of nested Rows.
 * rowTitle supports {heading|title}, {@type}, ancestor {@path} and _{heading}_
 * badges; […] de-emphasizes text/tokens, and #n inserts the row number.
 * Dependencies use local names or parent./root. for containing/root Flex scopes.
 * page.field targets an owning Page input; forpage.field targets the main editor
 * Page, including outside native Repeaters. See API.md#dependency-contexts.
 */
return [
	'heading'   => [
		'type'  => 'text',
		'label' => __('Content heading'),
	],
	'hero'      => [
		'type'     => 'image',
		'textformatters' => ['TextformatterEntities'],
		'label'    => __('Hero image'),
		'maxFiles' => 1,
	],
	'downloads' => [
		'type'       => 'file',
		'textformatters' => ['TextformatterEntities'],
		'label'      => __('Downloads'),
		'extensions' => 'pdf txt zip',
		'maxFiles'   => 5,
	],
	'slides'    => [
		'type'        => 'rows',
		'label'       => __('Slides'),
		'maxItems'    => 20,
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
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
						'textformatters' => ['TextformatterEntities'],
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
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
		'addRowLabel' => __('Add fact'),
		'types'       => [
			'item' => [
				'label'    => __('Fact'),
				'rowTitle' => '_{@type}_ {heading}',
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
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
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
						'textformatters' => ['TextformatterEntities'],
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
		'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
		'types'       => [
			'section' => [
				'label'    => __('Section'),
				'rowTitle' => '[{@path}] / _{heading}_',
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
						'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
						'types'       => [
							'text'  => [
								'label'    => __('Text'),
								'rowTitle' => '[{@path}] / _{heading}_',
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
										'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
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
										'textformatters' => ['TextformatterEntities'],
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
