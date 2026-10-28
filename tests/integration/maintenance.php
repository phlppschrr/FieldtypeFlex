<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$definition = [
	'heading' => ['type' => 'text', 'renamedFrom' => 'old_heading'],
	'localized' => ['type' => 'text', 'useLanguages' => true, 'renamedFrom' => 'old_localized'],
	'blocks' => ['type' => 'rows', 'renamedFrom' => 'old_blocks', 'types' => [
		'text' => ['children' => [
			'title' => ['type' => 'text', 'renamedFrom' => 'old_title'],
			'children' => ['type' => 'rows', 'types' => ['item' => ['children' => [
				'number' => ['type' => 'integer', 'renamedFrom' => 'old_number'],
			]]]],
		]],
	]],
	'absent_default' => ['type' => 'text', 'value' => 'Do not persist'],
];
$maintenance = $createField('flex_maintenance', $definition);
$store = $type->storage();
$db = $wire->database;
$write = static function(Page $owner, array $raw) use ($db, $maintenance, $store, $type): void {
	$table = $db->escapeTable($maintenance->table);
	$store->atomic(function() use ($db, $table, $owner, $raw, $maintenance, $store, $type): void {
		$q = $db->prepare("INSERT INTO `$table` (pages_id,data,search) VALUES (:id,:data,:search) ON DUPLICATE KEY UPDATE data=VALUES(data),search=VALUES(search)");
		$q->execute(['id' => $owner->id, 'data' => FlexStorage::encode($raw), 'search' => FlexStorage::search($raw)]);
		$store->projectRows($owner, $maintenance, $raw);
		$type->media()->sync($owner, $maintenance, $raw);
	});
};
$rowId = bin2hex(random_bytes(16)); $childId = bin2hex(random_bytes(16));
$rows = ['_flex' => 'rows:1', 'items' => [[
	'id' => $rowId, 'type' => 'text', 'values' => [
		'old_title' => '', 'obsolete' => 'Nested keep',
		'children' => ['_flex' => 'rows:1', 'items' => [[
			'id' => $childId, 'type' => 'item', 'values' => ['old_number' => 0, 'old_extra' => 'Delete me'],
		]]],
	],
]]];
$raw = ['old_heading' => 'Legacy', 'old_localized' => [(string) $defaultLanguage->id => 'Default', (string) $language->id => 'Deutsch'], 'old_blocks' => $rows, 'obsolete' => 'Remove', 'other_unknown' => ['x' => 'Keep']];
$write($page, $raw);
$write($second, ['old_heading' => 'Equal', 'heading' => 'Equal', 'obsolete' => 0]);
$before = $store->persisted($page, $maintenance);
$preview = $type->checkRenames($maintenance, 0, 1);
$same(1, $preview['checked'], 'rename preview respects batch limit');
$same(false, $preview['complete'], 'batch reports remaining owners');
$same(FlexStorage::revision($before), FlexStorage::revision($store->persisted($page, $maintenance)), 'rename preview is read-only');
$same(5, count($preview['renames']), 'root, language, collection and deep leaf renames discovered');
$same('old_blocks.text.old_title', $preview['renames'][3]['from'], 'nested rename source schema path retains the original collection name');
$same("old_blocks.$rowId.old_title", $preview['renames'][3]['address'], 'nested rename reports its original stored concrete address');
$orphans = $type->listOrphans($maintenance, 0, 1);
$assert(!isset($orphans['properties']['old_heading']) && !isset($orphans['properties']['old_blocks']), 'declared rename sources cannot be selected as orphans');
$result = $type->applyRenames($maintenance, $preview);
$same(1, $result['changed'], 'apply changes one checked page field');
$persisted = $store->persisted($page, $maintenance);
$same('Legacy', $persisted['heading'], 'root renamed value persisted');
$same($raw['old_localized'], $persisted['localized'], 'language map is preserved without normalization');
$same($rowId, $persisted['blocks']['items'][0]['id'], 'collection rename preserves row identity');
$same(0, $persisted['blocks']['items'][0]['values']['children']['items'][0]['values']['number'], 'deep numeric zero preserved');
$same('', $persisted['blocks']['items'][0]['values']['title'], 'blank old value preserved');
$assert(!array_key_exists('absent_default', $persisted), 'maintenance does not add unrelated defaults');
$same($before['other_unknown'], $persisted['other_unknown'], 'rename retains unrelated unknown subtree');
$assert($wire->pages->count("id=$id, {$maintenance->name}.heading=Legacy, include=all") === 1, 'renamed root selector matches');
$assert($wire->pages->count("id=$id, {$maintenance->name}.blocks.text.children.item.number=0, include=all") === 1, 'renamed row projection matches');
$same(0, $type->applyRenames($maintenance, $type->checkRenames($maintenance, 0, 1))['changed'], 'rename is idempotent');
$next = $type->checkRenames($maintenance, $preview['lastPageId'], 1);
$same(true, $next['complete'], 'continuation reaches final batch');
$same(1, $type->applyRenames($maintenance, $next)['changed'], 'equal old and new values merge');
$orphans = $type->listOrphans($maintenance);
$same(2, $orphans['properties']['obsolete']['pageCount'], 'orphan aggregates count owning pages');
$same([(int) $page->id, (int) $second->id], $orphans['properties']['obsolete']['pageIds'], 'bounded details identify affected page IDs');
$same("blocks.$rowId.obsolete", $orphans['properties']['blocks.text.obsolete']['locations'][0]['address'], 'schema paths are distinct from concrete addresses');
$remove = ['obsolete', 'blocks.text.children.item.old_extra'];
$cleanup = $type->cleanupProperties($maintenance, $remove, $orphans);
$same(0, $cleanup['changed'], 'cleanup defaults to preview');
$same(2, $cleanup['wouldChange'], 'cleanup preview counts affected owners');
$same(2, $type->cleanupProperties($maintenance, $remove, $cleanup, true)['changed'], 'cleanup changes selected paths');
$persisted = $store->persisted($page, $maintenance);
$assert(!isset($persisted['obsolete']) && isset($persisted['other_unknown']) && isset($persisted['blocks']['items'][0]['values']['obsolete']), 'only explicitly selected unknown paths are removed');
$throws(fn() => $type->cleanupProperties($maintenance, [], $orphans, true), 'cleanup requires explicit paths');
$throws(fn() => $type->cleanupProperties($maintenance, ['heading'], $orphans, true), 'cleanup requires matching selected preview');
$stale = $type->checkRenames($maintenance);
$raw = $persisted; $raw['heading'] = 'Concurrent'; $write($page, $raw);
$failed = $type->applyRenames($maintenance, $stale);
$same((int) $page->id, $failed['errors'][0]['pageId'], 'changed data rejects stale preview');
$assert(!$failed['finished'] && in_array((int) $page->id, $failed['retryPageIds'], true), 'failed page is explicitly retryable');
$same((int) $page->id - 1, $failed['resumeAfterPageId'], 'safe resume cursor does not hide failed page');
$stale = $type->listOrphans($maintenance);
$definition['new_schema_key'] = ['type' => 'text']; $maintenance->flexDefinition = json_encode($definition);
$failed = $type->cleanupProperties($maintenance, ['other_unknown'], $stale);
$assert(!$failed['success'] && str_contains($failed['errors'][0]['message'], 'schema'), 'schema change rejects old preview');
unset($definition['new_schema_key']); $maintenance->flexDefinition = json_encode($definition);
$write($second, ['old_heading' => 'Old', 'heading' => 'New', 'other' => 'Keep']);
$conflict = $type->checkRenames($maintenance);
$same('old_heading', $conflict['conflicts'][0]['path'], 'conflict identifies property path');
$same((int) $second->id, $conflict['conflicts'][0]['pageId'], 'conflict identifies owner');
$apply = $type->applyRenames($maintenance, $conflict);
$assert(!$apply['success'] && !$apply['finished'], 'applying valid pages cannot conceal preview conflicts');
$same('Old', $store->persisted($second, $maintenance)['old_heading'], 'conflicting field is wholly unchanged');
$write($second, ['heading' => 'Safe']);
$write($page, ['old_heading' => 'Rollback']);
$preview = $type->checkRenames($maintenance);
$db->beginTransaction();
try {
	$same(1, $type->applyRenames($maintenance, $preview)['changed'], 'maintenance participates in outer transaction');
	$db->rollBack();
} finally { if($db->inTransaction()) $db->rollBack(); }
$same(['old_heading' => 'Rollback'], $store->persisted($page, $maintenance), 'outer rollback restores canonical JSON');
$write($page, ['blocks' => ['_flex' => 'rows:1', 'items' => [['id' => $rowId, 'type' => 'removed', 'values' => ['orphan' => 'Keep']]]]]);
$unknown = $type->listOrphans($maintenance);
$same('blocks.removed', $unknown['errors'][0]['path'], 'unknown row type is a separate problem');
$assert(!isset($unknown['properties']['blocks.removed.orphan']), 'unknown row types are not cleanup candidates');
$maintenance->flexDefinition = '{invalid';
$same([], $type->listOrphans($maintenance)['properties'], 'invalid definitions cannot orphan all stored data');
$maintenance->flexDefinition = ''; $maintenance->flexDefinitionFile = 'does-not-exist-maintenance.php';
$missing = $type->listOrphans($maintenance);
$assert(!$missing['success'] && !$missing['properties'], 'lost definitions block classification');
$maintenance->flexDefinitionFile = ''; $maintenance->flexDefinition = json_encode($definition);
$write($page, ['heading' => 'Stored', 'hidden_key' => 'Retain']);
foreach([null, 0, 1] as $legacy) {
	$maintenance->flexPrune = $legacy;
	$value = $type->sanitizeValue($page, $maintenance, $store->persisted($page, $maintenance));
	$same('Retain', $value->rawArray()['hidden_key'], 'rawArray always retains unknown values for legacy prune metadata');
	$page->set($maintenance->name, $value); $page->trackChange($maintenance->name); $page->save($maintenance->name);
	$same('Retain', $store->persisted($page, $maintenance)['hidden_key'], 'normal save always retains unknown data');
}
foreach([0, 1001] as $limit) $throws(fn() => $type->listOrphans($maintenance, 0, $limit), 'invalid batch limit rejected');
$throws(fn() => $type->listOrphans($maintenance, -1), 'negative continuation cursor rejected');

