<?php
/**
 * Writes meta descriptions.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Description fixer.
 */
final class MetaDescriptionFixer extends MetaFieldFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'meta_description';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set meta description', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function field(): string {
		return SeoAdapterInterface::FIELD_DESCRIPTION;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function max_length(): int {
		return (int) Options::get( 'description_max_length', 155 );
	}
}
