<?php
/**
 * Markup extraction helpers.
 *
 * Regex-based on purpose: this runs over stored post_content for every post on
 * the site, where a full DOM parse per row is far too expensive and the content
 * is a fragment rather than a valid document. Rendered-page inspection (see
 * PageFetcher) uses DOMDocument instead.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts links, images and headings from content.
 */
final class Html {

	/**
	 * Extract anchors.
	 *
	 * @param string $html Content.
	 *
	 * @return array<int,array{href:string,text:string,rel:string,target:string,raw:string}>
	 */
	public static function links( string $html ): array {
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$links = array();
		foreach ( $matches as $match ) {
			$attributes = self::attributes( $match[1] );
			if ( empty( $attributes['href'] ) ) {
				continue;
			}

			$links[] = array(
				'href'   => $attributes['href'],
				'text'   => Text::plain( $match[2] ),
				'rel'    => $attributes['rel'] ?? '',
				'target' => $attributes['target'] ?? '',
				'raw'    => $match[0],
			);
		}

		return $links;
	}

	/**
	 * Extract images.
	 *
	 * @param string $html Content.
	 *
	 * @return array<int,array{src:string,alt:string|null,title:string,width:string,height:string,loading:string,raw:string}>
	 */
	public static function images( string $html ): array {
		if ( ! preg_match_all( '/<img\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$images = array();
		foreach ( $matches as $match ) {
			$attributes = self::attributes( $match[1] );

			$images[] = array(
				'src'     => $attributes['src'] ?? '',
				// null means the attribute is absent; '' means it is present but empty
				// (a legitimate choice for decorative images), and the two are treated
				// differently by the alt-text checker.
				'alt'     => array_key_exists( 'alt', $attributes ) ? $attributes['alt'] : null,
				'title'   => $attributes['title'] ?? '',
				'width'   => $attributes['width'] ?? '',
				'height'  => $attributes['height'] ?? '',
				'loading' => $attributes['loading'] ?? '',
				'raw'     => $match[0],
			);
		}

		return $images;
	}

	/**
	 * Extract headings in document order.
	 *
	 * @param string $html Content.
	 *
	 * @return array<int,array{level:int,text:string,raw:string}>
	 */
	public static function headings( string $html ): array {
		if ( ! preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$headings = array();
		foreach ( $matches as $match ) {
			$headings[] = array(
				'level' => (int) $match[1],
				'text'  => Text::plain( $match[2] ),
				'raw'   => $match[0],
			);
		}

		return $headings;
	}

	/**
	 * Parse an attribute string into a map.
	 *
	 * @param string $attribute_string Everything between the tag name and '>'.
	 *
	 * @return array<string,string>
	 */
	public static function attributes( string $attribute_string ): array {
		$attributes = array();

		// PREG_UNMATCHED_AS_NULL keeps alt="" (empty but present) distinguishable
		// from a branch of the alternation that never participated.
		$matched = preg_match_all(
			'/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\s"\'>]+)))?/',
			$attribute_string,
			$matches,
			PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
		);

		if ( ! $matched ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			if ( null === $match[1] || '' === $match[1] ) {
				continue;
			}

			$value = $match['dq'] ?? $match['sq'] ?? $match['bare'] ?? '';

			$attributes[ strtolower( $match[1] ) ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $attributes;
	}

	/**
	 * Pull JSON-LD blocks out of a rendered document.
	 *
	 * @param string $html Rendered HTML.
	 *
	 * @return array<int,array<string,mixed>> Decoded graphs.
	 */
	public static function json_ld( string $html ): array {
		if ( ! preg_match_all(
			'#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches
		) ) {
			return array();
		}

		$graphs = array();
		foreach ( $matches[1] as $raw ) {
			$decoded = json_decode( trim( $raw ), true );
			if ( is_array( $decoded ) ) {
				$graphs[] = $decoded;
			}
		}

		return $graphs;
	}

	/**
	 * Flatten a JSON-LD payload into a list of typed nodes.
	 *
	 * Handles both `@graph` containers and bare arrays of nodes.
	 *
	 * @param array<string,mixed>|array<int,mixed> $graph Decoded JSON-LD.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function flatten_json_ld( array $graph ): array {
		$nodes = array();

		if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
			foreach ( $graph['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}

			return $nodes;
		}

		if ( isset( $graph['@type'] ) ) {
			return array( $graph );
		}

		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Normalise a schema node's `@type`, which may be a string or an array.
	 *
	 * @param array<string,mixed> $node Schema node.
	 *
	 * @return string[]
	 */
	public static function node_types( array $node ): array {
		$type = $node['@type'] ?? '';

		return array_map( 'strval', is_array( $type ) ? $type : array( $type ) );
	}

	/**
	 * First matching `<meta>` content value.
	 *
	 * @param string $html      Rendered HTML.
	 * @param string $attribute 'name' or 'property'.
	 * @param string $value     Attribute value to match.
	 */
	public static function meta( string $html, string $attribute, string $value ): ?string {
		$pattern = sprintf(
			'/<meta\b[^>]*%1$s=["\']%2$s["\'][^>]*>/i',
			preg_quote( $attribute, '/' ),
			preg_quote( $value, '/' )
		);

		if ( ! preg_match( $pattern, $html, $match ) ) {
			return null;
		}

		$attributes = self::attributes( $match[0] );

		return $attributes['content'] ?? null;
	}
}
