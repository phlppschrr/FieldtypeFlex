<?php namespace ProcessWire;

/**
 * Optional native integrations and schema evolution.
 * Requires a native TinyMCE Textarea named rich_text_source and a Datetime field
 * named date_input_source. Copy all fields into the target ProcessWire site first.
 */
return [
    'advanced_enabled' => [
        'type' => 'checkbox',
        'label' => __('Show link'),
        'columnWidth' => 50,
    ],
    'advanced_link' => [
        'type' => 'url',
        'label' => __('Link URL'),
        'showIf' => 'advanced_enabled=1',
        'requiredIf' => 'advanced_enabled=1',
        'columnWidth' => 50,
    ],
    'advanced_title' => [
        'type' => 'text',
        'label' => __('Renamed heading'),
        'renamedFrom' => 'previous_advanced_title',
        'textformatters' => ['TextformatterEntities'],
        'columnWidth' => 50,
    ],
    'advanced_translation' => [
        'type' => 'textarea',
        'label' => __('Optional translation'),
        'useLanguages' => 'auto',
        'textformatters' => ['TextformatterEntities'],
        'columnWidth' => 50,
    ],
    'advanced_group' => [
        'type' => 'fieldset',
        'label' => __('Additional details'),
        'children' => [
            'advanced_note' => [
                'type' => 'text',
                'label' => __('Note'),
                'columnWidth' => 50,
            ],
            'advanced_rating' => [
                'type' => 'integer',
                'label' => __('Rating'),
                'columnWidth' => 50,
            ],
        ],
    ],
    'advanced_help' => [
        'type' => 'markup',
        'label' => __('Editor guidance'),
        'value' => '<p>Markup fields display a hint but do not store a value.</p>',
    ],
    'advanced_date' => [
        'type' => 'datetime',
        'label' => __('Inherited date control'),
        'inheritField' => 'date_input_source',
        'columnWidth' => 50,
    ],
    'advanced_body' => [
        'type' => 'TinyMCE',
        'label' => __('Inherited rich text'),
        'inheritField' => 'rich_text_source',
        'rows' => 12,
        'columnWidth' => 50,
    ],
];
