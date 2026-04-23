<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Tests\Support\PluginIntegrationTestCase;

class RestImportTest extends PluginIntegrationTestCase {

	public function testRestAppendModeAppendsRecords(): void {
		$source = $this->createSource(
			array(
				'import_mode' => 'append',
			)
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'id'    => 1,
						'title' => 'First',
					),
				)
			),
			(string) $source['slug'],
			1
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'id'    => 2,
						'title' => 'Second',
					),
				)
			),
			(string) $source['slug'],
			1
		);

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 2, $records );
		$this->assertSame( 'First', (string) $records[0]['title'] );
		$this->assertSame( 'Second', (string) $records[1]['title'] );
	}

	public function testRestReplaceModeReplacesExistingRecords(): void {
		$source = $this->createSource(
			array(
				'import_mode' => 'replace',
			)
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'id'    => 1,
						'title' => 'First',
					),
					array(
						'id'    => 2,
						'title' => 'Second',
					),
				)
			),
			(string) $source['slug'],
			2
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'id'    => 3,
						'title' => 'Third',
					),
				)
			),
			(string) $source['slug'],
			1
		);

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 1, $records );
		$this->assertSame( 3, (int) $records[0]['id'] );
		$this->assertSame( 'Third', (string) $records[0]['title'] );
	}

	public function testRestRequestCanOverrideTheConfiguredImportMode(): void {
		$source = $this->createSource(
			array(
				'import_mode' => 'replace',
				'update_key'  => 'external_id',
			)
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'external_id' => 'A',
						'title'       => 'First',
					),
				)
			),
			(string) $source['slug'],
			1
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'external_id' => 'B',
						'title'       => 'Second',
					),
				),
				array(
					'mode' => 'append',
				)
			),
			(string) $source['slug'],
			1
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'external_id' => 'A',
						'title'       => 'Updated',
					),
				),
				array(
					'mode' => 'upsert',
				)
			),
			(string) $source['slug'],
			1
		);

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 2, $records );

		$record_a = $this->findRecordByField( $records, 'external_id', 'A' );
		$this->assertTrue( is_array( $record_a ), 'Expected upserted record A to exist.' );
		$this->assertSame( 'Updated', (string) $record_a['title'] );
	}

	public function testRestUpsertModeUpdatesMatchingRecords(): void {
		$source = $this->createSource(
			array(
				'import_mode' => 'upsert',
				'update_key'  => 'external_id',
			)
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'external_id' => 'A',
						'title'       => 'Original',
					),
				)
			),
			(string) $source['slug'],
			1
		);

		$this->assertImportSuccessResponse(
			$this->dispatchImportRequest(
				$source,
				array(
					array(
						'external_id' => 'A',
						'title'       => 'Updated',
					),
					array(
						'external_id' => 'B',
						'title'       => 'Inserted',
					),
				)
			),
			(string) $source['slug'],
			2
		);

		$records = $this->getDecodedRecords( (int) $source['id'] );
		$this->assertCount( 2, $records );

		$record_a = $this->findRecordByField( $records, 'external_id', 'A' );
		$record_b = $this->findRecordByField( $records, 'external_id', 'B' );

		$this->assertTrue( is_array( $record_a ), 'Expected updated record A to exist.' );
		$this->assertTrue( is_array( $record_b ), 'Expected inserted record B to exist.' );
		$this->assertSame( 'Updated', (string) $record_a['title'] );
		$this->assertSame( 'Inserted', (string) $record_b['title'] );
	}
}
