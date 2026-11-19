<?php
/**
 * Shared platform EventBus.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Events;

use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Modules\EventSubscriberInterface;
use Tagbridge\Platform\Privacy\PrivacyGate;
use Tagbridge\Platform\Settings\GlobalSettings;

/**
 * Fans normalized events out to enabled subscriber modules.
 */
final class EventBus {

	/**
	 * Registered subscriber modules, keyed by module id.
	 *
	 * @var array<string,EventSubscriberInterface>
	 */
	private $subscribers = array();

	/**
	 * Subscribe an enabled module to receive dispatched events.
	 *
	 * @param EventSubscriberInterface $subscriber Subscribed module instance.
	 * @return void
	 */
	public function subscribe( EventSubscriberInterface $subscriber ) {
		$this->subscribers[ $subscriber->id() ] = $subscriber;
	}

	/**
	 * All currently subscribed modules, keyed by id.
	 *
	 * @return array<string,EventSubscriberInterface>
	 */
	public function subscribers() {
		return $this->subscribers;
	}

	/**
	 * Whether at least one subscriber module is registered.
	 *
	 * @return bool
	 */
	public function has_subscribers() {
		return ! empty( $this->subscribers );
	}

	/**
	 * Dispatch a normalized event to all eligible subscribers.
	 *
	 * @param Event $event Event to dispatch.
	 * @return void
	 */
	public function dispatch( Event $event ) {
		/**
		 * Fires immediately before an event is evaluated and fanned out.
		 *
		 * @param Event $event Normalized event.
		 */
		do_action( 'tagbridge_before_dispatch_event', $event );

		/**
		 * Filter a normalized event before it is routed to subscribers.
		 *
		 * Returning null or a non-Event cancels delivery.
		 *
		 * @param Event $event Normalized event.
		 */
		$event = apply_filters( 'tagbridge_event', $event );
		if ( ! $event instanceof Event ) {
			return;
		}

		if ( (bool) $event->context( 'excluded', false ) ) {
			return;
		}

		if ( empty( $this->subscribers ) ) {
			return;
		}

		$skip_session_consent = (bool) $event->context( 'skip_session_consent', false );
		$denied_categories    = $event->context( 'denied_consent_categories', array() );
		$denied_categories    = is_array( $denied_categories ) ? $denied_categories : array();
		$wp_user              = $event->context( 'wp_user', null );
		$consent_mode         = GlobalSettings::consent_mode();

		foreach ( $this->subscribers as $subscriber ) {
			$category = $subscriber->consent_category();

			if ( $skip_session_consent ) {
				$has_consent = ! in_array( $category, $denied_categories, true );
				$eval        = Policy::evaluate_consent( $consent_mode, ! $has_consent, $has_consent );
			} else {
				$eval = PrivacyGate::decision( $category, $wp_user );
			}

			if ( empty( $eval['allowed'] ) ) {
				continue;
			}

			$scoped_event = $event->with_context(
				array(
					'cookieless' => ! empty( $eval['cookieless'] ),
				)
			);

			try {
				$subscriber->handle_event( $scoped_event );
			} catch ( \Throwable $e ) {
				$this->log( $subscriber->id() . ' failed on ' . $event->name() . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Log subscriber errors only when WP_DEBUG is enabled.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'tagbridge: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
