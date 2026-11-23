<?php
/**
 * Webhooks module settings: defaults, typed accessors, and sanitization.
 *
 * @package Tagbridge\Modules\Webhooks
 */

namespace Tagbridge\Modules\Webhooks;

use Tagbridge\Core\Events\Schema;
use Tagbridge\Platform\Settings\Options;

/**
 * Settings accessor for the Webhooks module.
 */
final class Settings {

	/**
	 * Cached merged settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$events = array();
		foreach ( array_keys( Schema::all_events() ) as $key ) {
			$events[ $key ] = true;
		}

		return array(
			'endpoint_url' => '',
			'secret'       => '',
			'events'       => $events,
		);
	}

	/**
	 * Get the full, defaults-merged settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored           = Options::module_data( Module::ID );
		$defaults         = self::defaults();
		$merged           = array_merge( $defaults, $stored );
		$merged['events'] = array_merge(
			$defaults['events'],
			isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array()
		);

		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Clear the in-request cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Configured webhook endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return (string) self::all()['endpoint_url'];
	}

	/**
	 * Optional HMAC signing secret.
	 *
	 * @return string
	 */
	public static function secret() {
		return (string) self::all()['secret'];
	}

	/**
	 * Per-event enablement map.
	 *
	 * @return array<string,bool>
	 */
	public static function events() {
		return (array) self::all()['events'];
	}

	/**
	 * Whether a specific event is enabled for webhook delivery.
	 *
	 * @param string $event_name Event name.
	 * @return bool
	 */
	public static function is_event_enabled( $event_name ) {
		$events = self::events();
		return ! empty( $events[ $event_name ] );
	}

	/**
	 * Whether a valid endpoint URL is saved.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::endpoint_url();
	}

	/**
	 * Sanitize untrusted input into clean Webhooks settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$current = self::all();

		if ( isset( $input['endpoint_url'] ) ) {
			$raw = trim( sanitize_text_field( wp_unslash( $input['endpoint_url'] ) ) );
			if ( '' === $raw ) {
				$current['endpoint_url'] = '';
			} else {
				$escaped                 = esc_url_raw( $raw, array( 'http', 'https' ) );
				$current['endpoint_url'] = '' !== $escaped ? $escaped : $raw;
			}
		}

		if ( isset( $input['secret'] ) ) {
			$current['secret'] = trim( sanitize_text_field( wp_unslash( $input['secret'] ) ) );
		}

		if ( isset( $input['events'] ) && is_array( $input['events'] ) ) {
			$raw_events = wp_unslash( $input['events'] );
			$events     = $current['events'];
			foreach ( array_keys( $events ) as $key ) {
				$events[ $key ] = ! empty( $raw_events[ $key ] );
			}
			$current['events'] = $events;
		}

		return $current;
	}
}
