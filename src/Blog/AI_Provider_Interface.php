<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every AI provider (Anthropic, OpenAI, ...) must implement so the
 * rest of the plugin can stay provider-agnostic.
 */
interface AI_Provider_Interface {

	/**
	 * Human-readable provider name, e.g. "Anthropic".
	 */
	public function get_name();

	/**
	 * Whether this provider has a usable API key configured.
	 */
	public function is_configured();

	/**
	 * Whether this provider instance can generate images.
	 */
	public function supports_images();

	/**
	 * Generate text from a prompt.
	 *
	 * @param string $system_prompt System / instruction prompt.
	 * @param string $user_prompt   User prompt / task content.
	 * @param array  $args         Optional overrides: max_tokens, temperature.
	 * @return string|WP_Error Raw text response.
	 */
	public function generate_text( $system_prompt, $user_prompt, $args = array() );

	/**
	 * Generate an image and return its temporary URL.
	 *
	 * @param string $prompt
	 * @param array  $args
	 * @return string|WP_Error Image URL.
	 */
	public function generate_image( $prompt, $args = array() );
}
