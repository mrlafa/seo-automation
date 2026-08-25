<?php
/**
 * Taxonomy archive SEO checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\TermChecker;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category and product-category archives are frequently the highest-intent
 * pages on a store and the least maintained. This checks the metadata, the
 * on-page copy and whether the archive is worth indexing at all.
 */
final class CategorySeoChecker extends TermChecker {

	/** Below this many items an archive rarely deserves its own index entry. */
	private const MIN_ITEMS = 2;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'category_seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Category and archive SEO', 'seo-automation' );
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
		return __( 'Checks category and product-category archives for titles, descriptions, on-page copy and index-worthiness.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_term( \WP_Term $term, AuditContext $context ): array {
		$seo = $context->seo;

		$base = array(
			'object_type'  => 'term',
			'object_id'    => $term->term_id,
			'object_label' => $term->name,
			'url'          => (string) get_term_link( $term ),
		);

		$evidence = $this->term_context( $term );
		$issues   = array();

		// An empty archive is a soft 404 whichever way you look at it.
		if ( 0 === (int) $term->count ) {
			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'        => 'category.empty',
							'severity'    => Issue::SEVERITY_MEDIUM,
							'title'       => __( 'Empty archive is indexable', 'seo-automation' ),
							/* translators: %s: term name. */
							'detail'      => sprintf( __( 'The "%s" archive contains nothing. An indexable empty listing is a soft 404 — either populate it, merge it, or exclude it from the index.', 'seo-automation' ), $term->name ),
							'evidence'    => $evidence,
							'fixer'       => 'term_robots',
							'fix_mode'    => Issue::MODE_AUTO,
							'fix_payload' => array(
								'term_id' => $term->term_id,
								'robots'  => 'noindex,follow',
							),
						)
					)
				),
			);
		}

		if ( $seo->is_noindex( 'term', $term->term_id ) ) {
			return array();
		}

		$title       = $seo->effective_title( 'term', $term->term_id );
		$description = $seo->effective_description( 'term', $term->term_id );
		$copy        = Text::plain( (string) $term->description );

		$evidence['title']       = $title;
		$evidence['description'] = $description;
		$evidence['copy_words']  = Text::word_count( (string) $term->description );

		if ( '' === trim( $description ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'category.description.missing',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Archive has no meta description', 'seo-automation' ),
						/* translators: %s: term name. */
						'detail'      => sprintf( __( 'The "%s" archive has no description, so its search snippet is whatever Google assembles from the listing.', 'seo-automation' ), $term->name ),
						'evidence'    => $evidence,
						'fixer'       => 'term_meta',
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'term_id'    => $term->term_id,
							'field'      => 'description',
							'suggestion' => '' !== $copy ? Text::truncate( $copy, 155, '' ) : '',
						),
					)
				)
			);
		}

		if ( '' === trim( $title ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'category.title.missing',
						'severity'    => Issue::SEVERITY_HIGH,
						'title'       => __( 'Archive has no title tag', 'seo-automation' ),
						'detail'      => __( 'This archive produces an empty title tag.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fixer'       => 'term_meta',
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array(
							'term_id'    => $term->term_id,
							'field'      => 'title',
							'suggestion' => Text::truncate( $term->name . ' | ' . get_bloginfo( 'name' ), 60 ),
						),
					)
				)
			);
		}

		// A category page with no copy of its own is a bare list of links —
		// nothing for a search engine to judge relevance on.
		if ( '' === trim( $copy ) && (int) $term->count >= self::MIN_ITEMS ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'category.copy.missing',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Archive has no introductory copy', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: term name, 2: item count. */
							__( 'The "%1$s" archive lists %2$d items but has no description text of its own. Category pages compete for high-intent queries and need copy to do it.', 'seo-automation' ),
							$term->name,
							(int) $term->count
						),
						'evidence'    => $evidence,
						'fixer'       => 'term_copy',
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array( 'term_id' => $term->term_id ),
					)
				)
			);
		}

		if ( (int) $term->count === 1 ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'category.single_item',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Archive contains a single item', 'seo-automation' ),
						/* translators: %s: term name. */
						'detail'   => sprintf( __( 'The "%s" archive holds one item, so it duplicates that item\'s page with none of its detail. Consider merging it into a broader category.', 'seo-automation' ), $term->name ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		// Tag-style taxonomies with one-off terms generate thin archives at scale.
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) && (int) $term->count <= 2 ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'category.thin_tag',
						'severity'    => Issue::SEVERITY_LOW,
						'title'       => __( 'Thin tag archive', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: term name, 2: count. */
							__( 'The tag "%1$s" covers only %2$d items. Tags used once or twice create indexable pages with nothing on them.', 'seo-automation' ),
							$term->name,
							(int) $term->count
						),
						'evidence'    => $evidence,
						'fixer'       => 'term_robots',
						'fix_mode'    => Issue::MODE_AUTO,
						'fix_payload' => array(
							'term_id' => $term->term_id,
							'robots'  => 'noindex,follow',
						),
					)
				)
			);
		}

		// Focus keyword stated but not reflected in the title.
		$focus = $seo->get( SeoAdapterInterface::FIELD_FOCUS_KEYWORD, 'term', $term->term_id );
		if ( null !== $focus ) {
			$primary = trim( explode( ',', $focus )[0] );

			if ( '' !== $primary && '' !== $title && ! Text::contains( $title, $primary ) ) {
				$issues[] = $this->issue(
					array_merge(
						$base,
						array(
							'code'     => 'category.title.missing_focus_keyword',
							'severity' => Issue::SEVERITY_LOW,
							'title'    => __( 'Archive title does not contain its focus keyword', 'seo-automation' ),
							'detail'   => sprintf(
								/* translators: 1: keyword, 2: title. */
								__( 'The focus keyword is "%1$s" but the archive title reads "%2$s".', 'seo-automation' ),
								$primary,
								$title
							),
							'evidence' => array_merge( $evidence, array( 'focus_keyword' => $primary ) ),
							'fixer'    => 'term_meta',
							'fix_mode' => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'term_id' => $term->term_id,
								'field'   => 'title',
							),
						)
					)
				);
			}
		}

		return $issues;
	}
}
