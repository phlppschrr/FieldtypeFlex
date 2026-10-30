<?php namespace ProcessWire;

/** Number/state controls and localized date/time editors, also usable as Row children. */
return [
    'quantity' => [
        'type' => 'integer',
        'label' => 'Quantity',
        'min' => 0,
        'columnWidth' => 33,
    ],
    'price' => [
        'type' => 'float',
        'label' => 'Price',
        'precision' => 2, // Round values to two decimal places.
        'columnWidth' => 33,
    ],
    'toggle' => [
        'type' => 'Toggle',
        'label' => 'Enabled state',
        'columnWidth' => 34,
    ],
    'confirmed' => [
        'type' => 'checkbox',
        'label' => 'Confirmed',
        'columnWidth' => 50,
    ],
    'status' => [
        'type' => 'Toggle',
        'label' => 'Publication status',
        'options' => [0 => 'Draft', 1 => 'Published', 2 => 'Archived'],
        'formatType' => 2, // Formatted reads return the label; storage keeps the integer key.
        'columnWidth' => 50,
    ],
    'date' => [
        'type' => 'datetime',
        'label' => 'Start date',
        'inputType' => 'text',
        'datepicker' => InputfieldDatetime::datepickerFocus,
        'dateInputFormat' => 'd.m.Y',
        'dateOutputFormat' => 'd.m.Y',
        'columnWidth' => 50,
    ],
    'datetime' => [
        'type' => 'datetime',
        'label' => 'Start date and time',
        'inputType' => 'text',
        'datepicker' => InputfieldDatetime::datepickerFocus,
        'dateInputFormat' => 'Y-m-d',
        'timeInputFormat' => 'H:i',
        'dateOutputFormat' => 'd.m.Y H:i',
        'columnWidth' => 50,
    ],
];
