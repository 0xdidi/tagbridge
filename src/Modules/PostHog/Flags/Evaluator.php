<?php
/**
 * Server-side PostHog feature flag evaluator.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Flags;

use Tagbridge\Core\Events\ServerClient;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Privacy\PrivacyGate;

/**
 * Evaluates PostHog feature flags server-side with per-request memoization.
 */
final class Evaluator {

	/**
	 * Memoized flag results keyed by "flag_key|distinct_id".
	 *
	 * @var array<string,bool|string>
	 */
	private static $cache = array();

	/**
	 * Clear the in-request evaluation cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Sanitize a PostHog feature flag key while preserving case.
	 *
	 * @param string $flag_key Raw feature flag key.
	 * @return string
	 */
	public static function sanitize_flag_key( $flag_key ) {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $flag_key );
	}

	/**
	 * Evaluate a feature flag for the current visitor.
	 *
	 * @param string      $flag_key Feature flag key.
	 * @param bool|string $fallback Fallback value when unavailable.
	 * @return bool|string
	 */
	public static function evaluate( $flag_key, $fallback = false ) {
		$flag_key = self::sanitize_flag_key( $flag_key );
		if ( '' === $flag_key || ! Settings::is_configured() ) {
			return $fallback;
		}

		$decision = PrivacyGate::decision( ConsentCategory::STATISTICS );
		if ( empty( $decision['allowed'] ) || ! empty( $decision['cookieless'] ) ) {
			return $fallback;
		}

		$distinct_id = Identity::server_visitor_distinct_id();
		if ( '' === $distinct_id ) {
			return $fallback;
		}

		$cache_key = $flag_key . '|' . $distinct_id;
		if ( array_key_exists( $cache_key, self::$cache ) ) {
			return self::$cache[ $cache_key ];
		}

		$client = new ServerClient();
		$value  = $fallback;
		if ( $client->init( Settings::project_api_key(), Settings::host() ) ) {
			$value = $client->get_feature_flag( $flag_key, $distinct_id, $fallback );
		}

		self::$cache[ $cache_key ] = $value;

		return $value;
	}

	/**
	 * Whether an evaluated flag matches an expected boolean or variant string.
	 *
	 * @param string      $flag_key Feature flag key.
	 * @param string|bool $expected Expected value ('true', 'false', or variant key).
	 * @param bool|string $fallback Fallback value when flag evaluation is unavailable.
	 * @return bool
	 */
	public static function matches( $flag_key, $expected = 'true', $fallback = false ) {
		return self::matches_value( self::evaluate( $flag_key, $fallback ), $expected );
	}

	/**
	 * Compare an actual flag value against an expected string/boolean target.
	 *
	 * @param bool|string $actual   Evaluated flag value.
	 * @param string|bool $expected Expected match value.
	 * @return bool
	 */
	public static function matches_value( $actual, $expected ) {
		if ( is_bool( $expected ) ) {
			return (bool) $actual === $expected;
		}

		$expected = strtolower( trim( (string) $expected ) );
		if ( 'true' === $expected ) {
			return true === $actual || 'true' === $actual;
		}
		if ( 'false' === $expected ) {
			return false === $actual || 'false' === $actual;
		}

		return is_string( $actual ) && strtolower( trim( $actual ) ) === $expected;
	}
}
