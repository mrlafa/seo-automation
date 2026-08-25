<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron driven autopilot: on the configured interval, pull the next
 * due, queued topics and run them through the pipeline. Output always
 * lands as "pending review" — autopilot never publishes on its own.
 */
class Scheduler {

	const HOOK = 'theblog_autopilot_tick';
	const LOCK_KEY = 'theblog_autopilot_lock';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_tick' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'maybe_add_schedules' ) );
	}

	public static function maybe_add_schedules( $schedules ) {
		if ( ! isset( $schedules['hourly'] ) ) {
			$schedules['hourly'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Once Hourly', 'seo-automation' ),
			);
		}
		return $schedules;
	}

	/**
	 * Reschedule the recurring event to match the current settings.
	 * Called from the activator and whenever settings are saved.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );

		if ( ! Settings::get( 'autopilot_enabled' ) ) {
			return;
		}

		$interval = Settings::get( 'autopilot_interval', 'hourly' );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::HOOK );
	}

	public static function run_tick() {
		if ( ! Settings::get( 'autopilot_enabled' ) ) {
			return;
		}

		// Simple lock so overlapping cron triggers can't double-process.
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$max_posts = (int) Settings::get( 'max_posts_per_run', 1 );
		$topics    = CPT_Topic::get_due_queued( $max_posts );

		Logger::info( sprintf( 'Autopilot tick: %d topic(s) due.', count( $topics ) ) );

		foreach ( $topics as $topic ) {
			Pipeline::run_for_topic( $topic->ID );
		}

		delete_transient( self::LOCK_KEY );
	}
}
