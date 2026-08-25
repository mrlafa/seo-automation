<?php
/**
 * Contract for something that can resolve an issue.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

use SEOAgent\Seo\SeoAdapterInterface;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Fixers are split into planning and writing so that every fix can be
 * previewed, diffed and approved before anything is touched.
 */
interface FixerInterface {

	/**
	 * Machine name, referenced by issues.
	 */
	public function slug(): string;

	/**
	 * Human label.
	 */
	public function label(): string;

	/**
	 * Is this fix deterministic enough to run unattended?
	 *
	 * Fixers that need generated copy return false: they can be applied
	 * automatically only once an agent or a human has supplied the value.
	 */
	public function is_deterministic(): bool;

	/**
	 * Input this fixer needs when the issue cannot be resolved mechanically.
	 *
	 * @return array<string,string> field name => what it should contain.
	 */
	public function required_input(): array;

	/**
	 * Work out what would change, without changing it.
	 *
	 * @param array<string,mixed> $issue Issue row.
	 * @param array<string,mixed> $input Caller-supplied values.
	 * @param SeoAdapterInterface $seo   Metadata adapter.
	 *
	 * @return FixChange[]
	 *
	 * @throws FixException When the input is insufficient or invalid.
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array;

	/**
	 * Perform one planned change.
	 *
	 * @param FixChange           $change Change to write.
	 * @param SeoAdapterInterface $seo    Metadata adapter.
	 *
	 * @return bool True when the store was updated.
	 */
	public function write( FixChange $change, SeoAdapterInterface $seo ): bool;
}
