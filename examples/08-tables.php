<?php namespace ProcessWire;

// Direct cells: Text, Email, URL, Integer, Float, plain Textarea, Checkbox,
// Toggle, Select, Radios, Checkboxes, SelectMultiple, AsmSelect, TextTags and Datetime.
// Direct Page widgets: Select, SelectMultiple, AsmSelect, PageAutocomplete,
// Checkboxes, Radios and TextTags.
// Detail row: File, Image, PageListSelect, PageListSelectMultiple and TinyMCE.

return [
    // Fixed headings use the column labels. Use 'price' for ascending order.
    'prices' => [
        'type' => 'table',
        'label' => 'Price list',
        'sortOnSave' => '-price',
        'columns' => [
            'service' => ['type' => 'text', 'label' => 'Service'],
            'price' => ['type' => 'float', 'label' => 'Price', 'width' => '7rem'],
            'brochure' => ['type' => 'file', 'label' => 'Brochure', 'extensions' => 'pdf', 'maxFiles' => 1],
        ],
    ],
    // Editors can rename the headings, label rows and sort them manually.
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
