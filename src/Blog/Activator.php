<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		if ( false === get_option( Settings::OPTION_KEY, false ) ) {
			update_option( Settings::OPTION_KEY, Settings::defaults() );
		}

		// Ensure the topic CPT is registered before scheduling anything that depends on it.
		CPT_Topic::register();

		Scheduler::reschedule();

		flush_rewrite_rules();
	}
}
