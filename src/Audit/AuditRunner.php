<?php
/**
 * Drives an audit across many short slices.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

use SEOAgent\Database\AuditRepository;
use SEOAgent\Database\IssueRepository;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\PageFetcher;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Orchestrates checkers, persists findings and computes the health score.
 */
class AuditRunner {

	/** @var CheckerRegistry */
	private $registry;

	/** @var AuditRepository */
	private $audits;

	/** @var IssueRepository */
	private $issues;

	/** @var SeoAdapterInterface */
	private $seo;

	/** @var PageFetcher */
	private $fetcher;

	/**
	 * @param CheckerRegistry     $registry Checkers to run.
	 * @param AuditRepository     $audits   Audit storage.
	 * @param IssueRepository     $issues   Issue storage.
	 * @param SeoAdapterInterface $seo      Metadata adapter.
	 * @param PageFetcher|null    $fetcher  HTTP fetcher.
	 */
	public function __construct(
		CheckerRegistry $registry,
		AuditRepository $audits,
		IssueRepository $issues,
		SeoAdapterInterface $seo,
		?PageFetcher $fetcher = null
	) {
		$this->registry = $registry;
		$this->audits   = $audits;
		$this->issues   = $issues;
		$this->seo      = $seo;
		$this->fetcher  = $fetcher ?? new PageFetcher();
	}

	/**
	 * Queue a new audit.
	 *
	 * @param string[]            $scopes Checker slugs or groups; empty means all.
	 * @param array<string,mixed> $args   Run arguments.
	 *
	 * @return int Audit ID.
	 */
	public function start( array $scopes = array(), array $args = array() ): int {
		return $this->audits->create( $scopes, $args );
	}

