<?php
/**
 * Writes alt text on media library images.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Sets `_wp_attachment_image_alt`, which is where WordPress reads alt from for
 * every rendering of the image — so one write fixes it everywhere it appears.
 */
final class ImageAltFixer extends AbstractFixer {

	private const MAX_ALT_LENGTH = 125;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'image_alt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set image alt text', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value' => __( 'What the image shows, in a sentence fragment of at most 125 characters. Describe the content, not the file.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$attachment_id = (int) ( $issue['fix_payload']['attachment_id'] ?? $issue['object_id'] ?? 0 );

		if ( $attachment_id <= 0 ) {
			throw new FixException( 'This issue names no image.', 'no_target' );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			throw new FixException(
				sprintf( 'Post %d is not an attachment.', $attachment_id ),
				'not_an_attachment'
			);
		}

		$value = $this->value_from( $input, $issue );

		if ( null === $value ) {
			throw new FixException(
				'No alt text supplied, and this image carries no caption or title to fall back on.',
				'input_required',
				array( 'attachment_id' => $attachment_id )
			);
		}

		$value = trim( wp_strip_all_tags( $value, true ) );

		if ( Text::length( $value ) > self::MAX_ALT_LENGTH ) {
			$value = Text::truncate( $value, self::MAX_ALT_LENGTH, '' );
		}

		// "Image of" and friends are read aloud by screen readers on top of the
		// announcement that this is an image.
		$value = preg_replace( '/^(image|picture|photo|graphic|icon)\s+(of|showing)\s+/i', '', $value ) ?? $value;
		$value = ucfirst( trim( $value ) );

		$before = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			new FixChange(
				'media',
				$attachment_id,
				'meta:_wp_attachment_image_alt',
				'' === $before ? null : $before,
				$value,
				sprintf(
					/* translators: %s: image filename. */
					__( 'Set alt text on "%s"', 'seo-automation' ),
					basename( (string) get_attached_file( $attachment_id ) )
				)
			),
		);
	}
}
