<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$bridgeField = $createField('bridge_component', [
	'text' => ['type' => 'text'],
	'images' => ['type' => 'image', 'customFields' => ['caption' => ['type' => 'text']]],
	'files' => ['type' => 'file', 'extensions' => 'txt'],
	'blocks' => ['type' => 'rows', 'types' => ['text' => ['children' => ['text' => ['type' => 'text']]]]],
]);
$bridgeInput = $bridgeField->getInputfield($page);
$bridgeInput->value = $page->getUnformatted($bridgeField->name);
$bridgeComponents = [$bridgeInput];
foreach($bridgeInput->inputs()->getAll() as $child) {
	if($child instanceof InputfieldFlexRows || $child instanceof InputfieldFlexFile || $child instanceof InputfieldFlexImage) $bridgeComponents[] = $child;
}
$same(4, count($bridgeComponents), 'Flex and each structural/media input declare the optional native contract');
foreach($bridgeComponents as $component) {
	$assert($component instanceof BridgeComponent, $component->className() . ' implements the optional component contract');
	$same(['tag' => '', 'version' => '1'], $component->getBridgeComponent(), $component->className() . ' uses native plain markup without an extra module loader');
	$method = new \ReflectionMethod($component, 'getBridgeComponent');
	$same(get_class($component), $method->getDeclaringClass()->getName(), $component->className() . ' declares its own native behaviour, without implicit subclass opt-in');
	if($component instanceof InputfieldFile) {
		$same(null, $component->wrapAttr('data-flex-media'), 'Media subclasses need no Flex-specific client initialization marker');
		if($wire->modules->isInstalled('AdminThemeBridge')) {
			$theme = $wire->modules->get('AdminThemeBridge');
			if(method_exists($theme, 'borrowedWidget')) {
				$widget = $component instanceof InputfieldImage ? 'InputfieldImage' : 'InputfieldFile';
				$same(['kind' => 'field', 'widget' => $widget], $theme->getNativeMap()[$component->className()], 'Flex registers each media subclass in the public native map');
				$same($widget, $theme->borrowedWidget($component), 'Bridge resolves the declared native media widget');
			}
		}
	}
}
