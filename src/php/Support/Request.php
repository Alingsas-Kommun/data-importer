<?php

namespace DataImporter\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for inspecting the current HTTP request.
 */
class Request {

	/**
	 * Resolve the real client IP, optionally honouring X-Forwarded-For.
	 *
	 * Trusts REMOTE_ADDR by default. When the data_importer_trust_proxy filter is
	 * enabled, walks the X-Forwarded-For chain from the rightmost entry — the
	 * leftmost is client-supplied and trivially spoofable, so the rightmost IP
	 * added by a trusted proxy is the real client address.
	 *
	 * @return string The resolved IP, or 'unknown' if none could be determined.
	 */
	public static function get_client_ip(): string {
		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
				$ip = $remote;
			}
		}

		$trust_proxy = (bool) apply_filters( 'data_importer_trust_proxy', false );
		if ( $trust_proxy && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_reverse( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
			foreach ( $parts as $part ) {
				$cand = trim( (string) $part );
				if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
					$ip = $cand;
					break;
				}
			}
		}

		return '' === $ip ? 'unknown' : $ip;
	}
}
