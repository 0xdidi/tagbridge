<?php
/**
 * WordPress privacy gate for consent, role exclusions, and Do Not Track.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Privacy;

use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Settings\GlobalSettings;

/**
 * Central privacy evaluator for all integration modules.
 */
final class PrivacyGate {

	/**
	 * Register plugin integration with the WordPress Consent API.
	 *
	 * @return void
	 */
	public static function register() {
		if ( defined( 'TAGBRIDGE_FILE' ) ) {
			add_filter( 'wp_consent_api_registered_' . plugin_basename( TAGBRIDGE_FILE ), '__return_true' );
		}
	}

	/**
	 * Whether the WordPress Consent API plugin/function is available.
	 *
	 * @return bool
	 */
	public static function is_consent_api_active() {
		$active = function_exists( 'wp_has_consent' );

		/**
		 * Filter whether the WordPress Consent API is considered active.
		 *
		 * @param bool $active Whether wp_has_consent() is available.
		 */
		return (bool) apply_filters( 'tagbridge_consent_api_active', $active );
	}

	/**
	 * Whether the current visitor has granted consent for a category.
	 *
	 * Defaults to true when the WP Consent API is not installed.
	 *
	 * @param string $category Consent category slug.
	 * @return bool
	 */
	public static function has_consent( $category ) {
		if ( ! self::is_consent_api_active() || ! function_exists( 'wp_has_consent' ) ) {
			return true;
		}
		return (bool) wp_has_consent( (string) $category );
	}

	/**
	 * Whether a user (or the current logged-in visitor) has an excluded role.
	 *
	 * @param object|null $user Optional WP_User object. Defaults to current user.
	 * @return bool
	 */
	public static function is_user_excluded( $user = null ) {
		if ( null === $user && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
		}

		$roles    = ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();
		$excluded = Policy::is_role_excluded( $roles, GlobalSettings::excluded_roles() );

		/**
		 * Filter whether the given user is excluded from Tagbridge tracking.
		 *
		 * @param bool        $excluded Whether the user is excluded.
		 * @param object|null $user     The WordPress user object, or null for guests.
		 */
		return (bool) apply_filters( 'tagbridge_is_user_excluded', $excluded, $user );
	}

	/**
	 * Whether Do Not Track is enabled in settings and signaled by the request.
	 *
	 * @return bool
	 */
	public static function is_dnt_blocked() {
		if ( ! GlobalSettings::respect_dnt() ) {
			return false;
		}

		if ( ! isset( $_SERVER['HTTP_DNT'] ) ) {
			return false;
		}

		$dnt = sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) );
		return Policy::is_dnt_enabled( $dnt );
	}

	/**
	 * List consent categories that are currently denied for this request.
	 *
	 * Used to snapshot consent state on WooCommerce checkout orders when order
	 * completion later fires asynchronously from an admin or webhook context.
	 *
	 * @return string[]
	 */
	public static function denied_consent_categories() {
		if ( Policy::CONSENT_OFF === GlobalSettings::consent_mode() || ! self::is_consent_api_active() ) {
			return array();
		}

		$denied = array();
		foreach ( ConsentCategory::all() as $category ) {
			if ( ! self::has_consent( $category ) ) {
				$denied[] = $category;
			}
		}

		return $denied;
	}

	/**
	 * Evaluate the effective tracking decision for a module's consent category.
	 *
	 * @param string      $category Consent category slug.
	 * @param object|null $user     Optional WP_User to evaluate instead of current user.
	 * @return array{allowed:bool,cookieless:bool,reason:string}
	 */
	public static function decision( $category = ConsentCategory::STATISTICS, $user = null ) {
		if ( self::is_user_excluded( $user ) ) {
			$decision = array(
				'allowed'    => false,
				'cookieless' => false,
				'reason'     => 'role_excluded',
			);
		} elseif ( self::is_dnt_blocked() ) {
			$decision = array(
				'allowed'    => false,
				'cookieless' => false,
				'reason'     => 'dnt',
			);
		} else {
			$api_active  = self::is_consent_api_active();
			$has_consent = ( $api_active && function_exists( 'wp_has_consent' ) ) ? (bool) wp_has_consent( (string) $category ) : true;
			$eval        = Policy::evaluate_consent(
				GlobalSettings::consent_mode(),
				$api_active,
				$has_consent
			);

			if ( empty( $eval['allowed'] ) ) {
				$reason = 'consent_denied';
			} elseif ( ! empty( $eval['cookieless'] ) ) {
				$reason = 'cookieless_consent';
			} else {
				$reason = 'ok';
			}

			$decision = array(
				'allowed'    => ! empty( $eval['allowed'] ),
				'cookieless' => ! empty( $eval['cookieless'] ),
				'reason'     => $reason,
			);
		}

		/**
		 * Filter the privacy decision for a consent category.
		 *
		 * @param array{allowed:bool,cookieless:bool,reason:string} $decision Privacy evaluation result.
		 * @param string                                          $category Consent category slug.
		 * @param object|null                                     $user     Evaluated WP_User or null.
		 */
		return (array) apply_filters( 'tagbridge_privacy_decision', $decision, $category, $user );
	}
}
