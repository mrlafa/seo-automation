<?php
/**
 * Canonical URL checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Seo\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspects explicitly set canonical URLs.
 *
 * Only stored overrides are examined — a page with no override gets a correct
 * self-referencing canonical from WordPress and every SEO plugin, so checking
 * those would mean fetching every page to learn nothing. Overrides are where
 * the damage happens: a canonical pointing somewhere wrong quietly removes a
 * page from the index.
 */
final class CanonicalChecker extends PostChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'canonical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Canonical URLs', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'technical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Examines custom canonical URLs for the mistakes that silently de-index a page.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$canonical = $context->seo->get( SeoAdapterInterface::FIELD_CANONICAL, 'post', $post->ID );

		if ( null === $canonical ) {
			return array();
		}

		$permalink = (string) get_permalink( $post );

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => $permalink,
			'fixer'        => 'canonical',
		);

		$evidence = array_merge(
			$this->post_context( $post ),
			array(
				'canonical' => $canonical,
				'permalink' => $permalink,
			)
		);

		$issues = array();

		if ( ! preg_match( '#^https?://#i', $canonical ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'canonical.relative',
						'severity'    => Issue::SEVERITY_HIGH,
						'title'       => __( 'Canonical URL is not absolute', 'seo-automation' ),
						/* translators: %s: the stored canonical value. */
						'detail'      => sprintf( __( 'The canonical is set to "%s". Canonical URLs must be absolute, including the scheme and host, or they are ignored.', 'seo-automation' ), $canonical ),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_AUTO,
						'fix_payload' => array(
							'post_id'   => $post->ID,
							'canonical' => $permalink,
						),
					)
				)
			);

			return $issues;
		}

		$canonical_host = strtolower( (string) wp_parse_url( $canonical, PHP_URL_HOST ) );

		if ( '' !== $canonical_host && $canonical_host !== $this->site_host() ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'canonical.cross_domain',
						'severity' => Issue::SEVERITY_HIGH,
						'title'    => __( 'Canonical points to another domain', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: canonical URL, 2: site host. */
							__( 'This page names %1$s as its canonical, handing all its ranking value to a site other than %2$s. Correct if unintended — syndicated content is the only case where this is right.', 'seo-automation' ),
							$canonical,
							$this->site_host()
						),
						'evidence' => array_merge( $evidence, array( 'canonical_host' => $canonical_host ) ),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);

			return $issues;
		}

		// Same URL bar the trailing slash or the scheme: harmless intent, but
		// it can still split signals if the server treats the forms differently.
		$normalised_canonical = $this->normalise( $canonical );
		$normalised_permalink = $this->normalise( $permalink );

		if ( $normalised_canonical === $normalised_permalink && $canonical !== $permalink ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'canonical.inconsistent_form',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Canonical differs from the permalink only in form', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: canonical, 2: permalink. */
							__( 'The canonical is "%1$s" but the page is served at "%2$s". They differ only in scheme or trailing slash; align them so there is one unambiguous URL.', 'seo-automation' ),
							$canonical,
							$permalink
						),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_AUTO,
						'fix_payload' => array(
							'post_id'   => $post->ID,
							'canonical' => $permalink,
						),
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Reduce a URL to the form where only meaningful differences remain.
	 *
	 * @param string $url URL.
	 */
	private function normalise( string $url ): string {
		$url = preg_replace( '#^https?://#i', '', $url ) ?? $url;
		$url = preg_replace( '#^www\.#i', '', $url ) ?? $url;

		return strtolower( untrailingslashit( $url ) );
	}
}
