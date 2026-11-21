<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

if(PHP_SAPI !== 'cli' || !isset($same, $createField, $temporaryFields)) {
	http_response_code(404);
	exit;
}
$media = $createField('flex_media', [
	'download' => [
		'type'       => 'file',
		'extensions' => 'txt pdf',
		'maxFiles'   => 2,
	],
]);
$mediaName = $media->name;
$fixtureFile = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-media-fixture.txt');
file_put_contents($fixtureFile, 'Owned Flex file fixture');
$page = $wire->pages->getFresh($id);
$page->of(false);
$files = $page->getUnformatted($mediaName)->download;
$assert($files instanceof Pagefiles, 'Flex File property exposes native Pagefiles');
$files->add($fixtureFile);
$files->first()->description = 'Fixture description';
$basename = $files->first()->basename;
$assetId = $files->rawArray()['items'][0]['id'];
$assert($page->isChanged($mediaName), 'native file mutation marks outer Flex field dirty');
$page->save($mediaName);
$files->first()->description = 'Metadata after field save';
$assert($files->first()->save(), 'same native file object can save metadata after a full field save');
$files->first()->description = 'Fixture description';
$files->first()->save();
$page = $wire->pages->getFresh($id);
$page->of(false);
$file = $page->getUnformatted($mediaName)->download->first();
$assert($file instanceof Pagefile && is_file($file->filename), 'native File roundtrip preserves physical original');
$same('Fixture description', $file->description, 'File metadata survives JSON persistence');
$same($assetId, $page->getUnformatted($mediaName)->download->rawArray()['items'][0]['id'], 'media identity survives save and reload');
$assert(str_contains($file->url, '/' . $page->id . '/'), 'File URLs use actual owner page assets');
$oldPath = $file->filename();
$oldRaw = $page->getUnformatted($mediaName)->rawArray();
$wire->database->beginTransaction();
try {
	$page->getUnformatted($mediaName)->download->remove($file);
	$page->save($mediaName);
	$assert(is_file($oldPath), 'caller-owned transaction cannot delete committed original before commit');
	$throws(fn() => $type->media()->collect(), 'collector refuses to run within an outer transaction');
} finally {
	$wire->database->rollBack();
}
$same($oldRaw, $wire->pages->getFresh($id)->getUnformatted($mediaName)->rawArray(), 'outer rollback restores media metadata');
$assert(is_file($oldPath), 'outer rollback retains original bytes');
$page = $wire->pages->getFresh($id);
$page->of(false);
$wire->database->beginTransaction();
try {
	$files = $page->getUnformatted($mediaName)->download;
	$files->add($fixtureFile);
	$stagedPath = $files->last()->filename();
	$stagedId = $files->last()->assetId();
	$page->save($mediaName);
} finally {
	$wire->database->rollBack();
}
$assert(is_file($stagedPath) && $type->media()->asset($stagedId) !== null, 'staged ownership survives caller transaction rollback');
$assert(count($wire->pages->getFresh($id)->getUnformatted($mediaName)->download) === 1, 'rolled-back staged file is not committed content');
$page = $wire->pages->getFresh($id);
$page->of(false);
$page->getUnformatted($mediaName)->download->removeAll();
$page->save($mediaName);
$assert(!is_file($oldPath), 'successful media removal deletes only after canonical commit');
$same(null, $type->media()->asset($assetId), 'successful cleanup releases owned asset record');
$media->flexDefinition = json_encode([
	'download' => [
		'type'       => 'file',
		'extensions' => 'txt pdf',
		'maxFiles'   => 2,
	],
	'note' => ['type' => 'text'],
	'hero' => [
		'type'     => 'image',
		'maxFiles' => 1,
	],
	'slides' => [
		'type'       => 'rows',
		'types'      => [
			'item' => [
				'label'    => 'Item',
				'children' => [
					'title' => ['type' => 'text'],
					'photo' => ['type' => 'image'],
				],
			],
		],
	],
]);
$media->save();
$imageFixture = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-media-fixture.png');
$image = imagecreatetruecolor(80, 60);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 170));
imagepng($image, $imageFixture);
imagedestroy($image);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$value->hero->add($imageFixture);
$value->hero->first()->description = 'Hero image';
$value->hero->first()->filedata('fixture', [
	'zeta'    => 1,
	'alpha'   => true,
	'ordered' => ['second', 'first'],
]);
$imageAssetId = $value->hero->first()->assetId();
$imagePath = $value->hero->first()->filename();
$row = $value->slides->addRow('item', ['title' => 'Photo row']);
$row->photo->add($imageFixture);
$page->save($mediaName);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$assert($value->hero instanceof Pageimages && $value->hero->first() instanceof Pageimage, 'Image properties expose native Pageimages and Pageimage');
$same(80, $value->hero->first()->width(), 'native image dimensions survive roundtrip');
$thumb = $value->hero->first()->size(32, 24);
$assert(is_file($thumb->filename()) && $thumb->width() === 32, 'native Pageimage variation API works');
$value->note = 'Keep dirty';
$value->hero->resetTrackChanges();
$assert($page->isChanged($mediaName), 'media collection reset cannot erase an earlier scalar edit');
$page->save($mediaName);
$page = $wire->pages->getFresh($id);
$page->of(true);
$image = $page->get($mediaName)->hero;
$assert($image instanceof FlexPageimage, 'default Auto single-image output returns a FlexPageimage');
if($image) {
	$imageUrl = $image->size(24, 18)->url;
	$assert(is_string($imageUrl) && $imageUrl !== '', 'single-image size example returns a variation URL');
}
$formattedImage = $page->get($mediaName)->hero->size(24, 18);
$assert(is_file($formattedImage->filename()), 'formatted image reads can create native variations');
$page->of(false);
$value = $page->getUnformatted($mediaName);
$sourceRow = $value->slides->first();
$sourcePhoto = $sourceRow->photo->first();
$duplicate = $value->slides->duplicateRow($sourceRow->rowId());
$page->save($mediaName);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$assert($value->slides->first()->photo->first()->assetId() !== $value->slides->last()->photo->first()->assetId(), 'row duplication creates independent media identity');
$assert($value->slides->first()->photo->first()->filename() !== $value->slides->last()->photo->first()->filename(), 'row duplication copies physical media instead of sharing it');
$same([$id], $wire->pages->findIDs("id=$ids, $mediaName.hero.count=1"), 'root media count selector matches committed files');
$same([$second->id], $wire->pages->findIDs("id=$ids, $mediaName.hero.count=0"), 'absent root media count is zero');
$same([$id], $wire->pages->findIDs("id=$ids, $mediaName.slides.item.photo.count>0"), 'row media count selector resolves complete nested path');
$same([$id], $wire->pages->findIDs("id=$ids, @$mediaName.slides.item.title=Photo row, @$mediaName.slides.item.photo.count>0"), 'same-row matching supports media counts');
$same([$second->id, $id], $wire->pages->findIDs("id=$ids, sort=$mediaName.hero.count"), 'media count sorting');
$same('number', $type->getSelectorInfo($media)['subfields']['hero.count']['input'], 'media count exposed to selector discovery');
$manifestProperty = (new \ReflectionClass($type->media()))->getProperty('manifest');
$manifestProperty->setValue($type->media(), []);
$pdo = $wire->database->pdo('writer');
$selectsBefore = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetchColumn(1);
$hydrated = new FlexValue($page, $media, $value->definitions(), $value->rawArray());
$selectsAfter = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetchColumn(1);
$assert($selectsAfter-$selectsBefore <= 2, 'multi-row media hydration batches ownership reads instead of querying each file');
$pictures = $value->slides->first()->photo;
$original = $pictures->first();
$originalId = $original->assetId();
$pictureCopy = $pictures->clone($original);
$same(2, count($pictures), 'native collection clone appends an independent file');
$same($originalId, $pictures->first()->assetId(), 'collection clone retains original identity and object');
$assert($pictureCopy->assetId() !== $originalId && is_file($original->filename()), 'collection clone allocates independent bytes before inserting a basename key');
foreach([Inputfield::collapsedHidden, Inputfield::collapsedNoLocked, Inputfield::collapsedBlankLocked, Inputfield::collapsedYesLocked, Inputfield::collapsedTabLocked] as $collapsed) {
	$locked = clone $media;
	$lockedDefinition = json_decode($locked->flexDefinition, true);
	$lockedDefinition['slides']['collapsed'] = $collapsed;
	$locked->flexDefinition = json_encode($lockedDefinition);
	$lockedValue = new FlexValue($page, $locked, $type->getDefinitions($locked, $page), $value->rawArray());
	$lockedToken = $type->admin()->open($lockedValue, $mediaName);
	$throws(fn() => $type->admin()->target($lockedToken, 'slides.' . $lockedValue->slides->first()->rowId() . '.photo', $lockedValue), 'scoped media target rejects hidden/locked ancestor state ' . $collapsed);
	$type->admin()->forget($lockedToken);
	$locked->collapsed = $collapsed;
	// AJAX/image actions check the outer field before resolving a scoped target.
	$guard = new \ReflectionMethod(FlexAdmin::class, 'editable');
	$throws(fn() => $guard->invoke($type->admin(), $page, $locked), 'admin action guard rejects hidden/locked outer field state ' . $collapsed);
}
// Replay the native upload handoff: reserve, normalize, write bytes, add basename.
foreach(['Äpfel & Öl.png', 'photo.__detail.png', str_repeat('a', 150) . '.png'] as $uploadName) {
	$uploadValue = new FlexValue($page, $media, $value->definitions(), []);
	$uploadFiles = $uploadValue->hero;
	$uploadFiles->draft($type->admin()->open($uploadValue, $mediaName));
	$uploadId = bin2hex(random_bytes(16));
	$reservedName = $type->media()->uniqueName($page, $uploadName, $uploadId);
	$type->media()->reserve($uploadFiles, $uploadId, $reservedName, 'uploading');
	$nativeUpload = $wire->wire(new WireUpload('flex_upload_test'));
	$nativeName = $nativeUpload->validateFilename($reservedName, ['png']);
	$nativePath = $trackFile($page->filesManager()->path() . $nativeName);
	try {
		copy($imageFixture, $nativePath);
		$uploadFiles->add($nativeName);
		$type->media()->finishUpload($uploadFiles->first());
		$same($uploadId, $uploadFiles->first()->assetId(), 'native upload filename retains its reserved Flex identity');
		$same($reservedName, $nativeName, 'reserved filename survives native upload normalization');
	} finally {
		$type->media()->abandon($uploadId, $page, $media, $uploadFiles->draft());
	}
}
$nativeTree = $value->definitions()->getInputfields($value, $mediaName . '__');
$uploadInput = $nativeTree->getChildByName($mediaName . '__hero');
$savedUploads = $_FILES;
try {
	$_FILES[$uploadInput->name] = [
		'name'     => ['fixture.png'],
		'error'    => [UPLOAD_ERR_OK],
		'tmp_name' => [$imageFixture],
		'size'     => [filesize($imageFixture)],
	];
	$multipartPost = [FlexPath::controlName($uploadInput->flexInputName ?: $uploadInput->name, $uploadInput->value->address(), 'present') => 1];
	$uploadInput->processInput($wire->wire(new WireInputData($multipartPost)));
	$assert(count($uploadInput->getErrors()) > 0, 'ordinary multipart upload is rejected before native byte writes');
} finally {
	$_FILES = $savedUploads;
}
// A full draft is rejected by the native raw upload handler before it reads the body.
$savedGet = $wire->input->get->getArray();
$savedPost = $wire->input->post->getArray();
$savedServer = $_SERVER;
$wire->input->get->set('InputfieldFileAjax', 1);
$limited = $createField('flex_upload_limit', [
	'files' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 3],
]);
$pending = $page->getUnformatted($limited->name);
$token = $type->admin()->open($pending, $limited->name);
try {
	$pending->files->draft($token);
	for($i = 0; $i < 3; $i++) $pending->files->add($fixtureFile);
	$type->admin()->stageMedia($token, 'files', $pending->files);
	$expectedMedia = $type->admin()->draft($token)['media']['files'];
	$wire->input->post->removeAll()->setArray([
		$wire->session->CSRF->getTokenName() => $wire->session->CSRF->getTokenValue(),
	]);
	$_SERVER['HTTP_X_FLEX_DRAFT'] = $token;
	$_SERVER['HTTP_X_FIELDNAME'] = $limited->name;
	$_SERVER['HTTP_X_FLEX_PATH'] = 'files';
	$_SERVER['HTTP_X_FILENAME'] = 'fourth.txt';
	ob_start();
	try {
		$type->admin()->ajax(new HookEvent(['arguments' => [$page]]));
		$response = json_decode(ob_get_contents(), true, 64, JSON_THROW_ON_ERROR);
	} finally {
		ob_end_clean();
	}
	$same(['error' => true, 'message' => 'Max file upload limit reached'], $response, 'fourth upload returns the native maximum-file error');
	$same($expectedMedia, $type->admin()->draft($token)['media']['files'], 'rejected upload preserves the three already staged files');
	$same(0, count($wire->pages->getFresh($id)->getUnformatted($limited->name)->files), 'maximum-file error does not write canonical media');
} finally {
	$type->admin()->forget($token);
	$wire->input->get->removeAll()->setArray($savedGet);
	$wire->input->post->removeAll()->setArray($savedPost);
	$_SERVER = $savedServer;
}
// Targeted metadata saves, conflict checks and directory cloning.
$assert(FlexStorage::revision([
	'filedata' => [
		1 => 'b',
		0 => 'a',
	],
]) !== FlexStorage::revision(['filedata' => ['a', 'b']]), 'numeric-key JSON objects remain distinct from ordered lists in revisions');
$same(FlexStorage::revision([
	'filedata' => (object) [
		'zeta'  => 1,
		'alpha' => true,
	],
]), FlexStorage::revision([
	'filedata' => (object) [
		'alpha' => true,
		'zeta'  => 1,
	],
]), 'native JSON object metadata revisions ignore key order');
$reverseObjects = static function(array $raw) use (&$reverseObjects): array {
	foreach($raw as $key => $item) {
		if(is_array($item)) {
			$raw[$key] = $reverseObjects($item);
		}
	}
	if(!array_is_list($raw)) {
		krsort($raw, SORT_STRING);
	}
	return $raw;
};
$storedRaw = $reverseObjects($type->storage()->persisted($page, $media));
$mediaTable = $wire->database->escapeTable($media->table);
$wire->database->prepare("UPDATE `$mediaTable` SET data=:data WHERE pages_id=:page")->execute([
	'data' => json_encode((object) $storedRaw, JSON_THROW_ON_ERROR),
	'page' => $id,
]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$firstImage = $value->hero->first();
$value->note = 'Unpublished scalar edit';
$firstImage->description = 'Metadata only';
$assert($firstImage->save(), 'native Pagefile save bridge persists Flex metadata');
$fresh = $wire->pages->getFresh($id)->getUnformatted($mediaName);
$same('Keep dirty', $fresh->note, 'targeted metadata save does not publish another pending scalar');
$same('Metadata only', $fresh->hero->first()->description, 'targeted metadata save reaches canonical JSON');
$assert(!$firstImage->isChanged(), 'reordered JSON object keys do not leave committed file metadata dirty');
$assert(is_file($thumb->filename()), 'reordered filedata keys retain existing native image variations');
$stale = $wire->pages->getFresh($id);
$stale->of(false);
$staleFile = $stale->getUnformatted($mediaName)->hero->first();
$firstImage->description = 'New metadata';
$firstImage->save();
$staleFile->description = 'Stale metadata';
$throws(fn() => $staleFile->save(), 'stale single-file metadata save cannot overwrite a newer revision');
$firstImage->tags = 'Pending tags';
$firstImage->description = 'Partial metadata';
$type->saveFile($page, $media, $firstImage, ['description']);
$assert($firstImage->isChanged(), 'partial metadata save keeps unsaved metadata dirty');
$firstImage->save();
$same('Pending tags', $wire->pages->getFresh($id)->getUnformatted($mediaName)->hero->first()->tags, 'partial metadata baseline permits saving remaining columns');
$staleFile = $wire->pages->getFresh($id)->getUnformatted($mediaName)->hero->first();
$firstImage->filedata('fixture', [
	'zeta'    => 1,
	'alpha'   => true,
	'ordered' => ['first', 'second'],
]);
$firstImage->save();
$staleFile->description = 'List order is significant';
$throws(fn() => $staleFile->save(), 'filedata list reordering remains a metadata conflict');
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$value->expectedRevision(FlexStorage::revision($value->rawArray()));
$value->note = 'Order-independent revision';
$page->save($mediaName);
$same('Order-independent revision', $wire->pages->getFresh($id)->getUnformatted($mediaName)->note, 'typed field revision accepts reordered JSON object keys');
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$value->expectedRevision(FlexStorage::revision($value->rawArray()));
$other = $wire->pages->getFresh($id);
$other->of(false);
$other->getUnformatted($mediaName)->hero->first()->description = 'Concurrent update';
$other->getUnformatted($mediaName)->hero->first()->save();
$value->note = 'Must conflict';
$throws(fn() => $page->save($mediaName), 'final field transaction rejects a revision changed after form validation');
$same('Concurrent update', $wire->pages->getFresh($id)->getUnformatted($mediaName)->hero->first()->description, 'atomic draft conflict preserves the concurrent metadata save');
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$value->download->add($fixtureFile);
$abandoned = $value->download->last();
$copy = $wire->pages->clone($wire->pages->getFresh($id));
$pages[] = $copy;
$copy->of(false);
$copyValue = $copy->getUnformatted($mediaName);
$assert($copyValue->hero->first()->assetId() !== $value->hero->first()->assetId(), 'page clone creates target asset identities (source=' . $id . ', copy=' . $copy->id . ', object owner=' . $copyValue->ownerPage()->id . ')');
$assert(is_file($copyValue->hero->first()->filename()) && $copyValue->hero->first()->page->id === $copy->id, 'page clone owns copied originals on the target');
$same('committed', $type->media()->asset($copyValue->hero->first()->assetId())['state'], 'page clone finalizes ownership after native directory copy');
$assert(!is_file($copy->filesManager()->path() . $abandoned->basename()) && is_file($abandoned->filename()), 'page clone excludes registered abandoned drafts without deleting source bytes');
$cloneFile = $copyValue->hero->first();
$wire->database->prepare("UPDATE fieldtype_flex_assets SET state='cloning',expires=:expires WHERE asset_id=:id")->execute([
	'expires' => time()+21600,
	'id'      => $cloneFile->assetId(),
]);
unlink($cloneFile->filename());
$throws(fn() => $type->media()->cloned($page, $copy), 'incomplete native directory copy cannot be finalized as a successful clone');
$assert(is_file($value->hero->first()->filename()), 'clone failure retains source original');
$same(1, $type->repairClonedFiles($copy), 'clone repair copies only the missing registered original');
$assert(is_file($cloneFile->filename()) && $type->media()->asset($cloneFile->assetId(), true)['state'] === 'committed', 'clone repair finalizes independent target ownership');
$wire->database->beginTransaction();
try {
	// An empty clone must not lock the ownership index range needed by a later clone.
	$emptyRolledClone = $wire->pages->clone($wire->pages->getFresh($second->id));
	$rolledClone = $wire->pages->clone($wire->pages->getFresh($id));
	$rolledClonePath = $rolledClone->filesManager()->path();
	$rolledCloneId = $rolledClone->id;
	$rolledFile = $rolledClone->getUnformatted($mediaName)->hero->first();
	$rolledAsset = $rolledFile->assetId();
	$rolledRecord = $type->media()->asset($rolledAsset, true);
	$rolledData = json_decode($rolledRecord['data'], true);
	$same($rolledClonePath, $wire->config->paths->files . $rolledData['directory'], 'clone journal records the final target directory before bytes are copied');
	$rolledVariation = $rolledFile->size(17, 13)->filename();
} finally {
	$wire->database->rollBack();
}
$wire->pages->uncache($rolledClone);
$wire->pages->uncache($emptyRolledClone);
$assert($type->media()->asset($rolledAsset, true)['state'] === 'cloning', 'clone staging journal survives rollback of the new owner page');
$wire->database->prepare("UPDATE fieldtype_flex_assets SET expires=:time WHERE pages_id=:page AND state='cloning'")->execute([
	'time' => time()-1,
	'page' => $rolledCloneId,
]);
$cloneCleanup = $type->media()->collect(1000);
$assert(!is_file($rolledClonePath . $rolledFile->basename()) && !is_file($rolledVariation) && $type->media()->asset($rolledAsset, true) === null, 'expired rolled-back clone cleanup removes registered originals and native variations');
$assert(is_file($value->hero->first()->filename()), 'rolled-back clone cleanup cannot remove source originals');

$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$before = $value->rawArray();
$oldImage = $value->hero->first();
$oldPath = $oldImage->filename();
$oldHash = hash_file('sha256', $oldPath);
$triggerName = 'flex_media_failure_' . bin2hex(random_bytes(3));
$wire->database->exec("CREATE TRIGGER $triggerName BEFORE UPDATE ON fieldtype_flex_assets FOR EACH ROW BEGIN IF NEW.fields_id=" . (int) $media->id . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture asset failure'; END IF; END");
try {
	$type->media()->transform($oldImage, 'rotate90');
	$assert(is_file($oldPath) && hash_file('sha256', $oldPath) === $oldHash, 'image rotation stages bytes and retains committed original before SQL');
	$throws(fn() => $page->save($mediaName), 'asset sync failure rejects image replacement', \PDOException::class);
	$same($before, $wire->pages->getFresh($id)->getUnformatted($mediaName)->rawArray(), 'failed image save restores canonical metadata');
	$same($oldHash, hash_file('sha256', $oldPath), 'failed image save preserves original bytes');
} finally {
	$wire->database->exec("DROP TRIGGER $triggerName");
}
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($mediaName);
$token = $type->admin()->open($value, $mediaName);
$staged = new FlexImages($page, $media, 'hero', $value->definitions()->property('hero'));
$staged->draft($token);
$staged->add($imageFixture);
$type->admin()->stageMedia($token, 'hero', $staged);
$editor = $media->getInputfield($page);
$editor->value = $value;
$post = [
	$mediaName . '__0draft'                                    => $token,
	$mediaName . '__0complete'                                    => $token,
	FlexPath::controlName($mediaName, 'hero', 'present')                            => '1',
	'sort_' . $mediaName . '__hero_' . $staged->first()->hash => '0',
];
$editor->processInput($wire->wire(new WireInputData($post)));
$same([], $editor->getErrors(), 'ordinary form accepts the addressed staged image with unchanged metadata');
$same($staged->first()->assetId(), $editor->value->hero->first()->assetId(), 'accepted media input publishes staged content even when native metadata is unchanged');
$page->{$mediaName} = $editor->value;
$page->save($mediaName);
$same($staged->first()->assetId(), $wire->pages->getFresh($id)->getUnformatted($mediaName)->hero->first()->assetId(), 'staged image survives canonical save and reload');

$newPage = $wire->pages->newPage([
	'template' => $template,
	'parent'   => 1,
	'name'     => 'flex-media-unsaved-' . bin2hex(random_bytes(4)),
	'title'    => 'Unsaved media owner',
]);
$newPage->of(false);
$newPage->{$mediaName} = $page->getUnformatted($mediaName);
$newPage->save();
$pages[] = $newPage;
$assert(is_file($newPage->getUnformatted($mediaName)->hero->first()->filename()) && $newPage->getUnformatted($mediaName)->hero->first()->assetId() !== $page->getUnformatted($mediaName)->hero->first()->assetId(), 'assignment to an unsaved owner defers an independent media copy until save');

// Lazy schema evolution and omitted definitions preserve IDs, bytes and ownership.
$page = $wire->pages->getFresh($id);
$page->of(false);
$oldHero = $page->getUnformatted($mediaName)->hero->first();
$renamedId = $oldHero->assetId();
$renamedPath = $oldHero->filename();
$definition = json_decode($media->flexDefinition, true);
$definition['banner'] = $definition['hero'] + ['renamedFrom' => 'hero'];
unset($definition['hero']);
$media->flexDefinition = json_encode($definition);
$media->save();
$page->getUnformatted($mediaName)->note = 'Apply rename';
$page->save($mediaName);
$page = $wire->pages->getFresh($id);
$page->of(false);
$same($renamedId, $page->getUnformatted($mediaName)->banner->first()->assetId(), 'media property rename preserves asset identity');
$assert(is_file($renamedPath) && $type->media()->asset($renamedId, true)['address'] === 'banner', 'media rename changes address without moving physical bytes');
unset($definition['banner']);
$media->flexDefinition = json_encode($definition);
$media->flexPrune = 0; // Legacy metadata is ignored.
$media->save();
$page->getUnformatted($mediaName)->note = 'Temporary omission';
$page->save($mediaName);
$assert(isset($type->storage()->persisted($page, $media)['banner']) && is_file($renamedPath), 'omitted media and ownership are always retained');
$omittedCopy = $wire->pages->clone($wire->pages->getFresh($id));
$pages[] = $omittedCopy;
$omittedRaw = $type->storage()->persisted($omittedCopy, $media);
$assert($omittedRaw['banner']['items'][0]['id'] !== $renamedId && is_file($omittedCopy->filesManager()->path() . $omittedRaw['banner']['items'][0]['basename']), 'native page clone rebinds omitted media');
$media->flexPrune = 1;
$media->save();
$page = $wire->pages->getFresh($id);
$page->of(false);
$page->getUnformatted($mediaName)->note = 'Legacy setting cannot prune omitted image';
$page->save($mediaName);
$assert(isset($type->storage()->persisted($page, $media)['banner']) && is_file($renamedPath), 'legacy prune=true cannot remove omitted media');
$stagedFiles = $page->getUnformatted($mediaName)->download;
$stagedFiles->add($fixtureFile);
$expired = $stagedFiles->last();
$wire->database->prepare('UPDATE fieldtype_flex_assets SET expires=:time WHERE asset_id=:id')->execute([
	'time' => time()-1,
	'id'   => $expired->assetId(),
]);
$type->media()->collect(100, $page, $media);
$assert(!is_file($expired->filename()) && $type->media()->asset($expired->assetId(), true) === null, 'bounded collector expires uncommitted owned imports');

// A registered staged asset can outlive its field's physical table.
$orphanField = $createField('flex_media_orphan', ['download' => ['type' => 'file', 'extensions' => 'txt']]);
$orphanValue = $type->getBlankValue($page, $orphanField);
$orphanValue->download->add($fixtureFile);
$orphanFile = $orphanValue->download->first();
$wire->database->prepare('UPDATE fieldtype_flex_assets SET expires=:time WHERE asset_id=:id')->execute([
	'time' => time()-1,
	'id' => $orphanFile->assetId(),
]);
$orphanTable = $wire->database->escapeTable($orphanField->table);
$orphanSnapshot = 'flex_orphan_' . bin2hex(random_bytes(4));
$wire->database->exec("RENAME TABLE `$orphanTable` TO `$orphanSnapshot`");
try {
	$cleanup = $type->media()->collect(100, $page, $orphanField);
	$same([], $cleanup['failed'], 'collector handles a missing field table without a failed cleanup');
	$assert(!is_file($orphanFile->filename()) && $type->media()->asset($orphanFile->assetId(), true) === null, 'collector removes expired registered bytes when the field table is absent');
} finally {
	$wire->database->exec("RENAME TABLE `$orphanSnapshot` TO `$orphanTable`");
}

// Replacing an owned file preserves collection order, and formatted slice() works without write errors.
$orderField = $createField('flex_media_order', ['docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 0]]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$orderDocs = $page->getUnformatted($orderField->name)->docs;
$orderDocs->add($fixtureFile);
$orderDocs->add($fixtureFile);
$orderDocs->add($fixtureFile);
$page->save($orderField->name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$orderDocs = $page->getUnformatted($orderField->name)->docs;
$beforeOrder = array_values(array_map(static fn($f) => $f->basename(), $orderDocs->getArray()));
$replacedName = $orderDocs->replaceOwned($orderDocs->first(), $fixtureFile, 'replaced-first.txt');
$afterOrder = array_values(array_map(static fn($f) => $f->basename(), $orderDocs->getArray()));
$same([$replacedName, $beforeOrder[1], $beforeOrder[2]], $afterOrder, 'replaceOwned preserves file position instead of moving item to end');
$page->save($orderField->name);
$page->of(true);
$formattedOrderDocs = $page->get($orderField->name)->docs;
$slicedMedia = $formattedOrderDocs->slice(0, 2);
$same(2, count($slicedMedia), 'formatted FlexFiles::slice() returns requested items without triggering write guards');
$same([$replacedName, $beforeOrder[1]], array_values(array_map(static fn($f) => $f->basename(), $slicedMedia->getArray())), 'formatted FlexFiles::slice() preserves order');
$assert($slicedMedia->first()->pagefiles === $slicedMedia, 'formatted FlexFiles::slice() binds sliced files to the slice collection');
$page->of(false);
$rawOrderDocs = $page->getUnformatted($orderField->name)->docs;
$rawSlice = $rawOrderDocs->slice(0, 2);
$assert($rawSlice->first()->pagefiles === $rawSlice, 'unformatted FlexFiles::slice() binds sliced files to the slice collection');
$rawSlice->remove($rawSlice->first());
$same(1, count($rawSlice), 'unformatted FlexFiles::slice() supports remove() on its own sliced item');
$same(3, count($rawOrderDocs), 'removing from FlexFiles::slice() leaves source collection intact');
$rawSlice->first()->unlink();
$same(0, count($rawSlice), 'unlink() on sliced file mutates the slice collection');
$same(3, count($rawOrderDocs), 'unlink() on sliced file leaves source collection intact');

// C-01: Lowering maxFiles below existing stored files does not lock out loading, caps formatted output, and blocks new additions.
$orderField->flexDefinition = json_encode(['docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 2]]);
$orderField->save();
$reloadedOver = $wire->pages->getFresh($id);
$reloadedOver->of(false);
$same(3, count($reloadedOver->getUnformatted($orderField->name)->docs), 'lowering maxFiles still hydrates all existing stored files without lockout');
$throws(fn() => $reloadedOver->getUnformatted($orderField->name)->docs->add($fixtureFile), 'adding above lowered maxFiles is still rejected');
$reloadedOver->of(true);
$same(2, count($reloadedOver->get($orderField->name)->docs), 'formatted output caps collection to lowered maxFiles');

// C-11: clone() ignores unknown options and supports target pagefiles; saveFile() ignores unknown columns.
$cloneTargetField = $createField('flex_media_clone_target', ['docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 0]]);
$nativeFileField = new Field();
$nativeFileField->type = $wire->modules->get('FieldtypeFile');
$nativeFileField->name = 'flex_native_clone_' . substr(bin2hex(random_bytes(3)), 0, 6);
$nativeFileField->extensions = 'txt';
$nativeFileField->maxFiles = 0;
$wire->fields->save($nativeFileField);
$template->fieldgroup->add($nativeFileField);
$template->fieldgroup->save();
try {
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$srcDocs = $page->getUnformatted($orderField->name)->docs;
	$dstDocs = $page->getUnformatted($cloneTargetField->name)->docs;
	$clonedToOther = $srcDocs->clone($srcDocs->first(), ['pagefiles' => $dstDocs, 'unknownOption' => true]);
	$same(1, count($dstDocs), 'clone() with target pagefiles copies into the target Flex collection and ignores unknown options');
	$assert($clonedToOther->pagefiles === $dstDocs, 'cross-collection clone returns file owned by target collection');
	// P1: Cloning a Flex file into a native Pagefiles field on the SAME page must create a distinct physical file, keep metadata and honor action.
	$srcFile = $srcDocs->first();
	$srcFile->description = 'Native clone desc';
	$srcFile->tags = 'alpha beta';
	$nativeTarget = $page->getUnformatted($nativeFileField->name);
	$nativeTarget->add($fixtureFile);
	$nativeClone = $srcDocs->clone($srcFile, ['pagefiles' => $nativeTarget, 'action' => 'prepend']);
	$assert($nativeClone instanceof Pagefile && $nativeClone->pagefiles === $nativeTarget, 'native clone returns the created native Pagefile');
	$assert($nativeClone->filename() !== $srcFile->filename(), 'native clone on the same page uses a distinct physical path');
	$assert(is_file($nativeClone->filename()) && is_file($srcFile->filename()), 'both Flex original and native clone exist independently on disk');
	$same($nativeClone->basename(), $nativeTarget->first()->basename(), 'native clone honors action=prepend');
	$same('Native clone desc', $nativeClone->description, 'native clone preserves description metadata');
	$same('alpha beta', $nativeClone->tags, 'native clone preserves tags metadata');
	$nativeTarget->delete($nativeClone);
	$assert(is_file($srcFile->filename()), 'deleting native clone on the same page leaves Flex original intact');
	// P2: Bulk media assignment enforces maxFiles for newly introduced files.
	$singleCapField = $createField('flex_media_bulk_cap', ['docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 1]]);
	$throws(fn() => $page->getUnformatted($singleCapField->name)->set('docs', $srcDocs), 'bulk media assignment above maxFiles is rejected');
	$page->save($cloneTargetField->name);
	// P2: Lowered maxFiles=1 with >1 existing files must not wipe all existing files on upload.
	$orderField->flexDefinition = json_encode(['docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 1]]);
	$orderField->save();
	$uploadPage = $wire->pages->getFresh($id);
	$uploadPage->of(false);
	$uploadCollection = $uploadPage->getUnformatted($orderField->name)->docs;
	$same(3, count($uploadCollection), 'lowered maxFiles=1 still loads all 3 existing files');
	$mediaInput = $wire->wire(new InputfieldFlexFile());
	$mediaInput->name = 'docs';
	$mediaInput->maxFiles = 1;
	$mediaInput->extensions = 'txt';
	$mediaInput->value = $uploadCollection;
	$extraUpload = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-over-cap-' . bin2hex(random_bytes(4)) . '.txt');
	file_put_contents($extraUpload, 'extra upload');
	$throws(fn() => $mediaInput->processInputAddFile($extraUpload), 'upload onto lowered maxFiles=1 collection with >1 files is rejected instead of wiping all existing files');
	$same(3, count($uploadCollection), 'rejected upload leaves all existing files intact');
} finally {
	$template->fieldgroup->remove($nativeFileField);
	$template->fieldgroup->save();
	$wire->fields->delete($nativeFileField);
}
$targetPage = $wire->pages->getFresh($id);
$targetPage->of(false);
$savedTargetFile = $targetPage->getUnformatted($cloneTargetField->name)->docs->first();
$savedTargetFile->description = 'Updated via partial columns';
$same(true, $type->saveFile($targetPage, $cloneTargetField, $savedTargetFile, ['description', 'nonexistent_col']), 'saveFile() ignores unknown column names like FieldtypeFile');
$same('Updated via partial columns', $wire->pages->getFresh($id)->getUnformatted($cloneTargetField->name)->docs->first()->rawDescription(), 'saveFile() persists supported columns when unknown columns are also passed');

// 6.1: FlexFiles::add() and FlexImages::add() support remote HTTP(S) URLs via native WireHttp::download().
$httpDir = $wire->files->tempDir('FlexHttpTest-' . bin2hex(random_bytes(4)))->get();
mkdir($httpDir . 'a');
mkdir($httpDir . 'b');
file_put_contents($httpDir . 'a/same.txt', 'FIRST-SAME-BASENAME');
file_put_contents($httpDir . 'b/same.txt', 'SECOND-SAME-BASENAME');
file_put_contents($httpDir . 'large.txt', str_repeat('X', 2048));
copy($fixtureFile, $httpDir . 'remote-doc.txt');
copy($imageFixture, $httpDir . 'remote-photo.png');
file_put_contents($httpDir . 'bad-image.png', 'not actually a png');
$routerScript = $httpDir . '_router.php';
file_put_contents($routerScript, '<?php
if(parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) === "/truncated.txt") {
	header("Content-Type: text/plain");
	header("Content-Length: 10");
	header("Connection: close");
	echo "ABC";
	exit;
}
return false;
');
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = $sock ? (int) parse_url('tcp://' . stream_socket_get_name($sock, false), PHP_URL_PORT) : 0;
if($sock) fclose($sock);
$serverProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $httpDir, $routerScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
try {
	$ready = false;
	for($attempt = 0; $attempt < 30; $attempt++) {
		$probe = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1);
		if($probe) { fclose($probe); $ready = true; break; }
		usleep(50000);
	}
	$assert($ready, 'local HTTP server started for remote URL media test');
	$remoteField = $createField('flex_media_remote', [
		'docs' => ['type' => 'file', 'extensions' => 'txt', 'maxFilesize' => 1024],
		'photos' => ['type' => 'image'],
	]);
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$remoteVal = $page->getUnformatted($remoteField->name);
	$remoteVal->docs->add("http://127.0.0.1:{$port}/remote-doc.txt?cache=1");
	$remoteVal->photos->add("http://127.0.0.1:{$port}/remote-photo.png");
	$throws(fn() => $remoteVal->docs->add("http://127.0.0.1:{$port}/large.txt"), 'remote file exceeding maxFilesize is rejected');
	$throws(fn() => $remoteVal->docs->add("http://127.0.0.1:{$port}/truncated.txt"), 'truncated remote response shorter than Content-Length is rejected');
	$throws(fn() => $remoteVal->photos->add("http://127.0.0.1:{$port}/bad-image.png"), 'remote invalid image bytes are rejected');
	$throws(fn() => $remoteVal->docs->add("http://127.0.0.1:{$port}/remote-photo.png"), 'remote disallowed extension is rejected');
	$throws(fn() => $remoteVal->docs->add('file://' . $fixtureFile), 'non-HTTP(S) URI schemes remain rejected');
	$page->save($remoteField->name);
	$reloadedRemote = $wire->pages->getFresh($id)->getUnformatted($remoteField->name);
	$same(file_get_contents($fixtureFile), file_get_contents($reloadedRemote->docs->first()->filename()), 'remote HTTP file URL persists exact bytes');
	$same(file_get_contents($imageFixture), file_get_contents($reloadedRemote->photos->first()->filename()), 'remote HTTP image URL persists exact bytes');
	// P1/P2: Same-basename remote downloads on an unsaved page keep isolated temp paths even when removed from a clone.
	$unsaved = new Page();
	$unsaved->template = $template;
	$unsaved->parent = $wire->pages->get(1);
	$unsaved->name = 'flex-unsaved-remote-' . bin2hex(random_bytes(4));
	$unsaved->title = 'Unsaved Remote Test';
	$unsavedVal = $unsaved->getUnformatted($remoteField->name);
	$unsavedVal->docs->add("http://127.0.0.1:{$port}/a/same.txt");
	$unsavedVal->docs->add("http://127.0.0.1:{$port}/b/same.txt");
	$clonedDocs = clone $unsavedVal->docs;
	$clonedDocs->remove($clonedDocs->first());
	// P3: Cloning fails immediately if a pending temp source file is missing on disk.
	$refPending = new \ReflectionProperty(FlexFiles::class, 'pendingCopies');
	$refPending->setAccessible(true);
	foreach($refPending->getValue($clonedDocs) as $pendingPath) {
		if(is_file($pendingPath)) unlink($pendingPath);
	}
	$throws(fn() => clone $clonedDocs, 'cloning a collection with a missing pending temp file fails immediately', WireException::class, 'Unable to clone pending media file');
	$throws(fn() => $unsavedVal->docs->add("http://127.0.0.1:{$port}/missing/same.txt"), 'failed same-basename download throws without damaging prior pending download');
	$unsaved->save();
	try {
		$savedUnsavedDocs = array_values($wire->pages->getFresh($unsaved->id)->getUnformatted($remoteField->name)->docs->getArray());
		$same(2, count($savedUnsavedDocs), 'both same-basename remote downloads on unsaved page persist even after removing from a clone');
		$same(['FIRST-SAME-BASENAME', 'SECOND-SAME-BASENAME'], array_map(static fn($f) => file_get_contents($f->filename()), $savedUnsavedDocs), 'same-basename remote downloads keep independent bytes');
	} finally {
		$wire->pages->delete($unsaved, true);
	}
} finally {
	if(is_resource($serverProc)) {
		proc_terminate($serverProc);
		foreach($serverPipes as $pipe) if(is_resource($pipe)) fclose($pipe);
		proc_close($serverProc);
	}
}

unlink($imageFixture);
unlink($fixtureFile);
