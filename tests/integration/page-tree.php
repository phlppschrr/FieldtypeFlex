<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$fieldsetType = $wire->modules->get('FieldtypeFieldsetPage');
$fieldsetType->getFieldClass();
$fieldset = $wire->wire(new FieldsetPageField());
$fieldset->name = 'flex_fieldset_' . bin2hex(random_bytes(3));
$fieldset->type = $fieldsetType;
$fieldset->repeaterFields = [$field->id];
$temporaryFields[] = $fieldset;
$wire->fields->save($fieldset);
$itemTemplate = $fieldsetType->_getRepeaterTemplate($fieldset);
$itemTemplate->fieldgroup->add($field);
$itemTemplate->fieldgroup->save();
$template->fieldgroup->add($fieldset);
$template->fieldgroup->save();
$page = $wire->pages->getFresh($id);
$page->of(false);
$child = $page->getUnformatted($fieldset->name);
$assert($child instanceof Page, 'native FieldsetPage returns a single Page');
$child->set($field->name, ['name' => 'Inside fieldset']);
$child->save();
$page->title = 'Full save with FieldsetPage';
$page->save();
$same('Inside fieldset', $wire->pages->getFresh($child->id)->getUnformatted($field->name)->name, 'full Page save traverses native FieldsetPage without iterating its scalar properties');
$child->set($field->name, ['name' => str_repeat('x', 500)]);
$field->flexMaxBytes = 100;
$event = $wire->wire(new HookEvent());
$event->arguments(0, $page);
$throws(fn() => $type->validatePageTree($event), 'nested FieldsetPage still enforces Flex byte budgets', WireException::class, 'byte budget');
$field->flexMaxBytes = 0;
