<?php
/**
 * Standard consent categories aligned with the WordPress Consent API.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Privacy;

/**
 * Consent category constants and validation helpers.
 */
final class ConsentCategory {

	/**
	 * Strictly necessary / functional integrations (e.g. support widgets).
	 *
	 * @var string
	 */
	const FUNCTIONAL = 'functional';

	/**
	 * Preference storage integrations.
	 *
	 * @var string
	 */
	const PREFERENCES = 'preferences';

	/**
	 * Analytics and product intelligence integrations.
	 *
	 * @var string
	 */
	const STATISTICS = 'statistics';

	/**
	 * Anonymous-only statistics integrations.
	 *
	 * @var string
	 */
	const STATISTICS_ANONYMOUS = 'statistics-anonymous';

	/**
	 * Advertising, remarketing, and attribution integrations.
	 *
	 * @var string
	 */
	const MARKETING = 'marketing';

	/**
	 * All supported consent categories.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::FUNCTIONAL,
			self::PREFERENCES,
			self::STATISTICS,
			self::STATISTICS_ANONYMOUS,
			self::MARKETING,
		);
	}

	/**
	 * Whether a string matches a known consent category.
	 *
	 * @param string $category Consent category slug.
	 * @return bool
	 */
	public static function is_valid( $category ) {
		return in_array( (string) $category, self::all(), true );
	}
}
