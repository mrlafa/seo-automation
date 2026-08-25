<?php
/**
 * Base class for whole-site checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs once per audit against the site as a whole.
 */
abstract class SiteChecker extends AbstractChecker {

	/**
	 * Inspect the site.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	abstract protected function check_site( AuditContext $context ): array;

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		return CheckerResult::done( $this->check_site( $context ), 1 );
	}
}
