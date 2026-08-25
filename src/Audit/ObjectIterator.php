<?php
/**
 * Stable, resumable iteration over posts and terms.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyset pagination over IDs.
 *
 * OFFSET pagination is wrong for a long-running audit: rows shift as content is
 * edited mid-run, so pages get skipped or repeated. Walking `id > last_seen_id`
 * is stable and stays fast on a large catalogue because it rides the primary key.
 */
final class ObjectIterator {

	/**
	 * Next batch of post IDs after a given ID.
	 *
	 * @param string[] $post_types Post types to include.
	 * @param int      $after_id   Exclusive lower bound.
	 * @param int      $limit      Batch size.
	 * @param string[] $statuses   Post statuses to include.
	 *
	 * @return int[]
	 */
	public static function post_ids( array $post_types, int $after_id, int $limit, array $statuses = array( 'publish' ) ): array {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'strval', $post_types ) ) );
		$statuses   = array_values( array_filter( array_map( 'strval', $statuses ) ) );

		if ( empty( $post_types ) || empty( $statuses ) ) {
			return array();
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$params = array_merge( $post_types, $statuses, array( $after_id, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ({$type_placeholders})
			   AND post_status IN ({$status_placeholders})
			   AND ID > %d
			 ORDER BY ID ASC
			 LIMIT %d",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Total posts matching a scope, for progress reporting.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $statuses   Post statuses.
	 */
	public static function count_posts( array $post_types, array $statuses = array( 'publish' ) ): int {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'strval', $post_types ) ) );
		$statuses   = array_values( array_filter( array_map( 'strval', $statuses ) ) );

		if ( empty( $post_types ) || empty( $statuses ) ) {
			return 0;
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})",
			array_merge( $post_types, $statuses )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Next batch of term IDs after a given ID.
	 *
	 * @param string[] $taxonomies Taxonomies to include.
	 * @param int      $after_id   Exclusive lower bound.
	 * @param int      $limit      Batch size.
	 *
	 * @return int[]
	 */
	public static function term_ids( array $taxonomies, int $after_id, int $limit ): array {
		global $wpdb;

		$taxonomies = array_values( array_filter( array_map( 'strval', $taxonomies ) ) );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$params       = array_merge( $taxonomies, array( $after_id, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$sql = $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy IN ({$placeholders})
			   AND t.term_id > %d
			 ORDER BY t.term_id ASC
			 LIMIT %d",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Total terms matching a scope.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 */
	public static function count_terms( array $taxonomies ): int {
		global $wpdb;

		$taxonomies = array_values( array_filter( array_map( 'strval', $taxonomies ) ) );
		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$placeholders})",
			$taxonomies
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}
}
