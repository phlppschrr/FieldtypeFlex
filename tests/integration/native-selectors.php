<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Cases adapted from the pinned core's Fieldtype*.test.php. Compare complete
// result sets rather than only checking that one known page can be found.
$cases = [
	'text' => ['FieldtypeText', [], ['type' => 'text'], ['Hello World', 'Other sentence', 'World elsewhere'], ['="Hello World"', '!="Hello World"', '^=Hello', '%^=Hello', '$=World', '%$=World', '*=World', '%=World', '~=Hello', '~|="Hello Missing"', '=""', '!=""']],
	'textarea' => ['FieldtypeTextarea', [], ['type' => 'textarea'], ["First paragraph\nSecond line", 'Another paragraph', 'Unrelated content'], ['*=paragraph', '%=line', '^=First', '$=content', '!="Unrelated content"', '=""', '!=""']],
	'email' => ['FieldtypeEmail', [], ['type' => 'email'], ['user@example.com', 'other@example.org', 'user+tag@mail.example.co.uk'], ['=user@example.com', '*=example', '$=.com', '^=user', '!=user@example.com', '=""', '!=""']],
	'url' => ['FieldtypeURL', [], ['type' => 'url'], ['https://example.com/docs/', '/local/path/', 'https://other.test/'], ['^=https://', '*=example.com', '$=.test/', '%=docs', '!=https://other.test/', '=""', '!=""']],
	'integer' => ['FieldtypeInteger', ['zeroNotEmpty' => 1], ['type' => 'integer'], [42, -10, 0], ['=42', '!=42', '>40', '>=42', '<0', '<=0', '=0', '=""', '!=""']],
	'float' => ['FieldtypeFloat', ['colType' => 'double', 'precision' => 2, 'zeroNotEmpty' => 1], ['type' => 'float', 'precision' => 2], [3.14, -2.5, 0.0], ['=3.14', '!=3.14', '>3', '>=3.14', '<0', '<=0', '=0', '=""', '!=""']],
	'datetime' => ['FieldtypeDatetime', [], ['type' => 'datetime'], ['2026-04-08 14:30:00', '2025-01-01 12:00:00', '2027-01-01 12:00:00'], ['="2026-04-08 14:30:00"', '!="2026-04-08 14:30:00"', '>2026-01-01', '>="2026-04-08 14:30:00"', '<2026-01-01', '<="2026-04-08 14:30:00"', '=""', '!=""']],
	'checkbox' => ['FieldtypeCheckbox', [], ['type' => 'checkbox'], [1, 0, 1], ['=1', '=0', '!=1']],
	'toggle' => ['FieldtypeToggle', ['formatType' => 0], ['type' => 'Toggle'], [1, 0, 1], ['=1', '=0', '!=1']],
	'page' => ['FieldtypePage', ['derefAsPage' => 2], ['type' => 'page', 'derefAsPage' => 2], [$id, $second->id, $id], ["=$id", "!=$id", "=$second->id", '=""', '!=""']],
];
$owners = [];
foreach(['match', 'other', 'zero', 'blank', 'missing_property', 'absent_storage'] as $state) {
	$owner = $wire->pages->newPage([
		'template' => $template,
		'parent' => 1,
		'name' => 'flex-core-selector-' . bin2hex(random_bytes(5)),
		'title' => $state,
	]);
	$pages[] = $owner;
	$owner->of(false);
	$owner->save();
	$owners[$state] = $owner;
}
$ownerIDs = implode('|', array_map(static fn(Page $p): int => (int) $p->id, $owners));
$find = static function(string $predicate) use ($wire, $ownerIDs): array {
	$found = array_map('intval', $wire->pages->findIDs("id=$ownerIDs, $predicate, include=all, check_access=0"));
	sort($found);
	return $found;
};
$failures = [];
foreach($cases as $kind => [$nativeType, $settings, $definition, $values, $predicates]) {
	$n = $wire->wire(new Field());
	$n->name = 'flex_core_selector_' . bin2hex(random_bytes(5));
	$n->type = $wire->modules->get($nativeType);
	foreach($settings as $key => $value) $n->set($key, $value);
	$temporaryFields[] = $n;
	$n->save();
	$template->fieldgroup->add($n);
	$template->fieldgroup->save();
	$f = $createField('flex_core_selector', ['value' => $definition]);
	foreach(array_values($owners) as $index => $owner) {
		if($index === 5) continue; // No native or Flex field-table row.
		$p = $wire->pages->getFresh($owner->id);
		$p->of(false);
		$p->set($n->name, $values[$index] ?? '');
		$p->set($f->name, $index === 4 ? [] : ['value' => $values[$index] ?? '']);
		$p->save($n->name);
		$p->save($f->name);
	}
	// Normal saves materialize schema defaults. Simulate an older canonical
	// record predating this property, so this case really has no JSON key.
	$table = $wire->database->escapeTable($f->getTable());
	$missing = $wire->database->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id");
	$missing->execute([':data' => '{}', ':id' => (int) $owners['missing_property']->id]);
	$stored = $wire->database->prepare("SELECT data FROM `$table` WHERE pages_id=:id");
	$stored->execute([':id' => (int) $owners['missing_property']->id]);
	$same([], json_decode($stored->fetchColumn(), true, 512, JSON_THROW_ON_ERROR), "$kind missing property really has no canonical JSON key");
	$stored->execute([':id' => (int) $owners['absent_storage']->id]);
	$same(false, $stored->fetchColumn(), "$kind absent storage really has no field-table row");
	if($kind === 'datetime') {
		$rowField = $createField('flex_core_dates', ['events' => [
			'type' => 'rows',
			'types' => ['event' => ['children' => ['value' => ['type' => 'datetime']]]],
		]]);
		foreach(array_values($owners) as $index => $owner) {
			if($index === 5) continue;
			$p = $wire->pages->getFresh($owner->id);
			$p->of(false);
			$p->get($rowField->name)->events->addRow('event', $index === 4 ? [] : ['value' => $values[$index] ?? '']);
			$p->save($rowField->name);
		}
		foreach(['<2026-01-01', '<=2026-04-08 14:30:00'] as $predicate) {
			$expected = $find($n->name . $predicate);
			$same($expected, $find($rowField->name . '.events.event.value' . $predicate), "row date $predicate excludes blank values like native");
			$same($expected, $find($rowField->name . '.events.event="value' . $predicate . '"'), "same-row date $predicate excludes blank values like native");
		}
	}
	foreach($predicates as $predicate) {
		try {
			$expected = $find($n->name . $predicate);
			$actual = $find($f->name . '.value' . $predicate);
			$assert(count($expected) > 0 && count($expected) < count($owners), "$kind $predicate has native positive and negative controls");
			if(str_starts_with($predicate, '!=') && $predicate !== '!=""') {
				// Native scalar Fieldtypes differ in whether blank/absent values
				// satisfy !=. Compare populated values, then enforce Flex's own
				// documented no-match semantics for missing data independently.
				$populated = array_map(static fn(Page $p): int => (int) $p->id, array_slice(array_values($owners), 0, 3));
				$same(array_values(array_intersect($expected, $populated)), array_values(array_intersect($actual, $populated)), "$kind $predicate native/Flex populated selector results");
				foreach(['blank', 'missing_property', 'absent_storage'] as $state) {
					$assert(in_array((int) $owners[$state]->id, $actual, true), "$kind $predicate includes $state in Flex");
				}
				if($kind === 'text') {
					foreach(['blank', 'missing_property', 'absent_storage'] as $state) {
						$assert(!in_array((int) $owners[$state]->id, $expected, true), "native text inequality excludes $state");
						$assert(in_array((int) $owners[$state]->id, $actual, true), "Flex text inequality includes $state");
					}
				}
			} else if($kind === 'checkbox' && $predicate === '=0') {
				// Native Checkbox collapses absent storage into unchecked. Flex
				// requires an explicit canonical zero for a numeric zero match.
				$missingIDs = [];
				foreach(['missing_property', 'absent_storage'] as $state) {
					$missingIDs[] = (int) $owners[$state]->id;
					$assert(in_array((int) $owners[$state]->id, $expected, true), "native unchecked Checkbox includes $state");
					$assert(!in_array((int) $owners[$state]->id, $actual, true), "Flex explicit Checkbox zero excludes $state");
				}
				$same(array_values(array_diff($expected, $missingIDs)), $actual, 'Checkbox zero agrees for stored values');
			} else {
				$same($expected, $actual, "$kind $predicate native/Flex complete selector results");
			}
		} catch(\Throwable $error) {
			$failures[] = $error->getMessage();
			echo $error->getMessage() . "\n";
		}
	}
}
$same([], $failures, 'all independent native selector comparisons pass');
