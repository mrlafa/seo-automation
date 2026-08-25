<?php
/**
 * SERP title checks.
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

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Flags titles that are missing, truncated in the SERP, too thin to describe
 * the page, or still containing an unresolved template variable.
 */
final class MetaTitleChecker extends PostChecker {

	/** Google truncates desktop titles at roughly this width. */
	private const MAX_PIXELS = 580;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'meta_title';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Meta titles', 'seo-automation' );
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
		return __( 'Checks every page for a title that exists, fits the search result, and describes the page.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$seo = $context->seo;

		// A page excluded from the index has no SERP title to get wrong.
		if ( $seo->is_noindex( 'post', $post->ID ) ) {
			return array();
		}

		$title    = $seo->effective_title( 'post', $post->ID );
		$override = $seo->get( SeoAdapterInterface::FIELD_TITLE, 'post', $post->ID );
		$min      = (int) $context->arg( 'title_min_length', 30 );
		$max      = (int) $context->arg( 'title_max_length', 60 );

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
			'fixer'        => 'meta_title',
		);

		$evidence = array_merge(
			$this->post_context( $post ),
			array(
				'title'        => $title,
				'has_override' => null !== $override,
				'length'       => Text::length( $title ),
				'pixel_width'  => Text::pixel_width( $title ),
			)
		);

		if ( '' === trim( $title ) ) {
			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'meta.title.missing',
							'severity'    => Issue::SEVERITY_CRITICAL,
							'title'       => __( 'No title tag', 'seo-automation' ),
							'detail'      => __( 'This page produces an empty title tag, so search engines invent one from the page content. Set an explicit title.', 'seo-automation' ),
							'evidence'    => $evidence,
							'fix_mode'    => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'field'      => 'title',
								'suggestion' => $this->suggest( $post ),
							),
						)
					)
				),
			);
		}

		// An unresolved variable means the template referenced something this
		// page does not have — it ships to Google literally.
		if ( preg_match( '/%%?[a-z_]+%%?/i', $title ) ) {
			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'meta.title.unresolved_variable',
							'severity'    => Issue::SEVERITY_HIGH,
							'title'       => __( 'Title contains an unresolved variable', 'seo-automation' ),
							/* translators: %s: the rendered title. */
							'detail'      => sprintf( __( 'The title renders as "%s". A template variable did not resolve, so the raw placeholder is published.', 'seo-automation' ), $title ),
							'evidence'    => $evidence,
							'fix_mode'    => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'field'      => 'title',
								'suggestion' => $this->suggest( $post ),
							),
						)
					)
				),
			);
		}

		$issues = array();
		$pixels = Text::pixel_width( $title );
		$length = Text::length( $title );

		if ( $pixels > self::MAX_PIXELS || $length > $max ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'meta.title.too_long',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Title will be truncated in search results', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: character count, 2: pixel width, 3: character limit. */
							__( 'The title is %1$d characters (about %2$dpx) against a practical limit of %3$d characters. Google will cut it mid-phrase.', 'seo-automation' ),
							$length,
							$pixels,
							$max
						),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'field'      => 'title',
							'suggestion' => Text::truncate( $title, $max ),
						),
					)
				)
			);
		} elseif ( $length < $min ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'meta.title.too_short',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Title is shorter than it needs to be', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: character count, 2: minimum length. */
							__( 'The title is %1$d characters against a target of at least %2$d. There is unused space to describe the page and earn the click.', 'seo-automation' ),
							$length,
							$min
						),
						'evidence'    => $evidence,
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'field'      => 'title',
							'suggestion' => $this->suggest( $post ),
						),
					)
				)
			);
		}

		// A focus keyword the title never mentions is a stated intent the page
		// does not deliver on.
		$focus = $seo->get( SeoAdapterInterface::FIELD_FOCUS_KEYWORD, 'post', $post->ID );
		if ( null !== $focus ) {
			$primary = trim( explode( ',', $focus )[0] );

			if ( '' !== $primary && ! Text::contains( $title, $primary ) ) {
				$issues[] = $this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'meta.title.missing_focus_keyword',
							'severity'    => Issue::SEVERITY_LOW,
							'title'       => __( 'Title does not contain the focus keyword', 'seo-automation' ),
							'detail'      => sprintf(
								/* translators: 1: focus keyword, 2: current title. */
								__( 'The focus keyword is "%1$s" but the title reads "%2$s".', 'seo-automation' ),
								$primary,
								$title
							),
							'evidence'    => array_merge( $evidence, array( 'focus_keyword' => $primary ) ),
							'fix_mode'    => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'field'         => 'title',
								'focus_keyword' => $primary,
							),
						)
					)
				);
			}
		}

		return $issues;
	}

	/**
	 * A starting-point title built from what the post already tells us.
	 *
	 * Deliberately mechanical — an agent with the page content in front of it
	 * will write something better, and this is what it starts from.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function suggest( \WP_Post $post ): string {
		$site  = get_bloginfo( 'name' );
		$title = trim( (string) $post->post_title );

		if ( '' === $title ) {
			$title = Text::truncate( Text::plain( Content::rendered( (string) $post->post_content ) ), 45, '' );
		}

		$candidate = '' !== $site ? $title . ' | ' . $site : $title;

		return Text::truncate( $candidate, 60 );
	}
}
