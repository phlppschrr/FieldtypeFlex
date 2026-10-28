<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

if(PHP_SAPI !== 'cli' || !isset($same, $createField, $temporaryFields)) {
	http_response_code(404);
	exit;
}
// Public value API: row identity and typed persistence, without editor internals.
$structured = $createField('flex_structured', [
	'heading' => ['type' => 'text'],
	'slides'  => [
		'type'       => 'rows',
		'maxItems'   => 20,
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => [
					'heading' => ['type' => 'text'],
					'price'   => ['type' => 'float'],
					'group'   => [
						'type'     => 'fieldset',
						'children' => ['city' => ['type' => 'text']],
					],
				],
			],
		],
	],
]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$page->{$structured->name} = ['heading' => 'Root'];
$row = $page->{$structured->name}->slides->addRow('item', [
	'heading' => 'Intro <one>',
	'price'   => 5.5,
	'city'    => 'Berlin',
]);
$rowId = $row->rowId();
$assert((bool) preg_match('/^[a-f0-9]{32}$/D', $rowId), 'repeatable rows have stable opaque identity');
$page->save($structured->name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$same('Root', $page->{$structured->name}->heading, 'row names do not collide with root names');
$same($rowId, $page->{$structured->name}->slides->first()->rowId(), 'row identity survives save and reload');
$same(5.5, $page->{$structured->name}->slides->first()->price, 'typed row values survive database roundtrip');
$same('Berlin', $page->{$structured->name}->slides->first()->city, 'ordinary fieldsets remain flat inside a row');
$name = $structured->name;
$rows = $page->{$name}->slides;
$otherRow = $rows->addRow('item', [
	'heading' => 'Other',
	'price'   => 20,
]);
$otherId = $otherRow->rowId();
$rows->moveRow($otherId, $rowId);
$page->save($name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$same([$otherId, $rowId], array_keys($page->{$name}->slides->getArray()), 'reordering preserves row identities');
$page->resetTrackChanges();
$page->{$name}->slides->getById($otherId)->city = 'Paris';
$assert($page->isChanged($name), 'nested row mutation marks actual owner changed');
$page->save();
$same('Paris', $wire->pages->getFresh($id)->getUnformatted($name)->slides->first()->city, 'full page save persists nested row edit');
$formatted = $wire->pages->getFresh($id)->get($name);
$formatted->of(true);
$same('Intro &lt;one&gt;', $formatted->slides->getById($rowId)->heading, 'formatted row reads use property escaping');
$throws(fn() => $formatted->slides->addRow('item'), 'formatted row collection rejects writes');
$throws(fn() => $formatted->slides->first()->set('heading', 'Bad'), 'formatted row leaves reject writes');
$same('Intro <one>', $formatted->rawArray()['slides']['items'][1]['values']['heading'], 'formatted row view does not contaminate storage');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.item.heading=Other"), 'nested row leaf selectors match any row');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.item.heading=Intro <one>, $name.slides.item.price>10"), 'independent row predicates may match different rows');
$same([], $wire->pages->findIDs("id=$ids, $name.slides.item=\"heading=Intro <one>, price>10\""), 'same-row predicates reject deliberate cross-row false positive');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.item=\"heading=Other, price>10\""), 'quoted same-row selector requires one matching row');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.item=\"price!=20\""), 'same-row numeric inequality follows scalar negative semantics');
$same([$second->id], $wire->pages->findIDs("id=$ids, $name.slides.item.heading!=Other"), 'negative row predicate includes absent collection but excludes matching row');
$same([$second->id], $wire->pages->findIDs("id=$ids, $name.slides.item.heading="), 'row leaf blank means no populated row');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.item.heading!="), 'row leaf nonblank means at least one populated row');
$same([$id], $wire->pages->findIDs("id=$ids, $name.slides.count=2"), 'repeatable count selector counts committed rows');
$same([$second->id], $wire->pages->findIDs("id=$ids, $name.slides.count=0"), 'absent collection has count zero');
$same([$second->id, $id], $wire->pages->findIDs("id=$ids, sort=$name.slides.count"), 'repeatable count sorting');
$same([], $wire->pages->findIDs("id=$id, $name*=$rowId"), 'whole-field search excludes internal row identities');
$same('number', $type->getSelectorInfo($structured)['subfields']['slides.item.price']['input'], 'selector metadata exposes typed row leaves');
$same('number', $type->getSelectorInfo($structured)['subfields']['slides.count']['input'], 'selector metadata exposes row count');
$throws(fn() => $wire->pages->find("id=$id, $name.slides.item.unknown=x"), 'unknown row query leaf rejected');
$throws(fn() => $wire->pages->find("id=$id, sort=$name.slides.item.price"), 'repeated leaf sorting rejected as ambiguous');
$page = $wire->pages->getFresh($id);
$page->of(false);
$originalRows = $page->{$name}->rawArray();
$wire->database->beginTransaction();
try {
	$page->{$name}->slides->addRow('item', ['heading' => 'Transaction']);
	$page->save($name);
	$assert($wire->database->inTransaction(), 'Flex save preserves caller-owned transaction');
	$same([$id], $wire->pages->findIDs("id=$id, $name.slides.count=3"), 'projection changes participate in caller transaction');
} finally {
	$wire->database->rollBack();
}
$same($originalRows, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'caller rollback restores canonical row values');
$same([$id], $wire->pages->findIDs("id=$id, $name.slides.count=2"), 'caller rollback restores row query projection');
$same(1, $type->rebuildRowIndex($structured)['count'], 'row query projection can be rebuilt from canonical values');
$same([$id], $wire->pages->findIDs("id=$id, $name.slides.item.heading=Other"), 'row index rebuild preserves query results');
$rowOnly = $createField('flex_row_only', [
	'items' => [
		'type'       => 'rows',
		'maxItems'   => 1,
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => ['title' => ['type' => 'text']],
			],
		],
	],
]);
$blankRows = $type->getBlankValue($page, $rowOnly);
$assert($type->isEmptyValue($rowOnly, $blankRows), 'row envelope does not make an empty field populated');
$blankRows->items->addRow('item');
$assert($type->isEmptyValue($rowOnly, $blankRows), 'blank row identity is not a populated user value');
$throws(fn() => $blankRows->items->addRow('item'), 'server enforces repeatable maximum');
$throws(fn() => $type->sanitizeValue($page, $rowOnly, [
	'items' => [
		'_flex' => 'rows:9',
		'items' => [],
	],
]), 'unsupported row envelope version rejected');
$badRows = clone $rowOnly;
$badRows->flexDefinition = json_encode([
	'outer' => [
		'type'       => 'rows',
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => [
					'inner' => [
						'type'       => 'rows',
						'types'      => [
							'item' => [
								'label'    => 'Item',
								'children' => [],
							],
						],
					],
				],
			],
		],
	],
]);
$assert($type->getDefinitions($badRows, $page)->property('outer.item.inner')['kind'] === 'rows', 'nested repeatable set is supported');
$triggerName = 'flex_rows_failure_' . bin2hex(random_bytes(3));
$wire->database->exec("CREATE TRIGGER $triggerName BEFORE INSERT ON fieldtype_flex_rows FOR EACH ROW BEGIN IF NEW.fields_id=" . (int) $structured->id . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture projection failure'; END IF; END");
try {
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$page->{$name}->slides->first()->heading = 'Must roll back';
	$throws(fn() => $page->save($name), 'projection failure rejects the field save', \PDOException::class);
	$assert(!$wire->database->inTransaction(), 'failed owned save closes its transaction');
	$same($originalRows, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'projection failure retains previous canonical values');
} finally {
	$wire->database->exec("DROP TRIGGER $triggerName");
}
// Native editor seam: complete POST, stable order, deferred fields and tamper rejection.
$page = $wire->pages->getFresh($id);
$page->of(false);
$editor = $structured->getInputfield($page);
$editor->value = $page->getUnformatted($name);
$editor->renderReady();
$html = $editor->render();
$prefix = $editor->name . '__slides__';
$assert(str_contains($html, FlexPath::inputPrefix($editor->name, 'slides.' . $rowId) . 'heading') && str_contains($html, FlexPath::inputPrefix($editor->name, 'slides.' . $otherId) . 'heading'), 'repeatable editor renders independent native leaf names');
preg_match('/name="' . preg_quote($editor->name . '__0draft', '/') . '" value="([a-f0-9]{64})"/', $html, $match);
$draftToken = $match[1] ?? '';
$assert($draftToken !== '', 'repeatable editor emits a server-owned draft context');
$post = [
	$editor->name . '__0draft'           => $draftToken,
	$editor->name . '__0complete'           => $draftToken,
	$editor->name . '__heading'         => 'Edited root',
	FlexPath::controlName($editor->name, 'slides', 'present') => '1',
	FlexPath::controlName($editor->name, 'slides', 'order')   => "$rowId,$otherId",
	FlexPath::inputPrefix($editor->name, 'slides.' . $rowId) . 'heading'      => 'Edited first',
	FlexPath::inputPrefix($editor->name, 'slides.' . $rowId) . 'price'        => '7.5',
	FlexPath::inputPrefix($editor->name, 'slides.' . $rowId) . 'city'         => 'London',
	FlexPath::inputPrefix($editor->name, 'slides.' . $otherId) . 'heading'    => 'Edited second',
	FlexPath::inputPrefix($editor->name, 'slides.' . $otherId) . 'price'      => '30',
	FlexPath::inputPrefix($editor->name, 'slides.' . $otherId) . 'city'       => 'Vienna',
];
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'native row form processes a complete valid POST');
$page->{$name} = $editor->value;
$page->save($name);
$saved = $wire->pages->getFresh($id)->getUnformatted($name);
$same([$rowId, $otherId], array_keys($saved->slides->getArray()), 'native row POST persists submitted stable order');
$same('Edited first', $saved->slides->first()->heading, 'native row POST persists isolated leaf values');
$same(7.5, $saved->slides->first()->price, 'native row POST applies typed leaf conversion');
$page = $wire->pages->getFresh($id);
$page->of(false);
$editor = $structured->getInputfield($page);
$editor->value = $page->{$name};
$partialPost = [$editor->name . '__heading' => 'Partial root'];
$editor->processInput($wire->wire(new WireInputData($partialPost)));
$same($saved->slides->rawArray(), $editor->value->slides->rawArray(), 'omitted repeatable controls preserve deferred rows');
$page = $wire->pages->getFresh($id);
$page->of(false);
$editor = $structured->getInputfield($page);
$editor->value = $page->{$name};
$html = $editor->render();
preg_match('/name="' . preg_quote($editor->name . '__0draft', '/') . '" value="([a-f0-9]{64})"/', $html, $match);
$forgedPost = [
	$editor->name . '__0draft'           => $match[1],
	$editor->name . '__0complete'           => $match[1],
	FlexPath::controlName($editor->name, 'slides', 'present') => '1',
	FlexPath::controlName($editor->name, 'slides', 'order')   => bin2hex(random_bytes(16)),
];
$editor->processInput($wire->wire(new WireInputData($forgedPost)));
$assert(count($editor->getErrors()) > 0, 'editor rejects an unregistered forged row identity');
$same($saved->rawArray(), $editor->value->rawArray(), 'invalid row POST preserves the containing committed value');
$second = $wire->pages->getFresh($second->id);
$second->of(false);
$second->{$name}->slides->addRow('item', [
	'heading' => 'Blank numeric',
	'price'   => '',
]);
$second->save($name);
$same([$second->id], $wire->pages->findIDs("id={$second->id}, $name.slides.item=\"price!=20\""), 'same-row numeric inequality includes a blank leaf');
$minField = $createField('flex_row_min', [
	'items' => [
		'type'       => 'rows',
		'minItems'   => 2,
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => ['heading' => ['type' => 'text']],
			],
		],
	],
]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$minValue = $page->getUnformatted($minField->name);
$token = $type->admin()->open($minValue, $minField->name);
$row = $minValue->items->addRow('item');
$type->admin()->registerRow($token, 'items', $row);
$canonical = $type->getBlankValue($page, $minField);
$editor = $minField->getInputfield($page);
$editor->value = $canonical;
$minPost = [
	$minField->name . '__0draft'                                 => $token,
	$minField->name . '__0complete'                                 => $token,
	FlexPath::controlName($minField->name, 'items', 'present')                        => '1',
	FlexPath::controlName($minField->name, 'items', 'order')                          => $row->rowId(),
	FlexPath::inputPrefix($minField->name, 'items.' . $row->rowId()) . 'heading' => 'Preserved after minimum error',
];
$editor->processInput($wire->wire(new WireInputData($minPost)));
$assert(count($editor->getErrors()) > 0 && str_contains($editor->render(), 'Preserved after minimum error'), 'minimum-count validation preserves new rows in the recovery UI');
$same(0, count($editor->value->items), 'minimum-count error rejects the containing canonical value');
$template->fieldgroup->remove($minField);
$template->fieldgroup->save();
