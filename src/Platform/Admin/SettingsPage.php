<?php
/**
 * Settings page shell.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Admin;

use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Platform\Events\Sources\WooCommerceSource;
use Tagbridge\Platform\Modules\Catalog;
use Tagbridge\Platform\Modules\ConfigurableModule;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Privacy\PrivacyGate;
use Tagbridge\Platform\Settings\GlobalSettings;
use Tagbridge\Platform\Settings\Options;

/**
 * The plugin's top-level admin shell.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const SLUG = 'tagbridge';

	/**
	 * Admin-post action for toggling an integration module on/off.
	 *
	 * @var string
	 */
	const ACTION_TOGGLE_MODULE = 'tagbridge_toggle_module';

	/**
	 * Nonce name/action for toggling a module.
	 *
	 * @var string
	 */
	const NONCE_TOGGLE_MODULE = 'tagbridge_toggle_module';

	/**
	 * Admin-post action for saving a single module's settings.
	 *
	 * @var string
	 */
	const ACTION_SAVE_MODULE = 'tagbridge_save_module';

	/**
	 * Nonce name/action for saving a module.
	 *
	 * @var string
	 */
	const NONCE_SAVE_MODULE = 'tagbridge_save_module';

	/**
	 * Admin-post action for saving global privacy and platform settings.
	 *
	 * @var string
	 */
	const ACTION_SAVE_GLOBAL = 'tagbridge_save_global';

	/**
	 * Nonce name/action for saving global settings.
	 *
	 * @var string
	 */
	const NONCE_SAVE_GLOBAL = 'tagbridge_save_global';

	/**
	 * Admin-post action for toggling an upcoming integration's launch notification.
	 *
	 * @var string
	 */
	const ACTION_TOGGLE_WATCHLIST = 'tagbridge_toggle_watchlist';

	/**
	 * Nonce name/action for toggling an upcoming integration's launch notification.
	 *
	 * @var string
	 */
	const NONCE_TOGGLE_WATCHLIST = 'tagbridge_toggle_watchlist';

	/**
	 * The module registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_TOGGLE_MODULE, array( $this, 'handle_toggle_module' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_MODULE, array( $this, 'handle_save_module' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_GLOBAL, array( $this, 'handle_save_global' ) );
		add_action( 'admin_post_' . self::ACTION_TOGGLE_WATCHLIST, array( $this, 'handle_toggle_watchlist' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the top-level admin menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Tagbridge', 'tagbridge' ),
			__( 'Tagbridge', 'tagbridge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			$this->menu_icon(),
			58
		);
	}

	/**
	 * Data URI for the WordPress admin sidebar icon.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$svg = '<svg width="20" height="20" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M18 42 C 18 16, 46 16, 46 42" stroke="black" stroke-width="6" stroke-linecap="round" fill="none"/>'
			. '<circle cx="18" cy="42" r="6" fill="black"/>'
			. '<circle cx="46" cy="42" r="6" fill="black"/>'
			. '</svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * The admin page hook suffix for our screen.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return 'toplevel_page_' . self::SLUG;
	}

	/**
	 * Enqueue page assets only on our settings screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'tagbridge-admin',
			TAGBRIDGE_URL . 'assets/css/admin.css',
			array(),
			TAGBRIDGE_VERSION
		);

		wp_enqueue_script(
			'tagbridge-admin',
			TAGBRIDGE_URL . 'assets/js/admin.js',
			array(),
			TAGBRIDGE_VERSION,
			true
		);
	}

	/**
	 * Capability check helper.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle toggling a single module on or off without running network validation.
	 *
	 * @return void
	 */
	public function handle_toggle_module() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE_TOGGLE_MODULE, 'tagbridge_nonce' );

		$id = isset( $_POST['tagbridge_module'] ) ? sanitize_key( wp_unslash( $_POST['tagbridge_module'] ) ) : '';
		if ( '' === $id || ! $this->registry->has( $id ) ) {
			$this->flash( 'error', __( 'Unknown integration.', 'tagbridge' ) );
			$this->redirect_to( $this->hub_url() );
			return;
		}

		$enabled = ! empty( $_POST['tagbridge_enabled'] );
		Options::set_module_enabled( $id, $enabled );
		if ( Options::is_watched( $id ) ) {
			Options::set_watched( $id, false );
		}

		$meta  = $this->registry->meta( $id );
		$label = $meta ? $meta['label'] : $id;

		if ( $enabled ) {
			/* translators: %s: integration name. */
			$message = sprintf( __( '%s enabled.', 'tagbridge' ), $label );
		} else {
			/* translators: %s: integration name. */
			$message = sprintf( __( '%s disabled.', 'tagbridge' ), $label );
		}

		$this->flash( 'success', $message );

		$return_to = isset( $_POST['tagbridge_return_to'] ) ? sanitize_key( wp_unslash( $_POST['tagbridge_return_to'] ) ) : 'hub';
		$target    = ( 'module' === $return_to ) ? $this->module_url( $id ) : $this->hub_url();
		$this->redirect_to( $target );
	}

	/**
	 * Handle saving a single integration module's settings.
	 *
	 * @return void
	 */
	public function handle_save_module() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE_SAVE_MODULE, 'tagbridge_nonce' );

		$id     = isset( $_POST['tagbridge_module'] ) ? sanitize_key( wp_unslash( $_POST['tagbridge_module'] ) ) : '';
		$module = '' !== $id ? $this->registry->get( $id ) : null;
		if ( ! $module instanceof ConfigurableModule ) {
			$this->flash( 'error', __( 'Unknown integration.', 'tagbridge' ) );
			$this->redirect_to( $this->hub_url() );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_all = isset( $_POST['tagbridge'] ) && is_array( $_POST['tagbridge'] ) ? wp_unslash( $_POST['tagbridge'] ) : array();
		$input   = isset( $raw_all[ $id ] ) && is_array( $raw_all[ $id ] ) ? $raw_all[ $id ] : array();

		$sanitized = $module->sanitize( $input );
		$result    = $module->before_save( $sanitized );

		if ( empty( $result['ok'] ) ) {
			$this->flash( 'error', $result['message'] );
			$this->redirect_to( $this->module_url( $id ) );
			return;
		}

		Options::save_module_data( $id, $sanitized );
		if ( Options::is_watched( $id ) ) {
			Options::set_watched( $id, false );
		}

		$saved_message = ! empty( $result['message'] ) ? $result['message'] : __( 'Settings saved.', 'tagbridge' );
		$this->flash( 'success', $saved_message );
		$this->redirect_to( $this->module_url( $id ) );
	}

	/**
	 * Handle saving global privacy and platform settings.
	 *
	 * @return void
	 */
	public function handle_save_global() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE_SAVE_GLOBAL, 'tagbridge_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['tagbridge_global'] ) && is_array( $_POST['tagbridge_global'] ) ? wp_unslash( $_POST['tagbridge_global'] ) : array();

		$valid_roles = array_keys( wp_roles()->get_names() );
		$sanitized   = GlobalSettings::sanitize( $raw, $valid_roles );
		GlobalSettings::save( $sanitized );

		$this->flash( 'success', __( 'Privacy and platform settings saved.', 'tagbridge' ) );
		$this->redirect_to( $this->settings_url() );
	}

	/**
	 * Handle toggling local launch notifications for a Coming Soon integration.
	 *
	 * @return void
	 */
	public function handle_toggle_watchlist() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE_TOGGLE_WATCHLIST, 'tagbridge_nonce' );

		$id       = isset( $_POST['tagbridge_upcoming'] ) ? sanitize_key( wp_unslash( $_POST['tagbridge_upcoming'] ) ) : '';
		$upcoming = Catalog::upcoming( $this->registry );
		if ( '' === $id || ! isset( $upcoming[ $id ] ) ) {
			$this->flash( 'error', __( 'Unknown upcoming integration.', 'tagbridge' ) );
			$this->redirect_to( $this->hub_url() );
			return;
		}

		$watch = ! empty( $_POST['tagbridge_watch'] );
		Options::set_watched( $id, $watch );

		if ( $watch ) {
			/* translators: %s: upcoming integration name. */
			$message = sprintf( __( 'You will be notified in WordPress when %s launches in a Tagbridge update.', 'tagbridge' ), $upcoming[ $id ]['label'] );
		} else {
			/* translators: %s: upcoming integration name. */
			$message = sprintf( __( 'Removed %s from your launch notifications.', 'tagbridge' ), $upcoming[ $id ]['label'] );
		}

		$this->flash( 'success', $message );
		$this->redirect_to( $this->hub_url() );
	}

	/**
	 * Store a one-time flash message for the current user.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private function flash( $type, $message ) {
		set_transient(
			$this->flash_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Read and clear the flash message.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function read_flash() {
		$flash = get_transient( $this->flash_key() );
		if ( ! is_array( $flash ) ) {
			return null;
		}
		delete_transient( $this->flash_key() );
		return $flash;
	}

	/**
	 * Per-user flash transient key.
	 *
	 * @return string
	 */
	private function flash_key() {
		return 'tagbridge_flash_' . get_current_user_id();
	}

	/**
	 * URL for the Integrations Hub.
	 *
	 * @return string
	 */
	private function hub_url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * URL for a specific integration's detail screen.
	 *
	 * @param string $id Module id.
	 * @return string
	 */
	private function module_url( $id ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&module=' . rawurlencode( $id ) );
	}

	/**
	 * URL for Global Privacy & Settings.
	 *
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'admin.php?page=' . self::SLUG . '&view=settings' );
	}

	/**
	 * Redirect safely to a Tagbridge admin URL.
	 *
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function redirect_to( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Human-readable label for a module category slug.
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	private function category_label( $category ) {
		$labels = array(
			'analytics'  => __( 'Analytics', 'tagbridge' ),
			'ads'        => __( 'Ads & Conversions', 'tagbridge' ),
			'crm'        => __( 'Lifecycle & CRM', 'tagbridge' ),
			'support'    => __( 'Support', 'tagbridge' ),
			'automation' => __( 'Automation', 'tagbridge' ),
		);

		return isset( $labels[ $category ] ) ? $labels[ $category ] : ucfirst( (string) $category );
	}

	/**
	 * Render the requested Tagbridge admin screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tagbridge' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		$flash      = $this->read_flash();
		$active_nav = 'hub';
		$detail_id  = '';
		$detail_mod = null;

		if ( '' !== $requested_module ) {
			$candidate = $this->registry->get( $requested_module );
			if ( $candidate instanceof ConfigurableModule ) {
				$detail_id  = $requested_module;
				$detail_mod = $candidate;
				$active_nav = 'hub';
			} elseif ( null === $flash ) {
				$flash = array(
					'type'    => 'error',
					'message' => __( 'That integration could not be found.', 'tagbridge' ),
				);
			}
		} elseif ( 'settings' === $requested_view ) {
			$active_nav = 'settings';
		}
		?>
		<div class="wrap tagbridge-wrap">
			<?php $this->render_hero( $active_nav ); ?>

			<?php if ( $flash ) : ?>
				<div class="tagbridge-flash tagbridge-flash--<?php echo esc_attr( 'success' === $flash['type'] ? 'ok' : 'error' ); ?>">
					<?php echo esc_html( $flash['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php
			if ( $detail_mod instanceof ConfigurableModule ) {
				$this->render_module_detail( $detail_id, $detail_mod );
			} elseif ( 'settings' === $active_nav ) {
				$this->render_global_settings();
			} else {
				$this->render_hub();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the brand hero and top-level view switcher.
	 *
	 * @param string $active_nav Active section ('hub' or 'settings').
	 * @return void
	 */
	private function render_hero( $active_nav ) {
		?>
		<header class="tagbridge-hero">
			<div class="tagbridge-hero__brand">
				<span class="tagbridge-hero__mark" aria-hidden="true">
					<svg width="40" height="40" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
						<path d="M18 42 C 18 16, 46 16, 46 42" stroke="currentColor" stroke-width="6" stroke-linecap="round" fill="none"/>
						<circle cx="18" cy="42" r="6" fill="currentColor"/>
						<circle cx="46" cy="42" r="6" fill="currentColor"/>
					</svg>
				</span>
				<span class="tagbridge-hero__titles">
					<span class="tagbridge-wordmark">Tagbridge</span>
					<span class="tagbridge-hero__subtitle"><?php esc_html_e( 'Connect WordPress to the tools you already use.', 'tagbridge' ); ?></span>
				</span>
			</div>
		</header>

		<nav class="tagbridge-nav" aria-label="<?php esc_attr_e( 'Tagbridge sections', 'tagbridge' ); ?>">
			<a
				href="<?php echo esc_url( $this->hub_url() ); ?>"
				class="tagbridge-nav__link<?php echo 'hub' === $active_nav ? ' is-active' : ''; ?>"
				<?php echo 'hub' === $active_nav ? 'aria-current="page"' : ''; ?>
			>
				<?php esc_html_e( 'Integrations', 'tagbridge' ); ?>
			</a>
			<a
				href="<?php echo esc_url( $this->settings_url() ); ?>"
				class="tagbridge-nav__link<?php echo 'settings' === $active_nav ? ' is-active' : ''; ?>"
				<?php echo 'settings' === $active_nav ? 'aria-current="page"' : ''; ?>
			>
				<?php esc_html_e( 'Privacy & Settings', 'tagbridge' ); ?>
			</a>
		</nav>
		<?php
	}

	/**
	 * Render the Integrations Hub catalog.
	 *
	 * Reads manifest metadata without instantiating disabled modules.
	 *
	 * @return void
	 */
	private function render_hub() {
		$ids = $this->registry->ids();
		?>
		<div class="tagbridge-hub">
			<?php
			foreach ( $ids as $id ) :
				$meta = $this->registry->meta( $id );
				if ( ! $meta ) {
					continue;
				}
				$enabled    = $this->registry->is_enabled( $id );
				$configured = false;
				if ( $enabled ) {
					$module     = $this->registry->get( $id );
					$configured = ( $module instanceof ConfigurableModule && $module->is_configured() );
				}
				?>
				<article class="tagbridge-integration<?php echo $enabled ? '' : ' is-disabled'; ?>">
					<div class="tagbridge-integration__head">
						<?php $this->render_icon( $meta['icon'] ); ?>
						<div class="tagbridge-integration__titles">
							<h2 class="tagbridge-integration__name"><?php echo esc_html( $meta['label'] ); ?></h2>
							<span class="tagbridge-badge"><?php echo esc_html( $this->category_label( $meta['category'] ) ); ?></span>
						</div>
						<?php $this->render_toggle_form( $id, $meta['label'], $enabled, 'hub' ); ?>
					</div>

					<p class="tagbridge-integration__desc"><?php echo esc_html( $meta['description'] ); ?></p>

					<div class="tagbridge-integration__foot">
						<?php if ( ! $enabled ) : ?>
							<span class="tagbridge-status"><?php esc_html_e( 'Disabled', 'tagbridge' ); ?></span>
						<?php elseif ( $configured ) : ?>
							<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Connected', 'tagbridge' ); ?></span>
						<?php else : ?>
							<span class="tagbridge-status"><?php esc_html_e( 'Needs setup', 'tagbridge' ); ?></span>
						<?php endif; ?>

						<a href="<?php echo esc_url( $this->module_url( $id ) ); ?>" class="button button-secondary tagbridge-btn-secondary">
							<?php esc_html_e( 'Configure', 'tagbridge' ); ?>
						</a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<p class="tagbridge-hub__meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of installed integrations. */
					_n( '%d integration available. Disabled integrations use zero runtime resources.', '%d integrations available. Disabled integrations use zero runtime resources.', count( $ids ), 'tagbridge' ),
					count( $ids )
				)
			);
			?>
		</p>

		<?php
		$upcoming = Catalog::upcoming( $this->registry );
		if ( ! empty( $upcoming ) ) :
			?>
			<section class="tagbridge-upcoming" aria-labelledby="tagbridge-upcoming-heading">
				<h2 id="tagbridge-upcoming-heading" class="tagbridge-section-title"><?php esc_html_e( 'Coming soon', 'tagbridge' ); ?></h2>
				<p class="tagbridge-section-lead">
					<?php esc_html_e( 'Planned deep integrations. Select Notify me to receive a WordPress admin alert when a Tagbridge update adds it.', 'tagbridge' ); ?>
				</p>

				<div class="tagbridge-hub tagbridge-hub--upcoming">
					<?php
					foreach ( $upcoming as $upcoming_id => $item ) :
						$watched = Options::is_watched( $upcoming_id );
						?>
						<article class="tagbridge-integration tagbridge-integration--upcoming">
							<div class="tagbridge-integration__head">
								<?php $this->render_icon( $item['icon'] ); ?>
								<div class="tagbridge-integration__titles">
									<h3 class="tagbridge-integration__name"><?php echo esc_html( $item['label'] ); ?></h3>
									<span class="tagbridge-badge"><?php echo esc_html( $this->category_label( $item['category'] ) ); ?></span>
								</div>
								<span class="tagbridge-badge tagbridge-badge--soon"><?php esc_html_e( 'Coming soon', 'tagbridge' ); ?></span>
							</div>

							<p class="tagbridge-integration__desc"><?php echo esc_html( $item['description'] ); ?></p>

							<div class="tagbridge-integration__foot">
								<?php if ( $watched ) : ?>
									<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Notifying on launch', 'tagbridge' ); ?></span>
								<?php else : ?>
									<span class="tagbridge-status"><?php esc_html_e( 'Planned', 'tagbridge' ); ?></span>
								<?php endif; ?>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tagbridge-notify-form">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TOGGLE_WATCHLIST ); ?>" />
									<input type="hidden" name="tagbridge_upcoming" value="<?php echo esc_attr( $upcoming_id ); ?>" />
									<input type="hidden" name="tagbridge_watch" value="<?php echo $watched ? '0' : '1'; ?>" />
									<?php wp_nonce_field( self::NONCE_TOGGLE_WATCHLIST, 'tagbridge_nonce' ); ?>
									<?php if ( $watched ) : ?>
										<button
											type="submit"
											class="button button-secondary tagbridge-btn-notify-active"
											aria-pressed="true"
											title="<?php esc_attr_e( 'Click to cancel launch notification', 'tagbridge' ); ?>"
										>
											<?php esc_html_e( 'Notifying', 'tagbridge' ); ?>
										</button>
									<?php else : ?>
										<button type="submit" class="button button-secondary tagbridge-btn-secondary" aria-pressed="false">
											<?php esc_html_e( 'Notify me', 'tagbridge' ); ?>
										</button>
									<?php endif; ?>
								</form>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render an isolated integration detail page.
	 *
	 * @param string             $id     Module id.
	 * @param ConfigurableModule $module Module instance.
	 * @return void
	 */
	private function render_module_detail( $id, ConfigurableModule $module ) {
		$enabled    = $this->registry->is_enabled( $id );
		$configured = $module->is_configured();
		?>
		<div class="tagbridge-detail-bar">
			<a href="<?php echo esc_url( $this->hub_url() ); ?>" class="tagbridge-back">
				&larr; <?php esc_html_e( 'All integrations', 'tagbridge' ); ?>
			</a>
		</div>

		<section class="tagbridge-module" data-tagbridge-module="<?php echo esc_attr( $id ); ?>">
			<header class="tagbridge-module__head">
				<?php $this->render_icon( $module->icon() ); ?>
				<span class="tagbridge-module__titles">
					<span class="tagbridge-module__name">
						<?php echo esc_html( $module->label() ); ?>
						<span class="tagbridge-badge"><?php echo esc_html( $this->category_label( $module->category() ) ); ?></span>
					</span>
					<span class="tagbridge-module__desc"><?php echo esc_html( $module->description() ); ?></span>
				</span>
				<div class="tagbridge-module__actions">
					<?php if ( ! $enabled ) : ?>
						<span class="tagbridge-status"><?php esc_html_e( 'Disabled', 'tagbridge' ); ?></span>
					<?php elseif ( $configured ) : ?>
						<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Connected', 'tagbridge' ); ?></span>
					<?php else : ?>
						<span class="tagbridge-status"><?php esc_html_e( 'Needs setup', 'tagbridge' ); ?></span>
					<?php endif; ?>
					<?php $this->render_toggle_form( $id, $module->label(), $enabled, 'module' ); ?>
				</div>
			</header>

			<?php if ( ! $enabled ) : ?>
				<div class="tagbridge-banner">
					<?php esc_html_e( 'This integration is currently disabled. Enable it above to start sending data; you can still update its settings below.', 'tagbridge' ); ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tagbridge-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_MODULE ); ?>" />
				<input type="hidden" name="tagbridge_module" value="<?php echo esc_attr( $id ); ?>" />
				<?php wp_nonce_field( self::NONCE_SAVE_MODULE, 'tagbridge_nonce' ); ?>

				<div class="tagbridge-module__body">
					<?php $module->render_settings(); ?>
				</div>

				<?php
				$save_copy = (array) apply_filters(
					'tagbridge_module_save_copy',
					array(
						'button' => __( 'Save settings', 'tagbridge' ),
						'note'   => '',
					),
					$id,
					$module
				);
				$btn_label = ! empty( $save_copy['button'] ) ? (string) $save_copy['button'] : __( 'Save settings', 'tagbridge' );
				$btn_note  = isset( $save_copy['note'] ) ? (string) $save_copy['note'] : '';
				?>
				<div class="tagbridge-actionbar tagbridge-actionbar--inset">
					<button type="submit" class="button button-primary button-hero tagbridge-btn">
						<?php echo esc_html( $btn_label ); ?>
					</button>
					<?php if ( '' !== $btn_note ) : ?>
						<span class="tagbridge-actionbar__note"><?php echo esc_html( $btn_note ); ?></span>
					<?php endif; ?>
				</div>
			</form>
			<?php
			/**
			 * Fires immediately after a module's main settings form closes.
			 *
			 * @param string             $id     Module id.
			 * @param ConfigurableModule $module Module instance.
			 */
			do_action( 'tagbridge_after_module_form', $id, $module );
			?>
		</section>
		<?php
	}

	/**
	 * Render global privacy controls (WP Consent API, DNT, role exclusions) and event sources.
	 *
	 * @return void
	 */
	private function render_global_settings() {
		$consent_api_active = PrivacyGate::is_consent_api_active();
		$consent_mode       = GlobalSettings::consent_mode();
		$respect_dnt        = GlobalSettings::respect_dnt();
		$excluded_roles     = GlobalSettings::excluded_roles();
		$all_roles          = wp_roles()->get_names();
		$woo_active         = WooCommerceSource::is_active();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tagbridge-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_GLOBAL ); ?>" />
			<input type="hidden" name="tagbridge_global[present]" value="1" />
			<input type="hidden" name="tagbridge_global[roles_present]" value="1" />
			<?php wp_nonce_field( self::NONCE_SAVE_GLOBAL, 'tagbridge_nonce' ); ?>

			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Consent (WordPress Consent API)', 'tagbridge' ); ?></h2>
					<?php if ( $consent_api_active ) : ?>
						<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'WP Consent API active', 'tagbridge' ); ?></span>
					<?php else : ?>
						<span class="tagbridge-status"><?php esc_html_e( 'WP Consent API not installed', 'tagbridge' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Control how Tagbridge integrations wait for visitor consent when using a WordPress Consent API compatible banner.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-global-consent-mode"><?php esc_html_e( 'Consent behavior', 'tagbridge' ); ?></label>
						<select id="tagbridge-global-consent-mode" class="tagbridge-input" name="tagbridge_global[consent_mode]">
							<option value="<?php echo esc_attr( Policy::CONSENT_OFF ); ?>" <?php selected( $consent_mode, Policy::CONSENT_OFF ); ?>>
								<?php esc_html_e( 'Off: track visitors without checking consent', 'tagbridge' ); ?>
							</option>
							<option value="<?php echo esc_attr( Policy::CONSENT_REQUIRE ); ?>" <?php selected( $consent_mode, Policy::CONSENT_REQUIRE ); ?>>
								<?php esc_html_e( 'Require consent: wait until consent is granted', 'tagbridge' ); ?>
							</option>
							<option value="<?php echo esc_attr( Policy::CONSENT_COOKIELESS ); ?>" <?php selected( $consent_mode, Policy::CONSENT_COOKIELESS ); ?>>
								<?php esc_html_e( 'Cookieless until consent: track anonymously in memory first', 'tagbridge' ); ?>
							</option>
						</select>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Before consent is granted, "Require consent" blocks scripts and server events; "Cookieless until consent" runs in memory without cookies or person identification. When consent is withdrawn, browser tracking opts out immediately.', 'tagbridge' ); ?>
						</p>
					</div>

					<label class="tagbridge-row">
						<span class="tagbridge-row__text">
							<span class="tagbridge-row__label"><?php esc_html_e( 'Respect Do Not Track (DNT)', 'tagbridge' ); ?></span>
							<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Skip browser and server-side tracking when a visitor sends a DNT: 1 header.', 'tagbridge' ); ?></span>
						</span>
						<input
							type="checkbox"
							class="tagbridge-switch__input"
							name="tagbridge_global[respect_dnt]"
							value="1"
							<?php checked( $respect_dnt ); ?>
						/>
						<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
					</label>
				</div>
			</section>

			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Exclude user roles', 'tagbridge' ); ?></h2>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Logged-in visitors with any selected role are excluded from browser tracking and server-side events across all integrations.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-roles-grid">
						<?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
							<label class="tagbridge-role-item">
								<span class="tagbridge-role-item__name"><?php echo esc_html( translate_user_role( $role_name ) ); ?></span>
								<input
									type="checkbox"
									class="tagbridge-switch__input"
									name="tagbridge_global[excluded_roles][]"
									value="<?php echo esc_attr( $role_slug ); ?>"
									<?php checked( in_array( $role_slug, $excluded_roles, true ) ); ?>
								/>
								<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<section class="tagbridge-card">
				<div class="tagbridge-card__head">
					<h2 class="tagbridge-card__title"><?php esc_html_e( 'Event sources', 'tagbridge' ); ?></h2>
				</div>
				<div class="tagbridge-card__body">
					<p class="tagbridge-card__lead">
						<?php esc_html_e( 'Tagbridge captures these events once and fans them out to every enabled integration.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-source-row">
						<div class="tagbridge-row__text">
							<span class="tagbridge-row__label"><?php esc_html_e( 'WordPress Core', 'tagbridge' ); ?></span>
							<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Captures user_logged_in and user_registered.', 'tagbridge' ); ?></span>
						</div>
						<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Active', 'tagbridge' ); ?></span>
					</div>

					<div class="tagbridge-source-row">
						<div class="tagbridge-row__text">
							<span class="tagbridge-row__label"><?php esc_html_e( 'WooCommerce', 'tagbridge' ); ?></span>
							<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Captures product_viewed, product_added_to_cart, checkout_started, and order_completed.', 'tagbridge' ); ?></span>
						</div>
						<?php if ( $woo_active ) : ?>
							<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Active', 'tagbridge' ); ?></span>
						<?php else : ?>
							<span class="tagbridge-status"><?php esc_html_e( 'Not active', 'tagbridge' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<div class="tagbridge-actionbar">
				<button type="submit" class="button button-primary button-hero tagbridge-btn">
					<?php esc_html_e( 'Save settings', 'tagbridge' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render an integration's logo image or Dashicons fallback.
	 *
	 * @param string $icon Plugin-relative asset path, URL, or Dashicons class.
	 * @return void
	 */
	private function render_icon( $icon ) {
		$icon     = (string) $icon;
		$is_image = 0 === strpos( $icon, 'assets/' )
			|| 1 === preg_match( '#^https?://#i', $icon )
			|| 1 === preg_match( '/\.(?:svg|png|webp|jpg|jpeg)$/i', $icon );

		if ( $is_image ) {
			$url = 1 === preg_match( '#^https?://#i', $icon ) ? $icon : TAGBRIDGE_URL . ltrim( $icon, '/' );
			?>
			<span class="tagbridge-module__icon tagbridge-module__icon--img" aria-hidden="true">
				<img src="<?php echo esc_url( $url ); ?>" alt="" width="24" height="24" loading="lazy" decoding="async" />
			</span>
			<?php
			return;
		}
		?>
		<span class="tagbridge-module__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
		<?php
	}

	/**
	 * Render a standalone enable/disable toggle form for a module.
	 *
	 * @param string $id        Module id.
	 * @param string $label     Module label for accessible text.
	 * @param bool   $enabled   Whether the module is currently enabled.
	 * @param string $return_to Redirect target ('hub' or 'module').
	 * @return void
	 */
	private function render_toggle_form( $id, $label, $enabled, $return_to ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tagbridge-toggle-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TOGGLE_MODULE ); ?>" />
			<input type="hidden" name="tagbridge_module" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="tagbridge_return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php wp_nonce_field( self::NONCE_TOGGLE_MODULE, 'tagbridge_nonce' ); ?>
			<label class="tagbridge-headswitch tagbridge-module__toggle">
				<span class="screen-reader-text">
					<?php
					/* translators: %s: integration name. */
					printf( esc_html__( 'Enable %s', 'tagbridge' ), esc_html( $label ) );
					?>
				</span>
				<input
					type="checkbox"
					class="tagbridge-switch__input"
					name="tagbridge_enabled"
					value="1"
					data-tagbridge-auto-submit
					<?php checked( $enabled ); ?>
				/>
				<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
			</label>
			<noscript>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Apply', 'tagbridge' ); ?></button>
			</noscript>
		</form>
		<?php
	}
}
