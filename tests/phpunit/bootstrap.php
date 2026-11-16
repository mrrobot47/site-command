<?php
// Package tests run inside a core checkout that has this package in vendor/easyengine/; core's prepend.php defines EE_ROOT.
if ( ! defined( 'EE_ROOT' ) || ! file_exists( EE_ROOT . '/tests/phpunit/bootstrap.php' ) ) {
	fwrite( STDERR, "Run from a core checkout: php -d auto_prepend_file=tests/phpunit/prepend.php vendor/bin/phpunit -c <this package>/phpunit.xml.dist\n" );
	exit( 1 );
}
require_once EE_ROOT . '/tests/phpunit/bootstrap.php';
require_once __DIR__ . '/includes/SiteTestCase.php';
require_once __DIR__ . '/includes/HttpProxyRecorder.php';
