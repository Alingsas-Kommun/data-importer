<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Template tab and all sub-views (list, editor).
 */
class TemplateTab {

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
		$source_id   = (int) $source['id'];
		$templates   = Database::get_templates_by_source( $source_id );
		$error_index = $this->get_template_error_index( $source_id );
		$template_id = isset( $_GET['template_id'] ) ? absint( wp_unslash( $_GET['template_id'] ) ) : 0;
		$error       = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['error'] ) ) ) : '';

		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( $template_id > 0 ) {
			$current_template = Database::get_template( $template_id );
			if ( ! $current_template || (int) $current_template['source_id'] !== $source_id ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Template not found.', 'data-importer' ) . '</p></div>';
			} else {
				$this->render_editor( $source, $current_template, $templates );
				return;
			}
		}

		$this->render_list( $source, $templates, $error_index );
	}

	// -------------------------------------------------------------------------
	// Template list view
	// -------------------------------------------------------------------------

	/**
	 * Render the list of templates for a source.
	 *
	 * @param array $source      Source row.
	 * @param array $templates   Template rows.
	 * @param array $error_index Latest errors indexed by template ID.
	 * @return void
	 */
	private function render_list( array $source, array $templates, array $error_index = array() ): void {
		$source_id = (int) $source['id'];
		?>
		<div class="metabox-holder">
			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Templates', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<table class="wp-list-table widefat fixed striped data-importer-list-table data-importer-template-list-table">
						<thead>
							<tr>
								<th scope="col" class="column-primary"><?php esc_html_e( 'Template', 'data-importer' ); ?></th>
								<th scope="col" class="column-slug"><?php esc_html_e( 'Slug', 'data-importer' ); ?></th>
								<th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'data-importer' ); ?></th>
								<th scope="col" class="column-updated-at"><?php esc_html_e( 'Updated', 'data-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $templates ) ) : ?>
								<tr>
									<td colspan="4"><?php esc_html_e( 'No templates yet.', 'data-importer' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $templates as $tpl ) : ?>
									<?php
									$tpl_id        = (int) $tpl['id'];
									$edit_url      = $this->page->template_url( $source_id, $tpl_id );
									$shortcode     = '[data_importer source="' . esc_attr( $source['slug'] ) . '" template="' . esc_attr( $tpl['slug'] ) . '"]';
									$template_fail = $error_index[ $tpl_id ] ?? null;
									$api_log_url   = $this->page->edit_url( $source_id, 'api' ) . '#data-importer-template-error-log';
									?>
									<tr>
										<td class="column-primary">
											<strong><a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $tpl['name'] ); ?></a></strong>
											<?php if ( $template_fail ) : ?>
												<span class="data-importer-template-error-badge" title="<?php echo esc_attr( sprintf( __( 'Latest template error: %1$s (%2$s)', 'data-importer' ), (string) $template_fail['event'], (string) $template_fail['time'] ) ); ?>"><?php esc_html_e( 'Template Error', 'data-importer' ); ?></span>
											<?php endif; ?>
											<div class="row-actions">
												<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'data-importer' ); ?></a></span>
												<?php if ( $template_fail ) : ?>
													<span class="sep"> | </span>
													<span class="view-log"><a href="<?php echo esc_url( $api_log_url ); ?>"><?php esc_html_e( 'View Error Log', 'data-importer' ); ?></a></span>
												<?php endif; ?>
											</div>
										</td>
										<td class="column-slug"><code><?php echo esc_html( $tpl['slug'] ); ?></code></td>
										<td class="column-shortcode">
											<?php $this->page->render_shortcode_copy_control( $shortcode ); ?>
										</td>
										<td class="column-updated-at"><?php echo esc_html( (string) $tpl['updated_at'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Create New Template', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_create_template_' . $source_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_create_template" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="data_importer_template_name"><?php esc_html_e( 'Template Name', 'data-importer' ); ?></label></th>
								<td><input type="text" class="regular-text" id="data_importer_template_name" name="data_importer_template_name" required /></td>
							</tr>
							<tr>
								<th scope="row"><label for="data_importer_template_slug"><?php esc_html_e( 'Template Slug (optional)', 'data-importer' ); ?></label></th>
								<td><input type="text" class="regular-text" id="data_importer_template_slug" name="data_importer_template_slug" pattern="[a-z0-9\-]+" /></td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Template', 'data-importer' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Template editor view
	// -------------------------------------------------------------------------

	/**
	 * Render the single-template editor view.
	 *
	 * @param array $source           Source row.
	 * @param array $current_template Active template row.
	 * @param array $all_templates    All templates for the same source.
	 * @return void
	 */
	private function render_editor( array $source, array $current_template, array $all_templates ): void {
		$source_id           = (int) $source['id'];
		$current_template_id = (int) $current_template['id'];
		$fields              = Database::get_fields( $source_id );
		$style_rows          = $this->decode_asset_rows( $current_template['styles_json'] ?? array(), 'style' );
		$script_rows         = $this->decode_asset_rows( $current_template['scripts_json'] ?? array(), 'script' );

		$extract_active = (bool) apply_filters( 'data_importer_template_extract_vars', false, array(), array() );
		$legacy_fields  = $extract_active ? array() : $this->detect_legacy_var_usage( $fields, $current_template );
		?>
		<p style="margin:12px 0;">
			<a class="button" href="<?php echo esc_url( $this->page->template_url( $source_id ) ); ?>">← <?php esc_html_e( 'Back to Template List', 'data-importer' ); ?></a>
		</p>

		<?php $this->render_editor_actions( count( $all_templates ) ); ?>

		<?php if ( ! empty( $legacy_fields ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Data Importer — global variable syntax detected', 'data-importer' ); ?></strong><br />
					<?php
					$field_list = implode( ', ', array_map( fn( $f ) => '$' . $f, $legacy_fields ) );
					printf(
						/* translators: %s: comma-separated list of field names */
						esc_html__( 'This template references fields as global variables (%s). Use $vars[\'field\'] instead, or enable the data_importer_template_extract_vars filter if you prefer the global variable syntax.', 'data-importer' ),
						esc_html( $field_list )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="metabox-holder columns-2 data-importer-template-layout">
			<div class="postbox-container" style="float:none;width:100%;">
				<form id="data-importer-save-template-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'data_importer_save_template_' . $source_id, 'data_importer_nonce' ); ?>
					<input type="hidden" name="action" value="data_importer_save_template" />
					<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
					<input type="hidden" name="data_importer_template_id" value="<?php echo esc_attr( (string) $current_template_id ); ?>" />

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Template Settings', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<p>
								<label for="data_importer_template_name_edit"><strong><?php esc_html_e( 'Template Name', 'data-importer' ); ?></strong></label><br />
								<input type="text" class="regular-text" id="data_importer_template_name_edit" name="data_importer_template_name" value="<?php echo esc_attr( $current_template['name'] ); ?>" />
							</p>
							<p>
								<label for="data_importer_template_slug_edit"><strong><?php esc_html_e( 'Template Slug', 'data-importer' ); ?></strong></label><br />
								<input type="text" class="regular-text" id="data_importer_template_slug_edit" name="data_importer_template_slug" value="<?php echo esc_attr( $current_template['slug'] ); ?>" pattern="[a-z0-9\-]+" />
							</p>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Before List (PHP/HTML)', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<label for="data_importer_wrapper_before" class="screen-reader-text"><?php esc_html_e( 'Before List (PHP/HTML)', 'data-importer' ); ?></label>
							<textarea id="data_importer_wrapper_before" name="data_importer_wrapper_before" class="large-text code" rows="8" spellcheck="false"><?php echo esc_textarea( $current_template['wrapper_before'] ); ?></textarea>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'List (PHP/HTML per record)', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<?php $this->render_variable_buttons( $fields, 'template' ); ?>
							<label for="data_importer_template_html" class="screen-reader-text"><?php esc_html_e( 'PHP Template', 'data-importer' ); ?></label>
							<textarea id="data_importer_template_html" name="data_importer_template_html" class="large-text code" rows="20" spellcheck="false"><?php echo esc_textarea( $current_template['template_html'] ); ?></textarea>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'After List (PHP/HTML)', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<label for="data_importer_wrapper_after" class="screen-reader-text"><?php esc_html_e( 'After List (PHP/HTML)', 'data-importer' ); ?></label>
							<textarea id="data_importer_wrapper_after" name="data_importer_wrapper_after" class="large-text code" rows="8" spellcheck="false"><?php echo esc_textarea( $current_template['wrapper_after'] ); ?></textarea>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Styles', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Add stylesheet URLs and optional handles. Empty handles are generated from the stylesheet filename.', 'data-importer' ); ?></p>
							<div class="data-importer-asset-group" data-asset-type="style">
								<div class="data-importer-asset-headings" aria-hidden="true">
									<span><?php esc_html_e( 'Source', 'data-importer' ); ?></span>
									<span><?php esc_html_e( 'Handle', 'data-importer' ); ?></span>
									<span></span>
								</div>
								<div class="data-importer-asset-rows">
									<?php
									if ( empty( $style_rows ) ) {
										$this->render_style_row();
									} else {
										foreach ( $style_rows as $style_row ) {
											$this->render_style_row( $style_row );
										}
									}
									?>
								</div>
								<button type="button" class="button button-secondary data-importer-add-asset" data-asset-type="style"><?php esc_html_e( 'Add Style', 'data-importer' ); ?></button>
							</div>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Scripts', 'data-importer' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Add script URLs and optional handles. Empty handles are generated from the script filename.', 'data-importer' ); ?></p>
							<div class="data-importer-asset-group" data-asset-type="script">
								<div class="data-importer-asset-headings" aria-hidden="true">
									<span><?php esc_html_e( 'Source', 'data-importer' ); ?></span>
									<span><?php esc_html_e( 'Handle', 'data-importer' ); ?></span>
									<span></span>
								</div>
								<div class="data-importer-asset-rows">
									<?php
									if ( empty( $script_rows ) ) {
										$this->render_script_row();
									} else {
										foreach ( $script_rows as $script_row ) {
											$this->render_script_row( $script_row );
										}
									}
									?>
								</div>
								<button type="button" class="button button-secondary data-importer-add-asset" data-asset-type="script"><?php esc_html_e( 'Add Script', 'data-importer' ); ?></button>
							</div>
						</div>
					</div>

				</form>

				<template id="data-importer-style-row-template">
					<?php $this->render_style_row(); ?>
				</template>

				<template id="data-importer-script-row-template">
					<?php $this->render_script_row(); ?>
				</template>

				<?php $this->render_editor_actions( count( $all_templates ) ); ?>

				<?php if ( count( $all_templates ) > 1 ) : ?>
					<form id="data-importer-delete-template-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'data_importer_delete_template_' . $source_id, 'data_importer_nonce' ); ?>
						<input type="hidden" name="action" value="data_importer_delete_template" />
						<input type="hidden" name="data_importer_source_id" value="<?php echo esc_attr( (string) $source_id ); ?>" />
						<input type="hidden" name="data_importer_template_id" value="<?php echo esc_attr( (string) $current_template_id ); ?>" />
					</form>
				<?php endif; ?>
			</div>
		</div>
		<br class="clear" />
		<?php
	}

	// -------------------------------------------------------------------------
	// Editor action buttons
	// -------------------------------------------------------------------------

	/**
	 * Render Save + Delete action buttons for the template editor.
	 * Buttons reference the save/delete forms by ID so they can be placed
	 * anywhere on the page (top bar and bottom bar).
	 *
	 * @param int $all_template_count Total templates for this source.
	 * @return void
	 */
	private function render_editor_actions( int $all_template_count ): void {
		?>
		<div class="data-importer-template-actions">
			<button type="submit" form="data-importer-save-template-form" class="button button-primary"><?php esc_html_e( 'Save Template', 'data-importer' ); ?></button>
			<?php if ( $all_template_count > 1 ) : ?>
				<button type="submit" form="data-importer-delete-template-form" class="button data-importer-delete-btn data-importer-confirm-action" data-confirm="<?php echo esc_attr__( 'Delete this template? This action cannot be undone.', 'data-importer' ); ?>">
					<?php esc_html_e( 'Delete Template', 'data-importer' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="button data-importer-delete-btn" disabled title="<?php esc_attr_e( 'At least one template must remain.', 'data-importer' ); ?>">
					<?php esc_html_e( 'Delete Template', 'data-importer' ); ?>
					(<?php esc_html_e( 'only template', 'data-importer' ); ?>)
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Asset row renderers
	// -------------------------------------------------------------------------

	/**
	 * Render one stylesheet repeater row.
	 *
	 * @param array $style Style row (src, handle).
	 * @return void
	 */
	private function render_style_row( array $style = array() ): void {
		$src    = (string) ( $style['src'] ?? '' );
		$handle = (string) ( $style['handle'] ?? '' );
		?>
		<div class="data-importer-asset-row" data-asset-type="style" data-auto-handle="<?php echo '' === $handle ? '1' : '0'; ?>">
			<input type="text" class="regular-text data-importer-asset-input data-importer-asset-source" name="data_importer_template_styles[][src]" value="<?php echo esc_attr( $src ); ?>" placeholder="https://example.com/styles.css" />
			<input type="text" class="regular-text data-importer-asset-input data-importer-asset-handle" name="data_importer_template_styles[][handle]" value="<?php echo esc_attr( $handle ); ?>" placeholder="<?php esc_attr_e( 'style-handle', 'data-importer' ); ?>" />
			<button type="button" class="button button-small data-importer-remove-asset"><?php esc_html_e( 'Remove', 'data-importer' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render one script repeater row.
	 *
	 * @param array $script Script row (src, handle).
	 * @return void
	 */
	private function render_script_row( array $script = array() ): void {
		$src    = (string) ( $script['src'] ?? '' );
		$handle = (string) ( $script['handle'] ?? '' );
		?>
		<div class="data-importer-asset-row" data-asset-type="script" data-auto-handle="<?php echo '' === $handle ? '1' : '0'; ?>">
			<input type="text" class="regular-text data-importer-asset-input data-importer-asset-source" name="data_importer_template_scripts[][src]" value="<?php echo esc_attr( $src ); ?>" placeholder="https://example.com/script.js" />
			<input type="text" class="regular-text data-importer-asset-input data-importer-asset-handle" name="data_importer_template_scripts[][handle]" value="<?php echo esc_attr( $handle ); ?>" placeholder="<?php esc_attr_e( 'script-handle', 'data-importer' ); ?>" />
			<button type="button" class="button button-small data-importer-remove-asset"><?php esc_html_e( 'Remove', 'data-importer' ); ?></button>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Legacy variable detection
	// -------------------------------------------------------------------------

	/**
	 * Return the subset of known fields that appear as bare $field variables
	 * in any of the three template sections, without $vars[] wrapping.
	 *
	 * @param array $fields           Flat field paths known for this source.
	 * @param array $current_template Template row with template_html, wrapper_before, wrapper_after.
	 * @return string[] Matched field names.
	 */
	private function detect_legacy_var_usage( array $fields, array $current_template ): array {
		if ( empty( $fields ) ) {
			return array();
		}

		$code = implode(
			"\n",
			array(
				(string) ( $current_template['wrapper_before'] ?? '' ),
				(string) ( $current_template['template_html'] ?? '' ),
				(string) ( $current_template['wrapper_after'] ?? '' ),
			)
		);

		$found = array();
		foreach ( $fields as $field ) {
			// Only check the top-level part of a dotted path (e.g. "address" from "address.city")
			// since extract() only creates top-level variables.
			$top = strtok( (string) $field, '.' );

			// Skip reserved variable names that are legitimately in scope.
			if ( in_array( $top, array( 'vars', 'record', 'context', 'code' ), true ) ) {
				continue;
			}

			// Match $top as a standalone variable: not immediately followed by [ (array access)
			// and not preceded by -> (object property). Word boundary prevents partial matches.
			$pattern = '/(?<!->)\$' . preg_quote( $top, '/' ) . '\b(?!\s*\[)/';
			if ( preg_match( $pattern, $code ) ) {
				$found[] = $top;
			}
		}

		return array_unique( $found );
	}

	// -------------------------------------------------------------------------
	// Variable buttons
	// -------------------------------------------------------------------------

	/**
	 * Render variable-insertion buttons for a template editor target.
	 *
	 * @param array  $fields Flat field paths.
	 * @param string $target Target editor ID (template|before|after).
	 * @return void
	 */
	private function render_variable_buttons( array $fields, string $target ): void {
		if ( empty( $fields ) ) {
			echo '<p class="description">' . esc_html__( 'No variables yet. Import data to get field buttons.', 'data-importer' ) . '</p>';
			return;
		}
		?>
		<div class="data-importer-tags" role="group">
			<?php foreach ( $fields as $field ) : ?>
				<button type="button" class="button button-small data-importer-tag-button" data-field="<?php echo esc_attr( $field ); ?>" data-target="<?php echo esc_attr( $target ); ?>">
					<?php echo esc_html( $field ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Error index helper
	// -------------------------------------------------------------------------

	/**
	 * Build an array of latest template errors indexed by template ID.
	 *
	 * @param int $source_id Source ID.
	 * @return array<int,array<string,string>>
	 */
	private function get_template_error_index( int $source_id ): array {
		$log = get_option( 'data_importer_template_error_log_' . $source_id, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		$index = array();
		foreach ( $log as $entry ) {
			$tpl_id = isset( $entry['template'] ) ? absint( $entry['template'] ) : 0;
			if ( $tpl_id <= 0 || isset( $index[ $tpl_id ] ) ) {
				continue;
			}

			$index[ $tpl_id ] = array(
				'time'    => sanitize_text_field( (string) ( $entry['time'] ?? '' ) ),
				'event'   => sanitize_key( (string) ( $entry['event'] ?? '' ) ),
				'context' => sanitize_key( (string) ( $entry['context'] ?? '' ) ),
			);
		}

		return $index;
	}

	// -------------------------------------------------------------------------
	// Asset row decoder (stored JSON → display-ready rows)
	// -------------------------------------------------------------------------

	/**
	 * Decode asset rows stored as JSON or array.
	 *
	 * @param mixed  $value Stored value (JSON string or array).
	 * @param string $type  'style' or 'script'.
	 * @return array<int,array<string,string>>
	 */
	private function decode_asset_rows( $value, string $type ): array {
		$type = 'script' === $type ? 'script' : 'style';
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$decoded = array();
		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) {
				$row = array( 'src' => $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}

			$src = '';
			foreach ( array( 'src', 'source', 'url' ) as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$src = esc_url_raw( trim( (string) $row[ $key ] ) );
					break;
				}
			}

			if ( '' === $src ) {
				continue;
			}

			$decoded[] = array(
				'src'    => $src,
				'handle' => sanitize_key( (string) ( $row['handle'] ?? '' ) ),
			);
		}

		// Suppress unused-variable warning — $type reserved for future per-type logic.
		unset( $type );

		return $decoded;
	}
}
