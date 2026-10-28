<?php namespace ProcessWire;

/**
 * Field metadata API. Definitions are evaluated in the supplied page context.
 *
 * @property FieldtypeFlex $type Owning fieldtype and its public definition/storage APIs.
 */
class FlexField extends Field {
	/**
	 * Get this field’s definitions for a page or the NullPage query context.
	 *
	 * @param Page|null $page Owning page.
	 * @return FlexFieldDefs
	 */
	public function defs(?Page $page = null): FlexFieldDefs {
		return $this->type->getDefinitions($this, $page);
	}
}
