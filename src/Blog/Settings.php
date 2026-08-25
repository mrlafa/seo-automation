<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings accessor. Backed by a single option so the whole
 * configuration can be read/written in one call.
 */
class Settings {

	const OPTION_KEY = 'theblog_settings';

	public static function defaults() {
		return array(
			'ai_provider'          => 'anthropic', // anthropic | openai | deepseek
			'anthropic_api_key'    => '',
			'anthropic_model'      => 'claude-sonnet-5',
			'openai_api_key'       => '',
			'openai_model'         => 'gpt-4o-mini',
			'deepseek_api_key'     => '',
			'deepseek_model'       => 'deepseek-chat',
			'image_provider'       => 'none', // none | openai
			'image_model'          => 'dall-e-3',
			'autopilot_enabled'    => false,
			'autopilot_interval'   => 'hourly', // hourly | twicedaily | daily
			'max_posts_per_run'    => 1,
			'default_category'     => 0,
			'default_author'       => 0,
			'internal_link_limit'  => 3,
			'notify_email'         => get_option( 'admin_email' ),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$all = self::all();
		$all = array_merge( $all, $values );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	/**
	 * Resolve a credential field submitted from the settings screen.
	 *
	 * Credentials are write-only: the form never echoes a stored key back
	 * into the page, so it always arrives blank unless the user typed a new
	 * one. A blank submission therefore means "keep what is stored", not
	 * "delete it" — without this, saving any unrelated setting would
	 * silently wipe the configured key. Removal is an explicit checkbox.
	 *
	 * @param array  $input Raw (already unslashed) form input.
	 * @param string $key   Setting key.
	 *
	 * @return string
	 */
	private static function sanitize_secret( array $input, $key ) {
		if ( ! empty( $input[ $key . '_remove' ] ) ) {
			return '';
		}

		if ( ! isset( $input[ $key ] ) ) {
			return (string) self::get( $key, '' );
		}

		$value = sanitize_text_field( $input[ $key ] );

		return '' === trim( $value ) ? (string) self::get( $key, '' ) : $value;
	}

	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$clean['ai_provider']       = in_array( $input['ai_provider'] ?? '', array( 'anthropic', 'openai', 'deepseek' ), true ) ? $input['ai_provider'] : $defaults['ai_provider'];
		$clean['anthropic_api_key'] = self::sanitize_secret( $input, 'anthropic_api_key' );
		$clean['anthropic_model']   = isset( $input['anthropic_model'] ) ? sanitize_text_field( $input['anthropic_model'] ) : $defaults['anthropic_model'];
		$clean['openai_api_key']    = self::sanitize_secret( $input, 'openai_api_key' );
		$clean['openai_model']      = isset( $input['openai_model'] ) ? sanitize_text_field( $input['openai_model'] ) : $defaults['openai_model'];
		$clean['deepseek_api_key']  = self::sanitize_secret( $input, 'deepseek_api_key' );
		$clean['deepseek_model']    = isset( $input['deepseek_model'] ) ? sanitize_text_field( $input['deepseek_model'] ) : $defaults['deepseek_model'];
		$clean['image_provider']    = in_array( $input['image_provider'] ?? '', array( 'none', 'openai' ), true ) ? $input['image_provider'] : $defaults['image_provider'];
		$clean['image_model']       = isset( $input['image_model'] ) ? sanitize_text_field( $input['image_model'] ) : $defaults['image_model'];
		$clean['autopilot_enabled'] = ! empty( $input['autopilot_enabled'] );
		$clean['autopilot_interval'] = in_array( $input['autopilot_interval'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $input['autopilot_interval'] : $defaults['autopilot_interval'];
		$clean['max_posts_per_run'] = isset( $input['max_posts_per_run'] ) ? max( 1, min( 10, (int) $input['max_posts_per_run'] ) ) : $defaults['max_posts_per_run'];
		$clean['default_category']  = isset( $input['default_category'] ) ? (int) $input['default_category'] : 0;
		$clean['default_author']    = isset( $input['default_author'] ) ? (int) $input['default_author'] : 0;
		$clean['internal_link_limit'] = isset( $input['internal_link_limit'] ) ? max( 0, min( 10, (int) $input['internal_link_limit'] ) ) : $defaults['internal_link_limit'];
		$clean['notify_email']      = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : $defaults['notify_email'];

		return $clean;
	}
}
