<?php
/**
 * Image alt text checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Content;
use SEOAgent\Support\Html;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds images with no alt text, and alt text that says nothing useful.
 *
 * Works on the media library record rather than the markup wherever possible,
 * because that is where WordPress reads alt from and therefore where a fix has
 * to be written for it to stick across content edits.
 */
final class ImageAltChecker extends PostChecker {

	/** Alt values that are present but carry no information. */
	private const USELESS_ALT = array( 'image', 'img', 'photo', 'picture', 'icon', 'logo', 'banner', 'untitled', 'dsc', 'screenshot' );

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
		return __( 'Image alt text', 'seo-automation' );
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
		return __( 'Finds images that are invisible to search engines and screen readers because they have no meaningful alt text.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$issues = array();

		$featured_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $featured_id > 0 ) {
			$issue = $this->check_attachment( $featured_id, $post, true );
			if ( $issue ) {
				$issues[] = $issue;
			}
		}

		// Block builders can generate an <img> tag from a JSON url attribute
		// rather than storing the tag literally, so an image found only this
		// way may not have a literal <img> string for InlineImageAltFixer to
		// edit — it reports 'no_match' cleanly in that case rather than
		// corrupting the block source, which is preferable to not finding the
		// problem at all.
		$images    = Html::images( Content::rendered( (string) $post->post_content ) );
		$missing   = array();
		$unhelpful = array();

