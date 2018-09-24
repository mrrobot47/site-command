<?php

if ( ! defined( 'SITE_TEMPLATE_ROOT' ) ) {
	define( 'SITE_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! defined( 'GLOBAL_DB' ) ) {
	define( 'GLOBAL_DB', 'global-db' );
}

if ( ! defined( 'GLOBAL_DB_CONTAINER' ) ) {
	define( 'GLOBAL_DB_CONTAINER', 'ee-global-db' );
}

if ( ! defined( 'GLOBAL_REDIS' ) ) {
	define( 'GLOBAL_REDIS', 'global-redis' );
}

if ( ! defined( 'GLOBAL_REDIS_CONTAINER' ) ) {
	define( 'GLOBAL_REDIS_CONTAINER', 'ee-global-redis' );
}

if ( ! defined( 'GLOBAL_NETWORK' ) ) {
	define( 'GLOBAL_NETWORK', 'ee-global-network' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'site', 'Site_Command' );
Site_Command::add_site_type( 'html', 'EE\Site\Type\HTML' );
