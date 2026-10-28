<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$media = $createField('flex_rename', [
	'download' => ['type' => 'file', 'extensions' => 'txt'],
	'hero' => ['type' => 'image'],
	'blocks' => ['type' => 'rows', 'types' => ['image' => ['children' => ['photo' => ['type' => 'image']]]]],
]);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-rename.txt');
file_put_contents($fixture, 'Rename keeps these bytes');
$page = $wire->pages->getFresh($id);
$page->of(false);
$files = $page->getUnformatted($media->name)->download;
$files->add($fixture);
$assert((bool) preg_match('/^flex-rename-[a-f0-9]{12}\.txt$/D', $files->first()->basename()), 'imports keep their existing independent-copy naming');
$page->save($media->name);
$file = $files->first();
$asset = $file->assetId();
$oldPath = $file->filename();
$same('test.txt', $file->rename('test.txt'), 'rename retains an available requested basename exactly');
$same($asset, $file->assetId(), 'rename preserves asset identity before save');
$assert(is_file($oldPath), 'rename stages bytes without moving the committed original');
$page->save($media->name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$file = $page->getUnformatted($media->name)->download->first();
$same('test.txt', $file->basename(), 'exact renamed basename survives save and reload');
$same($asset, $file->assetId(), 'rename preserves asset identity after reload');
$same('Rename keeps these bytes', file_get_contents($file->filename()), 'rename preserves original bytes');
$assert(!is_file($oldPath), 'commit cleans up the former basename');

// Native originals/unknown bytes and reserved names without bytes both count as conflicts.
$occupied = $trackFile($page->filesManager()->path() . 'occupied.txt');
file_put_contents($occupied, 'Keep unrelated bytes');
$renamed = $file->rename('occupied.txt');
$assert($renamed !== 'occupied.txt' && str_starts_with($renamed, 'occupied-'), 'rename adds a suffix for a physical name conflict');
$same('Keep unrelated bytes', file_get_contents($occupied), 'rename cannot overwrite unrelated page files');
$same($asset, $file->assetId(), 'conflicting rename retains asset identity');
$page->save($media->name);
$files = $page->getUnformatted($media->name)->download;
$copy = $files->clone($file);
$assert($copy->assetId() !== $asset && $copy->basename() !== $file->basename(), 'duplication retains independent ownership and unique names');
$reservedName = $copy->basename();
$order = array_map(fn($item) => $item['id'], $files->rawArray()['items']);
unlink($copy->filename());
$assert($file->rename($reservedName) !== $reservedName, 'rename respects an ownership reservation even without physical bytes');
$same($order, array_column($files->rawArray()['items'], 'id'), 'rename preserves collection order');
$same($file, $files->get($file->basename()), 'filename lookup returns the same native object after rename');
$files->remove($copy);
$page->save($media->name);
$same($file->basename(), $file->rename($file->basename()), 'rename to the current name is a no-op');
$same($asset, $file->assetId(), 'no-op rename retains asset identity');
$same($file->basename(), $file->rename(strtoupper($file->basename())), 'normalized current basename is also a no-op');

$imageFixture = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-rename.png');
$image = imagecreatetruecolor(80, 60);
imagepng($image, $imageFixture);
imagedestroy($image);
$value = $page->getUnformatted($media->name);
$value->hero->add($imageFixture);
$row = $value->blocks->addRow('image');
$row->photo->add($imageFixture);
$page->save($media->name);

foreach(['hero', 'row'] as $scope) {
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$value = $page->getUnformatted($media->name);
	$images = $scope === 'hero' ? $value->hero : $value->blocks->first()->photo;
	$image = $images->first();
	$image->description = 'Rename metadata';
	$image->tags = 'fixture';
	$image->filedata('rename', ['keep' => true]);
	$page->save($media->name);
	$asset = $image->assetId();
	$oldPath = $image->filename();
	$oldHash = hash_file('sha256', $oldPath);
	$variation = $image->size(32, 24);
	$variationPath = $variation->filename();
	$variationHash = hash_file('sha256', $variationPath);
	$webpSupported = $wire->wire(new ImageSizer($oldPath))->getEngine()->supported('webp');
	if($webpSupported) {
		$image->webp()->url(false);
		$variation->webp()->url(false);
		$webpPath = $image->webp()->filename();
		$variationWebpPath = $variation->webp()->filename();
		$assert(is_file($webpPath) && is_file($variationWebpPath), "$scope native engine creates original and variation WebP extras");
		$webpHash = hash_file('sha256', $webpPath);
		$variationWebpHash = hash_file('sha256', $variationWebpPath);
	} else {
		echo "SKIP: $scope WebP extras require native engine support\n";
	}
	// MySQL's JSON storage reorders object keys. Exercise that distinction on MariaDB too.
	$table = $wire->database->escapeTable($media->table);
	$wire->database->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:page")->execute([
		'data' => json_encode((object) array_reverse($value->rawArray(), true), JSON_THROW_ON_ERROR),
		'page' => $page->id,
	]);
	$before = $type->storage()->persisted($page, $media);
	$same("$scope.png", $images->rename($image, "$scope.png"), "$scope image rename uses an exact free name");
	$same($asset, $image->assetId(), "$scope image rename retains identity before save");
	$newPath = $image->filename();
	$newVariation = $image->size(32, 24)->filename();
	$same($variationHash, hash_file('sha256', $newVariation), "$scope rename copies existing variation bytes under the new stem");
	if($webpSupported) {
		$newWebp = $image->webp()->filename();
		$newVariationWebp = $image->size(32, 24)->webp()->filename();
		$assert(is_file($newWebp) && is_file($newVariationWebp), "$scope rename preserves original and variation WebP extras without regeneration");
		$same($webpHash, hash_file('sha256', $newWebp), "$scope rename preserves original WebP bytes");
		$same($variationWebpHash, hash_file('sha256', $newVariationWebp), "$scope rename preserves variation WebP bytes");
	}
	$assert(is_file($oldPath) && is_file($variationPath), "$scope rename retains committed original and variations before save");
	$same($before, $type->storage()->persisted($page, $media), "$scope staging leaves canonical data unchanged");

	// Fail after identity transfer, while promoting the new ownership record.
	$triggerName = 'flex_rename_failure_' . bin2hex(random_bytes(3));
	$wire->database->exec("CREATE TRIGGER $triggerName BEFORE UPDATE ON fieldtype_flex_assets FOR EACH ROW BEGIN IF NEW.fields_id=" . (int) $media->id . " AND NEW.state='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rename failure'; END IF; END");
	try {
		$throws(fn() => $page->save($media->name), "$scope injected SQL failure rejects rename", \PDOException::class);
	} finally {
		$wire->database->exec("DROP TRIGGER $triggerName");
	}
	$same($before, $type->storage()->persisted($page, $media), "$scope failed rename rolls canonical data back");
	$same(basename($oldPath), $type->media()->asset($asset, true)['basename'], "$scope failed rename rolls ownership identity back");
	$same($oldHash, hash_file('sha256', $oldPath), "$scope failed rename keeps original bytes");
	$same($variationHash, hash_file('sha256', $variationPath), "$scope failed rename keeps old variation bytes");
	if($webpSupported) $assert(is_file($webpPath) && is_file($variationWebpPath), "$scope failed rename retains committed WebP extras");
	$assert($type->media()->assetByName($page, basename($newPath))['state'] === 'staged', "$scope failed rename retains durable staging ownership");

	$wire->database->beginTransaction();
	try {
		$page->save($media->name);
		$assert(is_file($oldPath) && is_file($variationPath), "$scope outer transaction defers rename cleanup");
	} finally {
		$wire->database->rollBack();
	}
	$same($before, $type->storage()->persisted($page, $media), "$scope outer rollback restores canonical rename state");
	$same(basename($oldPath), $type->media()->asset($asset, true)['basename'], "$scope outer rollback restores ownership identity");
	$pending = $value->rawArray();
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$page->set($media->name, $pending);
	$page->save($media->name);
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$value = $page->getUnformatted($media->name);
	$image = ($scope === 'hero' ? $value->hero : $value->blocks->first()->photo)->first();
	$same("$scope.png", $image->basename(), "$scope image exact name survives reload");
	$same($asset, $image->assetId(), "$scope image identity survives reload");
	$same($oldHash, hash_file('sha256', $image->filename()), "$scope original bytes survive reload");
	$same($variationHash, hash_file('sha256', $image->size(32, 24)->filename()), "$scope renamed variation survives reload");
	if($webpSupported) $assert(is_file($image->webp()->filename()) && is_file($image->size(32, 24)->webp()->filename()), "$scope WebP extras survive rename save and reload");
	$same('Rename metadata', $image->description, "$scope rename retains description");
	$same('fixture', $image->tags, "$scope rename retains tags");
	$same(['keep' => true], $image->filedata('rename'), "$scope rename retains filedata");
	$assert(!is_file($oldPath) && !is_file($variationPath), "$scope commit cleans former original and variations");
	if($webpSupported) $assert(!is_file($webpPath) && !is_file($variationWebpPath), "$scope commit cleans former WebP extras");
	$same($asset, $type->media()->assetByName($page, $image->basename())['asset_id'], "$scope ownership registry uses the stable asset ID");
}

