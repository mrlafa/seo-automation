<?php
/**
 * Broken link detection.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AbstractChecker;
use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\CheckerResult;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\ObjectIterator;
use SEOAgent\Support\Html;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Two-phase: collect every distinct link on the site, then verify them.
 *
 * Links are deduplicated across pages before any request is made — one broken
 * footer link would otherwise be fetched once per page on the site. Each unique
 * URL is requested at most once per audit, and the result is cached for a day
 * so consecutive audits do not re-hammer other people's servers.
 */
final class BrokenLinkChecker extends AbstractChecker {

	/** Hard ceiling on links verified per audit. */
	private const MAX_LINKS = 3000;

	/** Links verified per slice, keeping each request short. */
	private const PER_SLICE = 25;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'broken_links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Broken links', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Collects every link on the site and verifies each distinct destination once.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$phase = (string) $context->cursor( 'phase', 'collect' );

		return 'verify' === $phase
			? $this->verify( $context )
			: $this->collect( $context );
	}

	/**
	 * Phase one: build the distinct link set and remember where each came from.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function collect( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$batch_size = $context->batch_size();

		$ids = ObjectIterator::post_ids( $context->post_types(), $after, $batch_size );

		if ( empty( $ids ) ) {
			return new CheckerResult( array(), array( 'phase' => 'verify' ), 0 );
		}

		$queue = $this->read_queue( $context->audit_id );

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

			foreach ( Html::links( (string) $post->post_content ) as $link ) {
				$href = trim( $link['href'] );

				if ( '' === $href || $this->is_non_http( $href ) || 0 === strpos( $href, '#' ) ) {
					continue;
				}

				$absolute = $this->absolutise( $href );

				if ( '' === $absolute ) {
					continue;
				}

				if ( ! isset( $queue[ $absolute ] ) ) {
					if ( count( $queue ) >= self::MAX_LINKS ) {
						continue;
					}

					$queue[ $absolute ] = array(
						'sources' => array(),
						'anchor'  => $link['text'],
					);
				}

				// Keep a handful of sources: enough to fix the link everywhere
				// it appears without storing the whole site in a transient.
				if ( count( $queue[ $absolute ]['sources'] ) < 10 ) {
					$queue[ $absolute ]['sources'][ (string) $post->ID ] = (string) $post->post_title;
				}
			}

			if ( $context->out_of_time() ) {
				break;
			}
		}

		$this->write_queue( $context->audit_id, $queue );

		if ( $last === $last_in_page && count( $ids ) < $batch_size ) {
			return new CheckerResult( array(), array( 'phase' => 'verify' ), $scanned );
		}

		return new CheckerResult(
			array(),
			array(
				'phase'    => 'collect',
				'after_id' => $last,
			),
			$scanned
		);
	}

	/**
	 * Phase two: request each distinct URL once.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function verify( AuditContext $context ): CheckerResult {
		$queue = $this->read_queue( $context->audit_id );

		if ( empty( $queue ) ) {
			$this->clear_queue( $context->audit_id );

			return CheckerResult::done( array(), 0 );
		}

		$issues  = array();
		$checked = 0;

		foreach ( array_slice( $queue, 0, self::PER_SLICE, true ) as $url => $meta ) {
			unset( $queue[ $url ] );
			++$checked;

			$result = $context->fetcher->check( $url, DAY_IN_SECONDS );
			$status = (int) $result['status'];

			// 401/403 usually mean "bot blocked", not "link broken" — reporting
			// them as broken produces noise nobody can act on.
			if ( 0 !== $status && $status < 400 ) {
				continue;
			}
			if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
				continue;
			}

			$internal = $this->is_internal( $url );
			$sources  = (array) ( $meta['sources'] ?? array() );

			$issues[] = $this->issue(
				array(
					'code'         => 0 === $status ? 'link.unreachable' : 'link.broken',
					'severity'     => $internal ? Issue::SEVERITY_HIGH : Issue::SEVERITY_MEDIUM,
					'object_type'  => 'url',
					'object_id'    => (int) array_key_first( $sources ) ?: 0,
					'object_label' => $url,
					'url'          => $url,
					'key'          => md5( $url ),
					'title'        => $internal
						? __( 'Internal link is broken', 'seo-automation' )
						: __( 'External link is broken', 'seo-automation' ),
					'detail'       => sprintf(
						/* translators: 1: URL, 2: status or error, 3: number of pages. */
						__( '%1$s returned %2$s. It is linked from %3$d page(s).', 'seo-automation' ),
						$url,
						0 === $status ? ( $result['error'] ?: __( 'no response', 'seo-automation' ) ) : (string) $status,
						count( $sources )
					),
					'evidence'     => array(
						'status'         => $status,
						'error'          => $result['error'],
						'method'         => $result['method'],
						'anchor'         => $meta['anchor'] ?? '',
						'internal'       => $internal,
						'sources'        => $sources,
						'affected_count' => count( $sources ),
					),
					'fixer'        => 'broken_link',
					'fix_mode'     => Issue::MODE_ASSISTED,
					'fix_payload'  => array(
						'url'      => $url,
						'post_ids' => array_map( 'intval', array_keys( $sources ) ),
					),
				)
			);

			if ( $context->out_of_time() ) {
				break;
			}
		}

		if ( empty( $queue ) ) {
			$this->clear_queue( $context->audit_id );

			return new CheckerResult( $issues, null, $checked );
		}

		$this->write_queue( $context->audit_id, $queue );

		return new CheckerResult( $issues, array( 'phase' => 'verify' ), $checked );
	}

	/**
	 * Resolve a possibly-relative href against the site root.
	 *
	 * @param string $href Link href.
	 */
	private function absolutise( string $href ): string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );

			return $scheme . ':' . $href;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return home_url( $href );
		}

		// Anything else (a bare relative path) resolves against the page it
		// appeared on, which we cannot reconstruct reliably enough to check.
		return '';
	}

	/**
	 * The pending link queue.
	 *
	 * @param int $audit_id Audit ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function read_queue( int $audit_id ): array {
		$queue = get_transient( $this->queue_key( $audit_id ) );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Persist the pending link queue.
	 *
	 * @param int                              $audit_id Audit ID.
	 * @param array<string,array<string,mixed>> $queue   Queue.
	 */
	private function write_queue( int $audit_id, array $queue ): void {
		set_transient( $this->queue_key( $audit_id ), $queue, DAY_IN_SECONDS );
	}

	/**
	 * Drop the queue.
	 *
	 * @param int $audit_id Audit ID.
	 */
	private function clear_queue( int $audit_id ): void {
		delete_transient( $this->queue_key( $audit_id ) );
	}

	/**
	 * Transient key for one audit's link queue.
	 *
	 * @param int $audit_id Audit ID.
	 */
	private function queue_key( int $audit_id ): string {
		return 'seo_agent_linkqueue_' . $audit_id;
	}
}
