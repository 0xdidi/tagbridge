<?php
/**
 * PostHog Feature Flag Gutenberg block and shortcode.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Flags;

/**
 * Registers the tagbridge/feature-flag dynamic block and [tagbridge_flag] shortcode.
 */
final class FeatureFlagBlock {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'tagbridge_flag', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the Gutenberg block and its editor script.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'tagbridge-feature-flag-block',
			TAGBRIDGE_URL . 'assets/js/block-feature-flag.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			TAGBRIDGE_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'tagbridge-feature-flag-block', 'tagbridge' );
		}

		register_block_type(
			'tagbridge/feature-flag',
			array(
				'api_version'     => 2,
				'editor_script'   => 'tagbridge-feature-flag-block',
				'attributes'      => array(
					'flagKey'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'matchValue'   => array(
						'type'    => 'string',
						'default' => 'true',
					),
					'defaultValue' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render callback for the tagbridge/feature-flag block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner block HTML.
	 * @return string
	 */
	public function render_block( array $attributes, $content = '' ) {
		$flag_key = isset( $attributes['flagKey'] ) ? (string) $attributes['flagKey'] : '';
		if ( '' === $flag_key ) {
			return '';
		}

		$match   = isset( $attributes['matchValue'] ) && '' !== $attributes['matchValue'] ? (string) $attributes['matchValue'] : 'true';
		$default = ! empty( $attributes['defaultValue'] );

		return Evaluator::matches( $flag_key, $match, $default ) ? (string) $content : '';
	}

	/**
	 * Render the [tagbridge_flag key="..." match="true" default="false"]...[/tagbridge_flag] shortcode.
	 *
	 * @param array<string,mixed>|string $atts    Shortcode attributes.
	 * @param string|null                $content Enclosed content.
	 * @return string
	 */
	public function render_shortcode( $atts, $content = null ) {
		$pairs = shortcode_atts(
			array(
				'key'     => '',
				'match'   => 'true',
				'default' => 'false',
			),
			is_array( $atts ) ? $atts : array(),
			'tagbridge_flag'
		);

		$flag_key = (string) $pairs['key'];
		if ( '' === $flag_key ) {
			return '';
		}

		$default = in_array( strtolower( (string) $pairs['default'] ), array( '1', 'true', 'yes' ), true );
		if ( ! Evaluator::matches( $flag_key, (string) $pairs['match'], $default ) ) {
			return '';
		}

		return do_shortcode( (string) $content );
	}
}
