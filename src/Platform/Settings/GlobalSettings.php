<?php
/**
 * Global platform privacy and behavior settings.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Settings;

use Tagbridge\Core\Privacy\Policy;

/**
 * Typed accessor and sanitizer for global platform settings.
 */
final class GlobalSettings {

	/**
	 * Cached merged settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default global settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'excluded_roles' => array(),
			'consent_mode'   => Policy::CONSENT_OFF,
			'respect_dnt'    => false,
		);
	}

	/**
	 * Get the defaults-merged global settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = Options::global_data();
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $stored );

		if ( ! isset( $merged['excluded_roles'] ) || ! is_array( $merged['excluded_roles'] ) ) {
			$merged['excluded_roles'] = array();
		}

		if ( ! Policy::is_valid_consent_mode( isset( $merged['consent_mode'] ) ? $merged['consent_mode'] : '' ) ) {
			$merged['consent_mode'] = Policy::CONSENT_OFF;
		}

		$merged['respect_dnt'] = ! empty( $merged['respect_dnt'] );

		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Persist sanitized global settings and clear the request cache.
	 *
	 * @param array<string,mixed> $settings Sanitized global settings.
	 * @return void
	 */
	public static function save( array $settings ) {
		Options::save_global_data( $settings );
		self::$cache = null;
	}

	/**
	 * Clear the in-request cache (useful in tests).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * WordPress user role slugs excluded from tracking across all modules.
	 *
	 * @return string[]
	 */
	public static function excluded_roles() {
		return (array) self::all()['excluded_roles'];
	}

	/**
	 * Active WordPress Consent API mode.
	 *
	 * @return string One of the Policy::CONSENT_* constants.
	 */
	public static function consent_mode() {
		return (string) self::all()['consent_mode'];
	}

	/**
	 * Whether browser Do Not Track (DNT) headers should suppress tracking.
	 *
	 * @return bool
	 */
	public static function respect_dnt() {
		return ! empty( self::all()['respect_dnt'] );
	}

	/**
	 * Sanitize raw input into clean global settings.
	 *
	 * Merges over current settings so partial updates preserve unposted fields.
	 *
	 * @param array<string,mixed> $input       Raw, untrusted input.
	 * @param string[]            $valid_roles Optional allowlist of valid role slugs.
	 * @return array<string,mixed> Sanitized settings ready to save.
	 */
	public static function sanitize( array $input, array $valid_roles = array() ) {
		$current = self::all();

		if ( isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) ) {
			$clean_roles = array();
			foreach ( wp_unslash( $input['excluded_roles'] ) as $role ) {
				$slug = sanitize_key( (string) $role );
				if ( '' !== $slug ) {
					$clean_roles[] = $slug;
				}
			}
			$clean_roles = array_values( array_unique( $clean_roles ) );
			if ( ! empty( $valid_roles ) ) {
				$clean_roles = array_values( array_intersect( $clean_roles, $valid_roles ) );
			}
			$current['excluded_roles'] = $clean_roles;
		} elseif ( ! empty( $input['roles_present'] ) ) {
			$current['excluded_roles'] = array();
		}

		if ( isset( $input['consent_mode'] ) ) {
			$mode                    = sanitize_text_field( wp_unslash( (string) $input['consent_mode'] ) );
			$current['consent_mode'] = Policy::is_valid_consent_mode( $mode ) ? $mode : Policy::CONSENT_OFF;
		}

		if ( isset( $input['present'] ) || array_key_exists( 'respect_dnt', $input ) ) {
			$current['respect_dnt'] = ! empty( $input['respect_dnt'] );
		}

		return $current;
	}
}
