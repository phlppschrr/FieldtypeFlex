<?php namespace ProcessWire;

/** Number/state controls and localized date/time editors, also usable as Row children. */
return [
    'number_quantity' => [
        'type' => 'integer',
        'label' => __('Quantity'),
        'min' => 0,
        'columnWidth' => 33,
    ],
    'number_price' => [
        'type' => 'float',
        'label' => __('Price'),
        'precision' => 2,
        'columnWidth' => 33,
    ],
    'number_toggle' => [
        'type' => 'Toggle',
        'label' => __('Enabled state'),
        'columnWidth' => 34,
    ],
    'number_confirmed' => [
        'type' => 'checkbox',
        'label' => __('Confirmed'),
        'columnWidth' => 50,
    ],
    'number_date' => [
        'type' => 'datetime',
        'label' => __('Start date'),
        'inputType' => 'text',
        'datepicker' => InputfieldDatetime::datepickerFocus,
        'dateInputFormat' => 'd.m.Y',
        'dateOutputFormat' => 'd.m.Y',
        'columnWidth' => 50,
    ],
    'number_datetime' => [
        'type' => 'datetime',
        'label' => __('Start date and time'),
        'inputType' => 'text',
        'datepicker' => InputfieldDatetime::datepickerFocus,
        'dateInputFormat' => 'Y-m-d',
        'timeInputFormat' => 'H:i',
        'dateOutputFormat' => 'd.m.Y H:i',
        'columnWidth' => 50,
    ],
];
