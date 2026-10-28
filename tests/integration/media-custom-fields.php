<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$metadata = [
	'description' => ['type' => 'textarea', 'textformatters' => []],
	'tags' => ['type' => 'checkboxes', 'options' => ['web' => 'Website', 'print' => 'Print']],
	'copyright' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
	'year' => ['type' => 'integer'], 'amount' => ['type' => 'float'],
	'decorative' => ['type' => 'checkbox'], 'toggle' => ['type' => 'toggle'],
	'usage' => ['type' => 'select', 'options' => ['web' => 'Web', 'print' => 'Print']],
	'choices' => ['type' => 'asmSelect', 'options' => ['a' => 'A', 'b' => 'B']],
	'taken_at' => ['type' => 'datetime', 'dateOutputFormat' => 'Y-m-d'],
	'credit' => ['type' => 'text', 'useLanguages' => true],
	'group' => ['type' => 'fieldset', 'children' => ['email' => ['type' => 'email'], 'link' => ['type' => 'URL'], 'keywords' => ['type' => 'TextTags']]],
];
$defs = ['gallery' => ['type' => 'image', 'maxFiles' => 0, 'customFields' => $metadata]];
$f = $createField('flex_custom_media', $defs);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/custom-media.png');
$im = imagecreatetruecolor(32, 32); imagepng($im, $fixture); imagedestroy($im);
$page->of(false);
$value = $page->get($f->name);
$value->gallery->add($fixture);
$file = $value->gallery->first();
$file->copyright = 'A & <b>B</b>';
$file->year = 0; $file->amount = 0.0; $file->decorative = false;
$file->choices = ['b', 'a']; $file->taken_at = 1700000000;
$file->credit = [$defaultLanguage->id => 'English', $language->id => 'Deutsch'];
$file->description = 'Native <b>description</b>'; $file->addTag('web');
$same(0, $file->year, 'integer zero through direct property');
$same(['b', 'a'], $file->choices, 'ordered metadata choices');
$same(1700000000, $file->taken_at, 'metadata date uses Flex timestamp runtime');
$same(null, $file->getFieldValue('unknown'), 'unknown native bridge read is null');
$same(false, $file->setFieldValue('unknown', 'x'), 'unknown native bridge write is false');
$same('web', $file->tags, 'tags retain native string');
$page->save($f->name);
$page = $wire->pages->getFresh($id); $page->of(false);
$value = $page->get($f->name); $file = $value->gallery->first();
$same(0, $file->year, 'integer zero persists');
$same(['b', 'a'], $file->choices, 'ordered choices persist');
$same('Deutsch', $file->credit->getLanguageValue($language), 'metadata languages persist');
$raw = $value->rawArray();
$assert(!isset($raw['gallery']['items'][0]['values']['description'], $raw['gallery']['items'][0]['values']['tags']), 'native slots are never duplicated');
$variation = $file->size(16, 16); $variationPath = $variation->filename();
$file->copyright = 'Changed & text';
$same('Changed & text', $variation->copyright, 'existing variation follows original metadata edits');
$throws(fn() => $variation->copyright = 'divergent', 'variation metadata writes rejected');
$throws(fn() => $variation->save(), 'variation metadata save rejected');
$file->save();
$assert(is_file($variationPath), 'ordinary metadata edit retains variations');
$same('Changed & text', $wire->pages->getFresh($id)->getUnformatted($f->name)->gallery->first()->copyright, 'file save persists custom values');
$file->copyright = 'Pending'; $file->description = 'Partial native';
$type->saveFile($page, $f, $file, ['description']);
$same('Changed & text', $wire->pages->getFresh($id)->getUnformatted($f->name)->gallery->first()->copyright, 'selective native save leaves custom changes pending');
$assert($file->isChanged(), 'partial save does not reset pending metadata');
$file->save();
$same('Pending', $wire->pages->getFresh($id)->getUnformatted($f->name)->gallery->first()->copyright, 'full save after partial persists pending metadata');
$file->copyright = 'A & B'; $page->save($f->name);
$page->of(true); $out = $page->get($f->name)->gallery->first();
$same('A &amp; B', $out->copyright, 'metadata formatter applies once');
$same('A &amp; B', $out->copyright, 'repeated metadata formatting is stable');
$same('A & B', $out->getFieldValue('copyright', false), 'native bridge explicitly reads raw values');
$throws(fn() => $out->copyright = 'forbidden', 'formatted metadata is read only');
$page->of(false);
foreach(['rows', 'image'] as $typeName) {
	$bad = $defs; $bad['gallery']['customFields'] = ['candidate' => ['type' => $typeName]];
	$throws(fn() => new FlexFieldDefs($f, $page, $bad), "unsupported metadata $typeName rejected", FlexDefinitionException::class);
}
foreach(['basename', 'url', 'width', 'assetId', 'description123', 'pagefiles', 'getFieldValue', 'id', 'sort', 'values'] as $name) {
	$bad = $defs; $bad['gallery']['customFields'] = [$name => ['type' => 'text']];
	$throws(fn() => new FlexFieldDefs($f, $page, $bad), "reserved metadata $name rejected", FlexDefinitionException::class);
}

