<?php
// Used only inside the isolated DDEV fixture. The generated password is ignored.
return [
	'profile'   => 'site-blank',
	'dbName'    => 'db',
	'dbUser'    => 'db',
	'dbPass'    => 'db',
	'dbHost'    => getenv('FLEX_DB_HOST') ?: 'db',
	'username'  => 'admin',
	'userpass'  => trim(file_get_contents(dirname(__DIR__) . '/.dev/admin-password')),
	'useremail' => 'admin@example.test',
	'timezone'  => 'Europe/Berlin',
	'httpHosts' => [
		parse_url(getenv('DDEV_PRIMARY_URL') ?: 'https://fieldtype-flex.ddev.site', PHP_URL_HOST),
		'fieldtype-flex.test',
		'fieldtype-flex.ddev.site',
		'localhost',
	],
	'debugMode'   => 1,
	'extraConfig' => ['sessionFingerprint' => false],
];
