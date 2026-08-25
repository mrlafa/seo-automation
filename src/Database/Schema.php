<?php
/**
 * Custom table definitions.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the plugin's tables via dbDelta.
 */
final class Schema {

	/** Bump to trigger a dbDelta run on the next request. */
	public const VERSION = '1.0.0';

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name One of 'audits', 'issues', 'changes'.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'seoagent_' . $name;
	}

	/**
	 * Create or upgrade all tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$audits  = self::table( 'audits' );
		$issues  = self::table( 'issues' );
		$changes = self::table( 'changes' );

		// dbDelta is whitespace- and format-sensitive: two spaces after PRIMARY KEY,
		// one field per line, KEY names spelled out.
		$sql = array();

		$sql[] = "CREATE TABLE {$audits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			trigger_source varchar(20) NOT NULL DEFAULT 'manual',
			scopes longtext NULL,
			args longtext NULL,
			cursor_state longtext NULL,
			totals longtext NULL,
			score tinyint(3) unsigned NULL,
			error text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$issues} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
			fingerprint char(40) NOT NULL,
			checker varchar(64) NOT NULL,
			code varchar(64) NOT NULL,
			severity varchar(10) NOT NULL DEFAULT 'medium',
			status varchar(16) NOT NULL DEFAULT 'open',
			impact smallint(5) unsigned NOT NULL DEFAULT 0,
			object_type varchar(20) NOT NULL DEFAULT 'site',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_label text NULL,
			url text NULL,
			title text NOT NULL,
			detail longtext NULL,
			evidence longtext NULL,
			fixer varchar(64) NULL,
			fix_mode varchar(12) NOT NULL DEFAULT 'manual',
			fix_payload longtext NULL,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY audit_id (audit_id),
			KEY status_severity (status,severity),
			KEY code (code),
			KEY object (object_type,object_id),
			KEY checker (checker)
		) {$charset};";

		$sql[] = "CREATE TABLE {$changes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch char(36) NOT NULL,
			issue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			fixer varchar(64) NOT NULL,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(191) NOT NULL,
			before_value longtext NULL,
			after_value longtext NULL,
			status varchar(16) NOT NULL DEFAULT 'applied',
			note text NULL,
			applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
			applied_at datetime NOT NULL,
			reverted_at datetime NULL,
			PRIMARY KEY  (id),
			KEY batch (batch),
			KEY issue_id (issue_id),
			KEY object (object_type,object_id),
			KEY status (status)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Drop all tables. Only called from uninstall.php when the user opts in.
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( array( 'changes', 'issues', 'audits' ) as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
