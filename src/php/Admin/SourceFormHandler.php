<?php

namespace DataImporter\Admin;

use DataImporter\Infrastructure\Database;
use DataImporter\Admin\Tabs\ManualTab;
use DataImporter\Support\IpRules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all admin_post_* form submissions related to data sources.
 *
 * Extracted from AdminPage to keep handler logic separate from rendering.
 */
class SourceFormHandler {

	private const API_KEY_REVEAL_TRANSIENT_PREFIX = 'data_importer_api_key_reveal_';

	private AdminPage $page;
	private ManualImportProcessor $manual_import_processor;

	public function __construct( AdminPage $page, ?ManualImportProcessor $manual_import_processor = null ) {
		$this->page                    = $page;
		$this->manual_import_processor = $manual_import_processor ? $manual_import_processor : new ManualImportProcessor();
	}

	/**
	 * Store a one-time plaintext key reveal for the current admin user.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $payload   Reveal payload.
	 * @return void
	 */
	public static function remember_api_key_reveal( int $source_id, array $payload ): void {
		$user_id = get_current_user_id();
		if ( $source_id <= 0 || $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::api_key_reveal_transient_key( $source_id, $user_id ),
			$payload,
			MINUTE_IN_SECONDS * 10
		);
	}

	/**
	 * Consume and clear a one-time plaintext key reveal for the current user.
	 *
	 * @param int $source_id Source ID.
	 * @return array|null
	 */
	public static function consume_api_key_reveal( int $source_id ): ?array {
		$user_id = get_current_user_id();
		if ( $source_id <= 0 || $user_id <= 0 ) {
			return null;
		}

		$key   = self::api_key_reveal_transient_key( $source_id, $user_id );
		$value = get_transient( $key );

		delete_transient( $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Register all source-related admin_post hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_data_importer_save_source', array( $this, 'handle_save_source' ) );
		add_action( 'admin_post_data_importer_delete_source', array( $this, 'handle_delete_source' ) );
		add_action( 'admin_post_data_importer_create_api_key', array( $this, 'handle_create_api_key' ) );
		add_action( 'admin_post_data_importer_update_api_key', array( $this, 'handle_update_api_key' ) );
		add_action( 'admin_post_data_importer_regen_key', array( $this, 'handle_regen_key' ) );
		add_action( 'admin_post_data_importer_delete_api_key', array( $this, 'handle_delete_api_key' ) );
		add_action( 'admin_post_data_importer_manual_import', array( $this, 'handle_manual_import' ) );
		add_action( 'wp_ajax_data_importer_clear_data', array( $this, 'ajax_clear_data' ) );
		add_action( 'wp_ajax_data_importer_create_api_key_ajax', array( $this, 'ajax_create_api_key' ) );
	}

	/**
	 * Save (create or update) a data source.
	 *
	 * @return void
	 */
	public function handle_save_source(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_save_source_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() ) {
			$this->page->deny_access();
		}

		$tab             = isset( $_POST['data_importer_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['data_importer_active_tab'] ) ) : 'general';
		$name            = sanitize_text_field( wp_unslash( $_POST['data_importer_name'] ?? '' ) );
		$slug_raw        = sanitize_title( wp_unslash( $_POST['data_importer_slug'] ?? '' ) );
		$import_mode_raw = isset( $_POST['data_importer_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['data_importer_import_mode'] ) ) : '';
		$update_key_raw  = isset( $_POST['data_importer_update_key'] ) ? sanitize_text_field( wp_unslash( $_POST['data_importer_update_key'] ) ) : '';

		if ( 0 === $source_id ) {
			$result = Database::insert_source(
				array(
					'name' => $name,
					'slug' => $slug_raw,
				)
			);

			if ( is_wp_error( $result ) ) {
				$this->redirect(
					add_query_arg(
						array(
							'page'   => AdminPage::PAGE_SLUG,
							'action' => 'new',
							'error'  => rawurlencode( $result->get_error_message() ),
						),
						admin_url( 'admin.php' )
					)
				);
			}

			$source_id = $result;
			$key       = Database::create_source_api_key(
				$source_id,
				array(
					'name' => __( 'Primary Key', 'data-importer' ),
				)
			);

			if ( is_wp_error( $key ) ) {
				Database::delete_source( $source_id );

				$this->redirect(
					add_query_arg(
						array(
							'page'   => AdminPage::PAGE_SLUG,
							'action' => 'new',
							'error'  => rawurlencode( $key->get_error_message() ),
						),
						admin_url( 'admin.php' )
					)
				);
			}

			self::remember_api_key_reveal(
				$source_id,
				array(
					'key_id'      => (int) ( $key['key']['id'] ?? 0 ),
					'name'        => (string) ( $key['key']['name'] ?? __( 'Primary Key', 'data-importer' ) ),
					'secret'      => (string) ( $key['secret'] ?? '' ),
					'allowed_ips' => (string) ( $key['key']['allowed_ips'] ?? '' ),
					'created'     => true,
				)
			);

			$tab = 'api';
		} else {
			$update = array();

			if ( 'general' === $tab && '' !== $name ) {
				$update['name'] = $name;
			}
			if ( 'general' === $tab && '' !== $import_mode_raw ) {
				$update['import_mode'] = $import_mode_raw;
				$update['update_key']  = $update_key_raw;
			}

			if ( ! empty( $update ) ) {
				Database::update_source( $source_id, $update );
			}
		}

		$args = array(
			'page'      => AdminPage::PAGE_SLUG,
			'source_id' => $source_id,
			'tab'       => $tab,
			'saved'     => '1',
		);

		if ( 0 !== $source_id && 'api' === $tab ) {
			$args['reveal'] = '1';
		}

		$this->redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	}

	/**
	 * Delete a data source and all its records.
	 *
	 * @return void
	 */
	public function handle_delete_source(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_delete_source_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id ) {
			$this->page->deny_access();
		}

		Database::delete_source( $source_id );

		$this->redirect(
			add_query_arg(
				array(
					'page'    => AdminPage::PAGE_SLUG,
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Create an additional API key for a source.
	 *
	 * @return void
	 */
	public function handle_create_api_key(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$name      = sanitize_text_field( wp_unslash( $_POST['data_importer_key_name'] ?? '' ) );
		$rules     = IpRules::sanitize_rule_list( (string) wp_unslash( $_POST['data_importer_key_allowed_ips'] ?? '' ) );

		if ( ! check_admin_referer( 'data_importer_create_api_key_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id ) {
			$this->page->deny_access();
		}

		$result = Database::create_source_api_key(
			$source_id,
			array(
				'name'        => $name,
				'allowed_ips' => $rules,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				add_query_arg(
					array(
						'error' => rawurlencode( $result->get_error_message() ),
					),
					$this->page->edit_url( $source_id, 'api' )
				)
			);
		}

		self::remember_api_key_reveal(
			$source_id,
			array(
				'key_id'      => (int) ( $result['key']['id'] ?? 0 ),
				'name'        => (string) ( $result['key']['name'] ?? $name ),
				'secret'      => (string) ( $result['secret'] ?? '' ),
				'allowed_ips' => (string) ( $result['key']['allowed_ips'] ?? $rules ),
				'created'     => true,
			)
		);

		$this->redirect(
			add_query_arg(
				array(
					'saved'  => '1',
					'reveal' => '1',
				),
				$this->page->edit_url( $source_id, 'api' )
			)
		);
	}

	/**
	 * Update one API key's label or IP/CIDR rules.
	 *
	 * @return void
	 */
	public function handle_update_api_key(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$key_id    = isset( $_POST['data_importer_key_id'] ) ? absint( wp_unslash( $_POST['data_importer_key_id'] ) ) : 0;
		$name      = sanitize_text_field( wp_unslash( $_POST['data_importer_key_name'] ?? '' ) );
		$rules     = IpRules::sanitize_rule_list( (string) wp_unslash( $_POST['data_importer_key_allowed_ips'] ?? '' ) );

		if ( ! check_admin_referer( 'data_importer_update_api_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id || ! $key_id ) {
			$this->page->deny_access();
		}

		$key = $this->require_source_api_key( $source_id, $key_id );

		$updated = Database::update_source_api_key(
			$key_id,
			array(
				'name'        => '' !== $name ? $name : (string) ( $key['name'] ?? '' ),
				'allowed_ips' => $rules,
			)
		);

		if ( ! $updated ) {
			$this->redirect(
				add_query_arg(
					array(
						'error' => rawurlencode( __( 'Could not update the API key.', 'data-importer' ) ),
					),
					$this->page->edit_url( $source_id, 'api' )
				)
			);
		}

		$this->redirect(
			add_query_arg(
				array(
					'saved' => '1',
				),
				$this->page->edit_url( $source_id, 'api' )
			)
		);
	}

	/**
	 * Regenerate one API key and reveal the new plaintext once.
	 *
	 * @return void
	 */
	public function handle_regen_key(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$key_id    = isset( $_POST['data_importer_key_id'] ) ? absint( wp_unslash( $_POST['data_importer_key_id'] ) ) : 0;
		$name      = sanitize_text_field( wp_unslash( $_POST['data_importer_key_name'] ?? '' ) );
		$rules     = IpRules::sanitize_rule_list( (string) wp_unslash( $_POST['data_importer_key_allowed_ips'] ?? '' ) );

		if ( ! check_admin_referer( 'data_importer_regen_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id || ! $key_id ) {
			$this->page->deny_access();
		}

		$key    = $this->require_source_api_key( $source_id, $key_id );
		$result = Database::regenerate_source_api_key(
			$key_id,
			array(
				'name'        => '' !== $name ? $name : (string) ( $key['name'] ?? '' ),
				'allowed_ips' => $rules,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				add_query_arg(
					array(
						'error' => rawurlencode( $result->get_error_message() ),
					),
					$this->page->edit_url( $source_id, 'api' )
				)
			);
		}

		self::remember_api_key_reveal(
			$source_id,
			array(
				'key_id'      => (int) ( $result['key']['id'] ?? 0 ),
				'name'        => (string) ( $result['key']['name'] ?? $name ),
				'secret'      => (string) ( $result['secret'] ?? '' ),
				'allowed_ips' => (string) ( $result['key']['allowed_ips'] ?? $rules ),
				'created'     => false,
			)
		);

		$this->redirect(
			add_query_arg(
				array(
					'saved'  => '1',
					'reveal' => '1',
				),
				$this->page->edit_url( $source_id, 'api' )
			)
		);
	}

	/**
	 * Delete one API key.
	 *
	 * @return void
	 */
	public function handle_delete_api_key(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$key_id    = isset( $_POST['data_importer_key_id'] ) ? absint( wp_unslash( $_POST['data_importer_key_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_delete_api_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id || ! $key_id ) {
			$this->page->deny_access();
		}

		$this->require_source_api_key( $source_id, $key_id );
		Database::delete_source_api_key( $key_id );

		$this->redirect(
			add_query_arg(
				array(
					'saved' => '1',
				),
				$this->page->edit_url( $source_id, 'api' )
			)
		);
	}

	/**
	 * Import JSON from the admin UI without using the REST endpoint.
	 *
	 * @return void
	 */
	public function handle_manual_import(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_manual_import_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id ) {
			$this->page->deny_access();
		}

		$redirect_url = $this->page->edit_url( $source_id, 'manual' );
		$raw_json     = isset( $_POST['data_importer_manual_json'] ) ? trim( (string) wp_unslash( $_POST['data_importer_manual_json'] ) ) : '';
		$cache_key    = ManualTab::transient_key( $source_id );

		$import_mode_raw = isset( $_POST['data_importer_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['data_importer_import_mode'] ) ) : '';
		$update_key_raw  = isset( $_POST['data_importer_update_key'] ) ? sanitize_text_field( wp_unslash( $_POST['data_importer_update_key'] ) ) : '';

		$result = $this->manual_import_processor->process(
			$source_id,
			$raw_json,
			array(
				'import_mode' => $import_mode_raw,
				'update_key'  => $update_key_raw,
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			set_transient( $cache_key, $raw_json, MINUTE_IN_SECONDS * 10 );
			$this->redirect(
				add_query_arg(
					array( 'error' => rawurlencode( $result->get_error_message() ) ),
					$redirect_url
				)
			);
		}

		delete_transient( $cache_key );

		$this->redirect(
			add_query_arg(
				array( 'manual_imported' => (string) (int) ( $result['imported'] ?? 0 ) ),
				$redirect_url
			)
		);
	}

	/**
	 * AJAX: create an API key and return the secret + rendered card HTML.
	 *
	 * @return void
	 */
	public function ajax_create_api_key(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$name      = sanitize_text_field( wp_unslash( $_POST['data_importer_key_name'] ?? '' ) );
		$rules     = IpRules::sanitize_rule_list( (string) wp_unslash( $_POST['data_importer_key_allowed_ips'] ?? '' ) );

		if ( ! check_ajax_referer( 'data_importer_create_api_key_' . $source_id, 'data_importer_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'data-importer' ) ), 403 );
			return;
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'data-importer' ) ), 403 );
			return;
		}

		$result = Database::create_source_api_key(
			$source_id,
			array(
				'name'        => $name,
				'allowed_ips' => $rules,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			return;
		}

		$key = $result['key'];

		ob_start();
		( new \DataImporter\Admin\Tabs\ApiTab( $this->page ) )->render_key_card( $source_id, $key );
		$card_html = ob_get_clean();

		wp_send_json_success(
			array(
				'secret'    => (string) ( $result['secret'] ?? '' ),
				'name'      => (string) ( $key['name'] ?? $name ),
				'card_html' => (string) $card_html,
			)
		);
	}

	/**
	 * Delete all records for a source via AJAX.
	 *
	 * @return void
	 */
	public function ajax_clear_data(): void {
		check_ajax_referer( 'data_importer_admin_nonce', 'nonce' );

		if ( ! $this->page->can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'data-importer' ) ), 403 );
		}

		$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;

		if ( ! $source_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data source.', 'data-importer' ) ), 400 );
		}

		Database::truncate_records( $source_id );
		wp_send_json_success( array( 'message' => __( 'Data cleared.', 'data-importer' ) ) );
	}

	/**
	 * Ensure an API key belongs to the current source.
	 *
	 * @param int $source_id Source ID.
	 * @param int $key_id    Key ID.
	 * @return array<string,string>
	 */
	private function require_source_api_key( int $source_id, int $key_id ): array {
		$key = Database::get_source_api_key( $key_id );

		if ( ! $key || (int) ( $key['source_id'] ?? 0 ) !== $source_id ) {
			wp_die( esc_html__( 'API key not found.', 'data-importer' ) );
		}

		return $key;
	}

	/**
	 * Build the reveal transient key for a source/user pair.
	 *
	 * @param int $source_id Source ID.
	 * @param int $user_id   User ID.
	 * @return string
	 */
	private static function api_key_reveal_transient_key( int $source_id, int $user_id ): string {
		return self::API_KEY_REVEAL_TRANSIENT_PREFIX . $user_id . '_' . $source_id;
	}

	/**
	 * Redirect after handling a source action.
	 *
	 * @param string $url Redirect target.
	 * @return void
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
