<?php
/**
 * Tests for PostHog Feature Flag Evaluator and Block/Shortcode.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Modules\PostHog;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Events\ServerClient;
use Tagbridge\Modules\PostHog\Flags\Evaluator;
use Tagbridge\Modules\PostHog\Flags\FeatureFlagBlock;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Evaluator::class )]
#[CoversClass( FeatureFlagBlock::class )]
final class FlagsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Evaluator::flush_cache();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $k ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $k ) );
			}
		);
		Functions\when( 'shortcode_atts' )->alias(
			static function ( $pairs, $atts ) {
				return array_merge( $pairs, is_array( $atts ) ? $atts : array() );
			}
		);
		Functions\when( 'do_shortcode' )->returnArg();
	}

	protected function tearDown(): void {
		Evaluator::flush_cache();
		Options::flush_cache();
		GlobalSettings::flush_cache();
		Settings::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_matches_value_handles_booleans_and_multivariate_variants() {
		$this->assertTrue( Evaluator::matches_value( true, 'true' ) );
		$this->assertFalse( Evaluator::matches_value( false, 'true' ) );
		$this->assertTrue( Evaluator::matches_value( false, 'false' ) );
		$this->assertTrue( Evaluator::matches_value( 'variant-b', 'variant-b' ) );
		$this->assertFalse( Evaluator::matches_value( 'variant-a', 'variant-b' ) );
	}

	public function test_shortcode_and_block_respect_default_when_unconfigured() {
		Functions\when( 'get_option' )->justReturn( array() );
		$block = new FeatureFlagBlock();

		$this->assertSame( '', $block->render_shortcode( array( 'key' => 'new-hero' ), 'New Hero' ) );
		$this->assertSame(
			'Fallback Hero',
			$block->render_shortcode(
				array(
					'key'     => 'new-hero',
					'default' => 'true',
				),
				'Fallback Hero'
			)
		);

		$this->assertSame(
			'',
			$block->render_block(
				array(
					'flagKey'      => 'new-hero',
					'matchValue'   => 'true',
					'defaultValue' => false,
				),
				'<p>Beta</p>'
			)
		);
		$this->assertSame(
			'<p>Beta</p>',
			$block->render_block(
				array(
					'flagKey'      => 'new-hero',
					'matchValue'   => 'true',
					'defaultValue' => true,
				),
				'<p>Beta</p>'
			)
		);
	}

	public function test_server_client_get_feature_flag_returns_default_when_uninitialized() {
		$client = new ServerClient();
		$this->assertFalse( $client->get_feature_flag( 'my-flag', 'wp_123', false ) );
		$this->assertSame( 'control', $client->get_feature_flag( 'my-flag', 'wp_123', 'control' ) );
	}

	public function test_sanitize_flag_key_preserves_case() {
		$this->assertSame( 'NewHero_Banner-2', Evaluator::sanitize_flag_key( 'NewHero_Banner-2!@#' ) );
	}
}
