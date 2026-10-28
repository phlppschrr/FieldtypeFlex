<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$native = static function(string $typeName, array $settings = []) use ($wire, $template, &$temporaryFields): Field {
	$f = $wire->wire(new Field());
	$f->name = 'flex_traversal_' . bin2hex(random_bytes(5));
	$f->type = $wire->modules->get($typeName);
	foreach($settings as $key => $value) $f->set($key, $value);
	$temporaryFields[] = $f;
	$f->save();
	$template->fieldgroup->add($f);
	$template->fieldgroup->save();
	return $f;
};
$referenceSettings = ['derefAsPage' => 0, 'allowUnpub' => 1, 'inputfield' => 'InputfieldAsmSelect'];
$reference = $native('FieldtypePage', $referenceSettings);
$flex = $createField('flex_traversal', ['ingredient' => ['type' => 'page'] + $referenceSettings]);
$target = $second;
$target->of(false);
$target->title = 'Flour';
$target->save('title');
$page->of(false);
$page->set($reference->name, $wire->pages->newPageArray()->add($target));
$page->set($flex->name, ['ingredient' => [$target->id]]);
$page->save();
$same(
	$wire->pages->findIDs("id=$id, {$reference->name}.title=Flour"),
	$wire->pages->findIDs("id=$id, {$flex->name}.ingredient.title=Flour"),
	'root Page traversal matches native title equality'
);

$newTarget = static function(string $title, int $status = 0) use ($wire, $template, &$pages): Page {
	$p = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'flex-traversal-' . bin2hex(random_bytes(5)), 'title' => $title]);
	if($status) $p->addStatus($status);
	$p->save();
	$pages[] = $p;
	return $p;
};
$sugar = $newTarget('Sugar');
$blank = $newTarget('');
$hidden = $newTarget('Hidden Flour', Page::statusHidden);
$unpublished = $newTarget('Unpublished Flour', Page::statusUnpublished);
$compare = static function(Field $ref, Field $flex, string $suffix, string $extra = '') use ($wire, $id, $same): array {
	$extra = $extra === '' ? '' : ", $extra";
	$expected = $wire->pages->findIDs("id=$id, {$ref->name}.$suffix$extra");
	$actual = $wire->pages->findIDs("id=$id, {$flex->name}.ingredient.$suffix$extra");
	$same($expected, $actual, 'native root traversal: ' . $suffix . $extra);
	return $actual;
};
$assign = static function(Field $ref, Field $flex, array $targets) use ($wire, $page): void {
	$page->of(false);
	$values = $wire->pages->newPageArray();
	foreach($targets as $p) $values->add($p);
	$page->set($ref->name, clone $values);
	$page->set($flex->name, ['ingredient' => clone $values]);
	$page->save($ref->name);
	$page->save($flex->name);
};
foreach([0, 1, 2] as $mode) {
	$settings = array_replace($referenceSettings, ['derefAsPage' => $mode]);
	$ref = $mode === 0 ? $reference : $native('FieldtypePage', $settings);
	$f = $mode === 0 ? $flex : $createField('flex_traversal', ['ingredient' => ['type' => 'page'] + $settings]);
	foreach([[], [$target], [$sugar], [$blank], [$hidden], [$unpublished], [$target, $sugar]] as $targets) {
		$assign($ref, $f, $targets);
		foreach(['title=Flour', 'title!=Flour', 'title=Flour|Sugar', 'title!=Flour|Sugar', 'title=', 'title!=', 'title%=lou', 'title~=Flour', 'title~|=Flour Sugar', 'title^=Flo', 'title$=our', 'title=No such target'] as $suffix) {
			$compare($ref, $f, $suffix);
		}
		foreach(['', 'include=all', 'include=hidden', 'include=unpublished', 'check_access=0'] as $extra) {
			$compare($ref, $f, 'title=Unpublished Flour', $extra);
		}
	}
	$assign($ref, $f, []);
	foreach([$ref, $f] as $emptyField) {
		$stmt = $wire->database->prepare('DELETE FROM `' . $emptyField->table . '` WHERE pages_id=:owner');
		$stmt->execute(['owner' => $id]);
	}
	foreach(['title=Flour', 'title!=Flour', 'title=', 'title!='] as $suffix) $compare($ref, $f, $suffix);
}

