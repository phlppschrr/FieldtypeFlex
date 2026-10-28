<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$searchField = $createField('flex_row_search', ['blocks' => [
	'type' => 'rows',
	'types' => [
		'text' => ['children' => [
			'heading' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
			'body' => ['type' => 'text'],
			'type' => ['type' => 'text'],
			'_type' => ['type' => 'text'],
			'number' => ['type' => 'integer'],
			'notes' => ['type' => 'rows', 'types' => ['note' => ['children' => [
				'heading' => ['type' => 'text'], 'number' => ['type' => 'integer'],
				'photo' => ['type' => 'image', 'maxFiles' => 1],
			]]]],
		]],
		'image' => ['children' => ['heading' => ['type' => 'text'], 'photo' => ['type' => 'image'], 'portrait' => ['type' => 'image', 'maxFiles' => 1]]],
	],
]]);
$name = $searchField->name;
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->get($name);
$rows = $value->blocks;
$a = $rows->addRow('text', ['heading' => 'Introduction & <one>', 'body' => 'Other', 'type' => 'editorial', '_type' => 'stored metadata-like content', 'future_property' => ['preserve' => true], 'number' => 0]);
$b = $rows->addRow('text', ['heading' => 'Other', 'body' => 'ProcessWire', 'number' => 2]);
$blank = $rows->addRow('text');
$image = $rows->addRow('image', ['heading' => 'Gallery']);
$hidden = $rows->addRow('image', ['heading' => 'Hidden']);
$hidden->setHidden(true);
$x = $a->notes->addRow('note', ['heading' => 'X', 'number' => 1]);
$y = $a->notes->addRow('note', ['heading' => 'Y', 'number' => 2]);
$z = $b->notes->addRow('note', ['heading' => 'Z', 'number' => 3]);
$secret = $b->notes->addRow('note', ['heading' => 'Secret', 'number' => 4]);
$secret->setHidden(true);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/row-search.png');
$bitmap = imagecreatetruecolor(8, 8);
imagepng($bitmap, $fixture);
imagedestroy($bitmap);
$image->photo->add($fixture);
$image->photo->add($fixture);
$image->photo->add($fixture);
$image->portrait->add($fixture);
$x->photo->add($fixture);
$page->save($name);
$raw = $value->rawArray();
$page->resetTrackChanges();
$value->resetTrackChanges();
$assert([$image, $hidden] === array_values($rows->find('_type=image')->getArray()), 'find selects metadata type and preserves order and row identity');
$assert([$a] === array_values($rows->find('type=editorial')->getArray()), 'type remains an ordinary content property');
$assert([$image] === array_values($rows->find('_type=image, photo.count>=3')->getArray()), 'find supports requested three-image threshold');
$assert([$image] === array_values($rows->find('_type=image, photo.count=3')->getArray()), 'find combines metadata and native image count on one row');
$same([], $rows->find('_type=text, heading^=Introduction, body*=ProcessWire')->getArray(), 'find rejects cross-row false positive');
$assert([$a, $b, $blank] === array_values($rows->find('_type=text')->getArray()), 'find selects all direct text rows');
$assert([$x] === array_values($a->notes->find('_type=note, heading=X, number=1')->getArray()), 'nested collection find retains concrete child identity');
$same([], $a->notes->find('heading=X, number=2')->getArray(), 'nested find rejects split child matches');
$assert([$a, $blank] === array_values($rows->find('_type=text, number=0')->getArray()), 'runtime follows native in-memory zero/blank equality');
$assert([$blank] === array_values($rows->find('_type=text, heading=""')->getArray()), 'runtime blank applies to concrete row');
$assert([$b, $blank] === array_values($rows->find('_type=text, heading!=Introduction & <one>')->getArray()), 'runtime negative applies to concrete row');
$same('stored metadata-like content', $a->_type, 'metadata selector does not overwrite content named _type');
$same([], $rows->find('_type=unknown')->getArray(), 'unknown runtime type yields no rows');
$same(5, count($rows->find('')), 'empty runtime selector returns the current view');
$same(1, count($rows->find('_type=image')->find('heading!=Gallery')), 'chained search uses result membership');
$same($raw, $value->rawArray(), 'find preserves canonical data and identities');
$same(false, $value->isChanged(), 'find does not mark rows or owner changed');
$same(false, $page->isChanged($name), 'find does not mark owning field changed');
$selection = $rows->find('_type=text');
$selection->removeRow($blank->rowId());
$same(5, count($rows), 'result membership is independent of source collection');
$selection->first()->body = 'Shared row edit';
$same(1, count($rows->find('body=Shared row edit')), 'find sees unsaved leaf edits');
$same('Shared row edit', $a->body, 'selected rows remain shared owned objects');
$rows->find('_type=text');
$same(true, $a->isChanged('body'), 'find retains pre-existing changes on shared rows');
$a->body = 'Other';
foreach(['missing=x', 'photo.name=x', '_type>image', 'number*=2', '@heading=x', 'heading|body=x', 'notes.note.heading=X', 'photo.count=', 'sort=heading'] as $invalid) {
	$throws(fn() => $rows->find($invalid), 'runtime rejects invalid or unsupported selector: ' . $invalid);
}
$empty = $rows->makeNew();
$throws(fn() => $empty->find('missing=x'), 'empty runtime collection still validates paths');
$fresh = $wire->pages->getFresh($id);
$fresh->of(true);
$formatted = $fresh->get($name)->blocks;
$formattedRaw = $formatted->rawArray();
$fresh->resetTrackChanges();
$same(1, count($formatted->find('_type=image')), 'formatted search excludes hidden direct rows');
$found = $formatted->find('heading=Introduction & <one>')->first();
$same('Introduction &amp; &lt;one&gt;', $found->heading, 'formatted search compares raw text but returns formatted objects');
$assert($found === $formatted->getById($a->rowId()), 'formatted search preserves existing view identity');
$same(1, count($formatted->find('portrait.count=1')), 'formatted single image still has native collection count');
$same(1, count($formatted->getById($b->rowId())->notes->find('_type=note')), 'formatted nested search excludes hidden children');
$same(1, count($formatted->find('_type=text, notes.count=1')), 'nested runtime count respects the visible collection');
$same(0, count($rows->find('_type=text, notes.count=1')), 'unformatted nested count includes hidden child');
$same($formattedRaw, $formatted->rawArray(), 'formatted find preserves full backing data');
$same(false, $fresh->isChanged($name), 'formatted find preserves owner tracking');
$view = $formatted->find('_type=image');
$view->of(false);
$same(1, count($view), 'unformatting search result does not resurrect excluded hidden or nonmatching rows');
$same(5, count($rows), 'search result formatting does not change source membership');

