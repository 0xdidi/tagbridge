<?php
/**
 * Tests for GlobalSettings and Options global slice persistence.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Platform;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( GlobalSettings::class )]
#[CoversClass( Options::class )]
final class GlobalSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
	}

	protected function tearDown(): void {
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_when_option_is_empty() {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame(
			array(
				'excluded_roles' => array(),
				'consent_mode'   => Policy::CONSENT_OFF,
				'respect_dnt'    => false,
			),
			GlobalSettings::all()
		);
		$this->assertSame( array(), GlobalSettings::excluded_roles() );
		$this->assertSame( Policy::CONSENT_OFF, GlobalSettings::consent_mode() );
		$this->assertFalse( GlobalSettings::respect_dnt() );
	}

	public function test_sanitize_filters_roles_validates_consent_mode_and_clears_unchecked_roles() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'global' => array(
					'excluded_roles' => array( 'administrator' ),
					'consent_mode'   => Policy::CONSENT_REQUIRE,
					'respect_dnt'    => true,
				),
			)
		);

		$sanitized = GlobalSettings::sanitize(
			array(
				'present'        => '1',
				'roles_present'  => '1',
				'excluded_roles' => array( 'Administrator', 'editor', 'invalid_role', 'editor' ),
				'consent_mode'   => 'bogus_mode',
			),
			array( 'administrator', 'editor', 'subscriber' )
		);

		$this->assertSame( array( 'administrator', 'editor' ), $sanitized['excluded_roles'] );
		$this->assertSame( Policy::CONSENT_OFF, $sanitized['consent_mode'] );
		$this->assertFalse( $sanitized['respect_dnt'] );

		$cleared = GlobalSettings::sanitize(
			array(
				'present'       => '1',
				'roles_present' => '1',
				'consent_mode'  => Policy::CONSENT_COOKIELESS,
				'respect_dnt'   => '1',
			)
		);

		$this->assertSame( array(), $cleared['excluded_roles'] );
		$this->assertSame( Policy::CONSENT_COOKIELESS, $cleared['consent_mode'] );
		$this->assertTrue( $cleared['respect_dnt'] );
	}

	public function test_save_global_data_preserves_existing_modules_and_posthog_slices() {
		$stored = array(
			'modules' => array( 'posthog' => true ),
			'posthog' => array( 'project_api_key' => 'phc_existing' ),
		);

		Functions\when( 'get_option' )->alias(
			function () use ( &$stored ) {
				return $stored;
			}
		);

		$saved_option = null;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$stored, &$saved_option ) {
				$saved_option = array(
					'key'   => $key,
					'value' => $value,
				);
				$stored       = $value;
				return true;
			}
		);

		GlobalSettings::save(
			array(
				'excluded_roles' => array( 'administrator' ),
				'consent_mode'   => Policy::CONSENT_REQUIRE,
				'respect_dnt'    => true,
			)
		);

		$this->assertSame( Options::OPTION_KEY, $saved_option['key'] );
		$this->assertSame( array( 'posthog' => true ), $saved_option['value']['modules'] );
		$this->assertSame( array( 'project_api_key' => 'phc_existing' ), $saved_option['value']['posthog'] );
		$this->assertSame( array( 'administrator' ), $saved_option['value']['global']['excluded_roles'] );
	}
}
