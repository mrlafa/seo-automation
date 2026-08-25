<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_OpenAI implements AI_Provider_Interface {

	protected $api_key;
	protected $model;
	protected $image_model;

	public function __construct() {
		$this->api_key     = Settings::get( 'openai_api_key' );
		$this->model       = Settings::get( 'openai_model', 'gpt-4o-mini' );
		$this->image_model = Settings::get( 'image_model', 'dall-e-3' );
	}

	public function get_name() {
		return 'OpenAI';
	}

	public function is_configured() {
		return ! empty( $this->api_key );
	}

	public function supports_images() {
		return $this->is_configured() && 'openai' === Settings::get( 'image_provider' );
	}

	public function generate_text( $system_prompt, $user_prompt, $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'theblog_not_configured', __( 'OpenAI API key is not set.', 'seo-automation' ) );
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

		// Some newer model IDs reject `temperature` outright, so only send
		// it when a caller explicitly asks for a non-default value.
		if ( isset( $args['temperature'] ) ) {
			$body['temperature'] = (float) $args['temperature'];
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
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
			return new \WP_Error( 'theblog_api_error', sprintf( 'OpenAI API error (%d): %s', $code, $message ) );
		}

		$text = $data['choices'][0]['message']['content'] ?? '';

		if ( '' === $text ) {
			return new \WP_Error( 'theblog_empty_response', __( 'OpenAI returned an empty response.', 'seo-automation' ) );
		}

		return $text;
	}

	public function generate_image( $prompt, $args = array() ) {
		if ( ! $this->supports_images() ) {
			return new \WP_Error( 'theblog_unsupported', __( 'OpenAI image generation is not enabled.', 'seo-automation' ) );
		}

		$defaults = array(
			'size' => '1024x1024',
		);
		$args = wp_parse_args( $args, $defaults );

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body' => wp_json_encode(
					array(
						'model'  => $this->image_model,
						'prompt' => $prompt,
						'n'      => 1,
						'size'   => $args['size'],
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['error']['message'] ?? wp_remote_retrieve_body( $response );
			return new \WP_Error( 'theblog_api_error', sprintf( 'OpenAI image API error (%d): %s', $code, $message ) );
		}

		$url = $data['data'][0]['url'] ?? '';

		if ( '' === $url ) {
			return new \WP_Error( 'theblog_empty_response', __( 'OpenAI returned no image.', 'seo-automation' ) );
		}

		return $url;
	}
}
