<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$suites = [
	'compatibility' => ['native-compatibility.php', 'native-selectors.php'],
	'values' => ['values.php'],
	'definitions' => ['definitions.php', 'definition-sources.php', 'examples.php'],
	'formatting' => ['formatting.php', 'textformatters.php'],
	'languages' => ['language-auto.php'],
	'selectors' => ['selectors.php', 'page-traversal.php'],
	'schema' => ['schema-evolution.php'],
	'maintenance' => ['maintenance.php', 'maintenance-repeaters.php'],
	'inputs' => ['inputs.php'],
	'admin' => ['admin.php', 'admin-errors.php', 'row-editor.php'],
	'rows' => ['rows.php', 'row-types.php', 'row-helpers.php'],
	'media' => ['media-lifecycle.php', 'media.php', 'media-output.php', 'media-variations.php', 'media-rename.php'],
	'repeaters' => ['repeaters.php'],
	'nested' => ['nested.php'],
	'translations' => ['translations.php', 'bundled-translations.php'],
	'public-api' => ['public-api.php', 'label-helpers.php', 'array-views.php', 'api-examples.php'],
];

$usage = static function() use ($suites): void {
	fwrite(STDERR, "Usage: php tests/run.php [suite ...]\n       php tests/run.php --list\n");
	fwrite(STDERR, "With no suite names, all available suites run in separate PHP processes.\n");
	fwrite(STDERR, 'Suites: ' . implode(', ', array_keys($suites)) . "\n");
};

$arguments = array_slice($argv, 1);
if($arguments === ['--list']) {
	foreach(array_keys($suites) as $name) echo $name . "\n";
	exit(0);
}
if($arguments === ['--help'] || $arguments === ['-h']) {
	$usage();
	exit(0);
}

if(($arguments[0] ?? null) === '--child') {
	$name = $arguments[1] ?? '';
	$file = $arguments[2] ?? '';
	if(!isset($suites[$name]) || !in_array($file, $suites[$name], true)) {
		fwrite(STDERR, "Unknown suite or file.\n");
		exit(2);
	}
	$path = __DIR__ . '/integration/' . $file;
	try {
		if($file === 'nested.php') {
			require $path;
		} else {
			require __DIR__ . '/support.php';
			runFlexTestSuite($path, $name . '/' . $file);
		}
	} catch(\Throwable $error) {
		fwrite(STDERR, "\nSuite $name/$file failed: " . get_class($error) . ': ' . $error->getMessage() . "\n");
		fwrite(STDERR, $error->getTraceAsString() . "\n");
		exit(1);
	}
	exit(0);
}

if($arguments && $arguments[0] === '--') {
	array_shift($arguments);
}
$requested = $arguments ?: array_keys($suites);
if(!$requested) {
	$usage();
	exit(2);
}

$failed = false;
foreach($requested as $name) {
	if(!isset($suites[$name])) {
		fwrite(STDERR, "Unknown suite: $name\n");
		$usage();
		exit(2);
	}
	foreach($suites[$name] as $file) {
		echo "\n=== $name/$file ===\n";
		$process = proc_open(
			[PHP_BINARY, __FILE__, '--child', $name, $file],
			[0 => STDIN, 1 => STDOUT, 2 => STDERR],
			$pipes,
			dirname(__DIR__)
		);
		if(!is_resource($process) || proc_close($process) !== 0) {
			fwrite(STDERR, "Suite $name/$file failed.\n");
			$failed = true;
		}
	}
}

exit($failed ? 1 : 0);
