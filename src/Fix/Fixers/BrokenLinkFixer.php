<?php
/**
 * Replaces or removes broken links.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Html;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swaps a dead URL for a working one across every page that links to it, or
 * unwraps the anchor and leaves the text in place.
 */
final class BrokenLinkFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'broken_link';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Replace or remove a broken link', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'replacement' => __( 'The working URL to point at instead. Pass "unlink" as true to strip the link and keep the text.', 'seo-automation' ),
			'unlink'      => __( 'Set true to remove the anchor, keeping its text. Use when there is no equivalent destination.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$broken  = (string) ( $payload['url'] ?? '' );
		$targets = array_map( 'intval', (array) ( $payload['post_ids'] ?? array() ) );

		if ( '' === $broken || empty( $targets ) ) {
			throw new FixException( esc_html( 'This issue records no URL or no pages to update.' ), 'no_target' );
		}

		$unlink      = ! empty( $input['unlink'] );
		$replacement = trim( (string) ( $input['replacement'] ?? '' ) );

		if ( ! $unlink ) {
			if ( '' === $replacement ) {
				throw new FixException(
					esc_html( sprintf( 'Supply a replacement URL for %s, or set "unlink" to true to remove the link.', $broken ) ),
					'input_required',
					array( 'broken_url' => $broken ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is machine-readable metadata for the REST layer, never rendered; the message argument is escaped.
				);
			}

			if ( ! preg_match( '#^(https?://|/|mailto:)#i', $replacement ) ) {
				throw new FixException(
					esc_html( sprintf( '"%s" is not a usable URL.', $replacement ) ),
					'invalid_replacement'
				);
			}

			$replacement = esc_url_raw( $replacement );
		}

		$changes = array();

		foreach ( $targets as $post_id ) {
			$post    = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$content = (string) $post->post_content;
			$updated = $unlink
				? $this->unlink( $content, $broken )
				: str_replace( $broken, $replacement, $content );

			if ( $updated === $content ) {
				continue;
			}

			$changes[] = new FixChange(
				'post',
				$post_id,
				'post:post_content',
				$content,
				$updated,
				$unlink
					? sprintf(
						/* translators: 1: broken URL, 2: post title. */
						__( 'Removed the link to %1$s in "%2$s", keeping the text', 'seo-automation' ),
						$broken,
						$post->post_title
					)
					: sprintf(
						/* translators: 1: broken URL, 2: replacement URL, 3: post title. */
						__( 'Repointed %1$s to %2$s in "%3$s"', 'seo-automation' ),
						$broken,
						$replacement,
						$post->post_title
					)
			);
		}

		if ( empty( $changes ) ) {
			throw new FixException(
				esc_html( sprintf( '%s no longer appears in any of the recorded pages.', $broken ) ),
				'stale_issue'
			);
		}

		return $changes;
	}

	/**
	 * Replace anchors pointing at a URL with their own text.
	 *
	 * @param string $content Post content.
	 * @param string $url     URL to unlink.
	 */
	private function unlink( string $content, string $url ): string {
		foreach ( Html::links( $content ) as $link ) {
			if ( $link['href'] !== $url ) {
				continue;
			}

			$position = strpos( $content, $link['raw'] );

			if ( false === $position ) {
				continue;
			}

			// Keep the inner markup, drop only the anchor wrapper.
			$inner   = preg_replace( '/^<a\b[^>]*>|<\/a>$/i', '', $link['raw'] ) ?? $link['text'];
			$content = substr_replace( $content, $inner, $position, strlen( $link['raw'] ) );
		}

		return $content;
	}
}
