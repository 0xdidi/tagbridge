<?php
/**
 * PostHog settings panel.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Platform\Events\Sources\WooCommerceSource;
use Tagbridge\Platform\Settings\Options;

/**
 * Renders the PostHog module's settings UI.
 */
final class SettingsPanel {

	/**
	 * The module id, used as the field-name prefix (e.g. tagbridge[posthog][...]).
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
	 * The tab definitions for this module, in display order.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'connection'  => __( 'Connection', 'tagbridge' ),
			'tracking'    => __( 'Tracking', 'tagbridge' ),
			'identity'    => __( 'Identity', 'tagbridge' ),
			'server'      => __( 'Server events', 'tagbridge' ),
			'diagnostics' => __( 'Diagnostics', 'tagbridge' ),
		);
	}

	/**
	 * Render the tab nav and all tab panels for the module.
	 *
	 * @return void
	 */
	public function render() {
		$connected   = Settings::is_configured();
		$api_key     = Settings::project_api_key();
		$region      = Settings::region();
		$custom_host = Settings::custom_host();
		$client      = Settings::client();
		$identity    = Settings::identity();
		$server      = Settings::server_events();
		$woo_active  = WooCommerceSource::is_active();
		$is_custom   = Host::REGION_CUSTOM === $region;
		?>
		<nav class="tagbridge-tabs" role="tablist" aria-label="<?php esc_attr_e( 'PostHog settings', 'tagbridge' ); ?>">
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

		<div class="tagbridge-panel is-active" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-connection" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-connection" tabindex="0">
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Connection', 'tagbridge' ); ?></h2>
					<?php if ( $connected ) : ?>
						<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Connected', 'tagbridge' ); ?></span>
					<?php else : ?>
						<span class="tagbridge-status"><?php esc_html_e( 'Not connected', 'tagbridge' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php esc_html_e( 'Paste your PostHog project API key and pick your region. We check it before saving.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-<?php echo esc_attr( $this->id ); ?>-key"><?php esc_html_e( 'Project API key', 'tagbridge' ); ?></label>
						<input
							type="text"
							id="tagbridge-<?php echo esc_attr( $this->id ); ?>-key"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'project_api_key' ) ); ?>"
							value="<?php echo esc_attr( $api_key ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="phc_..."
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Find this in PostHog under Settings, Project, Project token (or Project API key). It is safe to expose in the browser.', 'tagbridge' ); ?>
						</p>
					</div>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-<?php echo esc_attr( $this->id ); ?>-region"><?php esc_html_e( 'Region', 'tagbridge' ); ?></label>
						<select id="tagbridge-<?php echo esc_attr( $this->id ); ?>-region" class="tagbridge-input" name="<?php echo esc_attr( $this->field( 'region' ) ); ?>" data-tagbridge-region>
							<option value="<?php echo esc_attr( Host::REGION_US ); ?>" <?php selected( $region, Host::REGION_US ); ?>><?php esc_html_e( 'US cloud (us.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_EU ); ?>" <?php selected( $region, Host::REGION_EU ); ?>><?php esc_html_e( 'EU cloud (eu.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_CUSTOM ); ?>" <?php selected( $region, Host::REGION_CUSTOM ); ?>><?php esc_html_e( 'Self-hosted or reverse proxy', 'tagbridge' ); ?></option>
						</select>
					</div>

					<div class="tagbridge-field" data-tagbridge-custom-host <?php echo $is_custom ? '' : 'hidden'; ?>>
						<label class="tagbridge-label" for="tagbridge-<?php echo esc_attr( $this->id ); ?>-host"><?php esc_html_e( 'Custom host URL', 'tagbridge' ); ?></label>
						<input
							type="url"
							id="tagbridge-<?php echo esc_attr( $this->id ); ?>-host"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'custom_host' ) ); ?>"
							value="<?php echo esc_attr( $custom_host ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="https://posthog.example.com"
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'The full URL of your PostHog instance or reverse proxy, including https://.', 'tagbridge' ); ?>
						</p>
					</div>
				</div>
			</section>
		</div>

		<div class="tagbridge-panel" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-tracking" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-tracking" tabindex="0" hidden>
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Tracking', 'tagbridge' ); ?></h2>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'What PostHog captures in the browser.', 'tagbridge' ); ?>
					</p>
					<?php
					$this->row(
						'client',
						'pageviews',
						__( 'Track pageviews', 'tagbridge' ),
						__( 'A pageview each time someone loads a page.', 'tagbridge' ),
						! empty( $client['pageviews'] )
					);
					$this->row(
						'client',
						'autocapture',
						__( 'Autocapture clicks and forms', 'tagbridge' ),
						__( 'Clicks, form submissions, and other interactions.', 'tagbridge' ),
						! empty( $client['autocapture'] )
					);
					$this->row(
						'client',
						'heatmaps',
						__( 'Capture heatmaps', 'tagbridge' ),
						__( 'Collect click and scroll heatmap data when enabled in PostHog.', 'tagbridge' ),
						! empty( $client['heatmaps'] )
					);
					$this->row(
						'client',
						'session_recording',
						__( 'Record sessions', 'tagbridge' ),
						__( 'Session replays with form inputs and customer details masked.', 'tagbridge' ),
						! empty( $client['session_recording'] )
					);
					$this->row(
						'client',
						'capture_exceptions',
						__( 'Capture JavaScript errors', 'tagbridge' ),
						__( 'Send unhandled browser exceptions to PostHog error tracking.', 'tagbridge' ),
						! empty( $client['capture_exceptions'] )
					);
					$this->row(
						'client',
						'cookieless',
						__( 'Cookieless mode', 'tagbridge' ),
						__( 'Keep visitor state in memory; no PostHog cookie.', 'tagbridge' ),
						! empty( $client['cookieless'] )
					);
					?>
					<div class="tagbridge-row tagbridge-row--static">
						<span class="tagbridge-row__text">
							<span class="tagbridge-row__label"><?php esc_html_e( 'Person profiles', 'tagbridge' ); ?></span>
							<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Identified-only is the usual choice.', 'tagbridge' ); ?></span>
						</span>
						<select class="tagbridge-input" name="<?php echo esc_attr( $this->field( 'client', 'person_profiles' ) ); ?>">
							<option value="identified_only" <?php selected( $client['person_profiles'], 'identified_only' ); ?>><?php esc_html_e( 'Identified only', 'tagbridge' ); ?></option>
							<option value="always" <?php selected( $client['person_profiles'], 'always' ); ?>><?php esc_html_e( 'Everyone', 'tagbridge' ); ?></option>
						</select>
					</div>
				</div>
			</section>
		</div>

		<div class="tagbridge-panel" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-identity" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-identity" tabindex="0" hidden>
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Identity', 'tagbridge' ); ?></h2>
					<?php
					$this->head_switch(
						'identity',
						'identify_logged_in',
						'identity',
						__( 'Identify logged-in users', 'tagbridge' ),
						! empty( $identity['identify_logged_in'] )
					);
					?>
				</div>
				<div class="tagbridge-card__body tagbridge-dependent" data-tagbridge-dependent="identity">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Tie logged-in users to one PostHog person with a hashed ID (never the raw user ID). Choose which properties to send.', 'tagbridge' ); ?>
					</p>
					<input type="hidden" name="<?php echo esc_attr( $this->field( 'identity', 'present' ) ); ?>" value="1" />
					<?php
					$this->row(
						'identity',
						'send_email',
						__( 'Send email address', 'tagbridge' ),
						__( 'Attach the user email to their profile.', 'tagbridge' ),
						! empty( $identity['send_email'] )
					);
					$this->row(
						'identity',
						'send_name',
						__( 'Send display name', 'tagbridge' ),
						__( 'Attach the display name to their profile.', 'tagbridge' ),
						! empty( $identity['send_name'] )
					);
					$this->row(
						'identity',
						'send_role',
						__( 'Send role', 'tagbridge' ),
						__( 'Attach the primary role to their profile.', 'tagbridge' ),
						! empty( $identity['send_role'] )
					);
					?>
				</div>
			</section>
		</div>

		<div class="tagbridge-panel" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-server" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-server" tabindex="0" hidden>
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Server-side events', 'tagbridge' ); ?></h2>
					<?php
					$this->head_switch(
						'server_events',
						'enabled',
						'server',
						__( 'Send events from the server', 'tagbridge' ),
						! empty( $server['enabled'] )
					);
					?>
				</div>
				<div class="tagbridge-card__body tagbridge-dependent" data-tagbridge-dependent="server">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Send key events from your own WordPress server, so they still arrive when a visitor\'s browser blocks tracking. Runs on your existing hosting; nothing extra to set up.', 'tagbridge' ); ?>
					</p>
					<input type="hidden" name="<?php echo esc_attr( $this->field( 'server_events', 'present' ) ); ?>" value="1" />
					<?php
					$this->row(
						'server_events',
						'user_logged_in',
						__( 'User logged in', 'tagbridge' ),
						__( 'Capture when a user logs in.', 'tagbridge' ),
						! empty( $server['user_logged_in'] )
					);
					$this->row(
						'server_events',
						'user_registered',
						__( 'User registered', 'tagbridge' ),
						__( 'Capture when a new account is created.', 'tagbridge' ),
						! empty( $server['user_registered'] )
					);

					if ( $woo_active ) {
						$this->row(
							'server_events',
							'product_viewed',
							__( 'Product viewed', 'tagbridge' ),
							__( 'WooCommerce: a product page was viewed.', 'tagbridge' ),
							! empty( $server['product_viewed'] )
						);
						$this->row(
							'server_events',
							'product_added_to_cart',
							__( 'Added to cart', 'tagbridge' ),
							__( 'WooCommerce: a product was added to the cart.', 'tagbridge' ),
							! empty( $server['product_added_to_cart'] )
						);
						$this->row(
							'server_events',
							'checkout_started',
							__( 'Checkout started', 'tagbridge' ),
							__( 'WooCommerce: a checkout was submitted.', 'tagbridge' ),
							! empty( $server['checkout_started'] )
						);
						$this->row(
							'server_events',
							'order_completed',
							__( 'Order completed', 'tagbridge' ),
							__( 'WooCommerce: an order completed, with value and currency.', 'tagbridge' ),
							! empty( $server['order_completed'] )
						);
					} else {
						?>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'WooCommerce is not active. Install it to capture commerce events (product views, add to cart, checkout, orders).', 'tagbridge' ); ?>
						</p>
						<?php
					}
					?>
				</div>
			</section>
		</div>

		<div class="tagbridge-panel" id="tagbridge-panel-<?php echo esc_attr( $this->id ); ?>-diagnostics" role="tabpanel" aria-labelledby="tagbridge-tab-<?php echo esc_attr( $this->id ); ?>-diagnostics" tabindex="0" hidden>
			<?php
			$enabled     = Options::is_module_enabled( $this->id );
			$last_diag   = function_exists( 'get_transient' ) ? get_transient( Module::LAST_DIAG_TRANSIENT ) : null;
			$host        = Settings::host();
			$assets_host = str_replace( '.i.posthog.com', '-assets.i.posthog.com', $host );
			?>
			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Live check & test event', 'tagbridge' ); ?></h2>
					<?php if ( is_array( $last_diag ) && ! empty( $last_diag['message'] ) ) : ?>
						<span class="tagbridge-status <?php echo ! empty( $last_diag['ok'] ) ? 'tagbridge-status--ok' : ''; ?>">
							<?php echo esc_html( $last_diag['message'] ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php esc_html_e( 'Verify PostHog host reachability and send a clearly labeled tagbridge_test_event (without creating a person profile).', 'tagbridge' ); ?>
					</p>
					<?php wp_nonce_field( Module::NONCE_TEST_EVENT, 'tagbridge_posthog_test_nonce' ); ?>
					<button
						type="submit"
						class="button button-secondary tagbridge-btn-secondary"
						formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=' . Module::ACTION_TEST_EVENT ) ); ?>"
						<?php disabled( ! ( $connected && $enabled ) ); ?>
					>
						<?php esc_html_e( 'Send test event', 'tagbridge' ); ?>
					</button>
					<?php if ( ! $connected || ! $enabled ) : ?>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Enable PostHog and save your project API key before sending a test event.', 'tagbridge' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</section>

			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Troubleshooting', 'tagbridge' ); ?></h2>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: script host, 2: ingestion host. */
								__( 'Content Security Policy (CSP): allow %1$s in script-src and %2$s in connect-src if your site enforces strict CSP headers.', 'tagbridge' ),
								'' !== $assets_host ? $assets_host : 'https://us-assets.i.posthog.com',
								'' !== $host ? $host : 'https://us.i.posthog.com'
							)
						);
						?>
					</p>
					<p class="tagbridge-help">
						<?php esc_html_e( 'Duplicate snippets: if another theme or tag manager already calls posthog.init(), remove the duplicate snippet so pageviews and identities are not split.', 'tagbridge' ); ?>
					</p>
					<p class="tagbridge-help">
						<?php esc_html_e( 'Feature flags: use the PostHog Feature Flag block or [tagbridge_flag key="my-flag"]content[/tagbridge_flag] shortcode. Full-page caches may serve one variant to anonymous visitors unless bypassed.', 'tagbridge' ); ?>
					</p>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Build a namespaced field name: tagbridge[<id>][<group>] or [<group>][<name>].
	 *
	 * @param string      $group Group key (e.g. 'client') or a top-level key.
	 * @param string|null $name  Optional sub-key within the group.
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
	 * Render a settings row: label and help on the left, switch on the right.
	 *
	 * The whole row is a label, so clicking anywhere toggles the switch.
	 *
	 * @param string $group   Settings group (e.g. 'client', 'identity').
	 * @param string $name    Setting key within the group.
	 * @param string $label   Visible label.
	 * @param string $help    One-line help text.
	 * @param bool   $checked Whether currently enabled.
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

	/**
	 * Render a master switch for a card header (switch only, label is the title).
	 *
	 * @param string $group      Settings group.
	 * @param string $name       Setting key within the group.
	 * @param string $master_key Identifier linking this master to its dependent body.
	 * @param string $aria_label Accessible label for the switch.
	 * @param bool   $checked    Whether currently enabled.
	 * @return void
	 */
	private function head_switch( $group, $name, $master_key, $aria_label, $checked ) {
		?>
		<label class="tagbridge-headswitch" data-tagbridge-master="<?php echo esc_attr( $master_key ); ?>">
			<span class="screen-reader-text"><?php echo esc_html( $aria_label ); ?></span>
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