$cli = new FlexMaintenanceCli($type);
ob_start(); $status = $cli->run(['orphans', $maintenance->name, '--details', '--limit=1']); $out = ob_get_clean();
$same(0, $status, 'CLI read-only adapter succeeds');
$cliResult = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
$same($type->listOrphans($maintenance, 0, 1)['properties'], $cliResult['properties'], 'API and CLI orphan results agree');
ob_start(); $status = $cli->run(['--help']); $out = ob_get_clean();
$assert($status === 0 && str_contains($out, 'blocks.text.old') && str_contains($out, 'resumeAfterPageId'), 'CLI help explains paths and error continuation');
foreach([
	['rename', $maintenance->name, '--apply', '--dry-run'],
	['rename', $maintenance->name, '--unexpected'],
	['orphans', $maintenance->name, '--apply'],
	['orphans', $maintenance->name, '--limit=0'],
	['cleanup', $maintenance->name, '--apply'],
	['cleanup', $maintenance->name, '--path=x..y'],
] as $args) {
	ob_start(); $status = $cli->run($args); ob_end_clean();
	$assert($status !== 0, 'CLI rejects invalid arguments');
}

// Real asset ownership: move root media and a collection containing media, then remove the subtree.
$assetSchema = [
	'old_file' => ['type' => 'file', 'extensions' => 'txt'],
	'old_set' => ['type' => 'rows', 'types' => ['item' => ['children' => ['old_file' => ['type' => 'file', 'extensions' => 'txt']]]]],
	'note' => ['type' => 'text'],
];
$assetField = $createField('flex_maintenance_assets', $assetSchema);
$name = $assetField->name;
$page = $wire->pages->getFresh($id); $page->of(false); $page->$name = ['note' => 'Keep']; $page->save($name);
$fixtureBase = $trackFile(tempnam(sys_get_temp_dir(), 'flex-maintenance-'));
$fixture = $trackFile($fixtureBase . '.txt'); rename($fixtureBase, $fixture);  file_put_contents($fixture, 'Maintenance owned bytes');
$page->$name->old_file->add($fixture);
$assetRow = $page->$name->old_set->addRow('item'); $assetRow->old_file->add($fixture); $page->save($name);
$rootFile = $page->$name->old_file->first(); $rowFile = $assetRow->old_file->first();
$rootAsset = $rootFile->assetId(); $rowAsset = $rowFile->assetId();
$rootPath = $rootFile->filename(); $rowPath = $rowFile->filename();
$originalRows = $store->persisted($page, $assetField);
$assetSchema['file'] = $assetSchema['old_file'] + ['renamedFrom' => 'old_file']; unset($assetSchema['old_file']);
$assetSchema['items'] = $assetSchema['old_set'] + ['renamedFrom' => 'old_set']; unset($assetSchema['old_set']);
$assetSchema['items']['types']['item']['children'] = ['file' => ['type' => 'file', 'extensions' => 'txt', 'renamedFrom' => 'old_file']];
$assetField->flexDefinition = json_encode($assetSchema); $assetField->save();
$snapshot = static function() use ($db, $assetField, $id): array {
	return [
		$db->query('SELECT data,search FROM `' . $db->escapeTable($assetField->table) . '` WHERE pages_id=' . (int) $id)->fetchAll(\PDO::FETCH_ASSOC),
		$db->query('SELECT * FROM fieldtype_flex_rows WHERE fields_id=' . (int) $assetField->id . ' ORDER BY pages_id,set_key,row_id')->fetchAll(\PDO::FETCH_ASSOC),
		$db->query('SELECT * FROM fieldtype_flex_assets WHERE fields_id=' . (int) $assetField->id . ' ORDER BY asset_id')->fetchAll(\PDO::FETCH_ASSOC),
	];
};
$readBefore = $snapshot();
$type->listOrphans($assetField);
$same($readBefore, $snapshot(), 'orphan inventory preserves JSON, search/row projections and ownership exactly');
$preview = $type->checkRenames($assetField);
// A projection SQL failure must roll back the canonical update and preserve the same reusable preview.
$trigger = 'flex_maintenance_failure_' . bin2hex(random_bytes(3));
$db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON fieldtype_flex_rows FOR EACH ROW BEGIN IF NEW.fields_id=" . (int) $assetField->id . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='maintenance projection failure'; END IF; END");
try { $failure = $type->applyRenames($assetField, $preview); }
finally { $db->exec("DROP TRIGGER $trigger"); }
$same(0, $failure['changed'], 'SQL failure never reports an applied page');
$same(FlexStorage::revision($originalRows), FlexStorage::revision($store->persisted($page, $assetField)), 'SQL failure rolls back exact canonical values');
$same('old_file', $type->media()->asset($rootAsset, true)['address'], 'SQL failure preserves media address');
$trigger = 'flex_maintenance_asset_failure_' . bin2hex(random_bytes(3));
$db->exec("CREATE TRIGGER $trigger BEFORE UPDATE ON fieldtype_flex_assets FOR EACH ROW BEGIN IF NEW.fields_id=" . (int) $assetField->id . " AND NEW.address='file' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='maintenance asset failure'; END IF; END");
try { $failure = $type->applyRenames($assetField, $preview); }
finally { $db->exec("DROP TRIGGER $trigger"); }
$same(0, $failure['changed'], 'ownership SQL failure reports no successful migration');
$same($readBefore, $snapshot(), 'ownership SQL failure rolls back JSON, search, row projection and assets together');
$same(1, $type->applyRenames($assetField, $preview)['changed'], 'same preview can retry a rolled-back SQL failure');
$renamed = $store->persisted($page, $assetField);
$same($rootAsset, $renamed['file']['items'][0]['id'], 'root media property rename preserves asset ID');
$same($rowAsset, $renamed['items']['items'][0]['values']['file']['items'][0]['id'], 'collection and nested media rename preserve asset ID');
$same('file', $type->media()->asset($rootAsset, true)['address'], 'root media ownership address follows rename');
$same('items.' . $assetRow->rowId() . '.file', $type->media()->asset($rowAsset, true)['address'], 'descendant ownership address follows collection and leaf rename');
$assert(is_file($rootPath) && is_file($rowPath), 'maintenance renames preserve physical file bytes');
unset($assetSchema['items']); $assetField->flexDefinition = json_encode($assetSchema); $assetField->save();
$scan = $type->listOrphans($assetField);
$same(1, $scan['properties']['items']['media'], 'removed subtree aggregates nested media references');
$cleanup = $type->cleanupProperties($assetField, ['items'], $scan);
$db->beginTransaction();
try {
	$same(1, $type->cleanupProperties($assetField, ['items'], $cleanup, true)['changed'], 'media cleanup runs inside outer transaction');
	$assert(is_file($rowPath), 'physical bytes survive until outer commit');
	$same('pending_delete', $type->media()->asset($rowAsset, true)['state'], 'cleanup queues removed descendant ownership');
	$wire->finished();
	$assert($db->inTransaction() && is_file($rowPath), 'automatic lifecycle skips property cleanup inside open outer transaction');
	$db->rollBack();
} finally { if($db->inTransaction()) $db->rollBack(); }
$same(FlexStorage::revision($renamed), FlexStorage::revision($store->persisted($page, $assetField)), 'media cleanup rollback restores canonical subtree');
$same('committed', $type->media()->asset($rowAsset, true)['state'], 'media cleanup rollback restores ownership');
$same(1, $type->cleanupProperties($assetField, ['items'], $cleanup, true)['changed'], 'cleanup applies after rollback using same preview');
$assert(is_file($rowPath), 'successful maintenance defers physical deletion to collector');
$wire->finished();
$wire->finished(); // Newly eligible IDs may precede the persisted cursor.
$assert(!is_file($rowPath) && is_file($rootPath), 'automatic post-commit lifecycle removes selected bytes and retains other owned files');
// An editor draft captures the pre-maintenance revision and must not overwrite the result.
$page = $wire->pages->getFresh($id); $page->of(false);
$draft = $type->admin()->open($page->$name, $name);
$scan = $type->listOrphans($assetField);
$old = $store->persisted($page, $assetField);
$assetSchema = ['note' => ['type' => 'text']]; $assetField->flexDefinition = json_encode($assetSchema); $assetField->save();
$scan = $type->listOrphans($assetField);
$type->cleanupProperties($assetField, ['file'], $type->cleanupProperties($assetField, ['file'], $scan), true);
$throws(fn() => $type->admin()->assertFresh($draft, $page, $assetField), 'old editor draft rejects data changed by maintenance');

