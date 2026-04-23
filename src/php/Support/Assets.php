<?php

namespace DataImporter\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	/**
	 * Cached Vite manifest contents.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $manifest = null;

	/**
	 * Return the parsed Vite manifest.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_manifest() {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}

		$manifest_path = DATAIMPORTER_PLUGIN_DIR . 'dist/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			self::$manifest = array();
			return self::$manifest;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		self::$manifest = is_array( $manifest ) ? $manifest : array();

		return self::$manifest;
	}

	/**
	 * Return one manifest entry by source path.
	 *
	 * @param string $entry Source entry path, e.g. src/js/admin.js.
	 * @return array<string,mixed>|null
	 */
	private static function get_manifest_entry( $entry ) {
		$manifest = self::get_manifest();

		if ( isset( $manifest[ $entry ] ) && is_array( $manifest[ $entry ] ) ) {
			return $manifest[ $entry ];
		}

		return null;
	}

	/**
	 * Convert a relative plugin path into a public URL.
	 *
	 * @param string $relative_path Relative path inside plugin dir.
	 * @return string
	 */
	private static function to_url( $relative_path ) {
		return DATAIMPORTER_PLUGIN_URL . ltrim( (string) $relative_path, '/' );
	}

	/**
	 * Convert a relative plugin path into an absolute path.
	 *
	 * @param string $relative_path Relative path inside plugin dir.
	 * @return string
	 */
	private static function to_path( $relative_path ) {
		return DATAIMPORTER_PLUGIN_DIR . ltrim( (string) $relative_path, '/' );
	}

	/**
	 * Convert a manifest asset path into a public URL relative to dist/.
	 *
	 * @param string $relative_path Relative path inside dist/.
	 * @return string
	 */
	private static function manifest_to_url( $relative_path ) {
		return self::to_url( 'dist/' . ltrim( (string) $relative_path, '/' ) );
	}

	/**
	 * Convert a manifest asset path into an absolute path relative to dist/.
	 *
	 * @param string $relative_path Relative path inside dist/.
	 * @return string
	 */
	private static function manifest_to_path( $relative_path ) {
		return self::to_path( 'dist/' . ltrim( (string) $relative_path, '/' ) );
	}

	/**
	 * Return the best script URL for an entry, with optional legacy fallback.
	 *
	 * @param string $entry           Source entry path, e.g. src/js/admin.js.
	 * @param string $legacy_relative Optional fallback relative path.
	 * @return string
	 */
	public static function get_script_url( $entry, $legacy_relative = '' ) {
		$manifest_entry = self::get_manifest_entry( $entry );

		if ( isset( $manifest_entry['file'] ) ) {
			return self::manifest_to_url( (string) $manifest_entry['file'] );
		}

		if ( '' !== $legacy_relative && file_exists( self::to_path( $legacy_relative ) ) ) {
			return self::to_url( $legacy_relative );
		}

		return '';
	}

	/**
	 * Return the best stylesheet URL for an entry, with optional legacy fallback.
	 *
	 * @param string $entry           Source entry path, e.g. src/js/admin.js.
	 * @param string $legacy_relative Optional fallback relative path.
	 * @return string
	 */
	public static function get_style_url( $entry, $legacy_relative = '' ) {
		$manifest_entry = self::get_manifest_entry( $entry );

		if ( ! empty( $manifest_entry['css'] ) && is_array( $manifest_entry['css'] ) ) {
			return self::manifest_to_url( (string) $manifest_entry['css'][0] );
		}

		if ( '' !== $legacy_relative && file_exists( self::to_path( $legacy_relative ) ) ) {
			return self::to_url( $legacy_relative );
		}

		return '';
	}

	/**
	 * Return a cache-busting version for the resolved script file.
	 *
	 * @param string $entry           Source entry path, e.g. src/js/admin.js.
	 * @param string $legacy_relative Optional fallback relative path.
	 * @return string
	 */
	public static function get_script_version( $entry, $legacy_relative = '' ) {
		$manifest_entry = self::get_manifest_entry( $entry );

		if ( isset( $manifest_entry['file'] ) ) {
			$path = self::manifest_to_path( (string) $manifest_entry['file'] );

			if ( file_exists( $path ) ) {
				return (string) filemtime( $path );
			}
		}

		if ( '' !== $legacy_relative ) {
			$path = self::to_path( $legacy_relative );

			if ( file_exists( $path ) ) {
				return (string) filemtime( $path );
			}
		}

		return DATAIMPORTER_VERSION;
	}

	/**
	 * Return a cache-busting version for the resolved stylesheet.
	 *
	 * @param string $entry           Source entry path, e.g. src/js/admin.js.
	 * @param string $legacy_relative Optional fallback relative path.
	 * @return string
	 */
	public static function get_style_version( $entry, $legacy_relative = '' ) {
		$manifest_entry = self::get_manifest_entry( $entry );

		if ( ! empty( $manifest_entry['css'] ) && is_array( $manifest_entry['css'] ) ) {
			$path = self::manifest_to_path( (string) $manifest_entry['css'][0] );

			if ( file_exists( $path ) ) {
				return (string) filemtime( $path );
			}
		}

		if ( '' !== $legacy_relative ) {
			$path = self::to_path( $legacy_relative );

			if ( file_exists( $path ) ) {
				return (string) filemtime( $path );
			}
		}

		return DATAIMPORTER_VERSION;
	}
}
