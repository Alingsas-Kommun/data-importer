<?php
/**
 * Uninstall handler for Data Importer.
 *
 * Drops all custom tables and removes all plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

DataImporter\Infrastructure\Database::drop_table();

// Remove scalar options.
$scalar_options = array(
	'data_importer_db_version',
	'data_importer_safe_mode',
	// Legacy v1 options (may or may not exist).
	'data_importer_api_key',
	'data_importer_template_html',
	'data_importer_wrapper_before',
	'data_importer_wrapper_after',
	'data_importer_import_log',
);

foreach ( $scalar_options as $opt ) {
	delete_option( $opt );
}

// Remove per-source import logs (option names include source id).
global $wpdb;
$log_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'data\_importer\_import\_log\_%'"
);

foreach ( $log_options as $opt ) {
	delete_option( $opt );
}

// Remove per-source security logs.
$security_log_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'data\_importer\_security\_log\_%'"
);

foreach ( $security_log_options as $opt ) {
	delete_option( $opt );
}

// Remove per-source template runtime/validation error logs.
$template_error_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'data\_importer\_template\_error\_log\_%'"
);

foreach ( $template_error_options as $opt ) {
	delete_option( $opt );
}

// Remove all plugin transients (rate limit, API key reveal, manual import drafts).
// Covers both the value row (_transient_) and its expiry row (_transient_timeout_).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '\_transient\_data\_importer\_%'
	   OR option_name LIKE '\_transient\_timeout\_data\_importer\_%'"
);
