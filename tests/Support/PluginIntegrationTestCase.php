<?php

namespace DataImporter\Tests\Support;

use DataImporter\Admin\ManualImportProcessor;
use DataImporter\Api\RestController;
use DataImporter\Infrastructure\Database;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

abstract class PluginIntegrationTestCase extends TestCase {

	/** @var int[] */
	private array $source_ids = array();

	/** @var int[] */
	private array $user_ids = array();

	/**
	 * Original safe-mode option value.
	 *
	 * @var mixed
	 */
	private $original_safe_mode;

	private bool $safe_mode_option_exists = false;

	protected function setUp(): void {
		parent::setUp();

		$this->source_ids = array();
		$this->user_ids   = array();

		$this->original_safe_mode      = get_option( 'data_importer_safe_mode', null );
		$this->safe_mode_option_exists = null !== $this->original_safe_mode;

		delete_option( 'data_importer_safe_mode' );
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );

		foreach ( array_reverse( $this->source_ids ) as $source_id ) {
			Database::delete_source( $source_id );
			delete_option( 'data_importer_import_log_' . $source_id );
			delete_option( 'data_importer_security_log_' . $source_id );
			delete_option( 'data_importer_template_error_log_' . $source_id );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		foreach ( array_reverse( $this->user_ids ) as $user_id ) {
			wp_delete_user( $user_id );
		}

		if ( $this->safe_mode_option_exists ) {
			update_option( 'data_importer_safe_mode', $this->original_safe_mode, false );
		} else {
			delete_option( 'data_importer_safe_mode' );
		}

		parent::tearDown();
	}

	protected function createSource( array $overrides = array() ): array {
		$seed     = $this->unique_suffix();
		$defaults = array(
			'name'           => 'Test Source ' . $seed,
			'slug'           => 'test-source-' . $seed,
			'template_html'  => '<article><?php echo esc_html( $title ?? "" ); ?></article>',
			'wrapper_before' => '<section class="data-importer-test-list">',
			'wrapper_after'  => '</section>',
			'import_mode'    => 'replace',
			'update_key'     => '',
		);

		$api_key         = isset( $overrides['api_key'] ) ? (string) $overrides['api_key'] : 'test-key-' . $seed;
		$key_name        = isset( $overrides['api_key_name'] ) ? (string) $overrides['api_key_name'] : 'Primary Key';
		$key_allowed_ips = isset( $overrides['allowed_ips'] ) ? (string) $overrides['allowed_ips'] : '';

		unset( $overrides['api_key'], $overrides['api_key_name'], $overrides['allowed_ips'] );

		$result = Database::insert_source( array_merge( $defaults, $overrides ) );
		if ( is_wp_error( $result ) ) {
			self::fail( 'Could not create source for test: ' . $result->get_error_message() );
		}

		$source_id          = (int) $result;
		$this->source_ids[] = $source_id;

		$key = Database::create_source_api_key(
			$source_id,
			array(
				'name'        => $key_name,
				'allowed_ips' => $key_allowed_ips,
				'secret'      => $api_key,
			)
		);

		if ( is_wp_error( $key ) ) {
			self::fail( 'Could not create API key for test source: ' . $key->get_error_message() );
		}

		$source = Database::get_source( $source_id );
		if ( ! is_array( $source ) ) {
			self::fail( 'Could not reload source after creation.' );
		}

		$source['api_key']     = (string) ( $key['secret'] ?? '' );
		$source['api_key_id']  = (int) ( $key['id'] ?? 0 );
		$source['allowed_ips'] = (string) ( $key['key']['allowed_ips'] ?? $key_allowed_ips );

		return $source;
	}

	protected function createApiKey( int $source_id, array $overrides = array() ): array {
		$seed = $this->unique_suffix();
		$data = array(
			'name'        => isset( $overrides['name'] ) ? (string) $overrides['name'] : 'Key ' . $seed,
			'allowed_ips' => isset( $overrides['allowed_ips'] ) ? (string) $overrides['allowed_ips'] : '',
			'secret'      => isset( $overrides['secret'] ) ? (string) $overrides['secret'] : 'test-key-' . $seed,
		);

		$result = Database::create_source_api_key( $source_id, $data );
		if ( is_wp_error( $result ) ) {
			self::fail( 'Could not create API key for test: ' . $result->get_error_message() );
		}

		$key = Database::get_source_api_key( (int) $result['id'] );
		if ( ! is_array( $key ) ) {
			self::fail( 'Could not reload API key after creation.' );
		}

		$key['secret'] = (string) ( $result['secret'] ?? '' );

		return $key;
	}

	protected function regenerateApiKey( int $key_id, array $overrides = array() ): array {
		$result = Database::regenerate_source_api_key( $key_id, $overrides );
		if ( is_wp_error( $result ) ) {
			self::fail( 'Could not regenerate API key for test: ' . $result->get_error_message() );
		}

		$key = Database::get_source_api_key( $key_id );
		if ( ! is_array( $key ) ) {
			self::fail( 'Could not reload regenerated API key.' );
		}

		$key['secret'] = (string) ( $result['secret'] ?? '' );

		return $key;
	}

	protected function createTemplate( int $source_id, array $overrides = array() ): array {
		$seed     = $this->unique_suffix();
		$defaults = array(
			'name'           => 'Template ' . $seed,
			'slug'           => 'template-' . $seed,
			'template_html'  => '<div><?php echo esc_html( $title ?? "" ); ?></div>',
			'wrapper_before' => '<div class="template-wrapper">',
			'wrapper_after'  => '</div>',
			'styles_json'    => '[]',
			'scripts_json'   => '[]',
		);

		$result = Database::insert_template( $source_id, array_merge( $defaults, $overrides ) );
		if ( is_wp_error( $result ) ) {
			self::fail( 'Could not create template for test: ' . $result->get_error_message() );
		}

		$template = Database::get_template( (int) $result );
		if ( ! is_array( $template ) ) {
			self::fail( 'Could not reload template after creation.' );
		}

		return $template;
	}

