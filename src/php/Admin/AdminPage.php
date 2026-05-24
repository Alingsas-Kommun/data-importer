<?php

namespace DataImporter\Admin;

use DataImporter\Admin\Views\EditView;
use DataImporter\Admin\Views\ListView;
use DataImporter\Admin\Views\NewSourceView;
use DataImporter\Infrastructure\Database;
use DataImporter\Plugin;
use DataImporter\Support\Assets;

/**
 * Admin UI for Data Importer.
 *
 * Orchestrates hooks, asset enqueueing, and view dispatching.
 * Rendering is delegated to tab classes in the Tabs/ directory;
 * form handling is delegated to SourceFormHandler / TemplateFormHandler.
 *
 * Views:
 *   List   – ?page=data-importer
 *   New    – ?page=data-importer&action=new
 *   Edit   – ?page=data-importer&source_id=X[&tab=general|template|api|manual|data|log|about]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPage {

	private static ?AdminPage $instance = null;

	/** Admin page slug used everywhere this plugin registers admin URLs. */
	const PAGE_SLUG = 'data-importer';

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor – wires up all WordPress hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_settings_url' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_safe_mode_toggle' ) );

		// Delegate form submissions to dedicated handler classes.
		( new SourceFormHandler( $this ) )->register_hooks();
		( new TemplateFormHandler( $this ) )->register_hooks();
	}

	/**
	 * Redirect old Settings URL to new top-level menu URL.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_settings_url() {
		global $pagenow;

		if ( ! is_admin() || 'options-general.php' !== $pagenow ) {
			return;
		}

		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$args = array( 'page' => self::PAGE_SLUG );

		if ( isset( $_GET['action'] ) ) {
			$args['action'] = sanitize_key( wp_unslash( (string) $_GET['action'] ) );
		}
		if ( isset( $_GET['source_id'] ) ) {
			$args['source_id'] = absint( wp_unslash( $_GET['source_id'] ) );
		}
		if ( isset( $_GET['tab'] ) ) {
			$args['tab'] = sanitize_key( wp_unslash( (string) $_GET['tab'] ) );
		}
		if ( isset( $_GET['template_id'] ) ) {
			$args['template_id'] = absint( wp_unslash( $_GET['template_id'] ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$args['error'] = sanitize_text_field( wp_unslash( (string) $_GET['error'] ) );
		}
		foreach ( array( 'saved', 'deleted', 'safe_mode_updated', 'data_importer_safe_mode', 'reveal' ) as $flag_key ) {
			if ( isset( $_GET[ $flag_key ] ) ) {
				$args[ $flag_key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $flag_key ] ) );
			}
		}
		if ( isset( $_GET['data_importer_nonce'] ) ) {
			$args['data_importer_nonce'] = sanitize_text_field( wp_unslash( (string) $_GET['data_importer_nonce'] ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// URL helpers
	// -------------------------------------------------------------------------

	/**
	 * Base page URL.
	 *
	 * @return string
	 */
	public function list_url(): string {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL for the new-source form.
	 *
	 * @return string
	 */
	public function new_url(): string {
		return add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'new' ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL for a source's edit view.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $tab       Tab slug (general|template|api|manual|data|log|about).
	 * @return string
	 */
	public function edit_url( int $source_id, string $tab = 'general' ): string {
		return add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'source_id' => (int) $source_id,
				'tab'       => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL for template tab with selected template.
	 *
	 * @param int $source_id   Source ID.
	 * @param int $template_id Template ID.
	 * @return string
	 */
	public function template_url( int $source_id, int $template_id = 0 ): string {
		$args = array(
			'page'      => self::PAGE_SLUG,
			'source_id' => (int) $source_id,
			'tab'       => 'template',
		);

		if ( $template_id > 0 ) {
			$args['template_id'] = (int) $template_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Capability gate for all admin/template operations.
	 *
	 * @return bool
	 */
	public function can_manage_plugin(): bool {
		return Plugin::user_can_manage();
	}

	/**
	 * Stop request with standardized access denied message.
	 *
	 * @return void
	 */
	public function deny_access(): void {
		wp_die(
			esc_html__( 'Insufficient permissions.', 'data-importer' ),
			esc_html__( 'Access Denied', 'data-importer' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Build URL to toggle global template safe mode.
	 *
	 * @param bool $enable Target mode.
	 * @return string
	 */
	private function safe_mode_toggle_url( $enable ) {
		$args = array(
			'page'                    => self::PAGE_SLUG,
			'data_importer_safe_mode' => $enable ? '1' : '0',
		);

		if ( isset( $_GET['source_id'] ) ) {
			$args['source_id'] = absint( wp_unslash( $_GET['source_id'] ) );
		}
		if ( isset( $_GET['tab'] ) ) {
			$args['tab'] = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		if ( isset( $_GET['template_id'] ) ) {
			$args['template_id'] = absint( wp_unslash( $_GET['template_id'] ) );
		}

		$url = add_query_arg( $args, admin_url( 'admin.php' ) );

		return wp_nonce_url( $url, 'data_importer_toggle_safe_mode', 'data_importer_nonce' );
	}

	/**
	 * Toggle global safe mode for template execution.
	 *
	 * @return void
	 */
	public function maybe_handle_safe_mode_toggle() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['data_importer_safe_mode'] ) ) {
			return;
		}

		if ( ! $this->can_manage_plugin() ) {
			$this->deny_access();
		}

		check_admin_referer( 'data_importer_toggle_safe_mode', 'data_importer_nonce' );

		$enabled = '1' === (string) wp_unslash( $_GET['data_importer_safe_mode'] );
		update_option( 'data_importer_safe_mode', $enabled ? '1' : '0', false );

		$redirect_args = array(
			'page'              => self::PAGE_SLUG,
			'safe_mode_updated' => '1',
		);
		if ( isset( $_GET['source_id'] ) ) {
			$redirect_args['source_id'] = absint( wp_unslash( $_GET['source_id'] ) );
		}
		if ( isset( $_GET['tab'] ) ) {
			$redirect_args['tab'] = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		if ( isset( $_GET['template_id'] ) ) {
			$redirect_args['template_id'] = absint( wp_unslash( $_GET['template_id'] ) );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render safe-mode status notices in the plugin admin.
	 *
	 * @return void
	 */
	private function render_safe_mode_notices() {
		$is_safe_mode = Plugin::is_safe_mode_active();
		$lock_source  = Plugin::safe_mode_lock_source();

		if ( isset( $_GET['safe_mode_updated'] ) ) {
			if ( null !== $lock_source ) {
				$message = 'constant' === $lock_source
					? __( 'Your change was saved, but safe mode is currently locked by the DATA_IMPORTER_SAFE_MODE constant and cannot be changed from this screen.', 'data-importer' )
					: __( 'Your change was saved, but safe mode is currently locked by a custom filter and cannot be changed from this screen.', 'data-importer' );
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			} else {
				$message = $is_safe_mode
					? __( 'Safe mode enabled: PHP templates will not run until you disable it.', 'data-importer' )
					: __( 'Safe mode disabled: PHP templates are running again.', 'data-importer' );
				$class   = $is_safe_mode ? 'notice-warning' : 'notice-success';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( null !== $lock_source ) {
			$message = 'constant' === $lock_source
				? sprintf(
					/* translators: %s: PHP constant name */
					__( 'Safe mode is locked by the %s constant. The stored option and the toggle on this page have no effect until the constant is removed.', 'data-importer' ),
					'DATA_IMPORTER_SAFE_MODE'
				)
				: sprintf(
					/* translators: %s: WordPress filter name */
					__( 'Safe mode is locked by a custom %s filter. The stored option and the toggle on this page have no effect until the filter is removed.', 'data-importer' ),
					'data_importer_safe_mode_active'
				);
			echo '<div class="notice notice-info"><p>';
			echo '<strong>' . esc_html__( 'Safe mode override in effect.', 'data-importer' ) . '</strong> ';
			echo esc_html( $message );
			echo '</p></div>';
		}

		if ( $is_safe_mode ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Safe mode is active. PHP templates will not run on the frontend until it is disabled.', 'data-importer' );
			if ( null === $lock_source ) {
				$disable_url = $this->safe_mode_toggle_url( false );
				echo ' <a class="button button-small" href="' . esc_url( $disable_url ) . '">' . esc_html__( 'Disable Safe Mode', 'data-importer' ) . '</a>';
			}
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Shortcode + copy (admin UI)
	// -------------------------------------------------------------------------

	/**
	 * Inline shortcode text + Dashicons clipboard button (same row).
	 *
	 * @param string $shortcode Full shortcode to show and copy.
	 * @return void
	 */
	public function render_shortcode_copy_control( string $shortcode ): void {
		$shortcode = (string) $shortcode;
		$label     = __( 'Copy shortcode', 'data-importer' );
		?>
		<span class="data-importer-shortcode-with-copy">
			<code class="data-importer-shortcode-text"><?php echo esc_html( $shortcode ); ?></code>
			<button type="button" class="button button-small data-importer-copy data-importer-copy-shortcode-icon" data-clipboard-text="<?php echo esc_attr( $shortcode ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
			</button>
		</span>
		<?php
	}

	/**
	 * Pre block with shortcode + clipboard button on same row (Om-flik m.m.).
	 *
	 * @param string $shortcode Full shortcode to show and copy.
	 * @return void
	 */
	public function render_shortcode_pre_with_copy( string $shortcode ): void {
		$shortcode = (string) $shortcode;
		$label     = __( 'Copy shortcode', 'data-importer' );
		?>
		<div class="data-importer-shortcode-pre-wrap">
			<pre class="data-importer-code-block"><code><?php echo esc_html( $shortcode ); ?></code></pre>
			<button type="button" class="button button-small data-importer-copy data-importer-copy-shortcode-icon" data-clipboard-text="<?php echo esc_attr( $shortcode ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
			</button>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// WordPress hooks
	// -------------------------------------------------------------------------

	/**
	 * Register settings page.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Data Importer', 'data-importer' ),
			__( 'Data Importer', 'data-importer' ),
			Plugin::get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-database',
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Data Sources', 'data-importer' ),
			__( 'Data Sources', 'data-importer' ),
			Plugin::get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets for this plugin's page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_' . self::PAGE_SLUG, self::PAGE_SLUG . '_page_' . self::PAGE_SLUG ), true ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		// Template tab: syntax highlighting (CodeMirror) for PHP/HTML, CSS, and JS fields.
		$code_editor_settings = false;
		$code_editor_modes    = array(
			'php'        => false,
			'css'        => false,
			'javascript' => false,
		);
		if ( 'template' === $tab && isset( $_GET['source_id'] ) ) {
			$code_editor_modes['php']        = wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
			$code_editor_modes['css']        = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
			$code_editor_modes['javascript'] = wp_enqueue_code_editor( array( 'type' => 'application/javascript' ) );
			$code_editor_settings            = $code_editor_modes['php'];
		}

		wp_enqueue_style(
			'data-importer-admin',
			Assets::get_style_url( 'src/js/admin.js', 'assets/css/admin.css' ),
			array(),
			Assets::get_style_version( 'src/js/admin.js', 'assets/css/admin.css' )
		);

		$source_id    = isset( $_GET['source_id'] ) ? absint( wp_unslash( $_GET['source_id'] ) ) : 0;
		$dependencies = array( 'jquery', 'wp-a11y' );

		if ( array_filter( $code_editor_modes ) ) {
			$dependencies[] = 'code-editor';
		}

		wp_enqueue_script(
			'data-importer-admin',
			Assets::get_script_url( 'src/js/admin.js', 'assets/js/admin.js' ),
			$dependencies,
			Assets::get_script_version( 'src/js/admin.js', 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'data-importer-admin',
			'dataImporterAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'data_importer_admin_nonce' ),
				'codeEditor'  => $code_editor_settings,
				'codeEditors' => $code_editor_modes,
				'sourceId'    => $source_id,
				'extractVars' => (bool) apply_filters( 'data_importer_template_extract_vars', false, array(), array() ),
				'i18n'       => array(
					'copied'        => __( 'Copied.', 'data-importer' ),
					'copyFailed'    => __( 'Could not copy.', 'data-importer' ),
					'clearConfirm'  => __( 'Clear all imported data for this data source? This action cannot be undone.', 'data-importer' ),
					'deleteConfirm' => __( 'Delete the data source and all of its data? This action cannot be undone.', 'data-importer' ),
					'regenConfirm'  => __( 'Regenerate the API key? The previous key will stop working immediately.', 'data-importer' ),
					'addStyle'      => __( 'Add Style', 'data-importer' ),
					'addScript'     => __( 'Add Script', 'data-importer' ),
					'styleSource'   => __( 'Style Source', 'data-importer' ),
					'scriptSource'  => __( 'Script Source', 'data-importer' ),
					'scriptHandle'  => __( 'Handle', 'data-importer' ),
					'removeAsset'   => __( 'Remove', 'data-importer' ),
					'recordPreview' => __( 'Preview Record', 'data-importer' ),
					'recordModalTitle' => __( 'Record Content', 'data-importer' ),
					'closeModal'    => __( 'Close', 'data-importer' ),
					'createKeyError' => __( 'Could not create API key.', 'data-importer' ),
					'keyCreated'    => __( 'API key created. Copy it now \u2014 it will not be shown again.', 'data-importer' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Main render dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Render the admin page – dispatches to list, new, or edit view.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! $this->can_manage_plugin() ) {
			$this->deny_access();
		}
		?>
		<div class="wrap data-importer-admin">
			<?php $this->render_safe_mode_notices(); ?>
			<?php
			if ( isset( $_GET['source_id'] ) ) {
				( new EditView( $this ) )->render( absint( wp_unslash( $_GET['source_id'] ) ) );
			} elseif ( isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
				( new NewSourceView( $this ) )->render();
			} else {
				( new ListView( $this ) )->render();
			}
			?>
		</div>
		<?php
	}
}
