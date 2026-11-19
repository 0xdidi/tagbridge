<?php
/**
 * Tests for the shared EventBus.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Platform;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Modules\EventSubscriberInterface;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( EventBus::class )]
final class EventBusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->alias(
			function ( $category ) {
				return ConsentCategory::STATISTICS === $category;
			}
		);
	}

	protected function tearDown(): void {
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an anonymous EventSubscriberInterface spy.
	 *
	 * @param string   $id               Module id.
	 * @param string   $consent_category Consent category.
	 * @param callable $callback         Handler invoked on event.
	 * @return EventSubscriberInterface
	 */
	private function make_subscriber( $id, $consent_category, callable $callback ) {
		return new class( $id, $consent_category, $callback ) implements EventSubscriberInterface {
			/**
			 * Module id.
			 *
			 * @var string
			 */
			private $id;

			/**
			 * Consent category.
			 *
			 * @var string
			 */
			private $category;

			/**
			 * Event handler callback.
			 *
			 * @var callable
			 */
			private $callback;

			public function __construct( $id, $category, callable $callback ) {
				$this->id       = $id;
				$this->category = $category;
				$this->callback = $callback;
			}

			public function id() {
				return $this->id;
			}

			public function label() {
				return $this->id;
			}

			public function description() {
				return '';
			}

			public function icon() {
				return 'dashicons-admin-generic';
			}

			public function category() {
				return 'analytics';
			}

			public function consent_category() {
				return $this->category;
			}

			public function boot() {}

			public function handle_event( Event $event ) {
				call_user_func( $this->callback, $event );
			}
		};
	}

	public function test_fans_out_per_consent_category_and_isolates_failures() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'global' => array(
					'consent_mode'   => Policy::CONSENT_REQUIRE,
					'excluded_roles' => array(),
					'respect_dnt'    => false,
				),
			)
		);

		$received = array();
		$bus      = new EventBus();

		$bus->subscribe(
			$this->make_subscriber(
				'throwing_stats',
				ConsentCategory::STATISTICS,
				function () {
					throw new \RuntimeException( 'Simulated outage' );
				}
			)
		);

		$bus->subscribe(
			$this->make_subscriber(
				'denied_ads',
				ConsentCategory::MARKETING,
				function ( Event $event ) use ( &$received ) {
					$received['denied_ads'] = $event->name();
				}
			)
		);

		$bus->subscribe(
			$this->make_subscriber(
				'healthy_stats',
				ConsentCategory::STATISTICS,
				function ( Event $event ) use ( &$received ) {
					$received['healthy_stats'] = $event->id();
				}
			)
		);

		$event = new Event( 'product_viewed', array( 'product_id' => 99 ), array(), 'evt-abc' );
		$bus->dispatch( $event );

		$this->assertArrayNotHasKey( 'denied_ads', $received );
		$this->assertSame( 'evt-abc', $received['healthy_stats'] );
	}

	public function test_excluded_context_stops_all_subscribers() {
		Functions\when( 'get_option' )->justReturn( array() );

		$called = false;
		$bus    = new EventBus();
		$bus->subscribe(
			$this->make_subscriber(
				'stats',
				ConsentCategory::STATISTICS,
				function () use ( &$called ) {
					$called = true;
				}
			)
		);

		$bus->dispatch( new Event( 'order_completed', array(), array( 'excluded' => true ) ) );

		$this->assertFalse( $called );
	}

	public function test_skip_session_consent_respects_snapshot_denied_categories() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'global' => array(
					'consent_mode'   => Policy::CONSENT_COOKIELESS,
					'excluded_roles' => array(),
					'respect_dnt'    => false,
				),
			)
		);

		$seen = array();
		$bus  = new EventBus();

		$bus->subscribe(
			$this->make_subscriber(
				'stats',
				ConsentCategory::STATISTICS,
				function ( Event $event ) use ( &$seen ) {
					$seen['stats'] = $event->context( 'cookieless' );
				}
			)
		);

		$bus->subscribe(
			$this->make_subscriber(
				'ads',
				ConsentCategory::MARKETING,
				function ( Event $event ) use ( &$seen ) {
					$seen['ads'] = $event->context( 'cookieless' );
				}
			)
		);

		$event = new Event(
			'order_completed',
			array( 'value' => 50.0 ),
			array(
				'skip_session_consent'      => true,
				'denied_consent_categories' => array( ConsentCategory::MARKETING ),
				'excluded'                  => false,
			)
		);

		$bus->dispatch( $event );

		$this->assertFalse( $seen['stats'] );
		$this->assertTrue( $seen['ads'] );
	}
}
