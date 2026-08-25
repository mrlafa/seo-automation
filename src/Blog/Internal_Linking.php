<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 4: suggest/insert internal links to existing published posts.
 * Deterministic and rule-based (no extra AI call): find candidate posts by
 * keyword overlap, then link the first matching mention of each title's
 * significant words inside the new content.
 */
class Internal_Linking {

	public static function run( $content_html, $keyword, $exclude_post_id = 0 ) {
		$limit = (int) Settings::get( 'internal_link_limit', 3 );
		if ( $limit <= 0 ) {
			return $content_html;
		}

		$candidates = self::find_candidates( $keyword, $exclude_post_id, $limit * 3 );
		if ( empty( $candidates ) ) {
			return $content_html;
		}

		$linked_count = 0;

		foreach ( $candidates as $candidate ) {
			if ( $linked_count >= $limit ) {
				break;
			}

			$title = $candidate->post_title;
			$phrase = self::pick_linkable_phrase( $title );
			if ( ! $phrase ) {
				continue;
			}

			// Only link a plain-text occurrence that isn't already inside a tag/anchor.
			$pattern = '/(?<!<a[^>]{0,300})\b(' . preg_quote( $phrase, '/' ) . ')\b(?![^<]*<\/a>)/i';

			$new_content = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $candidate ) {
					return sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_permalink( $candidate->ID ) ),
						$matches[1]
					);
				},
				$content_html,
				1,
				$replacements
			);

			if ( $replacements > 0 && null !== $new_content ) {
				$content_html = $new_content;
				$linked_count++;
			}
		}

		return $content_html;
	}

	/**
	 * Product mode: guarantee at least one real, non-hallucinated link to
	 * the product itself (and, where resolvable, its category page). Runs
	 * deterministically in PHP rather than trusting the model to produce a
	 * correct URL — it appends a short CTA paragraph rather than editing
	 * the article body, so it can't corrupt AI-generated HTML.
	 */
	public static function ensure_product_cta( $content_html, array $product ) {
		if ( empty( $product['permalink'] ) ) {
			return $content_html;
		}

		// Already contains a real link to the product? Nothing to add.
		if ( false !== strpos( $content_html, $product['permalink'] ) ) {
			return $content_html;
		}

		$cta = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $product['permalink'] ),
			sprintf(
				/* translators: %s: product title */
				esc_html__( 'View the %s', 'seo-automation' ),
				esc_html( $product['title'] )
			)
		);

		if ( ! empty( $product['category_links'][0]['url'] ) ) {
			$cta .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $product['category_links'][0]['url'] ),
				sprintf(
					/* translators: %s: category name */
					esc_html__( 'Browse more in %s', 'seo-automation' ),
					esc_html( $product['category_links'][0]['name'] )
				)
			);
		}

		if ( ! empty( $product['related_products'][0]['url'] ) ) {
			$cta .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $product['related_products'][0]['url'] ),
				sprintf(
					/* translators: %s: related product title */
					esc_html__( 'You may also like: %s', 'seo-automation' ),
					esc_html( $product['related_products'][0]['title'] )
				)
			);
		}

		return $content_html . "\n" . $cta;
	}

	protected static function find_candidates( $keyword, $exclude_post_id, $limit ) {
		$terms = preg_split( '/\s+/', trim( $keyword ) );
		$terms = array_filter( $terms, function ( $t ) {
			return strlen( $t ) > 2;
		} );

		if ( empty( $terms ) ) {
			return array();
		}

		$search = implode( ' ', $terms );

		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				's'              => $search,
				'exclude'        => array( $exclude_post_id ),
				'orderby'        => 'relevance',
			)
		);
	}

	/**
	 * Reduce a post title down to a short, safely-matchable phrase
	 * (avoid linking on single stopwords or overly generic titles).
	 */
	protected static function pick_linkable_phrase( $title ) {
		$title = trim( wp_strip_all_tags( $title ) );
		if ( strlen( $title ) < 4 ) {
			return false;
		}

		// Prefer the first 4-6 significant words so we don't require the
		// entire (often long) title to appear verbatim in the new content.
		$words = preg_split( '/\s+/', $title );
		$slice = array_slice( $words, 0, min( 6, count( $words ) ) );

		return implode( ' ', $slice );
	}
}