// The target Fieldtype remains the oracle for numeric/date/choice/reference behavior.
$numeric = $native('FieldtypeInteger');
$float = $native('FieldtypeFloat', ['precision' => 2]);
$date = $native('FieldtypeDatetime');
$check = $native('FieldtypeCheckbox');
$text = $native('FieldtypeText');
$textarea = $native('FieldtypeTextarea');
$email = $native('FieldtypeEmail');
$url = $native('FieldtypeURL');
$option = $native('FieldtypeOptions');
$option->type->manager->setOptionsString($option, "1=flour|Flour\n2=sugar|Sugar");
$target->of(false);
foreach([$numeric->name => 42, $float->name => 2.75, $date->name => '2026-10-18 14:30:00', $check->name => 1, $text->name => 'Flour sugar', $textarea->name => 'Fine flour sugar', $email->name => 'flour@example.test', $url->name => 'https://example.test/flour', $option->name => 1] as $name => $value) {
	$target->set($name, $value);
	$target->save($name);
}
$assign($reference, $flex, [$target, $sugar]);
foreach([
	$numeric->name . '>40', $numeric->name . '<40', $numeric->name . '!=42',
	$float->name . '>=2.5', $check->name . '=1', $check->name . '!=1',
	$date->name . '=2026-10-18', $date->name . '>2026-10-17', $date->name . '^=2026-10', $date->name . '%=10-18',
	$text->name . '~*=flou', $text->name . '~%=flou', $text->name . '*=Flour',
	$textarea->name . '~=flour sugar', $email->name . '=flour@example.test', $url->name . '%=flour',
	$option->name . '=1', $option->name . '=flour', $option->name . '=Flour', $option->name . '!=Flour',
] as $suffix) $compare($reference, $flex, $suffix);
$terminalRef = $native('FieldtypePage', $referenceSettings);
$target->set($terminalRef->name, $wire->pages->newPageArray()->add($sugar));
$target->save($terminalRef->name);
$compare($reference, $flex, $terminalRef->name . '=' . $sugar->id);
$compare($reference, $flex, $terminalRef->name . '!=' . $sugar->id);

// Live target edits, escaped values and independent target conditions.
$target->title = 'Flour, fine';
$target->save('title');
$escaped = $wire->sanitizer->selectorValue($target->title);
$same([$id], $compare($reference, $flex, 'title=' . $escaped), 'escaped target title matches');
$target->title = 'Flour';
$target->save('title');
$same([$id], $compare($reference, $flex, 'title=Flour'), 'target edit requires no owner save');
$same(
	$wire->pages->findIDs("id=$id, {$reference->name}.title=Flour, {$reference->name}.title=Sugar"),
	$wire->pages->findIDs("id=$id, {$flex->name}.ingredient.title=Flour, {$flex->name}.ingredient.title=Sugar"),
	'independent clauses may match different targets'
);
$same(1, $wire->pages->count("id=$id, {$flex->name}.ingredient.title=Flour"), 'traversal count');
$found = $wire->pages->find("id=$id, {$flex->name}.ingredient.title=Flour, limit=1");
$same([$id], array_map('intval', $found->explode('id')), 'traversal pagination');
$same(1, $found->getTotal(), 'traversal total');

