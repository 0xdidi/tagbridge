<?php
/**
 * WordPress core Event Source.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Events\Sources;

use Tagbridge\Core\Events\Event;
use Tagbridge\Core\Events\Schema;
use Tagbridge\Platform\Events\EventBus;

/**
 * Emits core WordPress user events onto the shared EventBus.
 */
final class WordPressSource {

	/**
	 * Shared event bus.
	 *
	 * @var EventBus
	 */
	private $bus;

	/**
	 * Constructor.
	 *
	 * @param EventBus $bus Shared event bus.
	 */
	public function __construct( EventBus $bus ) {
		$this->bus = $bus;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( $this, 'on_register' ), 10, 1 );
	}

	/**
	 * Handle a successful WordPress login.
	 *
	 * @param string        $user_login Username.
	 * @param \WP_User|null $user       Logged-in user object.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$event = new Event(
			Schema::USER_LOGGED_IN,
			array( 'login_source' => 'wordpress' ),
			$this->user_context( $user )
		);

		$this->bus->dispatch( $event );
	}

	/**
	 * Handle a new WordPress user registration.
	 *
	 * @param int $user_id Newly created user id.
	 * @return void
	 */
	public function on_register( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$event = new Event(
			Schema::USER_REGISTERED,
			array( 'registration_source' => 'wordpress' ),
			$this->user_context( $user )
		);

		$this->bus->dispatch( $event );
	}

	/**
	 * Build normalized user context for subscribers.
	 *
	 * @param \WP_User $user WordPress user object.
	 * @return array<string,mixed>
	 */
	private function user_context( \WP_User $user ) {
		$primary_role = ( is_array( $user->roles ) && ! empty( $user->roles ) ) ? (string) reset( $user->roles ) : '';

		return array(
			'source'  => 'wordpress',
			'user_id' => (int) $user->ID,
			'wp_user' => $user,
			'user'    => array(
				'id'    => (int) $user->ID,
				'email' => (string) $user->user_email,
				'name'  => (string) $user->display_name,
				'role'  => $primary_role,
			),
		);
	}
}
