<?php
/**
 * Tests for the Core Event value object.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Events\Event;

#[CoversClass( Event::class )]
final class EventTest extends TestCase {

	public function test_generates_unique_uuid_v4_ids() {
		$first  = new Event( 'order_completed' );
		$second = new Event( 'order_completed' );

		$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
		$this->assertMatchesRegularExpression( $pattern, $first->id() );
		$this->assertMatchesRegularExpression( $pattern, $second->id() );
		$this->assertNotSame( $first->id(), $second->id() );
	}

	public function test_preserves_explicit_id_and_timestamp() {
		$event = new Event(
			'user_logged_in',
			array( 'login_source' => 'wordpress' ),
			array( 'user_id' => 7 ),
			'custom-event-id',
			1700000000
		);

		$this->assertSame( 'custom-event-id', $event->id() );
		$this->assertSame( 'user_logged_in', $event->name() );
		$this->assertSame( array( 'login_source' => 'wordpress' ), $event->properties() );
		$this->assertSame( 1700000000, $event->timestamp() );
	}

	public function test_context_returns_full_array_or_key_with_fallback() {
		$event = new Event(
			'product_viewed',
			array(),
			array(
				'source' => 'woocommerce',
				'flag'   => false,
			)
		);

		$this->assertSame(
			array(
				'source' => 'woocommerce',
				'flag'   => false,
			),
			$event->context()
		);
		$this->assertSame( 'woocommerce', $event->context( 'source' ) );
		$this->assertFalse( $event->context( 'flag', true ) );
		$this->assertSame( 'default-val', $event->context( 'missing', 'default-val' ) );
	}

	public function test_with_context_returns_immutable_clone() {
		$original = new Event( 'checkout_started', array( 'value' => 42.0 ), array( 'source' => 'woocommerce' ) );
		$cloned   = $original->with_context( array( 'cookieless' => true ) );

		$this->assertNotSame( $original, $cloned );
		$this->assertNull( $original->context( 'cookieless' ) );
		$this->assertTrue( $cloned->context( 'cookieless' ) );
		$this->assertSame( 'woocommerce', $cloned->context( 'source' ) );
		$this->assertSame( $original->id(), $cloned->id() );
	}

	public function test_to_array_exports_public_payload() {
		$event = new Event(
			'order_completed',
			array( 'value' => 99.5 ),
			array( 'secret' => 'internal' ),
			'evt-1',
			1700000123
		);

		$this->assertSame(
			array(
				'id'         => 'evt-1',
				'name'       => 'order_completed',
				'properties' => array( 'value' => 99.5 ),
				'timestamp'  => 1700000123,
			),
			$event->to_array()
		);
	}
}
