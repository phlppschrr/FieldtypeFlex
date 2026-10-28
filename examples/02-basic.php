<?php namespace ProcessWire;

/** A required title and an email input, each occupying half the editor width. */
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
