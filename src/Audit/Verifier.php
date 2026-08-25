<?php
/**
 * Re-checks a single object after a fix.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

use SEOAgent\Database\IssueRepository;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\PageFetcher;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Closes the loop: after applying a fix, re-run the checker that raised the
 * issue against only the affected object and see whether the finding is gone.
 *
 * This is what separates "the write succeeded" from "the problem is solved" —
 * a title can be saved successfully and still be too long.
 */
class Verifier {

	/** @var CheckerRegistry */
	private $registry;

	/** @var IssueRepository */
	private $issues;

	/** @var SeoAdapterInterface */
	private $seo;

	/** @var PageFetcher */
	private $fetcher;

	/**
	 * @param CheckerRegistry     $registry Checkers.
	 * @param IssueRepository     $issues   Issue storage.
	 * @param SeoAdapterInterface $seo      Metadata adapter.
	 * @param PageFetcher|null    $fetcher  HTTP fetcher.
	 */
	public function __construct(
		CheckerRegistry $registry,
		IssueRepository $issues,
		SeoAdapterInterface $seo,
		?PageFetcher $fetcher = null
	) {
		$this->registry = $registry;
		$this->issues   = $issues;
		$this->seo      = $seo;
		$this->fetcher  = $fetcher ?? new PageFetcher();
	}

	/**
	 * Re-run the owning checker for one issue's object.
	 *
	 * @param int $issue_id Issue ID.
	 *
	 * @return array<string,mixed>
	 */
	public function verify( int $issue_id ): array {
		$issue = $this->issues->find( $issue_id );

		if ( ! $issue ) {
			return array(
				'ok'      => false,
				'code'    => 'issue_missing',
				'message' => 'That issue no longer exists.',
			);
		}

		$checker = $this->registry->get( (string) $issue['checker'] );

		if ( ! $checker ) {
			return array(
				'ok'      => false,
				'code'    => 'checker_missing',
				'message' => sprintf( 'Checker "%s" is not registered.', $issue['checker'] ),
			);
		}

		$object_type = (string) $issue['object_type'];
		$object_id   = (int) $issue['object_id'];

		// A page's rendered output is cached during the audit; a fix invalidates
		// that, so drop it before re-reading.
		if ( ! empty( $issue['url'] ) ) {
			$this->fetcher->forget( (string) $issue['url'] );
		}

		$scope = $this->scope_for( $issue, $checker );

		if ( null === $scope ) {
			return array(
				'ok'         => true,
				'issue_id'   => $issue_id,
				'verifiable' => false,
				'resolved'   => null,
				'code'       => (string) $issue['code'],
				'checker'    => (string) $issue['checker'],
				'message'    => sprintf(
					'The "%s" check cannot be re-run against a single object — it builds site-wide state first. This issue is re-checked by the next full audit.',
					(string) $issue['checker']
				),
			);
		}

		$context = new AuditContext(
			(int) $issue['audit_id'],
			array( 'batch_size' => 1 ),
			$this->seo,
			$this->fetcher
		);
		$context->deadline = microtime( true ) + 25;
		$context->cursor   = $scope;

		try {
			$result = $checker->run( $context );
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'code'    => 'checker_failed',
				'message' => $e->getMessage(),
			);
		}

		$scoped_id     = (int) ( $scope['after_id'] ?? 0 ) + 1;
		$still_present = false;
		$related       = array();

		foreach ( $result->issues as $found ) {
			if ( $found->fingerprint() === (string) $issue['fingerprint'] ) {
				$still_present = true;
			}

			// Findings against the object we re-checked, whether that is the
			// issue's own object or the page an image was reached through.
			if ( in_array( (int) $found->object_id, array( $object_id, $scoped_id ), true ) ) {
				$related[] = array(
					'code'     => $found->code,
					'severity' => $found->severity,
					'title'    => $found->title,
				);
			}
		}

		$this->issues->set_status( $issue_id, $still_present ? 'open' : 'fixed' );

		return array(
			'ok'         => true,
			'issue_id'   => $issue_id,
			'verifiable' => true,
			'resolved'   => ! $still_present,
			'code'       => (string) $issue['code'],
			'checker'    => (string) $issue['checker'],
			// Other findings on the same object, so a fix that solved one
			// problem and introduced another does not read as a clean pass.
			'remaining'  => $related,
		);
	}

	/**
	 * Work out how to re-run a checker for just this issue's object.
	 *
	 * @param array<string,mixed> $issue   Issue row.
	 * @param CheckerInterface    $checker Owning checker.
	 *
	 * @return array<string,mixed>|null Cursor, or null when the checker cannot
	 *                                  be scoped to one object.
	 */
	private function scope_for( array $issue, CheckerInterface $checker ): ?array {
		$object_type = (string) $issue['object_type'];
		$object_id   = (int) $issue['object_id'];

		if ( in_array( $object_type, array( 'post', 'term' ), true ) && $object_id > 0 ) {
			return array( 'after_id' => $object_id - 1 );
		}

		// An image issue is found while walking the pages that use the image,
		// not the attachment itself, so re-checking means revisiting the host
		// page — its ID is the one the cursor has to target.
		if ( 'media' === $object_type ) {
			$host = (int) ( $issue['evidence']['used_on_post'] ?? 0 );

			return $host > 0 ? array( 'after_id' => $host - 1 ) : null;
		}

		// Whole-site checks run in a single pass, so running them again is
		// exactly the verification. Multi-phase checkers are not: restarting
		// one would rebuild site-wide state and report nothing in the first
		// slice, which would read as a false pass.
		return $checker instanceof SiteChecker ? array() : null;
	}

	/**
	 * Verify several issues.
	 *
	 * @param int[] $issue_ids Issue IDs.
	 *
	 * @return array<string,mixed>
	 */
	public function verify_many( array $issue_ids ): array {
		$results      = array();
		$resolved     = 0;
		$unverifiable = 0;

		foreach ( $issue_ids as $issue_id ) {
			$result = $this->verify( (int) $issue_id );

			if ( ! empty( $result['resolved'] ) ) {
				++$resolved;
			} elseif ( isset( $result['verifiable'] ) && false === $result['verifiable'] ) {
				++$unverifiable;
			}

			$results[] = $result;
		}

		return array(
			'total'        => count( $results ),
			'resolved'     => $resolved,
			// Counted separately so "not resolved" never quietly includes
			// "could not be checked".
			'unverifiable' => $unverifiable,
			'results'      => $results,
		);
	}
}
