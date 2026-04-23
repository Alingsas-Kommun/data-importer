<?php

namespace DataImporter\Admin;

use DataImporter\Api\RestController;
use DataImporter\Infrastructure\Database;
use DataImporter\Support\Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared manual-import workflow used by the admin UI and integration tests.
 */
class ManualImportProcessor {

	/**
	 * Validate and import a manual JSON payload for one source.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $raw_json  User-provided JSON text.
	 * @param array  $settings  Optional import settings to persist.
	 * @param array  $context   Optional request context such as IP and user.
	 * @return array|WP_Error
	 */
	public function process( int $source_id, string $raw_json, array $settings = array(), array $context = array() ) {
		$source_id = (int) $source_id;
		$raw_json  = trim( $raw_json );
		$source    = Database::get_source( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Data source not found.', 'data-importer' ) );
		}

		$import_mode_raw = isset( $settings['import_mode'] ) ? sanitize_key( (string) $settings['import_mode'] ) : '';
		$update_key_raw  = isset( $settings['update_key'] ) ? sanitize_text_field( (string) $settings['update_key'] ) : '';

		if ( '' !== $import_mode_raw ) {
			Database::update_source(
				$source_id,
				array(
					'import_mode' => $import_mode_raw,
					'update_key'  => $update_key_raw,
				)
			);

			$source = Database::get_source( $source_id );
			if ( ! $source ) {
				return new WP_Error( 'not_found', __( 'Data source not found.', 'data-importer' ) );
			}
		}

		if ( '' === $raw_json ) {
			return new WP_Error( 'manual_import_empty', __( 'Please paste valid JSON before importing.', 'data-importer' ) );
		}

		$decoded = json_decode( $raw_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'manual_import_invalid_json',
				sprintf(
					/* translators: %s: JSON parser error text. */
					__( 'JSON could not be parsed: %s', 'data-importer' ),
					json_last_error_msg()
				)
			);
		}

		$request_ip = isset( $context['ip'] ) ? sanitize_text_field( (string) $context['ip'] ) : $this->get_request_ip();
		$user_id    = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();

		return RestController::instance()->import_source_records(
			$source,
			$decoded,
			array(
				'ip'      => '' !== $request_ip ? $request_ip : 'unknown',
				'user_id' => $user_id,
				'status'  => 'success',
			)
		);
	}

	/**
	 * Resolve the current request IP for admin-triggered imports.
	 *
	 * @return string
	 */
	private function get_request_ip(): string {
		$ip = Request::get_client_ip();

		return 'unknown' === $ip ? '' : $ip;
	}
}
