<?php namespace ProcessWire;

/**
 * Portable definition: multilingual when both language modules are installed.
 * Without support, previous language maps expose their default text; saving
 * the Flex field replaces those maps with strings. Save once with support
 * active before removing it, so Flex remembers the default-language ID.
 */
return [
	'heading' => [
		'type'         => 'text',
		'label'        => __('Heading'),
		'useLanguages' => 'auto',
	],
	'summary' => [
		'type'         => 'textarea',
		'label'        => __('Summary'),
		'useLanguages' => 'auto',
	],
];
