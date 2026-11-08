<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $maintenance, $nativeCli)) { http_response_code(404); exit; }

$invoke = static function(array $args) use ($cli): array {
	ob_start();
	try { $status = $cli->run($args); $out = ob_get_contents(); }
	finally { ob_end_clean(); }
	return [$status, $out];
};
$decode = static fn(string $out): array => json_decode($out, true, 512, JSON_THROW_ON_ERROR);
$schema = (new FlexInspection($type))->schema($maintenance, null);
$same(true, $schema['success'], 'schema discovery succeeds');
$same('discovery/NullPage', $schema['context'], 'schema exposes NullPage context');
$same(['old_heading'], $schema['properties']['heading']['renamedFrom'], 'schema exposes rename mappings');
$same('integer', $schema['properties']['blocks']['types']['text']['children']['types']['item']['number']['kind'], 'schema describes recursive row kinds');
$assert(isset($schema['selectors']['blocks.text.children.item.number'], $schema['selectors']['blocks.text.count']) && !isset($schema['selectors']['blocks.text.id']), 'schema uses genuine typed selector paths');
$assert(!str_contains(json_encode($schema), 'absolutePath'), 'schema omits absolute source paths');

file_put_contents($definitionPath, '<?php namespace ProcessWire; return ["heading" => ["type" => "text"]] + ($page && $page->id === ' . (int) $page->id . ' ? ["page_only" => ["type" => "integer"]] : []);');
$maintenance->flexDefinition = ''; $maintenance->flexDefinitionFile = basename($definitionPath);
$inspect = new FlexInspection($type);
$assert(!isset($inspect->schema($maintenance, null)['properties']['page_only']) && isset($inspect->schema($maintenance, $page->id)['properties']['page_only']), 'schema evaluates discovery and exact Page definitions independently');
$maintenance->flexDefinitionFile = '';
$same(false, $inspect->schema($maintenance, null)['success'], 'schema reports missing definitions even for empty data');
$maintenance->flexDefinition = '{broken';
$same(false, $inspect->schema($maintenance, null)['success'], 'schema reports invalid definitions');
$maintenance->flexDefinition = json_encode($definition); $maintenance->save();

$third = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'flex-cli-' . bin2hex(random_bytes(4))]);
$third->title = 'CLI third owner'; $third->save(); $pages[] = $third;
$db->prepare("DELETE FROM `$table` WHERE pages_id=:id")->execute(['id' => $third->id]);
$maintenanceService = new FlexMaintenance($type);
$same(false, $maintenanceService->page($maintenance, 'orphans', $third->id)['stored'], 'assigned page without storage is explicitly empty');
[$code, $out] = $invoke(['check', $maintenance->name, '--page=' . $third->id, '--json']);
$same(0, $code, 'empty exact page is a successful check'); $same(0, $decode($out)['checked'], 'empty page does not invent canonical storage');
[$code] = $invoke(['orphans', $maintenance->name, '--page=1']); $same(2, $code, 'page without field assignment is a request error');
[$code] = $invoke(['orphans', $maintenance->name, '--page=4294967295']); $same(2, $code, 'nonexistent exact page is a request error');
$write($page, ['old_heading' => 'First', 'obsolete' => 'Delete', 'keep_unknown' => 'Keep']);
$write($second, ['old_heading' => 'Second', 'obsolete' => 'Delete']);
$write($third, ['old_heading' => 'Third']);
[$code, $out] = $invoke(['rename', $maintenance->name, '--page=' . $second->id, '--apply']);
$same(0, $code, 'exact hidden unpublished page rename succeeds');
$same([(int) $second->id], array_column($decode($out)['renames'], 'pageId'), 'exact selection touches only requested hidden owner');
$assert(isset($store->persisted($page, $maintenance)['old_heading']), 'exact apply leaves preceding owner unchanged');

