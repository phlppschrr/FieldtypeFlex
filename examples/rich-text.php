<?php namespace ProcessWire;

/** Requires an installed native TinyMCE input module. */
return [
	'body' => [
		'type'    => 'TinyMCE',
		'label'   => __('Body'),
		'rows'    => 8,
		'toolbar' => 'bold italic | bullist numlist | link',
	],
];
