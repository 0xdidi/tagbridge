<?php
/**
 * Module registry.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Platform\Events\EventBus;
use Tagbridge\Platform\Settings\Options;

/**
 * Registers, resolves, and boots integration modules.
 */
final class Registry {

	/**
	 * Module manifest, keyed by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $manifest;

	/**
	 * Instantiated modules, keyed by id (lazy).
	 *
	 * @var array<string,ModuleInterface>
	 */
	private $instances = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $manifest Module manifest.
	 */
	public function __construct( array $manifest ) {
		$this->manifest = $manifest;
	}

	/**
	 * All known module ids, in registration order.
	 *
	 * @return string[]
	 */
	public function ids() {
		return array_keys( $this->manifest );
	}

	/**
	 * Whether a module id exists in the manifest.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->manifest[ $id ] );
	}

	/**
	 * Whether a module is enabled, without instantiating it.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function is_enabled( $id ) {
		if ( ! isset( $this->manifest[ $id ] ) ) {
			return false;
		}
		return Options::is_module_enabled( $id, ! empty( $this->manifest[ $id ]['default'] ) );
	}

	/**
	 * Lightweight metadata for a module, read from the manifest without
	 * instantiating the module class when all metadata keys are supplied.
	 *
	 * @param string $id Module id.
	 * @return array{id:string,label:string,description:string,category:string,consent_category:string,icon:string,default:bool}|null
	 */
	public function meta( $id ) {
		if ( ! isset( $this->manifest[ $id ] ) ) {
			return null;
		}

		$entry          = $this->manifest[ $id ];
		$needs_instance = ! isset( $entry['label'], $entry['description'], $entry['category'], $entry['consent_category'], $entry['icon'] );
		$module         = $needs_instance ? $this->get( $id ) : null;

		$label = $module ? $module->label() : (string) $id;
		if ( isset( $entry['label'] ) ) {
			$label = $entry['label'] instanceof \Closure ? (string) $entry['label']() : (string) $entry['label'];
		}

		$description = $module ? $module->description() : '';
		if ( isset( $entry['description'] ) ) {
			$description = $entry['description'] instanceof \Closure ? (string) $entry['description']() : (string) $entry['description'];
		}

		return array(
			'id'               => (string) $id,
			'label'            => $label,
			'description'      => $description,
			'category'         => isset( $entry['category'] ) ? (string) $entry['category'] : ( $module ? $module->category() : 'general' ),
			'consent_category' => isset( $entry['consent_category'] ) ? (string) $entry['consent_category'] : ( $module ? $module->consent_category() : ConsentCategory::STATISTICS ),
			'icon'             => isset( $entry['icon'] ) ? (string) $entry['icon'] : ( $module ? $module->icon() : 'dashicons-admin-generic' ),
			'default'          => ! empty( $entry['default'] ),
		);
	}

	/**
	 * Get (and lazily instantiate) a module by id.
	 *
	 * @param string $id Module id.
	 * @return ModuleInterface|null
	 */
	public function get( $id ) {
		if ( ! isset( $this->manifest[ $id ] ) ) {
			return null;
		}
		if ( ! isset( $this->instances[ $id ] ) ) {
			$class                  = $this->manifest[ $id ]['class'];
			$this->instances[ $id ] = new $class();
		}
		return $this->instances[ $id ];
	}

	/**
	 * Every module instance, in registration order (instantiates all; admin use).
	 *
	 * @return ModuleInterface[]
	 */
	public function all() {
		$modules = array();
		foreach ( $this->ids() as $id ) {
			$modules[ $id ] = $this->get( $id );
		}
		return $modules;
	}

	/**
	 * Boot every enabled module and subscribe event-capable modules to the EventBus.
	 * Disabled modules are never instantiated here.
	 *
	 * @param EventBus|null $bus Optional shared event bus.
	 * @return void
	 */
	public function boot_enabled( ?EventBus $bus = null ) {
		foreach ( $this->ids() as $id ) {
			if ( ! $this->is_enabled( $id ) ) {
				continue;
			}

			$module = $this->get( $id );
			if ( null === $module ) {
				continue;
			}

			$module->boot();

			if ( null !== $bus && $module instanceof EventSubscriberInterface ) {
				$bus->subscribe( $module );
			}
		}
	}
}
