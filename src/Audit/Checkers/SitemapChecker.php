<?php
/**
 * XML sitemap checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\SiteChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates the sitemap, confirms it works, and looks for the contradiction that
 * matters most: URLs listed for indexing that are simultaneously marked
 * noindex.
 */
final class SitemapChecker extends SiteChecker {

	/** Sample size for per-URL verification. */
	private const SAMPLE = 25;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'sitemap';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'XML sitemap', 'seo-automation' );
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
		return __( 'Finds the sitemap, checks it responds, and looks for URLs that are listed but excluded from the index.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_site( AuditContext $context ): array {
		$found = $this->locate( $context );

		if ( null === $found ) {
			return array(
				$this->issue(
					array(
						'code'        => 'sitemap.missing',
						'severity'    => Issue::SEVERITY_HIGH,
						'object_type' => 'site',
						'object_id'   => 0,
						'title'       => __( 'No XML sitemap found', 'seo-automation' ),
						'detail'      => __( 'None of the usual sitemap locations responded. Search engines can still crawl the site through links, but new and orphaned pages will be found slowly or not at all.', 'seo-automation' ),
						'url'         => home_url( '/wp-sitemap.xml' ),
						'evidence'    => array( 'candidates' => $this->candidates() ),
						'fix_mode'    => Issue::MODE_MANUAL,
					)
				),
			);
		}

		[ $sitemap_url, $body ] = $found;

		$issues = array();
		$urls   = $this->extract_urls( $body );
		$is_index = false !== stripos( $body, '<sitemapindex' );

		// A sitemap index points at child sitemaps; follow the first one so the
		// URL-level checks have something real to work with.
		if ( $is_index && ! empty( $urls ) ) {
			$child = $context->fetcher->get( $urls[0], HOUR_IN_SECONDS );

			if ( '' === $child['error'] && $child['status'] < 400 ) {
				$urls = $this->extract_urls( $child['body'] );
			}
		}

		if ( empty( $urls ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'sitemap.empty',
					'severity'    => Issue::SEVERITY_HIGH,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Sitemap contains no URLs', 'seo-automation' ),
					/* translators: %s: sitemap URL. */
					'detail'      => sprintf( __( '%s responded but lists nothing. A sitemap that submits an empty set is worse than none, because it looks authoritative.', 'seo-automation' ), $sitemap_url ),
					'url'         => $sitemap_url,
					'evidence'    => array( 'sitemap' => $sitemap_url ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);

			return $issues;
		}

		$sample     = array_slice( $urls, 0, self::SAMPLE );
		$noindexed  = array();
		$unresolved = array();

		foreach ( $sample as $url ) {
			$post_id = url_to_postid( $url );

			if ( $post_id <= 0 ) {
				$unresolved[] = $url;
				continue;
			}

			if ( $context->seo->is_noindex( 'post', $post_id ) ) {
				$noindexed[] = array(
					'url'     => $url,
					'post_id' => $post_id,
					'title'   => (string) get_the_title( $post_id ),
				);
			}
		}

		if ( ! empty( $noindexed ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'sitemap.contains_noindex',
					'severity'    => Issue::SEVERITY_MEDIUM,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Sitemap lists pages marked noindex', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: 1: count, 2: sample size. */
						__( '%1$d of the first %2$d sitemap URLs are set to noindex. The sitemap asks for indexing while the page refuses it — pick one.', 'seo-automation' ),
						count( $noindexed ),
						count( $sample )
					),
					'url'         => $sitemap_url,
					'evidence'    => array(
						'pages'          => array_slice( $noindexed, 0, 20 ),
						'affected_count' => count( $noindexed ),
						'sampled'        => count( $sample ),
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		// Cross-check the robots.txt declaration while we are here.
		$robots = $context->fetcher->get( RobotsChecker::url(), HOUR_IN_SECONDS );

		if ( '' === $robots['error'] && $robots['status'] < 400 && false === stripos( $robots['body'], $sitemap_url ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'sitemap.not_declared',
					'severity'    => Issue::SEVERITY_LOW,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'robots.txt does not point at the sitemap that exists', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: %s: sitemap URL. */
						__( '%s works but is not referenced in robots.txt, so crawlers have to guess its location.', 'seo-automation' ),
						$sitemap_url
					),
					'url'         => $sitemap_url,
					'evidence'    => array( 'sitemap' => $sitemap_url ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		return $issues;
	}

	/**
	 * Locations worth trying, in order of likelihood.
	 *
	 * @return string[]
	 */
	private function candidates(): array {
		return array(
			home_url( '/sitemap_index.xml' ),
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap-index.xml' ),
		);
	}

	/**
	 * First candidate that returns XML.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return array{0:string,1:string}|null URL and body.
	 */
	private function locate( AuditContext $context ): ?array {
		foreach ( $this->candidates() as $url ) {
			$response = $context->fetcher->get( $url, HOUR_IN_SECONDS );

			if ( '' !== $response['error'] || $response['status'] >= 400 ) {
				continue;
			}

			if ( false === stripos( $response['body'], '<urlset' ) && false === stripos( $response['body'], '<sitemapindex' ) ) {
				continue;
			}

			return array( $url, $response['body'] );
		}

		return null;
	}

	/**
	 * Pull `<loc>` values out of a sitemap document.
	 *
	 * @param string $xml Sitemap body.
	 *
	 * @return string[]
	 */
	private function extract_urls( string $xml ): array {
		if ( ! preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $xml, $matches ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn( $url ) => esc_url_raw( html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
					$matches[1]
				)
			)
		);
	}
}
