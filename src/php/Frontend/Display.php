<?php

namespace DataImporter\Frontend;

use DataImporter\Infrastructure\Database;
use DataImporter\Plugin;
use DataImporter\Support\Assets;
use WP_Error;
use WP_Post;
/**
 * Frontend display / shortcode for Data Importer.
 *
 * Templates are PHP files stored as text in the database.
 * Inside a template the following variables are available:
 *   $record  – full associative array for the current row
 *   $<key>   – each top-level key extracted as its own variable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Display {

	private static $instance = null;

	/**
	 * Inline template code queued for the current page.
	 *
	 * @var array<string,string>
	 */
	private static array $inline_style_codes = array();

	/**
	 * Inline template scripts queued for the current page.
	 *
	 * @var array<string,string>
	 */
	private static array $inline_script_codes = array();

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'print_inline_styles' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_inline_scripts' ), 99 );
	}

	/**
	 * Enqueue frontend CSS only when the shortcode is present on the current post.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		global $post;

		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$shortcode_sets = self::extract_shortcode_attribute_sets( (string) $post->post_content );

		if ( empty( $shortcode_sets ) ) {
			return;
		}

		wp_enqueue_style(
			'data-importer-frontend',
			Assets::get_style_url( 'src/js/frontend.js', 'assets/css/frontend.css' ),
			array(),
			Assets::get_style_version( 'src/js/frontend.js', 'assets/css/frontend.css' )
		);

		foreach ( $shortcode_sets as $shortcode_atts ) {
			self::enqueue_template_assets_for_shortcode_atts( $shortcode_atts );
		}
	}

	/**
	 * Return default shortcode attributes.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_shortcode_defaults() {
		return array(
			'source'      => '',
			'template'    => '',
			'limit'       => 0,
			'offset'      => 0,
			'order'       => 'ASC',
			'id'          => 0,
			'where'       => '',
			'where_key'   => '',
			'where_op'    => '',
			'where_value' => '',
		);
	}

	/**
	 * Render shortcode output.
	 *
	 * Usage:
	 *   [data_importer source="my-slug"]
	 *   [data_importer source="my-slug" template="compact"]
	 *   [data_importer source="my-slug" limit="10" order="DESC"]
	 *   [data_importer source="my-slug" id="5"]
	 *   [data_importer source="my-slug" where_key="status" where_op="eq" where_value="active"]
	 *   [data_importer source="my-slug" where_key="address.city" where_op="contains" where_value="Alingsas"]
	 *
	 * `source` may be omitted when exactly one data source exists.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts( self::get_shortcode_defaults(), $atts, 'data_importer' );

		$source = self::resolve_source( sanitize_text_field( (string) $atts['source'] ) );

		if ( ! $source ) {
			return '';
		}

		$template = self::resolve_template(
			(int) $source['id'],
			sanitize_text_field( (string) $atts['template'] )
		);

		self::enqueue_template_assets( $template );

		// Backward compatibility: if no template row exists, fall back to source-owned fields.
		if ( $template ) {
			$template_html = (string) $template['template_html'];
			$before        = (string) $template['wrapper_before'];
			$after         = (string) $template['wrapper_after'];
		} else {
			$template_html = (string) $source['template_html'];
			$before        = (string) $source['wrapper_before'];
			$after         = (string) $source['wrapper_after'];
		}

		$rows = Database::get_records(
			array(
				'source_id' => (int) $source['id'],
				'limit'     => absint( $atts['limit'] ),
				'offset'    => absint( $atts['offset'] ),
				'order'     => sanitize_text_field( (string) $atts['order'] ),
				'id'        => absint( $atts['id'] ),
			)
		);

		if ( empty( $rows ) ) {
			return '';
		}

		$records = self::decode_records_from_rows( $rows );
		$filter  = self::build_filter_from_atts( $atts );

		if ( ! empty( $filter ) ) {
			$records = self::apply_record_filter( $records, $filter );
		}

		if ( empty( $records ) ) {
			return '';
		}

		ob_start();

		self::eval_php(
			$before,
			array(),
			array(
				'context'     => 'wrapper_before',
				'source_id'   => (int) $source['id'],
				'template_id' => $template ? (int) $template['id'] : 0,
			)
		);

		$record_count = count( $records );
		$record_index = 0;

		foreach ( $records as $record ) {
			self::eval_php(
				$template_html,
				$record,
				array(
					'context'         => 'template',
					'source_id'       => (int) $source['id'],
					'template_id'     => $template ? (int) $template['id'] : 0,
					'record_index'    => $record_index,
					'record_position' => $record_index + 1,
					'record_count'    => $record_count,
					'is_first'        => 0 === $record_index,
					'is_last'         => ( $record_count - 1 ) === $record_index,
				)
			);

			$record_index++;
		}

		self::eval_php(
			$after,
			array(),
			array(
				'context'     => 'wrapper_after',
				'source_id'   => (int) $source['id'],
				'template_id' => $template ? (int) $template['id'] : 0,
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * Parse shortcode attribute sets from post content.
	 *
	 * @param string $content Post content.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_shortcode_attribute_sets( $content ) {
		$pattern = get_shortcode_regex( array( 'data_importer' ) );
		$matches = array();
		$sets    = array();

		if ( '' === $content || ! preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return $sets;
		}

		foreach ( $matches as $match ) {
			if ( empty( $match[2] ) || 'data_importer' !== $match[2] ) {
				continue;
			}

			$atts = shortcode_parse_atts( $match[3] ?? '' );
			$sets[] = shortcode_atts(
				self::get_shortcode_defaults(),
				is_array( $atts ) ? $atts : array(),
				'data_importer'
			);
		}

		return $sets;
	}

	/**
	 * Resolve a shortcode payload and enqueue its template assets.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return void
	 */
	private static function enqueue_template_assets_for_shortcode_atts( $atts ) {
		$source = self::resolve_source( sanitize_text_field( (string) ( $atts['source'] ?? '' ) ) );
		if ( ! $source ) {
			return;
		}

		$template = self::resolve_template(
			(int) $source['id'],
			sanitize_text_field( (string) ( $atts['template'] ?? '' ) )
		);

		self::enqueue_template_assets( $template );
	}

	/**
	 * Enqueue styles and scripts configured on a template.
	 *
	 * @param array<string,mixed>|null $template Template row.
	 * @return void
	 */
	private static function enqueue_template_assets( $template ) {
		if ( empty( $template ) || ! is_array( $template ) ) {
			return;
		}

		$template_id = isset( $template['id'] ) ? (int) $template['id'] : 0;
		$styles      = self::decode_template_asset_rows( $template['styles_json'] ?? array(), 'style' );
		$scripts     = self::decode_template_asset_rows( $template['scripts_json'] ?? array(), 'script' );

		foreach ( $styles as $index => $style ) {
			$src = (string) ( $style['src'] ?? '' );
			if ( '' === $src ) {
				continue;
			}

			$requested = sanitize_key( (string) ( $style['handle'] ?? '' ) );
			$handle    = self::resolve_enqueue_handle(
				'' !== $requested ? $requested : self::build_asset_handle_from_source( $src, 'style' ),
				$src,
				'style',
				$template_id,
				$index
			);

			wp_enqueue_style( $handle, $src, array(), null );
		}

		foreach ( $scripts as $index => $script ) {
			$src = (string) ( $script['src'] ?? '' );
			if ( '' === $src ) {
				continue;
			}

			$requested = sanitize_key( (string) ( $script['handle'] ?? '' ) );
			$handle    = self::resolve_enqueue_handle(
				'' !== $requested ? $requested : self::build_asset_handle_from_source( $src, 'script' ),
				$src,
				'script',
				$template_id,
				$index
			);

			wp_enqueue_script( $handle, $src, array(), null, true );
		}

		self::enqueue_template_inline_code( $template );
	}

	/**
	 * Queue trusted inline CSS/JS configured on a template.
	 *
	 * @param array<string,mixed> $template Template row.
	 * @return void
	 */
	private static function enqueue_template_inline_code( array $template ) {
		$template_id = isset( $template['id'] ) ? (int) $template['id'] : 0;
		$key         = $template_id > 0 ? 'template-' . $template_id : 'template-' . md5( wp_json_encode( $template ) ?: serialize( $template ) );
		$style_code  = (string) ( $template['style_code'] ?? '' );
		$script_code = (string) ( $template['script_code'] ?? '' );

		if ( '' !== trim( $style_code ) && ! isset( self::$inline_style_codes[ $key ] ) ) {
			self::$inline_style_codes[ $key ] = $style_code;
		}

		if ( '' !== trim( $script_code ) && ! isset( self::$inline_script_codes[ $key ] ) ) {
			self::$inline_script_codes[ $key ] = $script_code;
		}
	}

	/**
	 * Print queued template CSS at the end of the document head.
	 *
	 * @return void
	 */
	public function print_inline_styles() {
		if ( empty( self::$inline_style_codes ) ) {
			return;
		}

		$code = implode( "\n\n", array_map( array( self::class, 'escape_inline_style_code' ), self::$inline_style_codes ) );
		self::$inline_style_codes = array();

		echo "\n<style id=\"data-importer-template-inline-styles\">\n" . $code . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Print queued template JS at the end of the document body.
	 *
	 * @return void
	 */
	public function print_inline_scripts() {
		if ( empty( self::$inline_script_codes ) ) {
			return;
		}

		$code = implode( "\n\n", self::$inline_script_codes );
		self::$inline_script_codes = array();

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag(
				$code,
				array(
					'id' => 'data-importer-template-inline-scripts',
				)
			);
			return;
		}

		echo "\n<script id=\"data-importer-template-inline-scripts\">\n" . str_ireplace( '</script', '<\/script', $code ) . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Prevent CSS text from closing the wrapping style element.
	 *
	 * @param string $code Raw CSS.
	 * @return string
	 */
	private static function escape_inline_style_code( $code ) {
		return str_ireplace( '</style', '<\/style', (string) $code );
	}

	/**
	 * Decode template asset rows stored as JSON.
	 *
	 * @param mixed  $value Asset payload.
	 * @param string $type  Asset type: style|script.
	 * @return array<int,array<string,string>>
	 */
	private static function decode_template_asset_rows( $value, $type ) {
		$type = 'script' === $type ? 'script' : 'style';
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$decoded = array();
		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) {
				$row = array( 'src' => $row );
			}

			if ( ! is_array( $row ) ) {
				continue;
			}

			$src = esc_url_raw( trim( (string) ( $row['src'] ?? '' ) ) );
			if ( '' === $src ) {
				continue;
			}

			if ( in_array( $type, array( 'style', 'script' ), true ) ) {
				$decoded[] = array(
					'src'    => $src,
					'handle' => sanitize_key( (string) ( $row['handle'] ?? '' ) ),
				);
				continue;
			}

			$decoded[] = array( 'src' => $src );
		}

		return $decoded;
	}

	/**
	 * Generate an asset handle from the source basename.
	 *
	 * @param string $source   Asset source.
	 * @param string $fallback Fallback basename.
	 * @return string
	 */
	private static function build_asset_handle_from_source( $source, $fallback = 'asset' ) {
		$source   = trim( (string) $source );
		$fallback = sanitize_key( (string) $fallback );

		if ( '' === $fallback ) {
			$fallback = 'asset';
		}

		if ( '' === $source ) {
			return $fallback;
		}

		$path = (string) wp_parse_url( $source, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = $source;
		}

		$basename = wp_basename( $path );
		$basename = preg_replace( '/\.[^.]+$/', '', $basename );
		$handle   = sanitize_key( (string) $basename );

		return '' !== $handle ? $handle : $fallback;
	}

	/**
	 * Resolve a safe enqueue handle, avoiding collisions with different sources.
	 *
	 * @param string $handle      Requested handle.
	 * @param string $src         Asset source.
	 * @param string $kind        Asset kind: style|script.
	 * @param int    $template_id Template ID.
	 * @param int    $index       Asset index.
	 * @return string
	 */
	private static function resolve_enqueue_handle( $handle, $src, $kind, $template_id, $index ) {
		$handle = sanitize_key( (string) $handle );
		$kind   = 'script' === $kind ? 'script' : 'style';

		if ( '' === $handle ) {
			$handle = sanitize_key( 'data-importer-' . $kind . '-' . $template_id . '-' . $index );
		}

		$registry = 'script' === $kind ? wp_scripts() : wp_styles();
		if ( ! isset( $registry->registered[ $handle ] ) ) {
			return $handle;
		}

		$registered_src = (string) $registry->registered[ $handle ]->src;
		if ( self::normalize_asset_source_for_compare( $registered_src ) === self::normalize_asset_source_for_compare( $src ) ) {
			return $handle;
		}

		return sanitize_key( 'data-importer-' . $kind . '-' . $template_id . '-' . $index . '-' . $handle );
	}

	/**
	 * Normalize asset sources before comparing already-registered URLs.
	 *
	 * @param string $src Asset source.
	 * @return string
	 */
	private static function normalize_asset_source_for_compare( $src ) {
		return preg_replace( '#^https?:#', '', trim( (string) $src ) );
	}

	/**
	 * Decode DB rows into records array.
	 *
	 * @param array $rows Raw DB rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function decode_records_from_rows( $rows ) {
		$records = array();

		foreach ( (array) $rows as $row ) {
			$record = json_decode( (string) ( $row['record_data'] ?? '' ), true );
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Parse and normalize shortcode filter attributes.
	 *
	 * Supported operators:
	 * - eq / neq
	 * - contains / ncontains
	 * - gt / gte / lt / lte
	 * - in / nin  (comma-separated where_value list)
	 * - starts_with / ends_with
	 * - empty / not_empty
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array<string,string>
	 */
	private static function build_filter_from_atts( $atts ) {
		$key      = sanitize_text_field( (string) ( $atts['where_key'] ?? '' ) );
		$operator = sanitize_text_field( (string) ( $atts['where_op'] ?? '' ) );
		$value    = (string) ( $atts['where_value'] ?? '' );
		$where    = (string) ( $atts['where'] ?? '' );

		if ( '' === $key && '' !== trim( $where ) ) {
			$parts = explode( '|', $where, 3 );
			$key   = sanitize_text_field( (string) ( $parts[0] ?? '' ) );
			if ( isset( $parts[1] ) ) {
				$operator = sanitize_text_field( (string) $parts[1] );
			}
			if ( isset( $parts[2] ) ) {
				$value = (string) $parts[2];
			}
		}

		if ( '' === $key ) {
			return array();
		}

		if ( '' === $operator ) {
			$operator = 'eq';
		}

		return array(
			'key'      => $key,
			'operator' => self::normalize_filter_operator( $operator ),
			'value'    => $value,
		);
	}

	/**
	 * Filter records before template rendering.
	 *
	 * @param array $records Decoded records.
	 * @param array $filter  Normalized filter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function apply_record_filter( $records, $filter ) {
		$filtered = array();

		foreach ( (array) $records as $record ) {
			$match = self::record_matches_filter( (array) $record, $filter );
			$match = (bool) apply_filters( 'data_importer_record_matches_filter', $match, $record, $filter );
			if ( $match ) {
				$filtered[] = $record;
			}
		}

		return $filtered;
	}

	/**
	 * Check whether one record matches one filter rule.
	 *
	 * @param array $record Record values.
	 * @param array $filter Normalized filter.
	 * @return bool
	 */
	private static function record_matches_filter( $record, $filter ) {
		$key      = (string) ( $filter['key'] ?? '' );
		$operator = (string) ( $filter['operator'] ?? 'eq' );
		$expected = (string) ( $filter['value'] ?? '' );
		$actual   = self::get_nested_value( $record, $key );

		return self::compare_filter_values( $actual, $operator, $expected );
	}

	/**
	 * Normalize filter operator aliases.
	 *
	 * @param string $operator Raw operator.
	 * @return string
	 */
	private static function normalize_filter_operator( $operator ) {
		$operator = strtolower( trim( (string) $operator ) );

		$aliases = array(
			'='           => 'eq',
			'=='          => 'eq',
			'eq'          => 'eq',
			'!='          => 'neq',
			'<>'          => 'neq',
			'neq'         => 'neq',
			'contains'    => 'contains',
			'like'        => 'contains',
			'ncontains'   => 'ncontains',
			'nlike'       => 'ncontains',
			'>'           => 'gt',
			'gt'          => 'gt',
			'>='          => 'gte',
			'gte'         => 'gte',
			'<'           => 'lt',
			'lt'          => 'lt',
			'<='          => 'lte',
			'lte'         => 'lte',
			'in'          => 'in',
			'nin'         => 'nin',
			'starts_with' => 'starts_with',
			'startswith'  => 'starts_with',
			'ends_with'   => 'ends_with',
			'endswith'    => 'ends_with',
			'empty'       => 'empty',
			'not_empty'   => 'not_empty',
		);

		return $aliases[ $operator ] ?? 'eq';
	}

	/**
	 * Compare a record value against shortcode filter criteria.
	 *
	 * @param mixed  $actual   Record value.
	 * @param string $operator Normalized operator.
	 * @param string $expected Shortcode where_value.
	 * @return bool
	 */
	private static function compare_filter_values( $actual, $operator, $expected ) {
		if ( 'empty' === $operator ) {
			return self::value_is_empty( $actual );
		}

		if ( 'not_empty' === $operator ) {
			return ! self::value_is_empty( $actual );
		}

		$actual_scalar   = self::normalize_filter_value( $actual );
		$expected_scalar = self::normalize_filter_value( $expected );

		if ( in_array( $operator, array( 'gt', 'gte', 'lt', 'lte' ), true ) ) {
			if ( ! is_numeric( $actual_scalar ) || ! is_numeric( $expected_scalar ) ) {
				return false;
			}

			$actual_num   = (float) $actual_scalar;
			$expected_num = (float) $expected_scalar;

			if ( 'gt' === $operator ) {
				return $actual_num > $expected_num;
			}
			if ( 'gte' === $operator ) {
				return $actual_num >= $expected_num;
			}
			if ( 'lt' === $operator ) {
				return $actual_num < $expected_num;
			}

			return $actual_num <= $expected_num;
		}

		if ( 'contains' === $operator ) {
			return '' !== $expected_scalar && false !== stripos( $actual_scalar, $expected_scalar );
		}

		if ( 'ncontains' === $operator ) {
			return '' === $expected_scalar || false === stripos( $actual_scalar, $expected_scalar );
		}

		if ( 'starts_with' === $operator ) {
			return '' !== $expected_scalar && 0 === strpos( $actual_scalar, $expected_scalar );
		}

		if ( 'ends_with' === $operator ) {
			if ( '' === $expected_scalar ) {
				return false;
			}
			$length = strlen( $expected_scalar );
			return substr( $actual_scalar, -$length ) === $expected_scalar;
		}

		if ( in_array( $operator, array( 'in', 'nin' ), true ) ) {
			$set = array_filter(
				array_map( 'trim', explode( ',', $expected_scalar ) ),
				static function ( $v ) {
					return '' !== $v;
				}
			);
			$in_set = in_array( $actual_scalar, $set, true );
			return 'in' === $operator ? $in_set : ! $in_set;
		}

		if ( 'neq' === $operator ) {
			return $actual_scalar !== $expected_scalar;
		}

		return $actual_scalar === $expected_scalar;
	}

	/**
	 * Convert any value to scalar string for comparisons.
	 *
	 * @param mixed $value Source value.
	 * @return string
	 */
	private static function normalize_filter_value( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		$encoded = wp_json_encode( $value );
		return false === $encoded ? '' : (string) $encoded;
	}

	/**
	 * Decide if value should be considered empty for shortcode filters.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	private static function value_is_empty( $value ) {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return false;
	}

	/**
	 * Execute a PHP template string in an isolated scope.
	 *
	 * The template may contain any mix of HTML and PHP.
	 * Variables exposed to row templates:
	 *   • $record           – the full associative array
	 *   • $vars             – alias of the full associative array
	 *   • $record_index     – zero-based row index in the rendered result set
	 *   • $record_position  – one-based row position in the rendered result set
	 *   • $record_count     – total rows in the rendered result set
	 *   • $is_first         – whether this is the first rendered row
	 *   • $is_last          – whether this is the last rendered row
	 *
	 * Parse/runtime errors are caught:
	 *   • On WP_DEBUG sites an inline error notice is printed.
	 *   • On production sites the template block is silently skipped.
	 *
	 * @param string $code    PHP/HTML template string.
	 * @param array  $record  Decoded record data.
	 * @param array  $context Runtime context.
	 * @return void  Output is written directly to the active output buffer.
	 */
	public static function eval_php( $code, $record, $context = array() ) {
		if ( '' === trim( $code ) ) {
			return;
		}

		if ( Plugin::is_safe_mode_active() ) {
			return;
		}

		$enabled = (bool) apply_filters( 'data_importer_enable_php_templates', true, $context );
		if ( ! $enabled ) {
			return;
		}

		$validation = self::validate_template_code( (string) $code, $context );
		if ( is_wp_error( $validation ) ) {
			self::append_template_security_log( $validation, $context );
			do_action( 'data_importer_template_error', $validation, $context );
			return;
		}

		// Make the full record available as $vars so templates can reference
		// $vars['name'] without polluting the eval() scope with arbitrary keys.
		$vars = is_array( $record ) ? $record : array();

		$record_index    = isset( $context['record_index'] ) ? (int) $context['record_index'] : 0;
		$record_position = isset( $context['record_position'] ) ? (int) $context['record_position'] : 0;
		$record_count    = isset( $context['record_count'] ) ? (int) $context['record_count'] : 0;
		$is_first        = ! empty( $context['is_first'] );
		$is_last         = ! empty( $context['is_last'] );

		/**
		 * Re-enable the legacy extract() behaviour so that top-level record keys are
		 * also available as individual variables ($name, $age, …) inside templates.
		 *
		 * SECURITY WARNING: extract() on untrusted data lets record keys shadow or
		 * introduce variables in the eval scope (e.g. a key named "code" or "context"
		 * can cause subtle bugs or, in edge cases, unexpected behaviour). Only enable
		 * this if you control the shape of all imported records and understand the risk.
		 *
		 * @param bool   $extract Whether to run extract() on the record. Default false.
		 * @param array  $vars    The record array that would be extracted.
		 * @param array  $context Runtime context.
		 */
		if ( (bool) apply_filters( 'data_importer_template_extract_vars', false, $vars, $context ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $vars, EXTR_SKIP );
		}

		try {
			// Prefix with the PHP close-tag sequence so the evaluated fragment starts in
			// "HTML" mode; the stored template may then lead with plain markup or a PHP open tag.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( '?>' . $code );
		} catch ( \Throwable $e ) {
			self::append_template_security_log( $e, $context );
			do_action( 'data_importer_template_error', $e, $context );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$show_details = (bool) apply_filters( 'data_importer_template_error_details', false, $context );
				echo '<p style="background:#fce8e8;border:1px solid #f00;padding:8px;margin:8px 0;font-family:monospace;">';
				echo '<strong>Data Importer - template error:</strong> ';
				echo $show_details ? esc_html( $e->getMessage() ) : esc_html__( 'An error occurred while rendering the template.', 'data-importer' );
				echo '</p>';
			}
		}
	}

	/**
	 * Run validation and controlled dry-run execution for full template payload.
	 *
	 * @param string $before   Wrapper before code.
	 * @param string $template Main row template code.
	 * @param string $after    Wrapper after code.
	 * @param array  $record   Optional sample record.
	 * @param array  $context  Base context.
	 * @return true|WP_Error
	 */
	public static function dry_run_template( $before, $template, $after, $record = array(), $context = array() ) {
		$before_context = array_merge( (array) $context, array( 'context' => 'save_wrapper_before' ) );
		$main_context   = array_merge( (array) $context, array( 'context' => 'save_template' ) );
		$after_context  = array_merge( (array) $context, array( 'context' => 'save_wrapper_after' ) );

		$before_validation = self::validate_template_code( (string) $before, $before_context );
		if ( is_wp_error( $before_validation ) ) {
			self::append_template_security_log( $before_validation, $before_context );
			return $before_validation;
		}

		$template_validation = self::validate_template_code( (string) $template, $main_context );
		if ( is_wp_error( $template_validation ) ) {
			self::append_template_security_log( $template_validation, $main_context );
			return $template_validation;
		}

		$after_validation = self::validate_template_code( (string) $after, $after_context );
		if ( is_wp_error( $after_validation ) ) {
			self::append_template_security_log( $after_validation, $after_context );
			return $after_validation;
		}

		$record = is_array( $record ) ? $record : array();

		ob_start();
		$before_exec = self::evaluate_fragment( (string) $before, array(), $before_context );
		if ( is_wp_error( $before_exec ) ) {
			ob_end_clean();
			return $before_exec;
		}

		$template_exec = self::evaluate_fragment( (string) $template, $record, $main_context );
		if ( is_wp_error( $template_exec ) ) {
			ob_end_clean();
			return $template_exec;
		}

		$after_exec = self::evaluate_fragment( (string) $after, array(), $after_context );
		ob_end_clean();

		if ( is_wp_error( $after_exec ) ) {
			return $after_exec;
		}

		return true;
	}

	/**
	 * Evaluate one template fragment and return runtime errors as WP_Error.
	 *
	 * @param string $code    Template code.
	 * @param array  $record  Record context.
	 * @param array  $context Runtime context.
	 * @return true|WP_Error
	 */
	private static function evaluate_fragment( $code, $record, $context = array() ) {
		if ( '' === trim( (string) $code ) ) {
			return true;
		}

		$vars = is_array( $record ) ? $record : array();

		/** This filter is documented in src/php/Frontend/Display.php */
		if ( (bool) apply_filters( 'data_importer_template_extract_vars', false, $vars, $context ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $vars, EXTR_SKIP );
		}

		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( '?>' . $code );
		} catch ( \Throwable $e ) {
			self::append_template_security_log( $e, $context );
			do_action( 'data_importer_template_error', $e, $context );
			$message = sprintf(
				/* translators: %s: runtime error message */
				__( 'Template error during test run: %s', 'data-importer' ),
				$e->getMessage()
			);
			return new WP_Error( 'template_runtime', $message );
		}

		return true;
	}

	/**
	 * Validate template code before runtime execution.
	 *
	 * @param string $code    Template code.
	 * @param array  $context Runtime context.
	 * @return true|WP_Error
	 */
	public static function validate_template_code( $code, $context = array() ) {
		$max_bytes = (int) apply_filters( 'data_importer_max_template_bytes', 524288, $context ); // 512 KB.
		if ( $max_bytes < 1024 ) {
			$max_bytes = 1024;
		}

		if ( strlen( (string) $code ) > $max_bytes ) {
			return new WP_Error( 'template_too_large', __( 'The template is too large.', 'data-importer' ) );
		}

		if ( false !== strpos( (string) $code, "\0" ) ) {
			return new WP_Error( 'template_invalid', __( 'The template contains invalid characters.', 'data-importer' ) );
		}

		/**
		 * Default blocklist of dangerous PHP functions.
		 *
		 * Extend or replace this list via the filter. To allow a function that is
		 * blocked by default, return a modified array with that entry removed.
		 *
		 * @param string[] $blocked_functions Array of blocked function names.
		 * @param string   $code              Template code being validated.
		 * @param array    $context           Runtime context.
		 */
		$blocked_functions = apply_filters(
			'data_importer_blocked_template_functions',
			array(
				// Shell execution.
				'exec',
				'system',
				'passthru',
				'shell_exec',
				'proc_open',
				'proc_close',
				'proc_get_status',
				'proc_nice',
				'proc_terminate',
				'popen',
				'pcntl_exec',
				// Code execution.
				'eval',
				'assert',
				'preg_replace',  // /e modifier is gone in PHP 7+, but flag defensively.
				'create_function',
				// Indirect execution via callables — any of these can run arbitrary
				// code if the callable argument is attacker-influenced.
				'call_user_func',
				'call_user_func_array',
				'array_map',
				'array_filter',
				'array_walk',
				'array_walk_recursive',
				'array_reduce',
				'usort',
				'uasort',
				'uksort',
				'register_shutdown_function',
				'register_tick_function',
				'spl_autoload_register',
				'set_error_handler',
				'set_exception_handler',
				// Filesystem writes / destructive ops.
				'unlink',
				'rmdir',
				'file_put_contents',
				'fputs',
				'fwrite',
				// Extension loading.
				'dl',
			),
			(string) $code,
			$context
		);

		if ( ! empty( $blocked_functions ) ) {
			// Build a regex that matches any blocked function call: name followed by optional
			// whitespace then an opening parenthesis, using word-boundary anchors to avoid
			// false positives on names like "my_exec" or "sys_exec".
			$pattern = '/\b(' . implode( '|', array_map( 'preg_quote', (array) $blocked_functions, array_fill( 0, count( (array) $blocked_functions ), '/' ) ) ) . ')\s*\(/i';
			if ( preg_match( $pattern, (string) $code, $matches ) ) {
				return new WP_Error(
					'template_blocked_function',
					sprintf(
						/* translators: %s: blocked function name */
						__( 'The template contains a blocked function: %s.', 'data-importer' ),
						esc_html( $matches[1] )
					)
				);
			}
		}

		/**
		 * Default blocklist of dangerous PHP language constructs.
		 *
		 * These are not functions (regex above would miss them) — they include the
		 * backtick shell-exec operator and the include/require family of file-inclusion
		 * constructs, which can execute arbitrary code from an attacker-controlled path.
		 *
		 * @param string[] $blocked_constructs Array of blocked construct tokens.
		 * @param string   $code               Template code being validated.
		 * @param array    $context            Runtime context.
		 */
		$blocked_constructs = apply_filters(
			'data_importer_blocked_template_constructs',
			array(
				'backticks',
				'include',
				'include_once',
				'require',
				'require_once',
			),
			(string) $code,
			$context
		);

		$blocked_constructs = array_map( 'strtolower', (array) $blocked_constructs );

		// Backtick operator — unlikely to be legitimate inside a template, and the
		// function regex above does not catch it since it is not a function call.
		if ( in_array( 'backticks', $blocked_constructs, true ) && false !== strpos( (string) $code, '`' ) ) {
			return new WP_Error(
				'template_blocked_construct',
				__( 'The template contains a blocked construct: backtick shell-exec operator.', 'data-importer' )
			);
		}

		// include / require family — language constructs so the no-parenthesis form
		// (`include 'file';`) would not be caught by the function regex above.
		$inclusion_keywords = array_intersect(
			array( 'include', 'include_once', 'require', 'require_once' ),
			$blocked_constructs
		);
		if ( ! empty( $inclusion_keywords ) ) {
			$inc_pattern = '/\b(' . implode( '|', array_map( 'preg_quote', $inclusion_keywords, array_fill( 0, count( $inclusion_keywords ), '/' ) ) ) . ')\b/i';
			if ( preg_match( $inc_pattern, (string) $code, $inc_matches ) ) {
				return new WP_Error(
					'template_blocked_construct',
					sprintf(
						/* translators: %s: blocked construct name */
						__( 'The template contains a blocked construct: %s.', 'data-importer' ),
						esc_html( strtolower( $inc_matches[1] ) )
					)
				);
			}
		}

		/**
		 * Allow custom validation/policy hooks by site owners.
		 *
		 * Return true to accept, false/WP_Error to reject.
		 */
		$result = apply_filters( 'data_importer_validate_template_code', true, (string) $code, $context );
		if ( true === $result ) {
			return true;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_Error( 'template_rejected', __( 'The template is not allowed by the security policy.', 'data-importer' ) );
	}

	/**
	 * Persist a short template runtime/validation error log for admins.
	 *
	 * @param WP_Error|\Throwable $error   Error object.
	 * @param array               $context Runtime context.
	 * @return void
	 */
	private static function append_template_security_log( $error, $context = array() ) {
		$source_id = isset( $context['source_id'] ) ? absint( $context['source_id'] ) : 0;
		if ( $source_id <= 0 ) {
			return;
		}

		$option = 'data_importer_template_error_log_' . $source_id;
		$log    = get_option( $option, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$error_code = 'template_error';
		$message    = __( 'Template rendering failed.', 'data-importer' );
		if ( is_wp_error( $error ) ) {
			$error_code = (string) $error->get_error_code();
			$message    = (string) $error->get_error_message();
		} elseif ( $error instanceof \Throwable ) {
			$error_code = 'template_runtime';
			$message    = (string) $error->getMessage();
		}

		array_unshift(
			$log,
			array(
				'time'      => current_time( 'mysql' ),
				'event'     => sanitize_key( $error_code ),
				'context'   => sanitize_key( (string) ( $context['context'] ?? 'template' ) ),
				'message'   => sanitize_text_field( $message ),
				'template'  => isset( $context['template_id'] ) ? absint( $context['template_id'] ) : 0,
				'source_id' => $source_id,
				'resolved'  => 0,
			)
		);

		update_option( $option, array_slice( $log, 0, 50 ) );
	}

	/**
	 * Find the source to use for a shortcode call.
	 *
	 * @param string $slug Source slug (may be empty).
	 * @return array<string,string>|null
	 */
	private static function resolve_source( $slug ) {
		if ( '' !== $slug ) {
			return Database::get_source_by_slug( $slug );
		}

		$sources = Database::get_all_sources();

		if ( 1 === count( $sources ) ) {
			return $sources[0];
		}

		return null;
	}

	/**
	 * Resolve template for a source.
	 *
	 * Shortcode accepts:
	 * - template="slug"
	 * - template="123" (template id)
	 *
	 * @param int    $source_id Source ID.
	 * @param string $template  Template selector.
	 * @return array<string,string>|null
	 */
	private static function resolve_template( $source_id, $template ) {
		if ( '' !== $template ) {
			if ( ctype_digit( $template ) ) {
				$tpl = Database::get_template( (int) $template );
				if ( $tpl && (int) $tpl['source_id'] === (int) $source_id ) {
					return $tpl;
				}
			}

			$tpl = Database::get_template_by_source_slug( (int) $source_id, $template );
			if ( $tpl ) {
				return $tpl;
			}
		}

		return Database::get_default_template_for_source( (int) $source_id );
	}

	/**
	 * Resolve a value from a nested array using dot-notation path.
	 *
	 * Kept as a utility; templates can call it directly if needed.
	 *
	 * @param array  $record Data record.
	 * @param string $path   Dot-separated key path (e.g. "address.city").
	 * @return mixed|null
	 */
	public static function get_nested_value( $record, $path ) {
		if ( '' === $path ) {
			return null;
		}

		$parts   = explode( '.', $path );
		$current = $record;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return null;
			}
			$current = $current[ $part ];
		}

		return $current;
	}
}
