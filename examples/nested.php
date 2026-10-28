<?php namespace ProcessWire;

return [
	'sections' => [
		'type'             => 'fieldset',
		'repeatable'       => true,
		'label'            => __('Sections'),
		'repeaterAddLabel' => __('Add section'),
		'repeaterCollapse' => 3,
		'types'            => [
			'section' => [
				'label'         => __('Section'),
				'repeaterTitle' => '{heading}',
				'children'      => [
					'heading' => [
						'type'     => 'text',
						'label'    => __('Section heading'),
						'required' => true,
					],
					'blocks'  => [
						'type'             => 'fieldset',
						'repeatable'       => true,
						'label'            => __('Blocks'),
						'repeaterAddLabel' => __('Add block'),
						'repeaterCollapse' => 3,
						'types'            => [
							'text'  => [
								'label'         => __('Text'),
								'repeaterTitle' => '{heading}',
								'children'      => [
									'heading' => [
										'type'  => 'text',
										'label' => __('Block heading'),
									],
									'body'    => [
										'type'  => 'TinyMCE',
										'label' => __('Body'),
									],
									'notes'   => [
										'type'             => 'fieldset',
										'repeatable'       => true,
										'label'            => __('Notes'),
										'repeaterAddLabel' => __('Add note'),
										'repeaterCollapse' => 3,
										'types'            => [
											'note' => [
												'label'         => __('Note'),
												'repeaterTitle' => '{text}',
												'children'      => [
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
								'label'         => __('Image'),
								'repeaterTitle' => '{caption}',
								'children'      => [
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
