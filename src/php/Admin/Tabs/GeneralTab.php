<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the General settings tab.
 */
class GeneralTab {

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
		$import_mode = (string) ( $source['import_mode'] ?? 'replace' );
		$update_key  = (string) ( $source['update_key'] ?? '' );
		?>
		<div class="metabox-holder">
			<div class="postbox">
				<div class="inside">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_save_source_' . $source['id'], 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_save_source" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source['id'] ); ?>" />
						<input type="hidden" name="data_importer_active_tab" value="general" />

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="data_importer_name"><?php esc_html_e( 'Name', 'data-importer' ); ?></label></th>
								<td>
									<input type="text" id="data_importer_name" name="data_importer_name" class="regular-text" value="<?php echo esc_attr( $source['name'] ); ?>" required />
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Slug', 'data-importer' ); ?></th>
								<td>
									<code><?php echo esc_html( $source['slug'] ); ?></code>
									<p class="description"><?php esc_html_e( 'The slug cannot be changed after the data source is created.', 'data-importer' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Created', 'data-importer' ); ?></th>
								<td><?php echo esc_html( (string) $source['created_at'] ); ?></td>
							</tr>
							<?php echo $this->render_import_mode_fields( $import_mode, $update_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</table>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'data-importer' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import mode radio buttons and update key field as table rows.
	 *
	 * Shared between GeneralTab and ManualTab so the markup stays consistent.
	 *
	 * @param string $current_mode Active mode ('replace', 'append', 'upsert').
	 * @param string $current_key  Comma-separated update key columns.
	 * @return string HTML.
	 */
	public static function render_import_mode_fields( string $current_mode, string $current_key ): string {
		ob_start();
		$modes = array(
			'replace' => array(
				'label'       => __( 'Replace', 'data-importer' ),
				'description' => __( 'Delete all existing records and insert the new ones.', 'data-importer' ),
			),
			'append'  => array(
				'label'       => __( 'Append', 'data-importer' ),
				'description' => __( 'Add the new records without removing existing ones.', 'data-importer' ),
			),
			'upsert'  => array(
				'label'       => __( 'Update (upsert)', 'data-importer' ),
				'description' => __( 'Update existing records that match on the key column(s) and insert the rest.', 'data-importer' ),
			),
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Import mode', 'data-importer' ); ?></th>
			<td>
				<?php foreach ( $modes as $value => $meta ) : ?>
					<label style="display:block;margin-bottom:4px;">
						<input
							type="radio"
							name="data_importer_import_mode"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $current_mode, $value ); ?>
							class="data-importer-mode-radio"
						/>
						<strong><?php echo esc_html( $meta['label'] ); ?></strong>
						&mdash; <?php echo esc_html( $meta['description'] ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr class="data-importer-update-key-row" <?php echo 'upsert' !== $current_mode ? 'style="display:none;"' : ''; ?>>
			<th scope="row"><label for="data_importer_update_key"><?php esc_html_e( 'Update key column(s)', 'data-importer' ); ?></label></th>
			<td>
				<input
					type="text"
					id="data_importer_update_key"
					name="data_importer_update_key"
					class="regular-text code"
					value="<?php echo esc_attr( $current_key ); ?>"
					placeholder="<?php echo esc_attr__( 'e.g. id  or  ean,location', 'data-importer' ); ?>"
				/>
				<p class="description"><?php esc_html_e( 'Comma-separated field name(s) used to match existing records. Every incoming record must contain these fields.', 'data-importer' ); ?></p>
			</td>
		</tr>
		<script>
		(function(){
			function toggleKeyRow(){
				document.querySelectorAll('.data-importer-mode-radio').forEach(function(r){
					r.addEventListener('change',function(){
						var row = document.querySelector('.data-importer-update-key-row');
						if(row){ row.style.display = this.value==='upsert' ? '' : 'none'; }
					});
				});
			}
			if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',toggleKeyRow);}else{toggleKeyRow();}
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}
}
