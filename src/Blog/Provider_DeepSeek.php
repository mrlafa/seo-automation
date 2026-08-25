<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek's API is OpenAI-compatible (same chat/completions request and
 * response shape, Bearer auth) — this provider is essentially the OpenAI
 * one pointed at a different host and model. No image generation: DeepSeek
 * doesn't offer an image API, so this is text-only.
 */
class Provider_DeepSeek implements AI_Provider_Interface {

	protected $api_key;
	protected $model;

	public function __construct() {
		$this->api_key = Settings::get( 'deepseek_api_key' );
		$this->model    = Settings::get( 'deepseek_model', 'deepseek-chat' );
	}

	public function get_name() {
		return 'DeepSeek';
	}

	public function is_configured() {
		return ! empty( $this->api_key );
	}

	public function supports_images() {
		return false;
	}

	public function generate_text( $system_prompt, $user_prompt, $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'theblog_not_configured', __( 'DeepSeek API key is not set.', 'seo-automation' ) );
		}

		$defaults = array(
			'max_tokens' => 4000,
		);
		$args = wp_parse_args( $args, $defaults );

		$body = array(
			'model'      => $this->model,
			'max_tokens' => (int) $args['max_tokens'],
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		if ( isset( $args['temperature'] ) ) {
			$body['temperature'] = (float) $args['temperature'];
		}

		$response = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body' => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['error']['message'] ?? wp_remote_retrieve_body( $response );
			return new \WP_Error( 'theblog_api_error', sprintf( 'DeepSeek API error (%d): %s', $code, $message ) );
		}

		$text = $data['choices'][0]['message']['content'] ?? '';

		if ( '' === $text ) {
			return new \WP_Error( 'theblog_empty_response', __( 'DeepSeek returned an empty response.', 'seo-automation' ) );
		}

		return $text;
	}

	public function generate_image( $prompt, $args = array() ) {
		return new \WP_Error( 'theblog_unsupported', __( 'DeepSeek does not support image generation.', 'seo-automation' ) );
	}
}
