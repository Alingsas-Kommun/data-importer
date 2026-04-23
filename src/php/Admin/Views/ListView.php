<?php

namespace DataImporter\Admin\Views;

use DataImporter\Admin\AdminPage;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the data-source list view.
 */
class ListView {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Render the source list table.
	 *
	 * @return void
	 */
	public function render(): void {
		$sources = Database::get_all_sources();
		$metrics = Database::get_source_overview_metrics();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Data Importer & Visualizer', 'data-importer' ); ?></h1>
		<a href="<?php echo esc_url( $this->page->new_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Data Source', 'data-importer' ); ?></a>
		<hr class="wp-header-end" />

		<?php if ( isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The data source was deleted.', 'data-importer' ); ?></p></div>
		<?php endif; ?>

		<?php if ( empty( $sources ) ) : ?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'No data sources yet. Click "Add New Data Source" to get started.', 'data-importer' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped data-importer-list-table">
				<thead>
					<tr>
						<th scope="col" class="column-primary column-name"><?php esc_html_e( 'Name', 'data-importer' ); ?></th>
						<th scope="col" class="column-slug"><?php esc_html_e( 'Slug', 'data-importer' ); ?></th>
						<th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'data-importer' ); ?></th>
						<th scope="col" class="column-count"><?php esc_html_e( 'Records', 'data-importer' ); ?></th>
						<th scope="col" class="column-imported"><?php esc_html_e( 'Latest Import', 'data-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sources as $source ) :
						$source_id = (int) $source['id'];
						$meta      = isset( $metrics[ $source_id ] ) && is_array( $metrics[ $source_id ] ) ? $metrics[ $source_id ] : array();
						$count     = isset( $meta['record_count'] ) ? (int) $meta['record_count'] : 0;
						$last_time = ! empty( $meta['latest_record_at'] ) ? (string) $meta['latest_record_at'] : '—';
						$shortcode = '[data_importer source="' . esc_attr( $source['slug'] ) . '"]';
						$edit_url  = $this->page->edit_url( $source_id );
					?>
					<tr>
						<td class="column-primary column-name" data-colname="<?php esc_attr_e( 'Name', 'data-importer' ); ?>">
							<strong>
								<a class="row-title" href="<?php echo esc_url( $edit_url ); ?>">
									<?php echo esc_html( $source['name'] ); ?>
								</a>
							</strong>
							<div class="row-actions">
								<span class="edit">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'data-importer' ); ?></a>
								</span>
								<span class="sep"> | </span>
								<span class="delete">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="data-importer-delete-form" data-confirm="<?php echo esc_attr( sprintf( __( 'Delete the data source "%s" and all of its data? This action cannot be undone.', 'data-importer' ), (string) $source['name'] ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'data_importer_delete_source_' . $source['id'], 'data_importer_nonce' ); ?>
										<input type="hidden" name="action" value="data_importer_delete_source" />
										<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source['id'] ); ?>" />
										<button type="submit" class="button-link delete data-importer-delete-btn">
											<?php esc_html_e( 'Delete', 'data-importer' ); ?>
										</button>
									</form>
								</span>
							</div>
						</td>
						<td class="column-slug" data-colname="<?php esc_attr_e( 'Slug', 'data-importer' ); ?>">
							<code><?php echo esc_html( $source['slug'] ); ?></code>
						</td>
						<td class="column-shortcode" data-colname="<?php esc_attr_e( 'Shortcode', 'data-importer' ); ?>">
							<?php $this->page->render_shortcode_copy_control( $shortcode ); ?>
						</td>
						<td class="column-count" data-colname="<?php esc_attr_e( 'Records', 'data-importer' ); ?>">
							<?php echo esc_html( (string) $count ); ?>
						</td>
						<td class="column-imported" data-colname="<?php esc_attr_e( 'Latest Import', 'data-importer' ); ?>">
							<?php echo esc_html( $last_time ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
