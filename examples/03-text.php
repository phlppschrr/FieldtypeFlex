<?php namespace ProcessWire;

/**
 * Text controls, HTML escaping and multilingual content.
 * TextformatterEntities escapes HTML text; an empty formatter list leaves text raw.
 */
return [
    'heading' => [
        'type' => 'text',
        'label' => 'Heading',
        'required' => true,
        'columnWidth' => 50,
        'textformatters' => ['TextformatterEntities'],
    ],
    'plain' => [
        'type' => 'text',
        'label' => 'Unformatted text',
        'columnWidth' => 50,
        'textformatters' => [],
    ],
    'summary' => [
        'type' => 'textarea',
        'label' => 'Summary',
        'rows' => 4,
        'columnWidth' => 100,
        'textformatters' => ['TextformatterEntities'],
    ],
    'body' => [
        'type' => 'TinyMCE',
        'label' => 'Rich text',
        'contentType' => FieldtypeTextarea::contentTypeHTML,
        'htmlOptions' => [FieldtypeTextarea::htmlLinkAbstract], // Keep internal links working when target Pages move.
        'rows' => 8,
        'columnWidth' => 100,
        'textformatters' => [],
        'toolbar' => 'bold italic | bullist numlist | link',
    ],
    'email' => [
        'type' => 'email',
        'label' => 'Email address',
        'columnWidth' => 50,
    ],
    'website' => [
        'type' => 'url',
        'label' => 'Website',
        'columnWidth' => 50,
        'noRelative' => 1,
    ],
    'hidden' => [
        'type' => 'hidden',
        'value' => 'example-token',
    ],
    'icon' => [
        'type' => 'icon',
        'label' => 'Icon',
        'columnWidth' => 50,
    ],
    'translation' => [
        'type' => 'text',
        'label' => 'Translated heading',
        'useLanguages' => true, // Requires LanguageSupport and LanguageSupportFields.
        'textformatters' => ['TextformatterEntities'],
        'columnWidth' => 50,
    ],
];
