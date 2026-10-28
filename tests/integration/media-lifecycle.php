<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$schema = [
	'files' => ['type' => 'file', 'extensions' => 'txt'],
	'rows' => ['type' => 'rows', 'types' => ['item' => ['children' => [
		'heading' => ['type' => 'text'],
		'files' => ['type' => 'file', 'extensions' => 'txt'],
	]]]],
];
$media = $createField('flex_lifecycle', $schema);
$name = $media->name;
$source = $trackFile(dirname(__DIR__, 2) . '/.dev/lifecycle-source-' . bin2hex(random_bytes(4)) . '.txt');
file_put_contents($source, 'Lifecycle original');
$db = $wire->database;
$wire->cache->save('FlexMedia.collectCursor', '', WireCache::expireNever);
$finished = static function() use ($wire): void { $wire->finished(); };
$reload = static function() use ($wire, $id): Page {
	$p = $wire->pages->getFresh($id);
	$p->of(false);
	return $p;
};
$page = $reload();
$page->$name->files->add($source);
$page->$name->rows->addRow('item', ['heading' => 'Projected'])->files->add($source);
$page->save($name);
$original = $page->$name->files->first()->filename();
$rowPath = $page->$name->rows->first()->files->first()->filename();
$same([$id], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Projected"), 'normal save projects media rows without rebuilding');
$copy = $wire->pages->clone($page);
$pages[] = $copy;
$same([$copy->id], $wire->pages->findIDs("id=$copy->id, $name.rows.item.heading=Projected"), 'native clone automatically projects rows');

$db->beginTransaction();
try {
	$page->$name->files->removeAll();
	$page->$name->rows->removeAll();
	$page->save($name);
	$finished();
	$assert($db->inTransaction() && is_file($original) && is_file($rowPath), 'finished lifecycle skips open outer transaction and preserves originals');
} finally {
	$db->rollBack();
}
$finished();
$assert(is_file($original) && is_file($rowPath), 'automatic cleanup after rollback preserves both originals');
$same([$id], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Projected"), 'outer rollback restores row projection without repair');
$page = $reload();
$db->beginTransaction();
$page->$name->files->removeAll();
$page->$name->rows->removeAll();
$page->save($name);
$db->commit();
$assert(is_file($original) && is_file($rowPath), 'outer commit alone leaves physical work queued');
$finished();
$finished();
$assert(!is_file($original) && !is_file($rowPath), 'native finished event automatically collects outer-committed originals and removed subtree media');
$same([], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Projected"), 'removing subtree automatically deletes its projection');

// Real discarded uploads use independent journal ownership and six-hour expiry.
$page = $reload();
$expired = [];
foreach(['staged', 'uploading', 'cloning'] as $state) {
	$page->$name->files->add($source);
	$file = $page->$name->files->last();
	$expired[] = [$file->assetId(), $file->filename()];
	$db->prepare('UPDATE fieldtype_flex_assets SET state=:state,expires=:expires WHERE asset_id=:id')->execute([
		'state' => $state, 'expires' => time() - 1, 'id' => $file->assetId(),
	]);
}
$unknown = $trackFile($page->filesManager()->path() . 'lifecycle-unknown.txt');
file_put_contents($unknown, 'Unregistered original');
$finished();
$finished(); // A newly eligible ID can precede the durable cursor.
foreach($expired as [$assetId, $path]) {
	$assert(!is_file($path) && !$type->media()->asset($assetId, true), 'finished collects expired ' . basename($path) . ' without a save or maintenance call');
}
$assert(is_file($unknown), 'automatic sweep preserves unknown files');

// A plain bootstrapped PHP program gets cleanup from native destruction as well.
$page = $reload();
$page->$name->files->add($source);
$discarded = $page->$name->files->last();
$discardedPath = $discarded->filename();
$db->prepare('UPDATE fieldtype_flex_assets SET expires=:expires WHERE asset_id=:id')->execute(['expires' => time() - 1, 'id' => $discarded->assetId()]);
$wire->cache->save('FlexMedia.collectCursor', '', WireCache::expireNever);
$child = $trackFile(dirname(__DIR__, 2) . '/.dev/lifecycle-child-' . bin2hex(random_bytes(4)) . '.php');
file_put_contents($child, "<?php namespace ProcessWire; require dirname(__DIR__) . '/scripts/bootstrap.php'; echo 'Bootstrapped';");
$assert(is_file($discardedPath), 'abandoned staging exists before the child starts');
$process = proc_open([PHP_BINARY, $child], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if(!is_resource($process)) throw new \RuntimeException('Unable to launch lifecycle child');
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
$errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
$same(0, proc_close($process), 'plain PHP child exits successfully: ' . $errors);
$same('Bootstrapped', $output, 'plain PHP child only bootstraps ProcessWire');
$same(null, $type->media()->asset($discarded->assetId(), true), 'native instance destruction releases staged ownership');
// The child changes files outside this process; PHP 8.1 can retain a positive stat.
clearstatcache();
$assert(!is_file($discardedPath), 'native instance destruction collects abandoned staging without application calls');

// Explicit business cleanup queues media; no follow-up collector call is required.
$page = $reload();
$page->$name->files->add($source);
$page->save($name);
$orphan = $page->$name->files->first()->filename();
$media->flexDefinition = json_encode(['rows' => ['type' => 'rows', 'types' => ['item' => ['children' => ['heading' => ['type' => 'text']]]]]]);
$media->save();
$inventory = $type->listOrphans($media);
$preview = $type->cleanupProperties($media, ['files'], $inventory);
$result = $type->cleanupProperties($media, ['files'], $preview, true);
$assert($result['success'] && is_file($orphan), 'explicit property cleanup commits logical removal and queues physical work');
$finished();
$finished();
$assert(!is_file($orphan), 'request completion handles property-cleanup files automatically');

// A whole protected batch and one failing record must not starve later records.
// Deliberately construct journal/canonical edge cases, retaining real owned paths.
$insert = $db->prepare('INSERT INTO fieldtype_flex_assets (asset_id,fields_id,pages_id,address,basename,state,expires,data) VALUES (:id,:field,:page,:address,:name,:state,:expires,:data)');
$records = [];
$raw = [];
for($n = 0; $n < 104; ++$n) {
	$assetId = '1' . str_pad(dechex($n), 31, '0', STR_PAD_LEFT);
	$basename = 'lifecycle-batch-' . $n . '.txt';
	$path = $trackFile($page->filesManager()->path() . $basename);
	file_put_contents($path, 'Batch ' . $n);
	$meta = ['id' => $assetId, 'basename' => $basename];
	$data = ['kind' => 'file', 'meta' => $meta];
	$records[] = [$assetId, $path, $data];
	$insert->execute(['id' => $assetId, 'field' => $media->id, 'page' => $id, 'address' => 'retained', 'name' => $basename, 'state' => 'pending_delete', 'expires' => 0, 'data' => $n === 0 ? '{invalid' : json_encode($data)]);
	if($n > 0 && $n < 100) $raw[] = $meta;
}
$table = $db->escapeTable($media->table);
$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute([
	'data' => FlexStorage::encode(['retained' => ['_flex' => 'files:1', 'items' => $raw]]), 'id' => $id,
]);
$wire->cache->save('FlexMedia.collectCursor', '', WireCache::expireNever);
$finished();
$assert(is_file($records[0][1]) && is_file($records[99][1]) && is_file($records[100][1]), 'one pass is bounded even when all first hundred candidates fail or remain referenced');
$finished();
$assert(!is_file($records[100][1]) && !is_file($records[103][1]), 'later pass advances beyond a full protected/failed batch');
$assert(is_file($records[1][1]) && is_file($records[99][1]), 'unknown properties with canonical references keep their originals');
$db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute(['data' => json_encode($records[0][2]), 'id' => $records[0][0]]);
$finished();
$assert(!is_file($records[0][1]), 'durable cursor wraps and retries a repaired failed candidate');
// A real physical delete failure retains ownership, while later candidates proceed.
$blockedId = $records[1][0];
$blockedPath = $records[1][1];
$db->prepare("UPDATE `$table` SET data='{}' WHERE pages_id=:id")->execute(['id' => $id]);
unlink($blockedPath);
mkdir($blockedPath);
$wire->cache->save('FlexMedia.collectCursor', '', WireCache::expireNever);
try {
	$finished();
	$assert($type->media()->asset($blockedId, true) !== null, 'failed physical removal retains registered ownership for retry');
	$assert(!is_file($records[2][1]), 'physical removal failure does not block subsequent candidates');
} finally {
	rmdir($blockedPath);
}
$finished();
$same(null, $type->media()->asset($blockedId, true), 'physical removal is retried on subsequent request completion');

// The inherited native emptyPageField path must share Flex deletion semantics.
$media->flexDefinition = json_encode($schema);
$media->save();
$page = $reload();
$row = $page->$name->rows->addRow('item', ['heading' => 'Native empty']);
$row->files->add($source);
$emptyPath = $row->files->first()->filename();
$page->save($name);
$db->beginTransaction();
try {
	$type->emptyPageField($page, $media);
	$finished();
	$assert(is_file($emptyPath), 'native empty retains media before outer commit');
	$same([], $type->storage()->persisted($page, $media), 'native empty removes canonical storage');
	$same([], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Native empty"), 'native empty removes projection in the same transaction');
} finally {
	$db->rollBack();
}
$assert(is_file($emptyPath), 'native-empty rollback preserves original bytes');
$same([$id], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Native empty"), 'outer rollback restores native-empty projection');
$type->emptyPageField($page, $media);
$assert(!is_file($emptyPath), 'committed native empty automatically collects media');
$same([], $wire->pages->findIDs("id=$id, $name.rows.item.heading=Native empty"), 'committed native empty leaves no stale projection');

// Deletion must release both canonical and projected rows without a repair step.
$copyId = $copy->id;
$wire->pages->delete($copy, true);
$q = $db->prepare('SELECT COUNT(*) FROM fieldtype_flex_rows WHERE fields_id=:field AND pages_id=:page');
$q->execute(['field' => $media->id, 'page' => $copyId]);
$same(0, (int) $q->fetchColumn(), 'native Page deletion removes row projections automatically');
