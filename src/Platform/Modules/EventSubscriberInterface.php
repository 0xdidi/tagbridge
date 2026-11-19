<?php
/**
 * Contract for modules that subscribe to the shared platform EventBus.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

use Tagbridge\Core\Events\Event;

/**
 * A module that processes normalized events from the shared EventBus.
 */
interface EventSubscriberInterface extends ModuleInterface {

	/**
	 * Handle a normalized event dispatched by the EventBus.
	 *
	 * @param Event $event Normalized event with properties and delivery context.
	 * @return void
	 */
	public function handle_event( Event $event );
}
