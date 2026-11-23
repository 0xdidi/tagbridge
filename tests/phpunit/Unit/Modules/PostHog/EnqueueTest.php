<?php
/**
 * Tests for PostHog frontend Enqueue privacy gating (role exclusions, DNT, WP Consent API).
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Modules\PostHog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Modules\PostHog\Frontend\Enqueue;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Enqueue::class )]
final class EnqueueTest extends TestCase {

	/**
	 * Captured inline scripts printed during the current test.
	 *
	 * @var array<int,array{js:string,attrs:array<string,string>}>
	 */
	private $printed = array();

	/**
	 * Original HTTP_DNT header value before the test.
	 *
	 * @var string|null
	 */
	private $original_dnt = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();

		$this->printed = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);

		$this->original_dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : null;
		unset( $_SERVER['HTTP_DNT'] );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $js, $attrs = array() ) {
				$this->printed[] = array(
					'js'    => (string) $js,
					'attrs' => (array) $attrs,
				);
			}
		);
	}

	protected function tearDown(): void {
		if ( null === $this->original_dnt ) {
			unset( $_SERVER['HTTP_DNT'] );
		} else {
			$_SERVER['HTTP_DNT'] = $this->original_dnt;
		}

		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper option payload with PostHog configured and custom global settings.
	 *
	 * @param array<string,mixed> $global_settings Global privacy settings override.
	 * @return array<string,mixed>
	 */
	private function configured_options( array $global_settings = array() ) {
		return array(
			'modules' => array( 'posthog' => true ),
			'posthog' => array(
				'project_api_key' => 'phc_test_123456',
				'region'          => 'us',
			),
			'global'  => $global_settings,
		);
	}

	public function test_prints_nothing_for_excluded_user_role() {
		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array( 'administrator' ),
					'consent_mode'   => Policy::CONSENT_OFF,
					'respect_dnt'    => false,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'user_email'   => 'admin@example.com',
				'display_name' => 'Admin',
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( true );

		( new Enqueue() )->print_snippet();

		$this->assertSame( array(), $this->printed );
	}

	public function test_prints_nothing_when_dnt_header_is_set_and_respected() {
		$_SERVER['HTTP_DNT'] = '1';

		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array(),
					'consent_mode'   => Policy::CONSENT_OFF,
					'respect_dnt'    => true,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->justReturn( true );

		( new Enqueue() )->print_snippet();

		$this->assertSame( array(), $this->printed );
	}

	public function test_require_consent_without_consent_prints_wait_stub_only() {
		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array(),
					'consent_mode'   => Policy::CONSENT_REQUIRE,
					'respect_dnt'    => false,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'           => 9,
				'roles'        => array( 'subscriber' ),
				'user_email'   => 'sub@example.com',
				'display_name' => 'Subscriber',
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( false );

		( new Enqueue() )->print_snippet();

		$this->assertCount( 1, $this->printed );
		$this->assertSame( 'tagbridge-posthog-consent', $this->printed[0]['attrs']['id'] );
		$this->assertStringContainsString( 'wp_listen_for_consent_change', $this->printed[0]['js'] );
		$this->assertStringNotContainsString( 'posthog.identify(', $this->printed[0]['js'] );
	}

	public function test_cookieless_until_consent_forces_memory_persistence_and_omits_identify() {
		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array(),
					'consent_mode'   => Policy::CONSENT_COOKIELESS,
					'respect_dnt'    => false,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'           => 9,
				'roles'        => array( 'subscriber' ),
				'user_email'   => 'sub@example.com',
				'display_name' => 'Subscriber',
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( false );

		( new Enqueue() )->print_snippet();

		$this->assertCount( 1, $this->printed );
		$this->assertSame( 'tagbridge-posthog-js', $this->printed[0]['attrs']['id'] );
		$this->assertStringContainsString( '"persistence":"memory"', $this->printed[0]['js'] );
		$this->assertStringContainsString( 'wp_listen_for_consent_change', $this->printed[0]['js'] );
		$this->assertStringNotContainsString( 'posthog.identify(', $this->printed[0]['js'] );
	}

	public function test_allowed_logged_in_visitor_prints_init_and_identify() {
		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array( 'administrator' ),
					'consent_mode'   => Policy::CONSENT_REQUIRE,
					'respect_dnt'    => false,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'           => 9,
				'roles'        => array( 'subscriber' ),
				'user_email'   => 'sub@example.com',
				'display_name' => 'Subscriber',
			)
		);
		Functions\when( 'wp_has_consent' )->justReturn( true );

		( new Enqueue() )->print_snippet();

		$this->assertCount( 1, $this->printed );
		$this->assertSame( 'tagbridge-posthog-js', $this->printed[0]['attrs']['id'] );
		$this->assertStringContainsString( 'posthog.init("phc_test_123456"', $this->printed[0]['js'] );
		$this->assertStringContainsString( 'posthog.identify("wp_', $this->printed[0]['js'] );
		$this->assertStringNotContainsString( '"persistence":"memory"', $this->printed[0]['js'] );
		$this->assertStringContainsString( '"capture_heatmaps":true', $this->printed[0]['js'] );
	}

	public function test_logout_cookie_triggers_posthog_reset_when_logged_out() {
		$_COOKIE[ Enqueue::RESET_COOKIE ] = '1';

		Functions\when( 'get_option' )->justReturn(
			$this->configured_options(
				array(
					'excluded_roles' => array(),
					'consent_mode'   => Policy::CONSENT_OFF,
					'respect_dnt'    => false,
				)
			)
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->justReturn( true );

		( new Enqueue() )->print_snippet();

		unset( $_COOKIE[ Enqueue::RESET_COOKIE ] );

		$this->assertCount( 1, $this->printed );
		$this->assertStringContainsString( 'posthog.reset();', $this->printed[0]['js'] );
	}

	public function test_session_recording_includes_masking_and_capture_exceptions() {
		$opts                      = $this->configured_options(
			array(
				'excluded_roles' => array(),
				'consent_mode'   => Policy::CONSENT_OFF,
				'respect_dnt'    => false,
			)
		);
		$opts['posthog']['client'] = array(
			'session_recording'  => true,
			'capture_exceptions' => true,
		);
		Functions\when( 'get_option' )->justReturn( $opts );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_has_consent' )->justReturn( true );

		( new Enqueue() )->print_snippet();

		$this->assertCount( 1, $this->printed );
		$this->assertStringContainsString( '"maskAllInputs":true', $this->printed[0]['js'] );
		$this->assertStringContainsString( '"maskTextSelector":".woocommerce-customer-details"', $this->printed[0]['js'] );
		$this->assertStringContainsString( '"capture_exceptions":true', $this->printed[0]['js'] );
	}
}
