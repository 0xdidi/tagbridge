<?php
/**
 * Tests for the upcoming integrations Catalog, local watchlist storage, and launch notices.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Platform;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Modules\PostHog\Module as PostHogModule;
use Tagbridge\Platform\Admin\Notices;
use Tagbridge\Platform\Modules\Catalog;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Settings\Options;

#[CoversClass( Catalog::class )]
#[CoversClass( Notices::class )]
#[CoversClass( Options::class )]
final class CatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::flush_cache();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $value ) {
				return 'http://example.com/wp-admin/?' . $key . '=' . $value;
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action ) {
				return $url . '&_wpnonce=nonce-' . $action;
			}
		);
	}

	protected function tearDown(): void {
		Options::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_upcoming_includes_planned_integrations_and_excludes_registered_ones() {
		$empty_registry = new Registry( array() );
		$all_upcoming   = Catalog::upcoming( $empty_registry );

		$this->assertArrayHasKey( 'webhooks', $all_upcoming );
		$this->assertArrayHasKey( 'ga4', $all_upcoming );
		$this->assertArrayHasKey( 'meta-capi', $all_upcoming );

		$shipped_registry = new Registry(
			array(
				'webhooks' => array(
					'class'            => 'Tagbridge\\Tests\\Fake\\WebhooksModule',
					'default'          => false,
					'label'            => 'Webhooks',
					'description'      => 'Shipped.',
					'category'         => 'automation',
					'consent_category' => ConsentCategory::FUNCTIONAL,
					'icon'             => 'dashicons-rest-api',
				),
			)
		);

		$filtered_upcoming = Catalog::upcoming( $shipped_registry );
		$this->assertArrayNotHasKey( 'webhooks', $filtered_upcoming );
		$this->assertArrayHasKey( 'ga4', $filtered_upcoming );
	}

	public function test_watchlist_storage_preserves_other_option_slices() {
		$stored = array(
			'modules' => array( 'posthog' => true ),
			'posthog' => array( 'project_api_key' => 'phc_keep' ),
			'global'  => array( 'respect_dnt' => true ),
		);

		Functions\when( 'get_option' )->alias(
			function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				unset( $key );
				$stored = $value;
				return true;
			}
		);

		$this->assertSame( array(), Options::watchlist() );
		$this->assertFalse( Options::is_watched( 'webhooks' ) );

		Options::set_watched( 'webhooks', true );
		Options::set_watched( 'ga4', true );
		Options::set_watched( 'webhooks', true );

		$this->assertTrue( Options::is_watched( 'webhooks' ) );
		$this->assertSame( array( 'webhooks', 'ga4' ), Options::watchlist() );
		$this->assertSame( array( 'posthog' => true ), $stored['modules'] );
		$this->assertSame( array( 'project_api_key' => 'phc_keep' ), $stored['posthog'] );
		$this->assertSame( array( 'respect_dnt' => true ), $stored['global'] );

		Options::set_watched( 'webhooks', false );

		$this->assertFalse( Options::is_watched( 'webhooks' ) );
		$this->assertSame( array( 'ga4' ), Options::watchlist() );
	}

	public function test_launched_watched_returns_only_watched_modules_present_in_registry() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'watchlist' => array( 'webhooks', 'ga4' ),
			)
		);

		$registry = new Registry(
			array(
				'webhooks' => array(
					'class'            => 'Tagbridge\\Tests\\Fake\\WebhooksModule',
					'default'          => false,
					'label'            => 'Webhooks',
					'description'      => 'Shipped.',
					'category'         => 'automation',
					'consent_category' => ConsentCategory::FUNCTIONAL,
					'icon'             => 'dashicons-rest-api',
				),
			)
		);

		$launched = Catalog::launched_watched( $registry );

		$this->assertArrayHasKey( 'webhooks', $launched );
		$this->assertArrayNotHasKey( 'ga4', $launched );
		$this->assertSame( 'Webhooks', $launched['webhooks']['label'] );
	}

	public function test_launched_notice_stays_silent_when_nothing_launched_and_renders_when_matched() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$stored = array(
			'watchlist' => array( 'ga4' ),
		);
		Functions\when( 'get_option' )->alias(
			function () use ( &$stored ) {
				return $stored;
			}
		);

		$registry = new Registry(
			array(
				'webhooks' => array(
					'class'            => 'Tagbridge\\Tests\\Fake\\WebhooksModule',
					'default'          => false,
					'label'            => 'Webhooks',
					'description'      => 'Shipped.',
					'category'         => 'automation',
					'consent_category' => ConsentCategory::FUNCTIONAL,
					'icon'             => 'dashicons-rest-api',
				),
			)
		);

		$notices = new Notices( $registry );

		ob_start();
		$notices->maybe_show_launched_notice();
		$empty_output = ob_get_clean();
		$this->assertSame( '', $empty_output );

		$stored['watchlist'] = array( 'webhooks' );
		Options::flush_cache();

		ob_start();
		$notices->maybe_show_launched_notice();
		$notice_output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $notice_output );
		$this->assertStringContainsString( 'Webhooks is now available in Tagbridge.', $notice_output );
		$this->assertStringContainsString( 'page=tagbridge&module=webhooks', $notice_output );
		$this->assertStringContainsString( Notices::DISMISS_LAUNCHED_ARG, $notice_output );
	}

	public function test_all_integration_logo_files_exist_and_are_valid_svgs() {
		$plugin_root = dirname( __DIR__, 4 );
		$icons       = array( ( new PostHogModule() )->icon() );

		foreach ( Catalog::all() as $item ) {
			$icons[] = $item['icon'];
		}

		$this->assertCount( 9, $icons );

		foreach ( $icons as $icon_path ) {
			$this->assertStringStartsWith( 'assets/icons/', $icon_path );
			$full_path = $plugin_root . '/' . $icon_path;
			$this->assertFileExists( $full_path );

			$svg = file_get_contents( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertIsString( $svg );
			$this->assertNotSame( '', trim( $svg ) );
			$this->assertStringContainsString( '<svg', strtolower( $svg ) );
			$this->assertStringNotContainsString( '<script', strtolower( $svg ) );
		}
	}
}
