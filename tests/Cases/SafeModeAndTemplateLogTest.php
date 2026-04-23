<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Frontend\Display;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class SafeModeAndTemplateLogTest extends PluginIntegrationTestCase {

	public function testSafeModePreventsShortcodeTemplateExecution(): void {
		$source = $this->createSource(
			array(
				'template_html'  => '<article><?php echo esc_html( $title ?? "" ); ?></article>',
				'wrapper_before' => '<section>',
				'wrapper_after'  => '</section>',
			)
		);

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Safe mode title',
				),
			)
		);

		update_option( 'data_importer_safe_mode', '1', false );

		$output = Display::render_shortcode(
			array(
				'source' => (string) $source['slug'],
			)
		);

		$this->assertSame( '', $output );
	}

	public function testTemplateRuntimeFailuresAreStoredInTheTemplateErrorLog(): void {
		$source = $this->createSource(
			array(
				'template_html'  => '<?php throw new \Exception( "Template exploded" ); ?>',
				'wrapper_before' => '',
				'wrapper_after'  => '',
			)
		);

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Broken template title',
				),
			)
		);

		$output = Display::render_shortcode(
			array(
				'source' => (string) $source['slug'],
			)
		);

		$this->assertStringNotContainsString( 'Template exploded', $output, 'Exception details must not leak into rendered output.' );

		$template_log = $this->getTemplateErrorLog( (int) $source['id'] );
		$this->assertNotEmpty( $template_log, 'Expected template runtime failures to be logged.' );
		$this->assertSame( 'template_runtime', (string) $template_log[0]['event'] );
		$this->assertSame( 'template', (string) $template_log[0]['context'] );
		$this->assertStringContainsString( 'Template exploded', (string) $template_log[0]['message'] );
	}
}
