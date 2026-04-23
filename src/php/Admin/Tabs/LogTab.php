<?php

namespace DataImporter\Admin\Tabs;

use DataImporter\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Logs tab (import log, security log, template error log).
 */
class LogTab {

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
		$log              = get_option( 'data_importer_import_log_' . $source['id'], array() );
		$security_log     = get_option( 'data_importer_security_log_' . $source['id'], array() );
		$template_err_log = get_option( 'data_importer_template_error_log_' . $source['id'], array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}
		if ( ! is_array( $security_log ) ) {
			$security_log = array();
		}
		if ( ! is_array( $template_err_log ) ) {
			$template_err_log = array();
		}
		?>
		<div class="metabox-holder">

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Import Log (latest 20)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<?php if ( empty( $log ) ) : ?>
						<p class="description"><?php esc_html_e( 'No imports logged yet.', 'data-importer' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Timestamp', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Records', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'IP / User', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Status', 'data-importer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $log as $entry ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) (int) ( $entry['count'] ?? 0 ) ); ?></td>
										<td>
											<?php
											$ip_text = esc_html( (string) ( $entry['ip'] ?? '' ) );
											$uid     = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
											if ( $uid > 0 ) {
												$u = get_userdata( $uid );
												if ( $u ) {
													$profile_url = esc_url( add_query_arg( array( 'user_id' => $uid ), admin_url( 'user-edit.php' ) ) );
													echo '<code>' . $ip_text . '</code> (<a href="' . $profile_url . '">' . esc_html( $u->user_login ) . '</a>)'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												} else {
													echo '<code>' . $ip_text . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												}
											} else {
												echo '<code>' . $ip_text . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											}
											?>
										</td>
										<td><?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Security Log (latest 50)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<?php if ( empty( $security_log ) ) : ?>
						<p class="description"><?php esc_html_e( 'No blocked attempts logged yet.', 'data-importer' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Timestamp', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Event', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Status', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'IP', 'data-importer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $security_log as $entry ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $entry['event'] ?? '' ) ); ?></code></td>
										<td><?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $entry['ip'] ?? '' ) ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="postbox" id="data-importer-template-error-log">
				<h2 class="hndle"><span><?php esc_html_e( 'Template Error Log (latest 50)', 'data-importer' ); ?></span></h2>
				<div class="inside">
					<?php if ( empty( $template_err_log ) ) : ?>
						<p class="description"><?php esc_html_e( 'No template errors logged yet.', 'data-importer' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Timestamp', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Event', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Context', 'data-importer' ); ?></th>
									<th><?php esc_html_e( 'Template ID', 'data-importer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $template_err_log as $entry ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $entry['event'] ?? '' ) ); ?></code></td>
										<td><?php echo esc_html( (string) ( $entry['context'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) (int) ( $entry['template'] ?? 0 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

		</div>
		<?php
	}
}
