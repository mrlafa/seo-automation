<?php
/**
 * Heading structure checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Content;
use SEOAgent\Support\Html;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Checks the H1-H6 outline of each page.
 *
 * Note on H1 detection: most themes render the post title as the H1 outside
 * post_content, so an absent H1 in the content is normal and is not reported.
 * What is reported is the opposite — extra H1s inside the content, which
 * genuinely produce a page with several competing top-level headings.
 */
final class HeadingChecker extends PostChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'headings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Heading structure', 'seo-automation' );
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
		return __( 'Checks that each page has one clear top-level heading and a sensible outline beneath it.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$rendered = Content::rendered( (string) $post->post_content );
		$headings = Html::headings( $rendered );

		if ( empty( $headings ) ) {
			// A page with no subheadings at all is only worth mentioning when
			// there is enough content to warrant structure.
			if ( str_word_count( wp_strip_all_tags( $rendered ) ) < 600 ) {
				return array();
			}

			return array(
				$this->issue(
					array(
						'code'         => 'headings.none',
						'severity'     => Issue::SEVERITY_LOW,
						'object_type'  => 'post',
						'object_id'    => $post->ID,
						'object_label' => $post->post_title,
						'url'          => (string) get_permalink( $post ),
						'title'        => __( 'Long page with no subheadings', 'seo-automation' ),
						'detail'       => __( 'This page runs past 600 words with no headings. Readers cannot scan it and search engines have no section signals to work with.', 'seo-automation' ),
						'evidence'     => $this->post_context( $post ),
						'fix_mode'     => Issue::MODE_MANUAL,
					)
				),
			);
		}

		$issues = array();
		$base   = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
		);

		$h1s = array_values( array_filter( $headings, static fn( $heading ) => 1 === $heading['level'] ) );

		if ( count( $h1s ) > 1 ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'headings.multiple_h1',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Several H1 headings on one page', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %d: number of H1 headings. */
							__( 'The content contains %d H1 headings, on top of whatever the theme renders for the page title. Demote all but the first to H2.', 'seo-automation' ),
							count( $h1s )
						),
						'evidence' => array_merge(
							$this->post_context( $post ),
							array(
								'h1_texts'       => wp_list_pluck( $h1s, 'text' ),
								'affected_count' => count( $h1s ),
							)
						),
						'fixer'    => 'heading_level',
						'fix_mode' => Issue::MODE_AUTO,
						'fix_payload' => array(
							// Keep the first H1, demote the rest.
							'demote' => array_map(
								static fn( $heading ) => $heading['raw'],
								array_slice( $h1s, 1 )
							),
						),
					)
				)
			);
		}

		// A jump from H2 straight to H4 breaks the document outline for screen
		// readers and for anything parsing the page into sections.
		$previous = 0;
		$skips    = array();

		foreach ( $headings as $heading ) {
			if ( $previous > 0 && $heading['level'] > $previous + 1 ) {
				$skips[] = array(
					'from' => $previous,
					'to'   => $heading['level'],
					'text' => $heading['text'],
				);
			}

			$previous = $heading['level'];
		}

		if ( ! empty( $skips ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'headings.skipped_level',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Heading levels skip a step', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: 1: from level, 2: to level, 3: heading text. */
							__( 'The outline jumps from H%1$d to H%2$d at "%3$s". Use the next level down so the page structure stays parseable.', 'seo-automation' ),
							$skips[0]['from'],
							$skips[0]['to'],
							$skips[0]['text']
						),
						'evidence' => array_merge(
							$this->post_context( $post ),
							array(
								'skips'   => $skips,
								'outline' => array_map(
									static fn( $heading ) => array(
										'level' => $heading['level'],
										'text'  => $heading['text'],
									),
									array_slice( $headings, 0, 40 )
								),
							)
						),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		$empty = array_filter( $headings, static fn( $heading ) => '' === trim( $heading['text'] ) );

		if ( ! empty( $empty ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'headings.empty',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Empty heading tags', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %d: number of empty headings. */
							__( '%d heading tags contain no text, usually left behind by a page builder. They add noise to the outline.', 'seo-automation' ),
							count( $empty )
						),
						'evidence' => array_merge(
							$this->post_context( $post ),
							array( 'affected_count' => count( $empty ) )
						),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return $issues;
	}
}
