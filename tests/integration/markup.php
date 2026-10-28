<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$html = ['type' => 'textarea', 'contentType' => FieldtypeTextarea::contentTypeHTML,
	'htmlOptions' => [FieldtypeTextarea::htmlLinkAbstract]];
$native = $wire->wire(new Field());
$native->name = 'flex_markup_native_' . bin2hex(random_bytes(4));
$native->type = $wire->modules->get('FieldtypeTextarea');
$native->contentType = $html['contentType'];
$native->htmlOptions = $html['htmlOptions'];
$temporaryFields[] = $native;
$wire->fields->save($native);
$template->fieldgroup->add($native); $template->fieldgroup->save();
$metadata = ['body' => $html, 'translated' => ['useLanguages' => true] + $html,
	'description' => ['useLanguages' => true] + $html];
$media = ['type' => 'file', 'extensions' => 'txt', 'customFields' => $metadata];
$row = ['body' => $html, 'attachment' => $media];
$definitions = [
	'body' => $html,
	'editor' => ['type' => 'TinyMCE'] + $html,
	'inherited' => ['type' => 'textarea', 'inheritField' => $native->name],
	'overridden' => ['type' => 'textarea', 'inheritField' => $native->name, 'htmlOptions' => []],
	'plain' => ['type' => 'textarea'],
	'text' => ['type' => 'text', 'contentType' => 1, 'htmlOptions' => $html['htmlOptions']],
	'translated' => ['useLanguages' => true] + $html,
	'attachment' => $media,
	'gallery' => ['type' => 'image', 'customFields' => $metadata],
	'blocks' => ['type' => 'rows', 'types' => ['block' => ['children' => $row + [
		'children' => ['type' => 'rows', 'types' => ['child' => ['children' => ['body' => $html]]]],
	]]]],
];
$f = $createField('flex_markup', $definitions);
$name = $f->name;
$input = '<p><a href="' . $second->url . '?a=1&amp;b=2#section">Internal</a> '
	. '<a href="https://example.invalid/external/">External</a> '
	. '<a href="mailto:hello@example.invalid">Email</a><img src="/missing.png" alt=""></p>';
$page->set($native->name, $input); $page->save($native->name);
$q = $wire->database->prepare('SELECT data FROM `' . $native->getTable() . '` WHERE pages_id=:id');
$q->execute(['id' => $id]); $asleep = $q->fetchColumn();
$assert(str_contains($asleep, 'data-pwid=' . $second->id), 'native fixture abstracts the internal link');
$value = $page->getUnformatted($name);
foreach(['body', 'editor', 'inherited', 'overridden', 'plain', 'text'] as $property) $value->set($property, $input);
$value->translated = [$defaultLanguage->id => $input, $language->id => $input];
$block = $value->blocks->addRow('block', ['body' => $input, 'unknown' => $input]);
$block->setHidden(true);
$child = $block->children->addRow('child', ['body' => $input]);
$visible = $value->blocks->addRow('block', ['body' => $input]);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/markup-file.txt');
file_put_contents($fixture, 'Markup attachment');
foreach([$value->attachment, $block->attachment] as $files) {
	$files->add($fixture); $file = $files->first();
	$file->body = $input;
	$file->translated = [$defaultLanguage->id => $input, $language->id => $input];
	$file->description = [$defaultLanguage->id => $input, $language->id => $input];
}
$imageFixture = $trackFile(dirname(__DIR__, 2) . '/.dev/markup-image.png');
$image = imagecreatetruecolor(16, 16); imagepng($image, $imageFixture); imagedestroy($image);
$value->gallery->add($imageFixture); $value->gallery->first()->body = $input;
$before = $value->rawArray();
$page->save($name);
$stored = $type->storage()->persisted($page, $f);
$same($asleep, $stored['body'], 'Flex storage matches native Textarea bytes');
foreach(['editor', 'inherited'] as $property) $same($asleep, $stored[$property], "$property stores native abstracted HTML");
foreach(['plain', 'text'] as $property) $same($input, $stored[$property], "$property is not transformed");
$assert(!str_contains($stored['overridden'], 'data-pwid='), 'explicit empty htmlOptions overrides inherited link abstraction');
foreach([$defaultLanguage->id, $language->id] as $lid) $same($asleep, $stored['translated'][$lid], "language $lid is asleep");
$same($asleep, $stored['blocks']['items'][0]['values']['body'], 'hidden Row is asleep');
$same($asleep, $stored['blocks']['items'][0]['values']['children']['items'][0]['values']['body'], 'hidden descendant is asleep');
$same($asleep, $stored['gallery']['items'][0]['values']['body'], 'Image custom HTML is asleep');
$same([$id], $wire->pages->findIDs("id=$id, $name.body*=Internal"), 'root selector searches stored HTML');
$same([$id], $wire->pages->findIDs("id=$id, $name.blocks.block.body*=Internal"), 'visible Row projection searches stored HTML');
$same($input, $stored['blocks']['items'][0]['values']['unknown'], 'unknown Row text is preserved');
foreach([$stored['attachment'], $stored['blocks']['items'][0]['values']['attachment']] as $envelope) {
	$same($asleep, $envelope['items'][0]['values']['body'], 'media custom HTML is asleep');
	$same($asleep, $envelope['items'][0]['values']['translated'][$language->id], 'media language HTML is asleep');
	$assert(str_contains(json_encode($envelope['items'][0]['description']), 'data-pwid=' . $second->id), 'custom HTML description is asleep');
}
$same($before['body'], $value->body, 'saving does not sleep the live value');
$same($before['attachment']['items'][0]['values'], $value->rawArray()['attachment']['items'][0]['values'], 'saving does not sleep live metadata');

