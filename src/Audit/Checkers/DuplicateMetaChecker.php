<?php
/**
 * Cross-page duplicate title and description detection.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\SiteChecker;
use SEOAgent\Seo\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds titles and descriptions shared by more than one page.
 *
 * Runs as grouped SQL rather than per-post comparison: on a catalogue with
 * tens of thousands of products, pairwise comparison is not an option.
 */
final class DuplicateMetaChecker extends SiteChecker {

	/** Cap on reported groups, so one templating mistake cannot flood the queue. */
	private const MAX_GROUPS = 50;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'duplicate_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Duplicate titles and descriptions', 'seo-automation' );
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
		return __( 'Finds pages competing with each other by sharing the same title or description.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_site( AuditContext $context ): array {
		$issues = array();

		$title_key = $context->seo->meta_key( SeoAdapterInterface::FIELD_TITLE, 'post' );
		$desc_key  = $context->seo->meta_key( SeoAdapterInterface::FIELD_DESCRIPTION, 'post' );

		if ( '' !== $title_key ) {
			$issues = array_merge(
				$issues,
				$this->duplicate_meta_issues(
					$title_key,
					$context,
					'meta.title.duplicate',
					Issue::SEVERITY_HIGH,
					__( 'Duplicate title across pages', 'seo-automation' ),
					'meta_title'
				)
			);
		}

		if ( '' !== $desc_key ) {
			$issues = array_merge(
				$issues,
				$this->duplicate_meta_issues(
					$desc_key,
					$context,
					'meta.description.duplicate',
					Issue::SEVERITY_MEDIUM,
					__( 'Duplicate description across pages', 'seo-automation' ),
					'meta_description'
				)
			);
		}

		return array_merge( $issues, $this->duplicate_post_titles( $context ) );
	}

	/**
	 * Group a meta key by value and report every value used more than once.
	 *
	 * @param string       $meta_key Meta key to group.
	 * @param AuditContext $context  Run state.
	 * @param string       $code     Issue code.
	 * @param string       $severity Issue severity.
	 * @param string       $title    Issue title.
	 * @param string       $fixer    Fixer slug.
	 *
	 * @return Issue[]
	 */
	private function duplicate_meta_issues(
		string $meta_key,
		AuditContext $context,
		string $code,
		string $severity,
		string $title,
		string $fixer
	): array {
		global $wpdb;

		$post_types   = $context->post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( array( $meta_key ), $post_types, array( self::MAX_GROUPS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS value,
				        COUNT(*) AS total,
				        GROUP_CONCAT(pm.post_id ORDER BY pm.post_id ASC SEPARATOR ',') AS ids
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value <> ''
				   AND p.post_status = 'publish'
				   AND p.post_type IN ({$placeholders})
				 GROUP BY pm.meta_value
				 HAVING COUNT(*) > 1
				 ORDER BY total DESC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$issues = array();

		foreach ( $rows as $row ) {
			$ids   = array_map( 'intval', explode( ',', (string) $row['ids'] ) );
			$total = (int) $row['total'];

			$issues[] = $this->issue(
				array(
					'code'         => $code,
					'severity'     => $severity,
					'object_type'  => 'site',
					'object_id'    => 0,
					'key'          => md5( $code . '|' . $row['value'] ),
					'object_label' => (string) $row['value'],
					'title'        => $title,
					'detail'       => sprintf(
						/* translators: 1: number of pages, 2: the duplicated value. */
						__( '%1$d pages share the value "%2$s". They compete for the same result and Google picks one arbitrarily.', 'seo-automation' ),
						$total,
						$row['value']
					),
					'evidence'     => array(
						'value'          => (string) $row['value'],
						'post_ids'       => array_slice( $ids, 0, 50 ),
						'affected_count' => $total,
						'pages'          => $this->describe_posts( array_slice( $ids, 0, 10 ) ),
					),
					'fixer'        => $fixer,
					'fix_mode'     => Issue::MODE_ASSISTED,
					'fix_payload'  => array(
						'post_ids' => array_slice( $ids, 0, 50 ),
						'field'    => 'meta_title' === $fixer ? 'title' : 'description',
					),
				)
			);
		}

		return $issues;
	}

	/**
	 * Published posts sharing an identical post title.
	 *
	 * On a WooCommerce store this is usually a variant naming problem, and it
	 * is the single most common source of self-competition in a catalogue.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function duplicate_post_titles( AuditContext $context ): array {
		global $wpdb;

		$post_types   = $context->post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( $post_types, array( self::MAX_GROUPS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names are compile-time constants or come from $wpdb->prefix; IN() placeholders are generated from a count, never from user input; every value is passed through $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_title AS value,
				        COUNT(*) AS total,
				        GROUP_CONCAT(ID ORDER BY ID ASC SEPARATOR ',') AS ids
				 FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ({$placeholders})
				   AND post_title <> ''
				 GROUP BY post_title
				 HAVING COUNT(*) > 1
				 ORDER BY total DESC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$issues = array();

		foreach ( $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', (string) $row['ids'] ) );

			$issues[] = $this->issue(
				array(
					'code'         => 'content.duplicate_post_title',
					'severity'     => Issue::SEVERITY_MEDIUM,
					'object_type'  => 'site',
					'object_id'    => 0,
					'key'          => md5( 'post_title|' . $row['value'] ),
					'object_label' => (string) $row['value'],
					'title'        => __( 'Several published pages share the same name', 'seo-automation' ),
					'detail'       => sprintf(
						/* translators: 1: count, 2: shared title. */
						__( '%1$d published items are all called "%2$s". Unless their titles are differentiated they cannibalise each other.', 'seo-automation' ),
						(int) $row['total'],
						$row['value']
					),
					'evidence'     => array(
						'value'          => (string) $row['value'],
						'post_ids'       => array_slice( $ids, 0, 50 ),
						'affected_count' => (int) $row['total'],
						'pages'          => $this->describe_posts( array_slice( $ids, 0, 10 ) ),
					),
					'fixer'        => 'meta_title',
					'fix_mode'     => Issue::MODE_ASSISTED,
					'fix_payload'  => array(
						'post_ids' => array_slice( $ids, 0, 50 ),
						'field'    => 'title',
					),
				)
			);
		}

		return $issues;
	}

	/**
	 * Compact descriptions of the affected posts, for the agent's benefit.
	 *
	 * @param int[] $ids Post IDs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function describe_posts( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
				'type'  => (string) $post->post_type,
				'url'   => (string) get_permalink( $post ),
			);
		}

		return $out;
	}
}
