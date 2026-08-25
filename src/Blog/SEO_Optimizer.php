<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 3: on-page SEO — SEO title, meta description, and a keyword
 * density score used both for the review-queue display and for the
 * SEO plugin integration (Yoast/RankMath) or our own fallback meta.
 */
class SEO_Optimizer {

	/**
	 * @param string $keyword
	 * @param array  $draft
	 * @param array  $brief
	 * @param array  $images Optional. Product images (WooCommerce::extract_product_data()
	 *                        shape) to request natural, non-stuffed alt-text suggestions for.
	 *                        Empty by default — topic-mode callers are unaffected.
	 * @return array
	 */
	public static function run( $keyword, array $draft, array $brief, array $images = array() ) {
		$search_intent = $brief['search_intent'] ?? 'informational';

		$system = "You are an SEO specialist optimizing content against Rank Math and Yoast SEO's own recommendations. "
			. "Follow every instruction precisely — these map directly to specific SEO checks, not vague guidance. "
			. "Never keyword-stuff, and never fabricate a URL for an external link. "
			. "Respond ONLY with valid JSON, no commentary, no markdown fences.";

		$user = sprintf(
			"Focus keyword: \"%s\"\nSearch intent: \"%s\"\nPost title: \"%s\"\nPost content:\n%s\n\n",
			$keyword,
			$search_intent,
			$draft['title'],
			wp_strip_all_tags( $draft['content_html'] )
		);

		$json_shape = "{\n"
			. "  \"seo_title\": \"50-60 characters. The focus keyword must appear within the first half of this title, "
			. "not just anywhere in it. Where it reads naturally, include a number (e.g. a count, a year) or one power "
			. "word (e.g. best, ultimate, essential, proven, guide, easy) — but only if it stays truthful and not clickbait.\",\n"
			. "  \"meta_description\": \"140-160 characters, includes the focus keyword, compelling, and — if the search "
			. "intent is commercial or transactional — ends with a short call to action.\",\n"
			. "  \"slug\": \"short, url-friendly slug with the focus keyword, no stop words, under 6 words\",\n"
			. "  \"external_reference_suggestion\": \"one sentence naming the KIND of reputable external source that would "
			. "strengthen this article (e.g. 'a manufacturer safety guideline page' or 'an independent review roundup') — "
			. "do NOT provide an actual URL, you cannot verify one exists or is still live. Empty string if none is genuinely useful.\"";

		if ( ! empty( $images ) ) {
			$json_shape .= ",\n  \"image_alt_text\": {\"<attachment_id>\": \"natural, descriptive alt text for that image, no keyword stuffing\"}";
		}

		$json_shape .= "\n}";

		$user .= "Return JSON with this exact shape:\n{$json_shape}";

		if ( ! empty( $images ) ) {
			$image_context = array();
			foreach ( $images as $image ) {
				$image_context[] = array(
					'attachment_id' => $image['attachment_id'],
					'title'         => $image['title'],
					'existing_alt'  => $image['existing_alt'],
				);
			}
			$user .= "\n\nProduct images to suggest alt text for (key the image_alt_text object by attachment_id):\n"
				. wp_json_encode( $image_context );
		}

		$seo = AI_Client::generate_json( $system, $user, array( 'max_tokens' => 800 ) );

		if ( is_wp_error( $seo ) ) {
			// Non-fatal: fall back to deriving simple metadata instead of failing the pipeline.
			Logger::error( 'SEO metadata generation failed, using fallback: ' . $seo->get_error_message() );
			$seo = array(
				'seo_title'        => $draft['title'],
				'meta_description' => $draft['excerpt'] ?? wp_trim_words( wp_strip_all_tags( $draft['content_html'] ), 25 ),
				'slug'              => sanitize_title( $draft['title'] ),
			);
		}

		if ( empty( $seo['slug'] ) ) {
			$seo['slug'] = sanitize_title( $seo['seo_title'] ?? $draft['title'] );
		} else {
			$seo['slug'] = sanitize_title( $seo['slug'] );
		}

		if ( empty( $seo['image_alt_text'] ) || ! is_array( $seo['image_alt_text'] ) ) {
			$seo['image_alt_text'] = array();
		}

		$seo['external_reference_suggestion'] = trim( wp_strip_all_tags( $seo['external_reference_suggestion'] ?? '' ) );

		$seo['focus_keyword']    = $brief['primary_keyword'] ?? $keyword;
		$seo['secondary_keywords'] = $brief['secondary_keywords'] ?? array();
		$seo['keyword_density']  = self::keyword_density( wp_strip_all_tags( $draft['content_html'] ), $seo['focus_keyword'] );
		$seo['has_headings']     = (bool) preg_match( '/<h[23][ >]/i', $draft['content_html'] );

		return $seo;
	}

	/**
	 * Percentage of words in the content that are (part of) the focus keyword phrase.
	 */
	public static function keyword_density( $plain_text, $keyword ) {
		$keyword = trim( $keyword );
		if ( '' === $keyword || '' === trim( $plain_text ) ) {
			return 0.0;
		}

		$words = preg_split( '/\s+/', trim( $plain_text ) );
		$word_count = max( 1, count( $words ) );

		$keyword_words = str_word_count( strtolower( $keyword ) );
		$occurrences   = substr_count( strtolower( $plain_text ), strtolower( $keyword ) );

		$density = ( $occurrences * max( 1, $keyword_words ) / $word_count ) * 100;

		return round( $density, 2 );
	}
}
