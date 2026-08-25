<?php
/**
 * Demotes surplus H1 headings.
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
 * Rewrites the second and subsequent `<h1>` in a page's content to `<h2>`,
 * preserving every attribute on the tag.
 *
 * Deterministic: no judgement is involved, so this one is safe to run
 * unattended.
 */
final class HeadingLevelFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'heading_level';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Demote surplus H1 headings', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_deterministic(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$post_id = (int) ( $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$targets = (array) ( $issue['fix_payload']['demote'] ?? array() );

		if ( empty( $targets ) ) {
			throw new FixException( 'This issue lists no headings to demote.', 'no_target' );
		}

		$content = (string) $post->post_content;
		$updated = $content;
		$count   = 0;

		foreach ( $targets as $raw ) {
			$raw = (string) $raw;

			$position = strpos( $updated, $raw );

			if ( false === $position ) {
				// The content changed since the audit; skip rather than guess.
				continue;
			}

			$demoted = (string) preg_replace(
				array( '/^<h1\b/i', '/<\/h1>$/i' ),
				array( '<h2', '</h2>' ),
				$raw
			);

			$updated = substr_replace( $updated, $demoted, $position, strlen( $raw ) );
			++$count;
		}

		if ( 0 === $count ) {
			throw new FixException(
				'None of the recorded headings are still present — the page has been edited since the audit. Re-run the audit and try again.',
				'stale_issue'
			);
		}

		return array(
			new FixChange(
				'post',
				$post_id,
				'post:post_content',
				$content,
				$updated,
				sprintf(
					/* translators: 1: number of headings, 2: post title. */
					__( 'Demoted %1$d surplus H1 headings to H2 in "%2$s"', 'seo-automation' ),
					$count,
					$post->post_title
				)
			),
		);
	}
}
