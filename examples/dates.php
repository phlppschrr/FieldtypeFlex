<?php namespace ProcessWire;

/** Canonical date and date/time inputs; stored values remain unformatted strings. */
return [
	'starts_on' => [
		'type'             => 'datetime',
		'label'            => __('Start date'),
		'inputType'        => 'text',
		'datepicker'       => InputfieldDatetime::datepickerFocus,
		'dateInputFormat'  => 'd.m.Y',
		'dateOutputFormat' => 'd.m.Y',
	],
	'starts_at' => [
		'type'             => 'datetime',
		'label'            => __('Start date and time'),
		'inputType'        => 'text',
		'datepicker'       => InputfieldDatetime::datepickerFocus,
		'dateInputFormat'  => 'Y-m-d',
		'timeInputFormat'  => 'H:i',
		'dateOutputFormat' => 'd.m.Y H:i',
	],
];
