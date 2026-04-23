<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Infrastructure\Database;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class ManualImportProcessorTest extends PluginIntegrationTestCase {

	public function testManualInputImportsRecordsAndStoresUserContext(): void {
		$source  = $this->createSource();
		$user_id = $this->createAdministrator();

		$result = $this->manualImportProcessor()->process(
			(int) $source['id'],
			'[{"external_id":"A","title":"Manual One"}]',
			array(
				'import_mode' => 'append',
				'update_key'  => 'external_id',
			),
			array(
				'ip'      => '192.0.2.55',
				'user_id' => $user_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->fail( 'Manual import failed unexpectedly: ' . $result->get_error_message() );
		}

		$this->assertSame( 1, (int) $result['imported'] );

		$updated_source = Database::get_source( (int) $source['id'] );
		$this->assertTrue( is_array( $updated_source ), 'Expected source to still exist after manual import.' );
		$this->assertSame( 'append', (string) $updated_source['import_mode'] );
		$this->assertSame( 'external_id', (string) $updated_source['update_key'] );

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 1, $records );
		$this->assertSame( 'Manual One', (string) $records[0]['title'] );

		$import_log = $this->getImportLog( (int) $source['id'] );
		$this->assertNotEmpty( $import_log, 'Expected manual import to write to the import log.' );
		$this->assertSame( $user_id, (int) $import_log[0]['user_id'] );
		$this->assertSame( '192.0.2.55', (string) $import_log[0]['ip'] );
	}

	public function testManualInputRejectsInvalidJson(): void {
		$source = $this->createSource();

		$result = $this->manualImportProcessor()->process(
			(int) $source['id'],
			'{"broken":',
			array(),
			array(
				'ip' => '192.0.2.56',
			)
		);

		$error = $this->assertWpError( $result );
		$this->assertSame( 'manual_import_invalid_json', (string) $error->get_error_code() );
		$this->assertCount( 0, $this->getDecodedRecords( (int) $source['id'] ) );
	}
}