// Independent metadata objects on collection copies and file duplication.
$page = $wire->pages->getFresh($id); $page->of(false);
$value = $page->get($f->name); $file = $value->gallery->first();
$copy = $value->gallery->clone($file);
$copy->credit->setLanguageValue($language, 'Copy only');
$same('Deutsch', $file->credit->getLanguageValue($language), 'file duplicate has independent language values');
$assert($copy->assetId() !== $file->assetId(), 'file duplicate gets independent asset identity');
$page->save($f->name);
$file->credit->setLanguageValue($language, 'Nested mutation');
$assert($file->isChanged() && $value->isChanged(), 'nested language mutation propagates through file and collection');
$file->save();
$same('Nested mutation', $wire->pages->getFresh($id)->getUnformatted($f->name)->gallery->first()->credit->getLanguageValue($language), 'file save persists nested mutation');
$clone = clone $value;
$clone->gallery->first()->credit->setLanguageValue($language, 'View only');
$same('Nested mutation', $file->credit->getLanguageValue($language), 'cloned Flex view has independent metadata objects');
$baseline = $type->storage()->persisted($page, $f);
$originalHash = hash_file('sha256', $file->filename());
$wire->database->beginTransaction();
try { $file->copyright = 'Rollback'; $page->save($f->name); }
finally { $wire->database->rollBack(); }
$same($baseline, $type->storage()->persisted($page, $f), 'outer rollback retains canonical custom metadata');
$same($originalHash, hash_file('sha256', $file->filename()), 'rollback retains original bytes');
$page = $wire->pages->getFresh($id); $page->of(false);
$stale = $page->get($f->name)->gallery->first();
$other = $wire->pages->getFresh($id); $other->of(false);
$other->get($f->name)->gallery->first()->year = 2026; $other->get($f->name)->gallery->first()->save();
$stale->year = 2025;
$throws(fn() => $stale->save(), 'stale file custom metadata is rejected');

// Ordinary metadata disappears from API but survives saves and definition loss.
$f->flexDefinition = json_encode(['gallery' => ['type' => 'image']]); $wire->fields->save($f);
$page = $wire->pages->getFresh($id); $page->of(false);
$file = $page->get($f->name)->gallery->first();
$same(null, $file->copyright, 'unknown stored custom metadata is not an API property');
$file->description = 'No custom schema'; $page->save($f->name);
$same('A & B', $type->storage()->persisted($page, $f)['gallery']['items'][0]['values']['copyright'], 'missing entire metadata schema retains values');
$f->flexDefinition = json_encode($defs); $wire->fields->save($f);
$page = $wire->pages->getFresh($id); $page->of(false);
$same('A & B', $page->get($f->name)->gallery->first()->copyright, 'reintroducing schema recovers retained custom metadata');