// Configured restrictions follow native custom Page traversal, not Inputfield callbacks.
foreach([
	['parent_id' => 1], ['parent_id' => $target->id],
	['template_id' => $template->id], ['template_ids' => [$template->id]],
	['template_id' => $wire->pages->get(1)->template->id],
	['findPagesSelector' => "id=$target->id, include=all"],
	['findPagesSelect' => "id=$sugar->id"],
	['findPagesSelector' => 'include=hidden'],
	['findPagesSelector' => 'parent=page.id, include=all'],
	['findPagesSelector' => 'page.parent_id=1, include=all'],
] as $restriction) {
	$settings = $restriction + $referenceSettings;
	$ref = $native('FieldtypePage', $settings);
	$f = $createField('flex_traversal', ['ingredient' => ['type' => 'page'] + $settings]);
	// Populate before comparing query restrictions, bypassing native selection validation
	// equally for both storage formats, so an excluded target remains a real test.
	$assign($reference, $flex, [$target, $unpublished]);
	$wire->database->exec('INSERT INTO `' . $ref->table . '` (pages_id,data,sort) SELECT pages_id,data,sort FROM `' . $reference->table . '`');
	$wire->database->exec('INSERT INTO `' . $f->table . '` (pages_id,data,search) SELECT pages_id,data,search FROM `' . $flex->table . '`');
	foreach(['title=Flour', 'title!=Flour', 'title=Unpublished Flour'] as $suffix) $compare($ref, $f, $suffix);
}

// Native custom traversal's target visibility is distinct from owner visibility.
$assign($reference, $flex, [$wire->pages->get(2)]);
$previousUser = $wire->user;
try {
	$wire->users->setCurrentUser($wire->users->getGuestUser());
	$compare($reference, $flex, 'title=' . $wire->sanitizer->selectorValue($wire->pages->get(2)->title), 'check_access=1');
} finally {
	$wire->users->setCurrentUser($previousUser);
}

foreach(['ingredient.title.extra=x', 'ingredient.name=x', 'ingredient.id=1', 'ingredient.unknown=x', 'ingredient.' . $flex->name . '=x'] as $path) {
	$throws(fn() => $wire->pages->findIDs("id=$id, {$flex->name}.$path"), 'unsupported traversal rejects ' . $path, PageFinderSyntaxException::class);
}
$throws(fn() => $wire->pages->findIDs("id=$id, g@{$flex->name}.ingredient.title=Flour"), 'same-target grouping rejects', PageFinderSyntaxException::class);
$throws(fn() => $wire->pages->findIDs("id=$id, !{$flex->name}.ingredient.title=Flour"), 'selector negation rejects', PageFinderSyntaxException::class);
$throws(fn() => $wire->pages->findIDs("id=$id, {$flex->name}.ingredient.title|{$flex->name}.ingredient.{$text->name}=Flour"), 'multi-dot OR fields reject', PageFinderSyntaxException::class);
$throws(fn() => $wire->pages->findIDs("id=$id, sort={$flex->name}.ingredient.title"), 'target sorting rejects', PageFinderSyntaxException::class);

$info = $type->getSelectorInfo($flex);
$assert(isset($info['subfields']['ingredient.title']), 'discovery exposes target title suffix');
$same('number', $info['subfields']['ingredient.' . $numeric->name]['input'], 'discovery uses numeric target input');
$assert(in_array('~=', $info['subfields']['ingredient.title']['operators'], true), 'target text operators remain native');
$assert(!isset($info['subfields']['ingredient.name']) && !isset($info['subfields']['ingredient.' . $flex->name]), 'discovery excludes unsupported targets');
$assert(!isset($info['subfields']['ingredient.' . $terminalRef->name . '.title']), 'discovery does not recurse through terminal Page');
$selectorInput = $wire->modules->get('InputfieldSelector');
$selectorInput->name = 'flex_traversal_selector';
$selectorInput->value = "{$flex->name}.ingredient.title=Flour, {$flex->name}.ingredient.{$terminalRef->name}=$sugar->id";
$html = $selectorInput->render();
$assert(str_contains($html, 'ingredient.title') && str_contains($html, $terminalRef->name), 'native selector renders dotted target text and Page IDs');

