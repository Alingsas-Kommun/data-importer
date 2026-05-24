<?php

namespace DataImporter\Admin;

use DataImporter\Frontend\Display;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all admin_post_* form submissions related to templates.
 *
 * Also owns the asset-sanitization helpers used during template saves.
 */
class TemplateFormHandler {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Register all template-related admin_post hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_data_importer_create_template', array( $this, 'handle_create_template' ) );
		add_action( 'admin_post_data_importer_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_data_importer_delete_template', array( $this, 'handle_delete_template' ) );
	}

	/**
	 * Create a new template under a source.
	 *
	 * @return void
	 */
	public function handle_create_template(): void {
		$source_id = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_create_template_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id ) {
			$this->page->deny_access();
		}

		$name = sanitize_text_field( wp_unslash( $_POST['data_importer_template_name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['data_importer_template_slug'] ?? '' ) );

		$result = Database::insert_template(
			$source_id,
			array(
				'name'           => '' !== $name ? $name : __( 'New Template', 'data-importer' ),
				'slug'           => $slug,
				'template_html'  => '<div class="data-importer-item"><?php echo esc_html( $record[array_key_first( $record )] ?? \'\' ); ?></div>',
				'wrapper_before' => '<div class="data-importer-list">',
				'wrapper_after'  => '</div>',
				'styles_json'    => array(),
				'style_code'     => '',
				'scripts_json'   => array(),
				'script_code'    => '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => AdminPage::PAGE_SLUG,
						'source_id' => $source_id,
						'tab'       => 'template',
						'error'     => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => AdminPage::PAGE_SLUG,
					'source_id'   => $source_id,
					'tab'         => 'template',
					'template_id' => (int) $result,
					'saved'       => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save a template.
	 *
	 * @return void
	 */
	public function handle_save_template(): void {
		$source_id   = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$template_id = isset( $_POST['data_importer_template_id'] ) ? absint( wp_unslash( $_POST['data_importer_template_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_save_template_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id || ! $template_id ) {
			$this->page->deny_access();
		}

		$template = Database::get_template( $template_id );
		if ( ! $template || (int) $template['source_id'] !== $source_id ) {
			wp_die( esc_html__( 'Template not found.', 'data-importer' ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['data_importer_template_name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['data_importer_template_slug'] ?? '' ) );

		$payload = array(
			'template_html'  => wp_unslash( $_POST['data_importer_template_html'] ?? '' ),
			'wrapper_before' => wp_unslash( $_POST['data_importer_wrapper_before'] ?? '' ),
			'wrapper_after'  => wp_unslash( $_POST['data_importer_wrapper_after'] ?? '' ),
			'styles_json'    => $this->sanitize_template_style_assets( wp_unslash( $_POST['data_importer_template_styles'] ?? array() ) ),
			'style_code'     => wp_unslash( $_POST['data_importer_style_code'] ?? '' ),
			'scripts_json'   => $this->sanitize_template_script_assets( wp_unslash( $_POST['data_importer_template_scripts'] ?? array() ) ),
			'script_code'    => wp_unslash( $_POST['data_importer_script_code'] ?? '' ),
		);

		if ( '' !== $name ) {
			$payload['name'] = $name;
		}
		if ( '' !== $slug ) {
			$payload['slug'] = $slug;
		}

		$dry_run = $this->dry_run_template_before_save( $source_id, $template_id, $payload );
		if ( is_wp_error( $dry_run ) ) {
			$this->redirect(
				add_query_arg(
					array(
						'page'        => AdminPage::PAGE_SLUG,
						'source_id'   => $source_id,
						'tab'         => 'template',
						'template_id' => $template_id,
						'error'       => rawurlencode( $dry_run->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			return;
		}

		$updated = Database::update_template( $template_id, $payload );
		if ( $updated ) {
			Database::resolve_template_error_log( $source_id, $template_id );
		}

		$this->redirect(
			add_query_arg(
				array(
					'page'        => AdminPage::PAGE_SLUG,
					'source_id'   => $source_id,
					'tab'         => 'template',
					'template_id' => $template_id,
					'saved'       => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		return;
	}

	/**
	 * Delete a template.
	 *
	 * @return void
	 */
	public function handle_delete_template(): void {
		$source_id   = isset( $_POST['data_importer_source_id'] ) ? absint( wp_unslash( $_POST['data_importer_source_id'] ) ) : 0;
		$template_id = isset( $_POST['data_importer_template_id'] ) ? absint( wp_unslash( $_POST['data_importer_template_id'] ) ) : 0;

		if ( ! check_admin_referer( 'data_importer_delete_template_' . $source_id, 'data_importer_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-importer' ) );
		}

		if ( ! $this->page->can_manage_plugin() || ! $source_id || ! $template_id ) {
			$this->page->deny_access();
		}

		$template = Database::get_template( $template_id );
		if ( ! $template || (int) $template['source_id'] !== $source_id ) {
			$this->redirect(
				add_query_arg(
					array(
						'page'      => AdminPage::PAGE_SLUG,
						'source_id' => $source_id,
						'tab'       => 'template',
						'error'     => rawurlencode( __( 'Template not found.', 'data-importer' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			return;
		}

		$result = Database::delete_template( $template_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect(
				add_query_arg(
					array(
						'page'      => AdminPage::PAGE_SLUG,
						'source_id' => $source_id,
						'tab'       => 'template',
						'error'     => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			return;
		}

		$this->redirect(
			add_query_arg(
				array(
					'page'      => AdminPage::PAGE_SLUG,
					'source_id' => $source_id,
					'tab'       => 'template',
					'saved'     => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		return;
	}

	// -------------------------------------------------------------------------
	// Asset sanitization helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize style repeater payload.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_template_style_assets( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$styles = array();
		$used   = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$src = esc_url_raw( trim( (string) ( $row['src'] ?? '' ) ) );
			if ( '' === $src ) {
				continue;
			}

			$handle = sanitize_key( (string) ( $row['handle'] ?? '' ) );

			$styles[] = array(
				'src'    => $src,
				'handle' => '' !== $handle ? $this->uniquify_asset_handle( $handle, $used ) : '',
			);
		}

		return $styles;
	}

	/**
	 * Sanitize script repeater payload.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_template_script_assets( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$scripts = array();
		$used    = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$src = esc_url_raw( trim( (string) ( $row['src'] ?? '' ) ) );
			if ( '' === $src ) {
				continue;
			}

			$handle = sanitize_key( (string) ( $row['handle'] ?? '' ) );

			$scripts[] = array(
				'src'    => $src,
				'handle' => '' !== $handle ? $this->uniquify_asset_handle( $handle, $used ) : '',
			);
		}

		return $scripts;
	}

	/**
	 * Ensure an asset handle is unique within one asset collection.
	 *
	 * @param string $handle   Requested handle.
	 * @param array  $used     In/out set of used handles.
	 * @return string
	 */
	private function uniquify_asset_handle( string $handle, array &$used ): string {
		$handle = sanitize_key( $handle );

		$base    = $handle;
		$counter = 2;

		while ( in_array( $handle, $used, true ) ) {
			$handle = sanitize_key( $base . '-' . $counter );
			++$counter;
		}

		$used[] = $handle;
		return $handle;
	}

	/**
	 * Validate and dry-run template payload before saving.
	 *
	 * @param int   $source_id   Source ID.
	 * @param int   $template_id Template ID.
	 * @param array $payload     Template fields.
	 * @return true|\WP_Error
	 */
	private function dry_run_template_before_save( int $source_id, int $template_id, array $payload ) {
		$rows = Database::get_records(
			array(
				'source_id' => $source_id,
				'limit'     => 1,
				'order'     => 'ASC',
			)
		);

		$record = array();
		if ( ! empty( $rows ) ) {
			$decoded = json_decode( (string) $rows[0]['record_data'], true );
			if ( is_array( $decoded ) ) {
				$record = $decoded;
			}
		}

		return Display::dry_run_template(
			(string) ( $payload['wrapper_before'] ?? '' ),
			(string) ( $payload['template_html'] ?? '' ),
			(string) ( $payload['wrapper_after'] ?? '' ),
			$record,
			array(
				'source_id'   => $source_id,
				'template_id' => $template_id,
			)
		);
	}

	/**
	 * Redirect after handling a template action.
	 *
	 * Kept as a dedicated method so integration tests can override it without
	 * terminating the PHP process.
	 *
	 * @param string $url Redirect target.
	 * @return void
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
