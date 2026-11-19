<?php
/**
 * Integration module contract.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

/**
 * The lifecycle contract every integration module implements.
 */
interface ModuleInterface {

	/**
	 * The stable, unique module id (used as the settings key and slug).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * The human-readable module name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * A one-line description shown in the integrations list.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Integration icon: a plugin-relative image path, URL, or Dashicons class.
	 *
	 * @return string
	 */
	public function icon();

	/**
	 * Integration category slug for grouping in the hub (e.g. analytics, ads).
	 *
	 * @return string
	 */
	public function category();

	/**
	 * WordPress Consent API category governing this integration.
	 *
	 * @return string One of the Tagbridge\Core\Privacy\ConsentCategory constants.
	 */
	public function consent_category();

	/**
	 * Wire the module's runtime hooks. Only called when the module is enabled.
	 *
	 * @return void
	 */
	public function boot();
}
