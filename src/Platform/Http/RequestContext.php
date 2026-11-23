<?php
/**
 * Request HTTP context helpers (visitor IP and user agent).
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Http;

/**
 * Resolves proxy-safe client IP addresses and sanitized user-agent strings.
 */
final class RequestContext {

	/**
	 * Resolve the visitor IP across CDNs and reverse proxies.
	 *
	 * @param array<string,mixed>|null $server Optional server array (defaults to $_SERVER).
	 * @return string Validated IP address, or '' if unavailable.
	 */
	public static function client_ip( $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;

		/**
		 * Filter the resolved visitor IP for server-side events.
		 *
		 * @param string              $ip     Resolved IP ('' if undetermined).
		 * @param array<string,mixed> $server Server request headers.
		 */
		$override = apply_filters( 'tagbridge_server_event_ip', '', $server );
		if ( is_string( $override ) && '' !== $override ) {
			$override = trim( $override );
			if ( false !== filter_var( $override, FILTER_VALIDATE_IP ) ) {
				return $override;
			}
		}

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( empty( $server[ $header ] ) || ! is_string( $server[ $header ] ) ) {
				continue;
			}
			$candidate = trim( sanitize_text_field( wp_unslash( $server[ $header ] ) ) );
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) && is_string( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts     = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $server['HTTP_X_FORWARDED_FOR'] ) ) ) );
			$count     = count( $parts );
			$candidate = $count >= 2 ? $parts[ $count - 2 ] : $parts[0];
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ) {
			$remote = trim( sanitize_text_field( wp_unslash( $server['REMOTE_ADDR'] ) ) );
			if ( false !== filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $remote;
			}
		}

		return '';
	}

	/**
	 * Sanitized browser User-Agent header, or '' if absent.
	 *
	 * @param array<string,mixed>|null $server Optional server array (defaults to $_SERVER).
	 * @return string
	 */
	public static function user_agent( $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;
		if ( empty( $server['HTTP_USER_AGENT'] ) || ! is_string( $server['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $server['HTTP_USER_AGENT'] ) );
	}
}
