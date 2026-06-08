<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Unauthorized\n";
	echo "This script is a CLI background service. Start it from systemd or the shell, not from the browser.\n";
	exit;
}

if (version_compare(PHP_VERSION, '7.1.0', '<')) {
	die("This script requires PHP 7.1.0 or higher. You are running " . PHP_VERSION . "\n");
}

require_once dirname(__DIR__, 4) . '/resources/require.php';
require_once dirname(__DIR__) . '/classes/emergency_alarm_device.php';
require_once dirname(__DIR__) . '/classes/emergency_alarm_service.php';

define('SERVICE_NAME', emergency_alarm_service::SERVICE_NAME);

try {
	$service = emergency_alarm_service::create();
	exit($service->run());
}
catch (Exception $exception) {
	echo "Error occurred in " . $exception->getFile() . ' (' . $exception->getLine() . '): ' . $exception->getMessage();
	exit($exception->getCode() ?: 1);
}
