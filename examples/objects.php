<?php namespace ProcessWire;

/**
 * The same contact form built from native Inputfield objects for IDE completion.
 * @var Page $page
 * @var Field $field
 * @var ProcessWire $wire
 */
$countryOptions = require __DIR__.'/options.php';

$inputs = $wire->wire(new InputfieldWrapper());

$firstName           = $wire->modules->get('InputfieldText');
$firstName->name     = 'first_name';
$firstName->label    = __('First name');
$firstName->required = true;
$inputs->add($firstName);

$email        = $wire->modules->get('InputfieldEmail');
$email->name  = 'email';
$email->label = __('Email');
$inputs->add($email);

$address        = $wire->modules->get('InputfieldFieldset');
$address->name  = 'address';
$address->label = __('Address');

$city        = $wire->modules->get('InputfieldText');
$city->name  = 'city';
$city->label = __('City');
$address->add($city);

$country        = $wire->modules->get('InputfieldSelect');
$country->name  = 'country';
$country->label = __('Country');
$country->addOptions($countryOptions);
$address->add($country);

$inputs->add($address);

return $inputs;
