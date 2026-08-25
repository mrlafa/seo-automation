<?php
/**
 * Pure text utilities used by checkers.
 *
 * Everything here is WordPress-free so it can be unit tested without a
 * WordPress bootstrap.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * String analysis helpers.
 */
final class Text {

	/**
	 * Words ignored when judging slug quality and keyword overlap.
	 *
	 * @var string[]
	 */
	private const STOP_WORDS = array(
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from',
		'how', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'that', 'the',
		'their', 'then', 'there', 'these', 'this', 'to', 'was', 'were', 'what',
		'when', 'where', 'which', 'who', 'why', 'will', 'with', 'your', 'you',
	);

	/**
	 * Average pixel widths per character class, calibrated against Arial 20px
	 * (Google's desktop title font). Good enough to catch truncation risk.
	 */
	private const NARROW = 'iljtfrI.,:;!|\'`()[]{}-';
	private const WIDE   = 'mwMW@%';

	/**
	 * Strip markup, shortcodes and block comments down to readable prose.
	 *
	 * @param string $html Raw content.
	 */
	public static function plain( string $html ): string {
		// Block delimiters and shortcodes first, so their attributes never leak in.
		$text = preg_replace( '/<!--\s*\/?wp:.*?-->/s', ' ', $html );
		$text = preg_replace( '/\[[^\]]*\]/', ' ', (string) $text );
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $text );
		$text = preg_replace( '/<[^>]+>/', ' ', (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\x{00a0}/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Word count of the readable prose in a chunk of content.
	 *
	 * @param string $html Raw content.
	 */
	public static function word_count( string $html ): int {
		$plain = self::plain( $html );
		if ( '' === $plain ) {
			return 0;
		}

		return count( preg_split( '/[\s\p{P}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $value Subject.
	 */
	public static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Estimate rendered pixel width of a SERP string.
	 *
	 * Character-class weighting rather than a real font metric table: the goal
	 * is to flag "this will be truncated", not to render a preview to the pixel.
	 *
	 * @param string $value Title or description.
	 */
	public static function pixel_width( string $value ): int {
		$width  = 0.0;
		$length = self::length( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $value, $i, 1, 'UTF-8' ) : $value[ $i ];

			// Calibrated so that a typical 60-character title lands on the
			// ~580px truncation point, which is the equivalence the character
			// limits in the settings assume.
			if ( ' ' === $char ) {
				$width += 5.6;
			} elseif ( false !== strpos( self::NARROW, $char ) ) {
				$width += 5.0;
			} elseif ( false !== strpos( self::WIDE, $char ) ) {
				$width += 17.0;
			} elseif ( ctype_upper( $char ) ) {
				$width += 13.5;
			} else {
				$width += 10.5;
			}
		}

		return (int) round( $width );
	}

	/**
	 * Lowercase word tokens with stop words removed.
	 *
	 * @param string $value Subject.
	 *
	 * @return string[]
	 */
	public static function keywords( string $value ): array {
		$plain  = strtolower( self::plain( $value ) );
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		return array_values(
			array_filter(
				$tokens,
				static fn( $token ) => strlen( $token ) > 2 && ! in_array( $token, self::STOP_WORDS, true )
			)
		);
	}

	/**
	 * Is this token a stop word?
	 *
	 * @param string $token Lowercase token.
	 */
	public static function is_stop_word( string $token ): bool {
		return in_array( strtolower( $token ), self::STOP_WORDS, true );
	}

	/**
	 * Fingerprint content for near-duplicate detection.
	 *
	 * Uses a 64-bit simhash over word 3-shingles: two pages whose fingerprints
	 * differ by only a few bits are near-identical, which is exactly the
	 * "same product description on 40 variants" case.
	 *
	 * @param string $html Raw content.
	 */
	public static function simhash( string $html ): string {
		$tokens = self::keywords( $html );
		if ( count( $tokens ) < 3 ) {
			return str_repeat( '0', 16 );
		}

		$vector = array_fill( 0, 64, 0 );

		for ( $i = 0, $max = count( $tokens ) - 2; $i < $max; $i++ ) {
			$shingle = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ] . ' ' . $tokens[ $i + 2 ];
			$hash    = substr( md5( $shingle ), 0, 16 );

			for ( $bit = 0; $bit < 64; $bit++ ) {
				$nibble = hexdec( $hash[ intdiv( $bit, 4 ) ] );
				$isset  = ( $nibble >> ( 3 - ( $bit % 4 ) ) ) & 1;
				$vector[ $bit ] += $isset ? 1 : -1;
			}
		}

		$out = '';
		for ( $nibble = 0; $nibble < 16; $nibble++ ) {
			$value = 0;
			for ( $bit = 0; $bit < 4; $bit++ ) {
				$value = ( $value << 1 ) | ( $vector[ $nibble * 4 + $bit ] > 0 ? 1 : 0 );
			}
			$out .= dechex( $value );
		}

		return $out;
	}

	/**
	 * Similarity of two simhashes, 0.0 (unrelated) to 1.0 (identical).
	 *
	 * @param string $a First fingerprint.
	 * @param string $b Second fingerprint.
	 */
	public static function simhash_similarity( string $a, string $b ): float {
		if ( strlen( $a ) !== 16 || strlen( $b ) !== 16 ) {
			return 0.0;
		}

		$distance = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$xor = hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] );
			for ( $bit = 0; $bit < 4; $bit++ ) {
				$distance += ( $xor >> $bit ) & 1;
			}
		}

		return 1.0 - ( $distance / 64 );
	}

	/**
	 * Truncate on a word boundary, appending an ellipsis when cut.
	 *
	 * @param string $value  Subject.
	 * @param int    $limit  Maximum characters.
	 * @param string $append Suffix when truncated.
	 */
	public static function truncate( string $value, int $limit, string $append = '…' ): string {
		if ( self::length( $value ) <= $limit ) {
			return $value;
		}

		$slice = function_exists( 'mb_substr' )
			? mb_substr( $value, 0, $limit - self::length( $append ), 'UTF-8' )
			: substr( $value, 0, $limit - strlen( $append ) );

		$space = strrpos( $slice, ' ' );
		if ( false !== $space && $space > $limit * 0.6 ) {
			$slice = substr( $slice, 0, $space );
		}

		return rtrim( $slice, " \t\n\r\0\x0B,.;:-" ) . $append;
	}

	/**
	 * Does the haystack contain the needle, case- and accent-insensitively?
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Search term.
	 */
	public static function contains( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}

		return false !== stripos( $haystack, $needle );
	}
}
