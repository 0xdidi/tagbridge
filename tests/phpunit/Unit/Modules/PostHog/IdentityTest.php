<?php
/**
 * Tests for PostHog Identity (consent-guarded cookie reading, session ID, WC session fallback).
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Modules\PostHog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Identity\Resolver;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Identity::class )]
final class IdentityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $v ) {
				return trim( (string) $v );
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cookie_distinct_id_and_session_id_are_blocked_without_consent() {
		$now                     = (int) round( microtime( true ) * 1000 );
		$cookie_name             = Resolver::cookie_name( 'phc_key_1' );
		$_COOKIE[ $cookie_name ] = rawurlencode( '{"distinct_id":"anon-1","$sesid":[' . ( $now - 1000 ) . ',"sess-1",' . ( $now - 5000 ) . ']}' );

		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array( 'project_api_key' => 'phc_key_1' ),
				'global'  => array( 'consent_mode' => Policy::CONSENT_REQUIRE ),
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( false );

		$this->assertNull( Identity::cookie_distinct_id() );
		$this->assertNull( Identity::cookie_session_id() );
	}

	public function test_cookie_distinct_id_and_session_id_return_values_when_consented() {
		$now                     = (int) round( microtime( true ) * 1000 );
		$cookie_name             = Resolver::cookie_name( 'phc_key_1' );
		$_COOKIE[ $cookie_name ] = rawurlencode( '{"distinct_id":"anon-1","$sesid":[' . ( $now - 1000 ) . ',"sess-1",' . ( $now - 5000 ) . ']}' );

		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array( 'project_api_key' => 'phc_key_1' ),
				'global'  => array( 'consent_mode' => Policy::CONSENT_REQUIRE ),
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( true );

		$this->assertSame( 'anon-1', Identity::cookie_distinct_id() );
		$this->assertSame( 'sess-1', Identity::cookie_session_id() );
	}

	public function test_server_visitor_distinct_id_returns_empty_instead_of_random_uuid() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array( 'project_api_key' => 'phc_key_1' ),
				'global'  => array( 'consent_mode' => Policy::CONSENT_OFF ),
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertSame( '', Identity::server_visitor_distinct_id() );
	}
}
