<?php
/**
 * PostHog integration module.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Events\Schema;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Modules\PostHog\Connection\Validator;
use Tagbridge\Modules\PostHog\Events\Dispatcher;
use Tagbridge\Modules\PostHog\Frontend\Enqueue;
use Tagbridge\Platform\Modules\ConfigurableModule;
use Tagbridge\Platform\Modules\EventSubscriberInterface;

/**
 * The PostHog integration module.
 */
final class Module implements ConfigurableModule, EventSubscriberInterface {

	/**
	 * Stable module id. Used as the settings key and the input field prefix.
	 *
	 * @var string
	 */
	const ID = 'posthog';

	/**
	 * Order meta key storing the resolved distinct id at checkout time.
	 *
	 * @var string
	 */
	const ORDER_DISTINCT_ID_META = '_tagbridge_distinct_id';

	/**
	 * Lazy server-side event dispatcher.
	 *
	 * @var Dispatcher|null
	 */
	private $dispatcher = null;

	/**
	 * The module id.
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * The human-readable module name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'PostHog', 'tagbridge' );
	}

	/**
	 * A one-line description for the integrations list.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send pageviews, autocapture, identity, and server-side events to PostHog.', 'tagbridge' );
	}

	/**
	 * Official PostHog brand mark path relative to the plugin root.
	 *
	 * @return string
	 */
	public function icon() {
		return 'assets/icons/posthog.svg';
	}

	/**
	 * Integration category slug for grouping in the hub.
	 *
	 * @return string
	 */
	public function category() {
		return 'analytics';
	}

	/**
	 * WordPress Consent API category governing PostHog tracking.
	 *
	 * @return string
	 */
	public function consent_category() {
		return ConsentCategory::STATISTICS;
	}

	/**
	 * Wire the module's runtime hooks. Only called when the module is enabled.
	 *
	 * @return void
	 */
	public function boot() {
		( new Enqueue() )->register();

		add_filter( 'tagbridge_order_checkout_meta', array( $this, 'capture_order_distinct_id' ), 10, 2 );
	}

	/**
	 * Attach the current visitor's PostHog distinct id to a WooCommerce order at checkout.
	 *
	 * @param array<string,mixed> $meta  Existing order metadata collected from modules.
	 * @param object              $order WooCommerce order instance.
	 * @return array<string,mixed>
	 */
	public function capture_order_distinct_id( array $meta, $order ) {
		unset( $order );
		$meta[ self::ORDER_DISTINCT_ID_META ] = $this->visitor_distinct_id();
		return $meta;
	}

