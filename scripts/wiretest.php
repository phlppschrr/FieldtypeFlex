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
// Keep the native core baseline separate from the portable Flex contract.
$coreTests = ['FieldtypeText', 'FieldtypeTextarea', 'FieldtypeInteger',
	'FieldtypeFloat', 'FieldtypeDatetime', 'FieldtypeEmail', 'FieldtypeURL',
	'FieldtypeCheckbox', 'FieldtypeToggle', 'FieldtypePage', 'FieldtypeOptions',
	'FieldtypeFile', 'FieldtypeImage'];
$arguments = array_slice($argv, 1);
if($arguments === ['--core']) {
	$failed = false;
	foreach($coreTests as $name) {
		$process = proc_open([PHP_BINARY, __FILE__, '--core-test', $name],
			[0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
		if(!is_resource($process) || proc_close($process) !== 0) $failed = true;
	}
	exit($failed ? 1 : 0);
}
$name = 'FieldtypeFlex';
if(count($arguments) === 2 && $arguments[0] === '--core-test' && in_array($arguments[1], $coreTests, true)) {
	$name = $arguments[1];
} else if($arguments) {
	fwrite(STDERR, "Usage: php scripts/wiretest.php [--core]\n");
	exit(2);
}
putenv('FLEX_PW_ROOT=' . $fixture);
putenv('FLEX_WIRETEST_DISPOSABLE=1');
if(getenv('FLEX_WIRETEST_MEDIA') === false) {
	putenv('FLEX_WIRETEST_MEDIA=1');
}
require __DIR__ . '/bootstrap.php';
if($name !== 'FieldtypeFlex') wire()->modules->get($name);
$tests = wire()->modules->get('WireTests');
ob_start();
$tests->executeCli([$name, '--json']);
$result = ob_get_clean();
echo $result;
$summary = json_decode($result, true);
exit(($summary['status'] ?? '') === 'pass' && ($summary['passed'] ?? 0) === 1 && ($summary['failed'] ?? -1) === 0 && ($summary['skipped'] ?? -1) === 0 ? 0 : 1);
