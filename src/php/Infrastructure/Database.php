<?php

namespace DataImporter\Infrastructure;

use DataImporter\Api\RestController;
use DataImporter\Support\IpRules;
use WP_Error;
/**
 * Database layer for Data Importer.
 *
 * Tables:
 *   {prefix}_data_importer_sources  – one row per configured data source
 *   {prefix}_data_importer_records  – imported records, keyed to a source
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	/**
	 * Table name: sources.
	 *
	 * @return string
	 */
	public static function get_sources_table() {
		global $wpdb;
		return $wpdb->prefix . 'data_importer_sources';
	}

	/**
	 * Table name: source API keys.
	 *
	 * @return string
	 */
	public static function get_source_keys_table() {
		global $wpdb;
		return $wpdb->prefix . 'data_importer_source_keys';
	}

	/**
	 * Table name: records.
	 *
	 * @return string
	 */
	public static function get_records_table() {
		global $wpdb;
		return $wpdb->prefix . 'data_importer_records';
	}

	/**
	 * Table name: templates.
	 *
	 * @return string
	 */
	public static function get_templates_table() {
		global $wpdb;
		return $wpdb->prefix . 'data_importer_templates';
	}

	// -------------------------------------------------------------------------
	// Schema management
	// -------------------------------------------------------------------------

	/**
	 * Create / upgrade all database tables.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sources = self::get_sources_table();
		$sql_sources = "CREATE TABLE {$sources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			api_key varchar(64) NOT NULL DEFAULT '',
			allowed_ips text NOT NULL,
			template_html longtext NOT NULL,
			wrapper_before longtext NOT NULL,
			wrapper_after longtext NOT NULL,
			import_mode varchar(10) NOT NULL DEFAULT 'replace',
			update_key varchar(500) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$keys = self::get_source_keys_table();
		$sql_keys = "CREATE TABLE {$keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(200) NOT NULL DEFAULT '',
			key_hash varchar(255) NOT NULL DEFAULT '',
			key_prefix varchar(16) NOT NULL DEFAULT '',
			key_last4 varchar(12) NOT NULL DEFAULT '',
			allowed_ips text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$records = self::get_records_table();
		$sql_records = "CREATE TABLE {$records} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			record_data longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$templates = self::get_templates_table();
		$sql_templates = "CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(200) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			template_html longtext NOT NULL,
			wrapper_before longtext NOT NULL,
			wrapper_after longtext NOT NULL,
			styles_json longtext NOT NULL,
			style_code longtext NOT NULL,
			scripts_json longtext NOT NULL,
			script_code longtext NOT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			UNIQUE KEY source_slug (source_id, slug)
		) {$charset_collate};";

		dbDelta( $sql_sources );
		dbDelta( $sql_keys );
		dbDelta( $sql_records );
		dbDelta( $sql_templates );

		update_option( 'data_importer_db_version', DATAIMPORTER_DB_VERSION );
	}

	/**
	 * Let dbDelta bring tables up to the current schema version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = (string) get_option( 'data_importer_db_version', '' );

		if ( version_compare( $current, DATAIMPORTER_DB_VERSION, '<' ) ) {
			self::create_table();
		}
	}

	/**
	 * Drop both tables (called on uninstall).
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$records = self::get_records_table();
		$sources = self::get_sources_table();
		$keys    = self::get_source_keys_table();
		$templates = self::get_templates_table();

		$wpdb->query( "DROP TABLE IF EXISTS {$records}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$keys}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$templates}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$sources}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// -------------------------------------------------------------------------
	// Source CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return all data sources ordered by creation date.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_all_sources() {
		global $wpdb;

		$table   = self::get_sources_table();
		$results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Return aggregated record metrics per source (count + latest import timestamp).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_source_overview_metrics() {
		global $wpdb;

		$sources_table = self::get_sources_table();
		$records_table = self::get_records_table();
		$sql           = "
			SELECT s.id AS source_id, COUNT(r.id) AS record_count, MAX(r.created_at) AS latest_record_at
			FROM {$sources_table} s
			LEFT JOIN {$records_table} r ON r.source_id = s.id
			GROUP BY s.id
		";
		$rows          = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$metrics = array();
		foreach ( $rows as $row ) {
			$source_id = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
			if ( $source_id <= 0 ) {
				continue;
			}

			$metrics[ $source_id ] = array(
				'record_count'      => isset( $row['record_count'] ) ? (int) $row['record_count'] : 0,
				'latest_record_at'  => isset( $row['latest_record_at'] ) ? (string) $row['latest_record_at'] : '',
			);
		}

		return $metrics;
	}

	/**
	 * Return a single source by ID.
	 *
	 * @param int $id Source ID.
	 * @return array<string, string>|null
	 */
	public static function get_source( $id ) {
		global $wpdb;

		$table = self::get_sources_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return a single source by slug.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, string>|null
	 */
	public static function get_source_by_slug( $slug ) {
		global $wpdb;

		$table = self::get_sources_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return all API keys for one source.
	 *
	 * @param int $source_id Source ID.
	 * @return array<int,array<string,string>>
	 */
	public static function get_source_api_keys( $source_id ) {
		global $wpdb;

		$table = self::get_source_keys_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d ORDER BY created_at ASC, id ASC", (int) $source_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return one API key row by ID.
	 *
	 * @param int $key_id Key ID.
	 * @return array<string,string>|null
	 */
	public static function get_source_api_key( $key_id ) {
		global $wpdb;

		$table = self::get_source_keys_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $key_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create a new API key for one source and return the plaintext once.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $data      Optional fields: name, allowed_ips, secret.
	 * @return array|WP_Error
	 */
	public static function create_source_api_key( $source_id, $data = array() ) {
		global $wpdb;

		$source_id = (int) $source_id;
		if ( $source_id <= 0 || ! self::get_source( $source_id ) ) {
			return new WP_Error( 'invalid_source', __( 'Invalid data source.', 'data-importer' ) );
		}

		$secret  = isset( $data['secret'] ) ? trim( (string) $data['secret'] ) : self::generate_source_api_key_secret();
		$name    = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : self::default_source_api_key_name( $source_id );
		$rules   = IpRules::sanitize_rule_list( (string) ( $data['allowed_ips'] ?? '' ) );
		$payload = self::build_source_api_key_storage_payload( $secret, $name, $rules );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$table  = self::get_source_keys_table();
		$result = $wpdb->insert(
			$table,
			array(
				'source_id'   => $source_id,
				'name'        => $payload['name'],
				'key_hash'    => $payload['key_hash'],
				'key_prefix'  => $payload['key_prefix'],
				'key_last4'   => $payload['key_last4'],
				'allowed_ips' => $payload['allowed_ips'],
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'insert_failed', __( 'Could not create the API key.', 'data-importer' ) );
		}

		$key_id = (int) $wpdb->insert_id;

		return array(
			'id'     => $key_id,
			'secret' => $secret,
			'key'    => self::get_source_api_key( $key_id ),
		);
	}

	/**
	 * Update a source API key's editable metadata.
	 *
	 * @param int   $key_id Key ID.
	 * @param array $data   Optional fields: name, allowed_ips.
	 * @return bool
	 */
	public static function update_source_api_key( $key_id, $data ) {
		global $wpdb;

		$existing = self::get_source_api_key( $key_id );
		if ( ! $existing ) {
			return false;
		}

		$update  = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				$name = (string) ( $existing['name'] ?? self::default_source_api_key_name( (int) $existing['source_id'] ) );
			}

			$update['name'] = $name;
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'allowed_ips', $data ) ) {
			$update['allowed_ips'] = IpRules::sanitize_rule_list( (string) $data['allowed_ips'] );
			$formats[]             = '%s';
		}

		$result = $wpdb->update( self::get_source_keys_table(), $update, array( 'id' => (int) $key_id ), $formats, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Regenerate one source API key and return the new plaintext once.
	 *
	 * @param int   $key_id Key ID.
	 * @param array $data   Optional fields: name, allowed_ips, secret.
	 * @return array|WP_Error
	 */
	public static function regenerate_source_api_key( $key_id, $data = array() ) {
		global $wpdb;

		$existing = self::get_source_api_key( $key_id );
		if ( ! $existing ) {
			return new WP_Error( 'invalid_key', __( 'API key not found.', 'data-importer' ) );
		}

		$secret = isset( $data['secret'] ) ? trim( (string) $data['secret'] ) : self::generate_source_api_key_secret();
		$name   = array_key_exists( 'name', $data )
			? sanitize_text_field( (string) $data['name'] )
			: (string) ( $existing['name'] ?? self::default_source_api_key_name( (int) $existing['source_id'] ) );
		$rules  = array_key_exists( 'allowed_ips', $data )
			? IpRules::sanitize_rule_list( (string) $data['allowed_ips'] )
			: (string) ( $existing['allowed_ips'] ?? '' );

		if ( '' === $name ) {
			$name = self::default_source_api_key_name( (int) $existing['source_id'] );
		}

		$payload = self::build_source_api_key_storage_payload( $secret, $name, $rules );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = $wpdb->update(
			self::get_source_keys_table(),
			array(
				'name'        => $payload['name'],
				'key_hash'    => $payload['key_hash'],
				'key_prefix'  => $payload['key_prefix'],
				'key_last4'   => $payload['key_last4'],
				'allowed_ips' => $payload['allowed_ips'],
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $key_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'update_failed', __( 'Could not regenerate the API key.', 'data-importer' ) );
		}

		return array(
			'id'     => (int) $key_id,
			'secret' => $secret,
			'key'    => self::get_source_api_key( (int) $key_id ),
		);
	}

	/**
	 * Delete one source API key.
	 *
	 * @param int $key_id Key ID.
	 * @return bool
	 */
	public static function delete_source_api_key( $key_id ) {
		global $wpdb;

		$deleted = $wpdb->delete( self::get_source_keys_table(), array( 'id' => (int) $key_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Find the matching API key row for a plaintext key.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $secret    Presented plaintext key.
	 * @return array<string,string>|null
	 */
	public static function verify_source_api_key( $source_id, $secret ) {
		$source_id = (int) $source_id;
		$secret    = (string) $secret;

		if ( $source_id <= 0 || '' === $secret ) {
			return null;
		}

		foreach ( self::get_source_api_keys( $source_id ) as $key ) {
			$hash = (string) ( $key['key_hash'] ?? '' );
			if ( '' === $hash || ! password_verify( $secret, $hash ) ) {
				continue;
			}

			if ( password_needs_rehash( $hash, self::get_source_api_key_hash_algorithm() ) ) {
				self::rehash_source_api_key( (int) $key['id'], $secret );
				$key = self::get_source_api_key( (int) $key['id'] ) ?: $key;
			}

			return $key;
		}

		return null;
	}

	/**
	 * Create a new data source.
	 *
	 * @param array $data Source fields (name, slug, template_html, wrapper_before, wrapper_after).
	 * @return int|WP_Error New source ID or error.
	 */
	public static function insert_source( $data ) {
		global $wpdb;

		$slug  = self::unique_slug( (string) ( $data['slug'] ?? '' ) );
		$table = self::get_sources_table();

		$default_template = implode(
			"\n",
			array(
				'<div class="data-importer-item">',
				"\t" . '<h3><?php echo esc_html( $title ?? $record[array_key_first( $record )] ?? \'\' ); ?></h3>',
				"\t" . '<?php foreach ( $record as $key => $val ) : ?>',
				"\t\t" . '<p><strong><?php echo esc_html( $key ); ?>:</strong> <?php echo esc_html( is_array( $val ) ? implode( \', \', $val ) : (string) $val ); ?></p>',
				"\t" . '<?php endforeach; ?>',
				'</div>',
			)
		);

		$result = $wpdb->insert(
			$table,
			array(
				'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'slug'           => $slug,
				'api_key'        => '',
				'allowed_ips'    => '',
				'template_html'  => (string) ( $data['template_html'] ?? $default_template ),
				'wrapper_before' => (string) ( $data['wrapper_before'] ?? '<div class="data-importer-list">' ),
				'wrapper_after'  => (string) ( $data['wrapper_after'] ?? '</div>' ),
				'import_mode'    => self::sanitize_import_mode( (string) ( $data['import_mode'] ?? 'replace' ) ),
				'update_key'     => sanitize_text_field( (string) ( $data['update_key'] ?? '' ) ),
				'updated_at'     => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'insert_failed', __( 'Could not create the data source.', 'data-importer' ) );
		}

		$source_id = (int) $wpdb->insert_id;
		$template  = self::insert_template(
			$source_id,
			array(
				'name'           => __( 'Default Template', 'data-importer' ),
				'slug'           => 'standard',
				'template_html'  => (string) ( $data['template_html'] ?? $default_template ),
				'wrapper_before' => (string) ( $data['wrapper_before'] ?? '<div class="data-importer-list">' ),
				'wrapper_after'  => (string) ( $data['wrapper_after'] ?? '</div>' ),
			)
		);

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		return $source_id;
	}

	/**
	 * Update an existing source's editable fields.
	 *
	 * Accepts any subset of: name, template_html, wrapper_before, wrapper_after, update_key, import_mode.
	 *
	 * @param int   $id   Source ID.
	 * @param array $data Fields to update.
	 * @return bool True on success.
	 */
	public static function update_source( $id, $data ) {
		global $wpdb;

		$table   = self::get_sources_table();
		$allowed = array( 'name', 'template_html', 'wrapper_before', 'wrapper_after', 'update_key' );
		$update  = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			if ( in_array( $field, array( 'template_html', 'wrapper_before', 'wrapper_after' ), true ) ) {
				// PHP templates are trusted (capability-gated via data_importer_capability); store as-is.
				$update[ $field ] = (string) $data[ $field ];
			} else {
				$update[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}

			$formats[] = '%s';
		}

		// import_mode is not in $allowed above because it needs whitelisting.
		if ( array_key_exists( 'import_mode', $data ) ) {
			$update['import_mode'] = self::sanitize_import_mode( (string) $data['import_mode'] );
			$formats[]             = '%s';
		}

		$result = $wpdb->update( $table, $update, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete a source and all its records.
	 *
	 * @param int $id Source ID.
	 * @return void
	 */
	public static function delete_source( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->delete( self::get_records_table(), array( 'source_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::get_source_keys_table(), array( 'source_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::get_templates_table(), array( 'source_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::get_sources_table(), array( 'id' => $id ), array( '%d' ) );

		delete_option( 'data_importer_import_log_' . $id );
		delete_option( 'data_importer_security_log_' . $id );
		delete_option( 'data_importer_template_error_log_' . $id );
	}

	/**
	 * Generate a default label for a new source API key.
	 *
	 * @param int $source_id Source ID.
	 * @return string
	 */
	private static function default_source_api_key_name( $source_id ) {
		$count = count( self::get_source_api_keys( (int) $source_id ) ) + 1;

		if ( 1 === $count ) {
			return __( 'Primary Key', 'data-importer' );
		}

		return sprintf(
			/* translators: %d: sequential API key number. */
			__( 'API Key %d', 'data-importer' ),
			$count
		);
	}

	/**
	 * Generate a new plaintext API key secret.
	 *
	 * @return string
	 */
	private static function generate_source_api_key_secret() {
		try {
			return 'di_' . bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return 'di_' . wp_generate_password( 64, false, false );
		}
	}

	/**
	 * Return the password_* algorithm used for API key hashes.
	 *
	 * @return string|int
	 */
	private static function get_source_api_key_hash_algorithm() {
		if ( defined( 'PASSWORD_ARGON2ID' ) ) {
			return PASSWORD_ARGON2ID;
		}

		return PASSWORD_DEFAULT;
	}

	/**
	 * Build one storage payload for a plaintext API key.
	 *
	 * @param string $secret      Plaintext key.
	 * @param string $name        Display label.
	 * @param string $allowed_ips Sanitized IP/CIDR rules.
	 * @return array|WP_Error
	 */
	private static function build_source_api_key_storage_payload( $secret, $name, $allowed_ips ) {
		$secret = trim( (string) $secret );
		$name   = sanitize_text_field( (string) $name );

		if ( '' === $secret ) {
			return new WP_Error( 'invalid_key', __( 'API key cannot be empty.', 'data-importer' ) );
		}

		if ( '' === $name ) {
			$name = __( 'API Key', 'data-importer' );
		}

		$hash = password_hash( $secret, self::get_source_api_key_hash_algorithm() );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return new WP_Error( 'hash_failed', __( 'Could not securely hash the API key.', 'data-importer' ) );
		}

		return array(
			'name'        => $name,
			'key_hash'    => $hash,
			'key_prefix'  => substr( $secret, 0, 12 ),
			'key_last4'   => substr( $secret, -4 ),
			'allowed_ips' => IpRules::sanitize_rule_list( (string) $allowed_ips ),
		);
	}

	/**
	 * Rehash a verified API key when PHP's preferred algorithm changes.
	 *
	 * @param int    $key_id  Key ID.
	 * @param string $secret  Plaintext key.
	 * @return void
	 */
	private static function rehash_source_api_key( $key_id, $secret ) {
		global $wpdb;

		$hash = password_hash( (string) $secret, self::get_source_api_key_hash_algorithm() );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return;
		}

		$wpdb->update(
			self::get_source_keys_table(),
			array(
				'key_hash'   => $hash,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $key_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whitelist-validate an import mode value.
	 *
	 * @param string $mode Candidate value.
	 * @return string One of 'replace', 'append', 'upsert'.
	 */
	public static function sanitize_import_mode( $mode ) {
		return in_array( $mode, array( 'replace', 'append', 'upsert' ), true ) ? $mode : 'replace';
	}

	/**
	 * Generate a unique slug from the given base string.
	 *
	 * @param string $base Input string.
	 * @return string Unique slug.
	 */
	private static function unique_slug( $base ) {
		global $wpdb;

		$table = self::get_sources_table();
		$slug  = sanitize_title( $base );

		if ( '' === $slug ) {
			$slug = 'data-source';
		}

		$original = $slug;
		$counter  = 2;

		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug = $original . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	// -------------------------------------------------------------------------
	// Template CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return all templates for a source.
	 *
	 * @param int $source_id Source ID.
	 * @return array<int,array<string,string>>
	 */
	public static function get_templates_by_source( $source_id ) {
		global $wpdb;

		$table = self::get_templates_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d ORDER BY created_at ASC, id ASC", (int) $source_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return one template by id.
	 *
	 * @param int $template_id Template ID.
	 * @return array<string,string>|null
	 */
	public static function get_template( $template_id ) {
		global $wpdb;

		$table = self::get_templates_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $template_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return one template by source + slug.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $slug      Template slug.
	 * @return array<string,string>|null
	 */
	public static function get_template_by_source_slug( $source_id, $slug ) {
		global $wpdb;

		$table = self::get_templates_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d AND slug = %s", (int) $source_id, (string) $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return the first/primary template for a source.
	 *
	 * @param int $source_id Source ID.
	 * @return array<string,string>|null
	 */
	public static function get_default_template_for_source( $source_id ) {
		global $wpdb;

		$table = self::get_templates_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d ORDER BY created_at ASC, id ASC LIMIT 1", (int) $source_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create a new template under a source.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $data      Template fields.
	 * @return int|WP_Error
	 */
	public static function insert_template( $source_id, $data ) {
		global $wpdb;

		$source_id = (int) $source_id;
		if ( $source_id <= 0 ) {
			return new WP_Error( 'invalid_source', __( 'Invalid data source.', 'data-importer' ) );
		}

		$table = self::get_templates_table();
		$slug  = self::unique_template_slug( $source_id, (string) ( $data['slug'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name ) {
			$name = __( 'New Template', 'data-importer' );
		}

		$result = $wpdb->insert(
			$table,
			array(
				'source_id'      => $source_id,
				'name'           => $name,
				'slug'           => $slug,
				'template_html'  => (string) ( $data['template_html'] ?? '' ),
				'wrapper_before' => (string) ( $data['wrapper_before'] ?? '' ),
				'wrapper_after'  => (string) ( $data['wrapper_after'] ?? '' ),
				'styles_json'    => self::normalize_template_asset_payload( $data['styles_json'] ?? array() ),
				'style_code'     => (string) ( $data['style_code'] ?? '' ),
				'scripts_json'   => self::normalize_template_asset_payload( $data['scripts_json'] ?? array() ),
				'script_code'    => (string) ( $data['script_code'] ?? '' ),
				'updated_at'     => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'insert_failed', __( 'Could not create the template.', 'data-importer' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing template.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $data        Template fields.
	 * @return bool
	 */
	public static function update_template( $template_id, $data ) {
		global $wpdb;

		$table    = self::get_templates_table();
		$existing = self::get_template( $template_id );
		if ( ! $existing ) {
			return false;
		}

		$update  = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'name', $data ) ) {
			$update['name'] = sanitize_text_field( (string) $data['name'] );
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'slug', $data ) ) {
			$update['slug'] = self::unique_template_slug(
				(int) $existing['source_id'],
				(string) $data['slug'],
				(int) $template_id
			);
			$formats[] = '%s';
		}

		foreach ( array( 'template_html', 'wrapper_before', 'wrapper_after', 'style_code', 'script_code' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = (string) $data[ $field ];
				$formats[]        = '%s';
			}
		}

		foreach ( array( 'styles_json', 'scripts_json' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = self::normalize_template_asset_payload( $data[ $field ] );
				$formats[]        = '%s';
			}
		}

		$result = $wpdb->update( $table, $update, array( 'id' => (int) $template_id ), $formats, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Mark stored runtime/validation errors for one template as resolved.
	 *
	 * @param int $source_id   Source ID.
	 * @param int $template_id Template ID.
	 * @return bool True when the log option was updated or did not need changes.
	 */
	public static function resolve_template_error_log( int $source_id, int $template_id ): bool {
		$source_id   = (int) $source_id;
		$template_id = (int) $template_id;

		if ( $source_id <= 0 || $template_id <= 0 ) {
			return false;
		}

		$option = 'data_importer_template_error_log_' . $source_id;
		$log    = get_option( $option, array() );
		if ( ! is_array( $log ) || empty( $log ) ) {
			return true;
		}

		$resolved = false;
		foreach ( $log as &$entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_template_id = isset( $entry['template'] ) ? absint( $entry['template'] ) : 0;
			if ( $entry_template_id === $template_id && empty( $entry['resolved'] ) ) {
				$entry['resolved']    = 1;
				$entry['resolved_at'] = current_time( 'mysql' );
				$resolved             = true;
			}
		}
		unset( $entry );

		if ( ! $resolved ) {
			return true;
		}

		return update_option( $option, array_values( $log ), false );
	}

	/**
	 * Delete one template (but keep at least one template per source).
	 *
	 * @param int $template_id Template ID.
	 * @return bool|WP_Error
	 */
	public static function delete_template( $template_id ) {
		global $wpdb;

		$template = self::get_template( $template_id );
		if ( ! $template ) {
			return false;
		}

		$table = self::get_templates_table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", (int) $template['source_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count <= 1 ) {
			return new WP_Error( 'last_template', __( 'At least one template must remain.', 'data-importer' ) );
		}

		$deleted = $wpdb->delete( $table, array( 'id' => (int) $template_id ), array( '%d' ) );
		if ( false !== $deleted ) {
			self::resolve_template_error_log( (int) $template['source_id'], (int) $template_id );
		}

		return false !== $deleted;
	}

	/**
	 * Generate a unique template slug within one source.
	 *
	 * @param int    $source_id    Source ID.
	 * @param string $base         Requested slug.
	 * @param int    $exclude_id   Optional template id to exclude.
	 * @return string
	 */
	private static function unique_template_slug( $source_id, $base, $exclude_id = 0 ) {
		global $wpdb;

		$table = self::get_templates_table();
		$slug  = sanitize_title( $base );

		if ( '' === $slug ) {
			$slug = 'template';
		}

		$original = $slug;
		$counter  = 2;

		do {
			$sql = "SELECT id FROM {$table} WHERE source_id = %d AND slug = %s";
			$args = array( (int) $source_id, $slug );

			if ( $exclude_id > 0 ) {
				$sql   .= ' AND id != %d';
				$args[] = (int) $exclude_id;
			}

			$sql .= ' LIMIT 1';
			$hit  = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $hit ) {
				break;
			}

			$slug = $original . '-' . $counter;
			++$counter;
		} while ( true );

		return $slug;
	}

	/**
	 * Normalize a template asset payload for DB storage.
	 *
	 * Accepts either a JSON string or a PHP array and always returns JSON.
	 *
	 * @param mixed $value Asset payload.
	 * @return string
	 */
	private static function normalize_template_asset_payload( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return (string) $value;
			}
		}

		if ( ! is_array( $value ) ) {
			return '[]';
		}

		$encoded = wp_json_encode( array_values( $value ) );
		return is_string( $encoded ) ? $encoded : '[]';
	}

	// -------------------------------------------------------------------------
	// Record CRUD
	// -------------------------------------------------------------------------

	/**
	 * Replace all records for a source (delete then insert).
	 *
	 * @param int   $source_id Source ID.
	 * @param array $records   Array of data objects.
	 * @return int|WP_Error Number of inserted records or error.
	 */
	public static function replace_all_records( $source_id, $records ) {
		global $wpdb;

		$source_id = (int) $source_id;

		if ( ! is_array( $records ) ) {
			return new WP_Error( 'invalid_records', __( 'Invalid data format.', 'data-importer' ), array( 'status' => 400 ) );
		}

		$table = self::get_records_table();
		$wpdb->delete( $table, array( 'source_id' => $source_id ), array( '%d' ) );

		$inserted = 0;
		foreach ( $records as $record ) {
			$clean  = RestController::sanitize_record( $record );
			$result = $wpdb->insert(
				$table,
				array(
					'source_id'   => $source_id,
					'record_data' => wp_json_encode( $clean ),
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s' )
			);

			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Append records to a source without removing existing ones.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $records   Array of data objects.
	 * @return int|WP_Error Number of inserted records or error.
	 */
	public static function append_records( $source_id, $records ) {
		global $wpdb;

		$source_id = (int) $source_id;

		if ( ! is_array( $records ) ) {
			return new WP_Error( 'invalid_records', __( 'Invalid data format.', 'data-importer' ), array( 'status' => 400 ) );
		}

		$table    = self::get_records_table();
		$inserted = 0;

		foreach ( $records as $record ) {
			$clean  = RestController::sanitize_record( $record );
			$result = $wpdb->insert(
				$table,
				array(
					'source_id'   => $source_id,
					'record_data' => wp_json_encode( $clean ),
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s' )
			);

			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Upsert records for a source: update existing rows that match on the given
	 * key column(s), insert new ones.
	 *
	 * Key columns are field names inside the JSON record_data blob. Matching is
	 * done in PHP so no JSON-indexing assumptions are required.
	 *
	 * @param int      $source_id   Source ID.
	 * @param array    $records     Array of data objects.
	 * @param string[] $key_columns Field names to match on (non-empty).
	 * @return int|WP_Error Number of affected rows (inserted + updated) or error.
	 */
	public static function upsert_records( $source_id, $records, $key_columns ) {
		global $wpdb;

		$source_id = (int) $source_id;

		if ( ! is_array( $records ) ) {
			return new WP_Error( 'invalid_records', __( 'Invalid data format.', 'data-importer' ), array( 'status' => 400 ) );
		}

		$key_columns = array_values( array_filter( array_map( 'strval', (array) $key_columns ) ) );

		if ( empty( $key_columns ) ) {
			return new WP_Error( 'missing_update_key', __( 'At least one update key column is required for upsert mode.', 'data-importer' ), array( 'status' => 400 ) );
		}

		$table = self::get_records_table();

		// Load existing records into a lookup map keyed by composite key value.
		$existing_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT id, record_data FROM {$table} WHERE source_id = %d", $source_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// Build a map: serialised composite key → DB row id.
		$key_map = array();
		if ( is_array( $existing_rows ) ) {
			foreach ( $existing_rows as $row ) {
				$data = json_decode( (string) $row['record_data'], true );
				if ( ! is_array( $data ) ) {
					continue;
				}

				$composite = self::build_composite_key( $data, $key_columns );
				if ( null !== $composite ) {
					$key_map[ $composite ] = (int) $row['id'];
				}
			}
		}

		$affected = 0;

		foreach ( $records as $record ) {
			$clean     = RestController::sanitize_record( $record );
			$composite = self::build_composite_key( $clean, $key_columns );

			if ( null !== $composite && isset( $key_map[ $composite ] ) ) {
				// Update existing row.
				$result = $wpdb->update(
					$table,
					array( 'record_data' => wp_json_encode( $clean ) ),
					array( 'id' => $key_map[ $composite ] ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false !== $result ) {
					++$affected;
				}
			} else {
				// Insert new row.
				$result = $wpdb->insert(
					$table,
					array(
						'source_id'   => $source_id,
						'record_data' => wp_json_encode( $clean ),
						'created_at'  => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s' )
				);

				if ( false !== $result ) {
					++$affected;
					// Add to map so duplicate keys within the same batch don't re-insert.
					if ( null !== $composite ) {
						$key_map[ $composite ] = (int) $wpdb->insert_id;
					}
				}
			}
		}

		return $affected;
	}

	/**
	 * Build a stable string key from a record's values for the given columns.
	 *
	 * Returns null if any required column is missing from the record.
	 *
	 * @param array    $record      Decoded record array.
	 * @param string[] $key_columns Column names to combine.
	 * @return string|null
	 */
	private static function build_composite_key( $record, $key_columns ) {
		$parts = array();

		foreach ( $key_columns as $col ) {
			if ( ! array_key_exists( $col, $record ) ) {
				return null;
			}

			$val = $record[ $col ];
			if ( is_array( $val ) ) {
				$val = wp_json_encode( $val );
			} elseif ( null === $val ) {
				$val = '';
			} else {
				$val = (string) $val;
			}

			$parts[] = $val;
		}

		return implode( "\x00", $parts );
	}

	/**
	 * Fetch records with optional filters.
	 *
	 * @param array $args {
	 *     @type int    $source_id Filter by source (required for proper scoping).
	 *     @type int    $limit     Max rows (0 = all).
	 *     @type int    $offset    Skip first N rows.
	 *     @type string $order     ASC or DESC.
	 *     @type int    $id        Fetch a single record by its DB id.
	 * }
	 * @return array
	 */
	public static function get_records( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'source_id' => 0,
			'limit'     => 0,
			'offset'    => 0,
			'order'     => 'ASC',
			'id'        => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$table         = self::get_records_table();
		$order         = ( 'DESC' === strtoupper( (string) $args['order'] ) ) ? 'DESC' : 'ASC';
		$where_clauses = array();
		$query_args    = array();

		if ( ! empty( $args['source_id'] ) ) {
			$where_clauses[] = 'source_id = %d';
			$query_args[]    = absint( $args['source_id'] );
		}

		if ( ! empty( $args['id'] ) ) {
			$where_clauses[] = 'id = %d';
			$query_args[]    = absint( $args['id'] );
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';
		$sql       = "SELECT id, source_id, record_data, created_at FROM {$table} {$where_sql} ORDER BY id {$order}";

		if ( ! empty( $args['limit'] ) ) {
			$sql         .= ' LIMIT %d';
			$query_args[] = absint( $args['limit'] );

			if ( ! empty( $args['offset'] ) ) {
				$sql         .= ' OFFSET %d';
				$query_args[] = absint( $args['offset'] );
			}
		}

		if ( ! empty( $query_args ) ) {
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count records, optionally scoped to a source.
	 *
	 * @param int $source_id Source ID (0 = all sources).
	 * @return int
	 */
	public static function count_records( $source_id = 0 ) {
		global $wpdb;

		$table = self::get_records_table();

		if ( $source_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", (int) $source_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete all records for a given source.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	public static function truncate_records( $source_id ) {
		global $wpdb;

		$wpdb->delete( self::get_records_table(), array( 'source_id' => (int) $source_id ), array( '%d' ) );
	}

	/**
	 * Return flattened field names from the first record of a source.
	 *
	 * @param int $source_id Source ID.
	 * @return string[]
	 */
	public static function get_fields( $source_id ) {
		$rows = self::get_records(
			array(
				'source_id' => (int) $source_id,
				'limit'     => 1,
				'order'     => 'ASC',
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = json_decode( $rows[0]['record_data'], true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$fields = array();
		self::flatten_keys( $data, '', $fields );
		sort( $fields );

		return $fields;
	}

	/**
	 * Recursively flatten associative keys to dot notation.
	 *
	 * @param array  $data   Data array.
	 * @param string $prefix Current key prefix.
	 * @param array  $fields Output collection (by reference).
	 * @return void
	 */
	private static function flatten_keys( $data, $prefix, &$fields ) {
		foreach ( $data as $key => $value ) {
			$key      = sanitize_key( (string) $key );
			$full_key = '' !== $prefix ? $prefix . '.' . $key : $key;

			if ( is_array( $value ) && self::is_assoc( $value ) ) {
				self::flatten_keys( $value, $full_key, $fields );
			} else {
				$fields[] = $full_key;
			}
		}
	}

	/**
	 * Whether an array is associative (not a list).
	 *
	 * @param array $array Input array.
	 * @return bool
	 */
	private static function is_assoc( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
