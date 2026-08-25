<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 2: turn a research brief into a full draft (title, HTML body, excerpt).
 */
class Writer {

	/**
	 * @param string $keyword        Topic, or the product title in product mode.
	 * @param array  $brief          Output of Research::run() or Product_Analysis::run().
	 * @param string $grounding_text Optional. When set (product mode), the model is instructed
	 *                               to ground every factual claim in this text and forbidden
	 *                               from inventing specs/features/prices/guarantees beyond it.
	 * @param string $word_range     Optional target word count range, e.g. "1500-2000".
	 * @param bool   $want_faqs      Optional. When true, the response includes a structured
	 *                               "faqs" array (question/answer pairs) suitable for FAQ schema,
	 *                               in addition to an FAQ section woven into content_html.
	 * @return array|WP_Error
	 */
	public static function run( $keyword, array $brief, $grounding_text = '', $word_range = '900-1500', $want_faqs = false ) {
		$primary_keyword = $brief['primary_keyword'] ?? $keyword;
		$want_toc        = self::wants_toc( $word_range );

		$system = "You are an experienced SEO blog writer. Write engaging, well-structured, "
			. "original blog content in clean HTML (using <h2>, <h3>, <p>, <ul>/<li>, <strong> as appropriate). "
			. "Do not include a top-level <h1> (the title is handled separately). "
			. "Respond ONLY with valid JSON, no commentary, no markdown fences.";

		if ( $grounding_text ) {
			$system .= " This article is about a real product. Ground every factual claim in the SOURCE MATERIAL "
				. "provided in the user message. Do NOT invent specifications, features, materials, certifications, "
				. "prices, guarantees, or medical/health claims that are not supported by the source material. "
				. "If something isn't covered by the source material, leave it out rather than guessing. "
				. "Do not simply rewrite the source material sentence-by-sentence — expand it intelligently "
				. "(use cases, context, guidance) while staying factually grounded.";
		}

		$faq_instruction = $want_faqs
			? "Include a \"Frequently Asked Questions\" section in content_html AND return the same Q&A pairs " .
			  "in a top-level \"faqs\" array so they can be used for FAQ schema. Only include questions you can " .
			  "answer using the source material / brief — do not invent unsupported answers. If there isn't " .
			  "enough grounded information for FAQs, return an empty faqs array and omit the FAQ section."
			: '';

		$toc_instruction = $want_toc
			? "This is a long article, so open content_html with a short \"Table of Contents\" as a <ul> of " .
			  "<a href=\"#slug\">Heading Text</a> links, one per H2 section. Give every <h2> a matching id " .
			  "attribute (e.g. <h2 id=\"slug\">) so the anchors work. Use short, lowercase, hyphenated ids."
			: '';

		$structure_instructions = "Structural requirements that matter for SEO, follow them precisely: "
			. "(1) Use the exact phrase \"{$primary_keyword}\" naturally within the first two sentences of the introduction — "
			. "this is a hard requirement, not optional. "
			. "(2) Keep paragraphs short: 2-4 sentences each, never a wall of text. "
			. "(3) Use H2 for main sections and H3 only nested under a preceding H2 — never an H3 before the first H2. "
			. "(4) Vary sentence length and use natural transition words (however, for example, in addition, as a result) between paragraphs. "
			. $toc_instruction;

		$json_shape = "{\n" .
			"  \"title\": \"SEO-friendly, compelling post title (under 60 characters)\",\n" .
			"  \"content_html\": \"the full article body as HTML\",\n" .
			"  \"excerpt\": \"a 1-2 sentence summary (under 160 characters)\""
			. ( $want_faqs ? ",\n  \"faqs\": [{\"question\": \"...\", \"answer\": \"...\"}]" : '' ) . "\n" .
			"}";

		$user = "Write a full blog post for \"{$keyword}\" using this brief:\n\n" . wp_json_encode( $brief ) . "\n\n";

		if ( $grounding_text ) {
			$user .= "SOURCE MATERIAL (the product's own long description — ground all claims in this):\n{$grounding_text}\n\n";
		}

		$user .= "Return JSON with this exact shape:\n{$json_shape}\n\n"
			. "Follow the outline order, but skip any outline section that doesn't genuinely fit this content. "
			. "Naturally weave in the primary and secondary keywords — do not keyword-stuff. "
			. "Aim for {$word_range} words. Use an engaging intro and a short conclusion with a call to action. "
			. $structure_instructions . ' ' . $faq_instruction;

		$draft = AI_Client::generate_json( $system, $user, array( 'max_tokens' => 6000 ) );

		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		if ( empty( $draft['title'] ) || empty( $draft['content_html'] ) ) {
			return new \WP_Error( 'theblog_draft_incomplete', __( 'Draft was missing a title or content.', 'seo-automation' ) );
		}

		$draft['content_html'] = wp_kses_post( $draft['content_html'] );

		if ( ! empty( $draft['faqs'] ) && is_array( $draft['faqs'] ) ) {
			$clean_faqs = array();
			foreach ( $draft['faqs'] as $faq ) {
				if ( ! empty( $faq['question'] ) && ! empty( $faq['answer'] ) ) {
					$clean_faqs[] = array(
						'question' => wp_strip_all_tags( $faq['question'] ),
						'answer'   => wp_strip_all_tags( $faq['answer'] ),
					);
				}
			}
			$draft['faqs'] = $clean_faqs;
		} else {
			$draft['faqs'] = array();
		}

		// Single source of truth: the validator checks for a TOC using this
		// same flag rather than recomputing it from the actual word count,
		// so it never fails a check the generation prompt was never asked
		// to satisfy in the first place.
		$draft['requires_toc'] = $want_toc;

		return $draft;
	}

	/**
	 * Long-form content (>=1500 word target) gets a Table of Contents
	 * instruction. Uses the range's lower bound so a "1200-1600" range
	 * (which could land above 1500) still counts as long-form — matching
	 * how the validator should treat the same borderline case.
	 */
	public static function wants_toc( $word_range ) {
		return self::range_ceiling( $word_range ) >= 1500;
	}

	/**
	 * Upper bound of a "1500-2000" style range (falls back to the single
	 * number found if there's no second one), 0 if unparseable.
	 */
	protected static function range_ceiling( $word_range ) {
		if ( preg_match_all( '/\d+/', (string) $word_range, $matches ) && ! empty( $matches[0] ) ) {
			return (int) end( $matches[0] );
		}
		return 0;
	}
}
