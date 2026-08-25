<?php
/**
 * Adds alt attributes to images written directly into post content.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Html;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * For images that are not in the media library, the alt has to live in the
 * markup. This rewrites the `<img>` tags in place, leaving everything else in
 * the content untouched.
 */
final class InlineImageAltFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'inline_image_alt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Add alt text to inline images', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'alts' => __( 'A map of image src to the alt text it should get. Sources not listed are left alone.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$post_id = (int) ( $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$alts = isset( $input['alts'] ) && is_array( $input['alts'] ) ? $input['alts'] : array();

		if ( empty( $alts ) ) {
			throw new FixException(
				'Pass "alts" as a map of image src to alt text. Nothing is guessed here — the alt has to describe what is in the picture.',
				'input_required',
				array( 'sources' => $issue['evidence']['sources'] ?? array() )
			);
		}

		$content = (string) $post->post_content;
		$updated = $content;
		$applied = array();

		foreach ( Html::images( $content ) as $image ) {
			$src = $image['src'];

			if ( '' === $src || ! isset( $alts[ $src ] ) ) {
				continue;
			}

			$alt = trim( wp_strip_all_tags( (string) $alts[ $src ], true ) );

			if ( '' === $alt ) {
				continue;
			}

			$replacement = $this->set_alt( $image['raw'], $alt );

			if ( $replacement === $image['raw'] ) {
				continue;
			}

			// Replace this specific tag only, so an image repeated on the page
			// with different intent is not collapsed into one.
			$position = strpos( $updated, $image['raw'] );

			if ( false === $position ) {
				continue;
			}

			$updated   = substr_replace( $updated, $replacement, $position, strlen( $image['raw'] ) );
			$applied[] = $src;
		}

		if ( empty( $applied ) ) {
			throw new FixException(
				'None of the supplied sources matched an image in this page.',
				'no_match',
				array( 'supplied' => array_keys( $alts ) )
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
					/* translators: 1: number of images, 2: post title. */
					__( 'Added alt text to %1$d images in "%2$s"', 'seo-automation' ),
					count( $applied ),
					$post->post_title
				),
				array( 'sources' => $applied )
			),
		);
	}

	/**
	 * Set or replace the alt attribute on one `<img>` tag.
	 *
	 * @param string $tag Original tag.
	 * @param string $alt Alt text.
	 */
	private function set_alt( string $tag, string $alt ): string {
		$escaped = esc_attr( $alt );

		if ( preg_match( '/\salt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag ) ) {
			return (string) preg_replace(
				'/\salt\s*=\s*("[^"]*"|\'[^\']*\')/i',
				sprintf( ' alt="%s"', $escaped ),
				$tag,
				1
			);
		}

		// Insert immediately after the tag name so the result stays readable.
		return (string) preg_replace(
			'/^<img\b/i',
			sprintf( '<img alt="%s"', $escaped ),
			$tag,
			1
		);
	}
}
