<?php
/**
 * Webhooks integration module.
 *
 * @package Tagbridge\Modules\Webhooks
 */

namespace Tagbridge\Modules\Webhooks;

use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Platform\Modules\ConfigurableModule;
use Tagbridge\Platform\Modules\EventSubscriberInterface;

/**
 * Dispatches normalized WordPress and WooCommerce events to an HTTPS webhook.
 */
final class Module implements ConfigurableModule, EventSubscriberInterface {

	/**
	 * Stable module id.
	 *
	 * @var string
	 */
	const ID = 'webhooks';

	/**
	 * Admin-post action for sending a test webhook.
	 *
	 * @var string
	 */
	const ACTION_TEST = 'tagbridge_test_webhook';

	/**
	 * Nonce action/field name for sending a test webhook.
	 *
	 * @var string
	 */
	const NONCE_TEST = 'tagbridge_test_webhook';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Human-readable module name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Webhooks', 'tagbridge' );
	}

	/**
	 * One-line description for the integrations hub.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send signed JSON event payloads to Zapier, Make, n8n, or your own endpoint.', 'tagbridge' );
	}

	/**
	 * Icon path relative to plugin root.
	 *
	 * @return string
	 */
	public function icon() {
		return 'assets/icons/webhooks.svg';
	}

	/**
	 * Category slug.
	 *
	 * @return string
	 */
	public function category() {
		return 'automation';
	}

	/**
	 * WordPress Consent API category governing webhook delivery.
	 *
	 * @return string
	 */
	public function consent_category() {
		return ConsentCategory::FUNCTIONAL;
	}

	/**
	 * Register runtime hooks when the module is enabled.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_post_' . self::ACTION_TEST, array( $this, 'handle_test_webhook' ) );
	}

	/**
	 * Handle a normalized event from the shared EventBus.
	 *
	 * @param Event $event Normalized event.
	 * @return void
	 */
	public function handle_event( Event $event ) {
		Dispatcher::send( $event, false );
	}

	/**
	 * Handle the admin "Send test webhook" action.
	 *
	 * @return void
	 */
	public function handle_test_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE_TEST, 'tagbridge_test_webhook_nonce' );

		$test_event = new Event(
			'tagbridge_test_webhook',
			array(
				'test' => true,
			),
			array(
				'source' => 'tagbridge',
			)
		);

		$result = Dispatcher::send( $test_event, true );

		set_transient(
			'tagbridge_flash_' . get_current_user_id(),
			array(
				'type'    => ! empty( $result['ok'] ) ? 'success' : 'error',
				'message' => $result['message'],
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=tagbridge&module=' . self::ID ) );
		exit;
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function default_settings() {
		return Settings::defaults();
	}

	/**
	 * Sanitize untrusted input.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $raw ) {
		return Settings::sanitize( $raw );
	}

	/**
	 * Validate settings before saving.
	 *
	 * @param array<string,mixed> $sanitized Sanitized settings.
	 * @return array{ok:bool,message:string}
	 */
	public function before_save( array $sanitized ) {
		$url = isset( $sanitized['endpoint_url'] ) ? (string) $sanitized['endpoint_url'] : '';
		if ( '' === $url ) {
			return array(
				'ok'      => true,
				'message' => __( 'Webhook settings saved.', 'tagbridge' ),
			);
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please enter a valid http:// or https:// endpoint URL.', 'tagbridge' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Webhook settings saved.', 'tagbridge' ),
		);
	}

	/**
	 * Whether the module is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return Settings::is_configured();
	}

	/**
	 * Render the Webhooks settings UI.
	 *
	 * @return void
	 */
	public function render_settings() {
		( new SettingsPanel( $this->id() ) )->render();
	}
}
