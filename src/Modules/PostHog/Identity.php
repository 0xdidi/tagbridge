<?php
/**
 * PostHog identity glue.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Identity\Resolver;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Privacy\PrivacyGate;
use Tagbridge\Platform\Settings\GlobalSettings;

/**
 * Resolves the current visitor's PostHog identity from WordPress state.
 */
final class Identity {

	/**
	 * Whether server-side code may read the posthog-js cookie for this request.
	 *
	 * @return bool
	 */
	public static function may_read_posthog_cookie() {
		if ( Policy::CONSENT_OFF === GlobalSettings::consent_mode() ) {
			return true;
		}

		return PrivacyGate::has_consent( ConsentCategory::STATISTICS );
	}

	/**
	 * The distinct id stored by posthog-js in its cookie, if present and permitted.
	 *
	 * @return string|null
	 */
	public static function cookie_distinct_id() {
		$key = Settings::project_api_key();
		if ( '' === $key || ! self::may_read_posthog_cookie() ) {
			return null;
		}

		$name = Resolver::cookie_name( $key );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = is_string( $raw ) ? $raw : '';

		$distinct_id = Resolver::parse_distinct_id( $raw );

		return null === $distinct_id ? null : sanitize_text_field( $distinct_id );
	}

	/**
	 * The browser's current session id from the posthog-js cookie, if fresh and permitted.
	 *
	 * @return string|null
	 */
	public static function cookie_session_id() {
		$key = Settings::project_api_key();
		if ( '' === $key || ! self::may_read_posthog_cookie() ) {
			return null;
		}

		$name = Resolver::cookie_name( $key );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = is_string( $raw ) ? $raw : '';

		$now_ms     = (int) round( microtime( true ) * 1000 );
		$session_id = Resolver::parse_session_id( $raw, $now_ms );

		return null === $session_id ? null : sanitize_text_field( $session_id );
	}

	/**
	 * The identity (stable distinct id + person properties) for the logged-in
	 * user, or null when the visitor is not logged in.
	 *
	 * @return array{distinct_id:string,properties:array<string,string>}|null
	 */
	public static function current_user_identity() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		return self::identity_for( wp_get_current_user() );
	}

	/**
	 * Build the identity for a specific user, respecting the identity settings.
	 *
	 * Used by login/registration hooks where the current-user globals are not
	 * yet reliable. Returns null when identifying users is disabled or the user
	 * is invalid.
	 *
	 * @param \WP_User|null $user A WordPress user object.
	 * @return array{distinct_id:string,properties:array<string,string>}|null
	 */
	public static function identity_for( $user ) {
		$settings = Settings::identity();
		if ( empty( $settings['identify_logged_in'] ) ) {
			return null;
		}

		if ( ! $user || ! isset( $user->ID ) || ! $user->ID ) {
			return null;
		}

		$distinct_id = Resolver::stable_user_id( $user->ID, self::salt() );

		$raw_props = array();
		if ( ! empty( $settings['send_email'] ) ) {
			$raw_props['email'] = $user->user_email;
		}
		if ( ! empty( $settings['send_name'] ) ) {
			$raw_props['name'] = $user->display_name;
		}
		if ( ! empty( $settings['send_role'] ) ) {
			$raw_props['role'] = ( is_array( $user->roles ) && ! empty( $user->roles ) ) ? (string) reset( $user->roles ) : '';
		}

		return array(
			'distinct_id' => $distinct_id,
			'properties'  => Resolver::person_properties( $raw_props ),
		);
	}

	/**
	 * The stable distinct id for a given user (ignores identity on/off setting).
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function stable_id_for_user( $user_id ) {
		return Resolver::stable_user_id( (int) $user_id, self::salt() );
	}

	/**
	 * Resolve the distinct id a server-side event should use.
	 *
	 * Logged-in users resolve to their stable id; anonymous visitors resolve to
	 * the posthog-js cookie id. Used by server-side capture.
	 *
	 * @param string|null $fallback Optional fallback id when nothing else exists.
	 * @return array{distinct_id:string,source:string}
	 */
	public static function server_distinct_id( $fallback = null ) {
		$identity      = self::current_user_identity();
		$identified_id = $identity ? $identity['distinct_id'] : null;

		return Resolver::resolve_distinct_id( self::cookie_distinct_id(), $identified_id, $fallback );
	}

	/**
	 * Existing WooCommerce session customer id when a session cookie already exists.
	 *
	 * @return string|null
	 */
	public static function existing_wc_session_id() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$prefix = 'wp_woocommerce_session_';
		$has    = false;
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			if ( is_string( $cookie_name ) && 0 === strpos( $cookie_name, $prefix ) ) {
				$has = true;
				break;
			}
		}

		if ( ! $has ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) || ! method_exists( $wc->session, 'get_customer_id' ) ) {
			return null;
		}

		$customer_id = sanitize_text_field( (string) $wc->session->get_customer_id() );
		return '' !== $customer_id ? 'wc_sess_' . $customer_id : null;
	}

	/**
	 * Resolve a stable distinct id for an in-session visitor without inventing random UUIDs.
	 *
	 * @return string Empty string when no stable identifier exists.
	 */
	public static function server_visitor_distinct_id() {
		$resolved = self::server_distinct_id( self::existing_wc_session_id() );
		return $resolved['distinct_id'];
	}

	/**
	 * The site-specific salt used to derive stable, non-reversible user ids.
	 *
	 * @return string
	 */
	private static function salt() {
		return wp_salt( 'auth' );
	}
}
