<?php

namespace DataImporter\Api;

use DataImporter\Infrastructure\Database;
use DataImporter\Plugin;
use DataImporter\Support\IpRules;
use DataImporter\Support\Request;
use WP_Error;
use WP_REST_Server;
/**
 * REST API layer for Data Importer.
 *
 * Routes (per data source, identified by slug):
 *   POST /wp-json/data-importer/v1/import/{slug}
 *   GET  /wp-json/data-importer/v1/fields/{slug}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestController {

	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'data-importer/v1',
			'/import/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
					'mode' => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'replace', 'append', 'upsert' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'data-importer/v1',
			'/fields/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_fields' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * Verify the current user can manage Data Importer (for /fields).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return Plugin::user_can_manage();
	}

	/**
	 * Handle an incoming JSON import for a data source.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_import( $request ) {
		$slug   = (string) $request->get_param( 'slug' );
		$source = Database::get_source_by_slug( $slug );

		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Data source not found.', 'data-importer' ), array( 'status' => 404 ) );
		}

		$source_id = (int) $source['id'];

		$content_type_guard = $this->check_content_type( $request, $source_id );
		if ( is_wp_error( $content_type_guard ) ) {
			return $content_type_guard;
		}

		$payload_guard = $this->check_payload_size( $request, $source_id );
		if ( is_wp_error( $payload_guard ) ) {
			return $payload_guard;
		}

		$rate_limit = $this->check_rate_limit( $slug, $source_id );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$header      = (string) $request->get_header( 'x-api-key' );
		$matched_key = Database::verify_source_api_key( $source_id, $header );

		if ( ! is_array( $matched_key ) ) {
			return $this->reject_import(
				$source_id,
				'unauthorized',
				401,
				__( 'Unauthorized request.', 'data-importer' ),
				'api_key_failed'
			);
		}

		if ( ! $this->is_ip_allowed_for_key( $matched_key ) ) {
			return $this->reject_import(
				$source_id,
				'forbidden_ip',
				403,
				__( 'Request denied.', 'data-importer' ),
				'ip_not_allowed'
			);
		}

		$params = $request->get_json_params();
		if ( null === $params ) {
			return new WP_Error( 'invalid_json', __( 'Body must be valid JSON.', 'data-importer' ), array( 'status' => 400 ) );
		}

		$args = array( 'ip' => $this->get_request_ip() );

		$mode_override = $request->get_param( 'mode' );
		if ( ! empty( $mode_override ) ) {
			$args['mode'] = $mode_override;
		}

		$result = $this->import_source_records( $source, $params, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Import decoded JSON data for a source and update the import log.
	 *
	 * Reused by both the REST endpoint and the admin manual-import UI so the
	 * actual import behavior stays consistent regardless of input method.
	 *
	 * @param array $source Source row.
	 * @param mixed $data   Parsed JSON payload.
	 * @param array $args   Optional overrides such as IP and status.
	 * @return array|WP_Error
	 */
	public function import_source_records( $source, $data, $args = array() ) {
		$source_id = isset( $source['id'] ) ? (int) $source['id'] : 0;
		$slug      = isset( $source['slug'] ) ? (string) $source['slug'] : '';

		if ( $source_id <= 0 || '' === $slug ) {
			return new WP_Error( 'invalid_source', __( 'Data source not found.', 'data-importer' ), array( 'status' => 404 ) );
		}

		$records = $this->normalize_records( $data );
		if ( is_wp_error( $records ) ) {
			return $records;
		}

		$records_guard = $this->check_records_limits( $records, $source_id );
		if ( is_wp_error( $records_guard ) ) {
			return $records_guard;
		}

		$mode       = Database::sanitize_import_mode( (string) ( $args['mode'] ?? $source['import_mode'] ?? 'replace' ) );
		$update_key = trim( (string) ( $source['update_key'] ?? '' ) );

		if ( 'append' === $mode ) {
			$result = Database::append_records( $source_id, $records );
		} elseif ( 'upsert' === $mode ) {
			$key_columns = array_filter( array_map( 'trim', explode( ',', $update_key ) ) );
			$result      = Database::upsert_records( $source_id, $records, array_values( $key_columns ) );
		} else {
			$result = Database::replace_all_records( $source_id, $records );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$time    = current_time( 'mysql' );
		$fields  = Database::get_fields( $source_id );
		$ip      = isset( $args['ip'] ) ? sanitize_text_field( (string) $args['ip'] ) : $this->get_request_ip();
		$status  = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'success';
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;

		$log_entry = array(
			'time'   => $time,
			'count'  => (int) $result,
			'status' => '' !== $status ? $status : 'success',
			'ip'     => '' !== $ip ? $ip : 'unknown',
		);

		if ( $user_id > 0 ) {
			$log_entry['user_id'] = $user_id;
		}

		$this->append_import_log( $source_id, $log_entry );

		return array(
			'success'  => true,
			'source'   => $slug,
			'imported' => (int) $result,
			'fields'   => $fields,
			'time'     => $time,
		);
	}

	/**
	 * Return available field tags for a data source.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_fields( $request ) {
		$slug   = (string) $request->get_param( 'slug' );
		$source = Database::get_source_by_slug( $slug );

		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Data source not found.', 'data-importer' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'source'  => $slug,
				'fields'  => Database::get_fields( (int) $source['id'] ),
			)
		);
	}

	/**
	 * Ensure data is an array of associative arrays.
	 *
	 * @param mixed $data Parsed JSON value.
	 * @return array|WP_Error
	 */
	private function normalize_records( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_payload', __( 'Payload must be an object or array.', 'data-importer' ), array( 'status' => 400 ) );
		}

		if ( $this->is_assoc( $data ) ) {
			return array( $data );
		}

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_item', __( 'All records must be objects.', 'data-importer' ), array( 'status' => 400 ) );
			}
		}

		return $data;
	}

	/**
	 * Recursively sanitize a record value.
	 *
	 * Preserves booleans, integers, floats and null. Strings are
	 * passed through sanitize_text_field.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed
	 */
	public static function sanitize_record( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean_key           = is_numeric( $key ) ? (int) $key : sanitize_key( (string) $key );
				$clean[ $clean_key ] = self::sanitize_record( $item );
			}
			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Per-slug rate limiting using transients (60 requests / min).
	 *
	 * NOTE: This implementation is best-effort only and has known limitations:
	 *
	 * - Non-atomic: `get_transient()` + `set_transient()` is not a single operation.
	 *   Concurrent requests can race past the limit before the counter is incremented.
	 * - Object cache dependency: Without a persistent object cache (e.g. Redis, Memcached),
	 *   transients are stored in `wp_options`. High traffic will create one row per
	 *   IP/slug/window and can itself become a write bottleneck.
	 * - Resettable: Flushing the object cache (e.g. on deploy or restart) clears all
	 *   rate-limit state immediately.
	 *
	 * For production deployments that require reliable rate limiting, enforce limits at
	 * the infrastructure layer instead — a WAF rule, an Nginx `limit_req` directive, or
	 * a reverse-proxy rate limiter will be both more accurate and more performant.
	 *
	 * @param string $slug Source slug (part of the transient key).
	 * @param int    $source_id Source ID.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( $slug, $source_id ) {
		$ip        = $this->get_request_ip();
		$key       = 'data_importer_rate_' . md5( $slug . '|' . $ip );
		$count     = (int) get_transient( $key );
		$limit     = (int) apply_filters( 'data_importer_rate_limit_count', 60, (string) $slug );
		$window    = (int) apply_filters( 'data_importer_rate_limit_window', MINUTE_IN_SECONDS, (string) $slug );

		if ( $limit < 1 ) {
			$limit = 1;
		}
		if ( $window < 1 ) {
			$window = MINUTE_IN_SECONDS;
		}

		if ( $count >= $limit ) {
			return $this->reject_import(
				(int) $source_id,
				'rate_limited',
				429,
				__( 'Too many requests. Please try again later.', 'data-importer' ),
				'rate_limit_exceeded'
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Reject imports that do not declare a JSON Content-Type.
	 *
	 * Defense-in-depth: WordPress will attempt to JSON-decode the body regardless,
	 * but requiring an explicit application/json content type rejects obviously
	 * malformed or form-encoded requests up front and makes the endpoint contract
	 * explicit to clients. The check can be relaxed via the
	 * `data_importer_require_json_content_type` filter.
	 *
	 * @param \WP_REST_Request $request   Request object.
	 * @param int              $source_id Source ID.
	 * @return true|WP_Error
	 */
	private function check_content_type( $request, $source_id ) {
		$require_json = (bool) apply_filters( 'data_importer_require_json_content_type', true, $request );
		if ( ! $require_json ) {
			return true;
		}

		$content_type = (string) $request->get_header( 'content-type' );

		// Accept empty body with no content type — the payload-size / JSON-parse
		// checks that follow will produce a clearer error.
		if ( '' === $content_type && '' === (string) $request->get_body() ) {
			return true;
		}

		// Take the media type portion only (strip charset etc.).
		$media_type = strtolower( trim( (string) strtok( $content_type, ';' ) ) );

		if ( 'application/json' !== $media_type ) {
			return $this->reject_import(
				(int) $source_id,
				'unsupported_media_type',
				415,
				__( 'Content-Type must be application/json.', 'data-importer' ),
				'unsupported_media_type'
			);
		}

		return true;
	}

	/**
	 * Guard against very large request bodies.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param int             $source_id Source ID.
	 * @return true|WP_Error
	 */
	private function check_payload_size( $request, $source_id ) {
		$max_bytes = (int) apply_filters( 'data_importer_max_payload_bytes', 1048576, $request ); // 1 MB default.
		if ( $max_bytes < 1024 ) {
			$max_bytes = 1024;
		}

		$body = (string) $request->get_body();
		if ( '' !== $body && strlen( $body ) > $max_bytes ) {
			return $this->reject_import(
				(int) $source_id,
				'payload_too_large',
				413,
				__( 'Payload is too large.', 'data-importer' ),
				'payload_exceeded_limit'
			);
		}

		return true;
	}

	/**
	 * Guard against very large record batches.
	 *
	 * @param array $records Decoded records.
	 * @param int   $source_id Source ID.
	 * @return true|WP_Error
	 */
	private function check_records_limits( $records, $source_id ) {
		$max_records = (int) apply_filters( 'data_importer_max_records_per_import', 1000, (int) $source_id );
		if ( $max_records < 1 ) {
			$max_records = 1;
		}

		if ( count( $records ) > $max_records ) {
			return $this->reject_import(
				(int) $source_id,
				'too_many_records',
				413,
				__( 'Too many records in a single import.', 'data-importer' ),
				'record_limit_exceeded'
			);
		}

		return true;
	}

	/**
	 * Prepend an entry to the source's import log (max 20 entries).
	 *
	 * @param int   $source_id Source ID.
	 * @param array $entry     Log entry data.
	 * @return void
	 */
	private function append_import_log( $source_id, $entry ) {
		$option = 'data_importer_import_log_' . (int) $source_id;
		$log    = get_option( $option, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, $entry );
		update_option( $option, array_slice( $log, 0, 20 ) );
	}

	/**
	 * Prepend a security-relevant event for a source.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $event     Event key.
	 * @param string $status    HTTP-like status code string.
	 * @return void
	 */
	private function append_security_log( $source_id, $event, $status ) {
		$option = 'data_importer_security_log_' . (int) $source_id;
		$log    = get_option( $option, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'   => current_time( 'mysql' ),
				'event'  => sanitize_key( (string) $event ),
				'status' => sanitize_text_field( (string) $status ),
				'ip'     => $this->get_request_ip(),
			)
		);

		update_option( $option, array_slice( $log, 0, 50 ) );
	}

	/**
	 * Return a WP_Error for rejected imports and log event.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $code      Error code.
	 * @param int    $status    HTTP status.
	 * @param string $message   Public message.
	 * @param string $event     Security log event key.
	 * @return WP_Error
	 */
	private function reject_import( $source_id, $code, $status, $message, $event ) {
		$this->append_security_log( (int) $source_id, (string) $event, (string) (int) $status );
		return new WP_Error( (string) $code, (string) $message, array( 'status' => (int) $status ) );
	}

	/**
	 * Resolve the real client IP, honouring X-Forwarded-For when proxy trust is enabled.
	 *
	 * @return string
	 */
	private function get_request_ip() {
		return Request::get_client_ip();
	}

	/**
	 * Check if requester IP is allowed for one matched API key.
	 *
	 * @param array $key API key row.
	 * @return bool
	 */
	private function is_ip_allowed_for_key( $key ) {
		$current_ip = $this->get_request_ip();
		$rules      = isset( $key['allowed_ips'] ) ? (string) $key['allowed_ips'] : '';

		return IpRules::is_ip_allowed( $current_ip, $rules );
	}

	/**
	 * Whether an array is associative (not a list).
	 *
	 * @param array $array Input array.
	 * @return bool
	 */
	private function is_assoc( $array ) {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
