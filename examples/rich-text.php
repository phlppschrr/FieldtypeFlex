<?php namespace ProcessWire;

/** Requires an installed native TinyMCE input module. */
return [
	'body' => [
		'type'           => 'TinyMCE',
		// An explicit empty native formatter list preserves HTML without extra encoding.
		'textformatters' => [],
		'label'          => __('Body'),
		'rows'           => 8,
		'toolbar'        => 'bold italic | bullist numlist | link',
	],
];