$toggle = $native('FieldtypeToggle');
$target->set($toggle->name, 1);
$target->save($toggle->name);
$assign($reference, $flex, [$target]);
$compare($reference, $flex, $toggle->name . '=1');
$compare($reference, $flex, $toggle->name . '!=1');
foreach(['FieldtypeTextLanguage', 'FieldtypeTextareaLanguage'] as $typeName) {
	$langField = $native($typeName, ['langBlankInherit' => 0]);
	foreach(['Mehl', ''] as $translation) {
		$target->set($langField->name, new LanguagesPageFieldValue($target, $langField, [$defaultLanguage->id => 'Flour', $language->id => $translation]));
		$target->save($langField->name);
		foreach([$defaultLanguage, $language] as $current) {
			$wire->user->language = $current;
			foreach(['=Flour', '=Mehl', '!=Mehl', '=', '!='] as $condition) $compare($reference, $flex, $langField->name . $condition);
		}
	}
}
$wire->user->language = $defaultLanguage;

$rowField = $createField('flex_traversal_rows', [
	'group' => ['type' => 'fieldset', 'children' => ['ingredient' => ['type' => 'page'] + $referenceSettings]],
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => ['ingredient' => ['type' => 'page'] + $referenceSettings]]]],
]);
$page->set($rowField->name, ['ingredient' => [$target->id]]);
$page->save($rowField->name);
$same([$id], $wire->pages->findIDs("id=$id, {$rowField->name}.ingredient.title=Flour"), 'ordinary fieldset keeps Page property at root');
$throws(fn() => $wire->pages->findIDs("id=$id, {$rowField->name}.items.item.ingredient.title=Flour"), 'row reference traversal still rejects', PageFinderSyntaxException::class);
$rowInfo = $type->getSelectorInfo($rowField);
$assert(isset($rowInfo['subfields']['ingredient.title']) && !isset($rowInfo['subfields']['items.item.ingredient.title']), 'only root Page properties discover target fields');

$restricted = $createField('flex_traversal_discovery', ['ingredient' => ['type' => 'page', 'template_id' => $wire->pages->get(1)->template->id] + $referenceSettings]);
$restrictedInfo = $type->getSelectorInfo($restricted);
$assert(isset($restrictedInfo['subfields']['ingredient.title']) && !isset($restrictedInfo['subfields']['ingredient.' . $numeric->name]), 'discovery uses explicit target template fieldgroups');

// Target matches must never inherit the selector editor's 500-option cap.
$broadGroup = $wire->wire(new Fieldgroup());
$broadGroup->name = 'flex_traversal_broad_' . bin2hex(random_bytes(4));
$broadGroup->add($wire->fields->get('title'));
$broadGroup->save();
$broadTemplate = $wire->wire(new Template());
$broadTemplate->name = $broadGroup->name;
$broadTemplate->fieldgroup = $broadGroup;
$broadTemplate->save();
$broadIds = [];
try {
	// This is a query-cardinality fixture, not a lifecycle test. Insert only the
	// native pages/title rows and remove exactly those rows below; hundreds of
	// Page save/delete hooks would otherwise dominate this selector regression.
	$insertPage = $wire->database->prepare('INSERT INTO pages (parent_id,templates_id,name,status) VALUES(1,:template,:name,1)');
	$insertTitle = $wire->database->prepare('INSERT INTO field_title (pages_id,data) VALUES(:page,:title)');
	for($i = 0; $i < 501; ++$i) {
		$insertPage->execute(['template' => $broadTemplate->id, 'name' => 'flex-broad-' . bin2hex(random_bytes(5))]);
		$lastId = (int) $wire->database->lastInsertId();
		$broadIds[] = $lastId;
		$insertTitle->execute(['page' => $lastId, 'title' => 'Flex broad target ' . $i]);
	}
	$assign($reference, $flex, [$wire->pages->get($lastId)]);
	$same([$id], $compare($reference, $flex, 'title^=Flex broad target'), 'target match beyond 500 is retained');
} finally {
	if($broadIds) {
		$ownedIds = implode(',', $broadIds);
		$wire->database->exec("DELETE FROM field_title WHERE pages_id IN($ownedIds)");
		$wire->database->exec("DELETE FROM pages WHERE id IN($ownedIds)");
	}
	$wire->templates->delete($broadTemplate);
	$wire->fieldgroups->delete($broadGroup);
}
