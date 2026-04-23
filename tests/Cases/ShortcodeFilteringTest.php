<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Frontend\Display;
use DataImporter\Infrastructure\Database;
use DataImporter\Tests\Support\PluginIntegrationTestCase;

class ShortcodeFilteringTest extends PluginIntegrationTestCase {

	public function testShortcodeSupportsFilterOperatorMatrix(): void {
		$source = $this->createFilterSource();

		foreach ( $this->shortcodeFilterCases() as $label => $case ) {
			$output = Display::render_shortcode(
				array_merge(
					array(
						'source' => (string) $source['slug'],
					),
					$case['atts']
				)
			);

			$this->assertNotSame( '', $output, 'Expected shortcode output for filter case "' . $label . '".' );
			$this->assertRenderedTitles( $output, $case['expected'], $case['unexpected'], $label );
		}
	}

	public function testShortcodeSupportsOrderingPaginationAndDatabaseRecordSelection(): void {
		$source = $this->createFilterSource();
		$rows   = Database::get_records(
			array(
				'source_id' => (int) $source['id'],
				'order'     => 'ASC',
			)
		);

		$this->assertCount( 3, $rows, 'Expected the seeded shortcode source to create three record rows.' );

		$paginated_output = Display::render_shortcode(
			array(
				'source' => (string) $source['slug'],
				'order'  => 'DESC',
				'limit'  => 2,
				'offset' => 1,
			)
		);

		$this->assertRenderedTitles( $paginated_output, array( 'Beta', 'Alpha' ), array( 'Gamma' ), 'pagination' );

		$id_output = Display::render_shortcode(
			array(
				'source' => (string) $source['slug'],
				'id'     => (int) $rows[1]['id'],
			)
		);

		$this->assertRenderedTitles( $id_output, array( 'Beta' ), array( 'Alpha', 'Gamma' ), 'id filter' );
	}

	private function createFilterSource(): array {
		$source = $this->createSource(
			array(
				'template_html'  => '<article class="filter-item"><?php echo esc_html( $vars["title"] ?? "" ); ?></article>',
				'wrapper_before' => '<section class="filter-results">',
				'wrapper_after'  => '</section>',
			)
		);

		$this->importSourceRecords( $source, $this->shortcodeRecords() );

		return $source;
	}

	private function shortcodeRecords(): array {
		return array(
			array(
				'title'    => 'Alpha',
				'status'   => 'active',
				'score'    => 5,
				'category' => 'news',
				'note'     => '',
				'address'  => array(
					'city' => 'Alingsas',
				),
			),
			array(
				'title'    => 'Beta',
				'status'   => 'draft',
				'score'    => 10,
				'category' => 'events',
				'note'     => 'Has note',
				'address'  => array(
					'city' => 'Stockholm',
				),
			),
			array(
				'title'    => 'Gamma',
				'status'   => 'active',
				'score'    => 15,
				'category' => 'news',
				'note'     => null,
				'address'  => array(
					'city' => 'Gothenburg',
				),
			),
		);
	}

