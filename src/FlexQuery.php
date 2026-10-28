<?php namespace ProcessWire;

/** MySQL/MariaDB JSON selector adapter. Names and operators are validated, values are bound. */
class FlexQuery extends Wire {
	private FieldtypeFlex $type;
	private int $rowAlias = 0;
	private const TEXT_OPERATORS = ['=', '!=', '*=', '%=', '^=', '$=', '%^=', '%$=', '~=', '~|='];
	private const NUMBER_OPERATORS = ['=', '!=', '>', '>=', '<', '<='];
	private const PAGE_TARGET_TYPES = [
		'FieldtypeText',
		'FieldtypeTextLanguage',
		'FieldtypeTextarea',
		'FieldtypeTextareaLanguage',
		'FieldtypePageTitle',
		'FieldtypePageTitleLanguage',
		'FieldtypeEmail',
		'FieldtypeURL',
		'FieldtypeInteger',
		'FieldtypeFloat',
		'FieldtypeCheckbox',
		'FieldtypeToggle',
		'FieldtypeDatetime',
		'FieldtypeOptions',
		'FieldtypePage',
	];

	/**
	 * Initialize the object with its owning ProcessWire context.
	 *
	 * @param FieldtypeFlex $type Owning fieldtype service.
	 */
	public function __construct(FieldtypeFlex $type) {
		parent::__construct();
		$type->wire($this);
		$this->type = $type;
	}

