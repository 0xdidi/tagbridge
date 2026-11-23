<?php
/**
 * Webhook payload builder, HMAC-SHA256 signer, and HTTP dispatcher.
 *
 * @package Tagbridge\Modules\Webhooks
 */

namespace Tagbridge\Modules\Webhooks;

use Tagbridge\Core\Events\Event;

/**
 * Sends normalized, optionally signed JSON event payloads to the configured endpoint.
 */
final class Dispatcher {

	/**
	 * Transient key storing the latest delivery outcome for admin feedback.
	 *
	 * @var string
	 */
	const LAST_DELIVERY_TRANSIENT = 'tagbridge_webhooks_last_delivery';

	/**
	 * Build the normalized JSON-serializable webhook payload for an Event.
	 *
	 * @param Event $event Normalized event.
	 * @return array<string,mixed>
	 */
	public static function build_payload( Event $event ) {
		return array(
			'event_id'   => $event->id(),
			'event'      => $event->name(),
			'timestamp'  => gmdate( 'c', $event->timestamp() ),
			'site_url'   => function_exists( 'home_url' ) ? home_url() : '',
			'source'     => (string) $event->context( 'source', 'wordpress' ),
			'properties' => $event->properties(),
		);
	}

	/**
	 * Compute the HMAC-SHA256 signature header value for a JSON body.
	 *
	 * @param string $json_body Encoded JSON payload.
	 * @param string $secret    Shared signing secret.
	 * @return string
	 */
	public static function sign( $json_body, $secret ) {
		return 'sha256=' . hash_hmac( 'sha256', (string) $json_body, (string) $secret );
	}

	/**
	 * Deliver an event to the configured webhook endpoint.
	 *
	 * @param Event $event    Normalized event.
	 * @param bool  $blocking Whether to wait for the HTTP response (used for test deliveries).
	 * @return array{ok:bool,code:int,message:string}
	 */
	public static function send( Event $event, $blocking = false ) {
		$url = Settings::endpoint_url();
		if ( '' === $url ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'No webhook endpoint URL is configured.', 'tagbridge' ),
			);
		}

		if ( ! $blocking && ! Settings::is_event_enabled( $event->name() ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Event is disabled.', 'tagbridge' ),
			);
		}

		$body = wp_json_encode( self::build_payload( $event ) );
		if ( ! is_string( $body ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Failed to encode webhook payload.', 'tagbridge' ),
			);
		}

		$version = defined( 'TAGBRIDGE_VERSION' ) ? TAGBRIDGE_VERSION : '0.4.0';
		$headers = array(
			'Content-Type'      => 'application/json',
			'User-Agent'        => 'Tagbridge/' . $version,
			'X-Tagbridge-Event' => $event->name(),
			'X-Tagbridge-Id'    => $event->id(),
		);

		$secret = Settings::secret();
		if ( '' !== $secret ) {
			$headers['X-Tagbridge-Signature'] = self::sign( $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => $blocking ? 10 : 3,
				'blocking' => (bool) $blocking,
				'headers'  => $headers,
				'body'     => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
			);
			self::record_delivery( $event->name(), $result );
			return $result;
		}

		if ( ! $blocking ) {
			return array(
				'ok'      => true,
				'code'    => 0,
				'message' => '',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;
		$msg  = $ok
			/* translators: %d: HTTP status code. */
			? sprintf( __( 'Delivered (HTTP %d).', 'tagbridge' ), $code )
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Endpoint returned HTTP %d.', 'tagbridge' ), $code );

		$result = array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $msg,
		);
		self::record_delivery( $event->name(), $result );

		return $result;
	}

	/**
	 * Read the latest recorded delivery status, or null if none.
	 *
	 * @return array{ok:bool,code:int,message:string,event:string,timestamp:int}|null
	 */
	public static function last_delivery() {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$stored = get_transient( self::LAST_DELIVERY_TRANSIENT );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Persist the latest delivery outcome for admin diagnostics.
	 *
	 * @param string                                 $event_name Event name.
	 * @param array{ok:bool,code:int,message:string} $result     Delivery outcome.
	 * @return void
	 */
	private static function record_delivery( $event_name, array $result ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient(
			self::LAST_DELIVERY_TRANSIENT,
			array(
				'ok'        => ! empty( $result['ok'] ),
				'code'      => (int) $result['code'],
				'message'   => (string) $result['message'],
				'event'     => (string) $event_name,
				'timestamp' => time(),
			),
			DAY_IN_SECONDS
		);
	}
}
