<?php namespace ProcessWire;

/**
 * Text controls, HTML escaping and optional language support.
 * Install InputfieldTinyMCE before using the text_body property.
 * TextformatterEntities escapes HTML text; an empty formatter list leaves text raw.
 */
return [
    'text_heading' => [
        'type' => 'text',
        'label' => __('Heading'),
        'required' => true,
        'columnWidth' => 50,
        'textformatters' => ['TextformatterEntities'],
    ],
    'text_plain' => [
        'type' => 'text',
        'label' => __('Plain text (no automatic HTML escaping)'),
        'columnWidth' => 50,
        'textformatters' => [],
    ],
    'text_summary' => [
        'type' => 'textarea',
        'label' => __('Summary'),
        'rows' => 4,
        'columnWidth' => 100,
        'textformatters' => ['TextformatterEntities'],
    ],
    'text_body' => [
        'type' => 'TinyMCE',
        'label' => __('Rich text'),
        'rows' => 8,
        'columnWidth' => 100,
        'textformatters' => [],
        'toolbar' => 'bold italic | bullist numlist | link',
    ],
    'text_email' => [
        'type' => 'email',
        'label' => __('Email address'),
        'columnWidth' => 50,
    ],
    'text_website' => [
        'type' => 'url',
        'label' => __('Website'),
        'columnWidth' => 50,
        'noRelative' => 1,
    ],
    'text_hidden' => [
        'type' => 'hidden',
        'value' => 'example-token',
    ],
    'text_icon' => [
        'type' => 'icon',
        'label' => __('Icon'),
        'columnWidth' => 50,
    ],
    'text_translation' => [
        'type' => 'text',
        'label' => __('Optional multilingual heading'),
        'useLanguages' => 'auto',
        'textformatters' => ['TextformatterEntities'],
        'columnWidth' => 50,
    ],
];
