<?php
/**
 * Locale-independent ASCII case fold (Task 3.1, review round t31-r2-14).
 *
 * Single owner of the fold every case-insensitive surface rides: the
 * duplicate fence and folded index in HeaderMap, the sensitive-name
 * vocabulary match in SecretMask (lower), and the HTTP method token's
 * normalization in HttpRequest (upper, t31-r3-10). Header names and
 * method tokens are ASCII by the token grammar, so the fold is ASCII —
 * and it must be LOCALE-INDEPENDENT: strtolower()/strtoupper() map each
 * byte through the C tolower()/toupper(), which glibc resolves through
 * the process LC_CTYPE locale, and in a Turkish locale the ASCII
 * capital I falls under the dotted-I rule (its lowercase is the
 * dotless ı, U+0131, with no single-byte spelling), so 'AUTHORIZATION'
 * would NOT fold to 'authorization' — the fence, the lookup, and the
 * masking vocabulary would silently disagree with each other and with
 * every hand-spelled comparison. An explicit byte-table fold has no
 * locale to consult: identical everywhere.
 *
 * No tr_* locale is generated on the development host (locale -a:
 * C, C.utf8, en_US.utf8, POSIX — setlocale('tr_TR.UTF-8') fails), so
 * the divergence is argued from PHP's/glibc's fold tables rather than
 * reproduced live; this is class-closure hardening, not a reproduced
 * defect.
 *
 * Pure stateless function, no environment access.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Support;

/**
 * Locale-independent ASCII lower-case fold.
 *
 * @since 0.1.0
 */
final class AsciiFold {

	/**
	 * Folds ASCII A-Z to a-z, leaving every other byte untouched.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to fold.
	 * @return string The folded bytes — identical in every locale.
	 */
	public static function lower( string $value ): string {
		return strtr( $value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Folds ASCII a-z to A-Z, leaving every other byte untouched.
	 *
	 * The upper twin of lower() (review round t31-r3-10): the HTTP
	 * method token's normalization is the one case-RAISING surface the
	 * shared VOs carry, and strtoupper() consults the same LC_CTYPE
	 * locale strtolower() does — under the Turkish dotted-I rule an
	 * ASCII 'i' upper-cases to the two-byte 'İ' (U+0130), which the
	 * method grammar (visible ASCII tokens only) would then REJECT:
	 * 'post' would stop being a method in exactly the processes whose
	 * locale folds it. An explicit byte table has no locale to consult;
	 * like lower(), it is identical everywhere by construction.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to fold.
	 * @return string The folded bytes — identical in every locale.
	 */
	public static function upper( string $value ): string {
		return strtr( $value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' );
	}
}
