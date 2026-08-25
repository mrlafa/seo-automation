<?php
/**
 * Re-enables search engine indexing site-wide.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Flips `blog_public` back on.
 *
 * Deliberately not deterministic despite being a one-line change: a staging
 * site has this switched off on purpose, and turning it on would put a
 * duplicate of the production site into the index. This one always waits for
 * a human to confirm.
 */
final class SiteVisibilityFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'site_visibility';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Allow search engines to index the site', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'confirm_production' => __( 'Must be true. Confirms this is the live site and not a staging or development copy.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		if ( empty( $input['confirm_production'] ) ) {
			throw new FixException(
				'Indexing is switched off deliberately on staging sites. Set "confirm_production" to true if this is the live site.',
				'confirmation_required',
				array( 'site_url' => home_url( '/' ) )
			);
		}

		$before = (string) get_option( 'blog_public' );

		if ( '1' === $before ) {
			throw new FixException( 'Indexing is already enabled.', 'no_change' );
		}

		return array(
			new FixChange(
				'option',
				0,
				'option:blog_public',
				$before,
				'1',
				__( 'Allowed search engines to index the site', 'seo-automation' )
			),
		);
	}
}
