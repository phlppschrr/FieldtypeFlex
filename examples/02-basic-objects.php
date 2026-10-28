<?php namespace ProcessWire;

/** Alternative representation of 02-basic.php; InputfieldWrapper remains supported. */
$inputs = $wire->wire(new InputfieldWrapper());

$title = $wire->modules->get('InputfieldText');
$title->name = 'title';
$title->label = __('Title');
$title->required = true;
$title->columnWidth = 50;
$inputs->add($title);

$email = $wire->modules->get('InputfieldEmail');
$email->name = 'email';
$email->label = __('Email');
$email->columnWidth = 50;
$inputs->add($email);

return $inputs;
