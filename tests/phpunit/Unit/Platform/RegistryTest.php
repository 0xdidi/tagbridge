<?php
/**
 * Tests for the lazy module Registry.
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
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Modules\EventSubscriberInterface;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Registry::class )]
final class RegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
	}

	protected function tearDown(): void {
		Options::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_disabled_module_is_never_instantiated_during_meta_or_boot() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'modules' => array(
					'disabled_mod' => false,
				),
			)
		);

		$autoload_calls = array();
		$spy            = function ( $class_name ) use ( &$autoload_calls ) {
			if ( 0 === strpos( $class_name, 'Tagbridge\\Tests\\Fake\\' ) ) {
				$autoload_calls[] = $class_name;
			}
		};
		spl_autoload_register( $spy );

		try {
			$registry = new Registry(
				array(
					'disabled_mod' => array(
						'class'            => 'Tagbridge\\Tests\\Fake\\NeverLoadedModule',
						'default'          => true,
						'label'            => 'Disabled Mod',
						'description'      => 'Should stay unloaded.',
						'category'         => 'automation',
						'consent_category' => ConsentCategory::FUNCTIONAL,
						'icon'             => 'dashicons-admin-plugins',
					),
				)
			);

			$bus = new EventBus();
			$registry->boot_enabled( $bus );
			$meta = $registry->meta( 'disabled_mod' );

			$this->assertFalse( $registry->is_enabled( 'disabled_mod' ) );
			$this->assertFalse( $bus->has_subscribers() );
			$this->assertSame( array(), $autoload_calls );
			$this->assertSame( 'Disabled Mod', $meta['label'] );
			$this->assertSame( 'automation', $meta['category'] );
			$this->assertSame( ConsentCategory::FUNCTIONAL, $meta['consent_category'] );
		} finally {
			spl_autoload_unregister( $spy );
		}
	}

	public function test_enabled_subscriber_boots_and_subscribes_to_event_bus() {
		Functions\when( 'get_option' )->justReturn( array() );

		$subscriber = new class() implements EventSubscriberInterface {
			/**
			 * Whether boot() was called.
			 *
			 * @var bool
			 */
			public $booted = false;

			public function id() {
				return 'sample';
			}

			public function label() {
				return 'Sample';
			}

			public function description() {
				return 'Sample module';
			}

			public function icon() {
				return 'dashicons-yes';
			}

			public function category() {
				return 'analytics';
			}

			public function consent_category() {
				return ConsentCategory::STATISTICS;
			}

			public function boot() {
				$this->booted = true;
			}

			public function handle_event( Event $event ) {
				unset( $event );
			}
		};

		$class_name = get_class( $subscriber );
		$registry   = new Registry(
			array(
				'sample' => array(
					'class'   => $class_name,
					'default' => true,
				),
			)
		);

		$bus = new EventBus();
		$registry->boot_enabled( $bus );

		$instance = $registry->get( 'sample' );
		$this->assertTrue( $instance->booted );
		$this->assertTrue( $bus->has_subscribers() );
		$this->assertArrayHasKey( 'sample', $bus->subscribers() );
	}

	public function test_unknown_module_returns_null_and_false() {
		Functions\when( 'get_option' )->justReturn( array() );

		$registry = new Registry( array() );

		$this->assertFalse( $registry->has( 'missing' ) );
		$this->assertFalse( $registry->is_enabled( 'missing' ) );
		$this->assertNull( $registry->meta( 'missing' ) );
		$this->assertNull( $registry->get( 'missing' ) );
	}

	public function test_closure_metadata_is_deferred_until_meta_is_requested() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'modules' => array(
					'deferred' => false,
				),
			)
		);

		$calls    = 0;
		$registry = new Registry(
			array(
				'deferred' => array(
					'class'            => 'Tagbridge\\Tests\\Fake\\DeferredModule',
					'default'          => true,
					'label'            => static function () use ( &$calls ) {
						++$calls;
						return 'Deferred Label';
					},
					'description'      => static function () use ( &$calls ) {
						++$calls;
						return 'Deferred Description';
					},
					'category'         => 'analytics',
					'consent_category' => ConsentCategory::STATISTICS,
					'icon'             => 'dashicons-chart-line',
				),
			)
		);

		$registry->boot_enabled( new EventBus() );
		$this->assertSame( 0, $calls );

		$meta = $registry->meta( 'deferred' );
		$this->assertSame( 2, $calls );
		$this->assertSame( 'Deferred Label', $meta['label'] );
		$this->assertSame( 'Deferred Description', $meta['description'] );
	}
}