// Schema-aware raw maintenance enters asset values without hydrating defaults.
$renamed = $defs; unset($renamed['gallery']['customFields']['copyright']);
$renamed['gallery']['customFields']['attribution'] = ['type' => 'text', 'renamedFrom' => 'copyright'];
$f->flexDefinition = json_encode($renamed); $wire->fields->save($f);
$preview = $type->checkRenames($f);
$same(2, count($preview['renames']), 'maintenance discovers renames on both asset values');
$same('gallery.copyright', $preview['renames'][0]['from'], 'metadata maintenance uses schema path without asset identity');
$assert(str_contains($preview['renames'][0]['address'], $page->get($f->name)->gallery->first()->assetId()), 'metadata maintenance concrete address includes asset identity');
$same(1, $type->applyRenames($f, $preview)['changed'], 'raw metadata rename applies atomically');
$stored = $type->storage()->persisted($page, $f);
$same('A & B', $stored['gallery']['items'][0]['values']['attribution'], 'raw rename preserves exact scalar value');
$orphansSchema = $renamed; unset($orphansSchema['gallery']['customFields']['year'], $orphansSchema['gallery']['customFields']['amount']);
$f->flexDefinition = json_encode($orphansSchema); $wire->fields->save($f);
$orphans = $type->listOrphans($f);
$same(2, $orphans['properties']['gallery.year']['occurrences'], 'orphan inspection traverses each file');
$cleanup = $type->cleanupProperties($f, ['gallery.year'], $orphans);
$same(1, $type->cleanupProperties($f, ['gallery.year'], $cleanup, true)['changed'], 'selected metadata cleanup applies');
$stored = $type->storage()->persisted($page, $f);
$assert(!array_key_exists('year', $stored['gallery']['items'][0]['values']) && array_key_exists('amount', $stored['gallery']['items'][0]['values']), 'metadata cleanup removes only selected unknown key');
$schema = (new FlexInspection($type))->schema($f, null);
$assert(isset($schema['properties']['gallery']['customFields']['attribution']), 'discovery schema exposes metadata without Page');
$assert(!isset($schema['selectors']['gallery.attribution']), 'metadata selectors remain unsupported');

// Native per-file inputs process through the existing scoped dependency form.
$editorDefs = ['enabled' => ['type' => 'checkbox'], 'gallery' => ['type' => 'image', 'customFields' => [
	'description' => ['type' => 'textarea', 'required' => true],
	'tags' => ['type' => 'checkboxes', 'options' => ['web' => 'Web', 'print' => 'Print']],
	'decorative' => ['type' => 'checkbox'],
	'credit' => ['type' => 'text', 'showIf' => 'decorative=0', 'required' => true, 'requiredIf' => 'parent.enabled=1'],
	'choices' => ['type' => 'selectMultiple', 'options' => ['a' => 'A', 'b' => 'B']],
]]];
$f->flexDefinition = json_encode($editorDefs); $wire->fields->save($f);
$page = $wire->pages->getFresh($id); $page->of(false);
$value = $page->get($f->name);
$inputs = $value->definitions()->getInputfields($value, $f->name . '__', '', $value, null, $f->name);
$mediaInput = $inputs->getChildByName($f->name . '__gallery');
$file = $mediaInput->value->first();
$itemInputs = $mediaInput->getItemInputfields($file);
$prefix = FlexPath::inputPrefix($f->name, 'gallery.' . $file->assetId());
$same($f->name . '__enabled=1', $itemInputs->getChildByName($prefix . 'credit')->requiredIf, 'metadata parent dependency resolves to owning Flex scope');
$same($prefix . 'decorative=0', $itemInputs->getChildByName($prefix . 'credit')->showIf, 'bare dependency resolves inside same asset');
$html = $mediaInput->render();
$same(1, substr_count($html, 'name="' . $prefix . 'credit"'), 'custom control renders once');
$assert(!str_contains($html, 'name=\'description_' . $mediaInput->name . '_'), 'replaced native description control suppressed');
$post = [$f->name . '__enabled' => 1, FlexPath::controlName($f->name, 'gallery', 'present') => 1];
foreach($mediaInput->value as $index => $asset) {
	$pfx = FlexPath::inputPrefix($f->name, 'gallery.' . $asset->assetId());
	$post[FlexPath::controlName($f->name, 'gallery.' . $asset->assetId(), 'metadata')] = 1;
	$post['sort_' . $mediaInput->name . '_' . $asset->hash] = $index;
	$post[$pfx . 'description'] = 'Editor description'; $post[$pfx . 'credit'] = 'Editor credit';
	$post[$pfx . 'tags'] = ['print'];
}
$mediaInput->processInput($wire->wire(new WireInputData($post)));
$same([], $mediaInput->getErrors(), 'native metadata processing accepts valid scoped form');
$same('Editor credit', $mediaInput->value->first()->credit, 'native metadata input updates direct value');
$same('Editor description', $mediaInput->value->first()->description, 'replacement description edits native slot');
$same('print', $mediaInput->value->first()->tags, 'choice tags convert to native string');
$same([], $mediaInput->value->first()->choices, 'omitted multiselect clears on valid submission');
$same(0, $mediaInput->value->first()->decorative, 'omitted checkbox clears on valid submission');

