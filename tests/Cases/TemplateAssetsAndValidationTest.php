<?php

namespace DataImporter\Tests\Cases;

use DataImporter\Admin\AdminPage;
use DataImporter\Admin\Tabs\TemplateTab;
use DataImporter\Admin\TemplateFormHandler;
use DataImporter\Frontend\Display;
use DataImporter\Infrastructure\Database;
use DataImporter\Tests\Support\PluginIntegrationTestCase;
use WP_Post;

class TemplateAssetsAndValidationTest extends PluginIntegrationTestCase {

	/** @var int[] */
	private array $post_ids = array();

	/** @var string[] */
	private array $style_handles = array();

	/** @var string[] */
	private array $script_handles = array();

	private $original_post;

	protected function setUp(): void {
		parent::setUp();

		$this->post_ids       = array();
		$this->style_handles  = array();
		$this->script_handles = array();
		$this->original_post  = $GLOBALS['post'] ?? null;
	}

	protected function tearDown(): void {
		global $post;

		foreach ( array_reverse( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( array_reverse( $this->style_handles ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		foreach ( array_reverse( $this->script_handles ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		wp_dequeue_style( 'data-importer-frontend' );
		wp_deregister_style( 'data-importer-frontend' );

		$post = $this->original_post;

		parent::tearDown();
	}

	public function testTemplateAssetSanitizationPreservesBlankHandlesAndDeduplicatesExplicitHandles(): void {
		$handler = $this->createTemplateFormHandler();

		$styles = $handler->sanitize_template_style_assets(
			array(
				array(
					'src' => 'https://cdn.example.com/assets/cards.css?ver=1',
				),
				array(
					'src' => 'https://static.example.com/assets/cards.css',
				),
				array(
					'src'    => 'https://static.example.com/assets/explicit.css',
					'handle' => 'shared-style',
				),
				array(
					'src'    => 'https://static.example.com/assets/other.css',
					'handle' => 'shared-style',
				),
				array(
					'src' => '',
				),
			)
		);

		$scripts = $handler->sanitize_template_script_assets(
			array(
				array(
					'src' => 'https://cdn.example.com/assets/cards.js?ver=1',
				),
				array(
					'src' => 'https://static.example.com/assets/cards.js',
				),
				array(
					'src'    => 'https://static.example.com/assets/runtime.js',
					'handle' => 'shared-script',
				),
				array(
					'src'    => 'https://static.example.com/assets/other-runtime.js',
					'handle' => 'shared-script',
				),
				array(
					'src' => '',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'src'    => 'https://cdn.example.com/assets/cards.css?ver=1',
					'handle' => '',
				),
				array(
					'src'    => 'https://static.example.com/assets/cards.css',
					'handle' => '',
				),
				array(
					'src'    => 'https://static.example.com/assets/explicit.css',
					'handle' => 'shared-style',
				),
				array(
					'src'    => 'https://static.example.com/assets/other.css',
					'handle' => 'shared-style-2',
				),
			),
			$styles
		);

		$this->assertSame(
			array(
				array(
					'src'    => 'https://cdn.example.com/assets/cards.js?ver=1',
					'handle' => '',
				),
				array(
					'src'    => 'https://static.example.com/assets/cards.js',
					'handle' => '',
				),
				array(
					'src'    => 'https://static.example.com/assets/runtime.js',
					'handle' => 'shared-script',
				),
				array(
					'src'    => 'https://static.example.com/assets/other-runtime.js',
					'handle' => 'shared-script-2',
				),
			),
			$scripts
		);
	}

	public function testSavingTemplateRejectsDryRunFailuresAndPreservesStoredTemplate(): void {
		$source   = $this->createSource();
		$template = Database::get_default_template_for_source( (int) $source['id'] );
		$user_id  = $this->createAdministrator();

		$this->assertTrue( is_array( $template ), 'Expected a default template for the created source.' );

		wp_set_current_user( $user_id );

		$handler = $this->createCapturingTemplateFormHandler();

		$original_post    = $_POST;
		$original_request = $_REQUEST;

		$_POST = array(
			'data_importer_source_id'        => (string) $source['id'],
			'data_importer_template_id'      => (string) $template['id'],
			'data_importer_nonce'            => wp_create_nonce( 'data_importer_save_template_' . $source['id'] ),
			'data_importer_template_name'    => (string) $template['name'],
			'data_importer_template_slug'    => (string) $template['slug'],
			'data_importer_template_html'    => '<?php throw new \Exception( "Save blocked" ); ?>',
			'data_importer_wrapper_before'   => '<section class="broken-save">',
			'data_importer_wrapper_after'    => '</section>',
			'data_importer_template_styles'  => array(),
			'data_importer_template_scripts' => array(),
		);
		$_REQUEST = $_POST;

		try {
			$handler->handle_save_template();
		} finally {
			$_POST    = $original_post;
			$_REQUEST = $original_request;
		}

		$reloaded = Database::get_template( (int) $template['id'] );

		$this->assertTrue( is_array( $reloaded ), 'Expected the template to still exist after a failed save.' );
		$this->assertSame( (string) $template['template_html'], (string) $reloaded['template_html'] );
		$this->assertNotNull( $handler->redirect_url, 'Expected a failed template save to redirect with an error.' );

		$query = array();
		parse_str( (string) wp_parse_url( (string) $handler->redirect_url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'template', (string) ( $query['tab'] ?? '' ) );
		$this->assertSame( (string) $template['id'], (string) ( $query['template_id'] ?? '' ) );
		$this->assertStringContainsString(
			'Template error during test run: Save blocked',
			rawurldecode( (string) ( $query['error'] ?? '' ) )
		);

		$template_log = $this->getTemplateErrorLog( (int) $source['id'] );
		$this->assertNotEmpty( $template_log, 'Expected failed dry runs to be logged.' );
		$this->assertSame( 'template_runtime', (string) $template_log[0]['event'] );
		$this->assertSame( 'save_template', (string) $template_log[0]['context'] );
	}

	public function testSavingTemplateSuccessfullyMarksItsPreviousErrorLogEntriesResolved(): void {
		$source         = $this->createSource();
		$template       = Database::get_default_template_for_source( (int) $source['id'] );
		$other_template = $this->createTemplate(
			(int) $source['id'],
			array(
				'name' => 'Other Template',
				'slug' => 'other-template',
			)
		);
		$user_id        = $this->createAdministrator();

		$this->assertTrue( is_array( $template ), 'Expected a default template for the created source.' );

		update_option(
			'data_importer_template_error_log_' . $source['id'],
			array(
				array(
					'time'      => '2026-05-20 10:00:00',
					'event'     => 'template_runtime',
					'context'   => 'template',
					'message'   => 'Old error',
					'template'  => (int) $template['id'],
					'source_id' => (int) $source['id'],
				),
				array(
					'time'      => '2026-05-20 11:00:00',
					'event'     => 'template_runtime',
					'context'   => 'template',
					'message'   => 'Other template error',
					'template'  => (int) $other_template['id'],
					'source_id' => (int) $source['id'],
				),
			),
			false
		);

		wp_set_current_user( $user_id );

		$handler = $this->createCapturingTemplateFormHandler();

		$original_post    = $_POST;
		$original_request = $_REQUEST;

		$_POST = array(
			'data_importer_source_id'        => (string) $source['id'],
			'data_importer_template_id'      => (string) $template['id'],
			'data_importer_nonce'            => wp_create_nonce( 'data_importer_save_template_' . $source['id'] ),
			'data_importer_template_name'    => (string) $template['name'],
			'data_importer_template_slug'    => (string) $template['slug'],
			'data_importer_template_html'    => '<article><?php echo esc_html( $record["title"] ?? "" ); ?></article>',
			'data_importer_wrapper_before'   => '<section class="fixed-template">',
			'data_importer_wrapper_after'    => '</section>',
			'data_importer_template_styles'  => array(),
			'data_importer_template_scripts' => array(),
		);
		$_REQUEST = $_POST;

		try {
			$handler->handle_save_template();
		} finally {
			$_POST    = $original_post;
			$_REQUEST = $original_request;
		}

		$template_log = $this->getTemplateErrorLog( (int) $source['id'] );

		$this->assertNotNull( $handler->redirect_url, 'Expected a successful template save to redirect.' );
		$this->assertCount( 2, $template_log );
		$this->assertSame( (int) $template['id'], (int) $template_log[0]['template'] );
		$this->assertSame( 1, (int) $template_log[0]['resolved'] );
		$this->assertNotEmpty( $template_log[0]['resolved_at'] );
		$this->assertSame( (int) $other_template['id'], (int) $template_log[1]['template'] );
		$this->assertArrayNotHasKey( 'resolved', $template_log[1] );
	}

	public function testTemplateErrorBadgeIndexIgnoresResolvedLogEntries(): void {
		$source          = $this->createSource();
		$resolved_tpl    = Database::get_default_template_for_source( (int) $source['id'] );
		$current_tpl     = $this->createTemplate(
			(int) $source['id'],
			array(
				'name' => 'Current Error Template',
				'slug' => 'current-error-template',
			)
		);
		$page            = $this->getMockBuilder( AdminPage::class )
			->disableOriginalConstructor()
			->getMock();
		$template_tab    = new TemplateTab( $page );
		$reflection      = new \ReflectionClass( TemplateTab::class );
		$error_index     = $reflection->getMethod( 'get_template_error_index' );

		$this->assertTrue( is_array( $resolved_tpl ), 'Expected a default template for the created source.' );

		update_option(
			'data_importer_template_error_log_' . $source['id'],
			array(
				array(
					'time'        => '2026-05-20 10:00:00',
					'event'       => 'template_runtime',
					'context'     => 'template',
					'message'     => 'Resolved error',
					'template'    => (int) $resolved_tpl['id'],
					'source_id'   => (int) $source['id'],
					'resolved'    => 1,
					'resolved_at' => '2026-05-20 10:05:00',
				),
				array(
					'time'      => '2026-05-20 11:00:00',
					'event'     => 'template_runtime',
					'context'   => 'template',
					'message'   => 'Current error',
					'template'  => (int) $current_tpl['id'],
					'source_id' => (int) $source['id'],
				),
			),
			false
		);

		$error_index->setAccessible( true );
		$index = $error_index->invoke( $template_tab, (int) $source['id'] );

		$this->assertArrayNotHasKey( (int) $resolved_tpl['id'], $index );
		$this->assertArrayHasKey( (int) $current_tpl['id'], $index );
	}

	public function testTemplateErrorLogLinkTargetsLogTab(): void {
		$source   = $this->createSource();
		$template = Database::get_default_template_for_source( (int) $source['id'] );

		$this->assertTrue( is_array( $template ), 'Expected a default template for the created source.' );

		$page = $this->getMockBuilder( AdminPage::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'template_url', 'edit_url', 'render_shortcode_copy_control' ) )
			->getMock();

		$page->method( 'template_url' )->willReturn( 'https://wordpress.local/wp-admin/admin.php?page=data-importer&source_id=' . (int) $source['id'] . '&tab=template&template_id=' . (int) $template['id'] );
		$page->method( 'edit_url' )->willReturnCallback(
			static function ( int $source_id, string $tab = 'general' ): string {
				return 'https://wordpress.local/wp-admin/admin.php?page=data-importer&source_id=' . $source_id . '&tab=' . $tab;
			}
		);
		$page->method( 'render_shortcode_copy_control' )->willReturnCallback(
			static function (): void {
				echo '<span class="shortcode-placeholder"></span>';
			}
		);

		$template_tab = new TemplateTab( $page );
		$reflection   = new \ReflectionClass( TemplateTab::class );
		$render_list  = $reflection->getMethod( 'render_list' );
		$error_index  = array(
			(int) $template['id'] => array(
				'time'    => '2026-05-20 10:00:00',
				'event'   => 'template_runtime',
				'context' => 'template',
			),
		);

		$render_list->setAccessible( true );

		ob_start();
		$render_list->invoke( $template_tab, $source, array( $template ), $error_index );
		$html = (string) ob_get_clean();

		$decoded = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

		$this->assertStringContainsString( 'tab=log#data-importer-template-error-log', $decoded );
		$this->assertStringNotContainsString( 'tab=api#data-importer-template-error-log', $decoded );
	}

	public function testValidateTemplateCodeRejectsOversizedNullByteAndPolicyBlockedTemplates(): void {
		$max_bytes_filter = static function () {
			return 1024;
		};
		$policy_filter    = static function () {
			return false;
		};

		add_filter( 'data_importer_max_template_bytes', $max_bytes_filter );
		$too_large = Display::validate_template_code( str_repeat( 'A', 1025 ) );
		remove_filter( 'data_importer_max_template_bytes', $max_bytes_filter );

		$null_byte = Display::validate_template_code( "safe\0unsafe" );

		add_filter( 'data_importer_validate_template_code', $policy_filter );
		$rejected = Display::validate_template_code( '<div>Blocked by policy</div>' );
		remove_filter( 'data_importer_validate_template_code', $policy_filter );

		$this->assertSame( 'template_too_large', (string) $this->assertWpError( $too_large )->get_error_code() );
		$this->assertSame( 'template_invalid', (string) $this->assertWpError( $null_byte )->get_error_code() );
		$this->assertSame( 'template_rejected', (string) $this->assertWpError( $rejected )->get_error_code() );
	}

	public function testDuplicateShortcodesDoNotEnqueueTemplateAssetsMultipleTimes(): void {
		$source   = $this->createSource();
		$template = $this->createTemplate(
			(int) $source['id'],
			array(
				'name'           => 'Asset Template',
				'slug'           => 'asset-template',
				'styles_json'    => array(
					array(
						'src'    => 'https://cdn.example.com/assets/repeated-style.css',
						'handle' => 'repeated-style',
					),
				),
				'scripts_json'   => array(
					array(
						'src'    => 'https://cdn.example.com/assets/repeated-script.js',
						'handle' => 'repeated-script',
					),
				),
			)
		);
		$post     = $this->createPostWithContent(
			sprintf(
				'[data_importer source="%1$s" template="%2$s"][data_importer source="%1$s" template="%2$s"]',
				$source['slug'],
				$template['slug']
			)
		);

		$this->style_handles[]  = 'repeated-style';
		$this->script_handles[] = 'repeated-script';

		$this->importSourceRecords(
			$source,
			array(
				array(
					'title' => 'Repeated output',
				),
			)
		);

		$this->enqueueAssetsForPost( $post );

		Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => (string) $template['slug'],
			)
		);
		Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => (string) $template['slug'],
			)
		);

		$style_queue  = wp_styles()->queue;
		$script_queue = wp_scripts()->queue;

		$this->assertSame( 1, $this->countHandleOccurrences( $style_queue, 'repeated-style' ) );
		$this->assertSame( 1, $this->countHandleOccurrences( $script_queue, 'repeated-script' ) );
		$this->assertSame( 'https://cdn.example.com/assets/repeated-style.css', (string) wp_styles()->registered['repeated-style']->src );
		$this->assertSame( 'https://cdn.example.com/assets/repeated-script.js', (string) wp_scripts()->registered['repeated-script']->src );
	}

	public function testTemplateInlineCodePrintsOnceInHeadAndFooter(): void {
		$source   = $this->createSource();
		$template = $this->createTemplate(
			(int) $source['id'],
			array(
				'name'        => 'Inline Code Template',
				'slug'        => 'inline-code',
				'style_code'  => '.data-importer-inline-test { color: red; } /* </style> */',
				'script_code' => 'window.dataImporterInlineTest = true; console.log("</script>");',
			)
		);
		$post     = $this->createPostWithContent(
			sprintf(
				'[data_importer source="%1$s" template="%2$s"][data_importer source="%1$s" template="%2$s"]',
				$source['slug'],
				$template['slug']
			)
		);

		$this->enqueueAssetsForPost( $post );

		ob_start();
		Display::instance()->print_inline_styles();
		$style_output = (string) ob_get_clean();

		ob_start();
		Display::instance()->print_inline_scripts();
		$script_output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="data-importer-template-inline-styles"', $style_output );
		$this->assertStringContainsString( 'id="data-importer-template-inline-scripts"', $script_output );
		$this->assertSame( 1, substr_count( $style_output, '.data-importer-inline-test' ) );
		$this->assertSame( 1, substr_count( $script_output, 'window.dataImporterInlineTest' ) );
		$this->assertStringContainsString( '<\/style>', $style_output );
		$this->assertStringContainsString( '</\u0073cript>', $script_output );
		$this->assertSame( 1, substr_count( $script_output, '</script>' ) );
	}

	public function testBlankStoredAssetHandlesGenerateRuntimeHandlesFromSource(): void {
		$source   = $this->createSource();
		$template = $this->createTemplate(
			(int) $source['id'],
			array(
				'name'         => 'Generated Handles Template',
				'slug'         => 'generated-handles',
				'styles_json'  => array(
					array(
						'src'    => 'https://cdn.example.com/assets/default-style.css',
						'handle' => '',
					),
				),
				'scripts_json' => array(
					array(
						'src'    => 'https://cdn.example.com/assets/default-script.js',
						'handle' => '',
					),
				),
			)
		);
		$post     = $this->createPostWithContent(
			sprintf(
				'[data_importer source="%1$s" template="%2$s"]',
				$source['slug'],
				$template['slug']
			)
		);

		$this->enqueueAssetsForPost( $post );

		Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => (string) $template['slug'],
			)
		);

		$style_matches = $this->findEnqueuedHandlesForSources(
			wp_styles()->queue,
			wp_styles()->registered,
			array( 'https://cdn.example.com/assets/default-style.css' )
		);
		$script_matches = $this->findEnqueuedHandlesForSources(
			wp_scripts()->queue,
			wp_scripts()->registered,
			array( 'https://cdn.example.com/assets/default-script.js' )
		);

		$this->style_handles  = array_merge( $this->style_handles, array_keys( $style_matches ) );
		$this->script_handles = array_merge( $this->script_handles, array_keys( $script_matches ) );

		$this->assertSame( array( 'https://cdn.example.com/assets/default-style.css' ), array_values( $style_matches ) );
		$this->assertSame( array( 'https://cdn.example.com/assets/default-script.js' ), array_values( $script_matches ) );
		$this->assertStringContainsString( 'default-style', (string) array_key_first( $style_matches ) );
		$this->assertStringContainsString( 'default-script', (string) array_key_first( $script_matches ) );
	}

