<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the About/documentation tab.
 */
class AboutTab {

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
		$source_id          = (int) $source['id'];
		$endpoint           = rest_url( 'data-importer/v1/import/' . $source['slug'] );
		$base_shortcode     = '[data_importer source="' . esc_attr( $source['slug'] ) . '"]';
		$templates          = Database::get_templates_by_source( $source_id );
		$template_shortcode = '';
		$filter_shortcode   = '[data_importer source="' . esc_attr( $source['slug'] ) . '" where_key="status" where_op="eq" where_value="active"]';
		$nested_shortcode   = '[data_importer source="' . esc_attr( $source['slug'] ) . '" where_key="address.city" where_op="contains" where_value="Alingsas"]';
		$where_shorthand    = '[data_importer source="' . esc_attr( $source['slug'] ) . '" where="status|eq|active"]';

		if ( ! empty( $templates ) ) {
			$template_shortcode = '[data_importer source="' . esc_attr( $source['slug'] ) . '" template="' . esc_attr( $templates[0]['slug'] ) . '"]';
		}
		?>
		<div class="metabox-holder">
			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Quick Start', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<ol style="margin-left:18px;">
						<li><?php esc_html_e( 'Import JSON via the endpoint below with the correct API key in the X-API-Key header.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Create one or more templates in the Template tab.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Paste the shortcode onto any page or into a block.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Verify the result in the Records tab and the logs in the API tab.', 'data-importer' ); ?></li>
					</ol>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Endpoint and Shortcode', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p><strong><?php esc_html_e( 'REST endpoint (POST)', 'data-importer' ); ?></strong></p>
					<pre class="data-importer-code-block"><code><?php echo esc_html( $endpoint ); ?></code></pre>

					<p><strong><?php esc_html_e( 'Default Shortcode', 'data-importer' ); ?></strong></p>
					<?php $this->page->render_shortcode_pre_with_copy( $base_shortcode ); ?>

					<?php if ( '' !== $template_shortcode ) : ?>
						<p><strong><?php esc_html_e( 'Shortcode with a Specific Template', 'data-importer' ); ?></strong></p>
						<?php $this->page->render_shortcode_pre_with_copy( $template_shortcode ); ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Shortcode Filters (server-side)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'Filtering runs before the render loop, reducing HTML output and making selections reusable.', 'data-importer' ); ?></p>

					<p><strong><?php esc_html_e( 'Simple Filter', 'data-importer' ); ?></strong></p>
					<?php $this->page->render_shortcode_pre_with_copy( $filter_shortcode ); ?>

					<p><strong><?php esc_html_e( 'Nested Field (dot notation)', 'data-importer' ); ?></strong></p>
					<?php $this->page->render_shortcode_pre_with_copy( $nested_shortcode ); ?>

					<p><strong><?php esc_html_e( 'Shorthand', 'data-importer' ); ?></strong></p>
					<?php $this->page->render_shortcode_pre_with_copy( $where_shorthand ); ?>

					<p style="margin:12px 0 6px;"><strong><?php esc_html_e( 'Operators', 'data-importer' ); ?></strong></p>
					<p class="description"><code>eq</code>, <code>neq</code>, <code>contains</code>, <code>ncontains</code>, <code>gt</code>, <code>gte</code>, <code>lt</code>, <code>lte</code>, <code>in</code>, <code>nin</code>, <code>starts_with</code>, <code>ends_with</code>, <code>empty</code>, <code>not_empty</code></p>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'PHP Template Examples', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'The template code runs once per record. Use conditions for fine-tuning even when prefiltering via shortcode.', 'data-importer' ); ?></p>
					<p><strong><?php esc_html_e( 'Show Only Active Records', 'data-importer' ); ?></strong></p>
					<pre class="data-importer-code-block"><code><?php echo esc_html( '<?php if ( ( $status ?? \'\' ) === \'active\' ) : ?>' . "\n" . '  &lt;div class="item"&gt;&lt;?php echo esc_html( $title ?? \'\' ); ?&gt;&lt;/div&gt;' . "\n" . '<?php endif; ?>' ); ?></code></pre>

					<p><strong><?php esc_html_e( 'Get Nested Value', 'data-importer' ); ?></strong></p>
					<pre class="data-importer-code-block"><code><?php echo esc_html( '<?php $city = \\DataImporter\\Frontend\\Display::get_nested_value( $record, \'address.city\' ); ?>' . "\n" . '<?php if ( $city ) : ?>' . "\n" . '  &lt;span&gt;&lt;?php echo esc_html( $city ); ?&gt;&lt;/span&gt;' . "\n" . '<?php endif; ?>' ); ?></code></pre>

					<p><strong><?php esc_html_e( 'Conditional CSS Class', 'data-importer' ); ?></strong></p>
					<pre class="data-importer-code-block"><code><?php echo esc_html( '&lt;article class="card &lt;?php echo ( ( $priority ?? \'\' ) === \'high\' ) ? \'is-high\' : \'\'; ?&gt;"&gt;' . "\n" . '  &lt;h3&gt;&lt;?php echo esc_html( $title ?? \'\' ); ?&gt;&lt;/h3&gt;' . "\n" . '&lt;/article&gt;' ); ?></code></pre>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Security Overview', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<ul style="margin-left:18px;list-style:disc;">
						<li><?php esc_html_e( 'REST imports are protected by hashed API keys, rate limiting, and optional per-key IP/CIDR allowlists.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Security logs are shown in the API tab (blocked attempts and template errors).', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Templates with PHP are powerful but require strict admin security and MFA.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Access for admin and templates is controlled via the data_importer_capability filter.', 'data-importer' ); ?></li>
					</ul>
					<p class="description"><?php esc_html_e( 'Tip: restrict wp-admin access, rotate API keys regularly, enable safe mode for template issues, and always use HTTPS.', 'data-importer' ); ?></p>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Recovery (Safe Mode)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<ol style="margin-left:18px;">
						<li><?php esc_html_e( 'Click "Enable Safe Mode" in the admin notice at the top of the Data Importer page.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'In safe mode, no PHP templates run on the frontend.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Open the Template tab, adjust the template, and save again.', 'data-importer' ); ?></li>
						<li><?php esc_html_e( 'Disable safe mode once the template is verified.', 'data-importer' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'Safe mode uses the global status (option data_importer_safe_mode) and can also be controlled with the DATA_IMPORTER_SAFE_MODE constant.', 'data-importer' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
