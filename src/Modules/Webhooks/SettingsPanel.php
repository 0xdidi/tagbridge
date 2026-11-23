<?php
/**
 * Webhooks settings panel.
 *
 * @package Tagbridge\Modules\Webhooks
 */

namespace Tagbridge\Modules\Webhooks;

use Tagbridge\Core\Events\Schema;
use Tagbridge\Platform\Settings\Options;

/**
 * Renders the Webhooks module's settings UI.
 */
final class SettingsPanel {

	/**
	 * Module id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param string $id Module id.
	 */
	public function __construct( $id ) {
		$this->id = $id;
	}

	/**
	 * Tab definitions.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'endpoint' => __( 'Endpoint', 'tagbridge' ),
			'events'   => __( 'Events', 'tagbridge' ),
		);
	}

	/**
	 * Render the Webhooks settings tabs and panels.
	 *
	 * @return void
	 */
	public function render() {
		$configured    = Settings::is_configured();
		$enabled       = Options::is_module_enabled( $this->id );
		$endpoint_url  = Settings::endpoint_url();
		$secret        = Settings::secret();
		$events        = Settings::events();
		$last_delivery = Dispatcher::last_delivery();
		?>
		<nav class="tagbridge-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Webhooks settings', 'tagbridge' ); ?>">
			<?php
			$first = true;
			foreach ( $this->tabs() as $key => $label ) {
				$scoped = $this->id . '-' . $key;
				printf(
					'<button type="button" class="tagbridge-tab%1$s" id="tagbridge-tab-%2$s" role="tab" aria-controls="tagbridge-panel-%2$s" aria-selected="%3$s" tabindex="%4$s" data-tagbridge-tab="%2$s">%5$s</button>',
					$first ? ' is-active' : '',
					esc_attr( $scoped ),
					$first ? 'true' : 'false',
					$first ? '0' : '-1',
					esc_html( $label )
				);
				$first = false;
			}
			?>
		</nav>

		<div class="tagbridge-panel is-active" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-endpoint" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-endpoint" tabindex="0">
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Webhook endpoint', 'tagbridge' ); ?></h2>
					<?php if ( $configured ) : ?>
						<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Configured', 'tagbridge' ); ?></span>
					<?php else : ?>
						<span class="tagbridge-status"><?php esc_html_e( 'Not configured', 'tagbridge' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php esc_html_e( 'Send normalized JSON payloads for WordPress and WooCommerce events to Zapier, Make, n8n, or your own HTTPS endpoint.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-<?php echo esc_attr( $this->id ); ?>-url"><?php esc_html_e( 'Endpoint URL', 'tagbridge' ); ?></label>
						<input
							type="url"
							id="tagbridge-<?php echo esc_attr( $this->id ); ?>-url"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'endpoint_url' ) ); ?>"
							value="<?php echo esc_attr( $endpoint_url ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="https://hooks.example.com/tagbridge"
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Must be a valid https:// or http:// URL that accepts POST JSON requests.', 'tagbridge' ); ?>
						</p>
					</div>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-<?php echo esc_attr( $this->id ); ?>-secret"><?php esc_html_e( 'Signing secret (optional)', 'tagbridge' ); ?></label>
						<input
							type="password"
							id="tagbridge-<?php echo esc_attr( $this->id ); ?>-secret"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'secret' ) ); ?>"
							value="<?php echo esc_attr( $secret ); ?>"
							spellcheck="false"
							autocomplete="new-password"
							placeholder="whsec_..."
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'When set, Tagbridge signs the raw JSON body using HMAC-SHA256 in the X-Tagbridge-Signature header (sha256=...).', 'tagbridge' ); ?>
						</p>
					</div>
				</div>
			</section>

			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Test delivery', 'tagbridge' ); ?></h2>
					<?php if ( is_array( $last_delivery ) ) : ?>
						<span class="tagbridge-status <?php echo ! empty( $last_delivery['ok'] ) ? 'tagbridge-status--ok' : ''; ?>">
							<?php echo esc_html( $last_delivery['message'] ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php esc_html_e( 'Send a sample tagbridge_test_webhook payload to verify your endpoint and signature.', 'tagbridge' ); ?>
					</p>
					<button
						type="submit"
						class="button button-secondary tagbridge-btn-secondary"
						form="tagbridge-webhooks-test-form"
						formnovalidate
						<?php disabled( ! ( $configured && $enabled ) ); ?>
					>
						<?php esc_html_e( 'Send test webhook', 'tagbridge' ); ?>
					</button>
					<?php
					add_action(
						'tagbridge_after_module_form',
						array( $this, 'render_test_form' ),
						10,
						1
					);
					?>
					<?php if ( ! $configured || ! $enabled ) : ?>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Enable Webhooks and save an endpoint URL first before sending a test delivery.', 'tagbridge' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</section>
		</div>

		<div class="tagbridge-panel" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-events" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-events" tabindex="0" hidden>
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Subscribed events', 'tagbridge' ); ?></h2>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Choose which normalized WordPress and WooCommerce events trigger a webhook request.', 'tagbridge' ); ?>
					</p>
					<input type="hidden" name="<?php echo esc_attr( $this->field( 'events', '__present' ) ); ?>" value="1" />
					<?php
					$descriptions = array(
						Schema::USER_LOGGED_IN        => array( __( 'User logged in', 'tagbridge' ), __( 'WordPress: a user signed in.', 'tagbridge' ) ),
						Schema::USER_REGISTERED       => array( __( 'User registered', 'tagbridge' ), __( 'WordPress: a new user account was created.', 'tagbridge' ) ),
						Schema::PRODUCT_VIEWED        => array( __( 'Product viewed', 'tagbridge' ), __( 'WooCommerce: a single product page was viewed.', 'tagbridge' ) ),
						Schema::PRODUCT_ADDED_TO_CART => array( __( 'Product added to cart', 'tagbridge' ), __( 'WooCommerce: an item was added to the cart.', 'tagbridge' ) ),
						Schema::CHECKOUT_STARTED      => array( __( 'Checkout started', 'tagbridge' ), __( 'WooCommerce: a checkout order was submitted.', 'tagbridge' ) ),
						Schema::ORDER_COMPLETED       => array( __( 'Order completed', 'tagbridge' ), __( 'WooCommerce: an order reached a paid or completed status.', 'tagbridge' ) ),
					);

					foreach ( Schema::all_events() as $key => $event_name ) {
						$label = isset( $descriptions[ $key ] ) ? $descriptions[ $key ][0] : $event_name;
						$help  = isset( $descriptions[ $key ] ) ? $descriptions[ $key ][1] : '';
						$this->row( 'events', $key, $label, $help, ! empty( $events[ $key ] ) );
					}
					?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render the standalone test-webhook form outside the main settings form.
	 *
	 * @param string $module_id Rendered module id.
	 * @return void
	 */
	public function render_test_form( $module_id ) {
		if ( $this->id !== $module_id ) {
			return;
		}
		?>
		<form id="tagbridge-webhooks-test-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="<?php echo esc_attr( Module::ACTION_TEST ); ?>" />
			<?php wp_nonce_field( Module::NONCE_TEST, 'tagbridge_test_webhook_nonce' ); ?>
		</form>
		<?php
	}

	/**
	 * Build a namespaced field name.
	 *
	 * @param string      $group Group key.
	 * @param string|null $name  Optional sub-key.
	 * @return string
	 */
	private function field( $group, $name = null ) {
		$field = 'tagbridge[' . $this->id . '][' . $group . ']';
		if ( null !== $name ) {
			$field .= '[' . $name . ']';
		}
		return $field;
	}

	/**
	 * Render a settings row with a toggle switch.
	 *
	 * @param string $group   Settings group.
	 * @param string $name    Setting key.
	 * @param string $label   Visible label.
	 * @param string $help    Help description.
	 * @param bool   $checked Whether checked.
	 * @return void
	 */
	private function row( $group, $name, $label, $help, $checked ) {
		?>
		<label class="tagbridge-row">
			<span class="tagbridge-row__text">
				<span class="tagbridge-row__label"><?php echo esc_html( $label ); ?></span>
				<span class="tagbridge-help tagbridge-help--field"><?php echo esc_html( $help ); ?></span>
			</span>
			<input
				type="checkbox"
				class="tagbridge-switch__input"
				name="<?php echo esc_attr( $this->field( $group, $name ) ); ?>"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
		</label>
		<?php
	}
}
