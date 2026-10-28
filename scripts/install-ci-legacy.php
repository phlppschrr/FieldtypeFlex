<?php

declare(strict_types=1);

/**
 * Run ProcessWire's web installer headlessly for releases without InstallerCli.
 */

$configFile = isset($argv[1]) ? realpath($argv[1]) : false;
$installRoot = getenv('FLEX_INSTALLER_ROOT') ?: __DIR__ . '/../.dev/pw';
$siteRoot = realpath($installRoot);

if($configFile === false || $siteRoot === false) {
	fwrite(STDERR, "Usage: php scripts/install-ci-legacy.php <install-config.php>\n");
	exit(2);
}

$installConfig = require $configFile;
if(!is_array($installConfig)) {
	fwrite(STDERR, "The installer config must return an array.\n");
	exit(2);
}

$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if($socket === false) {
	fwrite(STDERR, "Unable to reserve a local installer port: $error\n");
	exit(1);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr((string) $address, ':'), 1);

$server = proc_open(
	[PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $siteRoot],
	[
		0 => ['file', '/dev/null', 'r'],
		1 => ['file', '/dev/null', 'a'],
		2 => ['file', '/dev/null', 'a'],
	],
	$pipes,
	$siteRoot
);

if(!is_resource($server)) {
	fwrite(STDERR, "Unable to start the local ProcessWire installer server.\n");
	exit(1);
}

$exitCode = 0;

try {
	$ready = false;
	for($attempt = 0; $attempt < 100; $attempt++) {
		$connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
		if($connection) {
			fclose($connection);
			$ready = true;
			break;
		}
		usleep(50000);
	}
	if(!$ready) throw new RuntimeException('The local ProcessWire installer server did not start.');

	$url = "http://127.0.0.1:$port/install.php";
	$cookies = [];
	$request = static function(?array $data = null) use ($url, &$cookies): string {
		$headers = ['Host: 127.0.0.1', 'Connection: close'];
		if($cookies) $headers[] = 'Cookie: ' . implode('; ', $cookies);
		$options = [
			'http' => [
				'method'        => $data === null ? 'GET' : 'POST',
				'header'        => implode("\r\n", $headers),
				'ignore_errors' => true,
				'timeout'       => 120,
			],
		];
		if($data !== null) {
			$options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
			$options['http']['content'] = http_build_query($data);
		}
		$response = @file_get_contents($url, false, stream_context_create($options));
		if($response === false) throw new RuntimeException('Unable to request the local ProcessWire installer.');

		foreach($http_response_header ?? [] as $header) {
			if(stripos($header, 'Set-Cookie:') !== 0) continue;
			$cookie = trim(explode(';', substr($header, 11), 2)[0]);
			$name = explode('=', $cookie, 2)[0];
			if($name !== '') $cookies[$name] = $cookie;
		}

		return $response;
	};
	$token = static function(string $html): string {
		if(!preg_match('/name=[\'"]install_token[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', $html, $matches)) {
			throw new RuntimeException('The legacy ProcessWire installer did not return its session token.');
		}
		return $matches[1];
	};

	$html = $request();
	$authToken = $token($html);

	$html = $request([
		'step'         => '0',
		'profile'      => $installConfig['profile'] ?? 'site-blank',
		'install_token' => $authToken,
	]);
	if(stripos($html, 'Compatibility Check') === false) {
		throw new RuntimeException('The legacy ProcessWire installer could not select the site profile.');
	}
	$authToken = $token($html);

	$html = $request([
		'step'          => '2',
		'install_token' => $authToken,
	]);
	if(stripos($html, 'MySQL Database') === false) {
		throw new RuntimeException('The legacy ProcessWire installer did not reach database configuration.');
	}
	$authToken = $token($html);

	$timezones = timezone_identifiers_list();
	$timezone = array_search($installConfig['timezone'] ?? 'America/New_York', $timezones, true);
	$httpHosts = $installConfig['httpHosts'] ?? [];
	if(is_array($httpHosts)) $httpHosts = implode("\n", $httpHosts);

	$html = $request([
		'step'          => '4',
		'install_token' => $authToken,
		'dbName'        => $installConfig['dbName'] ?? '',
		'dbUser'        => $installConfig['dbUser'] ?? '',
		'dbPass'        => $installConfig['dbPass'] ?? '',
		'dbCon'         => 'Hostname',
		'dbHost'        => $installConfig['dbHost'] ?? 'localhost',
		'dbPort'        => $installConfig['dbPort'] ?? '3306',
		'dbSocket'      => '',
		'dbCharset'     => 'utf8mb4',
		'dbEngine'      => 'InnoDB',
		'chmodDir'      => '755',
		'chmodFile'     => '644',
		'timezone'      => $timezone === false ? '0' : (string) $timezone,
		'httpHosts'     => $httpHosts,
		'debugMode'     => (string) ($installConfig['debugMode'] ?? 1),
		'themeName'     => 'default',
	]);
	if(!is_file($siteRoot . '/site/config.php') || stripos($html, 'Admin Account') === false) {
		throw new RuntimeException('The legacy ProcessWire installer could not configure the database and profile.');
	}
	$authToken = $token($html);

	$html = $request([
		'step'              => '5',
		'install_token'     => $authToken,
		'admin_name'        => $installConfig['admin_name'] ?? 'processwire',
		'username'          => $installConfig['username'] ?? 'admin',
		'userpass'          => $installConfig['userpass'] ?? '',
		'userpass_confirm'  => $installConfig['userpass'] ?? '',
		'useremail'         => $installConfig['useremail'] ?? '',
	]);
	if(!is_file($siteRoot . '/site/assets/installed.php')) {
		throw new RuntimeException('The legacy ProcessWire installer did not complete the admin account step.');
	}

	$extraConfig = $installConfig['extraConfig'] ?? [];
	if(is_array($extraConfig) && $extraConfig) {
		$lines = [];
		foreach($extraConfig as $name => $value) {
			$name = (string) $name;
			if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
				throw new RuntimeException('Invalid extra config key in the installer config.');
			}
			$lines[] = '$config->' . $name . ' = ' . var_export($value, true) . ';';
		}
		$configPath = $siteRoot . '/site/config.php';
		$configSource = file_get_contents($configPath);
		if($configSource === false) throw new RuntimeException('Unable to read the installed ProcessWire config.');
		$extra = "\n\n/** CI installer extra configuration */\n" . implode("\n", $lines) . "\n";
		if(preg_match('/\?>\s*$/', $configSource)) {
			$configSource = preg_replace('/\?>\s*$/', $extra . "\n?>", $configSource);
		} else {
			$configSource .= $extra;
		}
		if(file_put_contents($configPath, $configSource, LOCK_EX) === false) {
			throw new RuntimeException('Unable to write extra CI installer config.');
		}
	}

	fwrite(STDOUT, "Legacy ProcessWire web installer completed.\n");
} catch(Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	$exitCode = 1;
} finally {
	$status = proc_get_status($server);
	if($status['running']) proc_terminate($server);
	proc_close($server);
}

exit($exitCode);
