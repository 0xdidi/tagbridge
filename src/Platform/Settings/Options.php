<?php
/**
 * Settings storage for the whole plugin.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Settings;

/**
 * Central, module-agnostic settings accessor.
 */
final class Options {

	/**
	 * Option name for the single plugin settings array.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'tagbridge';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * The full stored settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = $stored;

		return $stored;
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
	 * Whether a module is enabled. Falls back to the supplied default when the
	 * site owner has never toggled it.
	 *
	 * @param string $id      Module id.
	 * @param bool   $fallback Default enabled state when never toggled.
	 * @return bool
	 */
	public static function is_module_enabled( $id, $fallback = false ) {
		$all = self::all();
		if ( isset( $all['modules'] ) && is_array( $all['modules'] ) && array_key_exists( $id, $all['modules'] ) ) {
			return (bool) $all['modules'][ $id ];
		}
		return (bool) $fallback;
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id Module id.
	 * @param bool   $on Whether the module should be enabled.
	 * @return void
	 */
	public static function set_module_enabled( $id, $on ) {
		$all = self::all();
		if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
			$all['modules'] = array();
		}
		$all['modules'][ $id ] = (bool) $on;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}

	/**
	 * The raw stored settings for one module (no defaults merged in).
	 *
	 * @param string $id Module id.
	 * @return array<string,mixed>
	 */
	public static function module_data( $id ) {
		$all = self::all();
		return ( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) ? $all[ $id ] : array();
	}

	/**
	 * Persist one module's settings slice.
	 *
	 * @param string              $id   Module id.
	 * @param array<string,mixed> $data Sanitized module settings.
	 * @return void
	 */
	public static function save_module_data( $id, array $data ) {
		$all        = self::all();
		$all[ $id ] = $data;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}

	/**
	 * The raw stored global platform settings (no defaults merged in).
	 *
	 * @return array<string,mixed>
	 */
	public static function global_data() {
		$all = self::all();
		return ( isset( $all['global'] ) && is_array( $all['global'] ) ) ? $all['global'] : array();
	}

	/**
	 * Persist global platform settings while leaving module slices intact.
	 *
	 * @param array<string,mixed> $data Sanitized global settings.
	 * @return void
	 */
	public static function save_global_data( array $data ) {
		$all           = self::all();
		$all['global'] = $data;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}

	/**
	 * Upcoming integration ids the site owner asked to be notified about.
	 *
	 * @return string[]
	 */
	public static function watchlist() {
		$all = self::all();
		return ( isset( $all['watchlist'] ) && is_array( $all['watchlist'] ) )
			? array_values( $all['watchlist'] )
			: array();
	}

	/**
	 * Whether an upcoming integration id is currently on the launch watchlist.
	 *
	 * @param string $id Upcoming integration id.
	 * @return bool
	 */
	public static function is_watched( $id ) {
		return in_array( (string) $id, self::watchlist(), true );
	}

	/**
	 * Add or remove an upcoming integration id from the local launch watchlist.
	 *
	 * @param string $id      Upcoming integration id.
	 * @param bool   $watched True to watch, false to remove.
	 * @return void
	 */
	public static function set_watched( $id, $watched ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return;
		}

		$all  = self::all();
		$list = self::watchlist();

		if ( $watched ) {
			$list[] = $id;
			$list   = array_values( array_unique( $list ) );
		} else {
			$list = array_values( array_diff( $list, array( $id ) ) );
		}

		$all['watchlist'] = $list;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}
}
