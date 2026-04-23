<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Frontend\Display;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class ShortcodeDisplayTest extends PluginIntegrationTestCase {

	public function testShortcodeCanRenderASpecificTemplateBySlug(): void {
		$source = $this->createSource(
			array(
				'template_html'  => '<article class="default-view"><?php echo esc_html( $vars["title"] ?? "" ); ?></article>',
				'wrapper_before' => '<section class="default-wrapper">',
				'wrapper_after'  => '</section>',
			)
		);

		$this->createTemplate(
			(int) $source['id'],
			array(
				'name'           => 'Compact',
				'slug'           => 'compact',
				'template_html'  => '<li class="compact-item"><?php echo esc_html( strtoupper( $vars["title"] ?? "" ) ); ?></li>',
				'wrapper_before' => '<ul class="compact-list">',
				'wrapper_after'  => '</ul>',
			)
		);

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Compact title',
				),
			)
		);

		$output = Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => 'compact',
			)
		);

		$this->assertStringContainsString( '<ul class="compact-list">', $output );
		$this->assertStringContainsString( 'COMPACT TITLE', $output );
		$this->assertFalse( false !== strpos( $output, 'default-view' ), 'Expected specific template output to replace the default template.' );
	}

	public function testShortcodeUsesTheDefaultTemplateWhenNoTemplateIsSpecified(): void {
		$source = $this->createSource(
			array(
				'template_html'  => '<article class="default-view"><?php echo esc_html( $vars["title"] ?? "" ); ?></article>',
				'wrapper_before' => '<section class="default-wrapper">',
				'wrapper_after'  => '</section>',
			)
		);

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Default title',
				),
			)
		);

		$output = Display::render_shortcode(
			array(
				'source' => (string) $source['slug'],
			)
		);

		$this->assertStringContainsString( '<section class="default-wrapper">', $output );
		$this->assertStringContainsString( '<article class="default-view">Default title</article>', $output );
	}
}
