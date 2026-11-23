<?php
/**
 * Tests for the Webhooks module (settings, payload, HMAC signature, dispatch).
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Modules\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Events\Schema;
use Tagbridge\Modules\Webhooks\Dispatcher;
use Tagbridge\Modules\Webhooks\Module;
use Tagbridge\Modules\Webhooks\Settings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Module::class )]
#[CoversClass( Settings::class )]
#[CoversClass( Dispatcher::class )]
final class WebhooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		Settings::flush_cache();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'home_url' )->justReturn( 'https://store.example.com' );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $v ) {
				return trim( (string) $v );
			}
		);
	}

	protected function tearDown(): void {
		Options::flush_cache();
		Settings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_payload_and_hmac_signature() {
		$event   = new Event(
			Schema::ORDER_COMPLETED,
			array(
				'order_id' => 99,
				'value'    => 42.5,
			),
			array( 'source' => 'woocommerce' ),
			'uuid-99',
			1700000000
		);
		$payload = Dispatcher::build_payload( $event );

		$this->assertSame( 'uuid-99', $payload['event_id'] );
		$this->assertSame( 'order_completed', $payload['event'] );
		$this->assertSame( '2023-11-14T22:13:20+00:00', $payload['timestamp'] );
		$this->assertSame( 'https://store.example.com', $payload['site_url'] );
		$this->assertSame( 'woocommerce', $payload['source'] );
		$this->assertSame( 99, $payload['properties']['order_id'] );

		$sig = Dispatcher::sign( '{"hello":"world"}', 'whsec_test' );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', '{"hello":"world"}', 'whsec_test' ), $sig );
	}

	public function test_send_posts_signed_payload_and_respects_per_event_toggle() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'webhooks' => array(
					'endpoint_url' => 'https://hooks.example.com/in',
					'secret'       => 'my-secret',
					'events'       => array(
						Schema::ORDER_COMPLETED => true,
						Schema::PRODUCT_VIEWED  => false,
					),
				),
			)
		);

		$captured = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$skipped = Dispatcher::send( new Event( Schema::PRODUCT_VIEWED, array( 'product_id' => 5 ) ), false );
		$this->assertFalse( $skipped['ok'] );
		$this->assertCount( 0, $captured );

		$sent = Dispatcher::send( new Event( Schema::ORDER_COMPLETED, array( 'order_id' => 12 ), array(), 'ev-12' ), true );
		$this->assertTrue( $sent['ok'] );
		$this->assertSame( 200, $sent['code'] );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://hooks.example.com/in', $captured[0]['url'] );
		$this->assertSame(
			Dispatcher::sign( $captured[0]['args']['body'], 'my-secret' ),
			$captured[0]['args']['headers']['X-Tagbridge-Signature']
		);
	}

	public function test_module_before_save_validates_url() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				$scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );
				if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
					return '';
				}
				return (string) $url;
			}
		);
		$module = new Module();

		$sanitized_bad = $module->sanitize( array( 'endpoint_url' => 'ftp://invalid.example.com' ) );
		$bad           = $module->before_save( $sanitized_bad );
		$this->assertFalse( $bad['ok'] );

		$sanitized_good = $module->sanitize( array( 'endpoint_url' => 'https://hooks.example.com/path' ) );
		$good           = $module->before_save( $sanitized_good );
		$this->assertTrue( $good['ok'] );

		$empty = $module->before_save( $module->sanitize( array( 'endpoint_url' => '' ) ) );
		$this->assertTrue( $empty['ok'] );
	}
}
