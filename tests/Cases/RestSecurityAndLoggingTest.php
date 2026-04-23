<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Infrastructure\Database;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class RestSecurityAndLoggingTest extends PluginIntegrationTestCase {

	public function testAllowedIpAddressesAreEnforcedPerSource(): void {
		$source = $this->createSource(
			array(
				'allowed_ips' => '198.51.100.10,198.51.100.11',
			)
		);

		$denied = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 1,
					'title' => 'Blocked',
				),
			),
			array(
				'remote_addr' => '203.0.113.10',
			)
		);

		$this->assertErrorResponse( $denied, 403, 'forbidden_ip' );

		$allowed = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 2,
					'title' => 'Allowed',
				),
			),
			array(
				'remote_addr' => '198.51.100.11',
			)
		);

		$this->assertImportSuccessResponse( $allowed, (string) $source['slug'], 1 );

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 1, $records );
		$this->assertSame( 'Allowed', (string) $records[0]['title'] );

		$security_log = $this->getSecurityLog( (int) $source['id'] );
		$this->assertNotEmpty( $security_log, 'Expected a denied-IP request to be logged.' );
		$this->assertSame( 'ip_not_allowed', (string) $security_log[0]['event'] );
		$this->assertSame( '203.0.113.10', (string) $security_log[0]['ip'] );
	}

	public function testRestImportRequiresTheConfiguredApiKey(): void {
		$source = $this->createSource();

		$response = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 1,
					'title' => 'Unauthorized',
				),
			),
			array(
				'api_key'     => 'wrong-key',
				'remote_addr' => '203.0.113.25',
			)
		);

		$this->assertErrorResponse( $response, 401, 'unauthorized' );
		$this->assertCount( 0, $this->getDecodedRecords( (int) $source['id'] ) );

		$security_log = $this->getSecurityLog( (int) $source['id'] );
		$this->assertNotEmpty( $security_log, 'Expected a failed auth attempt to be logged.' );
		$this->assertSame( 'api_key_failed', (string) $security_log[0]['event'] );
		$this->assertSame( '401', (string) $security_log[0]['status'] );
		$this->assertSame( '203.0.113.25', (string) $security_log[0]['ip'] );
	}

	public function testAllowedCidrsAreSupportedPerApiKey(): void {
		$source = $this->createSource(
			array(
				'allowed_ips' => '198.51.100.0/24,2001:db8::/64',
			)
		);

		$allowed_ipv4 = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 1,
					'title' => 'CIDR v4',
				),
			),
			array(
				'remote_addr' => '198.51.100.22',
			)
		);

		$this->assertImportSuccessResponse( $allowed_ipv4, (string) $source['slug'], 1 );

		$allowed_ipv6 = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 2,
					'title' => 'CIDR v6',
				),
			),
			array(
				'remote_addr' => '2001:db8::44',
			)
		);

		$this->assertImportSuccessResponse( $allowed_ipv6, (string) $source['slug'], 1 );

		$denied = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 3,
					'title' => 'Blocked CIDR',
				),
			),
			array(
				'remote_addr' => '203.0.113.10',
			)
		);

		$this->assertErrorResponse( $denied, 403, 'forbidden_ip' );
	}

	public function testIpRulesApplyToTheMatchedApiKeyInsteadOfTheWholeSource(): void {
		$source  = $this->createSource(
			array(
				'allowed_ips' => '198.51.100.0/24',
			)
		);
		$second = $this->createApiKey(
			(int) $source['id'],
			array(
				'name'        => 'Secondary',
				'allowed_ips' => '203.0.113.0/24',
				'secret'      => 'secondary-key',
			)
		);

		$denied_primary = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 1,
					'title' => 'Blocked primary',
				),
			),
			array(
				'remote_addr' => '203.0.113.15',
			)
		);

		$this->assertErrorResponse( $denied_primary, 403, 'forbidden_ip' );

		$allowed_secondary = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 2,
					'title' => 'Allowed secondary',
				),
			),
			array(
				'api_key'     => (string) $second['secret'],
				'remote_addr' => '203.0.113.15',
			)
		);

		$this->assertImportSuccessResponse( $allowed_secondary, (string) $source['slug'], 1 );
	}

	public function testSourceStoresHashedApiKeysInsteadOfPlaintextSecrets(): void {
		$source = $this->createSource(
			array(
				'api_key' => 'plain-test-key',
			)
		);

		$reloaded_source = Database::get_source( (int) $source['id'] );
		$key_rows        = Database::get_source_api_keys( (int) $source['id'] );

		$this->assertIsArray( $reloaded_source );
		$this->assertSame( '', (string) ( $reloaded_source['api_key'] ?? '' ) );
		$this->assertNotEmpty( $key_rows );
		$this->assertNotSame( 'plain-test-key', (string) ( $key_rows[0]['key_hash'] ?? '' ) );
		$this->assertNotEmpty( (string) ( $key_rows[0]['key_hash'] ?? '' ) );
	}

	public function testRegeneratedApiKeyInvalidatesThePreviousSecret(): void {
		$source  = $this->createSource(
			array(
				'api_key' => 'old-secret',
			)
		);
		$new_key = $this->regenerateApiKey(
			(int) $source['api_key_id'],
			array(
				'secret' => 'new-secret',
			)
		);

		$old_response = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 1,
					'title' => 'Old secret denied',
				),
			),
			array(
				'api_key' => 'old-secret',
			)
		);

		$this->assertErrorResponse( $old_response, 401, 'unauthorized' );

		$new_response = $this->dispatchImportRequest(
			$source,
			array(
				array(
					'id'    => 2,
					'title' => 'New secret allowed',
				),
			),
			array(
				'api_key' => (string) $new_key['secret'],
			)
		);

		$this->assertImportSuccessResponse( $new_response, (string) $source['slug'], 1 );
	}

	public function testSuccessfulImportsWriteToTheImportLog(): void {
		$source = $this->createSource();

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'id'    => 1,
						'title' => 'Logged',
					),
				),
				array(
					'remote_addr' => '192.0.2.44',
				)
			),
			(string) $source['slug'],
			1
		);

		$import_log = $this->getImportLog( (int) $source['id'] );
		$this->assertNotEmpty( $import_log, 'Expected a successful import to be logged.' );
		$this->assertSame( 1, (int) $import_log[0]['count'] );
		$this->assertSame( 'success', (string) $import_log[0]['status'] );
		$this->assertSame( '192.0.2.44', (string) $import_log[0]['ip'] );
	}
}
