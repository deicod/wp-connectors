<?php
/**
 * Secret-masking policy for safe debug rendering (Task 3.1).
 *
 * Single owner of the two facts every safe debug form depends on: what a
 * masked secret looks like (an ellipsis plus the last four characters —
 * enough to correlate a value across log lines, never enough to use it),
 * and which HTTP header names always count as secret-bearing regardless
 * of the value they carry.
 *
 * Pure PHP, byte-oriented: credentials and tokens are ASCII in practice,
 * and a byte-wise tail cannot be split by a multibyte boundary.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Support;

/**
 * Masks secret values and identifies secret-bearing header names.
 *
 * @since 0.1.0
 */
final class SecretMask {

	/**
	 * The mask marker (horizontal ellipsis).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const MASK = '…';

	/**
	 * How many trailing characters of a masked secret stay visible.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const VISIBLE_TAIL = 4;

	/**
	 * Shortest secret whose tail is shown at all.
	 *
	 * At or below this length, four visible characters would reveal half
	 * the secret or more, so the mask shows nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MIN_LENGTH_FOR_VISIBLE_TAIL = 8;

	/**
	 * Header names (lowercase) whose values are always masked.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAMES = array( 'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key' );

	/**
	 * Masks a secret value: ellipsis plus last four characters.
	 *
	 * Null and short values show the ellipsis only.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value The secret, or null.
	 * @return string The masked rendering.
	 */
	public static function mask( ?string $value ): string {
		if ( null === $value || \strlen( $value ) <= self::MIN_LENGTH_FOR_VISIBLE_TAIL ) {
			return self::MASK;
		}

		return self::MASK . substr( $value, -self::VISIBLE_TAIL );
	}

	/**
	 * Whether a header name is secret-bearing (case-insensitive).
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name.
	 * @return bool True when the header's value must always be masked.
	 */
	public static function is_sensitive_header_name( string $name ): bool {
		return \in_array( strtolower( $name ), self::SENSITIVE_HEADER_NAMES, true );
	}
}