$find = static fn(string $predicate) => $wire->pages->findIDs("id=$ids, $predicate");
$same([$id], $find("$name.blocks.text.heading^=Introduction, $name.blocks.text.body*=ProcessWire"), 'ungrouped SQL may match different rows');
$same([], $find("@$name.blocks.text.heading^=Introduction, @$name.blocks.text.body*=ProcessWire"), '@ SQL rejects split rows');
$same([$id], $find("@$name.blocks.text.heading=Other, @$name.blocks.text.body*=ProcessWire"), '@ SQL matches one concrete row');
$cases = [
	['heading=Other, body*=ProcessWire', [$id]],
	['heading^=Introduction, body*=ProcessWire', []],
	['heading^=Introduction, body!=ProcessWire', [$id]],
	['heading="", body=""', [$id]],
	['number=0, heading!=""', [$id]],
	['heading=Other, notes.count=1', [$id]],
];
foreach($cases as [$conditions, $expected]) {
	$at = implode(', ', array_map(static fn($p) => "@$name.blocks.text.$p", explode(', ', $conditions)));
	$same($expected, $find($at), '@ SQL row semantics: ' . $conditions);
}
$without = static function(string $selector) use ($wire, $ids): array {
	$excluded = $wire->pages->findIDs("id=$ids, $selector");
	$excluded = implode('|', $excluded) ?: '0';
	return $wire->pages->findIDs("id=$ids, id!=$excluded, sort=id");
};
$same([$second->id], $without("@$name.blocks.text.heading=Other, @$name.blocks.text.body*=ProcessWire"), 'native ID exclusion negates an entire same-row group');
$same([$id, $second->id], $without("@$name.blocks.text.heading=Missing, @$name.blocks.text.body*=ProcessWire"), 'native ID exclusion handles an empty matching group');
foreach(["$name.blocks.text=\"heading=Other, body*=ProcessWire\"", "$name.blocks.text!=\"heading=Other\"", "@$name.blocks.text.notes.note=\"heading=X, number=1\""] as $removed) {
	$throws(fn() => $find($removed), 'removed quoted same-row syntax fails explicitly', PageFinderSyntaxException::class);
}
$same([], $find("@$name.blocks.text.heading^=Introduction, @$name.blocks.text.notes.note.heading=Z"), '@ SQL cannot combine parents and unrelated children');
$same([], $find("@$name.blocks.text.notes.note.heading=X, @$name.blocks.text.notes.note.number=2"), '@ SQL cannot combine different children of same parent');
$same([$id], $find("@$name.blocks.text.heading^=Introduction, @$name.blocks.text.notes.note.heading=X, @$name.blocks.text.notes.note.number=1, @$name.blocks.text.notes.note.photo.count=1"), '@ SQL correlates parent, child, numeric value and media count');
$same([$id], $find("a@$name.blocks.text.heading^=Introduction, b@$name.blocks.text.body*=ProcessWire"), 'named @ groups allow independent rows');
$same([$id], $find("@$name.blocks.text.heading^=Introduction, !@$name.blocks.text.body*=ProcessWire"), '@ prefix negation applies to the concrete row predicate');
$same([], $find("@$name.blocks.text.heading=Other, !@$name.blocks.text.body*=ProcessWire"), '@ prefix negation does not invert the entire group');
$same([$id], $find("@$name.blocks.text.heading=Missing|Other, @$name.blocks.text.body=ProcessWire"), '@ keeps OR selector values within the row');
$same([], $find("@$name.blocks.text.heading^=Introduction, @$name.blocks.text.body!=Other|ProcessWire"), '@ negative OR excludes every specified value on the same row');
$same([], $find("@$name.blocks.image.heading=Hidden, @$name.blocks.image.photo.count=0"), '@ SQL excludes hidden rows');
$same([], $find("@$name.blocks.text.notes.note.heading=Secret, @$name.blocks.text.notes.note.number=4"), '@ SQL excludes hidden descendants');
$same([$id], $find("@$name.blocks.image.heading=Gallery, @$name.blocks.image.photo.count=3"), '@ SQL supports media counts on same image row');
$same([], $find("@$name.blocks.image.heading=Gallery, @$name.blocks.image.photo.count=0"), '@ SQL media count cannot match another row');
$same([], $find("id=$second->id, @$name.blocks.text.heading=, @$name.blocks.text.body!=ProcessWire"), '@ positive same-row needs an actual row even for blank and negative conditions');
$same([$second->id], $find("$name.blocks.text.heading!=Other"), 'ungrouped negative still includes absent collection');
foreach(["@$name.blocks.text.heading=x, @$name.blocks.image.heading=x", "@$name.blocks._type=text", "@$name.blocks.text.count=0", "@$name.blocks.text.missing=x", "@$name.blocks.text.notes.note.photo.name=x", "@$name.blocks.text.notes._type=note"] as $invalid) {
	$throws(fn() => $find($invalid), 'SQL @ rejects invalid scope or property: ' . $invalid, PageFinderSyntaxException::class);
}

