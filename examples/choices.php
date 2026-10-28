<?php namespace ProcessWire;

/** Small select and checkbox examples. `options.php` is a reusable helper file. */
$countries = require __DIR__.'/options.php';

return [
	'country'    => [
		'type'    => 'select',
		'label'   => __('Country'),
		'options' => $countries,
	],
	'interests'  => [
		'type'    => 'checkboxes',
		'label'   => __('Interests'),
		'options' => [
			'code'    => __('Code'),
			'design'  => __('Design'),
			'writing' => __('Writing'),
		],
	],
	'newsletter' => [
		'type'  => 'checkbox',
		'label' => __('Send me occasional news'),
	],
];
