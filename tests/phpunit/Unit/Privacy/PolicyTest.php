<?php
/**
 * Tests for pure Core privacy rules and consent categories.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;

#[CoversClass( ConsentCategory::class )]
#[CoversClass( Policy::class )]
final class PolicyTest extends TestCase {

	public function test_consent_categories_include_wp_consent_api_standards() {
		$this->assertSame(
			array(
				'functional',
				'preferences',
				'statistics',
				'statistics-anonymous',
				'marketing',
			),
			ConsentCategory::all()
		);
		$this->assertTrue( ConsentCategory::is_valid( ConsentCategory::STATISTICS ) );
		$this->assertTrue( ConsentCategory::is_valid( ConsentCategory::MARKETING ) );
		$this->assertFalse( ConsentCategory::is_valid( 'unknown' ) );
	}

	public function test_is_valid_consent_mode() {
		$this->assertSame(
			array( 'off', 'require', 'cookieless_until_consent' ),
			Policy::consent_modes()
		);
		$this->assertTrue( Policy::is_valid_consent_mode( Policy::CONSENT_OFF ) );
		$this->assertTrue( Policy::is_valid_consent_mode( Policy::CONSENT_REQUIRE ) );
		$this->assertTrue( Policy::is_valid_consent_mode( Policy::CONSENT_COOKIELESS ) );
		$this->assertFalse( Policy::is_valid_consent_mode( 'invalid' ) );
	}

	public function test_role_exclusion_matches_case_insensitively() {
		$this->assertTrue( Policy::is_role_excluded( array( 'Administrator' ), array( 'administrator' ) ) );
		$this->assertTrue( Policy::is_role_excluded( array( 'subscriber', 'editor' ), array( 'administrator', 'editor' ) ) );
		$this->assertFalse( Policy::is_role_excluded( array( 'customer' ), array( 'administrator', 'shop_manager' ) ) );
		$this->assertFalse( Policy::is_role_excluded( array(), array( 'administrator' ) ) );
		$this->assertFalse( Policy::is_role_excluded( array( 'administrator' ), array() ) );
	}

	public function test_dnt_detection() {
		$this->assertTrue( Policy::is_dnt_enabled( '1' ) );
		$this->assertTrue( Policy::is_dnt_enabled( ' 1 ' ) );
		$this->assertFalse( Policy::is_dnt_enabled( '0' ) );
		$this->assertFalse( Policy::is_dnt_enabled( '' ) );
	}

	public function test_evaluate_consent_truth_table() {
		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_OFF, true, false )
		);

		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_REQUIRE, false, false )
		);
		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_COOKIELESS, false, false )
		);

		$this->assertSame(
			array(
				'allowed'    => false,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_REQUIRE, true, false )
		);
		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_REQUIRE, true, true )
		);

		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => true,
			),
			Policy::evaluate_consent( Policy::CONSENT_COOKIELESS, true, false )
		);
		$this->assertSame(
			array(
				'allowed'    => true,
				'cookieless' => false,
			),
			Policy::evaluate_consent( Policy::CONSENT_COOKIELESS, true, true )
		);
	}
}
