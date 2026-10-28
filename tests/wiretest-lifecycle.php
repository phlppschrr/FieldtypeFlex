<?php namespace ProcessWire;
require dirname(__DIR__) . '/scripts/bootstrap.php';

$wire = wire();
$tests = $wire->modules->get('WireTests');
require dirname(__DIR__) . '/FieldtypeFlex.test.php';
putenv('FLEX_WIRETEST_DISPOSABLE=1');
putenv('FLEX_WIRETEST_MEDIA=0');
$assertions = 0;
$check = static function(bool $condition, string $message) use (&$assertions): void {
	if(!$condition) {
		throw new \RuntimeException($message);
	}
	++$assertions;
	echo "PASS: $message\n";
};
$snapshot = static function() use ($wire, $tests): array {
	$fields = [];
	foreach($wire->fields as $field) {
		if(str_starts_with($field->name, WireTests::fieldPrefix . 'flex_')) {
			$fields[] = (int) $field->id;
		}
	}
	sort($fields);
	$pages = $wire->pages->findIDs('parent=' . $tests->getTestPage()->id . ', name^=flex-, include=all, sort=id');
	$files = glob(sys_get_temp_dir() . '/flex-wiretest-*') ?: [];
	sort($files);
	return [$fields, $pages, $files];
};
$before = $snapshot();
$skipped = new WireTest_FieldtypeFlex($tests);
putenv('FLEX_WIRETEST_DISPOSABLE=0');
$check(!$skipped->allow(), 'external opt-in is required');
$skipped->finish();
$skipped->finish();
$check($before === $snapshot(), 'skip cleanup is idempotent and leaves existing fixtures intact');
putenv('FLEX_WIRETEST_DISPOSABLE=1');

// Fail after the first real field save: partial setup must remain registered,
// later independent sections must run, and native failure must reach the caller.
$saved = 0;
$hook = $wire->addHookAfter('Fields::save', static function(HookEvent $event) use (&$saved): void {
	$field = $event->arguments(0);
	if(str_starts_with($field->name, WireTests::fieldPrefix . 'flex_')) {
		++$saved;
		if($saved === 1) {
			throw new WireException('Injected portable setup failure');
		}
	}
});
$test = new WireTest_FieldtypeFlex($tests);
$failure = '';
try {
	$test->execute();
} catch(WireTestException $error) {
	$failure = $error->getMessage();
} finally {
	$wire->removeHook($hook);
	$test->finish();
	$test->finish();
}
$check(str_contains($failure, 'values: Injected portable setup failure'), 'partial setup failure is aggregated and propagated');
$check($saved >= 6, 'later independent sections still execute after setup failure');
$check($before === $snapshot(), 'partial setup and completed sections leave no fields or pages');

// A failed page deletion must not prevent remaining pages/fields being attempted;
// retained cleanup registries permit a second finish() to retry the failed item.
putenv('FLEX_WIRETEST_MEDIA=1');
$test = new WireTest_FieldtypeFlex($tests);
try {
	$test->execute();
	$deleted = 0;
	$hook = $wire->addHookBefore('Pages::delete', static function(HookEvent $event) use (&$deleted): void {
		$page = $event->arguments(0);
		if(str_starts_with($page->name, 'flex-')) {
			++$deleted;
			if($deleted === 1) {
				throw new WireException('Injected portable cleanup failure');
			}
		}
	});
	$cleanupFailure = '';
	try {
		$test->finish();
	} catch(WireException $error) {
		$cleanupFailure = $error->getMessage();
	} finally {
		$wire->removeHook($hook);
	}
	$check(str_contains($cleanupFailure, 'Injected portable cleanup failure'), 'cleanup failure is reported');
	$check($deleted > 1, 'cleanup continues after an individual deletion failure');
} finally {
	$test->finish();
	$test->finish();
}
$check($before === $snapshot(), 'cleanup retry removes remaining fields, pages and media fixtures');
echo "$assertions WireTest lifecycle assertions passed.\n";
