<?php
/**
 * Change log — every write the agent makes, with its before value.
 *
 * This table is what makes autonomous fixing acceptable: nothing is written
 * without recording how to put it back.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Database;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Records and reverts applied fixes.
 */
class ChangeRepository {

	/**
	 * Record one field-level change.
	 *
	 * @param array<string,mixed> $data batch, issue_id, fixer, object_type,
	 *                                  object_id, field, before_value, after_value, note.
	 *
	 * @return int Change ID.
	 */
	public function record( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'changes' ),
			array(
				'batch'        => (string) ( $data['batch'] ?? wp_generate_uuid4() ),
				'issue_id'     => (int) ( $data['issue_id'] ?? 0 ),
				'fixer'        => (string) ( $data['fixer'] ?? '' ),
				'object_type'  => (string) ( $data['object_type'] ?? '' ),
				'object_id'    => (int) ( $data['object_id'] ?? 0 ),
				'field'        => (string) ( $data['field'] ?? '' ),
				'before_value' => self::stringify( $data['before_value'] ?? null ),
				'after_value'  => self::stringify( $data['after_value'] ?? null ),
				'status'       => (string) ( $data['status'] ?? 'applied' ),
				'note'         => (string) ( $data['note'] ?? '' ),
				'applied_by'   => get_current_user_id(),
				'applied_at'   => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * All changes in a batch, newest first.
	 *
	 * @param string $batch Batch UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function by_batch( string $batch ): array {
		global $wpdb;

		$table = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch = %s ORDER BY id DESC", $batch ),
			ARRAY_A
		) ?: array();

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Recent changes across all batches.
	 *
	 * @param int    $limit  Row limit.
	 * @param string $status Optional status filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50, string $status = '' ): array {
		global $wpdb;

		$table = Schema::table( 'changes' );
		$limit = max( 1, min( 500, $limit ) );

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
		}

		return array_map( array( $this, 'hydrate' ), $wpdb->get_results( $sql, ARRAY_A ) ?: array() );
	}

	/**
	 * Find one change.
	 *
	 * @param int $id Change ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Mark a change reverted.
	 *
	 * @param int $id Change ID.
	 */
	public function mark_reverted( int $id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'changes' ),
			array(
				'status'      => 'reverted',
				'reverted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Mark a change as having failed to write.
	 *
	 * The row is kept rather than deleted: a failed write is part of the audit
	 * trail, and its recorded before value is what proves nothing was lost.
	 *
	 * @param int    $id      Change ID.
	 * @param string $message Failure reason.
	 */
	public function mark_failed( int $id, string $message ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'changes' ),
			array(
				'status' => 'failed',
				'note'   => $message,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Normalise a value for the before/after columns.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function stringify( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * Cast numerics.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 *
	 * @return array<string,mixed>
	 */
	protected function hydrate( array $row ): array {
		foreach ( array( 'id', 'issue_id', 'object_id', 'applied_by' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}

		return $row;
	}
}
