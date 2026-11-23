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
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 5 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_checkout' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * Order statuses that count as a completed/paid purchase.
	 *
	 * @return string[]
	 */
	public static function purchase_order_statuses() {
		$defaults = array( 'processing', 'completed' );

		/**
		 * Filter the WooCommerce order statuses that trigger order_completed.
		 *
		 * @param string[] $statuses Status slugs without the wc- prefix.
		 */
		$statuses = (array) apply_filters( 'tagbridge_purchase_order_statuses', $defaults );
		return array_values( array_filter( array_map( 'strval', $statuses ) ) );
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

		$properties = $this->product_properties( $product );

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
	 * @param int    $variation_id  Optional variation id.
	 * @param array  $variation     Optional variation attributes.
	 * @return void
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array() ) {
		unset( $cart_item_key, $variation );

		$variation_id = (int) $variation_id;
		$product      = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		$parent       = $variation_id > 0 ? wc_get_product( $product_id ) : $product;

		$properties = array(
			'product_id' => (int) $product_id,
			'name'       => $parent ? (string) $parent->get_name() : ( $product ? (string) $product->get_name() : '' ),
			'quantity'   => (int) $quantity,
			'currency'   => get_woocommerce_currency(),
		);

		if ( $variation_id > 0 ) {
			$properties['variation_id'] = $variation_id;
		}

		if ( $product && method_exists( $product, 'get_price' ) ) {
			$properties['price'] = (float) $product->get_price();
		}

		if ( $product && method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
			if ( '' !== $sku ) {
				$properties['sku'] = $sku;
			}
		}

		$category = $this->primary_category( (int) $product_id );
		if ( '' !== $category ) {
			$properties['category'] = $category;
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
	 * Trigger order_completed when an order transitions to a paid status.
	 *
	 * @param int         $order_id    Order id.
	 * @param string      $from_status Previous status slug.
	 * @param string      $to_status   New status slug.
	 * @param object|null $order       Optional WC_Order instance.
	 * @return void
	 */
	public function on_order_status_changed( $order_id, $from_status, $to_status, $order = null ) {
		unset( $from_status );
		if ( ! in_array( (string) $to_status, self::purchase_order_statuses(), true ) ) {
			return;
		}

		$this->on_order_completed( $order_id, $order );
	}

	/**
	 * Capture a completed order once (idempotent across repeated status transitions).
	 *
	 * Evaluates exclusion against the order/customer snapshot rather than the
	 * active admin session so an administrator marking a customer order complete
	 * still tracks the customer's revenue when appropriate.
	 *
	 * @param int         $order_id Order id.
	 * @param object|null $order    Optional WC_Order instance.
	 * @return void
	 */
	public function on_order_completed( $order_id, $order = null ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			$order = wc_get_order( $order_id );
		}
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
		$total = (float) $order->get_total();

		$properties = array(
			'order_id'   => (int) $order->get_id(),
			'value'      => $total,
			'revenue'    => $total,
			'currency'   => (string) $order->get_currency(),
			'item_count' => (int) $order->get_item_count(),
		);

		if ( method_exists( $order, 'get_shipping_total' ) ) {
			$properties['shipping'] = (float) $order->get_shipping_total();
		}

		if ( method_exists( $order, 'get_total_tax' ) ) {
			$properties['tax'] = (float) $order->get_total_tax();
		}

		if ( method_exists( $order, 'get_discount_total' ) ) {
			$properties['discount'] = (float) $order->get_discount_total();
		}

		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			$coupons = array_filter( array_map( 'strval', (array) $order->get_coupon_codes() ) );
			if ( ! empty( $coupons ) ) {
				$properties['coupon'] = implode( ', ', $coupons );
			}
		}

		if ( method_exists( $order, 'get_items' ) ) {
			$products = array();
			foreach ( (array) $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
					continue;
				}
				$pid      = (int) $item->get_product_id();
				$qty      = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
				$line_tot = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
				$product  = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

				$entry = array(
					'product_id' => $pid,
					'name'       => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
					'quantity'   => $qty,
					'price'      => round( $line_tot / $qty, 2 ),
				);

				if ( $product && method_exists( $product, 'get_sku' ) ) {
					$sku = (string) $product->get_sku();
					if ( '' !== $sku ) {
						$entry['sku'] = $sku;
					}
				}

				$category = $this->primary_category( $pid );
				if ( '' !== $category ) {
					$entry['category'] = $category;
				}

				$products[] = $entry;
			}
			if ( ! empty( $products ) ) {
				$properties['products'] = $products;
			}
		}

		return $properties;
	}

	/**
	 * Build normalized properties for a product object.
	 *
	 * @param object $product WC_Product instance.
	 * @return array<string,mixed>
	 */
	private function product_properties( $product ) {
		$product_id = (int) $product->get_id();
		$properties = array(
			'product_id' => $product_id,
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

		$category = $this->primary_category( $product_id );
		if ( '' !== $category ) {
			$properties['category'] = $category;
		}

		return $properties;
	}

	/**
	 * Primary product category name, or '' if unavailable.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function primary_category( $product_id ) {
		if ( $product_id <= 0 || ! function_exists( 'get_the_terms' ) ) {
			return '';
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$first = reset( $terms );
		return ( is_object( $first ) && isset( $first->name ) ) ? (string) $first->name : '';
	}
}
