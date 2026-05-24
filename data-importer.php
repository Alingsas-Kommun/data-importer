<?php
/**
 * Plugin Name: Data Importer & Visualizer
 * Plugin URI: https://www.alingsas.se
 * Description: Receive JSON via REST API, store data per source, and display it with template tags via shortcode.
 * Version: 1.0.3
 * Author: Alingsas Kommun
 * Author URI: https://www.alingsas.se
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-importer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DATAIMPORTER_VERSION', '1.0.3' );
define( 'DATAIMPORTER_DB_VERSION', '1.0.3' );
define( 'DATAIMPORTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DATAIMPORTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DATAIMPORTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once DATAIMPORTER_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Capability required for Data Importer admin and template management.
 *
 * @return string
 */
function data_importer_get_capability() {
	return \DataImporter\Plugin::get_capability();
}

/**
 * Whether current user can manage Data Importer.
 *
 * @return bool
 */
function data_importer_user_can_manage() {
	return \DataImporter\Plugin::user_can_manage();
}

/**
 * Global template safe mode state.
 *
 * When active, PHP template execution is disabled in both frontend and preview.
 *
 * @return bool
 */
function data_importer_is_safe_mode_active() {
	return \DataImporter\Plugin::is_safe_mode_active();
}

register_activation_hook( __FILE__, array( \DataImporter\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( \DataImporter\Plugin::class, 'boot' ) );
