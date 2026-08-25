<?php
/**
 * Audit run persistence.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Database;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * CRUD for audit runs.
 */
class AuditRepository {

	/**
	 * Create a queued audit run.
	 *
	 * @param string[]            $scopes Checker slugs to run; empty means all.
	 * @param array<string,mixed> $args   Run arguments (trigger, limit, post_types...).
	 *
	 * @return int Audit ID.
	 */
	public function create( array $scopes, array $args = array() ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			Schema::table( 'audits' ),
			array(
				'uuid'           => wp_generate_uuid4(),
				'status'         => 'queued',
				'trigger_source' => (string) ( $args['trigger'] ?? 'manual' ),
				'scopes'         => wp_json_encode( array_values( $scopes ) ),
				'args'           => wp_json_encode( $args ),
				'cursor_state'   => wp_json_encode( array() ),
				'totals'         => wp_json_encode( array() ),
				'created_by'     => get_current_user_id(),
				'created_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one audit as an associative array with JSON columns decoded.
	 *
	 * @param int $id Audit ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'audits' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Most recent audits, newest first.
	 *
	 * @param int    $limit  Row limit.
	 * @param string $status Optional status filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 20, string $status = '' ): array {
		global $wpdb;

		$table = Schema::table( 'audits' );
		$limit = max( 1, min( 200, $limit ) );

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * The newest completed audit, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest_completed(): ?array {
		$rows = $this->recent( 1, 'completed' );

		return $rows[0] ?? null;
	}

	/**
	 * Update columns on an audit. JSON-shaped values are encoded automatically.
	 *
	 * @param int                 $id   Audit ID.
	 * @param array<string,mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;

		foreach ( array( 'scopes', 'args', 'cursor_state', 'totals' ) as $json_column ) {
			if ( isset( $data[ $json_column ] ) && is_array( $data[ $json_column ] ) ) {
				$data[ $json_column ] = wp_json_encode( $data[ $json_column ] );
			}
		}

		$wpdb->update( Schema::table( 'audits' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Mark an audit as started.
	 *
	 * @param int $id Audit ID.
	 */
	public function mark_running( int $id ): void {
		$this->update(
			$id,
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Mark an audit finished.
	 *
	 * @param int                 $id     Audit ID.
	 * @param array<string,mixed> $totals Issue counts by severity plus checker stats.
	 * @param int                 $score  Health score 0-100.
	 */
	public function mark_completed( int $id, array $totals, int $score ): void {
		$this->update(
			$id,
			array(
				'status'      => 'completed',
				'totals'      => $totals,
				'score'       => max( 0, min( 100, $score ) ),
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Mark an audit failed.
	 *
	 * @param int    $id      Audit ID.
	 * @param string $message Error message.
	 */
	public function mark_failed( int $id, string $message ): void {
		$this->update(
			$id,
			array(
				'status'      => 'failed',
				'error'       => $message,
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Decode JSON columns and cast numerics.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 *
	 * @return array<string,mixed>
	 */
	protected function hydrate( array $row ): array {
		foreach ( array( 'scopes', 'args', 'cursor_state', 'totals' ) as $column ) {
			$decoded       = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}

		$row['id']         = (int) $row['id'];
		$row['created_by'] = (int) $row['created_by'];
		$row['score']      = null === $row['score'] ? null : (int) $row['score'];

		return $row;
	}
}