// Fail in the second one-owner batch: previous commits survive and forward scanning stops.
$write($second, ['old_heading' => 'Old', 'heading' => 'Different', 'obsolete' => 'Delete']);
[$code, $out] = $invoke(['rename', $maintenance->name, '--all', '--limit=1', '--apply', '--json']);
$lines = array_map($decode, explode("\n", trim($out))); $summary = end($lines);
$same(1, $code, 'all stops with failure status on a middle-batch conflict');
$same(3, count($lines), 'stream has two batch records and one summary');
$same(2, $summary['checked'], 'all progress includes owners rejected during preview');
$same('summary', $summary['record'], 'JSON-Lines ends with a typed summary');
$same([(int) $second->id], $summary['retryPageIds'], 'all identifies failed exact owners');
$same((int) $second->id - 1, $summary['resumeAfterPageId'], 'all exposes a safe retry cursor');
$same(false, $summary['finished'], 'failed all does not claim completion');
$assert(isset($store->persisted($page, $maintenance)['heading']) && isset($store->persisted($third, $maintenance)['old_heading']), 'earlier writes survive and later batches wait for explicit continuation');
$write($second, ['old_heading' => 'Fixed', 'obsolete' => 'Delete']);
[$code, $out] = $invoke(['rename', $maintenance->name, '--all', '--limit=1', '--after=' . $summary['resumeAfterPageId'], '--apply', '--json']);
$same(0, $code, 'all resumes after conflict repair');
$lines = array_map($decode, explode("\n", trim($out))); $same(true, end($lines)['finished'], 'resumed completion covers selected suffix');
[$code, $out] = $invoke(['rename', $maintenance->name, '--all', '--limit=1', '--apply', '--json']);
$lines = array_map($decode, explode("\n", trim($out))); $same(0, end($lines)['changed'], 'all rename is idempotent');

// Many occurrences on one page must count as one owner across batch aggregation.
$many = $rows; $many['items'][0]['values'] = ['obsolete' => 'One'];
$copy = $many['items'][0]; $copy['id'] = bin2hex(random_bytes(16)); $many['items'][] = $copy;
$write($page, ['heading' => 'Clean', 'blocks' => $many, 'obsolete' => 'Root', 'keep_unknown' => 'Keep']);
[$code, $out] = $invoke(['orphans', $maintenance->name, '--all', '--limit=1', '--json', '--details']);
$lines = array_map($decode, explode("\n", trim($out))); $summary = end($lines);
$same(1, $summary['properties']['blocks.text.obsolete']['pageCount'], 'all aggregates distinct owners rather than occurrences');
$same(2, $summary['properties']['blocks.text.obsolete']['occurrences'], 'all retains occurrence totals');
$assert(!isset($summary['properties']['blocks.text.obsolete']['locations']), 'summary does not accumulate detail locations');
[$code] = $invoke(['cleanup', $maintenance->name, '--page=' . $page->id, '--path=obsolete', '--apply']);
$afterCleanup = $store->persisted($page, $maintenance);
$assert(!isset($afterCleanup['obsolete']) && isset($afterCleanup['keep_unknown'], $afterCleanup['blocks']['items'][0]['values']['obsolete']), 'exact cleanup retains unselected root and nested values');

// A normal Fieldtype save derives search before the JSON database orders object keys.
$diagnosticField = $createField('flex_check_search', [
	'long_heading' => ['type' => 'text'], 'a' => ['type' => 'text'], 'zz' => ['type' => 'text'],
]);
$page->set($diagnosticField->name, ['long_heading' => 'First phrase', 'a' => 'Second', 'zz' => 'Third']);
$page->save($diagnosticField->name);
$same(true, $maintenanceService->page($diagnosticField, 'check', $page->id)['success'], 'check accepts search derived by an ordinary Fieldtype save after database JSON normalization');

