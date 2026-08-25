<?php
/**
 * What one checker slice produced.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Findings plus the cursor needed to resume.
 */
final class CheckerResult {

	/** @var Issue[] */
	public $issues;

	/**
	 * Cursor to pass back on the next call, or null when the checker is done.
	 *
	 * @var array<string,mixed>|null
	 */
	public $cursor;

	/** @var int Objects examined in this slice. */
	public $scanned;

	/** @var string[] Non-fatal notes worth surfacing in the report. */
	public $notes;

	/**
	 * @param Issue[]                  $issues  Findings.
	 * @param array<string,mixed>|null $cursor  Resume state, null when finished.
	 * @param int                      $scanned Objects examined.
	 * @param string[]                 $notes   Notes.
	 */
	public function __construct( array $issues = array(), ?array $cursor = null, int $scanned = 0, array $notes = array() ) {
		$this->issues  = $issues;
		$this->cursor  = $cursor;
		$this->scanned = $scanned;
		$this->notes   = $notes;
	}

	/**
	 * Convenience constructor for a finished single-pass checker.
	 *
	 * @param Issue[] $issues  Findings.
	 * @param int     $scanned Objects examined.
	 * @param string[] $notes  Notes.
	 */
	public static function done( array $issues, int $scanned = 1, array $notes = array() ): CheckerResult {
		return new self( $issues, null, $scanned, $notes );
	}

	/**
	 * Has this checker finished?
	 */
	public function is_complete(): bool {
		return null === $this->cursor;
	}
}
