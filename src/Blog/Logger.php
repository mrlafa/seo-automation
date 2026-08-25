<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight rolling log stored in wp_options, viewable in the admin.
 */
class Logger {

	const OPTION_KEY = 'theblog_logs';
	const MAX_ENTRIES = 200;

	public static function log( $message, $level = 'info', $context = array() ) {
		$logs = get_option( self::OPTION_KEY, array() );

		$logs[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => is_string( $message ) ? $message : wp_json_encode( $message ),
			'context' => $context,
		);

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -1 * self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );

		if ( 'error' === $level && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only reached when WP_DEBUG is on; this is the plugin's debug channel, and no credentials are logged.
			error_log( '[SEO Automation] ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) ) );
		}
	}

	public static function error( $message, $context = array() ) {
		self::log( $message, 'error', $context );
	}

	public static function info( $message, $context = array() ) {
		self::log( $message, 'info', $context );
	}

	public static function get_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return array_reverse( $logs );
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