// Capture database readback before all diagnostic handlers, independent of cached FlexValue objects.
$snapshotAll = static function() use ($db, $table): array {
	return [$db->query("SELECT * FROM `$table` ORDER BY pages_id")->fetchAll(\PDO::FETCH_ASSOC),
		$db->query('SELECT * FROM fieldtype_flex_rows ORDER BY fields_id,pages_id,set_key,row_id')->fetchAll(\PDO::FETCH_ASSOC),
		$db->query('SELECT * FROM fieldtype_flex_assets ORDER BY asset_id')->fetchAll(\PDO::FETCH_ASSOC)];
};
// JSON object member order can change in storage; flat search text has no property boundaries.
$searchBefore = $db->query("SELECT search FROM `$table` WHERE pages_id=" . (int) $page->id)->fetchColumn();
$terms = explode(' ', $searchBefore);
$db->prepare("UPDATE `$table` SET search=:search WHERE pages_id=:id")->execute(['search' => implode(' ', array_reverse($terms)), 'id' => $page->id]);
$checkSearch = static fn(): array => $maintenanceService->page($maintenance, 'check', $page->id);
$same(true, $checkSearch()['success'], 'check accepts the same search content in a different object property order');
foreach([substr($searchBefore, 1), $searchBefore . ' extra', $searchBefore . ' ' . $terms[0]] as $corruptSearch) {
	$db->prepare("UPDATE `$table` SET search=:search WHERE pages_id=:id")->execute(['search' => $corruptSearch, 'id' => $page->id]);
	$assert(in_array('search_projection', array_column($checkSearch()['findings'], 'category'), true), 'check rejects missing, additional or duplicate search content');
}
$db->prepare("UPDATE `$table` SET search=:search WHERE pages_id=:id")->execute(['search' => $searchBefore, 'id' => $page->id]);
$beforeReads = $snapshotAll();
foreach([['schema', $maintenance->name], ['schema', $maintenance->name, '--page=' . $page->id], ['check', $maintenance->name], ['status'], ['orphans', $maintenance->name]] as $args) {
	[$code, $out] = $invoke(array_merge($args, ['--json'])); $same(0, $code, 'read-only diagnostic handler succeeds: ' . $args[0] . ($code === 0 ? '' : ' output=' . $out));
	$decode($out);
}
$same($beforeReads, $snapshotAll(), 'diagnostic handlers preserve canonical, projections and ownership byte for byte');

