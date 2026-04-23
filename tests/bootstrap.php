<?php

$plugin_root = dirname( __DIR__ );
$wp_root     = dirname( dirname( dirname( $plugin_root ) ) );

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REMOTE_ADDR']    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';

require_once $plugin_root . '/vendor/autoload.php';
require_once $wp_root . '/wp-load.php';

if ( ! defined( 'DATAIMPORTER_PLUGIN_DIR' ) ) {
	require_once $plugin_root . '/data-importer.php';
}

\DataImporter\Plugin::activate();
\DataImporter\Plugin::boot();

rest_get_server();
do_action( 'rest_api_init' );