// Full validation fails before committing, and recovery uses retained native inputs.
$badPost = $post;
foreach($mediaInput->value as $asset) $badPost[FlexPath::inputPrefix($f->name, 'gallery.' . $asset->assetId()) . 'description'] = '';
$mediaInput->processInput($wire->wire(new WireInputData($badPost)));
$assert((bool) $mediaInput->getErrors(), 'native required metadata rejects incomplete save');
$assert(str_contains($mediaInput->render(), 'Editor credit'), 'failed save retains other metadata input values');
$mediaInput->getErrors(true);
$mediaInput->processInput($wire->wire(new WireInputData($post)));
$same([], $mediaInput->getErrors(), 'native metadata can recover after required error');

// PHP array, JSON and native wrapper schemas share parsing, language modes and settings.
$nativeMeta = $wire->wire(new InputfieldWrapper());
$nativeText = $wire->modules->get('InputfieldText'); $nativeText->name = 'credit'; $nativeText->useLanguages = true; $nativeMeta->add($nativeText);
$nativeImage = $wire->modules->get('InputfieldImage'); $nativeImage->name = 'gallery'; $nativeImage->customFields = $nativeMeta;
$nativeRoot = $wire->wire(new InputfieldWrapper()); $nativeRoot->add($nativeImage);
$nativeDefs = new FlexFieldDefs($f, $page, $nativeRoot);
$same('text', $nativeDefs->property('gallery.credit')['kind'], 'native wrapper customFields compile');
$same(true, $nativeDefs->property('gallery.credit')['languages'], 'native wrapper metadata language mode resolves');
foreach([
	['description' => ['type' => 'integer']], ['tags' => ['type' => 'datetime']],
	['tags' => ['type' => 'checkboxes', 'options' => ['two words' => 'Invalid']]],
	['credit' => ['type' => 'text', 'renamedFrom' => 'description']],
	['description' => ['type' => 'text', 'useLanguages' => false]],
] as $invalid) {
	$throws(fn() => new FlexFieldDefs($f, $page, ['gallery' => ['type' => 'image', 'customFields' => $invalid]]), 'invalid native adapter declaration rejected', FlexDefinitionException::class);
}
$plainDescription = new FlexFieldDefs($f, $page, ['gallery' => ['type' => 'image', 'noLang' => true, 'customFields' => ['description' => ['type' => 'text'], 'credit' => ['type' => 'text', 'useLanguages' => true]]]]);
$same(false, $plainDescription->property('gallery.description')['languages'], 'description inherits noLang');
$same(true, $plainDescription->property('gallery.credit')['languages'], 'ordinary text has independent languages');
$throws(fn() => new FlexFieldDefs($f, $page, ['title' => ['type' => 'text', 'customFields' => []]]), 'customFields on nonmedia rejected');
foreach([null, 'bad', [1, 2], ['description' => 'duplicate'], ['tags' => 'duplicate']] as $badMap) {
	$throws(fn() => FlexMedia::validateMeta(['id' => str_repeat('a', 32), 'basename' => 'photo.png', 'values' => $badMap]), 'invalid canonical metadata map rejected');
}
$encoded = json_decode(FlexStorage::encode(['gallery' => ['_flex' => 'images:1', 'items' => [['id' => str_repeat('a', 32), 'basename' => 'photo.png', 'values' => []]]]]));
$assert($encoded->gallery->items[0]->values instanceof \stdClass, 'empty metadata property map encodes as JSON object');
$encoded = json_decode(FlexStorage::encode(['gallery' => ['_flex' => 'images:1', 'items' => [['id' => str_repeat('a', 32), 'basename' => 'photo.png', 'values' => ['choices' => []]]]]]));
$assert(is_array($encoded->gallery->items[0]->values->choices), 'empty metadata multiselect remains a JSON list');
$throws(fn() => $wire->pages->findIDs("id=$id, {$f->name}.gallery.credit=Something, include=all"), 'metadata selector explicitly rejected', PageFinderSyntaxException::class);
$throws(fn() => $wire->pages->findIDs("id=$id, sort={$f->name}.gallery.credit, include=all"), 'metadata sorting explicitly rejected', PageFinderSyntaxException::class);