$categories = static fn(array $result): array => array_column($result['findings'], 'category');
$check = static fn(): array => $maintenanceService->page($maintenance, 'check', $page->id);
$db->prepare("UPDATE `$table` SET search='incorrect' WHERE pages_id=:id")->execute(['id' => $page->id]);
$assert(in_array('search_projection', $categories($check()), true), 'check detects incorrect search projection');
$write($page, $afterCleanup);
$db->prepare('UPDATE fieldtype_flex_rows SET data=:data WHERE fields_id=:field AND pages_id=:page')->execute(['data' => '{}', 'field' => $maintenance->id, 'page' => $page->id]);
$assert(in_array('row_projection', $categories($check()), true), 'check detects corrupted row payload');
$write($page, $afterCleanup);
$db->prepare('UPDATE fieldtype_flex_rows SET data=:data WHERE fields_id=:field AND pages_id=:page')->execute(['data' => '{broken', 'field' => $maintenance->id, 'page' => $page->id]);
$assert(in_array('row_projection', $categories($check()), true), 'invalid projection JSON is a known consistency error');
$write($page, $afterCleanup);
// Search content comparison must not hide a changed canonical Row order.
$reordered = $afterCleanup;
$reordered['blocks']['items'] = array_reverse($reordered['blocks']['items']);
$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['data' => FlexStorage::encode($reordered), 'id' => $page->id]);
$assert(in_array('row_projection', $categories($check()), true), 'check detects reordered canonical Rows against unchanged projection ordinals');
$write($page, $afterCleanup);
// Reorder JSON keys only: semantic comparison must not flag a projection error.
$q = $db->prepare('SELECT row_key,data FROM fieldtype_flex_rows WHERE fields_id=:field AND pages_id=:page'); $q->execute(['field' => $maintenance->id, 'page' => $page->id]);
foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $entry) {
	$data = json_decode($entry['data'], true); $data = array_reverse($data, true);
	$db->prepare('UPDATE fieldtype_flex_rows SET data=:data WHERE fields_id=:field AND pages_id=:page AND row_key=:key')->execute(['data' => json_encode((object) $data), 'field' => $maintenance->id, 'page' => $page->id, 'key' => $entry['row_key']]);
}
$same(true, $check()['success'], 'check accepts semantic row projections with different key order and unknown properties');
foreach(['7', '[]', '{"blocks":{"_flex":"rows:1"}}'] as $bad) {
	$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['data' => $bad, 'id' => $page->id]);
	$broken = $check(); $same(false, $broken['success'], 'check detects invalid canonical JSON/envelope');
	$assert($broken['skippedChecks'] !== [] && $broken['retryPageIds'] === [(int) $page->id], 'failed checks expose skipped work and retry owner');
}
if(stripos($db->getServerType(), 'maria') !== false) {
	$db->exec('SET SESSION check_constraint_checks=OFF');
	try {
		$db->prepare("UPDATE `$table` SET data=:data WHERE pages_id=:id")->execute(['data' => '{broken', 'id' => $page->id]);
		$assert(in_array('canonical_json', $categories($check()), true), 'check detects syntactically damaged JSON when database constraint is bypassed');
		$write($page, ['heading' => 'Safe']);
	} finally { $db->exec('SET SESSION check_constraint_checks=ON'); }
}
$write($page, ['old_heading' => 'Pending']);
$pending = $check(); $same(true, $pending['success'], 'pending rename is a warning, not a consistency error');
$assert(in_array('pending_rename', $categories($pending), true), 'pending rename is explicitly reported');
$write($page, ['blocks' => ['_flex' => 'rows:1', 'items' => [['id' => bin2hex(random_bytes(16)), 'type' => 'unknown', 'values' => []]]]]);
$assert(in_array('row_schema', $categories($check()), true), 'check detects unknown row type');
$write($page, ['heading' => 'Safe']);

// Create a fresh live File: the earlier draft test deliberately removed its original.
$assetField->flexDefinition = json_encode(['file' => ['type' => 'file', 'extensions' => 'txt'], 'note' => ['type' => 'text']]); $assetField->save();
$page = $wire->pages->getFresh($page->id); $page->of(false);
$page->{$assetField->name}->file->add($fixture); $page->save($assetField->name);
$liveFile = $page->{$assetField->name}->file->first(); $rootAsset = $liveFile->assetId(); $rootPath = $liveFile->filename();
$assetTable = $db->escapeTable($assetField->table);
$assetDocument = $db->query("SELECT data FROM `$assetTable` WHERE pages_id=" . (int) $page->id)->fetchColumn();
$wrongEnvelope = json_decode($assetDocument, true); $wrongEnvelope['file']['_flex'] = 'files:2';
$db->prepare("UPDATE `$assetTable` SET data=:data WHERE pages_id=:id")->execute(['data' => json_encode($wrongEnvelope), 'id' => $page->id]);
$assert(in_array('envelope', $categories($maintenanceService->page($assetField, 'check', $page->id)), true), 'known media property rejects an unsupported envelope version');
$db->prepare("UPDATE `$assetTable` SET data=:data WHERE pages_id=:id")->execute(['data' => $assetDocument, 'id' => $page->id]);
$assetCheck = $maintenanceService->page($assetField, 'check', $page->id);
$same(true, $assetCheck['success'], 'media checker accepts committed original and current ownership');
$assetTable = $db->escapeTable($assetField->table);
$db->prepare('UPDATE fieldtype_flex_assets SET address=:address WHERE asset_id=:id')->execute(['address' => 'wrong', 'id' => $rootAsset]);
$assert(in_array('media_reference', $categories($maintenanceService->page($assetField, 'check', $page->id)), true), 'check detects ownership address corruption without cache');
$db->prepare('UPDATE fieldtype_flex_assets SET address=:address WHERE asset_id=:id')->execute(['address' => 'file', 'id' => $rootAsset]);
$originalJournal = $db->query("SELECT data FROM fieldtype_flex_assets WHERE asset_id='" . $rootAsset . "'")->fetchColumn();
$db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute(['data' => '{broken', 'id' => $rootAsset]);
$assert(in_array('media_reference', $categories($maintenanceService->page($assetField, 'check', $page->id)), true), 'invalid ownership JSON is a known consistency error');
$db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute(['data' => $originalJournal, 'id' => $rootAsset]);
$badJournal = json_decode($originalJournal, true); $badJournal['directory'] = ['invalid'];
$db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute(['data' => json_encode($badJournal), 'id' => $rootAsset]);
[$code, $out] = $invoke(['check', $assetField->name, '--page=' . $page->id, '--json']);
$same(1, $code, 'invalid journal directory is a known consistency error');
$assert(in_array('media_reference', $categories($decode($out)), true), 'malformed journal inspection retains valid JSON output');
$db->prepare('UPDATE fieldtype_flex_assets SET data=:data WHERE asset_id=:id')->execute(['data' => $originalJournal, 'id' => $rootAsset]);
rename($rootPath, $rootPath . '.held');
try { $assert(in_array('media_original', $categories($maintenanceService->page($assetField, 'check', $page->id)), true), 'check detects missing registered original'); }
finally { rename($rootPath . '.held', $rootPath); }

