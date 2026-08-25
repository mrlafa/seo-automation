<?php
/**
 * Base class for checkers that walk posts.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks published posts in resumable batches.
 */
abstract class PostChecker extends AbstractChecker {

	/**
	 * Inspect one post.
	 *
	 * @param \WP_Post     $post    Post being checked.
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	abstract protected function check_post( \WP_Post $post, AuditContext $context ): array;

	/**
	 * Post types this checker cares about.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return string[]
	 */
	protected function post_types( AuditContext $context ): array {
		return $context->post_types();
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$post_types = $this->post_types( $context );
		$batch_size = $context->batch_size();

		if ( empty( $post_types ) ) {
			return CheckerResult::done( array(), 0 );
		}

		$ids = ObjectIterator::post_ids( $post_types, $after, $batch_size );

		if ( empty( $ids ) ) {
			return CheckerResult::done( array(), 0 );
		}

		$issues     = array();
		$scanned    = 0;
		$last       = $after;
		$last_in_page = (int) end( $ids );
		$exhausted  = false;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$last = $id;

			if ( $post ) {
				foreach ( $this->check_post( $post, $context ) as $issue ) {
					$issues[] = $issue;
				}
				++$scanned;
			}

			if ( $context->out_of_time() ) {
				break;
			}
		}

		// Finished only if we consumed the whole page AND the page was short,
		// which means there is nothing past it.
		if ( $last === $last_in_page && count( $ids ) < $batch_size ) {
			$exhausted = true;
		}

		return new CheckerResult(
			$issues,
			$exhausted ? null : array( 'after_id' => $last ),
			$scanned
		);
	}

	/**
	 * Standard evidence block for a post-scoped issue.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return array<string,mixed>
	 */
	protected function post_context( \WP_Post $post ): array {
		return array(
			'post_type'     => $post->post_type,
			'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
			'is_front_page' => (int) get_option( 'page_on_front' ) === (int) $post->ID,
		);
	}
}
