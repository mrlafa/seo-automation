<?php
/**
 * Issue persistence, including cross-run lifecycle tracking.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Database;

use SEOAgent\Audit\Issue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores findings and tracks whether they persist, resolve or regress.
 */
class IssueRepository {

	/**
	 * Insert or refresh an issue, keyed on its fingerprint.
	 *
	 * A finding seen in a previous run keeps its original first_seen date and
	 * flips back to 'open' if it had been marked resolved — that regression
	 * signal is what makes "did my fix hold?" answerable.
	 *
	 * @param Issue $issue    Finding.
	 * @param int   $audit_id Owning audit run.
	 *
	 * @return int Issue ID.
	 */
	public function upsert( Issue $issue, int $audit_id ): int {
		global $wpdb;

		$table       = Schema::table( 'issues' );
		$now         = current_time( 'mysql', true );
		$fingerprint = $issue->fingerprint();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, first_seen FROM {$table} WHERE fingerprint = %s", $fingerprint ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$data = array(
			'audit_id'     => $audit_id,
			'fingerprint'  => $fingerprint,
			'checker'      => $issue->checker,
			'code'         => $issue->code,
			'severity'     => $issue->severity,
			'impact'       => $issue->impact(),
			'object_type'  => $issue->object_type,
			'object_id'    => $issue->object_id,
			'object_label' => $issue->object_label,
			'url'          => $issue->url,
			'title'        => $issue->title,
			'detail'       => $issue->detail,
			'evidence'     => wp_json_encode( $issue->evidence ),
			'fixer'        => $issue->fixer,
			'fix_mode'     => $issue->fix_mode,
			'fix_payload'  => wp_json_encode( $issue->fix_payload ),
			'last_seen'    => $now,
		);

		if ( $existing ) {
			// 'ignored' is a deliberate human decision — never resurrect it.
			$data['status']      = 'ignored' === $existing['status'] ? 'ignored' : 'open';
			$data['resolved_at'] = null;

			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );

			return (int) $existing['id'];
		}

		$data['status']     = 'open';
		$data['first_seen'] = $now;

		$wpdb->insert( $table, $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Close out issues from earlier runs that the current run did not re-report.
	 *
	 * Scoped to the checkers that actually ran, so a partial audit never marks
	 * unrelated findings as fixed.
	 *
	 * @param int      $audit_id Current audit ID.
	 * @param string[] $checkers Checker slugs that completed in this run.
	 *
	 * @return int Number of issues resolved.
	 */
	public function resolve_stale( int $audit_id, array $checkers ): int {
		global $wpdb;

		if ( empty( $checkers ) ) {
			return 0;
		}

		$table        = Schema::table( 'issues' );
		$placeholders = implode( ',', array_fill( 0, count( $checkers ), '%s' ) );
		$params       = array_merge( array( current_time( 'mysql', true ), $audit_id ), $checkers );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'fixed', resolved_at = %s
			 WHERE status = 'open' AND audit_id <> %d AND checker IN ({$placeholders})",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		return (int) $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Query issues.
	 *
	 * @param array<string,mixed> $filters audit_id, status, severity, code, checker,
	 *                                     object_type, object_id, fixable, search,
	 *                                     per_page, page, orderby.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function query( array $filters = array() ): array {
		global $wpdb;

		$table  = Schema::table( 'issues' );
		$where  = array( '1=1' );
		$params = array();

		$equals = array(
			'audit_id'    => '%d',
			'object_id'   => '%d',
			'object_type' => '%s',
			'code'        => '%s',
			'checker'     => '%s',
			'fixer'       => '%s',
			'fix_mode'    => '%s',
		);

		foreach ( $equals as $column => $format ) {
			if ( isset( $filters[ $column ] ) && '' !== $filters[ $column ] ) {
				$where[]  = "{$column} = {$format}";
				$params[] = $filters[ $column ];
			}
		}

		foreach ( array( 'status', 'severity' ) as $column ) {
			if ( empty( $filters[ $column ] ) ) {
				continue;
			}

			$values       = (array) $filters[ $column ];
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$where[]      = "{$column} IN ({$placeholders})";
			$params       = array_merge( $params, $values );
		}

		if ( ! empty( $filters['fixable'] ) ) {
			$where[] = "fixer IS NOT NULL AND fixer <> ''";
		}

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(title LIKE %s OR object_label LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$clause = implode( ' AND ', $where );

		$allowed_order = array(
			'impact'    => 'impact DESC, id DESC',
			'severity'  => "FIELD(severity,'critical','high','medium','low','info'), id DESC",
			'recent'    => 'last_seen DESC, id DESC',
			'object'    => 'object_type ASC, object_id ASC, id ASC',
		);
		$order         = $allowed_order[ $filters['orderby'] ?? 'impact' ] ?? $allowed_order['impact'];

		$per_page = max( 1, min( 500, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clause holds placeholders only.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$rows_sql   = "SELECT * FROM {$table} WHERE {$clause} ORDER BY {$order} LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Fetch one issue.
	 *
	 * @param int $id Issue ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'issues' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Fetch many issues by ID, preserving the caller's order.
	 *
	 * @param int[] $ids Issue IDs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function find_many( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = Schema::table( 'issues' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $this->hydrate( $row );
		}

		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Change an issue's status.
	 *
	 * @param int    $id     Issue ID.
	 * @param string $status open | fixed | ignored | failed.
	 * @param string $note   Optional note appended to detail.
	 */
	public function set_status( int $id, string $status, string $note = '' ): void {
		global $wpdb;

		$data = array( 'status' => $status );

		if ( in_array( $status, array( 'fixed', 'ignored' ), true ) ) {
			$data['resolved_at'] = current_time( 'mysql', true );
		} else {
			$data['resolved_at'] = null;
		}

		if ( '' !== $note ) {
			$data['detail'] = $note;
		}

		$wpdb->update( Schema::table( 'issues' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Counts grouped by a column, for dashboards and scoring.
	 *
	 * @param string              $column  'severity', 'checker', 'code' or 'status'.
	 * @param array<string,mixed> $filters Optional status/audit filter.
	 *
	 * @return array<string,int>
	 */
	public function counts_by( string $column, array $filters = array() ): array {
		global $wpdb;

		$allowed = array( 'severity', 'checker', 'code', 'status', 'object_type', 'fix_mode' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table  = Schema::table( 'issues' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$values       = (array) $filters['status'];
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$params       = array_merge( $params, $values );
		}

		if ( ! empty( $filters['audit_id'] ) ) {
			$where[]  = 'audit_id = %d';
			$params[] = (int) $filters['audit_id'];
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column allow-listed, clause holds placeholders.
		$sql  = "SELECT {$column} AS bucket, COUNT(*) AS total FROM {$table} WHERE {$clause} GROUP BY {$column}";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['bucket'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Delete every issue for a checker. Used when a checker is disabled.
	 *
	 * @param string $checker Checker slug.
	 */
	public function delete_by_checker( string $checker ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'issues' ), array( 'checker' => $checker ), array( '%s' ) );
	}

	/**
	 * Decode JSON columns and cast numerics.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 *
	 * @return array<string,mixed>
	 */
	protected function hydrate( array $row ): array {
		foreach ( array( 'evidence', 'fix_payload' ) as $column ) {
			$decoded        = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}

		foreach ( array( 'id', 'audit_id', 'object_id', 'impact' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}

		return $row;
	}
}
