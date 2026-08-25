<?php
/**
 * Base class for checkers that walk taxonomy terms.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Walks terms in resumable batches.
 */
abstract class TermChecker extends AbstractChecker {

	/**
	 * Inspect one term.
	 *
	 * @param \WP_Term     $term    Term being checked.
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	abstract protected function check_term( \WP_Term $term, AuditContext $context ): array;

	/**
	 * Taxonomies this checker cares about.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return string[]
	 */
	protected function taxonomies( AuditContext $context ): array {
		return $context->taxonomies();
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$taxonomies = $this->taxonomies( $context );
		$batch_size = $context->batch_size();

		if ( empty( $taxonomies ) ) {
			return CheckerResult::done( array(), 0 );
		}

		$ids = ObjectIterator::term_ids( $taxonomies, $after, $batch_size );

		if ( empty( $ids ) ) {
			return CheckerResult::done( array(), 0 );
		}

		$issues       = array();
		$scanned      = 0;
		$last         = $after;
		$last_in_page = (int) end( $ids );

		foreach ( $ids as $id ) {
			$term = get_term( $id );
			$last = $id;

			if ( $term && ! is_wp_error( $term ) ) {
				foreach ( $this->check_term( $term, $context ) as $issue ) {
					$issues[] = $issue;
				}
				++$scanned;
			}

			if ( $context->out_of_time() ) {
				break;
			}
		}

		$exhausted = ( $last === $last_in_page && count( $ids ) < $batch_size );

		return new CheckerResult(
			$issues,
			$exhausted ? null : array( 'after_id' => $last ),
			$scanned
		);
	}

	/**
	 * Standard evidence block for a term-scoped issue.
	 *
	 * @param \WP_Term $term Term.
	 *
	 * @return array<string,mixed>
	 */
	protected function term_context( \WP_Term $term ): array {
		return array(
			'taxonomy' => $term->taxonomy,
			'count'    => (int) $term->count,
			'edit_url' => get_edit_term_link( $term->term_id, $term->taxonomy ),
		);
	}
}
