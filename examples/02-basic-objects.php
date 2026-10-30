<?php namespace ProcessWire;

/** Build the same title/email schema as 02-basic.php with native Inputfield objects. */
$inputs = $wire->wire(new InputfieldWrapper());

$title = $wire->modules->get('InputfieldText');
$title->name = 'title';
$title->label = 'Title';
$title->required = true;
$title->columnWidth = 50;
$inputs->add($title);

$email = $wire->modules->get('InputfieldEmail');
$email->name = 'email';
$email->label = 'Email';
$email->columnWidth = 50;
$inputs->add($email);

return $inputs;
