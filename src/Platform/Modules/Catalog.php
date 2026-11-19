<?php
/**
 * Upcoming integrations catalog and local launch-watchlist helpers.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

use Tagbridge\Platform\Settings\Options;

/**
 * Planned integrations catalog and launch-notification matching.
 */
final class Catalog {

	/**
	 * Planned integrations queued for upcoming Tagbridge releases.
	 *
	 * @return array<string,array{label:string,description:string,category:string,icon:string}>
	 */
	public static function all() {
		$items = array(
			'webhooks'  => array(
				'label'       => __( 'Webhooks', 'tagbridge' ),
				'description' => __( 'Send signed JSON event payloads to Zapier, Make, n8n, or your own endpoint.', 'tagbridge' ),
				'category'    => 'automation',
				'icon'        => 'assets/icons/webhooks.svg',
			),
			'ga4'       => array(
				'label'       => __( 'Google Analytics 4', 'tagbridge' ),
				'description' => __( 'Client gtag.js, server-side Measurement Protocol for WooCommerce, and Consent Mode v2.', 'tagbridge' ),
				'category'    => 'analytics',
				'icon'        => 'assets/icons/ga4.svg',
			),
			'meta-capi' => array(
				'label'       => __( 'Meta Pixel & Conversions API', 'tagbridge' ),
				'description' => __( 'Dual Pixel and server CAPI with event_id deduplication and WooCommerce funnel tracking.', 'tagbridge' ),
				'category'    => 'ads',
				'icon'        => 'assets/icons/meta-capi.svg',
			),
			'klaviyo'   => array(
				'label'       => __( 'Klaviyo', 'tagbridge' ),
				'description' => __( 'Server-side WooCommerce order, checkout, and customer profile sync for email and SMS flows.', 'tagbridge' ),
				'category'    => 'crm',
				'icon'        => 'assets/icons/klaviyo.svg',
			),
			'clarity'   => array(
				'label'       => __( 'Microsoft Clarity', 'tagbridge' ),
				'description' => __( 'Heatmaps and session recordings with user identity, WordPress tags, and consent gating.', 'tagbridge' ),
				'category'    => 'analytics',
				'icon'        => 'assets/icons/clarity.svg',
			),
			'plausible' => array(
				'label'       => __( 'Plausible Analytics', 'tagbridge' ),
				'description' => __( 'Privacy-first analytics with custom domain proxy, WooCommerce revenue goals, and custom props.', 'tagbridge' ),
				'category'    => 'analytics',
				'icon'        => 'assets/icons/plausible.svg',
			),
			'intercom'  => array(
				'label'       => __( 'Intercom', 'tagbridge' ),
				'description' => __( 'Support messenger with server-side HMAC identity verification and WooCommerce customer context.', 'tagbridge' ),
				'category'    => 'support',
				'icon'        => 'assets/icons/intercom.svg',
			),
			'slack'     => array(
				'label'       => __( 'Slack & Discord Alerts', 'tagbridge' ),
				'description' => __( 'Real-time notifications in Slack or Discord when orders complete or new users register.', 'tagbridge' ),
				'category'    => 'automation',
				'icon'        => 'assets/icons/slack.svg',
			),
		);

		/**
		 * Filter the list of upcoming Tagbridge integrations shown in the Hub.
		 *
		 * @param array<string,array{label:string,description:string,category:string,icon:string}> $items Upcoming integrations.
		 */
		return (array) apply_filters( 'tagbridge_upcoming_integrations', $items );
	}

	/**
	 * Upcoming integrations that are not yet available in the live Registry.
	 *
	 * Once a module ships in Registry, it automatically graduates out of this list.
	 *
	 * @param Registry $registry Active module registry.
	 * @return array<string,array{label:string,description:string,category:string,icon:string}>
	 */
	public static function upcoming( Registry $registry ) {
		$filtered = array();
		foreach ( self::all() as $id => $item ) {
			if ( ! $registry->has( $id ) ) {
				$filtered[ $id ] = $item;
			}
		}
		return $filtered;
	}

	/**
	 * Watched integration ids that have now shipped in the live Registry.
	 *
	 * @param Registry $registry Active module registry.
	 * @return array<string,array<string,mixed>> Metadata keyed by launched module id.
	 */
	public static function launched_watched( Registry $registry ) {
		$launched = array();
		foreach ( Options::watchlist() as $id ) {
			if ( ! $registry->has( $id ) ) {
				continue;
			}
			$meta = $registry->meta( $id );
			if ( null !== $meta ) {
				$launched[ $id ] = $meta;
			}
		}
		return $launched;
	}
}
