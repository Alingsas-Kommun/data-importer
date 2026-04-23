<?php

namespace DataImporter\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared IP/CIDR allowlist helpers.
 */
class IpRules {

	/**
	 * Sanitize a comma/newline separated allowlist.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function sanitize_rule_list( string $raw ): string {
		$valid = array();

		foreach ( self::split_rules( $raw ) as $rule ) {
			$normalized = self::normalize_rule( $rule );
			if ( '' === $normalized ) {
				continue;
			}

			$valid[ $normalized ] = $normalized;
		}

		return implode( ',', array_values( $valid ) );
	}

	/**
	 * Check if a client IP is allowed by a rule list.
	 *
	 * Empty rule lists allow all IPs.
	 *
	 * @param string $ip    Client IP.
	 * @param string $rules Comma/newline separated rule list.
	 * @return bool
	 */
	public static function is_ip_allowed( string $ip, string $rules ): bool {
		$rules = trim( $rules );

		if ( '' === $rules ) {
			return true;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( self::split_rules( $rules ) as $rule ) {
			$normalized = self::normalize_rule( $rule );
			if ( '' === $normalized ) {
				continue;
			}

			if ( self::ip_matches_rule( $ip, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split raw input into candidate rules.
	 *
	 * @param string $raw Raw input.
	 * @return array<int,string>
	 */
	private static function split_rules( string $raw ): array {
		$parts = preg_split( '/[\r\n,]+/', $raw );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Normalize one exact-IP or CIDR rule.
	 *
	 * @param string $rule Candidate rule.
	 * @return string
	 */
	private static function normalize_rule( string $rule ): string {
		$rule = trim( $rule );

		if ( '' === $rule ) {
			return '';
		}

		if ( false === strpos( $rule, '/' ) ) {
			return filter_var( $rule, FILTER_VALIDATE_IP ) ? $rule : '';
		}

		$parts = explode( '/', $rule, 2 );
		$ip    = trim( (string) ( $parts[0] ?? '' ) );
		$bits  = trim( (string) ( $parts[1] ?? '' ) );

		if ( '' === $ip || '' === $bits || ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! ctype_digit( $bits ) ) {
			return '';
		}

		$binary = inet_pton( $ip );
		if ( false === $binary ) {
			return '';
		}

		$max_bits = strlen( $binary ) * 8;
		$prefix   = (int) $bits;

		if ( $prefix < 0 || $prefix > $max_bits ) {
			return '';
		}

		return $ip . '/' . $prefix;
	}

	/**
	 * Match one IP against one exact-IP or CIDR rule.
	 *
	 * @param string $ip   Client IP.
	 * @param string $rule Exact IP or CIDR rule.
	 * @return bool
	 */
	private static function ip_matches_rule( string $ip, string $rule ): bool {
		if ( false === strpos( $rule, '/' ) ) {
			return $ip === $rule;
		}

		$parts   = explode( '/', $rule, 2 );
		$network = (string) ( $parts[0] ?? '' );
		$prefix  = (int) ( $parts[1] ?? 0 );
		$ip_bin  = inet_pton( $ip );
		$net_bin = inet_pton( $network );

		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}

		$full_bytes = intdiv( $prefix, 8 );
		$extra_bits = $prefix % 8;

		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $net_bin, 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $extra_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $extra_bits ) ) & 0xFF;

		return ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $net_bin[ $full_bytes ] ) & $mask );
	}
}
