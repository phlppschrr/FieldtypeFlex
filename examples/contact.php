<?php namespace ProcessWire;

/**
 * Contact form definition. Loaded with the current `$page`, `$field` and `$wire`.
 * @var Page $page
 * @var Field $field
 * @var ProcessWire $wire
 */
$countryOptions = require __DIR__.'/options.php';

return [
	'first_name' => [
		'type'     => 'text',
		'label'    => __('First name'),
		'required' => true,
	],
	'email'      => [
		'type'  => 'email',
		'label' => __('Email'),
	],
	'address'    => [
		'type'     => 'fieldset',
		'label'    => __('Address'),
		'children' => [
			'city'    => [
				'type'  => 'text',
				'label' => __('City'),
			],
			'country' => [
				'type'    => 'select',
				'label'   => __('Country'),
				'options' => $countryOptions,
			],
		],
	],
];