// Metadata-only SQL failure rolls canonical/projection/ownership data back without touching bytes.
$page = $wire->pages->getFresh($id); $page->of(false);
$file = $page->get($f->name)->gallery->first(); $saved = $type->storage()->persisted($page, $f);
$trigger = 'flex_metadata_failure_' . bin2hex(random_bytes(3));
$wire->database->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON `{$f->table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='metadata fixture failure'");
try { $file->credit = 'SQL pending'; $throws(fn() => $file->save(), 'failed SQL metadata save rejects change', \PDOException::class); }
finally { $wire->database->exec("DROP TRIGGER $trigger"); }
$same($saved, $type->storage()->persisted($page, $f), 'failed SQL metadata save retains canonical data');
$same($originalHash, hash_file('sha256', $file->filename()), 'failed SQL metadata save retains original bytes');

// Removing an incomplete asset must not require filling its metadata first.
$inputs = $page->get($f->name)->definitions()->getInputfields($page->get($f->name), $f->name . '__', '', $page->get($f->name), null, $f->name);
$mediaInput = $inputs->getChildByName($f->name . '__gallery');
$deletePost = [FlexPath::controlName($f->name, 'gallery', 'present') => 1];
foreach($mediaInput->value as $asset) $deletePost['delete_' . $mediaInput->name . '_' . $asset->hash] = 1;
$mediaInput->processInput($wire->wire(new WireInputData($deletePost)));
$same([], $mediaInput->getErrors(), 'removing files skips required metadata validation');
$same(0, count($mediaInput->value), 'incomplete files can be removed before metadata entry');

// Native upload handoff permits blank required metadata; replacement preserves its map.
$uploadField = $createField('flex_metadata_upload', ['photo' => ['type' => 'image', 'maxFiles' => 1, 'customFields' => ['copyright' => ['type' => 'text', 'required' => true]]]]);
$page = $wire->pages->getFresh($id); $page->of(false);
$uploadValue = $page->get($uploadField->name);
$uploadInput = $uploadValue->definitions()->getInputfields($uploadValue, $uploadField->name . '__', '', $uploadValue, null, $uploadField->name)->getChildByName($uploadField->name . '__photo');
(new \ReflectionProperty(InputfieldFile::class, 'isAjax'))->setValue($uploadInput, true);
$uploadFiles = $uploadInput->value;
foreach(['first', 'replacement'] as $operation) {
	$uploadId = bin2hex(random_bytes(16));
	$basename = $type->media()->uniqueName($page, 'metadata-upload.png', $uploadId);
	$type->media()->reserve($uploadFiles, $uploadId, $basename, 'uploading');
	copy($fixture, $uploadFiles->path() . $basename);
	$uploaded = $uploadInput->processInputAddFile($basename);
	$type->media()->finishUpload($uploaded);
	$same([], $uploadInput->getErrors(), "$operation native upload permits required metadata to remain staged");
	$same($uploadId, $uploaded->assetId(), "$operation upload retains reserved identity");
	$same($operation === 'first' ? '' : 'Pending upload credit', $uploaded->copyright, "$operation upload metadata preserved at native boundary");
	$uploaded->copyright = 'Pending upload credit';
}
$same(1, count($uploadFiles), 'maxFiles=1 staged replacement retains one active file');

// Metadata save remains independent when its containing Row was removed only in memory.
$nestedField = $createField('flex_metadata_detached', ['nodes' => ['type' => 'rows', 'types' => ['item' => ['children' => ['photo' => ['type' => 'image', 'customFields' => ['credit' => ['type' => 'text']]]]]]]]);
$page = $wire->pages->getFresh($id); $page->of(false);
$root = $page->get($nestedField->name); $row = $root->nodes->addRow('item'); $row->photo->add($fixture); $page->save($nestedField->name);
$file = $row->photo->first(); $root->nodes->removeRow($row->rowId()); $file->credit = 'Independent file save';
$assert($file->save(), 'file save succeeds with unrelated pending containing-Row removal');
$same('Independent file save', $wire->pages->getFresh($id)->getUnformatted($nestedField->name)->nodes->first()->photo->first()->credit, 'file save does not publish pending Row removal');
$same(0, count($root->nodes), 'file save leaves pending in-memory removal intact');

$limited = clone $f; $limited->flexMaxDepth = 1;
$limitedDefs = new FlexFieldDefs($limited, $page, ['nodes' => ['type' => 'rows', 'types' => ['item' => ['children' => ['photo' => ['type' => 'image', 'customFields' => ['credit' => ['type' => 'text']]]]]]]]);
$assert($limitedDefs->property('nodes.item.photo.credit') !== null, 'scalar metadata does not add a Row nesting level');
$throws(fn() => new FlexFieldDefs($f, $page, ['group' => ['type' => 'fieldset', 'customFields' => []]]), 'customFields on fieldsets rejected');
$throws(fn() => new FlexFieldDefs($f, $page, ['photo' => ['type' => 'image', 'customFields' => null]]), 'explicit null customFields rejected');