	public function testAssetHandleCollisionsGenerateUniqueEnqueueHandlesForDifferentSources(): void {
		$source      = $this->createSource();
		$template_one = $this->createTemplate(
			(int) $source['id'],
			array(
				'name'         => 'Template One',
				'slug'         => 'collision-one',
				'styles_json'  => array(
					array(
						'src'    => 'https://cdn.example.com/assets/source-one.css',
						'handle' => 'collision-style-' . $source['id'],
					),
				),
				'scripts_json' => array(
					array(
						'src'    => 'https://cdn.example.com/assets/source-one.js',
						'handle' => 'collision-script-' . $source['id'],
					),
				),
			)
		);
		$template_two = $this->createTemplate(
			(int) $source['id'],
			array(
				'name'         => 'Template Two',
				'slug'         => 'collision-two',
				'styles_json'  => array(
					array(
						'src'    => 'https://cdn.example.com/assets/source-two.css',
						'handle' => 'collision-style-' . $source['id'],
					),
				),
				'scripts_json' => array(
					array(
						'src'    => 'https://cdn.example.com/assets/source-two.js',
						'handle' => 'collision-script-' . $source['id'],
					),
				),
			)
		);
		$post         = $this->createPostWithContent(
			sprintf(
				'[data_importer source="%1$s" template="%2$s"][data_importer source="%1$s" template="%3$s"]',
				$source['slug'],
				$template_one['slug'],
				$template_two['slug']
			)
		);

		$base_style_handle  = 'collision-style-' . $source['id'];
		$base_script_handle = 'collision-script-' . $source['id'];

		$this->enqueueAssetsForPost( $post );

		Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => (string) $template_one['slug'],
			)
		);
		Display::render_shortcode(
			array(
				'source'   => (string) $source['slug'],
				'template' => (string) $template_two['slug'],
			)
		);

		$style_matches = $this->findEnqueuedHandlesForSources(
			wp_styles()->queue,
			wp_styles()->registered,
			array(
				'https://cdn.example.com/assets/source-one.css',
				'https://cdn.example.com/assets/source-two.css',
			)
		);
		$script_matches = $this->findEnqueuedHandlesForSources(
			wp_scripts()->queue,
			wp_scripts()->registered,
			array(
				'https://cdn.example.com/assets/source-one.js',
				'https://cdn.example.com/assets/source-two.js',
			)
		);

		$this->style_handles  = array_merge( $this->style_handles, array_keys( $style_matches ) );
		$this->script_handles = array_merge( $this->script_handles, array_keys( $script_matches ) );

		$this->assertCount( 2, $style_matches );
		$this->assertCount( 2, $script_matches );
		$this->assertCount( 2, array_unique( array_keys( $style_matches ) ) );
		$this->assertCount( 2, array_unique( array_keys( $script_matches ) ) );
		$this->assertContains( $base_style_handle, array_keys( $style_matches ) );
		$this->assertContains( $base_script_handle, array_keys( $script_matches ) );
		$this->assertSame(
			array(
				'https://cdn.example.com/assets/source-one.css',
				'https://cdn.example.com/assets/source-two.css',
			),
			array_values( $style_matches )
		);
		$this->assertSame(
			array(
				'https://cdn.example.com/assets/source-one.js',
				'https://cdn.example.com/assets/source-two.js',
			),
			array_values( $script_matches )
		);
	}

	private function createTemplateFormHandler(): TemplateFormHandler {
		$page = $this->getMockBuilder( AdminPage::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_manage_plugin' ) )
			->getMock();
		$page->method( 'can_manage_plugin' )->willReturn( true );

		return new TemplateFormHandler( $page );
	}

	private function createCapturingTemplateFormHandler(): CapturingSaveTemplateFormHandler {
		$page = $this->getMockBuilder( AdminPage::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_manage_plugin' ) )
			->getMock();
		$page->method( 'can_manage_plugin' )->willReturn( true );

		return new CapturingSaveTemplateFormHandler( $page );
	}

	private function createPostWithContent( string $content ): WP_Post {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Template Asset Test ' . wp_generate_password( 6, false, false ),
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			self::fail( 'Could not create a post for template asset testing.' );
		}

		$this->post_ids[] = (int) $post_id;

		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			self::fail( 'Could not load the created post for template asset testing.' );
		}

		return $post;
	}

	private function enqueueAssetsForPost( WP_Post $post ): void {
		$GLOBALS['post'] = $post;
		Display::instance()->enqueue_assets();
	}

	private function countHandleOccurrences( array $queue, string $handle ): int {
		return count(
			array_filter(
				$queue,
				static function ( string $queued_handle ) use ( $handle ): bool {
					return $queued_handle === $handle;
				}
			)
		);
	}

	/**
	 * Return the queued handles whose registered sources match the expected URLs.
	 *
	 * @param string[] $queue      Enqueued handle queue.
	 * @param array    $registered Registered dependency map.
	 * @param string[] $sources    Expected source URLs.
	 * @return array<string,string>
	 */
	private function findEnqueuedHandlesForSources( array $queue, array $registered, array $sources ): array {
		$matches = array();
		$wanted  = array_values( $sources );

		foreach ( $queue as $handle ) {
			if ( ! isset( $registered[ $handle ] ) ) {
				continue;
			}

			$src = (string) ( $registered[ $handle ]->src ?? '' );
			if ( ! in_array( $src, $wanted, true ) ) {
				continue;
			}

			$matches[ (string) $handle ] = $src;
		}

		asort( $matches );

		return $matches;
	}
}

class CapturingSaveTemplateFormHandler extends TemplateFormHandler {

	public ?string $redirect_url = null;

	protected function redirect( string $url ): void {
		$this->redirect_url = $url;
	}
}
