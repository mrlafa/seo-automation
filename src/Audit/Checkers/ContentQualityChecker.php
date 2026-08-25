<?php
/**
 * Content depth and freshness checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Content;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Flags indexable pages that are too thin to rank and pages that have gone
 * stale.
 */
final class ContentQualityChecker extends PostChecker {

	/** Content untouched for this long is worth a second look. */
	private const STALE_AFTER_DAYS = 730;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'content_quality';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Content depth', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Finds indexable pages with too little content to compete, and pages that have not been touched in years.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		if ( $context->seo->is_noindex( 'post', $post->ID ) ) {
			return array();
		}

		// Block-based builders (Divi 5 and similar) can store real text as JSON
		// inside block attributes, invisible until WordPress's own render
		// pipeline runs — reading post_content raw would see a fully built
		// page as empty.
		$words = Text::word_count( Content::rendered( (string) $post->post_content ) );
		$min   = (int) $context->arg( 'min_word_count', 300 );

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
		);

		$evidence = array_merge(
			$this->post_context( $post ),
			array(
				'word_count' => $words,
				'modified'   => $post->post_modified_gmt,
			)
		);

		$issues = array();

		if ( 0 === $words && ! $this->is_exempt( $post ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'content.empty',
						'severity' => Issue::SEVERITY_HIGH,
						'title'    => __( 'Published page with no content', 'seo-automation' ),
						'detail'   => __( 'This page is published and indexable but has no body text. Either fill it in or take it out of the index.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		} elseif ( $words > 0 && $words < $min && ! $this->is_exempt( $post ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'content.thin',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Thin content', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: word count, 2: threshold. */
							__( 'This page has %1$d words against a working minimum of %2$d. Thin pages rarely rank and, in volume, drag down how the whole site is assessed.', 'seo-automation' ),
							$words,
							$min
						),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		$modified = strtotime( (string) $post->post_modified_gmt );

		if ( $modified && ( time() - $modified ) > ( self::STALE_AFTER_DAYS * DAY_IN_SECONDS ) && $words >= $min ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'content.stale',
						'severity' => Issue::SEVERITY_INFO,
						'title'    => __( 'Substantial page not updated in over two years', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %s: last modified date. */
							__( 'Last edited %s. Pages with real content are usually worth refreshing rather than leaving to decay.', 'seo-automation' ),
							(string) $post->post_modified_gmt
						),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Page types where a low word count is the correct design.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function is_exempt( \WP_Post $post ): bool {
		// WooCommerce renders these dynamically — Shop is intentionally content-
		// free (it generates the product grid), and Cart/Checkout/My Account
		// render entirely from shortcodes or blocks with no static text of their
		// own. Matched by WooCommerce's own recorded page IDs rather than by
		// slug, since a slug can be renamed but the option still points at it.
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				if ( (int) wc_get_page_id( $page ) === $post->ID ) {
					return true;
				}
			}
		}

		// Contact pages, thank-you pages and the like are legitimately short.
		if ( preg_match( '/(contact|thank|cart|checkout|account|login|privacy|terms|404)/i', (string) $post->post_name ) ) {
			return true;
		}

		/**
		 * Filter whether a post is exempt from the thin-content check.
		 *
		 * @param bool     $exempt Whether to skip the check.
		 * @param \WP_Post $post   Post being checked.
		 */
		return (bool) apply_filters( 'seo_agent_thin_content_exempt', false, $post );
	}
}
