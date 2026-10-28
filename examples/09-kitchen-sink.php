<?php namespace ProcessWire;

/**
 * Combine examples 03–08 at the root and reuse choice/number schemas inside Rows.
 * Keep the included PHP files beside this file and meet the prerequisites in
 * 07-advanced.php. The included schemas use distinct root property names.
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
