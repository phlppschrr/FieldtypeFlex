<?php namespace ProcessWire;

/** A row uses the existing typed value pipeline, with identity outside its leaf namespace. */
class FlexRow extends FlexValue {
	private string $flexRowId;
	private string $flexRowType;

	/**
	 * Hydrate a typed row after validating its stable identity.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param FlexFieldDefs $defs Schema used to hydrate the value.
	 * @param string $set Repeatable property name.
	 * @param string $id Stable row or asset identity.
	 * @param string $type Stable row type name.
	 * @param array $values
	 */
	public function __construct(Page $page, Field $field, FlexFieldDefs $defs, string $set, string $id, string $type, array $values = []) {
		if(!preg_match('/^[a-f0-9]{32}$/D', $id)) {
			throw new WireException(__('Invalid Flex row ID'));
		}
		if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) || $type === 'count') {
			throw new WireException(__('Invalid Flex row type'));
		}
		$this->flexRowId = $id;
		$this->flexRowType = $type;
		parent::__construct($page, $field, $defs, $values, "$set.$id");
	}

	/**
	 * Get the stable identity outside the row’s leaf property namespace.
	 *
	 * @return string
	 */
	public function rowId(): string {
		return $this->flexRowId;
	}

	/**
	 * Get the immutable type outside the row’s leaf property namespace.
	 *
	 * @return string
	 */
	public function rowType(): string {
		return $this->flexRowType;
	}

	/**
	 * Get the type label resolved when this row's definition was evaluated.
	 *
	 * @return string
	 */
	public function rowTypeLabel(): string {
		return $this->definitions()->rowTypeLabel();
	}

	/**
	 * Whether a property is defined in this row's own flat schema, even when empty.
	 *
	 * @param string $name Direct property name, not a nested schema path.
	 * @return bool
	 */
	public function hasProperty(string $name): bool {
		return array_key_exists($name, $this->definitions()->properties());
	}
}

/** Ordered, owned rows. Reading/formatting never allocates row identities. */
class FlexRows extends WireArray implements \JsonSerializable {
	private Page $flexPage;
	private Field $flexField;
	private array $definition;
	private string $set;
	private bool $formatted = false;
	private bool $hydrating = true;

	/**
	 * Hydrate ordered owned rows from a validated persistence envelope.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param string $set Repeatable property name.
	 * @param array $definition
	 * @param array|FlexRows|string|null $raw Unformatted persisted rows.
	 */
	public function __construct(Page $page, Field $field, string $set, array $definition, $raw = []) {
		parent::__construct();
		$page->wire($this);
		$this->flexPage = $page;
		$this->flexField = $field;
		$this->set = $set;
		$this->definition = $definition;
		if($raw instanceof self) {
			$copy = $raw;
			$raw = $copy->rawArray();
			foreach($raw['items'] as &$item) {
				$item['id'] = bin2hex(random_bytes(16));
			}
			unset($item);
		}
		if($raw === '' || $raw === null || $raw === []) {
			$raw = [
				'_flex' => FlexStorage::ROWS_FORMAT,
				'items' => [],
			];
		}
		if(
			!is_array($raw)
			|| ($raw['_flex'] ?? '') !== FlexStorage::ROWS_FORMAT
			|| !isset($raw['items'])
			|| !is_array($raw['items'])
			|| !array_is_list($raw['items'])
		) {
			throw new WireException(sprintf($this->_('Invalid repeatable value: %s'), $set));
		}
		foreach($raw['items'] as $item) {
			if(!is_array($item) || !isset($item['id'], $item['type'], $item['values']) || !is_string($item['id']) || !is_string($item['type']) || !is_array($item['values'])) {
				throw new WireException($this->_('Invalid repeatable row'));
			}
			$row = new FlexRow($page, $field, $this->rowSchema($item['type']), $set, $item['id'], $item['type'], $item['values']);
			if(isset($this->data[$row->rowId()])) {
				throw new WireException($this->_('Duplicate Flex row ID'));
			}
			parent::add($row);
		}
		$this->checkLimit();
		$this->hydrating = false;
		$this->resetTrackChanges();
		if(isset($copy)) {
			$sources = array_values(iterator_to_array($copy));
			foreach(array_values($this->data) as $n => $row) {
				$source = $sources[$n];
				$row->copyValuesFrom($source);
			}
		}
	}

	/**
	 * Accept only typed Flex rows in this collection.
	 *
	 * @param mixed $item
	 * @return bool
	 */
	public function isValidItem($item) {
		return $item instanceof FlexRow;
	}

	/**
	 * Whether this value belongs to the supplied page and field context.
	 *
	 * @param Page $page Owning page.
	 * @param Field $field Owning Flex field.
	 * @param string $set Repeatable property name.
	 * @return bool
	 * #pw-internal
	 * @internal
	 */
	public function belongsTo(Page $page, Field $field, string $set): bool {
		return $this->flexPage === $page && $this->flexField->id === $field->id && $this->set === $set;
	}

