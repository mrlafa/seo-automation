<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_Anthropic implements AI_Provider_Interface {

	protected $api_key;
	protected $model;

	public function __construct() {
		$this->api_key = Settings::get( 'anthropic_api_key' );
		$this->model   = Settings::get( 'anthropic_model', 'claude-sonnet-5' );
	}

	public function get_name() {
		return 'Anthropic';
	}

	public function is_configured() {
		return ! empty( $this->api_key );
	}

	public function supports_images() {
		return false;
	}

	public function generate_text( $system_prompt, $user_prompt, $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'theblog_not_configured', __( 'Anthropic API key is not set.', 'seo-automation' ) );
		}

		$defaults = array(
			'max_tokens' => 4000,
		);
		$args = wp_parse_args( $args, $defaults );

		$body = array(
			'model'      => $this->model,
			'max_tokens' => (int) $args['max_tokens'],
			'system'     => $system_prompt,
			'messages'   => array(
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
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
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
			return new \WP_Error( 'theblog_api_error', sprintf( 'Anthropic API error (%d): %s', $code, $message ) );
		}

		$text = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === $text ) {
			return new \WP_Error( 'theblog_empty_response', __( 'Anthropic returned an empty response.', 'seo-automation' ) );
		}

		return $text;
	}

	public function generate_image( $prompt, $args = array() ) {
		return new \WP_Error( 'theblog_unsupported', __( 'Anthropic does not support image generation.', 'seo-automation' ) );
	}
}
