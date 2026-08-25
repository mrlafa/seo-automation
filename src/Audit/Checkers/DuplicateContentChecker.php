<?php
/**
 * Near-duplicate body content detection.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AbstractChecker;
use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\CheckerResult;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\ObjectIterator;
use SEOAgent\Support\Content;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Finds pages whose body content is substantially the same as another page's.
 *
 * Comparing every page against every other is quadratic and unusable past a
 * few thousand posts. Instead each page gets a 64-bit simhash, and only pages
 * sharing a 16-bit band of that hash are compared in full — near-duplicates
 * collide in at least one band with high probability, so the expensive
 * comparison runs on a tiny fraction of the pairs.
 */
final class DuplicateContentChecker extends AbstractChecker {

	private const HASH_META_KEY    = '_seo_agent_simhash';
	private const SOURCE_META_KEY  = '_seo_agent_simhash_source';
	private const MAX_REPORTED     = 100;
	private const MIN_WORDS        = 100;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'duplicate_content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Duplicate content', 'seo-automation' );
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
		return __( 'Finds pages whose body content substantially repeats another page, the usual cause being shared product or template copy.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$phase = (string) $context->cursor( 'phase', 'hash' );

		return 'compare' === $phase
			? $this->compare( $context )
			: $this->hash( $context );
	}

	/**
	 * Phase one: fingerprint each page, skipping content that has not changed
	 * since the last audit.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function hash( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$batch_size = $context->batch_size();

		$ids = ObjectIterator::post_ids( $context->post_types(), $after, $batch_size );

		if ( empty( $ids ) ) {
			return new CheckerResult( array(), array( 'phase' => 'compare' ), 0 );
		}

		$scanned      = 0;
		$last         = $after;
		$last_in_page = (int) end( $ids );

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$last = $id;

			if ( ! $post ) {
				continue;
			}

			++$scanned;

			$raw = (string) $post->post_content;

			// The change-detection key stays on the raw value — it only needs
			// to answer "has this post been edited since the last audit", not
			// to reflect what the page looks like rendered.
			$source = md5( $raw );

			if ( (string) get_post_meta( $id, self::SOURCE_META_KEY, true ) === $source ) {
				continue;
			}

			// The similarity signal itself needs the rendered form, or every
			// block-builder page collapses to the same near-empty fingerprint
			// and either floods the report with false duplicates or, below
			// MIN_WORDS, is skipped from comparison entirely.
			$rendered = Content::rendered( $raw );

			if ( Text::word_count( $rendered ) < self::MIN_WORDS ) {
				delete_post_meta( $id, self::HASH_META_KEY );
				update_post_meta( $id, self::SOURCE_META_KEY, $source );
				continue;
			}

			update_post_meta( $id, self::HASH_META_KEY, Text::simhash( $rendered ) );
			update_post_meta( $id, self::SOURCE_META_KEY, $source );

			if ( $context->out_of_time() ) {
				break;
			}
		}

		if ( $last === $last_in_page && count( $ids ) < $batch_size ) {
			return new CheckerResult( array(), array( 'phase' => 'compare' ), $scanned );
		}

		return new CheckerResult(
			array(),
			array(
				'phase'    => 'hash',
				'after_id' => $last,
			),
			$scanned
		);
	}

	/**
	 * Phase two: band the fingerprints and compare within buckets.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function compare( AuditContext $context ): CheckerResult {
		global $wpdb;

		$post_types   = $context->post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated, values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS hash, p.post_title, p.post_type
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_status = 'publish'
				   AND p.post_type IN ({$placeholders})",
				array_merge( array( self::HASH_META_KEY ), $post_types )
			),
			ARRAY_A
		) ?: array();

		if ( count( $rows ) < 2 ) {
			return CheckerResult::done( array(), count( $rows ) );
		}

		$threshold = (float) $context->arg( 'duplicate_threshold', 0.85 );
		$buckets   = array();

		foreach ( $rows as $row ) {
			$hash = (string) $row['hash'];

			if ( 16 !== strlen( $hash ) ) {
				continue;
			}

			// Four 16-bit bands: identical content collides in all four, and
			// near-identical content collides in at least one.
			for ( $band = 0; $band < 4; $band++ ) {
				$key = $band . ':' . substr( $hash, $band * 4, 4 );

				$buckets[ $key ][] = $row;
			}
		}

		$seen   = array();
		$issues = array();

		foreach ( $buckets as $bucket ) {
			if ( count( $bucket ) < 2 || count( $bucket ) > 200 ) {
				// A bucket of hundreds means a near-uniform template; reporting
				// every pair inside it would bury everything else.
				continue;
			}

			$count = count( $bucket );

			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a = $bucket[ $i ];
					$b = $bucket[ $j ];

					$pair = min( (int) $a['post_id'], (int) $b['post_id'] ) . '-' . max( (int) $a['post_id'], (int) $b['post_id'] );

					if ( isset( $seen[ $pair ] ) ) {
						continue;
					}
					$seen[ $pair ] = true;

					$similarity = Text::simhash_similarity( (string) $a['hash'], (string) $b['hash'] );

					if ( $similarity < $threshold ) {
						continue;
					}

					$issues[] = $this->build_issue( $a, $b, $similarity );

					if ( count( $issues ) >= self::MAX_REPORTED ) {
						return CheckerResult::done(
							$issues,
							count( $rows ),
							array( __( 'Reporting stopped at 100 duplicate pairs.', 'seo-automation' ) )
						);
					}
				}
			}

			if ( $context->out_of_time() ) {
				break;
			}
		}

		return CheckerResult::done( $issues, count( $rows ) );
	}

	/**
	 * Build the issue for one near-duplicate pair.
	 *
	 * @param array<string,mixed> $a          First page row.
	 * @param array<string,mixed> $b          Second page row.
	 * @param float               $similarity 0..1 similarity.
	 */
	private function build_issue( array $a, array $b, float $similarity ): Issue {
		$a_id = (int) $a['post_id'];
		$b_id = (int) $b['post_id'];

		$percent = (int) round( $similarity * 100 );

		return $this->issue(
			array(
				'code'         => 'content.near_duplicate',
				'severity'     => $similarity > 0.95 ? Issue::SEVERITY_HIGH : Issue::SEVERITY_MEDIUM,
				'object_type'  => 'post',
				'object_id'    => $a_id,
				'key'          => (string) $b_id,
				'object_label' => (string) $a['post_title'],
				'url'          => (string) get_permalink( $a_id ),
				'title'        => __( 'Two pages have near-identical content', 'seo-automation' ),
				'detail'       => sprintf(
					/* translators: 1: similarity percentage, 2: first page title, 3: second page title. */
					__( '"%2$s" and "%3$s" are %1$d%% the same. Either differentiate them, consolidate them, or canonicalise one to the other.', 'seo-automation' ),
					$percent,
					(string) $a['post_title'],
					(string) $b['post_title']
				),
				'evidence'     => array(
					'similarity' => $percent,
					'pages'      => array(
						array(
							'id'    => $a_id,
							'title' => (string) $a['post_title'],
							'type'  => (string) $a['post_type'],
							'url'   => (string) get_permalink( $a_id ),
						),
						array(
							'id'    => $b_id,
							'title' => (string) $b['post_title'],
							'type'  => (string) $b['post_type'],
							'url'   => (string) get_permalink( $b_id ),
						),
					),
				),
				'fixer'        => 'canonical',
				'fix_mode'     => Issue::MODE_ASSISTED,
				'fix_payload'  => array(
					'post_id'          => $b_id,
					'canonical_target' => (string) get_permalink( $a_id ),
				),
			)
		);
	}
}