// Preserve JSON object/list distinctions in unrelated subtrees and bind them into the preview.
$write($page, ['old_heading' => 'Shape', 'opaque' => []]);
$table = $db->escapeTable($maintenance->table);
$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['id' => $page->id, 'data' => '{"old_heading":"Shape","opaque":{"empty":{},"list":[],"nested":[{},[]]}}']);
$shapeBefore = $store->document($page, $maintenance)->opaque;
$preview = $type->checkRenames($maintenance);
$same(1, $type->applyRenames($maintenance, $preview)['changed'], 'original document patches apply root rename');
$same(json_encode($shapeBefore), json_encode($store->document($page, $maintenance)->opaque), 'unselected unknown object/list shapes remain exact');
$scan = $type->listOrphans($maintenance);
$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['id' => $page->id, 'data' => '{"heading":"Shape","opaque":{"empty":[],"list":[],"nested":[{},[]]}}']);
$stale = $type->cleanupProperties($maintenance, ['opaque'], $scan);
$assert(!$stale['success'] && str_contains($stale['errors'][0]['message'], 'Stale'), 'object-to-list concurrent change invalidates preview');

// Per-page definitions can intentionally omit properties. Never promote these to a global schema conclusion.
$definitionPath = $trackFile($wire->config->paths->templates . 'flex-maintenance-' . bin2hex(random_bytes(4)) . '.php');
file_put_contents($definitionPath, '<?php namespace ProcessWire; return ["heading" => ["type" => "text"]] + ($page && $page->id === ' . (int) $page->id . ' ? [] : ["contextual" => ["type" => "text"]]);');
$maintenance->flexDefinition = ''; $maintenance->flexDefinitionFile = basename($definitionPath);
$write($page, ['heading' => 'Visible', 'contextual' => 'Intentionally omitted']);
$write($second, ['heading' => 'Visible', 'contextual' => 'Known here']);
$dynamic = $type->listOrphans($maintenance);
$same(1, $dynamic['properties']['contextual']['pageCount'], 'dynamic orphan inventory evaluates each owning Page');
$same([(int) $page->id], $dynamic['properties']['contextual']['pageIds'], 'dynamic candidate is explicitly only potentially orphaned');
$maintenance->flexDefinitionFile = ''; $maintenance->flexDefinition = json_encode($definition);

