<?php
/**
 * Tests for the WooCommerce Event Source (idempotency, privacy snapshots, admin-safe completion).
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Platform;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Events\Schema;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Events\Sources\WooCommerceSource;
use Tagbridge\Platform\Modules\EventSubscriberInterface;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( WooCommerceSource::class )]
final class WooCommerceSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();
	}

	protected function tearDown(): void {
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create a fake HPOS-style order object.
	 *
	 * @param int                 $id          Order id.
	 * @param int                 $customer_id Customer user id.
	 * @param float               $total       Order total.
	 * @param array<string,mixed> $meta        Initial meta values.
	 * @return object
	 */
	private function make_order( $id, $customer_id = 0, $total = 49.50, array $meta = array() ) {
		return new class( $id, $customer_id, $total, $meta ) {
			/**
			 * Order id.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * Customer user id.
			 *
			 * @var int
			 */
			private $customer_id;

			/**
			 * Order total.
			 *
			 * @var float
			 */
			private $total;

			/**
			 * Order metadata map.
			 *
			 * @var array<string,mixed>
			 */
			public $meta;

			/**
			 * Number of save() calls.
			 *
			 * @var int
			 */
			public $saves = 0;

			public function __construct( $id, $customer_id, $total, array $meta ) {
				$this->id          = $id;
				$this->customer_id = $customer_id;
				$this->total       = $total;
				$this->meta        = $meta;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_customer_id() {
				return $this->customer_id;
			}

			public function get_total() {
				return $this->total;
			}

			public function get_currency() {
				return 'USD';
			}

			public function get_item_count() {
				return 2;
			}

			public function get_meta( $key ) {
				return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
			}

			public function update_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}

			public function save() {
				++$this->saves;
			}
		};
	}

	/**
	 * Create a subscriber collecting dispatched Event objects.
	 *
	 * @param string $consent_category Consent category.
	 * @param array  $log              Reference array receiving events.
	 * @return EventSubscriberInterface
	 */
	private function make_collector( $consent_category, array &$log ) {
		return new class( $consent_category, $log ) implements EventSubscriberInterface {
			/**
			 * Consent category.
			 *
			 * @var string
			 */
			private $category;

			/**
			 * Collected events log.
			 *
			 * @var array<int,Event>
			 */
			private $log;

			public function __construct( $category, array &$log ) {
				$this->category = $category;
				$this->log      = &$log;
			}

			public function id() {
				return 'collector_' . $this->category;
			}

			public function label() {
				return 'Collector';
			}

			public function description() {
				return '';
			}

			public function icon() {
				return 'dashicons-yes';
			}

			public function category() {
				return 'analytics';
			}

			public function consent_category() {
				return $this->category;
			}

			public function boot() {}

			public function handle_event( Event $event ) {
				$this->log[] = $event;
			}
		};
	}

	public function test_order_completed_is_idempotent_across_repeated_status_transitions() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->justReturn( true );

		$order = $this->make_order( 101, 0, 120.00 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$events = array();
		$bus    = new EventBus();
		$bus->subscribe( $this->make_collector( ConsentCategory::STATISTICS, $events ) );

		$source = new WooCommerceSource( $bus );
		$source->on_order_completed( 101 );
		$source->on_order_completed( 101 );

		$this->assertCount( 1, $events );
		$this->assertSame( Schema::ORDER_COMPLETED, $events[0]->name() );
		$this->assertSame( 1, $order->saves );
		$this->assertNotSame( '', $order->get_meta( WooCommerceSource::ORDER_COMPLETED_TRACKED_META ) );
	}

	public function test_checkout_persists_privacy_snapshot_and_module_meta() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'global' => array(
					'consent_mode'   => Policy::CONSENT_REQUIRE,
					'excluded_roles' => array( 'administrator' ),
					'respect_dnt'    => false,
				),
			)
		);

		$buyer = (object) array(
			'ID'    => 12,
			'roles' => array( 'administrator' ),
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( $buyer );
		Functions\when( 'get_userdata' )->justReturn( $buyer );
		Functions\when( 'wp_has_consent' )->alias(
			function ( $category ) {
				return ConsentCategory::MARKETING !== $category;
			}
		);

		Filters\expectApplied( 'tagbridge_order_checkout_meta' )
			->once()
			->andReturn( array( '_tagbridge_distinct_id' => 'anon-ph-id' ) );

		$order = $this->make_order( 201, 12, 75.00 );

		$events = array();
		$bus    = new EventBus();
		$bus->subscribe( $this->make_collector( ConsentCategory::STATISTICS, $events ) );

		$source = new WooCommerceSource( $bus );
		$source->on_checkout( 201, array(), $order );

		$this->assertCount( 0, $events );
		$this->assertSame( '1', $order->get_meta( WooCommerceSource::ORDER_EXCLUDED_META ) );
		$this->assertSame( array( ConsentCategory::MARKETING ), $order->get_meta( WooCommerceSource::ORDER_DENIED_CONSENT_META ) );
		$this->assertSame( 'anon-ph-id', $order->get_meta( '_tagbridge_distinct_id' ) );
		$this->assertNotSame( '', $order->get_meta( WooCommerceSource::ORDER_CHECKOUT_TRACKED_META ) );
	}

	public function test_admin_completing_customer_order_tracks_customer_while_excluded_buyer_stays_excluded() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'global' => array(
					'consent_mode'   => Policy::CONSENT_OFF,
					'excluded_roles' => array( 'administrator' ),
					'respect_dnt'    => false,
				),
			)
		);

		$admin_user    = (object) array(
			'ID'    => 1,
			'roles' => array( 'administrator' ),
		);
		$customer_user = (object) array(
			'ID'    => 55,
			'roles' => array( 'customer' ),
		);

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( $admin_user );
		Functions\when( 'wp_has_consent' )->justReturn( true );
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) use ( $admin_user, $customer_user ) {
				return 55 === (int) $user_id ? $customer_user : $admin_user;
			}
		);

		$customer_order = $this->make_order( 301, 55, 89.00 );
		$excluded_order = $this->make_order( 302, 0, 15.00, array( WooCommerceSource::ORDER_EXCLUDED_META => '1' ) );

		Functions\when( 'wc_get_order' )->alias(
			function ( $order_id ) use ( $customer_order, $excluded_order ) {
				return 301 === (int) $order_id ? $customer_order : $excluded_order;
			}
		);

		$events = array();
		$bus    = new EventBus();
		$bus->subscribe( $this->make_collector( ConsentCategory::STATISTICS, $events ) );

		$source = new WooCommerceSource( $bus );
		$source->on_order_completed( 301 );
		$source->on_order_completed( 302 );

		$this->assertCount( 1, $events );
		$this->assertSame( 301, $events[0]->context( 'order_id' ) );
	}

	public function test_paid_status_triggers_order_completed_once_with_enriched_properties() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->justReturn( true );
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'name' => 'Apparel' ) ) );

		$rich_order = new class() {
			/**
			 * Order metadata.
			 *
			 * @var array<string,mixed>
			 */
			public $meta = array();

			public function get_id() {
				return 401;
			}

			public function get_customer_id() {
				return 0;
			}

			public function get_total() {
				return 115.00;
			}

			public function get_currency() {
				return 'EUR';
			}

			public function get_item_count() {
				return 2;
			}

			public function get_shipping_total() {
				return 10.00;
			}

			public function get_total_tax() {
				return 15.00;
			}

			public function get_discount_total() {
				return 10.00;
			}

			public function get_coupon_codes() {
				return array( 'SAVE10' );
			}

			public function get_items() {
				return array(
					new class() {
						public function get_product_id() {
							return 12;
						}

						public function get_name() {
							return 'Hoodie';
						}

						public function get_quantity() {
							return 2;
						}

						public function get_total() {
							return 100.00;
						}

						public function get_product() {
							return new class() {
								public function get_sku() {
									return 'HD-01';
								}
							};
						}
					},
				);
			}

			public function get_meta( $key ) {
				return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
			}

			public function update_meta_data( $key, $value ) {
				$this->meta[ $key ] = $value;
			}

			public function save() {
			}
		};

		$events = array();
		$bus    = new EventBus();
		$bus->subscribe( $this->make_collector( ConsentCategory::STATISTICS, $events ) );

		$source = new WooCommerceSource( $bus );
		$source->on_order_status_changed( 401, 'checkout-draft', 'pending', $rich_order );
		$source->on_order_status_changed( 401, 'pending', 'processing', $rich_order );
		$source->on_order_status_changed( 401, 'processing', 'completed', $rich_order );

		$this->assertCount( 1, $events );
		$props = $events[0]->properties();
		$this->assertSame( 115.0, $props['value'] );
		$this->assertSame( 115.0, $props['revenue'] );
		$this->assertSame( 10.0, $props['shipping'] );
		$this->assertSame( 15.0, $props['tax'] );
		$this->assertSame( 10.0, $props['discount'] );
		$this->assertSame( 'SAVE10', $props['coupon'] );
		$this->assertCount( 1, $props['products'] );
		$this->assertSame( 'HD-01', $props['products'][0]['sku'] );
		$this->assertSame( 'Apparel', $props['products'][0]['category'] );
		$this->assertSame( 50.0, $props['products'][0]['price'] );
	}
}
