<?php
/**
 * Combined uninstall handler for SEO Automation (TheBlog Automation + SEO Agent).
 *
 * @package SEOAutomation
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TheBlog Automation uninstall
delete_option( 'theblog_settings' );
delete_option( 'theblog_logs' );

$topics = get_posts(
	array(
		'post_type'      => 'theblog_topic',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	)
);

foreach ( $topics as $topic_id ) {
	wp_delete_post( $topic_id, true );
}

// SEO Agent uninstall
require_once __DIR__ . '/src/autoload.php';

global $wpdb;

// Custom tables.
\SEOAgent\Database\Schema::uninstall();

// Options.
delete_option( 'seo_agent_settings' );
delete_option( 'seo_agent_db_version' );
delete_option( 'seo_agent_llms_txt' );

// Scheduled work.
wp_clear_scheduled_hook( 'seo_agent_scheduled_audit' );

// Capability.
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_seo_agent' );
}

// Working meta the plugin wrote for its own use, which is not site content.
foreach ( array( '_seo_agent_simhash', '_seo_agent_simhash_source' ) as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// Transients used to carry state between audit slices.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_seo_agent_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_seo_agent_' ) . '%'
	)
);

// Additional transients from TheBlog Automation (if any)
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_theblog_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_theblog_' ) . '%'
	)
);