for($round = 0; $round < 3; ++$round) {
	$page = $wire->pages->getFresh($id); $page->of(false);
	$value = $page->getUnformatted($name);
	$same($page->getUnformatted($native->name), $value->body, "round $round wakes like native");
	$same($input, $value->body, "round $round has runtime URLs");
	$same($input, $value->translated->getLanguageValue($language), "round $round wakes every language");
	$same($input, $value->blocks->first()->children->first()->body, "round $round wakes hidden descendants");
	$same($input, $value->attachment->first()->body, "round $round wakes metadata");
	$same($input, $value->attachment->first()->rawDescription($language), "round $round wakes HTML descriptions");
	$assert(!$value->isChanged(), "round $round wakeup is not dirty");
	$page->trackChange($name); $page->save($name);
	$same($stored, $type->storage()->persisted($page, $f), "round $round storage is stable");
}

// The target's identity survives a path change, without writing on read.
$second->name .= '-moved'; $second->save('name');
$page = $wire->pages->getFresh($id); $page->of(false);
$value = $page->getUnformatted($name);
$awake = $value->body;
$assert(str_contains($awake, $second->url . '?a=1&amp;b=2#section'), 'moved target gets its current URL and retains query/fragment');
$same($page->getUnformatted($native->name), $awake, 'moved target matches native wakeup');
$same($stored, $type->storage()->persisted($page, $f), 'reading moved links does not write storage');
$same($awake, $value->attachment->first()->body, 'metadata link follows moved target');
$page->of(true); $same($awake, $page->get($name)->body, 'formatted HTML uses awakened URLs'); $page->of(false);
$same($awake, $value->rawArray()['body'], 'rawArray exports awakened unformatted HTML');

// A selective file save must sleep its patch and retain unrelated pending edits.
$file = $value->attachment->first();
$value->body = 'Pending root'; $file->body = $awake . '<p>Metadata edit</p>';
$type->saveFile($page, $f, $file, ['values']);
$after = $type->storage()->persisted($page, $f);
$same($stored['body'], $after['body'], 'selective file save leaves root storage untouched');
$assert(str_contains($after['attachment']['items'][0]['values']['body'], 'data-pwid=' . $second->id), 'selective file save abstracts its custom HTML');
$same($after['attachment']['items'][0], $file->baseline(), 'selective save baseline is exact storage');
$file->body .= '<p>Again</p>'; $file->save();
$assert(!$file->isChanged(), 'second metadata save succeeds and resets changes');
$page->save($name);
$same('Pending root', $wire->pages->getFresh($id)->getUnformatted($name)->body, 'field save after selective save retains pending root edit');
$value->gallery->first()->body = $awake . '<p>Image metadata</p>';
$value->gallery->first()->save();
$assert(str_contains($type->storage()->persisted($page, $f)['gallery']['items'][0]['values']['body'], 'data-pwid=' . $second->id), 'selective Image save abstracts custom HTML');

