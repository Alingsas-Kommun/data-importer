<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Manual JSON import tab.
 *
 * Also owns the transient-key helper used by SourceFormHandler to
 * preserve the user's JSON input across redirects.
 */
class ManualTab {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Build the transient key used to preserve manual JSON input on errors.
	 *
	 * Shared with SourceFormHandler, so it is public static.
	 *
	 * @param int $source_id Source ID.
	 * @return string
	 */
	public static function transient_key( int $source_id ): string {
		return 'data_importer_manual_input_' . get_current_user_id() . '_' . $source_id;
	}

	/**
	 * Render the tab content.
	 *
	 * @param array $source Source row.
	 * @return void
	 */
	public function render( array $source ): void {
		$source_id     = (int) $source['id'];
		$preserved_raw = get_transient( self::transient_key( $source_id ) );
		$error         = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['error'] ) ) ) : '';
		$imported      = isset( $_GET['manual_imported'] ) ? absint( wp_unslash( $_GET['manual_imported'] ) ) : -1;
		$json_value    = is_string( $preserved_raw ) ? $preserved_raw : '';
		$example_json  = <<<JSON
[
  {
    "title": "Example item",
    "body": "Text",
    "status": "active"
  }
]
JSON;
		?>
		<div class="metabox-holder">
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $imported >= 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of imported records. */
								_n(
									'Manual import completed. %d record was imported.',
									'Manual import completed. %d records were imported.',
									$imported,
									'data-importer'
								),
								$imported
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="postbox">
				<h2><span><?php esc_html_e( 'Manual JSON Import', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p><?php esc_html_e( 'Paste a JSON object or an array of JSON objects and import it directly from wp-admin.', 'data-importer' ); ?></p>
					<p class="description"><?php esc_html_e( 'The import behaviour is determined by the Import mode setting below.', 'data-importer' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_manual_import_' . $source_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_manual_import" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />

						<p>
							<label for="data_importer_manual_json"><strong><?php esc_html_e( 'JSON Payload', 'data-importer' ); ?></strong></label>
						</p>
						<textarea id="data_importer_manual_json" name="data_importer_manual_json" class="large-text code" rows="18" spellcheck="false" placeholder="<?php echo esc_attr( $example_json ); ?>"><?php echo esc_textarea( $json_value ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Use the same payload format as the REST endpoint. Nested objects and arrays are supported.', 'data-importer' ); ?></p>

						<table class="form-table" style="margin-top:0;" role="presentation">
							<?php echo GeneralTab::render_import_mode_fields( (string) ( $source['import_mode'] ?? 'replace' ), (string) ( $source['update_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import JSON', 'data-importer' ); ?></button>
							<a href="<?php echo esc_url( $this->page->edit_url( $source_id, 'data' ) ); ?>" class="button"><?php esc_html_e( 'View Records', 'data-importer' ); ?></a>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