// Structured values must remain bound literals across group normalization.
$literal = <<<'LITERAL'
ProcessWire "quote", heading=Other | \\ path ' OR 1=1 --
LITERAL;
$b->body = $literal;
$page->save($name);
$bound = $wire->wire(new Selectors());
$bound->add($bound->create('id', '=', $id));
foreach(['heading' => 'Other', 'body' => $literal] as $property => $needle) {
	$condition = $bound->create("$name.blocks.text.$property", '=', $needle);
	$condition->group = 'literal';
	$bound->add($condition);
}
$same([$id], $wire->pages->findIDs($bound), '@ AST preserves punctuation and quotes as literal bound values');
$b->body = 'ProcessWire';
$page->save($name);

// Compare the installed core's native Repeater, using real saved item Pages.
$nativeFields = [];
foreach(['heading' => 'FieldtypeText', 'body' => 'FieldtypeText', 'photo' => 'FieldtypeImage', 'number' => 'FieldtypeInteger'] as $key => $class) {
	$f = $wire->wire(new Field());
	$f->name = 'flex_compare_' . $key . '_' . bin2hex(random_bytes(3));
	$f->type = $wire->modules->get($class);
	$f->maxFiles = 0;
	$f->extensions = $key === 'photo' ? 'png' : '';
	$f->save();
	$temporaryFields[] = $f;
	$nativeFields[$key] = $f;
}
$nativeChildren = $wire->wire(new RepeaterField());
$nativeChildren->name = 'flex_compare_children_' . bin2hex(random_bytes(3));
$nativeChildren->type = $repeaterType;
$nativeChildren->repeaterFields = array_map(static fn($f) => (int) $f->id, array_values($nativeFields));
$nativeChildren->save();
$temporaryFields[] = $nativeChildren;
$childTemplate = $repeaterType->_getRepeaterTemplate($nativeChildren);
foreach($nativeFields as $f) $childTemplate->fieldgroup->add($f);
$childTemplate->fieldgroup->save();
$nativeFields['notes'] = $nativeChildren;
$nativeRepeater = $wire->wire(new RepeaterField());
$nativeRepeater->name = 'flex_compare_repeater_' . bin2hex(random_bytes(3));
$nativeRepeater->type = $repeaterType;
$nativeRepeater->repeaterFields = array_map(static fn($f) => (int) $f->id, array_values($nativeFields));
$nativeRepeater->save();
$temporaryFields[] = $nativeRepeater;
$nativeTemplate = $repeaterType->_getRepeaterTemplate($nativeRepeater);
foreach($nativeFields as $f) $nativeTemplate->fieldgroup->add($f);
$nativeTemplate->fieldgroup->save();
$template->fieldgroup->add($nativeRepeater);
$template->fieldgroup->save();
$nativeOwner = $wire->pages->getFresh($id);
$nativeOwner->of(false);
foreach([['Introduction & <one>', 'Other'], ['Other', 'ProcessWire'], ['', ''], ['Gallery', '']] as [$heading, $body]) {
	$item = $nativeOwner->get($nativeRepeater->name)->getNewItem();
	$item->of(false);
	$item->set($nativeFields['heading']->name, $heading);
	$item->set($nativeFields['body']->name, $body);
	$item->save();
	if($heading === 'Gallery') for($i = 0; $i < 3; ++$i) $item->get($nativeFields['photo']->name)->add($fixture);
	foreach($heading === 'Introduction & <one>' ? [['X', 1], ['Y', 2]] : ($heading === 'Other' ? [['Z', 3]] : []) as [$childHeading, $number]) {
		$child = $item->get($nativeChildren->name)->getNewItem();
		$child->of(false);
		$child->set($nativeFields['heading']->name, $childHeading);
		$child->set($nativeFields['number']->name, $number);
		$child->save();
	}
	$item->save();
}
$nativeOwner->save($nativeRepeater->name);
$nh = $nativeFields['heading']->name;
$nb = $nativeFields['body']->name;
$np = $nativeFields['photo']->name;
$nr = $nativeRepeater->name;
$nativeLoaded = $wire->pages->getFresh($id)->getUnformatted($nr);
$same(1, count($nativeLoaded->find("$nh^=Introduction, $nb=Other")), 'native loaded Repeater find correlates predicates per item');
$same(0, count($nativeLoaded->find("$nh^=Introduction, $nb*=ProcessWire")), 'native loaded Repeater find rejects split items like FlexRows');
$same([$id], $find("$nr.$nh^=Introduction, $nr.$nb*=ProcessWire"), 'native ungrouped Repeater SQL allows split items');
$nativeAtSplit = $find("@$nr.$nh^=Introduction, @$nr.$nb*=ProcessWire");
// Some core versions accept @ here but leave independent native predicates.
// Keep Flex's stronger correlation even where the native core does not supply it.
$assert($nativeAtSplit === [] || $nativeAtSplit === [$id], 'native @ split probe returns a bounded owner result');
echo 'EVIDENCE native @ split correlation: ' . ($nativeAtSplit ? 'not enforced' : 'enforced') . "\n";
$same([], $find("@$name.blocks.text.heading^=Introduction, @$name.blocks.text.body*=ProcessWire"), 'Flex keeps same-row guarantee regardless of native @ implementation');
$same([], $find("$nr=[$nh^=Introduction, $nb*=ProcessWire]"), 'native explicit Repeater sub-selector enforces one item');
$same([$id], $find("$nr=[$nh=Other, $nb*=ProcessWire]"), 'native explicit Repeater sub-selector positive control');
$nativeAtSame = $find("@$nr.$nh=Other, @$nr.$nb*=ProcessWire");
$same([$id], $nativeAtSame, 'native @ Repeater SQL positive control');
$nativeMedia = $find("@$nr.$nh=Gallery, @$nr.$np.count>=3");
$same([$id], $nativeMedia, 'native @ media count positive control');
$nativeNegative = $find("@$nr.$nh^=Introduction, @$nr.$nb!=ProcessWire");
$same([$id], $nativeNegative, 'native @ negative positive control');
$same([$id], $find("@$nr.$nh=, @$nr.$nb="), 'native @ blank positive control');
$same([], $find("id=$second->id, @$nr.$nh=, @$nr.$nb!=ProcessWire"), 'native positive @ blank/negative combination requires an item');
$nc = $nativeChildren->name;
$nn = $nativeFields['number']->name;
$nativeNested = $find("@$nr.$nh^=Introduction, @$nr.$nc.$nh=Z");
$assert($nativeNested === [] || $nativeNested === [$id], 'native nested @ parent probe returns a bounded owner result');
echo 'EVIDENCE native nested @ parent correlation: ' . ($nativeNested ? 'not enforced' : 'enforced') . "\n";
$nativeChildrenSplit = $find("@$nr.$nc.$nh=X, @$nr.$nc.$nn=2");
$assert($nativeChildrenSplit === [] || $nativeChildrenSplit === [$id], 'native nested @ child probe returns a bounded owner result');
echo 'EVIDENCE native nested @ child correlation: ' . ($nativeChildrenSplit ? 'not enforced' : 'enforced') . "\n";
echo 'EVIDENCE RepeaterMatrix installed: ' . (int) $wire->modules->isInstalled('FieldtypeRepeaterMatrix') . "\n";
