<?php
/**
 * Main plugin controller.
 *
 * @package Tagbridge
 */

namespace Tagbridge\Platform;

use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Modules\PostHog\Module as PostHogModule;
use Tagbridge\Platform\Admin\Notices;
use Tagbridge\Platform\Admin\SettingsPage;
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Events\Sources\WooCommerceSource;
use Tagbridge\Platform\Events\Sources\WordPressSource;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Privacy\PrivacyGate;

/**
 * Wires the plugin together on init.
 *
 * Intentionally thin: it builds the module registry, registers the admin shell
 * and notices, and boots only the integration modules the site owner enabled.
 * A disabled module's runtime classes are never loaded.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already run, to guard against double-boot.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * The module registry.
	 *
	 * @var Registry|null
	 */
	private $registry = null;

	/**
	 * Shared platform event bus.
	 *
	 * @var EventBus|null
	 */
	private $event_bus = null;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for the singleton.
	 */
	private function __construct() {}

	/**
	 * The integration module manifest: id => metadata + class.
	 *
	 * Uses literal string keys and ::class references (which PHP resolves at
	 * compile time without triggering the autoloader), so a disabled module's
	 * files are never loaded on normal requests.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function manifest() {
		$manifest = array(
			'posthog' => array(
				'class'            => PostHogModule::class,
				'default'          => true,
				'label'            => static function () {
					return __( 'PostHog', 'tagbridge' );
				},
				'description'      => static function () {
					return __( 'Send pageviews, autocapture, identity, and server-side events to PostHog.', 'tagbridge' );
				},
				'category'         => 'analytics',
				'consent_category' => ConsentCategory::STATISTICS,
				'icon'             => 'assets/icons/posthog.svg',
			),
		);

		/**
		 * Filter the integration module manifest.
		 *
		 * @param array<string,array<string,mixed>> $manifest Module manifest.
		 */
		return (array) apply_filters( 'tagbridge_module_manifest', $manifest );
	}

	/**
	 * The module registry, built once per request.
	 *
	 * @return Registry
	 */
	public function registry() {
		if ( null === $this->registry ) {
			$this->registry = new Registry( $this->manifest() );
		}
		return $this->registry;
	}

	/**
	 * The shared event bus, built once per request.
	 *
	 * @return EventBus
	 */
	public function event_bus() {
		if ( null === $this->event_bus ) {
			$this->event_bus = new EventBus();
		}
		return $this->event_bus;
	}

	/**
	 * Initialise the plugin. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		PrivacyGate::register();

		$registry = $this->registry();

		( new SettingsPage( $registry ) )->register();
		( new Notices( $registry ) )->register();

		$bus = $this->event_bus();
		$registry->boot_enabled( $bus );

		if ( $bus->has_subscribers() ) {
			( new WordPressSource( $bus ) )->register();
			( new WooCommerceSource( $bus ) )->register();
		}
	}

	/**
	 * Activation callback. Kept side-effect-free and safe to re-run.
	 *
	 * @return void
	 */
	public static function activate() {
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
