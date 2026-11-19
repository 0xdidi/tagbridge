<?php
/**
 * Pure privacy policy evaluation rules.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Privacy;

/**
 * Evaluates tracking permissions against privacy policies.
 */
final class Policy {

	/**
	 * Consent mode: do not check consent (track immediately).
	 *
	 * @var string
	 */
	const CONSENT_OFF = 'off';

	/**
	 * Consent mode: block tracking until consent is granted.
	 *
	 * @var string
	 */
	const CONSENT_REQUIRE = 'require';

	/**
	 * Consent mode: allow anonymous/memory-only tracking until consent is granted.
	 *
	 * @var string
	 */
	const CONSENT_COOKIELESS = 'cookieless_until_consent';

	/**
	 * Valid consent mode identifiers.
	 *
	 * @return string[]
	 */
	public static function consent_modes() {
		return array(
			self::CONSENT_OFF,
			self::CONSENT_REQUIRE,
			self::CONSENT_COOKIELESS,
		);
	}

	/**
	 * Whether a string is a valid consent mode identifier.
	 *
	 * @param string $mode Consent mode slug.
	 * @return bool
	 */
	public static function is_valid_consent_mode( $mode ) {
		return in_array( (string) $mode, self::consent_modes(), true );
	}

	/**
	 * Whether any of a user's roles matches the excluded role list.
	 *
	 * @param string[] $user_roles     Roles assigned to the user.
	 * @param string[] $excluded_roles Roles excluded from tracking.
	 * @return bool
	 */
	public static function is_role_excluded( array $user_roles, array $excluded_roles ) {
		if ( empty( $user_roles ) || empty( $excluded_roles ) ) {
			return false;
		}

		$normalized_user = array();
		foreach ( $user_roles as $role ) {
			$clean = trim( strtolower( (string) $role ) );
			if ( '' !== $clean ) {
				$normalized_user[] = $clean;
			}
		}

		$normalized_excluded = array();
		foreach ( $excluded_roles as $role ) {
			$clean = trim( strtolower( (string) $role ) );
			if ( '' !== $clean ) {
				$normalized_excluded[] = $clean;
			}
		}

		if ( empty( $normalized_user ) || empty( $normalized_excluded ) ) {
			return false;
		}

		return ! empty( array_intersect( $normalized_user, $normalized_excluded ) );
	}

	/**
	 * Whether a request's DNT header value signals Do Not Track.
	 *
	 * @param string $dnt_header Raw HTTP DNT header value.
	 * @return bool
	 */
	public static function is_dnt_enabled( $dnt_header ) {
		return '1' === trim( (string) $dnt_header );
	}

	/**
	 * Evaluate consent mode against API availability and current consent state.
	 *
	 * @param string $mode          One of the CONSENT_* constants.
	 * @param bool   $api_available Whether a consent API provider is active.
	 * @param bool   $has_consent   Whether consent for the category is granted.
	 * @return array{allowed:bool,cookieless:bool}
	 */
	public static function evaluate_consent( $mode, $api_available, $has_consent ) {
		if ( self::CONSENT_OFF === $mode || ! $api_available || $has_consent ) {
			return array(
				'allowed'    => true,
				'cookieless' => false,
			);
		}

		if ( self::CONSENT_COOKIELESS === $mode ) {
			return array(
				'allowed'    => true,
				'cookieless' => true,
			);
		}

		return array(
			'allowed'    => false,
			'cookieless' => false,
		);
	}
}