	private function shortcodeFilterCases(): array {
		return array(
			'default eq'        => array(
				'atts'       => array(
					'where_key'   => 'status',
					'where_value' => 'draft',
				),
				'expected'   => array( 'Beta' ),
				'unexpected' => array( 'Alpha', 'Gamma' ),
			),
			'eq'                => array(
				'atts'       => array(
					'where_key'   => 'status',
					'where_op'    => 'eq',
					'where_value' => 'active',
				),
				'expected'   => array( 'Alpha', 'Gamma' ),
				'unexpected' => array( 'Beta' ),
			),
			'neq'               => array(
				'atts'       => array(
					'where_key'   => 'status',
					'where_op'    => 'neq',
					'where_value' => 'draft',
				),
				'expected'   => array( 'Alpha', 'Gamma' ),
				'unexpected' => array( 'Beta' ),
			),
			'contains'          => array(
				'atts'       => array(
					'where_key'   => 'address.city',
					'where_op'    => 'contains',
					'where_value' => 'holm',
				),
				'expected'   => array( 'Beta' ),
				'unexpected' => array( 'Alpha', 'Gamma' ),
			),
			'ncontains'         => array(
				'atts'       => array(
					'where_key'   => 'title',
					'where_op'    => 'ncontains',
					'where_value' => 'ph',
				),
				'expected'   => array( 'Beta', 'Gamma' ),
				'unexpected' => array( 'Alpha' ),
			),
			'gt'                => array(
				'atts'       => array(
					'where_key'   => 'score',
					'where_op'    => 'gt',
					'where_value' => '9',
				),
				'expected'   => array( 'Beta', 'Gamma' ),
				'unexpected' => array( 'Alpha' ),
			),
			'gte'               => array(
				'atts'       => array(
					'where_key'   => 'score',
					'where_op'    => 'gte',
					'where_value' => '10',
				),
				'expected'   => array( 'Beta', 'Gamma' ),
				'unexpected' => array( 'Alpha' ),
			),
			'lt'                => array(
				'atts'       => array(
					'where_key'   => 'score',
					'where_op'    => 'lt',
					'where_value' => '10',
				),
				'expected'   => array( 'Alpha' ),
				'unexpected' => array( 'Beta', 'Gamma' ),
			),
			'lte'               => array(
				'atts'       => array(
					'where_key'   => 'score',
					'where_op'    => 'lte',
					'where_value' => '10',
				),
				'expected'   => array( 'Alpha', 'Beta' ),
				'unexpected' => array( 'Gamma' ),
			),
			'in'                => array(
				'atts'       => array(
					'where_key'   => 'category',
					'where_op'    => 'in',
					'where_value' => 'news,alerts',
				),
				'expected'   => array( 'Alpha', 'Gamma' ),
				'unexpected' => array( 'Beta' ),
			),
			'nin'               => array(
				'atts'       => array(
					'where_key'   => 'category',
					'where_op'    => 'nin',
					'where_value' => 'events',
				),
				'expected'   => array( 'Alpha', 'Gamma' ),
				'unexpected' => array( 'Beta' ),
			),
			'starts_with'       => array(
				'atts'       => array(
					'where_key'   => 'address.city',
					'where_op'    => 'starts_with',
					'where_value' => 'Ali',
				),
				'expected'   => array( 'Alpha' ),
				'unexpected' => array( 'Beta', 'Gamma' ),
			),
			'ends_with'         => array(
				'atts'       => array(
					'where_key'   => 'address.city',
					'where_op'    => 'ends_with',
					'where_value' => 'burg',
				),
				'expected'   => array( 'Gamma' ),
				'unexpected' => array( 'Alpha', 'Beta' ),
			),
			'empty'             => array(
				'atts'       => array(
					'where_key' => 'note',
					'where_op'  => 'empty',
				),
				'expected'   => array( 'Alpha', 'Gamma' ),
				'unexpected' => array( 'Beta' ),
			),
			'not_empty'         => array(
				'atts'       => array(
					'where_key' => 'note',
					'where_op'  => 'not_empty',
				),
				'expected'   => array( 'Beta' ),
				'unexpected' => array( 'Alpha', 'Gamma' ),
			),
			'compact where'     => array(
				'atts'       => array(
					'where' => 'address.city|contains|holm',
				),
				'expected'   => array( 'Beta' ),
				'unexpected' => array( 'Alpha', 'Gamma' ),
			),
		);
	}

	private function assertRenderedTitles( string $output, array $expected, array $unexpected, string $label ): void {
		foreach ( $expected as $title ) {
			$this->assertStringContainsString(
				'<article class="filter-item">' . $title . '</article>',
				$output,
				'Expected shortcode output for case "' . $label . '" to contain "' . $title . '".'
			);
		}

		foreach ( $unexpected as $title ) {
			$this->assertStringNotContainsString(
				'<article class="filter-item">' . $title . '</article>',
				$output,
				'Expected shortcode output for case "' . $label . '" to exclude "' . $title . '".'
			);
		}
	}
}
