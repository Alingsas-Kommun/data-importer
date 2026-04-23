<?php

namespace DataImporter\Admin\Views;

use DataImporter\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the create-new-source form.
 */
class NewSourceView {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Render the new-source form.
	 *
	 * @return void
	 */
	public function render(): void {
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['error'] ) ) ) : '';
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'New Data Source', 'data-importer' ); ?></h1>
		<a href="<?php echo esc_url( $this->page->list_url() ); ?>" class="page-title-action">← <?php esc_html_e( 'All Data Sources', 'data-importer' ); ?></a>
		<hr class="wp-header-end" />

		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'data_importer_save_source_0', 'data_importer_nonce' ); ?>
			<input type="hidden" name="action" value="data_importer_save_source" />
			<input type="hidden" name="data_importer_source_id" value="0" />
			<input type="hidden" name="data_importer_active_tab" value="general" />

			<div class="postbox">
				<div class="inside">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="data_importer_name"><?php esc_html_e( 'Name', 'data-importer' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="text" id="data_importer_name" name="data_importer_name" class="regular-text" required />
								<p class="description"><?php esc_html_e( 'Display name, e.g. "Staff Directory" or "Events".', 'data-importer' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="data_importer_slug"><?php esc_html_e( 'Slug', 'data-importer' ); ?></label>
							</th>
							<td>
								<input type="text" id="data_importer_slug" name="data_importer_slug" class="regular-text" pattern="[a-z0-9\-]+" />
								<p class="description"><?php esc_html_e( 'Used in the REST API URL and shortcode. Generated automatically from the name. Cannot be changed after creation.', 'data-importer' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Data Source', 'data-importer' ); ?></button>
					</p>
				</div>
			</div>
		</form>
		<?php
	}
}
