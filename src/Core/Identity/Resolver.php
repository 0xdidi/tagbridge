<?php
/**
 * Identity resolver: the heart of identity stitching.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Identity;

/**
 * Resolves distinct ids and person properties.
 */
final class Resolver {

	/**
	 * PostHog cookie name prefix.
	 *
	 * @var string
	 */
	const COOKIE_PREFIX = 'ph_';

	/**
	 * PostHog cookie name suffix.
	 *
	 * @var string
	 */
	const COOKIE_SUFFIX = '_posthog';

	/**
	 * Prefix applied to the stable, hashed WordPress user identifier.
	 *
	 * @var string
	 */
	const USER_ID_PREFIX = 'wp_';

	/**
	 * Key posthog-js stores session state under inside its cookie.
	 *
	 * @var string
	 */
	const SESSION_ID_KEY = '$sesid';

	/**
	 * Idle window in milliseconds before posthog-js rotates session id.
	 *
	 * @var int
	 */
	const SESSION_MAX_IDLE_MS = 1800000;

	/**
	 * Maximum session length in milliseconds allowed by PostHog.
	 *
	 * @var int
	 */
	const SESSION_MAX_LENGTH_MS = 86400000;

	/**
	 * Source: the id came from the logged-in user (identified).
	 *
	 * @var string
	 */
	const SOURCE_IDENTIFIED = 'identified';

	/**
	 * Source: the id came from the posthog-js cookie (anonymous or prior).
	 *
	 * @var string
	 */
	const SOURCE_COOKIE = 'cookie';

	/**
	 * Source: a caller-supplied fallback id was used.
	 *
	 * @var string
	 */
	const SOURCE_FALLBACK = 'fallback';

	/**
	 * Source: no id could be determined.
	 *
	 * @var string
	 */
	const SOURCE_NONE = 'none';

	/**
	 * Build the posthog-js cookie name for a project API key.
	 *
	 * @param string $project_api_key Project API key.
	 * @return string
	 */
	public static function cookie_name( $project_api_key ) {
		return self::COOKIE_PREFIX . (string) $project_api_key . self::COOKIE_SUFFIX;
	}

