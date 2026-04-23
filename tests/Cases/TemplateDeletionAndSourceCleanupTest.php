<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Admin\AdminPage;
use DataImporter\Admin\TemplateFormHandler;
use DataImporter\Infrastructure\Database;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class TemplateDeletionAndSourceCleanupTest extends PluginIntegrationTestCase {

	public function testDeleteTemplateRejectsTemplatesFromAnotherSource(): void {
		$source_a         = $this->createSource( array( 'name' => 'Source A', 'slug' => 'source-a' ) );
		$source_b         = $this->createSource( array( 'name' => 'Source B', 'slug' => 'source-b' ) );
		$foreign_template = $this->createTemplate(
			(int) $source_b['id'],
			array(
				'name' => 'Foreign Template',
				'slug' => 'foreign-template',
			)
		);
		$admin_id         = $this->createAdministrator();

		wp_set_current_user( $admin_id );

		$page = $this->getMockBuilder( AdminPage::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_manage_plugin' ) )
			->getMock();
		$page->method( 'can_manage_plugin' )->willReturn( true );

		$handler = new CapturingTemplateFormHandler( $page );
		$original_post = $_POST;
		$original_request = $_REQUEST;

		$_POST = array(
			'data_importer_source_id'   => (string) $source_a['id'],
			'data_importer_template_id' => (string) $foreign_template['id'],
			'data_importer_nonce'       => wp_create_nonce( 'data_importer_delete_template_' . $source_a['id'] ),
		);
		$_REQUEST = $_POST;

		$templates_before = Database::get_templates_by_source( (int) $source_b['id'] );

		try {
			$handler->handle_delete_template();
		} finally {
			$_POST    = $original_post;
			$_REQUEST = $original_request;
		}

		$templates_after = Database::get_templates_by_source( (int) $source_b['id'] );

		$this->assertNotNull( Database::get_template( (int) $foreign_template['id'] ) );
		$this->assertCount( count( $templates_before ), $templates_after );
		$this->assertNotNull( $handler->redirect_url, 'Expected the tampered delete request to redirect with an error.' );

		$query = array();
		parse_str( (string) wp_parse_url( (string) $handler->redirect_url, PHP_URL_QUERY ), $query );

		$this->assertSame( (string) $source_a['id'], (string) ( $query['source_id'] ?? '' ) );
		$this->assertSame( 'template', (string) ( $query['tab'] ?? '' ) );
		$this->assertSame( 'Template not found.', rawurldecode( (string) ( $query['error'] ?? '' ) ) );
	}

	public function testDeletingASourceRemovesItsRecordsTemplatesAndLogs(): void {
		$source = $this->createSource();

		$this->createTemplate(
			(int) $source['id'],
			array(
				'name' => 'Second Template',
				'slug' => 'second-template',
			)
		);

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Delete me',
				),
			)
		);

		update_option(
			'data_importer_import_log_' . $source['id'],
			array(
				array(
					'status' => 'success',
				),
			),
			false
		);
		update_option(
			'data_importer_security_log_' . $source['id'],
			array(
				array(
					'event' => 'api_key_failed',
				),
			),
			false
		);
		update_option(
			'data_importer_template_error_log_' . $source['id'],
			array(
				array(
					'event' => 'template_runtime',
				),
			),
			false
		);

		Database::delete_source( (int) $source['id'] );

		$this->assertNull( Database::get_source( (int) $source['id'] ) );
		$this->assertSame( array(), Database::get_source_api_keys( (int) $source['id'] ) );
		$this->assertSame( array(), Database::get_templates_by_source( (int) $source['id'] ) );
		$this->assertSame( array(), Database::get_records( array( 'source_id' => (int) $source['id'] ) ) );
		$this->assertFalse( get_option( 'data_importer_import_log_' . $source['id'], false ) );
		$this->assertFalse( get_option( 'data_importer_security_log_' . $source['id'], false ) );
		$this->assertFalse( get_option( 'data_importer_template_error_log_' . $source['id'], false ) );
	}
}

class CapturingTemplateFormHandler extends TemplateFormHandler {

	public ?string $redirect_url = null;

	protected function redirect( string $url ): void {
		$this->redirect_url = $url;
	}
}
