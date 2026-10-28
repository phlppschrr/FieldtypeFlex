<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
$root = dirname(__DIR__);
$bootstrap = getenv('FLEX_PW_ROOT') ?: $root . '/.dev/pw';
$fixtureRoot = realpath($root . '/.dev');
$resolved = realpath($bootstrap);
// Validate before bootstrapping: core initialization may itself perform writes.
if(!$fixtureRoot || !$resolved || !str_starts_with($resolved, $fixtureRoot . DIRECTORY_SEPARATOR)) {
	throw new \RuntimeException('Refusing fixture writes outside this repository\'s .dev directory.');
}
if(!is_file($bootstrap . '/site/assets/installed.php')) {
	throw new \RuntimeException('Run bash scripts/setup.sh first, or set FLEX_PW_ROOT to an installed ProcessWire site.');
}
require $resolved . '/index.php';
wire()->users->setCurrentUser(wire()->users->get('admin'));
wire()->modules->refresh();
wire()->modules->get('FieldtypeFlex');
