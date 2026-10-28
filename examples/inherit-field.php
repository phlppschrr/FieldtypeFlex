<?php namespace ProcessWire;

/** Requires date_input_source (Datetime) and rich_text_source (Textarea using TinyMCE). */
return [
	'starts_at' => [
		'type'         => 'datetime',
		'inheritField' => 'date_input_source',
		'label'        => __('Start date and time'),
	],
	'body' => [
		'type'         => 'TinyMCE',
		'inheritField' => 'rich_text_source',
		'label'        => __('Body'),
		'rows'         => 12,
	],
];
