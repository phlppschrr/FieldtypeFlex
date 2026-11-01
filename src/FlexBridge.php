<?php namespace ProcessWire;

// The optional component contract is intentionally independent of theme installation/order.
if(!interface_exists(__NAMESPACE__ . '\\BridgeComponent', false)) {
	interface BridgeComponent { public function getBridgeComponent(); }
}

/** Flex owns its behaviour; rendered child Inputfields declare their own legacy needs. */
trait FlexBridgeComponent {
	public function getBridgeComponent() {
		return ['tag' => '', 'version' => '1'];
	}
}
