<?php namespace ProcessWire;

return [
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
