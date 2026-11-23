<?php
/**
 * Tests for PostHog Dispatcher property enrichment and person_profiles conformance.
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
use Tagbridge\Modules\PostHog\Events\Dispatcher;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Dispatcher::class )]
final class DispatcherTest extends TestCase {

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
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sets_process_person_profile_false_for_anonymous_ids_when_identified_only() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array(
					'project_api_key' => 'phc_key_1',
					'client'          => array( 'person_profiles' => 'identified_only' ),
				),
				'global'  => array( 'consent_mode' => Policy::CONSENT_OFF ),
			)
		);

		$dispatcher = new Dispatcher();
		$anon_props = $dispatcher->prepare_properties( '0190-anon-id', array() );
		$user_props = $dispatcher->prepare_properties( 'wp_deadbeef', array() );

		$this->assertFalse( $anon_props['$process_person_profile'] );
		$this->assertArrayNotHasKey( '$process_person_profile', $user_props );
	}

	public function test_omits_process_person_profile_when_mode_is_always() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array(
					'project_api_key' => 'phc_key_1',
					'client'          => array( 'person_profiles' => 'always' ),
				),
				'global'  => array( 'consent_mode' => Policy::CONSENT_OFF ),
			)
		);

		$dispatcher = new Dispatcher();
		$props      = $dispatcher->prepare_properties( '0190-anon-id', array() );

		$this->assertArrayNotHasKey( '$process_person_profile', $props );
	}

	public function test_stamps_session_id_ip_and_user_agent_onto_server_events() {
		$now                              = (int) round( microtime( true ) * 1000 );
		$cookie_name                      = Resolver::cookie_name( 'phc_key_1' );
		$_COOKIE[ $cookie_name ]          = rawurlencode( '{"distinct_id":"anon-1","$sesid":[' . ( $now - 1000 ) . ',"0190-sess",' . ( $now - 5000 ) . ']}' );
		$_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 Test';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.55';

		Functions\when( 'get_option' )->justReturn(
			array(
				'posthog' => array( 'project_api_key' => 'phc_key_1' ),
				'global'  => array( 'consent_mode' => Policy::CONSENT_OFF ),
			)
		);

		$dispatcher = new Dispatcher();
		$props      = $dispatcher->prepare_properties( 'anon-1', array() );

		$this->assertSame( '0190-sess', $props['$session_id'] );
		$this->assertSame( '203.0.113.55', $props['$ip'] );
		$this->assertSame( 'Mozilla/5.0 Test', $props['$raw_user_agent'] );
	}
}
