<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-mode equivalent of Research: turns extracted WooCommerce
 * product data into the same "brief" shape Writer already expects
 * (search_intent, primary/secondary keywords, outline, ...), plus product-
 * specific fields (benefits, features, use cases, FAQ/internal-link
 * opportunities). Covers pipeline steps 2 (product analysis) and 3
 * (keyword/entity analysis) in one grounded call, the same way Research
 * covers topic-mode analysis + outline in one call.
 *
 * Everything here is grounded in the extracted product data — the system
 * prompt explicitly forbids inventing specs, materials, certifications,
 * prices, or guarantees that aren't present in it.
 */
class Product_Analysis {

	/**
	 * @param array $product Output of WooCommerce::extract_product_data().
	 * @return array|WP_Error
	 */
	public static function run( array $product ) {
		if ( '' === trim( $product['long_description'] ) ) {
			return new \WP_Error(
				'theblog_no_long_description',
				__( 'Product found, but the long description is empty. Add a long description before generating SEO content.', 'seo-automation' )
			);
		}

		$system = "You are a senior SEO content strategist and e-commerce copywriter. "
			. "You analyze a real WooCommerce product's data and plan an SEO article about it. "
			. "The product's long description is the primary source of truth. "
			. "Do NOT invent specifications, features, materials, certifications, prices, guarantees, or medical/health claims "
			. "that are not supported by the product data provided. If information is not available, omit it rather than guessing. "
			. "Keyword suggestions are your own AI-generated candidates, not verified search-volume data — treat them accordingly. "
			. "Respond ONLY with valid JSON, no commentary, no markdown fences.";

		$user = sprintf(
			"PRODUCT DATA (ground truth — do not contradict or invent beyond this):\n%s\n\n" .
			"Return JSON with this exact shape:\n" .
			"{\n" .
			"  \"search_intent\": \"informational|commercial|transactional|navigational\",\n" .
			"  \"target_audience\": \"short description\",\n" .
			"  \"primary_keyword\": \"the single best primary keyword for this product, natural phrase, not brand name alone\",\n" .
			"  \"secondary_keywords\": [\"keyword1\", \"keyword2\", \"keyword3\", \"keyword4\", \"keyword5\"],\n" .
			"  \"angle\": \"the unique angle/hook for this article\",\n" .
			"  \"outline\": [\n" .
			"    {\"heading\": \"H2 heading text\", \"points\": [\"point to cover\", \"point to cover\"]}\n" .
			"  ],\n" .
			"  \"key_facts\": [\"fact taken directly from the product data\"],\n" .
			"  \"product_analysis\": {\n" .
			"    \"product_type\": \"short category label\",\n" .
			"    \"main_benefits\": [\"benefit grounded in the description\"],\n" .
			"    \"key_features\": [\"feature grounded in the description\"],\n" .
			"    \"use_cases\": [\"realistic use case\"],\n" .
			"    \"problems_solved\": [\"problem this product addresses, per the description\"],\n" .
			"    \"faq_opportunities\": [\"question a buyer would realistically ask, answerable from the product data\"],\n" .
			"    \"internal_linking_opportunities\": [\"natural anchor text/topic that could link to a category, related product, or blog post\"]\n" .
			"  }\n" .
			"}\n\n" .
			"Cover 5-8 outline sections appropriate to this specific product (skip sections that don't fit, e.g. skip \"How to Use\" for a product with no setup). " .
			"Prioritize natural language and real search intent over keyword density.",
			wp_json_encode( self::for_prompt( $product ) )
		);

		$brief = AI_Client::generate_json( $system, $user, array( 'max_tokens' => 4000 ) );

		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		if ( empty( $brief['outline'] ) || empty( $brief['primary_keyword'] ) ) {
			return new \WP_Error( 'theblog_analysis_incomplete', __( 'Product analysis was missing an outline or primary keyword.', 'seo-automation' ) );
		}

		return $brief;
	}

	/**
	 * Trim the product payload down to what the model needs — avoids
	 * pushing huge gallery/attachment metadata into the prompt, and caps
	 * the long description so an unusually long one (some WooCommerce
	 * descriptions run to several thousand words, sometimes with pasted
	 * HTML/table content) doesn't crowd out the output token budget.
	 */
	protected static function for_prompt( array $product ) {
		return array(
			'title'              => $product['title'],
			'sku'                => $product['sku'],
			'brand'              => $product['brand'],
			'long_description'   => self::cap_length( $product['long_description'], 6000 ),
			'short_description'  => self::cap_length( $product['short_description'], 1000 ),
			'categories'         => $product['categories'],
			'tags'               => $product['tags'],
			'attributes'         => $product['attributes'],
			'existing_seo_title' => $product['existing_seo_title'],
			'existing_meta_desc' => $product['existing_meta_desc'],
		);
	}

	protected static function cap_length( $text, $max_chars ) {
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}
		return substr( $text, 0, $max_chars ) . '… [truncated]';
	}
}