		foreach ( $images as $image ) {
			if ( '' === $image['src'] ) {
				continue;
			}

			$attachment_id = attachment_url_to_postid( $image['src'] );

			// Media-library images are handled through their own record so the
			// fix persists; only inline/external images are judged on markup.
			if ( $attachment_id > 0 ) {
				$issue = $this->check_attachment( $attachment_id, $post, false );
				if ( $issue ) {
					$issues[] = $issue;
				}

				continue;
			}

			if ( null === $image['alt'] ) {
				$missing[] = $image['src'];
			} elseif ( '' !== trim( $image['alt'] ) && $this->is_unhelpful( $image['alt'] ) ) {
				$unhelpful[] = array(
					'src' => $image['src'],
					'alt' => $image['alt'],
				);
			}
		}

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
		);

		if ( ! empty( $missing ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'image.alt.missing_inline',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Inline images with no alt attribute', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %d: number of images. */
							__( '%d images in this page\'s content have no alt attribute at all and are not in the media library, so the alt has to be written into the content itself.', 'seo-automation' ),
							count( $missing )
						),
						'evidence' => array_merge(
							$this->post_context( $post ),
							array(
								'sources'        => array_slice( $missing, 0, 20 ),
								'affected_count' => count( $missing ),
							)
						),
						'fix_mode' => Issue::MODE_ASSISTED,
						'fixer'    => 'inline_image_alt',
						'fix_payload' => array( 'sources' => array_slice( $missing, 0, 20 ) ),
					)
				)
			);
		}

		if ( ! empty( $unhelpful ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'image.alt.unhelpful_inline',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Inline images with placeholder alt text', 'seo-automation' ),
						'detail'   => __( 'Alt values like "image" or "DSC_0042" describe nothing. Replace them with what the image actually shows.', 'seo-automation' ),
						'evidence' => array_merge(
							$this->post_context( $post ),
							array(
								'images'         => array_slice( $unhelpful, 0, 20 ),
								'affected_count' => count( $unhelpful ),
							)
						),
						'fix_mode' => Issue::MODE_ASSISTED,
						'fixer'    => 'inline_image_alt',
						'fix_payload' => array( 'images' => array_slice( $unhelpful, 0, 20 ) ),
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Judge one media-library image.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param \WP_Post $post          Post the image appears on.
	 * @param bool     $is_featured   Whether this is the featured image.
	 */
	private function check_attachment( int $attachment_id, \WP_Post $post, bool $is_featured ): ?Issue {
		$alt      = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$filename = basename( (string) get_attached_file( $attachment_id ) );

		$common = array(
			'object_type'  => 'media',
			'object_id'    => $attachment_id,
			'object_label' => $filename,
			'url'          => (string) wp_get_attachment_url( $attachment_id ),
			'fixer'        => 'image_alt',
			'evidence'     => array(
				'attachment_id'  => $attachment_id,
				'filename'       => $filename,
				'alt'            => $alt,
				'used_on_post'   => (int) $post->ID,
				'used_on_title'  => (string) $post->post_title,
				'used_on_url'    => (string) get_permalink( $post ),
				'is_featured'    => $is_featured,
				'caption'        => (string) wp_get_attachment_caption( $attachment_id ),
				'attachment_title' => (string) get_the_title( $attachment_id ),
			),
			'fix_payload'  => array(
				'attachment_id' => $attachment_id,
				'suggestion'    => $this->suggest_alt( $attachment_id, $post ),
			),
		);

		if ( '' === trim( $alt ) ) {
			return $this->issue(
				array_merge(
					$common,
					array(
						'code'     => $is_featured ? 'image.alt.missing_featured' : 'image.alt.missing',
						'severity' => $is_featured ? Issue::SEVERITY_MEDIUM : Issue::SEVERITY_LOW,
						'title'    => $is_featured
							? __( 'Featured image has no alt text', 'seo-automation' )
							: __( 'Image has no alt text', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: filename, 2: post title. */
							__( '"%1$s" (used on "%2$s") has an empty alt attribute in the media library. It contributes nothing to image search and is silent to screen readers.', 'seo-automation' ),
							$filename,
							$post->post_title
						),
						'fix_mode' => Issue::MODE_ASSISTED,
					)
				)
			);
		}

		if ( $this->is_unhelpful( $alt ) ) {
			return $this->issue(
				array_merge(
					$common,
					array(
						'code'     => 'image.alt.unhelpful',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Alt text does not describe the image', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: current alt text, 2: filename. */
							__( 'The alt text for "%2$s" is "%1$s", which carries no information about the image.', 'seo-automation' ),
							$alt,
							$filename
						),
						'fix_mode' => Issue::MODE_ASSISTED,
					)
				)
			);
		}

		return null;
	}

	/**
	 * Does this alt value say anything?
	 *
	 * @param string $alt Alt text.
	 */
	private function is_unhelpful( string $alt ): bool {
		$normalised = strtolower( trim( $alt ) );

		if ( '' === $normalised ) {
			return false;
		}

		// A bare filename, with or without extension.
		if ( preg_match( '/^[\w-]+\.(jpe?g|png|gif|webp|avif|svg)$/i', $normalised ) ) {
			return true;
		}

		// Camera-roll style names: dsc_0042, img-1234, screenshot 2024-01-01.
		if ( preg_match( '/^(dsc|img|image|photo|pic|screenshot|untitled)[\s_-]*\d*$/i', $normalised ) ) {
			return true;
		}

		if ( in_array( $normalised, self::USELESS_ALT, true ) ) {
			return true;
		}

		// A single short word is rarely a description.
		return Text::length( $normalised ) < 4;
	}

	/**
	 * Best available starting point for alt text.
	 *
	 * Prefers human-written media metadata over the filename, and hands the
	 * agent the surrounding context so it can write something specific.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param \WP_Post $post          Post the image appears on.
	 */
	private function suggest_alt( int $attachment_id, \WP_Post $post ): string {
		$caption = trim( (string) wp_get_attachment_caption( $attachment_id ) );
		if ( '' !== $caption ) {
			return Text::truncate( Text::plain( $caption ), 125, '' );
		}

		$title = trim( (string) get_the_title( $attachment_id ) );

		// WordPress defaults the attachment title to the filename; that is not
		// a description, so only use it when it looks like real words.
		if ( '' !== $title && ! $this->is_unhelpful( $title ) && ! preg_match( '/^[\w-]{0,4}\d+$/', $title ) ) {
			return Text::truncate( $title, 125, '' );
		}

		return '';
	}
}
