<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Api\RestController;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class RestGuardrailsTest extends PluginIntegrationTestCase {

	public function testRestRejectsInvalidJsonBodies(): void {
		$source   = $this->createSource();
		$response = $this->dispatchRawImportRequest( $source, '{"broken":' );

		$this->assertErrorResponse( $response, 400, 'rest_invalid_json' );
		$this->assertCount( 0, $this->getDecodedRecords( (int) $source['id'] ) );
	}

	public function testRestRejectsPayloadsThatExceedTheConfiguredSizeLimit(): void {
		$source = $this->createSource();
		$filter = static function () {
			return 1024;
		};

		add_filter( 'data_importer_max_payload_bytes', $filter );

		try {
			$response = $this->dispatchImportRequest(
				$source,
				array(
					array(
						'title' => str_repeat( 'A', 1500 ),
					),
				)
			);
		} finally {
			remove_filter( 'data_importer_max_payload_bytes', $filter );
		}

		$this->assertErrorResponse( $response, 413, 'payload_too_large' );

		$security_log = $this->getSecurityLog( (int) $source['id'] );
		$this->assertNotEmpty( $security_log, 'Expected oversized payloads to be logged as security events.' );
		$this->assertSame( 'payload_exceeded_limit', (string) $security_log[0]['event'] );
	}

	public function testRestRejectsImportsThatExceedTheConfiguredRecordLimit(): void {
		$source = $this->createSource();
		$filter = static function () {
			return 1;
		};

		add_filter( 'data_importer_max_records_per_import', $filter );

		try {
			$response = $this->dispatchImportRequest(
				$source,
				array(
					array(
						'title' => 'First',
					),
					array(
						'title' => 'Second',
					),
				)
			);
		} finally {
			remove_filter( 'data_importer_max_records_per_import', $filter );
		}

		$this->assertErrorResponse( $response, 413, 'too_many_records' );

		$security_log = $this->getSecurityLog( (int) $source['id'] );
		$this->assertNotEmpty( $security_log, 'Expected record-limit failures to be logged.' );
		$this->assertSame( 'record_limit_exceeded', (string) $security_log[0]['event'] );
	}

	public function testRestRejectsRequestsWhenTheRateLimitIsExceeded(): void {
		$source       = $this->createSource();
		$count_filter = static function () {
			return 1;
		};
		$window_filter = static function () {
			return 60;
		};

		add_filter( 'data_importer_rate_limit_count', $count_filter );
		add_filter( 'data_importer_rate_limit_window', $window_filter );

		try {
			$first = $this->dispatchImportRequest(
				$source,
				array(
					array(
						'title' => 'First allowed request',
					),
				),
				array(
					'remote_addr' => '198.51.100.50',
				)
			);

			$second = $this->dispatchImportRequest(
				$source,
				array(
					array(
						'title' => 'Second blocked request',
					),
				),
				array(
					'remote_addr' => '198.51.100.50',
				)
			);
		} finally {
			remove_filter( 'data_importer_rate_limit_count', $count_filter );
			remove_filter( 'data_importer_rate_limit_window', $window_filter );
		}

		$this->assertImportSuccessResponse( $first, (string) $source['slug'], 1 );
		$this->assertErrorResponse( $second, 429, 'rate_limited' );

		$security_log = $this->getSecurityLog( (int) $source['id'] );
		$this->assertNotEmpty( $security_log, 'Expected rate-limit rejections to be logged.' );
		$this->assertSame( 'rate_limit_exceeded', (string) $security_log[0]['event'] );
	}

	public function testImportRoutineRejectsScalarPayloadsAndRestRejectsNonObjectItems(): void {
		$source = $this->createSource();

		$scalar_result = RestController::instance()->import_source_records( $source, 'plain string payload' );
		$item_response = $this->dispatchImportRequest( $source, array( 123 ) );

		$scalar_error = $this->assertWpError( $scalar_result );
		$error_data   = $scalar_error->get_error_data();

		$this->assertSame( 'invalid_payload', (string) $scalar_error->get_error_code() );
		$this->assertIsArray( $error_data );
		$this->assertSame( 400, (int) ( $error_data['status'] ?? 0 ) );
		$this->assertErrorResponse( $item_response, 400, 'invalid_item' );
	}

	public function testRestUpsertModeRequiresAnUpdateKey(): void {
		$source = $this->createSource(
			array(
				'import_mode' => 'upsert',
				'update_key'  => '',
			)
		);

		$response = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'title' => 'Missing key',
				),
			)
		);

		$this->assertErrorResponse( $response, 400, 'missing_update_key' );
		$this->assertCount( 0, $this->getDecodedRecords( (int) $source['id'] ) );
	}

	public function testFieldsEndpointRequiresManageCapability(): void {
		$source = $this->createSource();

		$response = $this->dispatchFieldsRequest( $source );

		$this->assertErrorResponse( $response, 401, 'rest_forbidden' );
	}

	public function testFieldsEndpointReturnsFlattenedFieldsForAdministrators(): void {
		$source  = $this->createSource();
		$user_id = $this->createAdministrator();

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title'   => 'Fielded',
					'status'  => 'active',
					'score'   => 10,
					'note'    => 'Visible',
					'address' => array(
						'city' => 'Gothenburg',
					),
				),
			)
		);

		wp_set_current_user( $user_id );

		$response = $this->dispatchFieldsRequest( $source );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( ! empty( $data['success'] ) );
		$this->assertSame(
			array( 'address.city', 'note', 'score', 'status', 'title' ),
			array_values( $data['fields'] ?? array() )
		);
	}
}
