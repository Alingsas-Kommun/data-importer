<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;
use DataImporter\Admin\SourceFormHandler;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the API tab (endpoint, keys, one-time reveals).
 */
class ApiTab {

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
		$source_id    = (int) $source['id'];
		$endpoint     = rest_url( 'data-importer/v1/import/' . $source['slug'] );
		$keys         = Database::get_source_api_keys( $source_id );
		$error        = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['error'] ) ) ) : '';
		$reveal       = isset( $_GET['reveal'] ) ? SourceFormHandler::consume_api_key_reveal( $source_id ) : null;
		$reveal_key_id = is_array( $reveal ) && ! empty( $reveal['secret'] ) ? (int) ( $reveal['key_id'] ?? 0 ) : 0;
		?>
		<div class="metabox-holder">
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<div class="postbox data-importer-api-credentials" role="region" aria-label="<?php echo esc_attr__( 'REST API', 'data-importer' ); ?>">
				<div class="inside">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="di-endpoint"><?php esc_html_e( 'Endpoint URL (POST)', 'data-importer' ); ?></label></th>
							<td>
								<div class="data-importer-field-with-copy">
									<input type="text" id="di-endpoint" class="large-text code" readonly value="<?php echo esc_attr( $endpoint ); ?>" />
									<button type="button" class="button button-small data-importer-copy data-importer-copy-shortcode-icon" data-clipboard-target="di-endpoint" aria-label="<?php echo esc_attr__( 'Copy Endpoint URL', 'data-importer' ); ?>" title="<?php echo esc_attr__( 'Copy Endpoint URL', 'data-importer' ); ?>">
										<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Copy', 'data-importer' ); ?></span>
									</button>
								</div>
								<p class="description"><?php esc_html_e( 'Send the chosen key in the X-API-Key header with each import request.', 'data-importer' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<div class="postbox">
				<h2><span><?php esc_html_e( 'API Keys', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'Each key has its own optional IP/CIDR allowlist. Leave a key\'s rules empty to allow all IPs for that key.', 'data-importer' ); ?></p>
					<p class="description"><?php esc_html_e( 'Stored keys are hashed and cannot be viewed again after creation. Regenerate a key if you need a fresh secret.', 'data-importer' ); ?></p>

					<div id="di-api-keys-list-<?php echo esc_attr( (string) $source_id ); ?>">
						<?php if ( empty( $keys ) ) : ?>
							<p class="description di-no-keys-message"><?php esc_html_e( 'No API keys exist yet. Create one below.', 'data-importer' ); ?></p>
						<?php else : ?>
							<?php foreach ( $keys as $key ) : ?>
								<?php
								$is_reveal = $reveal_key_id > 0 && (int) ( $key['id'] ?? 0 ) === $reveal_key_id;
								$this->render_key_card(
									$source_id,
									$key,
									$is_reveal ? (string) ( $reveal['secret'] ?? '' ) : '',
									$is_reveal ? (bool) ( $reveal['created'] ?? true ) : true
								);
								?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<hr class="di-keys-divider" />

					<p class="di-keys-section-title"><?php esc_html_e( 'Add New Key', 'data-importer' ); ?></p>

					<form
						id="di-create-api-key-form-<?php echo esc_attr( (string) $source_id ); ?>"
						class="di-create-api-key-form"
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					>
						<?php wp_nonce_field( 'data_importer_create_api_key_' . $source_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_create_api_key" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="data_importer_key_name_new"><?php esc_html_e( 'Label', 'data-importer' ); ?></label></th>
								<td>
									<input
										type="text"
										class="regular-text"
										id="data_importer_key_name_new"
										name="data_importer_key_name"
										value=""
										placeholder="<?php echo esc_attr__( 'e.g. Production feed', 'data-importer' ); ?>"
									/>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="data_importer_key_allowed_ips_new"><?php esc_html_e( 'Allowed IP / CIDR', 'data-importer' ); ?></label></th>
								<td>
									<input
										type="text"
										class="large-text code"
										id="data_importer_key_allowed_ips_new"
										name="data_importer_key_allowed_ips"
										value=""
										placeholder="<?php echo esc_attr__( 'e.g. 203.0.113.10, 198.51.100.0/24, 2001:db8::/32', 'data-importer' ); ?>"
									/>
									<p class="description"><?php esc_html_e( 'Leave empty to allow all IPs.', 'data-importer' ); ?></p>
								</td>
							</tr>
						</table>
						<p class="submit" style="margin-bottom:0;">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create API Key', 'data-importer' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<div class="postbox">
				<h2><span><?php esc_html_e( 'cURL Example', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<pre class="data-importer-code-block"><code>curl -X POST "<?php echo esc_html( $endpoint ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '[{"title":"Example","body":"Text"}]'</code></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one API key card.
	 *
	 * @param int    $source_id      Source ID.
	 * @param array  $key            API key row.
	 * @param string $reveal_secret  Plaintext secret to reveal inline; empty = hidden.
	 * @param bool   $reveal_created True = newly created, false = regenerated.
	 * @return void
	 */
	public function render_key_card( int $source_id, array $key, string $reveal_secret = '', bool $reveal_created = true ): void {
		$key_id       = (int) ( $key['id'] ?? 0 );
		$key_name     = (string) ( $key['name'] ?? '' );
		$allowed_ips  = (string) ( $key['allowed_ips'] ?? '' );
		$preview      = $this->format_key_preview( $key );
		$created_at   = (string) ( $key['created_at'] ?? '' );
		$display_name = '' !== $key_name ? $key_name : __( 'Unnamed key', 'data-importer' );
		$reveal_id    = 'di-key-reveal-input-' . $key_id;
		$reveal_msg   = $reveal_created
			? __( 'Copy this key now — it will not be shown again.', 'data-importer' )
			: __( 'Copy the regenerated key now — the previous secret has stopped working.', 'data-importer' );
		?>
		<div class="postbox di-api-key-card">
			<div class="inside">
				<div class="di-key-card-meta">
					<div class="di-key-card-meta-left">
						<strong class="di-key-card-name"><?php echo esc_html( $display_name ); ?></strong>
						<code class="di-key-id"><?php echo esc_html( $preview ); ?></code>
					</div>
					<?php if ( '' !== $created_at ) : ?>
						<span class="description di-key-created"><?php echo esc_html( sprintf( __( 'Created %s', 'data-importer' ), $created_at ) ); ?></span>
					<?php endif; ?>
				</div>

				<div class="di-key-reveal" <?php echo '' === $reveal_secret ? 'hidden' : ''; ?>>
					<p><strong><?php echo esc_html( $reveal_msg ); ?></strong> <?php esc_html_e( 'If you lose it, regenerate this key.', 'data-importer' ); ?></p>
					<div class="data-importer-field-with-copy">
						<input
							type="text"
							id="<?php echo esc_attr( $reveal_id ); ?>"
							class="large-text code"
							readonly
							value="<?php echo esc_attr( $reveal_secret ); ?>"
							autocomplete="off"
						/>
						<button
							type="button"
							class="button button-small data-importer-copy data-importer-copy-shortcode-icon"
							data-clipboard-target="<?php echo esc_attr( $reveal_id ); ?>"
							aria-label="<?php echo esc_attr__( 'Copy API Key', 'data-importer' ); ?>"
							title="<?php echo esc_attr__( 'Copy API Key', 'data-importer' ); ?>"
						>
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Copy', 'data-importer' ); ?></span>
						</button>
					</div>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'data_importer_update_api_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ); ?>
					<input type="hidden" name="action" value="data_importer_update_api_key" />
					<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
					<input type="hidden" name="data_importer_key_id" value="<?php echo esc_attr( (string) $key_id ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="data_importer_key_name_<?php echo esc_attr( (string) $key_id ); ?>"><?php esc_html_e( 'Label', 'data-importer' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="data_importer_key_name_<?php echo esc_attr( (string) $key_id ); ?>" name="data_importer_key_name" value="<?php echo esc_attr( $key_name ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="data_importer_key_allowed_ips_<?php echo esc_attr( (string) $key_id ); ?>"><?php esc_html_e( 'Allowed IP / CIDR', 'data-importer' ); ?></label></th>
							<td>
								<input
									type="text"
									class="large-text code"
									id="data_importer_key_allowed_ips_<?php echo esc_attr( (string) $key_id ); ?>"
									name="data_importer_key_allowed_ips"
									value="<?php echo esc_attr( $allowed_ips ); ?>"
									placeholder="<?php echo esc_attr__( 'e.g. 203.0.113.10, 198.51.100.0/24, 2001:db8::/32', 'data-importer' ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'Leave empty to allow all IPs.', 'data-importer' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="submit" style="margin-bottom:0;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Key Details', 'data-importer' ); ?></button>
					</p>
				</form>

				<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;align-items:center;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_regen_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_regen_key" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
						<input type="hidden" name="data_importer_key_id" value="<?php echo esc_attr( (string) $key_id ); ?>" />
						<input type="hidden" name="data_importer_key_name" value="<?php echo esc_attr( $key_name ); ?>" />
						<input type="hidden" name="data_importer_key_allowed_ips" value="<?php echo esc_attr( $allowed_ips ); ?>" />
						<button type="submit" class="button data-importer-confirm-action" data-confirm="<?php echo esc_attr__( 'Regenerate this API key? The previous secret will stop working immediately.', 'data-importer' ); ?>"><?php esc_html_e( 'Regenerate Key', 'data-importer' ); ?></button>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_delete_api_key_' . $source_id . '_' . $key_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_delete_api_key" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
						<input type="hidden" name="data_importer_key_id" value="<?php echo esc_attr( (string) $key_id ); ?>" />
						<button type="submit" class="button di-delete-key-btn data-importer-confirm-action" data-confirm="<?php echo esc_attr__( 'Delete this API key? Any integration still using it will stop working immediately.', 'data-importer' ); ?>"><?php esc_html_e( 'Delete Key', 'data-importer' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a masked preview for an API key row.
	 *
	 * @param array $key API key row.
	 * @return string
	 */
	private function format_key_preview( array $key ): string {
		$prefix = (string) ( $key['key_prefix'] ?? '' );

		if ( '' === $prefix ) {
			return __( 'Hidden', 'data-importer' );
		}

		return $prefix . "\u{2026}";
	}
}
