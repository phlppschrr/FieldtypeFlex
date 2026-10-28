<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Included by the integration runner, sharing its guarded disposable fixture and cleanup registry.
if(PHP_SAPI !== 'cli' || !isset($same, $page, $temporaryFields)) {
	http_response_code(404);
	exit;
}
// Keep this isolated Repeater fixture minimal; the public example catalog focuses on definitions.
$rich = $createField('flex_rich', [
    'heading' => ['type' => 'Text', 'label' => 'Item heading'],
    'body' => [
        'type' => 'TinyMCE',
        'height' => 180,
        'lazyMode' => 1,
        'toolbar' => 'bold italic link code',
        'menubar' => false,
    ],
    'details' => [
        'type' => 'Fieldset',
        'children' => [
            'translation' => [
                'type' => 'TinyMCE',
                'useLanguages' => true,
                'height' => 180,
                'lazyMode' => 1,
                'toolbar' => 'bold italic code',
                'menubar' => false,
            ],
        ],
    ],
]);
// Preload must reach both editors (including the one nested in a fieldset) without rendering HTML.
$ready = [];
$hook = $wire->addHookAfter('InputfieldTinyMCE::renderReadyHook', static function(HookEvent $event) use (&$ready) {
	$ready[] = $event->object->name;
});
$preload = $rich->getInputfield($page);
$preload->value = $page->get($rich->name);
$preload->renderReady();
$wire->removeHook($hook);
$same([$rich->name . '__body', $rich->name . '__translation'], $ready, 'Flex preloads nested TinyMCE assets without rendering');
$assert(str_contains(implode('\n', iterator_to_array($wire->config->scripts)), 'InputfieldTinyMCE.js'), 'TinyMCE script registered during Flex preload');
$repeaters = [];
foreach(['A', 'B'] as $label) {
	$rep = $wire->wire(new RepeaterField());
	$rep->name = 'flex_multi_' . strtolower($label) . '_' . bin2hex(random_bytes(3));
	$rep->type = $repeaterType;
	$rep->repeaterFields = [$rich->id];
	$rep->repeaterCollapse = FieldtypeRepeater::collapseNone;
	$wire->fields->save($rep);
	$temporaryFields[] = $rep;
	$repeaters[] = $rep;
	$itemTemplate = $repeaterType->_getRepeaterTemplate($rep);
	if(!$itemTemplate->fieldgroup->hasField($rich)) {
		$itemTemplate->fieldgroup->add($rich);
		$itemTemplate->fieldgroup->save();
	}
	$template->fieldgroup->add($rep);
	$template->fieldgroup->save();
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	foreach([1, 2] as $n) {
		$item = $page->get($rep->name)->getNewItem();
		$item->of(false);
		$item->set($rich->name, [
			'heading'     => "$label$n",
			'body'        => "<p>$label$n body</p>",
			'translation' => [
				'default' => "<p>$label$n English</p>",
				'de'      => "<p>$label$n Deutsch</p>",
			],
		]);
		$item->save();
	}
	$page->save($rep->name);
}
$page = $wire->pages->getFresh($id);
$page->of(false);
$form = $wire->modules->get('InputfieldForm');
$form->protectCSRF = false;
$post = [];
$expected = [];
$inputNames = [];
foreach($repeaters as $index => $rep) {
	$input = $rep->getInputfield($page);
	$input->value = $page->get($rep->name);
	$form->add($input);
	foreach($page->get($rep->name) as $position => $item) {
		$prefix = $rich->name . '_repeater' . $item->id . '__';
		$marker = 'saved-' . $index . '-' . $position;
		$post['loaded_repeater' . $item->id] = 1;
		$post['sort_repeater' . $item->id] = 1 - $position; // reverse both Repeaters during the same POST
		$post[$prefix . 'heading'] = $marker;
		$post[$prefix . 'body'] = "<p>$marker body</p>";
		$post[$prefix . 'translation'] = "<p>$marker English</p>";
		$post[$prefix . 'translation__' . $language->id] = "<p>$marker Deutsch</p>";
		$expected[$item->id] = [
			$marker,
			$post[$prefix . 'body'],
			$post[$prefix . 'translation'],
			$post[$prefix . 'translation__' . $language->id],
		];
		foreach(['heading', 'body', 'translation', 'translation__' . $language->id] as $property) {
			$inputNames[] = $prefix . $property;
		}
	}
}
$html = $form->render();
foreach($inputNames as $name) {
	$assert(str_contains($html, 'name="' . $name . '"'), 'native Repeater renders scoped input ' . $name);
}
preg_match_all('/\sid=[\'"]([^\'"]+)[\'"]/', $html, $matches);
$same(count($matches[1]), count(array_unique($matches[1])), 'two native Repeaters with rich text have unique HTML IDs');
$form->processInput($wire->wire(new WireInputData($post)));
$same([], $form->getErrors(), 'native Repeater processes all Flex rich text inputs without errors');
foreach($repeaters as $rep) {
	$page->save($rep->name);
}
$page = $wire->pages->getFresh($id);
$page->of(false);
foreach($repeaters as $rep) {
	$items = $page->get($rep->name);
	$same(2, count($items), 'native multi-Repeater POST keeps item count');
	foreach($items as $item) {
		$raw = $item->get($rich->name)->rawArray();
		$same(
			$expected[$item->id],
			[
				$raw['heading'],
				$raw['body'],
				$raw['translation'][(string) $wire->languages->getDefault()->id],
				$raw['translation'][(string) $language->id],
			],
			'native multi-Repeater POST persists isolated TinyMCE and translations for item ' . $item->id
		);
	}
	$assert(str_ends_with($items->first()->get($rich->name)->heading, '-1'), 'native Repeater reordering preserves Flex item identity');
}