// Direct table enumeration includes unpublished/hidden owners and explicitly reports dangling owners.
$second->addStatus(Page::statusHidden | Page::statusUnpublished); $second->save();
$same(2, $type->listOrphans($maintenance)['checked'], 'hidden and unpublished pages are included without frontend selector filtering');
$dangling = (int) $db->query('SELECT MAX(id) FROM pages')->fetchColumn() + 10000;
$db->prepare("INSERT INTO `$table` (pages_id,data,search) VALUES (:id,'{}','')")->execute(['id' => $dangling]);
try {
	$scan = $type->listOrphans($maintenance);
	$same($dangling, $scan['errors'][0]['pageId'], 'unresolvable stored owner is an explicit error');
	$assert(!$scan['finished'] && in_array($dangling, $scan['retryPageIds'], true), 'dangling owner never becomes successfully completed');
} finally { $db->prepare("DELETE FROM `$table` WHERE pages_id=:id")->execute(['id' => $dangling]); }

// Configuration rendering does not migrate data, exposes no prune switch and is superuser-only.
$before = $store->persisted($page, $maintenance);
$config = $type->getConfigInputfields($maintenance);
$assert($config->getChildByName('flexMaintenance') instanceof InputfieldFieldset, 'superuser gets collapsed native maintenance fieldset');
$same(null, $config->getChildByName('flexPrune'), 'prune is no longer a field configuration option');
$same(FlexStorage::revision($before), FlexStorage::revision($store->persisted($page, $maintenance)), 'config rendering never performs a migration');
$userBefore = $wire->user;
try {
	$wire->users->setCurrentUser($wire->users->getGuestUser());
	$assert(!$type->getConfigInputfields($maintenance)->getChildByName('flexMaintenance'), 'maintenance controls are unavailable to non-superusers');
} finally { $wire->users->setCurrentUser($userBefore); }

