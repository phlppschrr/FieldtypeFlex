<?php namespace ProcessWire;

/** Native InputfieldForm dependency processing for conditional and required inputs. */
return [
	'has_link'  => [
		'type'  => 'checkbox',
		'label' => __('Add a link'),
	],
	'link'      => [
		'type'       => 'url',
		'label'      => __('URL'),
		'showIf'     => 'has_link=1',
		'requiredIf' => 'has_link=1',
	],
	'link_text' => [
		'type'   => 'text',
		'label'  => __('Link text'),
		'showIf' => 'has_link=1',
	],
];
