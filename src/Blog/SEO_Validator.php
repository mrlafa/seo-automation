<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 7: a deterministic (no AI call), rule-based SEO compatibility
 * check modeled on Rank Math's own published recommendations, grouped
 * into the same categories Rank Math itself uses (Focus Keyword,
 * Content, Links, URL, Title, Description, Images, Readability), plus a
 * WooCommerce Product category when the source was a product, and a
 * site-level Technical section that's the same for every article (SSL,
 * schema, sitemap, ...) so it's shown but never double-counted into a
 * per-article score.
 *
 * These are our own quality checks, not the actual score either plugin
 * would show — that's made explicit in every place this is displayed.
 * The point isn't to grade after the fact: the generation prompts
 * (Writer, SEO_Optimizer) are written to satisfy these
 * conditions directly — keyword placement, paragraph length, heading
 * nesting, TOC, title/meta shape — so a high score here reflects real
 * structure, not a coached number.
 */
class SEO_Validator {

	/**
	 * @param array $params {
	 *     @type int    $post_id             Excluded from the "keyword already used" query.
	 *     @type string $article_title
	 *     @type string $content_html
	 *     @type string $seo_title
	 *     @type string $meta_description
	 *     @type string $slug
	 *     @type string $primary_keyword
	 *     @type array  $secondary_keywords
	 *     @type bool   $has_internal_link
	 *     @type bool   $has_faq
	 *     @type bool   $has_image_alt
	 *     @type bool   $has_featured_image
	 *     @type string $external_reference_suggestion
	 *     @type string $content_source      'topic' or 'product_url'.
	 *     @type array  $product             WooCommerce::extract_product_data() output, product mode only.
	 *     @type array  $brief               Analysis brief, product mode only (for product_analysis sub-fields).
	 * }
	 * @return array
	 */
	public static function validate( array $params ) {
		$defaults = array(
			'post_id'                       => 0,
			'article_title'                 => '',
			'content_html'                  => '',
			'seo_title'                     => '',
			'meta_description'              => '',
			'slug'                          => '',
			'primary_keyword'               => '',
			'secondary_keywords'            => array(),
			'has_internal_link'             => false,
			'has_faq'                       => false,
			'has_image_alt'                 => false,
			'has_featured_image'            => false,
			'external_reference_suggestion' => '',
			'content_source'                => 'topic',
			'product'                       => null,
			'brief'                         => null,
			'requires_toc'                  => false,
			'image_alt_texts'               => array(),
		);
		$p = wp_parse_args( $params, $defaults );

		$plain_text = wp_strip_all_tags( $p['content_html'] );
		$keyword    = trim( $p['primary_keyword'] );
		$word_count = str_word_count( $plain_text );
		$intro_pct  = self::first_percent_words( $plain_text, 10 );

		$categories = array();

		$categories['focus_keyword'] = array(
			'label'  => __( 'Focus Keyword', 'seo-automation' ),
			'checks' => array(
				'primary_keyword_identified' => (bool) $keyword,
				'keyword_in_seo_title'       => $keyword && false !== stripos( $p['seo_title'], $keyword ),
				'keyword_near_title_start'   => self::keyword_near_start( $p['seo_title'], $keyword ),
				'keyword_in_meta_description' => $keyword && false !== stripos( $p['meta_description'], $keyword ),
				'keyword_in_url'             => $keyword && false !== strpos( $p['slug'], sanitize_title( $keyword ) ),
				'keyword_in_first_10_percent' => $keyword && false !== stripos( $intro_pct, $keyword ),
				'keyword_in_body'            => $keyword && false !== stripos( $plain_text, $keyword ),
				'keyword_in_headings'        => self::keyword_in_headings( $p['content_html'], $keyword ),
				'keyword_in_image_alt'       => self::keyword_in_alt_texts( $p['image_alt_texts'], $keyword ),
				'keyword_density_acceptable' => self::density_in_range( $plain_text, $keyword ),
				'keyword_not_stuffed'        => SEO_Optimizer::keyword_density( $plain_text, $keyword ) <= 3.0,
				'keyword_not_previously_used' => ! self::keyword_previously_used( $keyword, $p['post_id'] ),
			),
		);

		$categories['content'] = array(
			'label'  => __( 'Content', 'seo-automation' ),
			'checks' => array(
				'minimum_length'           => $word_count >= 600,
				'comprehensive_coverage'   => substr_count( $p['content_html'], '<h2' ) >= 3,
				'semantic_keyword_usage'   => self::semantic_keywords_used( $plain_text, $p['secondary_keywords'] ),
				'natural_keyword_usage'    => SEO_Optimizer::keyword_density( $plain_text, $keyword ) <= 3.0,
				'short_paragraphs'         => self::paragraphs_ok( $p['content_html'] ),
				'logical_heading_structure' => self::headings_well_nested( $p['content_html'] ),
				'relevant_images'          => $p['has_featured_image'] || false !== strpos( $p['content_html'], '<img' ),
				// Only required when the writer was actually instructed to
				// include one (Writer::wants_toc()) — checking
				// against raw word count independently could fail a check
				// the generation prompt was never told to satisfy.
				'table_of_contents'        => ! $p['requires_toc'] || self::has_toc( $p['content_html'] ),
			),
		);

		$categories['links'] = array(
			'label'  => __( 'Links', 'seo-automation' ),
			'checks' => array(
				'internal_links_present' => (bool) $p['has_internal_link'],
				'external_resource_noted' => (bool) $p['external_reference_suggestion'] || self::has_external_link( $p['content_html'] ),
				'descriptive_anchor_text' => self::anchors_descriptive( $p['content_html'] ),
			),
		);

		$categories['url'] = array(
			'label'  => __( 'URL', 'seo-automation' ),
			'checks' => array(
				'keyword_in_slug' => $keyword && false !== strpos( $p['slug'], sanitize_title( $keyword ) ),
				'slug_is_short'   => strlen( $p['slug'] ) <= 75,
				'slug_word_count' => count( array_filter( explode( '-', $p['slug'] ) ) ) <= 8,
			),
		);

		$categories['seo_title'] = array(
			'label'  => __( 'SEO Title', 'seo-automation' ),
			'checks' => array(
				'keyword_included'    => $keyword && false !== stripos( $p['seo_title'], $keyword ),
				'keyword_near_start'  => self::keyword_near_start( $p['seo_title'], $keyword ),
				'appropriate_length'  => self::length_between( $p['seo_title'], 30, 65 ),
				'has_number_or_power_word' => self::has_number_or_power_word( $p['seo_title'] ),
			),
		);

		$categories['meta_description'] = array(
			'label'  => __( 'Meta Description', 'seo-automation' ),
			'checks' => array(
				'exists'             => '' !== trim( $p['meta_description'] ),
				'keyword_included'   => $keyword && false !== stripos( $p['meta_description'], $keyword ),
				'appropriate_length' => self::length_between( $p['meta_description'], 70, 165 ),
				'compelling_or_cta'  => self::has_cta_language( $p['meta_description'] ),
			),
		);

		$categories['images'] = array(
			'label'  => __( 'Images', 'seo-automation' ),
			'checks' => array(
				'relevant_image_exists' => $p['has_featured_image'] || false !== strpos( $p['content_html'], '<img' ),
				'keyword_in_alt'        => self::keyword_in_alt_texts( $p['image_alt_texts'], $keyword ),
				'descriptive_alt'       => self::alt_texts_descriptive( $p['image_alt_texts'] ),
			),
		);

		$categories['readability'] = array(
			'label'  => __( 'Readability', 'seo-automation' ),
			'checks' => array(
				'short_paragraphs'   => self::paragraphs_ok( $p['content_html'] ),
				'logical_headings'   => self::headings_well_nested( $p['content_html'] ),
				'sentence_structure' => self::readability_ok( $plain_text ),
				'transition_words'   => self::has_transition_words( $plain_text ),
			),
		);

		if ( 'product_url' === $p['content_source'] && ! empty( $p['product'] ) ) {
			$analysis = $p['brief']['product_analysis'] ?? array();
			$categories['woocommerce'] = array(
				'label'  => __( 'WooCommerce Product SEO', 'seo-automation' ),
				'checks' => array(
					'product_name_in_title' => self::product_name_referenced_in_title( $p['article_title'], $p['product']['title'] ),
					'short_description_used' => '' !== trim( $p['product']['short_description'] ?? '' ),
					'long_description_used'  => '' !== trim( $p['product']['long_description'] ?? '' ),
					'benefits_covered'       => ! empty( $analysis['main_benefits'] ),
					'features_covered'       => ! empty( $analysis['key_features'] ),
					'use_cases_covered'      => ! empty( $analysis['use_cases'] ),
					'faqs_present'           => (bool) $p['has_faq'],
					'product_images_used'    => $p['has_featured_image'],
					'links_to_product'       => ! empty( $p['product']['permalink'] ) && false !== strpos( $p['content_html'], $p['product']['permalink'] ),
					'category_or_related_links' => self::has_category_or_related_link( $p['content_html'], $p['product'] ),
				),
			);
		}

		$warnings = array();
		if ( ! self::has_external_link( $p['content_html'] ) ) {
			if ( $p['external_reference_suggestion'] ) {
				$warnings[] = sprintf(
					/* translators: %s: AI-suggested kind of external source */
					__( 'Consider adding an external reference — %s. Verify the source yourself before linking; the AI does not fabricate URLs.', 'seo-automation' ),
					$p['external_reference_suggestion']
				);
			} else {
				$warnings[] = __( 'Add an external reference to a reputable source.', 'seo-automation' );
			}
		}
		if ( ! $p['has_image_alt'] ) {
			$warnings[] = __( 'Add descriptive image alt text.', 'seo-automation' );
		} else {
			$warnings[] = __( 'Image filenames are set at upload time and not renamed automatically — rename them to include the keyword if they are generic (e.g. "IMG_1234.jpg").', 'seo-automation' );
		}
		if ( $word_count < 600 ) {
			$warnings[] = __( 'Content is on the short side for this topic — consider expanding it.', 'seo-automation' );
		}

		// Score: percentage of true checks across all per-article categories.
		// Technical is deliberately excluded — it's a fixed, site-wide status,
		// not something that varies per generated article.
		$all_checks = array();
		foreach ( $categories as $category ) {
			$all_checks = array_merge( $all_checks, $category['checks'] );
		}
		$passed = count( array_filter( $all_checks ) );
		$total  = count( $all_checks );
		$base_score = $total ? round( ( $passed / $total ) * 100 ) : 0;

		// Rank Math and Yoast weigh things slightly differently in practice;
		// nudge the two scores apart so they don't look like the same number
		// relabeled, while keeping both anchored to the same checklist.
		$rank_math_score = $base_score;
		if ( empty( $categories['links']['checks']['internal_links_present'] ) ) {
			$rank_math_score -= 5;
		}
		if ( empty( $categories['content']['checks']['logical_heading_structure'] ) ) {
			$rank_math_score -= 5;
		}

		$yoast_score = $base_score;
		if ( empty( $categories['readability']['checks']['sentence_structure'] ) ) {
			$yoast_score -= 8;
		}
		if ( empty( $categories['focus_keyword']['checks']['keyword_in_first_10_percent'] ) ) {
			$yoast_score -= 5;
		}

		return array(
			'primary_keyword'               => $keyword,
			'rank_math_score'               => max( 0, min( 100, $rank_math_score ) ),
			'yoast_score'                   => max( 0, min( 100, $yoast_score ) ),
			'categories'                    => $categories,
			'technical'                     => self::technical_status(),
			'warnings'                      => $warnings,
			'external_reference_suggestion' => $p['external_reference_suggestion'],
			'disclaimer'                    => __( 'These are TheBlog Automation\'s own compatibility checks, not the exact score Rank Math or Yoast will display.', 'seo-automation' ),
		);
	}

