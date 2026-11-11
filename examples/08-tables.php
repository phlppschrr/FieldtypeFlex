<?php namespace ProcessWire;

// Supported cells: Text, Email, URL, Integer, Float, plain Textarea, Checkbox,
// Toggle, Select, Radios, Checkboxes, SelectMultiple, AsmSelect, TextTags and Datetime.
// Page references use Select, SelectMultiple, AsmSelect or PageAutocomplete.

return [
    // Fixed headings use the column labels.
    'prices' => [
        'type' => 'table',
        'label' => 'Price list',
        'columns' => [
            'service' => ['type' => 'text', 'label' => 'Service'],
            'price' => ['type' => 'float', 'label' => 'Price', 'width' => '7rem'],
        ],
    ],
    // Editors can rename the headings and label each row.
    'comparison' => [
        'type' => 'table',
        'label' => 'Product comparison',
        'editableHeaders' => true,
        'rowLabels' => true,
        'columns' => [
            'standard' => ['type' => 'text', 'label' => 'Standard'],
            'premium' => ['type' => 'text', 'label' => 'Premium'],
        ],
    ],
];
