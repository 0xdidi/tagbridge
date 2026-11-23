<?php
/**
 * Server-side PostHog client.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Events;

use PostHog\PostHog;

/**
 * Thin, failure-tolerant wrapper over the posthog-php facade.
 */
final class ServerClient {

	/**
	 * Whether init() has successfully run this request.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * The last error message captured (for optional logging by the caller).
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Initialize the PostHog client with the project key and host.
	 *
	 * @param string $api_key Project API key.
	 * @param string $host    Base host URL.
	 * @return bool Whether initialization succeeded.
	 */
	public function init( $api_key, $host ) {
		if ( '' === (string) $api_key || ! class_exists( '\PostHog\PostHog' ) ) {
			return false;
		}

		try {
			PostHog::init(
				(string) $api_key,
				array(
					'host' => (string) $host,
				)
			);
			$this->initialized = true;
		} catch ( \Throwable $e ) {
			$this->last_error  = $e->getMessage();
			$this->initialized = false;
		}

		return $this->initialized;
	}

	/**
	 * Whether the client is ready to send.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->initialized;
	}

	/**
	 * Capture an event.
	 *
	 * @param string              $distinct_id Distinct id for the person.
	 * @param string              $event       Event name (from the Schema).
	 * @param array<string,mixed> $properties  Event properties.
	 * @return bool Whether the call was accepted (queued) without error.
	 */
	public function capture( $distinct_id, $event, array $properties = array() ) {
		if ( ! $this->guard( $distinct_id ) || '' === (string) $event ) {
			return false;
		}

		try {
			PostHog::capture(
				array(
					'distinctId' => (string) $distinct_id,
					'event'      => (string) $event,
					'properties' => $properties,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Identify a person and set properties.
	 *
	 * @param string              $distinct_id Distinct id for the person.
	 * @param array<string,mixed> $properties  Person properties to set.
	 * @return bool
	 */
	public function identify( $distinct_id, array $properties = array() ) {
		if ( ! $this->guard( $distinct_id ) ) {
			return false;
		}

		try {
			PostHog::identify(
				array(
					'distinctId' => (string) $distinct_id,
					'properties' => $properties,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Alias a previous (anonymous) id to a person, merging their history.
	 *
	 * Events from $alias become attributed to $distinct_id.
	 *
	 * @param string $distinct_id The canonical (identified) id.
	 * @param string $alias       The previous id to merge in.
	 * @return bool
	 */
	public function alias( $distinct_id, $alias ) {
		if ( ! $this->guard( $distinct_id ) || '' === (string) $alias || (string) $alias === (string) $distinct_id ) {
			return false;
		}

		try {
			PostHog::alias(
				array(
					'distinctId' => (string) $distinct_id,
					'alias'      => (string) $alias,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Flush queued events to PostHog. Safe to call on shutdown.
	 *
	 * @return bool
	 */
	public function flush() {
		if ( ! $this->initialized ) {
			return false;
		}

		try {
			PostHog::flush();
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Evaluate a feature flag for a distinct id, returning $fallback on failure.
	 *
	 * @param string      $key         Feature flag key.
	 * @param string      $distinct_id Distinct id.
	 * @param bool|string $fallback    Fallback value.
	 * @return bool|string
	 */
	public function get_feature_flag( $key, $distinct_id, $fallback = false ) {
		if ( ! $this->guard( $distinct_id ) || '' === (string) $key ) {
			return $fallback;
		}

		try {
			$value = PostHog::getFeatureFlag( (string) $key, (string) $distinct_id );
			return null === $value ? $fallback : $value;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return $fallback;
		}
	}

	/**
	 * The last error message, if any.
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * Shared guard: client ready and a non-empty distinct id.
	 *
	 * @param string $distinct_id Distinct id.
	 * @return bool
	 */
	private function guard( $distinct_id ) {
		return $this->initialized && '' !== (string) $distinct_id;
	}
}
