<?php

namespace DataImporter\Admin\Views;

use DataImporter\Admin\AdminPage;
use DataImporter\Admin\Tabs\AboutTab;
use DataImporter\Admin\Tabs\ApiTab;
use DataImporter\Admin\Tabs\DataTab;
use DataImporter\Admin\Tabs\GeneralTab;
use DataImporter\Admin\Tabs\LogTab;
use DataImporter\Admin\Tabs\ManualTab;
use DataImporter\Admin\Tabs\TemplateTab;
use DataImporter\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the source edit view with tab navigation.
 */
class EditView {

	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Render the edit view for the given source.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	public function render( int $source_id ): void {
		$source = Database::get_source( $source_id );

		if ( ! $source ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Data source not found.', 'data-importer' ) . '</p></div>';
			return;
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs = array(
			'general'  => __( 'General', 'data-importer' ),
			'template' => __( 'Template', 'data-importer' ),
			'api'      => __( 'API', 'data-importer' ),
			'manual'   => __( 'Manual input', 'data-importer' ),
			'data'     => __( 'Records', 'data-importer' ),
			'log'      => __( 'Logs', 'data-importer' ),
			'about'    => __( 'About', 'data-importer' ),
		);

		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'general';
		}
		?>
		<h1 class="wp-heading-inline">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: source name. */
					__( 'Edit: %s', 'data-importer' ),
					$source['name']
				)
			);
			?>
		</h1>
		<a href="<?php echo esc_url( $this->page->list_url() ); ?>" class="page-title-action">← <?php esc_html_e( 'All Data Sources', 'data-importer' ); ?></a>
		<hr class="wp-header-end" />

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Changes saved.', 'data-importer' ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Tabs', 'data-importer' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( $this->page->edit_url( $source_id, $slug ) ); ?>"
				   class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"
				   <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $tab ) {
			case 'template':
				( new TemplateTab( $this->page ) )->render( $source );
				break;
			case 'api':
				( new ApiTab( $this->page ) )->render( $source );
				break;
			case 'manual':
				( new ManualTab( $this->page ) )->render( $source );
				break;
			case 'data':
				( new DataTab( $this->page ) )->render( $source );
				break;
			case 'log':
				( new LogTab( $this->page ) )->render( $source );
				break;
			case 'about':
				( new AboutTab( $this->page ) )->render( $source );
				break;
			case 'general':
			default:
				( new GeneralTab( $this->page ) )->render( $source );
				break;
		}
	}
}
