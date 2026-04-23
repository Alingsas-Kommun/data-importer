<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Records (data) tab.
 */
class DataTab {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Render the tab content.
	 *
	 * @param array $source Source row.
	 * @return void
	 */
	public function render( array $source ): void {
		$source_id = (int) $source['id'];
		$count     = Database::count_records( $source_id );
		$rows      = Database::get_records(
			array(
				'source_id' => $source_id,
				'limit'     => 20,
				'order'     => 'DESC',
			)
		);
		$shortcode = '[data_importer source="' . esc_attr( $source['slug'] ) . '"]';
		?>
		<div class="metabox-holder">
			<?php if ( isset( $_GET['data_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Imported data has been cleared.', 'data-importer' ); ?></p></div>
			<?php endif; ?>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Overview', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p>
						<strong><?php esc_html_e( 'Total Records', 'data-importer' ); ?>:</strong>
						<?php echo esc_html( (string) $count ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Shortcode', 'data-importer' ); ?>:</strong>
						<?php $this->page->render_shortcode_copy_control( $shortcode ); ?>
					</p>
					<p class="submit">
						<button type="button" class="button" id="data-importer-clear-button" data-confirm="<?php echo esc_attr__( 'Clear all imported data for this data source? This action cannot be undone.', 'data-importer' ); ?>"><?php esc_html_e( 'Clear All Imported Data', 'data-importer' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Templates and the API key are not affected.', 'data-importer' ); ?></p>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Latest Records (max 20)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<div class="data-importer-table-wrap data-importer-records-wrap">
						<table class="widefat striped data-importer-records-table">
							<thead>
								<tr>
									<th scope="col" class="data-importer-col-id"><?php esc_html_e( 'ID', 'data-importer' ); ?></th>
									<th scope="col" class="data-importer-col-excerpt"><?php esc_html_e( 'Excerpt', 'data-importer' ); ?></th>
									<th scope="col" class="data-importer-col-imported"><?php esc_html_e( 'Imported', 'data-importer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $rows ) ) : ?>
									<tr>
										<td colspan="3"><?php esc_html_e( 'No imported records yet.', 'data-importer' ); ?></td>
									</tr>
								<?php else : ?>
									<?php foreach ( $rows as $row ) : ?>
										<tr>
											<td class="data-importer-col-id"><?php echo esc_html( (string) $row['id'] ); ?></td>
											<td class="data-importer-col-excerpt">
												<div class="data-importer-record-excerpt">
													<code class="data-importer-record-excerpt-code"><?php echo esc_html( $this->get_record_excerpt( (string) $row['record_data'], 200 ) ); ?>…</code>
													<button
														type="button"
														class="data-importer-record-preview"
														data-record-b64="<?php echo esc_attr( base64_encode( (string) $row['record_data'] ) ); ?>"
														aria-label="<?php echo esc_attr__( 'Preview Record', 'data-importer' ); ?>"
														title="<?php echo esc_attr__( 'Preview Record', 'data-importer' ); ?>"
													>
														<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
														<span class="screen-reader-text"><?php esc_html_e( 'Preview Record', 'data-importer' ); ?></span>
													</button>
												</div>
											</td>
											<td class="data-importer-col-imported"><?php echo esc_html( (string) $row['created_at'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a human-readable excerpt from stored JSON.
	 *
	 * @param string $raw_json Raw JSON string from DB.
	 * @param int    $max_len  Max excerpt length.
	 * @return string
	 */
	private function get_record_excerpt( string $raw_json, int $max_len = 200 ): string {
		$text    = $raw_json;
		$decoded = json_decode( $text, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$text = $encoded;
			}
		}

		$limit = max( 1, $max_len );

		return function_exists( 'mb_substr' )
			? mb_substr( $text, 0, $limit, 'UTF-8' )
			: substr( $text, 0, $limit );
	}
}
