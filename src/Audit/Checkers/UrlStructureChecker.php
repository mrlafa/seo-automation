<?php
/**
 * URL and slug quality checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Flags slugs that are auto-generated, bloated, or structurally awkward.
 *
 * Changing a live URL costs whatever equity the old one has unless a redirect
 * goes with it, so anything published long enough to have earned links is
 * reported for a human decision rather than offered as an automatic fix.
 */
final class UrlStructureChecker extends PostChecker {

	/** A slug older than this is assumed to have accumulated links. */
	private const SETTLED_AFTER_DAYS = 30;

	private const MAX_SLUG_LENGTH = 75;
	private const MAX_DEPTH       = 4;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'url_structure';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'URL structure', 'seo-automation' );
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
		return __( 'Checks slugs for auto-generated names, excessive length, stop words and unnecessary depth.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$slug = (string) $post->post_name;

		if ( '' === $slug ) {
			return array();
		}

		$permalink = (string) get_permalink( $post );
		$settled   = $this->is_settled( $post );

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => $permalink,
			'fixer'        => 'post_slug',
			// Once a URL has settled, rewriting it is a decision with a
			// redirect attached, not a safe automatic edit.
			'fix_mode'     => $settled ? Issue::MODE_MANUAL : Issue::MODE_ASSISTED,
		);

		$evidence = array_merge(
			$this->post_context( $post ),
			array(
				'slug'          => $slug,
				'permalink'     => $permalink,
				'published'     => $post->post_date_gmt,
				'settled'       => $settled,
				'redirect_note' => $settled
					? __( 'This URL has been live long enough to have inbound links. Any change needs a 301 from the old path.', 'seo-automation' )
					: '',
			)
		);

		$issues = array();

		// WordPress falls back to the post ID or a generic name when the title
		// is empty at save time; those slugs are pure noise in a URL.
		if ( preg_match( '/^(\d+|post|page|untitled|auto-draft|product)(-\d+)?$/i', $slug ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.auto_generated',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Auto-generated slug', 'seo-automation' ),
						/* translators: %s: the slug. */
						'detail'      => sprintf( __( 'The URL ends in "%s", which describes nothing. A descriptive slug is one of the cheapest relevance signals available.', 'seo-automation' ), $slug ),
						'evidence'    => $evidence,
						'fix_payload' => array( 'suggestion' => $this->suggest_slug( $post ) ),
					)
				)
			);
		}

		if ( strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.too_long',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Slug is very long', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: slug length, 2: recommended maximum. */
							__( 'The slug is %1$d characters against a recommended maximum of %2$d. Long URLs get truncated in results and are awkward to share.', 'seo-automation' ),
							strlen( $slug ),
							self::MAX_SLUG_LENGTH
						),
						'evidence'    => $evidence,
						'fix_payload' => array( 'suggestion' => $this->suggest_slug( $post ) ),
					)
				)
			);
		}

		$parts      = array_filter( explode( '-', $slug ) );
		$stop_words = array_filter( $parts, array( Text::class, 'is_stop_word' ) );

		if ( count( $stop_words ) >= 3 ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.stop_words',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Slug is padded with stop words', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: %s: comma-separated stop words. */
							__( 'The slug carries the filler words %s. Removing them shortens the URL without losing meaning.', 'seo-automation' ),
							implode( ', ', $stop_words )
						),
						'evidence'    => array_merge( $evidence, array( 'stop_words' => array_values( $stop_words ) ) ),
						'fix_payload' => array( 'suggestion' => $this->suggest_slug( $post ) ),
					)
				)
			);
		}

		// Underscores are a legal slug separator, so a year is just as
		// hard-coded in my_post_2023 as it is in my-post-2023.
		if ( preg_match( '/(^|[-_])(19|20)\d{2}([-_]\d{1,2})*([-_]|$)/', $slug ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.contains_date',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Slug hard-codes a date', 'seo-automation' ),
						'detail'      => __( 'A date in the slug makes the page look stale the moment the year turns, and blocks you from refreshing the content in place.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fix_payload' => array( 'suggestion' => $this->suggest_slug( $post ) ),
					)
				)
			);
		}

		if ( false !== strpos( $slug, '_' ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.underscores',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Slug uses underscores', 'seo-automation' ),
						'detail'      => __( 'Search engines treat hyphens as word separators and underscores as joiners, so "blue_widget" reads as one token.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fix_payload' => array( 'suggestion' => str_replace( '_', '-', $slug ) ),
					)
				)
			);
		}

		if ( $slug !== strtolower( $slug ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'url.slug.uppercase',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Slug contains uppercase characters', 'seo-automation' ),
						'detail'      => __( 'URLs are case sensitive on most servers, so an uppercase slug invites duplicate URLs for the same page.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fix_payload' => array( 'suggestion' => strtolower( $slug ) ),
					)
				)
			);
		}

		$path  = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$depth = count( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		if ( $depth > self::MAX_DEPTH ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'url.depth.excessive',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'URL is deeply nested', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: depth, 2: maximum. */
							__( 'This page sits %1$d levels deep against a recommended maximum of %2$d. Deep paths dilute internal link equity and are harder to crawl.', 'seo-automation' ),
							$depth,
							self::MAX_DEPTH
						),
						'evidence' => array_merge( $evidence, array( 'depth' => $depth ) ),
						'fix_mode' => Issue::MODE_MANUAL,
						'fixer'    => null,
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Has this URL been live long enough to have accumulated links?
	 *
	 * @param \WP_Post $post Post.
	 */
	private function is_settled( \WP_Post $post ): bool {
		$published = strtotime( (string) $post->post_date_gmt );

		if ( ! $published ) {
			return true;
		}

		return ( time() - $published ) > ( self::SETTLED_AFTER_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * A clean slug derived from the post title.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function suggest_slug( \WP_Post $post ): string {
		$words = Text::keywords( (string) $post->post_title );

		if ( empty( $words ) ) {
			return '';
		}

		$slug = sanitize_title( implode( '-', array_slice( $words, 0, 8 ) ) );

		return substr( $slug, 0, self::MAX_SLUG_LENGTH );
	}
}
