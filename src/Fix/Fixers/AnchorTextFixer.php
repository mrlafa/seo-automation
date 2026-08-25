<?php
/**
 * Rewrites uninformative anchor text.
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
 * Replaces the text inside specific anchors, leaving the href and every
 * attribute untouched.
 */
final class AnchorTextFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'anchor_text';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Rewrite anchor text', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'anchors' => __( 'A list of {href, from, to} objects: the link URL, its current text, and what it should say. The new text should describe the destination.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$post_id = (int) ( $payload['post_id'] ?? $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$anchors = isset( $input['anchors'] ) && is_array( $input['anchors'] ) ? $input['anchors'] : array();

		if ( empty( $anchors ) ) {
			throw new FixException(
				'Pass "anchors" describing what each link should say instead.',
				'input_required',
				array( 'links' => $payload['links'] ?? array() )
			);
		}

		$content = (string) $post->post_content;
		$updated = $content;
		$applied = 0;

		foreach ( $anchors as $anchor ) {
			$href = trim( (string) ( $anchor['href'] ?? '' ) );
			$from = trim( (string) ( $anchor['from'] ?? '' ) );
			$to   = trim( wp_strip_all_tags( (string) ( $anchor['to'] ?? '' ), true ) );

			if ( '' === $href || '' === $to ) {
				continue;
			}

			// Match the specific anchor by both href and current text so a
			// repeated "read more" pointing elsewhere is not caught by accident.
			$pattern = sprintf(
				'#(<a\b[^>]*href\s*=\s*["\']%s["\'][^>]*>)\s*%s\s*(</a>)#i',
				preg_quote( $href, '#' ),
				'' !== $from ? preg_quote( $from, '#' ) : '[^<]*'
			);

			$result = preg_replace(
				$pattern,
				'$1' . str_replace( '$', '\$', esc_html( $to ) ) . '$2',
				$updated,
				1,
				$count
			);

			if ( null !== $result && $count > 0 ) {
				$updated = $result;
				$applied += $count;
			}
		}

		if ( 0 === $applied ) {
			throw new FixException(
				'None of the supplied anchors matched a link in this page.',
				'no_match'
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
					/* translators: 1: number of links, 2: post title. */
					__( 'Rewrote the anchor text on %1$d links in "%2$s"', 'seo-automation' ),
					$applied,
					$post->post_title
				)
			),
		);
	}
}
