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
		// Also inherits the source Fieldtype's ordered textformatters list.
		// Add 'textformatters' => [] to replace it with no formatters.
		'inheritField' => 'rich_text_source',
		'label'        => __('Body'),
		'rows'         => 12,
	],
];
