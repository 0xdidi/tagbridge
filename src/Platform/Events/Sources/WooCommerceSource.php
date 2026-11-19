<?php
/**
 * WooCommerce Event Source.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Events\Sources;

use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Events\Schema;
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Privacy\PrivacyGate;

/**
 * Emits normalized WooCommerce events onto the shared EventBus.
 */
final class WooCommerceSource {

	/**
	 * Order meta recording that checkout_started was captured.
	 *
	 * @var string
	 */
	const ORDER_CHECKOUT_TRACKED_META = '_tagbridge_checkout_tracked';

	/**
	 * Order meta recording that order_completed was captured (idempotency).
	 *
	 * @var string
	 */
	const ORDER_COMPLETED_TRACKED_META = '_tagbridge_order_completed_tracked';

	/**
	 * Order meta recording whether the checkout visitor was excluded by privacy policy.
	 *
	 * @var string
	 */
	const ORDER_EXCLUDED_META = '_tagbridge_excluded';

	/**
	 * Order meta storing consent categories denied at checkout time.
	 *
	 * @var string
	 */
	const ORDER_DENIED_CONSENT_META = '_tagbridge_denied_consent';

	/**
	 * Shared event bus.
	 *
	 * @var EventBus
	 */
	private $bus;

	/**
	 * Constructor.
	 *
	 * @param EventBus $bus Shared event bus.
	 */
	public function __construct( EventBus $bus ) {
		$this->bus = $bus;
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register WooCommerce hooks when active.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'on_product_view' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_checkout' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );
	}

	/**
	 * Capture a single product page view.
	 *
	 * @return void
	 */
	public function on_product_view() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}

		$properties = array(
			'product_id' => (int) $product->get_id(),
			'name'       => (string) $product->get_name(),
			'price'      => (float) $product->get_price(),
			'currency'   => get_woocommerce_currency(),
		);

		if ( method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
			if ( '' !== $sku ) {
				$properties['sku'] = $sku;
			}
		}

		$this->bus->dispatch(
			new Event(
				Schema::PRODUCT_VIEWED,
				$properties,
				array(
					'source'     => 'woocommerce',
					'product_id' => (int) $product->get_id(),
				)
			)
		);
	}

	/**
	 * Capture an add-to-cart action.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity added.
	 * @return void
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity ) {
		$product    = wc_get_product( $product_id );
		$properties = array(
			'product_id' => (int) $product_id,
			'name'       => $product ? (string) $product->get_name() : '',
			'quantity'   => (int) $quantity,
			'currency'   => get_woocommerce_currency(),
		);

		if ( $product && method_exists( $product, 'get_price' ) ) {
			$properties['price'] = (float) $product->get_price();
		}

		$this->bus->dispatch(
			new Event(
				Schema::PRODUCT_ADDED_TO_CART,
				$properties,
				array(
					'source'     => 'woocommerce',
					'product_id' => (int) $product_id,
				)
			)
		);
	}

	/**
	 * Handle WooCommerce classic checkout submission.
	 *
	 * @param int         $order_id    Order id.
	 * @param array       $posted_data Posted checkout data.
	 * @param object|null $order       Optional WC_Order instance.
	 * @return void
	 */
	public function on_checkout( $order_id, $posted_data = array(), $order = null ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			$order = wc_get_order( $order_id );
		}

		$this->handle_checkout( $order );
	}

	/**
	 * Handle WooCommerce Store API (block) checkout submission.
	 *
	 * @param object|null $order WC_Order instance.
	 * @return void
	 */
	public function on_store_api_checkout( $order ) {
		$this->handle_checkout( $order );
	}

	/**
	 * Snapshot privacy & module metadata on the order and emit checkout_started.
	 *
	 * @param object|null $order WC_Order instance.
	 * @return void
	 */
	private function handle_checkout( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		if ( $order->get_meta( self::ORDER_CHECKOUT_TRACKED_META ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_CHECKOUT_TRACKED_META, (string) time() );

		$excluded    = PrivacyGate::is_user_excluded() || PrivacyGate::is_dnt_blocked();
		$customer_id = (int) $order->get_customer_id();
		if ( ! $excluded && $customer_id > 0 && function_exists( 'get_userdata' ) ) {
			$customer = get_userdata( $customer_id );
			if ( $customer ) {
				$excluded = PrivacyGate::is_user_excluded( $customer );
			}
		}

		if ( $excluded ) {
			$order->update_meta_data( self::ORDER_EXCLUDED_META, '1' );
		}

		$denied_consent = PrivacyGate::denied_consent_categories();
		if ( ! empty( $denied_consent ) ) {
			$order->update_meta_data( self::ORDER_DENIED_CONSENT_META, $denied_consent );
		}

		/**
		 * Allow integration modules to persist attribution or identity metadata on the order at checkout.
		 *
		 * @param array<string,mixed> $meta  Key-value pairs saved via $order->update_meta_data().
		 * @param object              $order The WooCommerce order object.
		 */
		$module_meta = (array) apply_filters( 'tagbridge_order_checkout_meta', array(), $order );
		foreach ( $module_meta as $meta_key => $meta_value ) {
			if ( is_string( $meta_key ) && '' !== $meta_key ) {
				$order->update_meta_data( $meta_key, $meta_value );
			}
		}

		$order->save();

		$this->bus->dispatch(
			new Event(
				Schema::CHECKOUT_STARTED,
				$this->order_properties( $order ),
				array(
					'source'   => 'woocommerce',
					'order_id' => (int) $order->get_id(),
					'order'    => $order,
					'user_id'  => $customer_id,
				)
			)
		);
	}

	/**
	 * Capture a completed order once (idempotent across repeated status transitions).
	 *
	 * Evaluates exclusion against the order/customer snapshot rather than the
	 * active admin session so an administrator marking a customer order complete
	 * still tracks the customer's revenue when appropriate.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		if ( $order->get_meta( self::ORDER_COMPLETED_TRACKED_META ) ) {
			return;
		}

		$excluded    = '1' === (string) $order->get_meta( self::ORDER_EXCLUDED_META );
		$customer_id = (int) $order->get_customer_id();
		if ( ! $excluded && $customer_id > 0 && function_exists( 'get_userdata' ) ) {
			$customer = get_userdata( $customer_id );
			if ( $customer ) {
				$excluded = PrivacyGate::is_user_excluded( $customer );
			}
		}

		if ( $excluded ) {
			return;
		}

		$order->update_meta_data( self::ORDER_COMPLETED_TRACKED_META, (string) time() );
		$order->save();

		$denied_consent = $order->get_meta( self::ORDER_DENIED_CONSENT_META );

		$this->bus->dispatch(
			new Event(
				Schema::ORDER_COMPLETED,
				$this->order_properties( $order ),
				array(
					'source'                    => 'woocommerce',
					'order_id'                  => (int) $order->get_id(),
					'order'                     => $order,
					'user_id'                   => $customer_id,
					'skip_session_consent'      => true,
					'denied_consent_categories' => is_array( $denied_consent ) ? $denied_consent : array(),
					'excluded'                  => false,
				)
			)
		);
	}

	/**
	 * Build standard order properties for commerce events.
	 *
	 * @param object $order WC_Order instance.
	 * @return array<string,mixed>
	 */
	private function order_properties( $order ) {
		return array(
			'order_id'   => (int) $order->get_id(),
			'value'      => (float) $order->get_total(),
			'currency'   => (string) $order->get_currency(),
			'item_count' => (int) $order->get_item_count(),
		);
	}
}