	/**
	 * Use a typed row’s stable identity as its collection key.
	 *
	 * @param mixed $item
	 * @return string|null
	 */
	public function getItemKey($item) {
		return $item instanceof FlexRow ? $item->rowId() : null;
	}

	/**
	 * Create an empty collection with the same owner and definition.
	 *
	 * @return FlexRows
	 */
	public function makeNew() {
		return new self($this->flexPage, $this->flexField, $this->set, $this->definition);
	}

	/**
	 * Create and append a new row with a server-generated identity.
	 *
	 * @param string $type Stable row type name.
	 * @param array $values
	 * @return FlexRow
	 */
	public function addRow(string $type, array $values = []): FlexRow {
		$this->writable();
		$max = (int) ($this->definition['settings']['maxItems'] ?? 0);
		if($max && count($this) >= $max) {
			throw new WireException($this->_('Maximum repeatable item count reached'));
		}
		$row = new FlexRow($this->flexPage, $this->flexField, $this->rowSchema($type), $this->set, bin2hex(random_bytes(16)), $type, $values);
		$this->add($row);
		return $row;
	}

	/**
	 * Add an item after validating its identity, owner and collection limits.
	 *
	 * @param mixed $item
	 * @return $this
	 */
	public function add($item) {
		$this->writable();
		if(!$item instanceof FlexRow || !$item->belongsTo($this->flexPage, $this->flexField) || $item->address() !== "$this->set.{$item->rowId()}") {
			throw new WireException($this->_('Use addRow() to create a row in this collection'));
		}
		if($item->definitions() !== $this->rowSchema($item->rowType())) {
			throw new WireException($this->_('Row schema does not belong to this repeatable type'));
		}
		$existing = $this->data[$item->rowId()] ?? null;
		if($existing && $existing !== $item) {
			throw new WireException($this->_('Duplicate Flex row identity'));
		}
		if(!$existing) {
			$max = (int) ($this->definition['settings']['maxItems'] ?? 0);
			if($max && count($this) >= $max) {
				throw new WireException($this->_('Maximum repeatable item count reached'));
			}
		}
		return parent::add($item);
	}

	/**
	 * Remove a row after checking output-formatting state.
	 *
	 * @param string|int $key
	 * @return $this
	 */
	public function remove($key) {
		$this->writable();
		return parent::remove($key);
	}

	/**
	 * Assign a value through the collection’s ownership and type checks.
	 *
	 * @param string|int $key
	 * @param mixed $value
	 * @return $this
	 */
	public function set($key, $value) {
		if(!$value instanceof FlexRow || $key !== $value->rowId()) {
			throw new WireException($this->_('A row key must match its identity'));
		}
		return $this->add($value);
	}

	/**
	 * Insert a valid owned row at the start of the collection.
	 *
	 * @param mixed $item
	 * @return $this
	 */
	public function prepend($item) {
		$this->add($item);
		$first = array_key_first($this->data);
		return $first === $item->rowId() ? $this : $this->moveRow($item->rowId(), $first);
	}

	/**
	 * Remove all rows after checking output-formatting state.
	 *
	 * @return $this
	 */
	public function removeAll() {
		$this->writable();
		return parent::removeAll();
	}

	/**
	 * Apply normal row mutation checks to quiet native removals.
	 *
	 * @param string|int $key
	 * @return $this
	 */
	public function removeQuietly($key) {
		$this->writable();
		return parent::removeQuietly($key);
	}

	/**
	 * Remove the row with the supplied stable identity.
	 *
	 * @param string $id Stable row or asset identity.
	 * @return self
	 */
	public function removeRow(string $id): self {
		$this->remove($id);
		return $this;
	}

	/**
	 * Find a row by its stable identity.
	 *
	 * @param string $id Stable row or asset identity.
	 * @return FlexRow|null
	 */
	public function getById(string $id): ?FlexRow {
		return $this->data[$id] ?? null;
	}