	/**
	 * Handle a normalized event dispatched from the shared EventBus.
	 *
	 * @param Event $event Normalized event.
	 * @return void
	 */
	public function handle_event( Event $event ) {
		$properties               = $event->properties();
		$properties['$insert_id'] = $event->id();
		$cookieless               = (bool) $event->context( 'cookieless', false );

		switch ( $event->name() ) {
			case Schema::USER_LOGGED_IN:
			case Schema::USER_REGISTERED:
				$user = $event->context( 'wp_user' );
				if ( ! is_object( $user ) || empty( $user->ID ) ) {
					$user_id = (int) $event->context( 'user_id', 0 );
					$user    = ( $user_id > 0 && function_exists( 'get_userdata' ) ) ? get_userdata( $user_id ) : null;
				}
				if ( ! is_object( $user ) || empty( $user->ID ) ) {
					return;
				}

				$distinct_id = Identity::stable_id_for_user( (int) $user->ID );
				if ( ! $cookieless ) {
					$this->sync_person( $user, $distinct_id );
				}

				$this->dispatcher()->capture( $event->name(), $distinct_id, $properties );
				break;

			case Schema::PRODUCT_VIEWED:
			case Schema::PRODUCT_ADDED_TO_CART:
				$this->dispatcher()->capture( $event->name(), $this->visitor_distinct_id(), $properties );
				break;

			case Schema::CHECKOUT_STARTED:
				$order       = $event->context( 'order' );
				$distinct_id = '';
				if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
					$distinct_id = (string) $order->get_meta( self::ORDER_DISTINCT_ID_META );
				}
				if ( '' === $distinct_id ) {
					$distinct_id = $this->visitor_distinct_id();
				}

				$this->dispatcher()->capture( Schema::CHECKOUT_STARTED, $distinct_id, $properties );
				break;

			case Schema::ORDER_COMPLETED:
				$order       = $event->context( 'order' );
				$distinct_id = '';
				if ( is_object( $order ) ) {
					$distinct_id = $this->order_distinct_id( $order );
				} else {
					$user_id     = (int) $event->context( 'user_id', 0 );
					$distinct_id = $user_id > 0 ? Identity::stable_id_for_user( $user_id ) : 'wc_order_' . (int) $event->context( 'order_id', 0 );
				}

				$this->dispatcher()->capture( Schema::ORDER_COMPLETED, $distinct_id, $properties );
				break;
		}
	}

	/**
	 * Lazy-initialize the server-side dispatcher.
	 *
	 * @return Dispatcher
	 */
	private function dispatcher() {
		if ( null === $this->dispatcher ) {
			$this->dispatcher = new Dispatcher();
		}
		return $this->dispatcher;
	}

	/**
	 * Identify a WordPress user and merge any prior anonymous browser activity.
	 *
	 * @param object $user        WordPress user object.
	 * @param string $distinct_id Stable distinct id.
	 * @return void
	 */
	private function sync_person( $user, $distinct_id ) {
		$identity = Identity::identity_for( $user );
		if ( null === $identity ) {
			return;
		}

		$this->dispatcher()->identify( $distinct_id, $identity['properties'] );

		$anon = Identity::cookie_distinct_id();
		if ( null !== $anon ) {
			$this->dispatcher()->alias( $distinct_id, $anon );
		}
	}

	/**
	 * Distinct id for an in-session visitor, falling back to a generated UUID.
	 *
	 * @return string
	 */
	private function visitor_distinct_id() {
		$resolved = Identity::server_distinct_id();
		return '' !== $resolved['distinct_id'] ? $resolved['distinct_id'] : wp_generate_uuid4();
	}

	/**
	 * Distinct id for an order, preferring the id saved at checkout, then the
	 * customer's stable id, then a deterministic per-order fallback.
	 *
	 * @param object $order WooCommerce order instance.
	 * @return string
	 */
	private function order_distinct_id( $order ) {
		if ( method_exists( $order, 'get_meta' ) ) {
			$saved = (string) $order->get_meta( self::ORDER_DISTINCT_ID_META );
			if ( '' !== $saved ) {
				return $saved;
			}
		}

		$customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		if ( $customer_id > 0 ) {
			return Identity::stable_id_for_user( $customer_id );
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		return 'wc_order_' . $order_id;
	}

	/**
	 * The module's default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function default_settings() {
		return Settings::defaults();
	}

	/**
	 * Sanitize a raw input array into clean settings.
	 *
	 * @param array<string,mixed> $raw Raw, untrusted input.
	 * @return array<string,mixed> Sanitized settings ready to save.
	 */
	public function sanitize( array $raw ) {
		return Settings::sanitize( $raw );
	}

	/**
	 * Validate sanitized settings before they are persisted.
	 *
	 * Runs a live connection check when a key is present, so a bad key is caught
	 * before it is saved. An empty key is allowed (the module is simply not yet
	 * configured) and saves without a network call.
	 *
	 * @param array<string,mixed> $sanitized Sanitized settings.
	 * @return array{ok:bool,message:string}
	 */
	public function before_save( array $sanitized ) {
		$key  = isset( $sanitized['project_api_key'] ) ? (string) $sanitized['project_api_key'] : '';
		$host = Host::resolve(
			isset( $sanitized['region'] ) ? (string) $sanitized['region'] : '',
			isset( $sanitized['custom_host'] ) ? (string) $sanitized['custom_host'] : ''
		);

		if ( '' === $key ) {
			return array(
				'ok'      => true,
				'message' => __( 'Settings saved.', 'tagbridge' ),
			);
		}

		$result = Validator::validate( $key, $host );
		if ( ! $result['ok'] ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connected and saved. PostHog will start receiving events on your site.', 'tagbridge' ),
		);
	}

	/**
	 * Whether the module has enough configuration to run.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return Settings::is_configured();
	}

	/**
	 * Render the body of the module's settings panel.
	 *
	 * The admin shell owns the page chrome, tab nav, nonce, and save flow; this
	 * renders the tab panels specific to PostHog. Field names are namespaced under
	 * the module id so the shell can route the saved data to this module.
	 *
	 * @return void
	 */
	public function render_settings() {
		( new SettingsPanel( self::ID ) )->render();
	}
}
