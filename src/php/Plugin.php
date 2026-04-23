<?php

namespace DataImporter;

use DataImporter\Admin\AdminPage;
use DataImporter\Api\RestController;
use DataImporter\Frontend\Display;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/**
	 * Whether the plugin runtime has been booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Capability required for Data Importer admin and template management.
	 *
	 * @return string
	 */
	public static function get_capability() {
		return (string) apply_filters( 'data_importer_capability', 'manage_options' );
	}

	/**
	 * Whether current user can manage Data Importer.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::get_capability() );
	}

	/**
	 * Global template safe mode state.
	 *
	 * When active, PHP template execution is disabled in both frontend and preview.
	 *
	 * @return bool
	 */
	public static function is_safe_mode_active() {
		$by_constant = defined( 'DATA_IMPORTER_SAFE_MODE' ) && constant( 'DATA_IMPORTER_SAFE_MODE' ) === true;
		$by_option   = (bool) get_option( 'data_importer_safe_mode', false );

		return (bool) apply_filters( 'data_importer_safe_mode_active', ( $by_constant || $by_option ) );
	}

	/**
	 * Whether safe mode is forced by something other than the stored option.
	 *
	 * Returns one of:
	 *   - 'constant' — forced by the DATA_IMPORTER_SAFE_MODE constant.
	 *   - 'filter'   — forced by a custom data_importer_safe_mode_active filter.
	 *   - null       — not locked; the admin toggle is authoritative.
	 *
	 * @return string|null
	 */
	public static function safe_mode_lock_source(): ?string {
		if ( defined( 'DATA_IMPORTER_SAFE_MODE' ) && constant( 'DATA_IMPORTER_SAFE_MODE' ) === true ) {
			return 'constant';
		}

		$by_option = (bool) get_option( 'data_importer_safe_mode', false );
		$filtered  = (bool) apply_filters( 'data_importer_safe_mode_active', $by_option );

		if ( $filtered !== $by_option ) {
			return 'filter';
		}

		return null;
	}

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate() {
		Database::create_table();
	}

	/**
	 * Load the plugin translation files.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'data-importer', false, dirname( DATAIMPORTER_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Boot plugin runtime once WordPress is ready.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::load_textdomain();

		Database::maybe_upgrade();
		RestController::instance();
		Display::instance();

		if ( is_admin() ) {
			AdminPage::instance();
		}

		add_shortcode( 'data_importer', array( Display::class, 'render_shortcode' ) );
	}
}
