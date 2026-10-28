<?php namespace ProcessWire;

/**
 * Complete configuration, composed from tested independent examples.
 * PHP array spread works with string keys on PHP 8.1+. Every root property
 * name is distinct; the Row types reuse smaller schemas in their own scopes.
 * Copy this file alongside the referenced numbered PHP examples.
 *
 * A native declarative "add file" schema directive is not currently supported.
 */
return [
    ...require __DIR__ . '/03-text.php',
    ...require __DIR__ . '/04-numbers-dates.php',
    ...require __DIR__ . '/05-choices.php',
    ...require __DIR__ . '/06-references-media.php',
    ...require __DIR__ . '/07-advanced.php',
    ...require __DIR__ . '/08-rows.php',

    'variants' => [
        'type' => 'rows',
        'label' => __('Reusable groups in Rows'),
        'types' => [
            'choices' => [
                'label' => __('Choices'),
                'rowTitle' => '{choice_country}',
                'children' => require __DIR__ . '/05-choices.php',
            ],
            'numbers' => [
                'label' => __('Numbers and states'),
                'rowTitle' => '{number_quantity}',
                'children' => require __DIR__ . '/04-numbers-dates.php',
            ],
        ],
    ],
];
