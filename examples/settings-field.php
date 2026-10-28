<?php namespace ProcessWire;

/** Requires a native FieldtypeTextarea field named rich_text_source using TinyMCE. */
return [
	'body' => [
		'type'          => 'TinyMCE',
		'label'         => __('Body'),
		'settingsField' => 'rich_text_source',
	],
];
