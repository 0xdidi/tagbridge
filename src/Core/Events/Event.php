<?php
/**
 * Immutable event value object for the shared EventBus.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Events;

/**
 * Normalized event payload dispatched through the platform EventBus.
 */
final class Event {

	/**
	 * Deterministic unique event identifier (UUIDv4).
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Canonical event name (from Schema).
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Event properties intended for downstream destinations.
	 *
	 * @var array<string,mixed>
	 */
	private $properties;

	/**
	 * Internal delivery context (source, user, order, consent flags).
	 *
	 * @var array<string,mixed>
	 */
	private $context;

	/**
	 * Unix timestamp when the event was created.
	 *
	 * @var int
	 */
	private $timestamp;

	/**
	 * Constructor.
	 *
	 * @param string              $name       Canonical event name.
	 * @param array<string,mixed> $properties Event properties.
	 * @param array<string,mixed> $context    Internal delivery context.
	 * @param string              $id         Optional explicit event id.
	 * @param int                 $timestamp  Optional Unix timestamp.
	 */
	public function __construct( $name, array $properties = array(), array $context = array(), $id = '', $timestamp = 0 ) {
		$this->name       = (string) $name;
		$this->properties = $properties;
		$this->context    = $context;
		$this->id         = '' !== (string) $id ? (string) $id : self::generate_id();
		$this->timestamp  = (int) $timestamp > 0 ? (int) $timestamp : time();
	}

	/**
	 * Generate a random RFC 4122 UUIDv4 identifier using native PHP primitives.
	 *
	 * @return string
	 */
	public static function generate_id() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * The unique event id (useful for deduplication across client/server).
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * The canonical event name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * The event properties array.
	 *
	 * @return array<string,mixed>
	 */
	public function properties() {
		return $this->properties;
	}

	/**
	 * The Unix timestamp when the event occurred.
	 *
	 * @return int
	 */
	public function timestamp() {
		return $this->timestamp;
	}

	/**
	 * Read the full context array or a single context value with fallback.
	 *
	 * @param string|null $key      Context key, or null for the full array.
	 * @param mixed       $fallback Value returned when key is not present.
	 * @return mixed
	 */
	public function context( $key = null, $fallback = null ) {
		if ( null === $key ) {
			return $this->context;
		}

		$key = (string) $key;
		return array_key_exists( $key, $this->context ) ? $this->context[ $key ] : $fallback;
	}

	/**
	 * Return a copy of this event with merged context entries.
	 *
	 * @param array<string,mixed> $extra Extra context values.
	 * @return self
	 */
	public function with_context( array $extra ) {
		$clone          = clone $this;
		$clone->context = array_merge( $this->context, $extra );

		return $clone;
	}

	/**
	 * Export the public event payload as a plain array.
	 *
	 * @return array{id:string,name:string,properties:array<string,mixed>,timestamp:int}
	 */
	public function to_array() {
		return array(
			'id'         => $this->id,
			'name'       => $this->name,
			'properties' => $this->properties,
			'timestamp'  => $this->timestamp,
		);
	}
}
