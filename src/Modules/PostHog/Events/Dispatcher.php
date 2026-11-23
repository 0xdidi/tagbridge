<?php
/**
 * Server-side event dispatcher.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Events;

use Tagbridge\Core\Events\Schema;
use Tagbridge\Core\Events\ServerClient;
use Tagbridge\Core\Identity\Resolver;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Http\RequestContext;

/**
 * Bridges WordPress events to the platform-agnostic ServerClient.
 */
final class Dispatcher {

	/**
	 * The Core client, created lazily.
	 *
	 * @var ServerClient|null
	 */
	private $client = null;

	/**
	 * Whether we already tried (and possibly failed) to create the client.
	 *
	 * @var bool
	 */
	private $attempted = false;

	/**
	 * Get a ready ServerClient, or null if not configured / init failed.
	 *
	 * @return ServerClient|null
	 */
	private function client() {
		if ( $this->attempted ) {
			return $this->client && $this->client->is_ready() ? $this->client : null;
		}
		$this->attempted = true;

		if ( ! Settings::is_configured() ) {
			return null;
		}

		$client = new ServerClient();
		if ( ! $client->init( Settings::project_api_key(), Settings::host() ) ) {
			return null;
		}

		$this->client = $client;

		add_action( 'shutdown', array( $this, 'flush' ), 100 );

		return $this->client;
	}

	/**
	 * Capture a server-side event if its setting is enabled.
	 *
	 * @param string              $event_key   Settings/event key (Schema key).
	 * @param string              $distinct_id Resolved distinct id.
	 * @param array<string,mixed> $properties  Event properties.
	 * @return void
	 */
	public function capture( $event_key, $distinct_id, array $properties = array() ) {
		if ( '' === (string) $distinct_id || ! Settings::is_server_event_enabled( $event_key ) ) {
			return;
		}

		$events = Schema::all_events();
		if ( ! isset( $events[ $event_key ] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		$properties = $this->prepare_properties( (string) $distinct_id, $properties );

		if ( ! $client->capture( $distinct_id, $events[ $event_key ], $properties ) ) {
			$this->log( 'capture ' . $event_key . ' failed: ' . $client->last_error() );
		}
	}

	/**
	 * Enrich event properties with request context and person-profile mode.
	 *
	 * @param string              $distinct_id Resolved distinct id.
	 * @param array<string,mixed> $properties  Base event properties.
	 * @return array<string,mixed>
	 */
	public function prepare_properties( $distinct_id, array $properties ) {
		$properties = $this->with_request_context( $properties );
		return $this->with_person_profile_mode( (string) $distinct_id, $properties );
	}

	/**
	 * Honor person_profiles: identified_only on server-side events.
	 *
	 * @param string              $distinct_id Resolved distinct id.
	 * @param array<string,mixed> $properties  Event properties.
	 * @return array<string,mixed>
	 */
	private function with_person_profile_mode( $distinct_id, array $properties ) {
		if ( isset( $properties['$process_person_profile'] ) ) {
			return $properties;
		}

		$client = Settings::client();
		$mode   = isset( $client['person_profiles'] ) ? (string) $client['person_profiles'] : 'identified_only';

		if ( 'identified_only' === $mode && ! Resolver::is_user_id( $distinct_id ) ) {
			$properties['$process_person_profile'] = false;
		}

		return $properties;
	}

	/**
	 * Attach proxy-safe visitor IP, raw user agent, and browser session id.
	 *
	 * @param array<string,mixed> $properties Event properties.
	 * @return array<string,mixed>
	 */
	private function with_request_context( array $properties ) {
		if ( ! isset( $properties['$raw_user_agent'] ) ) {
			$ua = RequestContext::user_agent();
			if ( '' !== $ua ) {
				$properties['$raw_user_agent'] = $ua;
			}
		}

		if ( ! isset( $properties['$ip'] ) ) {
			$ip = RequestContext::client_ip();
			if ( '' !== $ip ) {
				$properties['$ip'] = $ip;
			}
		}

		if ( ! isset( $properties['$session_id'] ) ) {
			$session_id = Identity::cookie_session_id();
			if ( null !== $session_id && '' !== $session_id ) {
				$properties['$session_id'] = $session_id;
			}
		}

		return $properties;
	}

	/**
	 * Identify a person server-side (respects the identity setting).
	 *
	 * @param string              $distinct_id Distinct id.
	 * @param array<string,mixed> $properties  Person properties.
	 * @return void
	 */
	public function identify( $distinct_id, array $properties = array() ) {
		$identity = Settings::identity();
		if ( empty( $identity['identify_logged_in'] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		if ( ! $client->identify( $distinct_id, $properties ) ) {
			$this->log( 'identify failed: ' . $client->last_error() );
		}
	}

	/**
	 * Merge a previous (anonymous) id into a person (respects identity setting).
	 *
	 * @param string $distinct_id Canonical id.
	 * @param string $alias       Previous id to merge.
	 * @return void
	 */
	public function alias( $distinct_id, $alias ) {
		$identity = Settings::identity();
		if ( empty( $identity['identify_logged_in'] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		if ( ! $client->alias( $distinct_id, $alias ) ) {
			$this->log( 'alias failed: ' . $client->last_error() );
		}
	}

	/**
	 * Flush queued events. Hooked to shutdown.
	 *
	 * @return void
	 */
	public function flush() {
		if ( $this->client && $this->client->is_ready() ) {
			$this->client->flush();
		}
	}

	/**
	 * Log a message only when debugging is on. Never surfaced to visitors.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'tagbridge: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
