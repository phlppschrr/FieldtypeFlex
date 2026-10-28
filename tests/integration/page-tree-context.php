<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Mirror Matrix's public Page contract without requiring proprietary ProFields in CI.
$owner = $wire->wire(new class extends Page {
	public array $activeFields = [];
	public array $reads = [];
	public function getFields() { return $this->activeFields; }
	public function getUnformatted($key) { $this->reads[] = $key; return null; }
});
$owner->template = $template;
$repeater = $wire->wire(new RepeaterField());
$repeater->name = 'other_type_repeater_' . bin2hex(random_bytes(3));
$repeater->type = $repeaterType;
$temporaryFields[] = $repeater;
$wire->fields->save($repeater);
$template->fieldgroup->add($repeater);
$event = $wire->wire(new HookEvent());
$event->arguments(0, $owner);
set_error_handler(static function($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
try {
	$type->validatePageTree($event);
	$same([], $owner->reads, 'page-tree validation reads only concrete owner getFields, excluding other Matrix types');
	$owner->activeFields = [$repeater];
	$type->validatePageTree($event);
	$same([$repeater->name], $owner->reads, 'page-tree validation safely skips an empty active Repeater');
} finally {
	restore_error_handler();
	$template->fieldgroup->remove($repeater);
}
