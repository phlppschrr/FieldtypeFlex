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
		'label' => 'Content heading',
	],
	'hero'      => [
		'type'     => 'image',
		'textformatters' => ['TextformatterEntities'],
		'label'    => 'Hero image',
		'maxFiles' => 1,
	],
	'downloads' => [
		'type'       => 'file',
		'textformatters' => ['TextformatterEntities'],
		'label'      => 'Downloads',
		'extensions' => 'pdf txt zip',
		'maxFiles'   => 5,
	],
	'notes_enabled' => [
		'type' => 'checkbox',
		'label' => 'Enable editorial notes',
	],
	'slides'    => [
		'type'        => 'rows',
		'label'       => 'Slides',
		'maxItems'    => 20,
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
		'addRowLabel' => 'Add slide',
		'types'       => [
			'item' => [
				'label'    => 'Slide',
				'rowTitle' => '#n: {heading}', // Row number and live heading.
				'children' => [
					'heading' => [
						'type'     => 'text',
						'label'    => 'Slide heading',
						'required' => true,
					],
					'image'   => [
						'type'     => 'image',
						'textformatters' => ['TextformatterEntities'],
						'label'    => 'Slide image',
						'maxFiles' => 1,
					],
					'body'    => [
						'type'     => 'TinyMCE',
						'label'    => 'Slide text',
						'lazyMode' => 0,
					],
					'summary' => [
						'type'         => 'text',
						'label'        => 'Summary',
						'useLanguages' => true, // Requires LanguageSupport and LanguageSupportFields.
					],
					'enabled' => [
						'type'  => 'checkbox',
						'label' => 'Enable link',
					],
					'link'    => [
						'type'   => 'url',
						'label'  => 'Link',
						'showIf' => 'enabled=1',
					],
				],
			],
		],
	],
	'facts'     => [
		'type'        => 'rows',
		'label'       => 'Facts',
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
		'rowLazyLoad' => true, // Load closed simple row bodies on opening; rich text/media types render directly.
		'addRowLabel' => 'Add fact',
		'types'       => [
			'item' => [
				'label'    => 'Fact',
				'rowTitle' => '_{@type}_ {heading}',
				'children' => [
					'heading' => [
						'type'  => 'text',
						'label' => 'Fact',
					],
					'number'  => [
						'type'  => 'integer',
						'label' => 'Number',
					],
				],
			],
		],
	],
	'blocks'    => [
		'type'        => 'rows',
		'label'       => 'Content blocks',
		'maxItems'    => 20,
		'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
		'addRowLabel' => 'Add block',
		'types'       => [
			'text'  => [
				'label'    => 'Text',
				'rowTitle' => '#n: {heading}',
				'children' => [
					'heading' => [
						'type'     => 'text',
						'label'    => 'Heading',
						'required' => true,
					],
					'body'    => [
						'type'     => 'TinyMCE',
						'label'    => 'Body',
						'lazyMode' => 0,
					],
				],
			],
			'image' => [
				'label'    => 'Image',
				'rowTitle' => '#n: {caption}',
				'children' => [
					'image'   => [
						'type'     => 'image',
						'textformatters' => ['TextformatterEntities'],
						'label'    => 'Image',
						'maxFiles' => 1,
					],
					'caption' => [
						'type'  => 'text',
						'label' => 'Caption',
					],
				],
			],
			'quote' => [
				'label'    => 'Quote',
				'rowTitle' => '#n: {quote}',
				'children' => [
					'quote'  => [
						'type'  => 'textarea',
						'label' => 'Quote',
					],
					'author' => [
						'type'  => 'text',
						'label' => 'Author',
					],
				],
			],
		],
	],

	'sections' => [
		'type'        => 'rows',
		'label'       => 'Sections',
		'addRowLabel' => 'Add section',
		'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
		'types'       => [
			'section' => [
				'label'    => 'Section',
				'rowTitle' => '[{@path}] / _{heading}_', // Muted ancestor types and a heading badge.
				'children' => [
					'heading' => [
						'type'     => 'text',
						'label'    => 'Section heading',
						'required' => true,
					],
					'show_notes' => [
						'type' => 'checkbox',
						'label' => 'Show notes in this section',
						'showIf' => 'root.notes_enabled=1', // Read the root Flex property from this section row.
					],
					'blocks'  => [
						'type'        => 'rows',
						'label'       => 'Blocks',
						'addRowLabel' => 'Add block',
						'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
						'types'       => [
							'text'  => [
								'label'    => 'Text',
								'rowTitle' => '[{@path}] / _{heading}_',
								'children' => [
									'heading' => [
										'type'  => 'text',
										'label' => 'Block heading',
									],
									'body'    => [
										'type'  => 'TinyMCE',
										'label' => 'Body',
									],
									'notes'   => [
										'type'        => 'rows',
										'label'       => 'Notes',
										'addRowLabel' => 'Add note',
										'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL,
										'showIf' => 'parent.show_notes=1, root.notes_enabled=1', // The containing section and the Flex root must both enable notes.
										'types'       => [
											'note' => [
												'label'    => 'Note',
												'rowTitle' => '{text}',
												'children' => [
													'text' => [
														'type'  => 'text',
														'label' => 'Note text',
													],
												],
											],
										],
									],
								],
							],
							'image' => [
								'label'    => 'Image',
								'rowTitle' => '{caption}',
								'children' => [
									'caption' => [
										'type'  => 'text',
										'label' => 'Caption',
									],
									'image'   => [
										'type'     => 'image',
										'textformatters' => ['TextformatterEntities'],
										'label'    => 'Image',
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
