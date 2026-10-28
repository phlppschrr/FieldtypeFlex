<?php namespace ProcessWire;

// Guard before bootstrap/module construction: WireTests creates shared fixtures
// before it calls a test's allow(). This runner accepts only our disposable site.
$fixture = realpath(dirname(__DIR__) . '/.dev/pw');
if(
	PHP_SAPI !== 'cli'
	|| !$fixture
	|| !str_contains($fixture, DIRECTORY_SEPARATOR . '.dev' . DIRECTORY_SEPARATOR)
	|| !is_file($fixture . '/site/assets/installed.php')
) {
	throw new \RuntimeException('Set up the disposable .dev/pw fixture first.');
}
putenv('FLEX_PW_ROOT=' . $fixture);
putenv('FLEX_WIRETEST_DISPOSABLE=1');
if(getenv('FLEX_WIRETEST_MEDIA') === false) {
	putenv('FLEX_WIRETEST_MEDIA=1');
}
require __DIR__ . '/bootstrap.php';
$tests = wire()->modules->get('WireTests');
ob_start();
$tests->executeCli(['FieldtypeFlex', '--json']);
$result = ob_get_clean();
echo $result;
$summary = json_decode($result, true);
exit(($summary['status'] ?? '') === 'pass' && ($summary['passed'] ?? 0) === 1 ? 0 : 1);
