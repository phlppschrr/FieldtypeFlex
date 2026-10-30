<?php namespace ProcessWire;

/**
 * Two editor columns, a fixed link object, renames and inherited native settings.
 * Adapt rich_text_source (Textarea with TinyMCE) and date_input_source (Datetime)
 * to existing Fields in your site.
 */
return [
    'content_column' => [
        'type' => 'fieldset',
        'label' => 'Content',
        'skipLabel' => Inputfield::skipLabelHeader, // Group inputs without a visible fieldset heading.
        'columnWidth' => 50,
        'children' => [ // Fieldsets organize inputs; values stay flat, e.g. $value->heading.
            'heading' => [
                'type' => 'text',
                'label' => 'Heading',
                'renamedFrom' => 'previous_heading', // Read the old key; persist the new name on save.
                'textformatters' => ['TextformatterEntities'],
            ],
            'translation' => [
                'type' => 'textarea',
                'label' => 'Translated summary',
                'useLanguages' => true, // Requires LanguageSupport and LanguageSupportFields.
                'textformatters' => ['TextformatterEntities'],
                'rows' => 3,
            ],
            'body' => [
                'type' => 'TinyMCE',
                'label' => 'Rich text',
                'inheritField' => 'rich_text_source', // Reuse native settings and formatters, never stored values.
                'rows' => 12, // Local settings override the inherited configuration.
            ],
        ],
    ],
    'settings_column' => [
        'type' => 'fieldset',
        'label' => 'Settings',
        'skipLabel' => Inputfield::skipLabelHeader,
        'columnWidth' => 50, // Two fieldsets form independent editor columns.
        'children' => [
            'show_link' => ['type' => 'checkbox', 'label' => 'Add a link'],
            'link' => [
                'type' => 'object',
                'label' => 'Link',
                'showIf' => 'show_link=1', // This condition uses the containing scope, still flat inside the fieldset.
                'children' => [ // Objects introduce a value scope: $value->link->url and $value->link->label.
                    'url' => [
                        'type' => 'url',
                        'label' => 'Address',
                        'required' => true, // Native validation applies when the containing object is visible.
                    ],
                    'label' => [
                        'type' => 'text',
                        'label' => 'Label',
                        'required' => true,
                        'textformatters' => ['TextformatterEntities'],
                    ],
                ],
            ],
            'date' => [
                'type' => 'datetime',
                'label' => 'Start date',
                'inheritField' => 'date_input_source',
            ],
            'details' => [
                'type' => 'fieldset',
                'label' => 'Additional details',
                'children' => [
                    'note' => ['type' => 'text', 'label' => 'Note', 'columnWidth' => 50],
                    'rating' => ['type' => 'integer', 'label' => 'Rating', 'columnWidth' => 50],
                ],
            ],
        ],
    ],
    'help' => [
        'type' => 'markup',
        'label' => 'Editor guidance',
        'value' => '<p>Markup fields display a hint but do not store a value.</p>',
    ],
];