// A conflicting derivative must not be overwritten, even if the original name is free.
$conflict = $trackFile($page->filesManager()->path() . 'derivative.19x17.png');
file_put_contents($conflict, 'Unowned derivative');
$hero = $value->hero->first();
$renamed = $hero->rename('derivative.png');
$assert($renamed !== 'derivative.png', 'rename detects an existing target variation as a name conflict');
$same('Unowned derivative', file_get_contents($conflict), 'rename preserves conflicting derivative bytes');
$firstStage = $hero->filename();
$same('final.png', $hero->rename('final.png'), 'repeated unsaved rename retains an available exact name');
$asset = $hero->assetId();
$page->save($media->name);
$hero = $wire->pages->getFresh($id)->getUnformatted($media->name)->hero->first();
$same('final.png', $hero->basename(), 'repeated rename survives save and reload');
$same($asset, $hero->assetId(), 'repeated rename preserves the logical identity');
$assert(is_file($firstStage), 'superseded staging remains registered until conservative expiry');
$type->media()->abandon($type->media()->assetByName($page, basename($firstStage))['asset_id']);
$type->collectFiles(1000);
$assert(!is_file($firstStage) && is_file($hero->filename()), 'expired superseded staging cleanup preserves the renamed original');

// A canonical basename alone cannot claim a staging record from another property.
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($media->name);
$before = $type->storage()->persisted($page, $media);
$value->blocks->first()->photo->first()->rename('row-draft.png');
$forged = $value->rawArray();
$forged['hero']['items'][0]['basename'] = 'row-draft.png';
$other = $wire->pages->getFresh($id);
$other->of(false);
$other->set($media->name, $forged);
$throws(fn() => $other->save($media->name), 'rename rejects a staging record belonging to another property');
$same($before, $type->storage()->persisted($page, $media), 'rejected staging ownership leaves all canonical data intact');

$value->hero->first()->rename('current.png');
$stale = $wire->pages->getFresh($id);
$stale->of(false);
$stale->getUnformatted($media->name)->hero->first()->rename('stale.png');
$page->save($media->name);
$throws(fn() => $stale->save($media->name), 'stale rename cannot overwrite a newer committed name');
$same('current.png', $wire->pages->getFresh($id)->getUnformatted($media->name)->hero->first()->basename(), 'stale rename rejection preserves the current original');