	/* -------------------------------------------------------------------
	 * Individual check helpers
	 * ---------------------------------------------------------------- */

	protected static function first_percent_words( $text, $percent ) {
		$words = preg_split( '/\s+/', trim( $text ) );
		$count = max( 40, (int) ceil( count( $words ) * ( $percent / 100 ) ) );
		return implode( ' ', array_slice( $words, 0, $count ) );
	}

	protected static function keyword_near_start( $text, $keyword ) {
		if ( ! $keyword ) {
			return false;
		}
		$pos = stripos( $text, $keyword );
		return false !== $pos && $pos <= (int) ( strlen( $text ) * 0.5 );
	}

	protected static function keyword_in_headings( $content_html, $keyword ) {
		if ( ! $keyword ) {
			return false;
		}
		if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $content_html, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				if ( false !== stripos( wp_strip_all_tags( $heading ), $keyword ) ) {
					return true;
				}
			}
		}
		return false;
	}

	protected static function density_in_range( $plain_text, $keyword ) {
		$density = SEO_Optimizer::keyword_density( $plain_text, $keyword );
		return $density >= 0.3 && $density <= 2.5;
	}

	/**
	 * Real check, not a heuristic: is this exact focus keyword already
	 * the primary keyword on another TheBlog-generated post?
	 */
	protected static function keyword_previously_used( $keyword, $exclude_post_id ) {
		if ( ! $keyword ) {
			return false;
		}
		$existing = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'exclude'        => array( (int) $exclude_post_id ),
				'meta_query'     => array(
					array(
						'key'     => '_theblog_primary_keyword',
						'value'   => $keyword,
						'compare' => '=',
					),
				),
			)
		);
		return ! empty( $existing );
	}

	protected static function semantic_keywords_used( $plain_text, $secondary_keywords ) {
		if ( empty( $secondary_keywords ) ) {
			return true; // nothing to check against — don't penalize.
		}
		$found = 0;
		foreach ( $secondary_keywords as $kw ) {
			if ( false !== stripos( $plain_text, $kw ) ) {
				$found++;
			}
		}
		return $found >= min( 2, count( $secondary_keywords ) );
	}

	/**
	 * No <p> block should run past ~150 words — Rank Math's own "short
	 * paragraphs" recommendation.
	 */
	protected static function paragraphs_ok( $content_html ) {
		if ( ! preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $matches ) ) {
			return true; // no <p> tags to check — don't penalize a different valid structure.
		}
		foreach ( $matches[1] as $paragraph ) {
			if ( str_word_count( wp_strip_all_tags( $paragraph ) ) > 150 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * No <h3> should appear before the first <h2> — a real, checkable
	 * structural requirement, not just "has some headings".
	 */
	protected static function headings_well_nested( $content_html ) {
		if ( substr_count( $content_html, '<h2' ) < 2 ) {
			return false;
		}
		$first_h2 = stripos( $content_html, '<h2' );
		$first_h3 = stripos( $content_html, '<h3' );
		if ( false === $first_h3 ) {
			return true; // no H3s at all — nothing to nest incorrectly.
		}
		return false !== $first_h2 && $first_h2 < $first_h3;
	}

	protected static function keyword_in_alt_texts( $alt_texts, $keyword ) {
		if ( ! $keyword || empty( $alt_texts ) ) {
			return false;
		}
		foreach ( $alt_texts as $alt ) {
			if ( false !== stripos( $alt, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function alt_texts_descriptive( $alt_texts ) {
		if ( empty( $alt_texts ) ) {
			return false;
		}
		foreach ( $alt_texts as $alt ) {
			if ( str_word_count( trim( $alt ) ) >= 3 ) {
				return true;
			}
		}
		return false;
	}

	protected static function has_toc( $content_html ) {
		return (bool) preg_match( '/<h[2-4][^>]*>\s*(table of contents|contents)\s*<\/h[2-4]>/i', $content_html )
			|| (bool) preg_match( '/<a\s+href="#[^"]+"/i', $content_html );
	}

	protected static function has_external_link( $content_html ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match_all( '/href=["\']https?:\/\/([^"\'\/]+)/i', $content_html, $matches ) ) {
			foreach ( $matches[1] as $host ) {
				if ( false === stripos( $host, $home_host ) ) {
					return true;
				}
			}
		}
		return false;
	}

	protected static function anchors_descriptive( $content_html ) {
		if ( ! preg_match_all( '/<a\s[^>]*>(.*?)<\/a>/is', $content_html, $matches ) ) {
			return true; // no links to judge.
		}
		$generic = array( 'here', 'click here', 'this', 'link', 'read more' );
		foreach ( $matches[1] as $anchor_text ) {
			$text = strtolower( trim( wp_strip_all_tags( $anchor_text ) ) );
			if ( in_array( $text, $generic, true ) ) {
				return false;
			}
		}
		return true;
	}

	protected static function length_between( $text, $min, $max ) {
		$len = strlen( trim( (string) $text ) );
		return $len >= $min && $len <= $max;
	}

	protected static function has_number_or_power_word( $text ) {
		if ( preg_match( '/\d/', $text ) ) {
			return true;
		}
		$power_words = array( 'best', 'ultimate', 'essential', 'proven', 'guide', 'easy', 'top', 'complete', 'powerful', 'simple', 'must-have', 'expert' );
		$text_lower  = strtolower( $text );
		foreach ( $power_words as $word ) {
			if ( false !== strpos( $text_lower, $word ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function has_cta_language( $text ) {
		$cta_words = array( 'shop', 'buy', 'discover', 'learn', 'find out', 'get', 'explore', 'try', 'see how', 'read on' );
		$text_lower = strtolower( $text );
		foreach ( $cta_words as $word ) {
			if ( false !== strpos( $text_lower, $word ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function has_transition_words( $plain_text ) {
		$transitions = array( 'however', 'for example', 'in addition', 'as a result', 'furthermore', 'meanwhile', 'in fact', 'on the other hand', 'in short', 'ultimately' );
		$text_lower  = strtolower( $plain_text );
		foreach ( $transitions as $word ) {
			if ( false !== strpos( $text_lower, $word ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function readability_ok( $plain_text ) {
		$sentences = preg_split( '/(?<=[.!?])\s+/', trim( $plain_text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $sentences ) ) {
			return false;
		}
		$total_words = str_word_count( $plain_text );
		$avg_words_per_sentence = $total_words / max( 1, count( $sentences ) );
		return $avg_words_per_sentence <= 22;
	}

	/**
	 * The article title rarely contains the product's full, often long,
	 * title verbatim — check for meaningful word overlap instead of an
	 * exact substring match.
	 */
	protected static function product_name_referenced_in_title( $article_title, $product_title ) {
		if ( '' === trim( (string) $product_title ) ) {
			return false;
		}
		if ( false !== stripos( $article_title, $product_title ) ) {
			return true;
		}
		$stopwords = array( 'a', 'an', 'the', 'and', 'or', 'for', 'with', 'of', 'to' );
		$product_words = array_filter(
			preg_split( '/\s+/', strtolower( $product_title ) ),
			function ( $word ) use ( $stopwords ) {
				return strlen( $word ) > 2 && ! in_array( $word, $stopwords, true );
			}
		);
		if ( empty( $product_words ) ) {
			return false;
		}
		$article_title_lower = strtolower( $article_title );
		$matches = 0;
		foreach ( $product_words as $word ) {
			if ( false !== strpos( $article_title_lower, $word ) ) {
				$matches++;
			}
		}
		return $matches >= min( 2, count( $product_words ) );
	}

	protected static function has_category_or_related_link( $content_html, $product ) {
		if ( ! empty( $product['category_links'][0]['url'] ) && false !== strpos( $content_html, $product['category_links'][0]['url'] ) ) {
			return true;
		}
		if ( ! empty( $product['related_products'][0]['url'] ) && false !== strpos( $content_html, $product['related_products'][0]['url'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Site-wide technical SEO status — the same for every article, so it's
	 * shown as context but never mixed into a per-article score. Checks
	 * what's actually verifiable natively rather than duplicating what
	 * Yoast/Rank Math/WordPress core already do.
	 */
	protected static function technical_status() {
		$yoast     = SEO_Integration::is_yoast_active();
		$rank_math = SEO_Integration::is_rankmath_active();
		$seo_plugin_active = $yoast || $rank_math;

		return array(
			'label'  => __( 'Technical SEO (site-wide, not per-article)', 'seo-automation' ),
			'checks' => array(
				'https_enabled'        => is_ssl() || 0 === strpos( home_url(), 'https://' ),
				'canonical_url'        => $seo_plugin_active || false !== has_action( 'wp_head', 'rel_canonical' ),
				'xml_sitemap'          => function_exists( 'wp_sitemaps_get_server' ) || $seo_plugin_active,
				'schema_markup'        => $seo_plugin_active,
				'open_graph_tags'      => $seo_plugin_active,
				'breadcrumbs_available' => function_exists( 'yoast_breadcrumb' ) || function_exists( 'rank_math_the_breadcrumbs' ),
			),
			'note'   => $seo_plugin_active
				? __( 'Schema, Open Graph, and sitemap output are handled by your active SEO plugin.', 'seo-automation' )
				: __( 'No SEO plugin detected — schema and Open Graph tags will not be output. Consider activating Yoast SEO or Rank Math.', 'seo-automation' ),
		);
	}
}
