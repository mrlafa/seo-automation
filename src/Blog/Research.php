<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 1: turn a bare keyword/topic into a structured research brief
 * (search intent, angle, outline, facts to cover) the writer stage builds on.
 */
class Research {

	public static function run( $keyword ) {
		$system = "You are a senior content strategist and researcher. Given a topic, "
			. "produce a structured research brief a writer will use to draft a blog post. "
			. "Respond ONLY with valid JSON, no commentary, no markdown fences.";

		$user = sprintf(
			"Topic: \"%s\"\n\n" .
			"Return JSON with this exact shape:\n" .
			"{\n" .
			"  \"search_intent\": \"informational|commercial|transactional|navigational\",\n" .
			"  \"target_audience\": \"short description\",\n" .
			"  \"primary_keyword\": \"the main keyword to target\",\n" .
			"  \"secondary_keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"],\n" .
			"  \"angle\": \"the unique angle/hook for this post\",\n" .
			"  \"outline\": [\n" .
			"    {\"heading\": \"H2 heading text\", \"points\": [\"point to cover\", \"point to cover\"]}\n" .
			"  ],\n" .
			"  \"key_facts\": [\"notable fact or statistic to include, phrased generally since you cannot browse live sources\"]\n" .
			"}\n\n" .
			"Cover 4-7 outline sections. Be specific to the topic, not generic.",
			$keyword
		);

		$brief = AI_Client::generate_json( $system, $user, array( 'max_tokens' => 2000 ) );

		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		if ( empty( $brief['outline'] ) ) {
			return new \WP_Error( 'theblog_research_incomplete', __( 'Research brief was missing an outline.', 'seo-automation' ) );
		}

		return $brief;
	}
}