// Exercise the real core dispatcher, not only the thin PHP adapter.
$nativeCli = static function(array $args) use ($wire): array {
	$pipes = [];
	$process = proc_open(array_merge([PHP_BINARY, $wire->config->paths->root . 'index.php', 'flex'], $args),
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $wire->config->paths->root);
	if(!is_resource($process)) throw new \RuntimeException('Unable to start native CLI');
	fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
	return ['status' => proc_close($process), 'out' => $out, 'err' => $err];
};
if(interface_exists(CliModule::class)) {
	$cliResult = $nativeCli(['--help']);
	$same(0, $cliResult['status'], 'real ProcessWire CLI propagates successful help status');
	$assert(str_contains($cliResult['out'], 'one bounded batch'), 'core discovers Flex through metadata and methods without implements');
	$cliResult = $nativeCli(['orphans', $maintenance->name, '--bogus']);
	$same(2, $cliResult['status'], 'real ProcessWire CLI propagates invalid argument exit code');
	$write($second, ['old_heading' => 'Old', 'heading' => 'New']);
	$cliResult = $nativeCli(['rename', $maintenance->name, '--apply']);
	$same(1, $cliResult['status'], 'real ProcessWire CLI propagates conflict exit code');
	$write($second, ['old_heading' => 'Legacy CLI', 'orphan_cli' => 'Remove']);
	$cliResult = $nativeCli(['rename', $maintenance->name, '--apply']);
	$same(0, $cliResult['status'], 'real ProcessWire CLI rename apply succeeds');
	$same('Legacy CLI', $store->persisted($second, $maintenance)['heading'], 'native CLI and API share canonical rename persistence');
	$cliResult = $nativeCli(['orphans', $maintenance->name, '--details', '--limit=100']);
	$same(0, $cliResult['status'], 'real ProcessWire CLI orphan inventory succeeds');
	$decoded = json_decode($cliResult['out'], true, 512, JSON_THROW_ON_ERROR);
	$assert(in_array((int) $second->id, $decoded['properties']['orphan_cli']['pageIds'], true), 'native details output contains affected owner IDs');
	$cliResult = $nativeCli(['cleanup', $maintenance->name, '--path=orphan_cli', '--dry-run']);
	$same(0, $cliResult['status'], 'native cleanup dry-run succeeds');
	$assert(isset($store->persisted($second, $maintenance)['orphan_cli']), 'native cleanup dry-run leaves canonical JSON unchanged');
	$cliResult = $nativeCli(['cleanup', $maintenance->name, '--path=orphan_cli', '--apply']);
	$same(0, $cliResult['status'], 'native explicit cleanup succeeds');
	$assert(!isset($store->persisted($second, $maintenance)['orphan_cli']), 'native explicit cleanup removes selected key');
} else {
	$legacyCli = $nativeCli(['--help']);
	echo 'Native CLI unavailable in pinned core ' . $wire->config->version . ': interface/dispatcher absent; index.php flex --help status=' . $legacyCli['status'] . ', output=' . json_encode($legacyCli['out']) . ". API and adapter tests above still apply.\n";
}

ob_start(); $status = $cli->run(['orphans', $maintenance->name, '--after=4294967295']); ob_end_clean();
$same(0, $status, 'CLI continuation accepts the entire unsigned page-ID range');
ob_start(); $status = $cli->run(['orphans', $maintenance->name, '--after=' . PHP_INT_MAX . '0']); ob_end_clean();
$same(2, $status, 'CLI rejects numeric overflow rather than truncating a cursor');

// Invalid retained envelopes must become explicit errors without corrupting the JSON response with warnings.
$original = $store->document($page, $maintenance);
try {
	foreach(['{"blocks":{"_flex":"rows:1"}}', '{"unknown":{"_flex":"files:1"}}', '7'] as $malformed) {
		$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['id' => $page->id, 'data' => $malformed]);
		ob_start(); $invalid = $type->listOrphans($maintenance); $warnings = ob_get_clean();
		$assert(!$invalid['success'] && !$invalid['finished'], 'invalid stored canonical data is explicitly reported');
		$same('', $warnings, 'invalid stored data never emits warnings into maintenance output');
	}
} finally {
	$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['id' => $page->id, 'data' => json_encode($original)]);
}
