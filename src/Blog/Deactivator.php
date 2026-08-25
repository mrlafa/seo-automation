<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		flush_rewrite_rules();
	}
}