	/**
	 * Get direct rows of a type in collection order, retaining object identity.
	 * Unknown types return an empty list. Validate explicitly to avoid PHP scalar
	 * coercion for callers that do not enable strict_types.
	 *
	 * @param string $type Stable row type name.
	 * @return FlexRow[] Numerically indexed existing rows, not a new collection.
	 * @throws \TypeError When the type is not a string.
	 */
	public function rowsOfType($type): array {
		if(!is_string($type)) {
			throw new \TypeError('FlexRows::rowsOfType(): $type must be a string');
		}
		$rows = [];
		foreach($this as $row) {
			if($row->rowType() === $type) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Count direct rows of a type in memory, including unsaved changes.
	 *
	 * @param string $type Stable row type name; unknown types return zero.
	 * @return int
	 * @throws \TypeError When the type is not a string.
	 */
	public function countOfType($type): int {
		return count($this->rowsOfType($type));
	}

	/**
	 * Append an independent typed copy of a row, including its media.
	 *
	 * @param string $id Stable row or asset identity.
	 * @return FlexRow
	 */
	public function duplicateRow(string $id): FlexRow {
		$source = $this->getById($id);
		if(!$source) {
			throw new WireException($this->_('Unknown repeatable row'));
		}
		$row = $this->addRow($source->rowType());
		$row->copyValuesFrom($source);
		return $row;
	}

	/**
	 * Move a row before another identity, or to the end when omitted.
	 *
	 * @param string $id Stable row or asset identity.
	 * @param string|null $before Target row identity, or null to move to the end.
	 * @return self
	 */
	public function moveRow(string $id, ?string $before = null): self {
		$this->writable();
		if(!isset($this->data[$id]) || ($before !== null && !isset($this->data[$before]))) {
			throw new WireException($this->_('Unknown repeatable row'));
		}
		if($id === $before) {
			return $this;
		}
		$row = $this->data[$id];
		unset($this->data[$id]);
		$ordered = [];
		foreach($this->data as $key => $item) {
			if($key === $before) {
				$ordered[$id] = $row;
			}
			$ordered[$key] = $item;
		}
		if($before === null) {
			$ordered[$id] = $row;
		}
		$this->data = $ordered;
		$this->trackChange('sort');
		return $this;
	}

	/**
	 * Get or change output formatting and return its previous state.
	 *
	 * @param bool|null $formatted New formatting state, or null to read without changing it.
	 * @return bool
	 */
	public function of(?bool $formatted = null): bool {
		$before = $this->formatted;
		if($formatted !== null) {
			$this->formatted = $formatted;
			foreach($this as $row) {
				$row->of($formatted);
			}
		}
		return $before;
	}

	/**
	 * Track a nested change on the owning Flex field as well as this collection.
	 *
	 * @param string $what
	 * @param mixed $old
	 * @param mixed $new
	 * @return $this
	 */
	public function trackChange($what, $old = null, $new = null) {
		if(!$this->hydrating && isset($this->flexPage)) {
			$this->flexPage->trackChange($this->flexField->name);
		}
		return parent::trackChange($what, $old, $new);
	}

	/**
	 * Check this value and its nested native objects for changes.
	 *
	 * @param string $what
	 * @return bool
	 */
	public function isChanged($what = '') {
		if(parent::isChanged($what)) {
			return true;
		}
		foreach($this as $row) {
			if($row->isChanged()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reset change tracking on this value and its nested native objects.
	 *
	 * @param bool|int $trackChanges
	 * @return $this
	 */
	public function resetTrackChanges($trackChanges = true) {
		foreach($this as $row) {
			$row->resetTrackChanges($trackChanges);
		}
		return parent::resetTrackChanges($trackChanges);
	}

	/**
	 * Clone nested objects so subsequent edits remain independent.
	 *
	 * @return void
	 */
	public function __clone() {
		foreach($this->data as $key => $row) {
			$this->data[$key] = clone $row;
		}
	}

	/**
	 * Export unformatted values in the canonical persistence shape.
	 *
	 * @return array
	 */
	public function rawArray(): array {
		$items = [];
		foreach($this as $row) {
			$items[] = [
				'id'     => $row->rowId(),
				'type'   => $row->rowType(),
				'values' => $row->rawArray(),
			];
		}
		return [
			'_flex' => FlexStorage::ROWS_FORMAT,
			'items' => $items,
		];
	}

	/**
	 * Export canonical unformatted values for JSON serialization.
	 *
	 * @return array
	 */
	public function jsonSerialize(): array {
		return $this->rawArray();
	}

	/**
	 * Check the maximum row count and optionally enforce the minimum.
	 *
	 * @param bool $minimum Also enforce the minimum row count.
	 * @return void
	 * @throws WireException
	 * #pw-internal
	 * @internal
	 */
	public function checkLimit(bool $minimum = false): void {
		$min = (int) ($this->definition['settings']['minItems'] ?? 0);
		$max = (int) ($this->definition['settings']['maxItems'] ?? 0);
		if(($minimum && count($this) < $min) || ($max && count($this) > $max)) {
			throw new WireException(sprintf($this->_("Repeatable '%s' item count is outside its limits"), $this->set));
		}
	}

	private function rowSchema(string $type): FlexFieldDefs {
		if(!isset($this->definition['types'][$type])) {
			throw new FlexDefinitionException($this->flexField->name, sprintf($this->_('Unknown Flex row type: %1$s.%2$s'), $this->set, $type), FlexFieldDefs::inspectSource($this->flexField, $this->wire()));
		}
		return $this->definition['types'][$type]['schema'];
	}

	private function writable(): void {
		if(!$this->hydrating && ($this->formatted || $this->flexPage->of())) {
			throw new WireException($this->_('Disable output formatting before changing repeatable rows'));
		}
	}
}
