<?php
/**
 * Admin notices.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Admin;

use Tagbridge\Platform\Modules\Catalog;
use Tagbridge\Platform\Modules\ConfigurableModule;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Settings\Options;

/**
 * First-run, launch-alert, and status admin notices.
 */
final class Notices {

	/**
	 * User meta key recording that the setup notice was dismissed.
	 *
	 * @var string
	 */
	const DISMISS_META = 'tagbridge_setup_notice_dismissed';

	/**
	 * Query arg used for the setup dismiss link.
	 *
	 * @var string
	 */
	const DISMISS_ARG = 'tagbridge_dismiss_setup';

	/**
	 * Query arg used to dismiss launched-integration alerts.
	 *
	 * @var string
	 */
	const DISMISS_LAUNCHED_ARG = 'tagbridge_dismiss_launched';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_launched' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_launched_notice' ) );
	}

	/**
	 * Handle a setup-notice dismiss request.
	 *
	 * @return void
	 */
	public function maybe_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_ARG );

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ), $redirect ) : admin_url() );
		exit;
	}

	/**
	 * Handle dismissing launch notifications for newly shipped integrations.
	 *
	 * @return void
	 */
	public function maybe_dismiss_launched() {
		if ( ! isset( $_GET[ self::DISMISS_LAUNCHED_ARG ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_LAUNCHED_ARG );

		foreach ( array_keys( Catalog::launched_watched( $this->registry ) ) as $id ) {
			Options::set_watched( $id, false );
		}

		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? remove_query_arg( array( self::DISMISS_LAUNCHED_ARG, '_wpnonce' ), $redirect ) : admin_url() );
		exit;
	}

	/**
	 * Whether at least one enabled integration is fully configured.
	 *
	 * @return bool
	 */
	private function any_configured() {
		foreach ( $this->registry->ids() as $id ) {
			if ( ! $this->registry->is_enabled( $id ) ) {
				continue;
			}
			$module = $this->registry->get( $id );
			if ( $module instanceof ConfigurableModule && $module->is_configured() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Show the first-run setup notice when appropriate.
	 *
	 * @return void
	 */
	public function maybe_show_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->any_configured() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && SettingsPage::hook_suffix() === $screen->id ) {
			return;
		}

		$setup_url   = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Tagbridge', 'tagbridge' ); ?></strong>
				&nbsp;&mdash;&nbsp;
				<?php esc_html_e( 'Finish setup to start sending events to your analytics tools. It takes about a minute.', 'tagbridge' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $setup_url ); ?>">
					<?php esc_html_e( 'Set up Tagbridge', 'tagbridge' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'tagbridge' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Notify the admin when one or more watched integrations have launched.
	 *
	 * @return void
	 */
	public function maybe_show_launched_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$launched = Catalog::launched_watched( $this->registry );
		if ( empty( $launched ) ) {
			return;
		}

		$labels = array();
		foreach ( $launched as $meta ) {
			$labels[] = $meta['label'];
		}

		if ( 1 === count( $launched ) ) {
			$ids       = array_keys( $launched );
			$cta_url   = admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&module=' . rawurlencode( $ids[0] ) );
			$cta_label = __( 'Configure', 'tagbridge' );
		} else {
			$cta_url   = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
			$cta_label = __( 'View integrations', 'tagbridge' );
		}

		$message = sprintf(
			/* translators: %s: comma-separated list of newly available integration names. */
			_n(
				'%s is now available in Tagbridge.',
				'%s are now available in Tagbridge.',
				count( $labels ),
				'tagbridge'
			),
			implode( ', ', $labels )
		);

		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_LAUNCHED_ARG, '1' ), self::DISMISS_LAUNCHED_ARG );
		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Tagbridge', 'tagbridge' ); ?></strong>
				&nbsp;&mdash;&nbsp;
				<?php echo esc_html( $message ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $cta_url ); ?>">
					<?php echo esc_html( $cta_label ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'tagbridge' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