$beforeRollback = $type->storage()->persisted($page, $f);
$wire->database->beginTransaction();
try {
	$value->body = $awake . '<p>Rollback</p>'; $page->save($name);
} finally {
	$wire->database->rollBack();
}
$same($beforeRollback, $type->storage()->persisted($page, $f), 'rollback restores asleep canonical data');

// Freshly loaded stale file metadata remains a conflict after URL wakeup.
$a = $wire->pages->getFresh($id)->getUnformatted($name)->attachment->first();
$b = $wire->pages->getFresh($id)->getUnformatted($name)->attachment->first();
$a->body = 'First writer'; $a->save(); $b->body = 'Stale writer';
$throws(fn() => $b->save(), 'URL transformation preserves stale metadata rejection');

// Existing unknown values remain byte-identical when the definition disappears.
$definitions['body']['renamedFrom'] = 'old_body';
$seed = $type->storage()->persisted($page, $f); $seed['old_body'] = $seed['inherited']; unset($seed['body']);
$q = $wire->database->prepare('UPDATE `' . $f->getTable() . '` SET data=:data WHERE pages_id=:id');
$q->execute(['data' => FlexStorage::encode($seed), 'id' => $id]);
$f->flexDefinition = json_encode($definitions); $wire->fields->save($f);
$page = $wire->pages->getFresh($id); $page->of(false);
$same($awake, $page->getUnformatted($name)->body, 'renamed asleep value wakes after migration');
$page->trackChange($name); $page->save($name);
$assert(!isset($type->storage()->persisted($page, $f)['old_body']), 'normal save completes rename with abstracted HTML');
$preserved = $type->storage()->persisted($page, $f)['inherited'];
unset($definitions['inherited']); $f->flexDefinition = json_encode($definitions); $wire->fields->save($f);
$page = $wire->pages->getFresh($id); $page->of(false); $page->trackChange($name); $page->save($name);
$same($preserved, $type->storage()->persisted($page, $f)['inherited'], 'removed HTML definition preserves asleep unknown bytes');

// URL-only HTML rebases href and src through the public fieldtype lifecycle.
$urlField = $createField('flex_markup_urls', ['body' => ['htmlOptions' => []] + $html]);
$originalRoot = $wire->config->urls->root;
try {
	$wire->config->urls->root = '/old-install/';
	$urlInput = '<a href="/old-install/example/">URL</a><img src="/old-install/site/assets/example.png">';
	$nativeUrl = clone $native; $nativeUrl->htmlOptions = [];
	$urlStored = $type->sleepValue($page, $urlField, ['body' => $urlInput]);
	$same($nativeUrl->type->sleepValue($page, $nativeUrl, $urlInput), json_decode($urlStored['data'], true)['body'], 'URL-only sleep matches native under a subdirectory');
	$wire->config->urls->root = '/new-install/';
	$urlAwake = $type->wakeupValue($page, $urlField, $urlStored);
	$same(str_replace('/old-install/', '/new-install/', $urlInput), $urlAwake->body, 'wakeup rebases href and src to the current installation');
	$same($nativeUrl->type->wakeupValue($page, $nativeUrl, json_decode($urlStored['data'], true)['body']), $urlAwake->body, 'subdirectory wakeup matches native');
	$same($urlStored, $type->sleepValue($page, $urlField, $urlAwake), 'subdirectory change retains the storage representation');
} finally {
	$wire->config->urls->root = $originalRoot;
}
