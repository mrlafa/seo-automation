<?php
/**
 * Meta description checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Content;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags pages with no description, or one that is truncated, thin, or
 * duplicated verbatim from the opening sentence of the content.
 */
final class MetaDescriptionChecker extends PostChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'meta_description';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Meta descriptions', 'seo-automation' );
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
		return __( 'Checks that every indexable page has a description that fits the search snippet and earns the click.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$seo = $context->seo;

		if ( $seo->is_noindex( 'post', $post->ID ) ) {
			return array();
		}

		$description = $seo->effective_description( 'post', $post->ID );
		$min         = (int) $context->arg( 'description_min_length', 70 );
		$max         = (int) $context->arg( 'description_max_length', 155 );
		$length      = Text::length( $description );

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
			'fixer'        => 'meta_description',
		);

		$evidence = array_merge(
			$this->post_context( $post ),
			array(
				'description' => $description,
				'length'      => $length,
			)
		);

		if ( '' === trim( $description ) ) {
			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'meta.description.missing',
							'severity'    => Issue::SEVERITY_HIGH,
							'title'       => __( 'No meta description', 'seo-automation' ),
							'detail'      => __( 'Google will pull an arbitrary sentence from the page for the snippet. Writing one puts the pitch under your control.', 'seo-automation' ),
							'evidence'    => $evidence,
							'fix_mode'    => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'field'      => 'description',
								'suggestion' => $this->suggest( $post, $max ),
							),
						)
					)
				),
			);
		}

		if ( preg_match( '/%%?[a-z_]+%%?/i', $description ) ) {
			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'meta.description.unresolved_variable',
							'severity'    => Issue::SEVERITY_HIGH,
							'title'       => __( 'Description contains an unresolved variable', 'seo-automation' ),
							/* translators: %s: the rendered description. */
							'detail'      => sprintf( __( 'The description renders as "%s", publishing the raw placeholder.', 'seo-automation' ), $description ),
							'evidence'    => $evidence,
							'fix_mode'    => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'field'      => 'description',
								'suggestion' => $this->suggest( $post, $max ),
							),
						)
					)
				),
			);
		}

		$issues = array();

		if ( $length > $max ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'meta.description.too_long',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Description will be truncated', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: character count, 2: limit. */
							__( 'The description is %1$d characters against a practical limit of %2$d, so the end will be cut off.', 'seo-automation' ),
							$length,
							$max
						),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'field'      => 'description',
							'suggestion' => Text::truncate( $description, $max ),
						),
					)
				)
			);
		} elseif ( $length < $min ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'meta.description.too_short',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Description is too short to be persuasive', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: character count, 2: minimum. */
							__( 'The description is %1$d characters against a target of at least %2$d.', 'seo-automation' ),
							$length,
							$min
						),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'field'      => 'description',
							'suggestion' => $this->suggest( $post, $max ),
						),
					)
				)
			);
		}

		// A description copied verbatim from the opening of the page adds
		// nothing over what Google would have generated anyway.
		$opening = Text::truncate( Text::plain( Content::rendered( (string) $post->post_content ) ), $length + 5, '' );
		if ( '' !== $opening && 0 === strcasecmp( trim( $opening ), trim( $description ) ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'meta.description.duplicates_content',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Description is copied from the first lines of the page', 'seo-automation' ),
						'detail'      => __( 'The description repeats the opening sentence verbatim. Write a distinct summary that gives a reason to click.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array( 'field' => 'description' ),
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * A serviceable description drawn from the excerpt or opening content.
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $max  Maximum length.
	 */
	private function suggest( \WP_Post $post, int $max ): string {
		$source = trim( (string) $post->post_excerpt );

		if ( '' === $source ) {
			$source = Content::rendered( (string) $post->post_content );
		}

		$plain = Text::plain( $source );

		return '' === $plain ? '' : Text::truncate( $plain, $max, '' );
	}
}
