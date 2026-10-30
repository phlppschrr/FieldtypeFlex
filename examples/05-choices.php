<?php namespace ProcessWire;

/**
 * Single and multiple choice controls. Values contain option keys; optionLabel()
 * returns a display label. Keep stored option keys stable when changing display labels.
 */
return [
    'country' => [
        'type' => 'select',
        'label' => 'Country',
        'options' => [
            'DE' => 'Germany',
            'AT' => 'Austria',
        ],
        'columnWidth' => 50,
    ],
    'priority' => [
        'type' => 'radios',
        'label' => 'Priority',
        'options' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
        ],
        'columnWidth' => 50,
    ],
    'features' => [
        'type' => 'checkboxes',
        'label' => 'Features',
        'options' => [
            'design' => 'Design',
            'code' => 'Code',
            'docs' => 'Documentation',
        ],
        'columnWidth' => 50,
    ],
    'topics' => [
        'type' => 'SelectMultiple',
        'label' => 'Topics',
        'options' => ['php' => 'PHP', 'cms' => 'CMS', 'api' => 'API'],
        'columnWidth' => 50,
    ],
    'ordered' => [
        'type' => 'AsmSelect',
        'label' => 'Ordered choices',
        'options' => ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
        'columnWidth' => 50,
    ],
    'tags' => [
        'type' => 'TextTags',
        'label' => 'Tags',
        'options' => ['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'],
        'columnWidth' => 50,
    ],
];