	private function property(Field $field, string $name): array {
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
			throw new PageFinderSyntaxException($this->_('Invalid Flex selector property'));
		}
		$def = $this->type->getDefinitions($field)->property($name);
		if(!$def) {
			throw new PageFinderSyntaxException(sprintf($this->_('Unknown Flex selector property: %1$s.%2$s. Page-dependent schemas must expose queryable properties without a page context.'), $field->name, $name));
		}
		return $def;
	}

	private function expression(Field $field, string $table, string $name, array $def): array {
		$table = $this->wire()->database->escapeTable($table);
		$path = '$."' . $name . '"';
		if($def['languages']) {
			$language = $this->wire()->user->language ?: $this->wire()->languages->getDefault();
			$path .= '."' . $language->id . '"';
		}
		$json = "JSON_EXTRACT(`$table`.`data`, " . $this->wire()->database->quote($path) . ')';
		$text = "COALESCE(NULLIF(JSON_UNQUOTE($json), 'null'), '')";
		if($def['languages'] && !$language->isDefault() && !(int) $def['input']->langBlankInherit) {
			$defaultPath = '$."' . $name . '"."' . $this->wire()->languages->getDefault()->id . '"';
			$fallback = "JSON_UNQUOTE(JSON_EXTRACT(`$table`.`data`, " . $this->wire()->database->quote($defaultPath) . '))';
			$text = "COALESCE(NULLIF($text, ''), $fallback, '')";
		}
		$typed = $text;
		if(in_array($def['kind'], ['integer', 'float'], true)) {
			$typed = "CAST(NULLIF($text, '') AS DECIMAL(65, 15))";
		} else if($def['kind'] === 'date') {
			$typed = "NULLIF($text, '')";
		} else if($def['kind'] === 'page' && !$def['multiple']) {
			$typed = "CAST($text AS UNSIGNED)";
		}
		return [$json, $text, $typed];
	}

	/**
	 * Add a validated scalar, media or repeatable selector predicate to the query.
	 *
	 * @param DatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param string $operator
	 * @param mixed $value
	 * @param bool $inner
	 * @return DatabaseQuerySelect
	 */
	public function match($query, string $table, string $subfield, string $operator, $value, bool $inner = false) {
		$field = $query->field;
		if(!$inner) {
			$subfield = $this->selectorPath($query, $subfield);
		}
		$root = explode('.', $subfield, 2)[0];
		$rootDef = $subfield === 'data' ? null : $this->property($field, $root);
		if($rootDef && $rootDef['kind'] === 'page' && str_contains($subfield, '.')) {
			return $this->matchPage($query, $subfield, $operator, $value, $rootDef);
		}
		if($rootDef && $rootDef['kind'] === 'rows') {
			return $this->matchRows($query, $subfield, $operator, $value, $rootDef);
		}
		if($rootDef && in_array($rootDef['kind'], ['file', 'image'], true)) {
			$parts = explode('.', $subfield);
			$count = $this->mediaCount($field, $parts[0]);
			if(count($parts) === 1 && $value === '' && in_array($operator, ['=', '!='], true)) {
				$query->where("$count" . ($operator === '=' ? '=0' : '>0'));
			} else if($parts === [$parts[0], 'count'] && is_numeric($value)) {
				$query->where($this->compare($query, $count, $operator, 0 + $value, self::NUMBER_OPERATORS));
			} else {
				throw new PageFinderSyntaxException($this->_('Use a Flex media count or blank selector'));
			}
			$query->set('joinType', 'leftjoin');
			return $query;
		}
		// PageFinder repeats predicates in a LEFT JOIN's ON and WHERE clauses.
		// A null-tolerant JSON predicate there would accidentally match every row
		// whose join was filtered out. Correlated predicates retain actual presence.
		if(!$inner && ($operator === '!=' || ($operator === '=' && $value === ''))) {
			$child = $this->wire(new DatabaseQuerySelect());
			$child->set('field', $field);
			$child->bindOption('global', true);
			$blank = $value === '';
			$this->match($child, 'flex_match', $subfield, $blank ? '!=' : '=', $value, true);
			$actualTable = $this->wire()->database->escapeTable($field->table);
			$predicate = '(' . implode(' AND ', $child->where) . ')';
			$negate = ($operator === '=' && $blank) || ($operator === '!=' && !$blank);
			$query->where(($negate ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM `$actualTable` AS `flex_match` WHERE flex_match.pages_id=pages.id AND $predicate)");
			$child->copyBindValuesTo($query);
			$query->set('joinType', 'leftjoin');
			return $query;
		}
		if($subfield === 'data') {
			$table = $this->wire()->database->escapeTable($table);
			$expression = "COALESCE(`$table`.`search`, '')";
			if($value === '' && $operator === '=') {
				$query->set('joinType', 'leftjoin');
			}
			$query->where($this->compare($query, $expression, $operator, (string) $value, self::TEXT_OPERATORS));
			return $query;
		}
		$def = $this->property($field, $subfield);
		[$json, $text, $typed] = $this->expression($field, $table, $subfield, $def);
		if($value === '' && $operator === '=') {
			$query->set('joinType', 'leftjoin');
		}
		$query->where($this->scalarPredicate($query, $json, $text, $typed, $def, $operator, $value));
		return $query;
	}

	/** Only terminal native Fields: no aliases, metadata columns or recursive traversal. */
	private function pageTarget(string $name): ?Field {
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
			return null;
		}
		$field = $this->wire()->fields->get($name);
		return $field && $field->type && in_array($field->type->className(), self::PAGE_TARGET_TYPES, true) ? $field : null;
	}

	/** Discover terminal target Fields without inspecting selected pages or recursing. */
	private function pageTargetInfo(array $def, array &$cache): array {
		$input = $def['input'];
		$templateIds = FieldtypePage::getTemplateIDs([$input->template_id, $input->template_ids]);
		$result = [];
		foreach($this->wire()->fields as $candidate) {
			if(!$this->pageTarget($candidate->name)) {
				continue;
			}
			if($templateIds) {
				$allowed = false;
				foreach($templateIds as $id) {
					$template = $this->wire()->templates->get($id);
					if($template && $template->fieldgroup->has($candidate)) {
						$allowed = true;
					}
				}
				if(!$allowed) {
					continue;
				}
			}
			if(!isset($cache[$candidate->id])) {
				$info = $candidate->type->getSelectorInfo($candidate, ['level' => 1]);
				$info['subfields'] = [];
				$info['operators'] = array_values(array_filter($info['operators'], static fn($op) => !str_starts_with($op, '!') || in_array($op, ['!=', '!=""'], true)));
				if($candidate->type->className() === 'FieldtypePage') {
					// This is an ID comparison, not another virtual Page picker/dereference.
					$info['input'] = 'number';
					$info['operators'] = self::NUMBER_OPERATORS;
					$info['options'] = [];
				}
				$cache[$candidate->id] = $info;
			}
			$result[$candidate->name] = $cache[$candidate->id];
		}
		return $result;
	}

	/** Delegate target semantics to PageFinder, retaining native custom-subfield inclusion. */
	private function matchPage($query, string $path, string $operator, $value, array $def) {
		[$property, $target] = explode('.', $path, 2);
		if(!$this->pageTarget($target)) {
			throw new PageFinderSyntaxException($this->_('Unsupported Flex Page-reference target field'));
		}
		if($query->group !== null || ($query->selector instanceof Selector && $query->selector->not)) {
			throw new PageFinderSyntaxException($this->_('Flex Page-reference traversal does not support selector negation or grouping'));
		}
		$input = $def['input'];
		$pf = $query->pageFinder;
		// Mirror FieldtypePage::getMatchQueryCustom(), including its include=all default.
		$include = !$pf ? 'hidden' : (empty($pf->getOptions()['findAll']) ? 'all' : ($pf->includeMode ?: 'hidden'));
		$configured = (string) ($input->findPagesSelector ?: $input->findPagesSelect);
		if(str_contains($configured, 'page.')) {
			$configured = preg_replace('/\bpage\.[a-zA-Z0-9_]+\s*[=!]+\s*[^,]*,?/', '', $configured);
			$configured = preg_replace('/[a-zA-Z0-9_.]+\s*[=!]+\s*page\.[a-zA-Z0-9_.]+\s*,?/', '', $configured);
		}
		$selectors = $this->wire(new Selectors(str_contains($configured, 'include=') ? '' : "include=$include"));
		if($pf && $pf->checkAccess === false) {
			$selectors->add($selectors->create('check_access', '=', 0));
		}
		if(trim($configured, ', ') !== '') {
			$selectors->import(trim($configured, ', '));
		}
		if($input->parent_id) {
			$selectors->add($selectors->create('parent_id', '=', (int) $input->parent_id));
		}
		$templateIds = FieldtypePage::getTemplateIDs([$input->template_id, $input->template_ids]);
		if($templateIds) {
			$selectors->add($selectors->create('templates_id', '=', $templateIds));
		}
		$selectors->add($selectors->create($target, $operator === '!=' ? '=' : $operator, $value));
		$finder = $this->wire(new PageFinder());
		$matches = $finder->find($selectors, ['getTotal' => false, 'returnVerbose' => false]);
		// These are database IDs, not selector text. Integer literals avoid a bind per ID,
		// as native FieldtypePage does, and there is no editor-option limit on matching.
		$ids = implode(',', array_map('intval', $matches));
		$predicate = '1=0';
		if($ids !== '') {
			[$json, , $typed] = $this->expression($query->field, 'flex_reference', $property, $def);
			$member = $def['multiple']
				? "EXISTS (SELECT 1 FROM JSON_TABLE(COALESCE($json, '[]'), '$[*]' COLUMNS(id INT UNSIGNED PATH '$')) AS flex_reference_id WHERE flex_reference_id.id IN($ids))"
				: "$typed IN($ids)";
			$table = $this->wire()->database->escapeTable($query->field->table);
			$predicate = "EXISTS (SELECT 1 FROM `$table` AS flex_reference WHERE flex_reference.pages_id=pages.id AND $member)";
		}
		$query->where(($operator === '!=' ? 'NOT ' : '') . "($predicate)");
		$query->set('joinType', 'leftjoin');
		return $query;
	}

	private function scalarPredicate($query, string $json, string $text, string $typed, array $def, string $operator, $value): string {
		if($value === '' && in_array($operator, ['=', '!='], true)) {
			$empty = $def['multiple'] ? "($json IS NULL OR JSON_LENGTH($json)=0)" : "($text='')";
			if($def['kind'] === 'page' && !$def['multiple']) {
				$empty = "($text='' OR $text='0')";
			}
			return $operator === '=' ? $empty : "NOT $empty";
		}
		if($def['kind'] === 'date') {
			$timestamp = strtotime((string) $value);
			if($timestamp === false) {
				throw new PageFinderSyntaxException($this->_('Invalid Flex date selector'));
			}
			$value = date(preg_match('/\d{2}:\d{2}/', (string) $value) ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);
		}
		if($def['multiple']) {
			if(!in_array($operator, ['=', '!='], true)) {
				throw new PageFinderSyntaxException($this->_('Multi-selection Flex properties support = and != membership'));
			}
			$value = $def['kind'] === 'page' ? (int) $value : (string) $value;
			$key = $query->bindValueGetKey(json_encode($value, JSON_THROW_ON_ERROR));
			$contains = "COALESCE(JSON_CONTAINS($json, $key), 0)";
			return $operator === '=' ? "$contains=1" : "$contains=0";
		} else {
			$numeric = in_array($def['kind'], ['integer', 'float', 'page'], true);
			if($numeric && !is_numeric($value)) {
				throw new PageFinderSyntaxException($this->_('Numeric Flex selectors require a number'));
			}
			return $this->compare(
				$query,
				$typed,
				$operator,
				$numeric ? 0 + $value : (string) $value,
				$numeric || $def['kind'] === 'date' ? self::NUMBER_OPERATORS : self::TEXT_OPERATORS
			);
		}
	}

	private function selectorPath($query, string $subfield): string {
		if(!$query->selector instanceof Selector) {
			return $subfield;
		}
		$paths = [];
		foreach($query->selector->fields() as $fieldName) {
			$prefix = $query->field->name . '.';
			if(!str_starts_with($fieldName, $prefix)) {
				continue;
			}
			$path = substr($fieldName, strlen($prefix));
			if(explode('.', $path, 2)[0] === $subfield) {
				$paths[$path] = true;
			}
		}
		if(count($paths) > 1) {
			throw new PageFinderSyntaxException($this->_('OR between nested Flex paths is not supported'));
		}
		$path = $paths ? array_key_first($paths) : $subfield;
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $path)) {
			throw new PageFinderSyntaxException($this->_('Invalid Flex selector path'));
		}
		return $path;
	}

	private function rowScope($query, Field $field, string $set): string {
		$fieldKey = $query->bindValueGetKey((int) $field->id);
		$setKey = $query->bindValueGetKey(hash('sha256', $set));
		return "flex_row.pages_id=pages.id AND flex_row.fields_id=$fieldKey AND flex_row.set_key=$setKey";
	}

	private function rowLeaf($query, string $name, array $schema, string $operator, $value, string $alias = 'flex_row'): string {
		$parts = explode('.', $name);
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $parts[0]) || !isset($schema[$parts[0]])) {
			throw new PageFinderSyntaxException(sprintf($this->_('Unknown row property: %s'), $name));
		}
		$def = $schema[$parts[0]];
		if(in_array($def['kind'], ['file', 'image'], true)) {
			$path = $this->wire()->database->quote('$."' . $parts[0] . '"."items"');
			$count = "COALESCE(JSON_LENGTH(JSON_EXTRACT($alias.data,$path)),0)";
			if(count($parts) === 1 && $value === '' && in_array($operator, ['=', '!='], true)) {
				return "$count" . ($operator === '=' ? '=0' : '>0');
			}
			if($parts !== [$parts[0], 'count'] || !is_numeric($value)) {
				throw new PageFinderSyntaxException($this->_('Use a row media count or blank selector'));
			}
			return $this->compare($query, $count, $operator, 0 + $value, self::NUMBER_OPERATORS);
		}
		if(count($parts) !== 1) {
			throw new PageFinderSyntaxException(sprintf($this->_('Invalid row property path: %s'), $name));
		}
		[$json, $text, $typed] = $this->expression($query->field, $alias, $name, $def);
		if($operator === '!=' && $value !== '') {
			return 'NOT COALESCE((' . $this->scalarPredicate($query, $json, $text, $typed, $def, '=', $value) . '),0)';
		}
		return $this->scalarPredicate($query, $json, $text, $typed, $def, $operator, $value);
	}

	private function mediaCount(Field $field, string $name): string {
		$table = $this->wire()->database->escapeTable($field->table);
		$path = $this->wire()->database->quote('$."' . $name . '"."items"');
		return "COALESCE((SELECT JSON_LENGTH(JSON_EXTRACT(flex_media.data,$path)) FROM `$table` AS flex_media WHERE flex_media.pages_id=pages.id),0)";
	}

	private function matchRows($query, string $path, string $operator, $value, array $definition) {
		$this->type->storage()->requireRowSchema();
		$query->set('joinType', 'leftjoin');
		$query->where($this->rowsPredicate($query, $path, $operator, $value, $this->type->getDefinitions($query->field)));
		return $query;
	}

	/** Compile a schema-qualified path with explicit ancestor correlation. */
	private function rowsPredicate($query, string $path, string $operator, $value, FlexFieldDefs $defs, string $prefix = '', ?string $parent = null): string {
		$parts = explode('.', $path);
		$nodes = [];
		$leaf = '';
		$mode = '';
		$schema = null;
		do {
			$name = array_shift($parts);
			$definition = $defs->property($name);
			if(!$definition || $definition['kind'] !== 'rows') {
				throw new PageFinderSyntaxException(sprintf($this->_('Unknown repeatable path: %s'), $path));
			}
			$schemaPath = $prefix . $name;
			$branch = $parts ? array_shift($parts) : '';
			$type = '';
			$schema = null;
			if(!in_array($branch, ['', 'count', '_type'], true)) {
				if(!isset($definition['types'][$branch])) {
					throw new PageFinderSyntaxException(sprintf($this->_('Unknown Flex row type: %s'), $branch));
				}
				$type = $branch;
				$schema = $definition['types'][$type]['schema'];
			}
			$alias = 'flex_row_' . ++$this->rowAlias;
			$nodes[] = ['alias' => $alias, 'path' => $schemaPath, 'type' => $type];
			if($type !== '' && $parts && ($schema->property($parts[0])['kind'] ?? '') === 'rows') {
				$defs = $schema;
				$prefix = $schemaPath . '.' . $type . '.';
				continue;
			}
			$mode = $type === '' ? $branch : ($parts === ['count'] ? 'count' : '');
			$leaf = $mode === 'count' && $type !== '' ? '' : implode('.', $parts);
			if($type === '' && $parts) {
				throw new PageFinderSyntaxException($this->_('Invalid Flex collection path'));
			}
			break;
		} while(true);
		$from = 'FROM fieldtype_flex_rows AS ' . $nodes[0]['alias'];
		$conditions = [];
		$fieldKey = $query->bindValueGetKey((int) $query->field->id);
		foreach($nodes as $i => $node) {
			$a = $node['alias'];
			if($i > 0) {
				$previous = $nodes[$i - 1]['alias'];
				$from .= " JOIN fieldtype_flex_rows AS $a ON $a.parent_row_key=$previous.row_key AND $a.fields_id=$previous.fields_id AND $a.pages_id=$previous.pages_id";
			}
			$key = $query->bindValueGetKey(hash('sha256', $node['path']));
			$conditions[] = "$a.pages_id=pages.id AND $a.fields_id=$fieldKey AND $a.schema_key=$key";
			if($node['type'] !== '') {
				$typeKey = $query->bindValueGetKey($node['type']);
				$conditions[] = "$a.row_type=$typeKey";
			}
		}
		$first = $nodes[0]['alias'];
		$conditions[] = $parent === null ? "$first.parent_row_key=''" : "$first.parent_row_key=$parent.row_key";
		$from .= ' WHERE ' . implode(' AND ', $conditions);
		if($mode === 'count') {
			if(!is_numeric($value)) {
				throw new PageFinderSyntaxException($this->_('Flex counts require a number'));
			}
			return $this->compare($query, "(SELECT COUNT(*) $from)", $operator, 0 + $value, self::NUMBER_OPERATORS);
		}
		if($mode === '_type') {
			if(!in_array($operator, ['=', '!='], true) || !is_string($value) || ($value !== '' && !isset($definition['types'][$value]))) {
				throw new PageFinderSyntaxException($this->_('Flex row type selectors require = or != and a known type or blank'));
			}
			if($value !== '') {
				$key = $query->bindValueGetKey($value);
				$from .= " AND $alias.row_type=$key";
			}
			$negate = $value === '' ? $operator === '=' : $operator === '!=';
			return ($negate ? 'NOT ' : '') . "EXISTS (SELECT 1 $from)";
		}
		$negate = false;
		$predicate = '1=1';
		if($leaf !== '') {
			$blank = $value === '';
			$negate = ($blank && $operator === '=') || (!$blank && $operator === '!=');
			$op = $negate ? ($blank ? '!=' : '=') : $operator;
			$predicate = $this->rowLeaf($query, $leaf, $schema->properties(), $op, $value, $alias);
		} else if($value === '') {
			if(!in_array($operator, ['=', '!='], true)) {
				throw new PageFinderSyntaxException($this->_('A row collection supports blank or same-row selectors'));
			}
			$negate = $operator === '=';
		} else {
			if(!$schema || !in_array($operator, ['=', '!='], true) || !is_string($value)) {
				throw new PageFinderSyntaxException($this->_('Same-row selectors require a type and a quoted selector string'));
			}
			$selectors = $this->wire(new Selectors($value));
			if(!count($selectors)) {
				throw new PageFinderSyntaxException($this->_('Empty same-row selector'));
			}
			$conditions = [];
			foreach($selectors as $selector) {
				$fields = $selector->fields();
				if(count($fields) !== 1 || $selector->quote === '[') {
					throw new PageFinderSyntaxException($this->_('Same-row selectors require one property path'));
				}
				$choices = [];
				$childName = explode('.', $fields[0], 2)[0];
				foreach($selector->values() as $choice) {
					if(($schema->property($childName)['kind'] ?? '') === 'rows') {
						$choices[] = $this->rowsPredicate($query, $fields[0], $selector->operator, $choice, $schema, $schemaPath . '.' . $type . '.', $alias);
					} else {
						$choices[] = $this->rowLeaf($query, $fields[0], $schema->properties(), $selector->operator, $choice, $alias);
					}
				}
				$condition = '(' . implode($selector->operator === '!=' ? ' AND ' : ' OR ', $choices) . ')';
				$conditions[] = $selector->not ? "NOT $condition" : $condition;
			}
			$predicate = implode(' AND ', $conditions);
			$negate = $operator === '!=';
		}
		return ($negate ? 'NOT ' : '') . "EXISTS (SELECT 1 $from AND ($predicate))";
	}

	private function compare($query, string $expression, string $operator, $value, array $allowed): string {
		if(!in_array($operator, $allowed, true)) {
			throw new PageFinderSyntaxException(sprintf($this->_('Unsupported Flex selector operator: %s'), $operator));
		}
		if(in_array($operator, self::NUMBER_OPERATORS, true)) {
			$key = $query->bindValueGetKey($value);
			return "$expression $operator $key";
		}
		if($operator === '~=' || $operator === '~|=') {
			$words = preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
			if(!$words) {
				return '1=0';
			}
			$conditions = [];
			foreach($words as $word) {
				$key = $query->bindValueGetKey('(^|[^[:alnum:]_])' . preg_quote($word, '/') . '([^[:alnum:]_]|$)');
				$conditions[] = "$expression REGEXP $key";
			}
			return '(' . implode($operator === '~=' ? ' AND ' : ' OR ', $conditions) . ')';
		}
		// Escape LIKE metacharacters; '=' values never become SQL fragments.
		$literal = strtr((string) $value, [
			'=' => '==',
			'%' => '=%',
			'_' => '=_',
		]);
		$pattern = match($operator) {
			'^=', '%^=' => $literal . '%', '$=', '%$=' => '%' . $literal, default => '%' . $literal . '%',
		};
		$key = $query->bindValueGetKey($pattern);
		return "$expression LIKE $key ESCAPE '='";
	}

	/**
	 * Build a validated SQL sort expression for a scalar or collection count.
	 *
	 * @param Field $field Owning Flex field.
	 * @param DatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param bool $desc
	 * @return string
	 */
	public function sort(Field $field, $query, string $table, string $subfield, bool $desc) {
		if($subfield === 'data') {
			return '`' . $this->wire()->database->escapeTable($table) . '`.`search` ' . ($desc ? 'DESC' : 'ASC');
		}
		$parts = explode('.', $subfield);
		if(count($parts) > 1) {
			$def = $this->property($field, $parts[0]);
			if($parts !== [$parts[0], 'count'] || !in_array($def['kind'], ['rows', 'file', 'image'], true)) {
				throw new PageFinderSyntaxException($this->_('Repeated leaf sorting is ambiguous; sort the set count'));
			}
			$subfield = $parts[0];
		}
		$def = $this->property($field, $subfield);
		if(in_array($def['kind'], ['file', 'image'], true)) {
			return $this->mediaCount($field, $subfield) . ($desc ? ' DESC' : ' ASC');
		}
		if($def['kind'] === 'rows') {
			$this->type->storage()->requireRowSchema();
			$scope = $this->rowScope($query, $field, $subfield);
			return "(SELECT COUNT(*) FROM fieldtype_flex_rows AS flex_row WHERE $scope)" . ($desc ? ' DESC' : ' ASC');
		}
		if($def['multiple']) {
			throw new PageFinderSyntaxException($this->_('Sorting multi-selection Flex properties is ambiguous'));
		}
		[, , $typed] = $this->expression($field, $table, $subfield, $def);
		return $typed . ($desc ? ' DESC' : ' ASC');
	}

	/**
	 * Describe searchable properties for the native selector interface.
	 *
	 * @param Field $field Owning Flex field.
	 * @param array $data
	 * @return array
	 */
	public function info(Field $field, array $data = [], ?FlexFieldDefs $definitions = null): array {
		$info = [
			'name'      => $field->name,
			'label'     => $field->label ?: $field->name,
			'input'     => 'text',
			'operators' => self::TEXT_OPERATORS,
			'options'   => [],
			'subfields' => [],
		];
		$page = isset($data['page']) && $data['page'] instanceof Page ? $data['page'] : null;
		$targetInfo = [];
		$inspection = !empty($data['inspection']);
		$discover = function(FlexFieldDefs $defs, bool $root = true) use (&$discover, $page, &$targetInfo, $inspection): array {
			$subfields = [];
			foreach($defs->properties() as $name => $def) {
				if($def['kind'] === 'rows') {
					$children = [];
					$typeOptions = ['""' => $this->_('No rows')];
					foreach($def['types'] as $type => $typeDefinition) {
						// Native InputfieldSelector inserts option labels without escaping.
						$typeOptions[$type] = $this->wire()->sanitizer->entities($typeDefinition['label']);
						$typeFields = $discover($typeDefinition['schema'], false);
						$typeFields['count'] = [
							'name'      => 'count',
							'label'     => $this->_('Item count'),
							'input'     => 'number',
							'operators' => self::NUMBER_OPERATORS,
							'options'   => [],
							'subfields' => [],
						];
						$children[$type] = [
							'name'      => $type,
							'label'     => $typeDefinition['label'],
							'input'     => 'text',
							'operators' => ['=', '!='],
							'options'   => [],
							'subfields' => $typeFields,
						];
					}
					$children['_type'] = [
						'name'      => '_type',
						'label'     => $this->_('Item type'),
						'input'     => 'select',
						'operators' => ['=', '!='],
						'options'   => $typeOptions,
						'subfields' => [],
					];
					$children['count'] = [
						'name'      => 'count',
						'label'     => $this->_('Item count'),
						'input'     => 'number',
						'operators' => self::NUMBER_OPERATORS,
						'options'   => [],
						'subfields' => [],
					];
					$subfields[$name] = [
						'name'      => $name,
						'label'     => $def['input']->label ?: $name,
						'input'     => 'select',
						'operators' => ['=', '!='],
						'options'   => ['""' => $this->_('No rows')],
						'subfields' => $children,
					];
					continue;
				}
				if(in_array($def['kind'], ['file', 'image'], true)) {
					$subfields[$name] = [
						'name'      => $name,
						'label'     => $def['input']->label ?: $name,
						'input'     => 'select',
						'operators' => ['=', '!='],
						'options'   => ['""' => $this->_('No media')],
						'subfields' => [
							'count' => [
								'name'      => 'count',
								'label'     => $this->_('File count'),
								'input'     => 'number',
								'operators' => self::NUMBER_OPERATORS,
								'options'   => [],
								'subfields' => [],
							],
						],
					];
					continue;
				}
				$numeric = in_array($def['kind'], ['integer', 'float'], true);
				$input = $def['kind'] === 'page' ? 'page' : ($def['kind'] === 'date' ? 'date' : ($numeric ? 'number' : 'text'));
				$options = method_exists($def['input'], 'getOptions') ? $def['input']->getOptions() : [];
				if($def['kind'] === 'page') {
					// InputfieldSelector assumes page subfields are real Field objects
					// unless selector metadata supplies its own selectable options.
					$options = [0 => $this->_('None')];
					foreach($inspection ? [] : $def['input']->getSelectablePages($page ?: $this->wire()->pages->newNullPage(), 'limit=500') as $item) {
						$options[$item->id] = $def['input']->getPageLabel($item);
					}
				} else if($options) {
					$input = 'select';
				}
				$operators = $def['multiple'] || $input === 'select' || $input === 'page' ? ['=', '!='] : ($numeric || $input === 'date' ? self::NUMBER_OPERATORS : self::TEXT_OPERATORS);
				$subfields[$name] = [
					'name'      => $name,
					'label'     => $def['input']->label ?: $name,
					'input'     => $input,
					'operators' => $operators,
					'options'   => $options,
					'subfields' => $root && $def['kind'] === 'page' ? $this->pageTargetInfo($def, $targetInfo) : [],
				];
			}
			return $subfields;
		};
		$tree = $discover($definitions ?? $this->type->getDefinitions($field, $page));
		$flat = [];
		$flatten = function(array $branch, string $prefix = '', string $labelPrefix = '') use (&$flatten, &$flat): void {
			foreach($branch as $name => $descriptor) {
				$path = $prefix === '' ? (string) $name : "$prefix.$name";
				$direct = $descriptor;
				$direct['name'] = $path;
				$localLabel = $descriptor['label'] ?? (string) $name;
				$direct['label'] = $labelPrefix === '' ? $localLabel : "$labelPrefix / $localLabel";
				$direct['subfields'] = [];
				$flat[$path] = $direct;
				if(!empty($descriptor['subfields'])) {
					$flatten($descriptor['subfields'], $path, $direct['label']);
				}
			}
		};
		$flatten($tree);
		// InputfieldSelector renders only this one-level map and looks up a
		// selected dotted suffix directly. Keep the navigable shape separately;
		// nested descriptors here would make core offer unsupported `.id` paths.
		$info['flexTree'] = $tree;
		$info['subfields'] = $flat;
		return $info;
	}
}
