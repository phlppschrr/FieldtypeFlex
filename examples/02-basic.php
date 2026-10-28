<?php namespace ProcessWire;

/** Same two fields as 01-basic.json. PHP definitions are recommended. */
return [
    'title' => [
        'type' => 'text',
        'label' => __('Title'),
        'required' => true,
        'columnWidth' => 50,
    ],
    'email' => [
        'type' => 'email',
        'label' => __('Email'),
        'columnWidth' => 50,
    ],
];
