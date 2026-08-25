<?php
/**
 * Writes SEO titles.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Title fixer.
 */
final class MetaTitleFixer extends MetaFieldFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'meta_title';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set meta title', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function field(): string {
		return SeoAdapterInterface::FIELD_TITLE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function max_length(): int {
		return (int) Options::get( 'title_max_length', 60 );
	}
}