	/**
	 * Decode a posthog-js cookie value into an associative array.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @return array<string,mixed>|null
	 */
	public static function decode_cookie( $cookie_value ) {
		if ( ! is_string( $cookie_value ) || '' === $cookie_value ) {
			return null;
		}

		$decoded = json_decode( $cookie_value, true );

		if ( ! is_array( $decoded ) && false !== strpos( $cookie_value, '%' ) ) {
			$decoded = json_decode( rawurldecode( $cookie_value ), true );
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Extract the distinct id from a posthog-js cookie value.
	 *
	 * Handles a missing cookie, a malformed (non-JSON) value, and a value that
	 * is still URL-encoded. Returns null when no usable distinct id is present.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @return string|null The distinct id, or null.
	 */
	public static function parse_distinct_id( $cookie_value ) {
		$decoded = self::decode_cookie( $cookie_value );
		if ( null === $decoded || ! isset( $decoded['distinct_id'] ) ) {
			return null;
		}

		$distinct_id = $decoded['distinct_id'];
		if ( ! is_string( $distinct_id ) || '' === $distinct_id ) {
			return null;
		}

		return $distinct_id;
	}

	/**
	 * Extract the browser session id from a posthog-js cookie value when fresh.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @param int|null    $now_ms       Current epoch time in ms, or null to skip freshness check.
	 * @return string|null
	 */
	public static function parse_session_id( $cookie_value, $now_ms = null ) {
		$decoded = self::decode_cookie( $cookie_value );
		if ( null === $decoded || ! isset( $decoded[ self::SESSION_ID_KEY ] ) ) {
			return null;
		}

		$sesid = $decoded[ self::SESSION_ID_KEY ];
		if ( ! is_array( $sesid ) || ! isset( $sesid[1] ) || ! is_string( $sesid[1] ) || '' === $sesid[1] ) {
			return null;
		}

		if ( null !== $now_ms ) {
			$last_activity = isset( $sesid[0] ) && is_numeric( $sesid[0] ) ? (int) $sesid[0] : 0;
			$session_start = isset( $sesid[2] ) && is_numeric( $sesid[2] ) ? (int) $sesid[2] : 0;

			if ( $last_activity <= 0 || ( $now_ms - $last_activity ) > self::SESSION_MAX_IDLE_MS ) {
				return null;
			}

			if ( $session_start > 0 && ( $now_ms - $session_start ) > self::SESSION_MAX_LENGTH_MS ) {
				return null;
			}
		}

		return $sesid[1];
	}

	/**
	 * Whether a distinct id belongs to an identified WordPress user.
	 *
	 * @param string $distinct_id Distinct id.
	 * @return bool
	 */
	public static function is_user_id( $distinct_id ) {
		return is_string( $distinct_id ) && 0 === strpos( $distinct_id, self::USER_ID_PREFIX );
	}

	/**
	 * Compute a stable, non-reversible distinct id for a WordPress user.
	 *
	 * Uses an HMAC of the user id keyed by a site salt, so the raw email or user
	 * id is never used directly as the PostHog identifier. The same user always
	 * resolves to the same id (stable across sessions), and the value cannot be
	 * reversed back to the user id without the salt.
	 *
	 * @param int|string $user_id WordPress user id.
	 * @param string     $salt    Site-specific secret salt.
	 * @return string
	 */
	public static function stable_user_id( $user_id, $salt ) {
		$hash = hash_hmac( 'sha256', 'user:' . (string) $user_id, (string) $salt );
		return self::USER_ID_PREFIX . $hash;
	}

	/**
	 * Resolve which distinct id a server-side event should use.
	 *
	 * Precedence:
	 *  1. The identified id (a logged-in user's stable id), so server events
	 *     attach to the identified person.
	 *  2. The cookie distinct id (the anonymous browser id), so anonymous server
	 *     events join the same anonymous person as client events.
	 *  3. A caller-supplied fallback (for server events with no prior page load).
	 *
	 * @param string|null $cookie_distinct_id The id from the posthog-js cookie.
	 * @param string|null $identified_id      The logged-in user's stable id.
	 * @param string|null $fallback           Optional fallback id.
	 * @return array{distinct_id:string,source:string}
	 */
	public static function resolve_distinct_id( $cookie_distinct_id, $identified_id = null, $fallback = null ) {
		if ( is_string( $identified_id ) && '' !== $identified_id ) {
			return array(
				'distinct_id' => $identified_id,
				'source'      => self::SOURCE_IDENTIFIED,
			);
		}

		if ( is_string( $cookie_distinct_id ) && '' !== $cookie_distinct_id ) {
			return array(
				'distinct_id' => $cookie_distinct_id,
				'source'      => self::SOURCE_COOKIE,
			);
		}

		if ( is_string( $fallback ) && '' !== $fallback ) {
			return array(
				'distinct_id' => $fallback,
				'source'      => self::SOURCE_FALLBACK,
			);
		}

		return array(
			'distinct_id' => '',
			'source'      => self::SOURCE_NONE,
		);
	}

	/**
	 * Normalize raw person properties to the allowed, non-empty set.
	 *
	 * Only email, name, and role are kept. Empty values are dropped so we never
	 * overwrite an existing person property with a blank.
	 *
	 * @param array<string,mixed> $raw Raw properties (email, name, role).
	 * @return array<string,string>
	 */
	public static function person_properties( array $raw ) {
		$allowed = array( 'email', 'name', 'role' );
		$props   = array();

		foreach ( $allowed as $field ) {
			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}
			$value = is_scalar( $raw[ $field ] ) ? trim( (string) $raw[ $field ] ) : '';
			if ( '' !== $value ) {
				$props[ $field ] = $value;
			}
		}

		return $props;
	}
}