// A missing optional variation is normal; only registered originals are required.
$imageFixture = $trackFile(dirname(__DIR__, 2) . '/.dev/cli-image-' . bin2hex(random_bytes(4)) . '.png');
$image = imagecreatetruecolor(12, 12); imagepng($image, $imageFixture); imagedestroy($image);
$assetField->flexDefinition = json_encode(['file' => ['type' => 'file', 'extensions' => 'txt'], 'photo' => ['type' => 'image'], 'note' => ['type' => 'text']]); $assetField->save();
$page = $wire->pages->getFresh($page->id); $page->of(false);
$page->{$assetField->name}->photo->add($imageFixture); $page->save($assetField->name);
$photo = $page->{$assetField->name}->photo->first(); $variation = $photo->size(6, 6); unlink($variation->filename());
$same(true, $maintenanceService->page($assetField, 'check', $page->id)['success'], 'missing optional image variation does not fail a check');
$assetField->flexDefinition = json_encode(['file' => ['type' => 'file', 'extensions' => 'txt'], 'note' => ['type' => 'text']]); $assetField->save();
[$code] = $invoke(['cleanup', $assetField->name, '--page=' . $page->id, '--path=photo', '--apply']);
$same(0, $code, 'exact CLI cleanup removes selected media property');
$retained = $store->persisted($page, $assetField);
$assert(!isset($retained['photo']) && $retained['file']['items'][0]['id'] === $rootAsset && is_file($rootPath), 'exact media cleanup preserves unselected ownership and original');

$hook = $wire->addHookAfter('PagefilesManager::path', static function(HookEvent $event): void {});
try {
	[$code, $out] = $invoke(['check', $assetField->name, '--page=' . $page->id, '--json']);
	$same(3, $code, 'custom native path hook makes original check explicitly incomplete');
	$assert(in_array('original_path', array_column($decode($out)['skippedChecks'], 'check'), true), 'path hook omission is machine-readable');
} finally { $wire->removeHook($hook); }