	/**
	 * Run one bounded slice of an audit.
	 *
	 * @param int   $audit_id Audit ID.
	 * @param float $budget   Seconds this call may use.
	 *
	 * @return array<string,mixed> Progress report.
	 */
	public function step( int $audit_id, float $budget = 20.0 ): array {
		$audit = $this->audits->find( $audit_id );

		if ( ! $audit ) {
			return array(
				'audit_id' => $audit_id,
				'status'   => 'missing',
				'complete' => true,
				'error'    => 'Audit not found.',
			);
		}

		if ( in_array( $audit['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return $this->progress_report( $audit, true );
		}

		if ( 'queued' === $audit['status'] ) {
			$this->audits->mark_running( $audit_id );
			$audit['status'] = 'running';
		}

		$checkers = $this->registry->resolve( (array) $audit['scopes'] );
		$state    = is_array( $audit['cursor_state'] ) ? $audit['cursor_state'] : array();
		$deadline = microtime( true ) + max( 1.0, $budget );

		$context = new AuditContext( $audit_id, (array) $audit['args'], $this->seo, $this->fetcher );

		foreach ( $checkers as $slug => $checker ) {
			if ( ! empty( $state[ $slug ]['done'] ) ) {
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$entry = $state[ $slug ] ?? array(
				'done'    => false,
				'cursor'  => array(),
				'scanned' => 0,
				'issues'  => 0,
				'notes'   => array(),
			);

			$context->cursor   = is_array( $entry['cursor'] ) ? $entry['cursor'] : array();
			$context->deadline = $deadline;

			try {
				$result = $checker->run( $context );
			} catch ( \Throwable $e ) {
				// One broken checker must not sink the whole audit.
				$entry['done']    = true;
				$entry['notes'][] = sprintf( 'Checker failed: %s', $e->getMessage() );
				$state[ $slug ]   = $entry;

				continue;
			}

			foreach ( $result->issues as $issue ) {
				$this->issues->upsert( $issue, $audit_id );
			}

			$entry['issues']  += count( $result->issues );
			$entry['scanned'] += $result->scanned;
			$entry['cursor']   = $result->cursor ?? array();
			$entry['done']     = $result->is_complete();
			$entry['notes']    = array_slice( array_merge( $entry['notes'], $result->notes ), -20 );

			$state[ $slug ] = $entry;
		}

		$this->audits->update( $audit_id, array( 'cursor_state' => $state ) );

		$complete = $this->all_done( $checkers, $state );

		if ( $complete ) {
			$this->finalise( $audit_id, array_keys( $checkers ), $state );
		}

		$audit = $this->audits->find( $audit_id ) ?? $audit;

		return $this->progress_report( $audit, $complete );
	}

	/**
	 * Keep stepping until the audit finishes or the overall budget runs out.
	 *
	 * @param int   $audit_id Audit ID.
	 * @param float $budget   Total seconds allowed.
	 *
	 * @return array<string,mixed> Final progress report.
	 */
	public function run_to_completion( int $audit_id, float $budget = 120.0 ): array {
		$deadline = microtime( true ) + $budget;
		$report   = array();

		do {
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				break;
			}

			$report = $this->step( $audit_id, min( 20.0, $remaining ) );
		} while ( empty( $report['complete'] ) );

		return $report;
	}

	/**
	 * Cancel a running audit.
	 *
	 * @param int $audit_id Audit ID.
	 */
	public function cancel( int $audit_id ): void {
		$this->audits->update(
			$audit_id,
			array(
				'status'      => 'cancelled',
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Have all the resolved checkers finished?
	 *
	 * @param array<string,CheckerInterface> $checkers Checkers in this run.
	 * @param array<string,mixed>            $state    Cursor state.
	 */
	private function all_done( array $checkers, array $state ): bool {
		foreach ( array_keys( $checkers ) as $slug ) {
			if ( empty( $state[ $slug ]['done'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Close out stale issues, compute the score, mark the audit complete.
	 *
	 * @param int                 $audit_id Audit ID.
	 * @param string[]            $checkers Checker slugs that ran.
	 * @param array<string,mixed> $state    Cursor state.
	 */
	private function finalise( int $audit_id, array $checkers, array $state ): void {
		$resolved = $this->issues->resolve_stale( $audit_id, $checkers );

		$by_severity = $this->issues->counts_by( 'severity', array( 'status' => array( 'open' ) ) );
		$by_group    = $this->issues->counts_by( 'checker', array( 'status' => array( 'open' ) ) );

		$scanned = 0;
		foreach ( $state as $entry ) {
			$scanned += (int) ( $entry['scanned'] ?? 0 );
		}

		$totals = array(
			'by_severity'    => $by_severity,
			'by_checker'     => $by_group,
			'open'           => array_sum( $by_severity ),
			'resolved_since' => $resolved,
			'scanned'        => $scanned,
			'checkers'       => $state,
		);

		$this->audits->mark_completed( $audit_id, $totals, self::score( $by_severity, $scanned ) );
	}

	/**
	 * Health score from 0 to 100.
	 *
	 * Severity-weighted issue density rather than a raw count, so a 10-page
	 * brochure site and a 50,000-SKU store are graded on the same curve. The
	 * exponential decay keeps the top of the range meaningful: a handful of
	 * medium issues should not read as a failing site.
	 *
	 * @param array<string,int> $by_severity Open issue counts per severity.
	 * @param int               $scanned     Objects examined.
	 */
	public static function score( array $by_severity, int $scanned ): int {
		$weights = array(
			Issue::SEVERITY_CRITICAL => 8.0,
			Issue::SEVERITY_HIGH     => 4.0,
			Issue::SEVERITY_MEDIUM   => 1.5,
			Issue::SEVERITY_LOW      => 0.5,
			Issue::SEVERITY_INFO     => 0.0,
		);

		$penalty = 0.0;
		foreach ( $weights as $severity => $weight ) {
			$penalty += $weight * (int) ( $by_severity[ $severity ] ?? 0 );
		}

		// Floor the denominator so a tiny site with one critical issue still
		// scores badly rather than being divided into insignificance.
		$scale   = max( 20, $scanned );
		$density = $penalty / $scale;

		$score = 100.0 * exp( -1.5 * $density );

		return (int) max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Shape the progress payload returned to callers.
	 *
	 * @param array<string,mixed> $audit    Audit row.
	 * @param bool                $complete Whether the run finished.
	 *
	 * @return array<string,mixed>
	 */
	private function progress_report( array $audit, bool $complete ): array {
		$state    = is_array( $audit['cursor_state'] ) ? $audit['cursor_state'] : array();
		$checkers = array();

		foreach ( $state as $slug => $entry ) {
			$checkers[ $slug ] = array(
				'done'    => (bool) ( $entry['done'] ?? false ),
				'scanned' => (int) ( $entry['scanned'] ?? 0 ),
				'issues'  => (int) ( $entry['issues'] ?? 0 ),
				'notes'   => (array) ( $entry['notes'] ?? array() ),
			);
		}

		$total_checkers = count( $this->registry->resolve( (array) $audit['scopes'] ) );
		$done_checkers  = count( array_filter( $checkers, static fn( $entry ) => $entry['done'] ) );

		return array(
			'audit_id' => (int) $audit['id'],
			'status'   => $complete ? ( $audit['status'] ?? 'completed' ) : 'running',
			'complete' => $complete,
			'score'    => $audit['score'] ?? null,
			'totals'   => $audit['totals'] ?? array(),
			'progress' => array(
				'checkers_done'  => $done_checkers,
				'checkers_total' => $total_checkers,
				'percent'        => $total_checkers > 0 ? (int) round( 100 * $done_checkers / $total_checkers ) : 100,
			),
			'checkers' => $checkers,
		);
	}
}