	protected function createAdministrator(): int {
		$seed    = $this->unique_suffix();
		$user_id = wp_create_user(
			'data_importer_test_' . $seed,
			wp_generate_password( 24, false, false ),
			'data-importer-test-' . $seed . '@example.com'
		);

		if ( is_wp_error( $user_id ) ) {
			self::fail( 'Could not create administrator user for test: ' . $user_id->get_error_message() );
		}

		$user = new WP_User( (int) $user_id );
		$user->set_role( 'administrator' );

		$this->user_ids[] = (int) $user_id;

		return (int) $user_id;
	}

	protected function manualImportProcessor(): ManualImportProcessor {
		return new ManualImportProcessor();
	}

	protected function importSourceRecords( array $source, array $records, array $args = array() ): array {
		$result = RestController::instance()->import_source_records( $source, $records, $args );
		if ( is_wp_error( $result ) ) {
			self::fail( 'Could not import source records for test: ' . $result->get_error_message() );
		}

		return $result;
	}

	protected function dispatchImportRequest( array $source, $payload, array $options = array() ): WP_REST_Response {
		$encoded = wp_json_encode( $payload );

		return $this->dispatchRawImportRequest( $source, false === $encoded ? '' : (string) $encoded, $options );
	}

	protected function dispatchRawImportRequest( array $source, string $raw_body, array $options = array() ): WP_REST_Response {
		$original_server = $_SERVER;

		$_SERVER['REMOTE_ADDR'] = isset( $options['remote_addr'] ) ? (string) $options['remote_addr'] : '127.0.0.1';

		if ( isset( $options['forwarded_for'] ) ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = (string) $options['forwarded_for'];
		} else {
			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		}

		$request = new WP_REST_Request( 'POST', '/data-importer/v1/import/' . $source['slug'] );
		$request->set_header( 'content-type', 'application/json' );

		if ( empty( $options['without_api_key'] ) ) {
			$request->set_header(
				'x-api-key',
				isset( $options['api_key'] ) ? (string) $options['api_key'] : (string) $source['api_key']
			);
		}

		if ( isset( $options['headers'] ) && is_array( $options['headers'] ) ) {
			foreach ( $options['headers'] as $name => $value ) {
				$request->set_header( (string) $name, (string) $value );
			}
		}

		if ( isset( $options['mode'] ) ) {
			$request->set_query_params(
				array(
					'mode' => (string) $options['mode'],
				)
			);
		}

		$request->set_body( $raw_body );

		$response = rest_do_request( $request );
		$_SERVER  = $original_server;

		if ( ! $response instanceof WP_REST_Response ) {
			self::fail( 'REST request did not return a WP_REST_Response instance.' );
		}

		return $response;
	}

	protected function dispatchFieldsRequest( array $source ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/data-importer/v1/fields/' . $source['slug'] );
		$response = rest_do_request( $request );

		if ( ! $response instanceof WP_REST_Response ) {
			self::fail( 'REST fields request did not return a WP_REST_Response instance.' );
		}

		return $response;
	}

	protected function assertImportSuccessResponse( WP_REST_Response $response, string $source_slug, int $imported ): array {
		self::assertSame( 200, $response->get_status(), 'Expected a successful REST import response.' );

		$data = $response->get_data();
		self::assertIsArray( $data, 'Expected REST response data to be an array.' );
		self::assertArrayHasKey( 'success', $data );
		self::assertTrue( ! empty( $data['success'] ), 'Expected import response success flag to be true.' );
		self::assertSame( $source_slug, (string) $data['source'] );
		self::assertSame( $imported, (int) $data['imported'] );

		return $data;
	}

	protected function assertErrorResponse( WP_REST_Response $response, int $status, string $code ): array {
		self::assertSame( $status, $response->get_status(), 'Unexpected REST error status.' );

		$data = $response->get_data();
		self::assertIsArray( $data, 'Expected REST error response data to be an array.' );
		self::assertSame( $code, (string) ( $data['code'] ?? '' ), 'Unexpected REST error code.' );

		return $data;
	}

	protected function getDecodedRecords( int $source_id ): array {
		$rows    = Database::get_records(
			array(
				'source_id' => $source_id,
				'order'     => 'ASC',
			)
		);
		$records = array();

		foreach ( $rows as $row ) {
			$record = json_decode( (string) ( $row['record_data'] ?? '' ), true );
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	protected function findRecordByField( array $records, string $field, $expected ): ?array {
		foreach ( $records as $record ) {
			if ( array_key_exists( $field, $record ) && $record[ $field ] === $expected ) {
				return $record;
			}
		}

		return null;
	}

	protected function getImportLog( int $source_id ): array {
		$log = get_option( 'data_importer_import_log_' . $source_id, array() );

		return is_array( $log ) ? $log : array();
	}

	protected function getSecurityLog( int $source_id ): array {
		$log = get_option( 'data_importer_security_log_' . $source_id, array() );

		return is_array( $log ) ? $log : array();
	}

	protected function getTemplateErrorLog( int $source_id ): array {
		$log = get_option( 'data_importer_template_error_log_' . $source_id, array() );

		return is_array( $log ) ? $log : array();
	}

	protected function assertWpError( $value, string $message = 'Expected a WP_Error instance.' ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $value, $message );

		return $value;
	}

	private function unique_suffix(): string {
		return strtolower( wp_generate_password( 6, false, false ) ) . '-' . (string) wp_rand( 1000, 9999 );
	}
}
