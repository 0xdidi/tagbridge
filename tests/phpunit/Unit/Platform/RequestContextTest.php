<?php
/**
 * Tests for RequestContext.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Platform;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Platform\Http\RequestContext;

#[CoversClass( RequestContext::class )]
final class RequestContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $v ) {
				return trim( (string) $v );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_prefers_cf_connecting_ip_over_x_real_ip_and_remote_addr() {
		$ip = RequestContext::client_ip(
			array(
				'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
				'HTTP_X_REAL_IP'        => '198.51.100.20',
				'REMOTE_ADDR'           => '192.0.2.30',
			)
		);
		$this->assertSame( '203.0.113.10', $ip );
	}

	public function test_extracts_trustworthy_hop_from_x_forwarded_for() {
		$ip = RequestContext::client_ip(
			array(
				'HTTP_X_FORWARDED_FOR' => '198.51.100.5, 203.0.113.9, 10.0.0.1',
				'REMOTE_ADDR'          => '10.0.0.1',
			)
		);
		$this->assertSame( '203.0.113.9', $ip );
	}

	public function test_rejects_private_remote_addr() {
		$ip = RequestContext::client_ip(
			array(
				'REMOTE_ADDR' => '127.0.0.1',
			)
		);
		$this->assertSame( '', $ip );
	}

	public function test_accepts_public_remote_addr() {
		$ip = RequestContext::client_ip(
			array(
				'REMOTE_ADDR' => '198.51.100.42',
			)
		);
		$this->assertSame( '198.51.100.42', $ip );
	}

	public function test_user_agent_is_sanitized() {
		$ua = RequestContext::user_agent(
			array(
				'HTTP_USER_AGENT' => ' Mozilla/5.0 ',
			)
		);
		$this->assertSame( 'Mozilla/5.0', $ua );
	}
}
