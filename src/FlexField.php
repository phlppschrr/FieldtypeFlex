<?php namespace ProcessWire;

/**
 * Field metadata API. Definitions are evaluated in the supplied page context.
 *
 * #pw-summary Page-aware Flex field definitions and configuration.
 *
 * @property string $flexDefinition Inline JSON; empty uses file lookup.
 * @property string $flexDefinitionFile PHP/JSON path inside site/templates.
 * @property bool $flexHideWrap Hide the outer editor heading (default false).
 * @property int $flexMaxDepth Additional depth cap; 0 adds no cap (default 0).
 * @property int $flexMaxRows Total descendant row budget; 0 disables it (default 2000).
 * @property int $flexMaxBytes Canonical JSON byte budget; 0 disables it (default 8388608).
 * @property FieldtypeFlex $type Owning fieldtype and its public definition and maintenance APIs.
 */
class FlexField extends Field {
	/**
	 * Get this field’s definitions for a page or the NullPage query context.
	 *
	 * #pw-group-retrieval
	 *
	 * @param Page|null $page Owning page.
	 * @return FlexFieldDefs
	 */
	public function defs(?Page $page = null): FlexFieldDefs {
		return $this->type->getDefinitions($this, $page);
	}
}