// Status is a journal summary. Pending and expired records are normal, not errors.
$db->beginTransaction();
try {
	$db->prepare("UPDATE fieldtype_flex_assets SET state='staged',expires=:expiry WHERE asset_id=:id")->execute(['expiry' => time() - 1, 'id' => $rootAsset]);
	$operating = $inspect->status();
	$assert(($operating['mediaStates']['pending_delete'] ?? 0) >= 1, 'status reports waiting deletions without collecting them');
	$assert($operating['expiredTemporary'] >= 1 && $operating['mediaStates']['staged'] >= 1 && $operating['success'], 'status counts expired temporary media without failing or collecting');
} finally { $db->rollBack(); }

foreach([
	['check', '--apply'], ['schema', '--all'], ['status', '--page=1'], ['orphans', '--page=' . $page->id, '--all'],
	['rename', '--page=' . $page->id, '--after=0'], ['rename', '--page=0'], ['check', '--limit=1001'],
	['check', '--limit=01'], ['check', '--limit=1', '--limit=2'], ['check', '--json=1'],
	['check', '--after=' . PHP_INT_MAX . '0'], ['cleanup', '--path=obsolete', '--path=obsolete'], ['rename', '--apply', '--dry-run'],
] as $invalid) {
	$op = array_shift($invalid); [$code] = $invoke(array_merge([$op], $op === 'status' ? [] : [$maintenance->name], $invalid));
	$same(2, $code, 'strict command-specific argument validation');
}
[$code, $out] = $invoke(['check', $maintenance->name, '--all', '--after=4294967295', '--json']);
$lines = array_map($decode, explode("\n", trim($out))); $same(0, end($lines)['checked'], 'empty all range finishes without looping');
$same(true, end($lines)['finished'], 'empty range is complete');

if(interface_exists(CliModule::class)) {
	foreach([['schema', $maintenance->name, '--json'], ['schema', $maintenance->name, '--page=' . $page->id, '--json'], ['status', '--json'], ['check', $maintenance->name, '--all', '--limit=1', '--json']] as $args) {
		$native = $nativeCli($args); $same(0, $native['status'], 'native dispatcher executes new command ' . $args[0]);
		if(!in_array('--all', $args, true)) $decode($native['out']);
	}
	$db->prepare("UPDATE `$table` SET search='bad' WHERE pages_id=:id")->execute(['id' => $page->id]);
	$same(1, $nativeCli(['check', $maintenance->name, '--page=' . $page->id, '--json'])['status'], 'native dispatcher propagates check error status');
	$write($page, ['heading' => 'Safe']);
}

// Execute every published CLI example against the owned field and Page fixture.
$api = file_get_contents(dirname(__DIR__, 2) . '/API.md');
$same(1, preg_match('/^### Native CLI[^\n]*\n(.*?)(?=^#{1,3} |\z)/ms', $api, $chapter), 'published native CLI chapter is found');
preg_match_all('/^php index\.php flex (.+)$/m', $chapter[1], $examples);
$same(9, count($examples[1]), 'all published CLI examples are exercised');
foreach($examples[1] as $example) {
	$args = explode(' ', str_replace(['content', '1234'], [$maintenance->name, (string) $page->id], $example));
	[$code] = $invoke($args);
	$same(0, $code, 'published CLI example executes: ' . $example);
}

// A missing derived table prevents that check; it does not prove a canonical error.
$heldTable = 'flex_check_rows_' . bin2hex(random_bytes(4));
$db->exec('RENAME TABLE fieldtype_flex_rows TO ' . $heldTable);
try {
	[$code, $out] = $invoke(['check', $maintenance->name, '--page=' . $page->id, '--json']);
	$same(3, $code, 'unavailable derived storage yields incomplete exit 3');
	$assert($decode($out)['skippedChecks'] !== [], 'incomplete report explicitly identifies skipped checks');
	if(interface_exists(CliModule::class)) $same(3, $nativeCli(['check', $maintenance->name, '--page=' . $page->id, '--json'])['status'], 'native dispatcher propagates incomplete exit 3');
} finally { $db->exec('RENAME TABLE ' . $heldTable . ' TO fieldtype_flex_rows'); }
