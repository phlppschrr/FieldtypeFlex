<?php namespace ProcessWire;

/**
 * Choice values are raw keys; use optionLabel() for formatted display labels.
 * The same stable keys work in PHP, translated labels and nested Rows.
 */
return [
    'choice_country' => [
        'type' => 'select',
        'label' => __('Country'),
        'options' => [
            'DE' => __('Germany'),
            'AT' => __('Austria'),
        ],
        'columnWidth' => 50,
    ],
    'choice_priority' => [
        'type' => 'radios',
        'label' => __('Priority'),
        'options' => [
            'low' => __('Low'),
            'medium' => __('Medium'),
            'high' => __('High'),
        ],
        'columnWidth' => 50,
    ],
    'choice_features' => [
        'type' => 'checkboxes',
        'label' => __('Features'),
        'options' => [
            'design' => __('Design'),
            'code' => __('Code'),
            'docs' => __('Documentation'),
        ],
        'columnWidth' => 50,
    ],
    'choice_topics' => [
        'type' => 'SelectMultiple',
        'label' => __('Topics'),
        'options' => ['php' => 'PHP', 'cms' => 'CMS', 'api' => 'API'],
        'columnWidth' => 50,
    ],
    'choice_ordered' => [
        'type' => 'AsmSelect',
        'label' => __('Ordered choices'),
        'options' => ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'],
        'columnWidth' => 50,
    ],
    'choice_tags' => [
        'type' => 'TextTags',
        'label' => __('Tags'),
        'options' => ['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'],
        'columnWidth' => 50,
    ],
];
