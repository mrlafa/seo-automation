<?php
/**
 * Contract for a single audit check.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * A checker inspects one aspect of the site and returns findings.
 *
 * Checkers must be resumable: each call does a bounded slice of work and hands
 * back a cursor, so a 50,000-product store audits in many short requests rather
 * than one that times out.
 */
interface CheckerInterface {

	/**
	 * Stable machine name, e.g. 'meta_title'.
	 */
	public function slug(): string;

	/**
	 * Human label for reports.
	 */
	public function label(): string;

	/**
	 * Grouping for the report: technical, content, links, schema, commerce,
	 * performance or discovery.
	 */
	public function group(): string;

	/**
	 * One-line explanation of what this checker looks for.
	 */
	public function description(): string;

	/**
	 * Should this checker run on this site at all?
	 *
	 * Lets the WooCommerce checkers stand down on a blog without the runner
	 * needing to know why.
	 */
	public function is_applicable(): bool;

	/**
	 * Inspect one slice of the site.
	 *
	 * @param AuditContext $context Run state, including this checker's cursor.
	 */
	public function run( AuditContext $context ): CheckerResult;
}
