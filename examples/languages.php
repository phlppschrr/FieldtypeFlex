<?php namespace ProcessWire;

/** Requires the native LanguageSupport and LanguageSupportFields modules. */
return [
	'title'   => [
		'type'         => 'text',
		'label'        => __('Title'),
		'useLanguages' => true,
	],
	'summary' => [
		'type'         => 'textarea',
		'label'        => __('Summary'),
		'useLanguages' => true,
	],
];
