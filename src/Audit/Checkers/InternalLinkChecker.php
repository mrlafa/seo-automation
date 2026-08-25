<?php
/**
 * Internal linking checks, including orphan detection.
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
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-phase checker: first walk every page recording its outbound internal
 * links, then walk again to report the pages nothing links to.
 *
 * Orphan detection needs the whole link graph, so it cannot be done in a
 * single streaming pass. The graph is keyed on slug rather than resolved post
 * ID because resolving every href through url_to_postid() would mean a
 * database round trip per link — hundreds of thousands of queries on a large
 * site, to answer a question a string comparison answers just as well.
 */
final class InternalLinkChecker extends AbstractChecker {

	/** Anchor text that tells a reader (and a crawler) nothing. */
	private const GENERIC_ANCHORS = array(
		'click here', 'here', 'read more', 'more', 'this', 'link', 'this page',
		'learn more', 'continue reading', 'download', 'see more', 'find out more',
		'click', 'go', 'view', 'link here', 'this link', 'website',
	);

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'internal_links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Internal linking', 'seo-automation' );
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
		return __( 'Maps the internal link graph to find orphaned pages, under-linked content and uninformative anchor text.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$phase = (string) $context->cursor( 'phase', 'scan' );

		return 'orphans' === $phase
			? $this->run_orphan_phase( $context )
			: $this->run_scan_phase( $context );
	}

	/**
	 * Phase one: inspect outbound links and build the inbound index.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function run_scan_phase( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$batch_size = $context->batch_size();
		$post_types = $context->post_types();

		$ids = ObjectIterator::post_ids( $post_types, $after, $batch_size );

		if ( empty( $ids ) ) {
			// Nothing left to scan; move on to reporting orphans.
			return new CheckerResult(
				array(),
				array(
					'phase'    => 'orphans',
					'after_id' => 0,
					'menu_ids' => $this->menu_object_ids(),
				),
				0
			);
		}

		$issues       = array();
		$scanned      = 0;
		$last         = $after;
		$last_in_page = (int) end( $ids );
		$inbound      = array();
		$min_links    = (int) $context->arg( 'min_internal_links', 3 );

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$last = $id;

			if ( ! $post ) {
				continue;
			}

			++$scanned;

			$links    = Html::links( $this->linkable_content( $post ) );
			$internal = 0;
			$generic  = array();

			foreach ( $links as $link ) {
				$href = trim( $link['href'] );

				if ( '' === $href || $this->is_non_http( $href ) || 0 === strpos( $href, '#' ) ) {
					continue;
				}

				if ( ! $this->is_internal( $href ) ) {
					continue;
				}

				$slug = $this->slug_from_url( $href );

				// A page linking to itself is not an inbound link for orphan purposes.
				if ( '' !== $slug && $slug !== $post->post_name ) {
					++$internal;
					$inbound[ $slug ] = ( $inbound[ $slug ] ?? 0 ) + 1;
				}

				$anchor = strtolower( trim( $link['text'] ) );
				if ( '' !== $anchor && in_array( $anchor, self::GENERIC_ANCHORS, true ) ) {
					$generic[] = array(
						'anchor' => $link['text'],
						'href'   => $href,
					);
				}
			}

			$base = array(
				'object_type'  => 'post',
				'object_id'    => $post->ID,
				'object_label' => $post->post_title,
				'url'          => (string) get_permalink( $post ),
			);

			$words = Text::word_count( (string) $post->post_content );

			if ( $words >= 300 && $internal < $min_links ) {
				$issues[] = $this->issue(
					array_merge(
						$base,
						array(
							'code'     => 'link.internal.too_few',
							'severity' => Issue::SEVERITY_MEDIUM,
							'title'    => __( 'Page barely links anywhere else', 'seo-automation' ),
							'detail'   => sprintf(
								/* translators: 1: link count, 2: target, 3: word count. */
								__( 'This page has %1$d internal links against a target of %2$d, across %3$d words. Internal links are how authority and crawlers reach the rest of the site.', 'seo-automation' ),
								$internal,
								$min_links,
								$words
							),
							'evidence' => array(
								'internal_links' => $internal,
								'word_count'     => $words,
								'post_type'      => $post->post_type,
								'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
							),
							'fixer'    => 'internal_links',
							'fix_mode' => Issue::MODE_ASSISTED,
							'fix_payload' => array( 'post_id' => $post->ID ),
						)
					)
				);
			}

			if ( ! empty( $generic ) ) {
				$issues[] = $this->issue(
					array_merge(
						$base,
						array(
							'code'     => 'link.anchor.generic',
							'severity' => Issue::SEVERITY_LOW,
							'title'    => __( 'Links with uninformative anchor text', 'seo-automation' ),
							'detail'   => sprintf(
								/* translators: %d: number of links. */
								__( '%d links use anchor text like "click here". The anchor is the main clue about what is on the other end — for readers and for search engines.', 'seo-automation' ),
								count( $generic )
							),
							'evidence' => array(
								'links'          => array_slice( $generic, 0, 20 ),
								'affected_count' => count( $generic ),
								'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
							),
							'fixer'    => 'anchor_text',
							'fix_mode' => Issue::MODE_ASSISTED,
							'fix_payload' => array(
								'post_id' => $post->ID,
								'links'   => array_slice( $generic, 0, 20 ),
							),
						)
					)
				);
			}

			if ( $context->out_of_time() ) {
				break;
			}
		}

		$this->merge_graph( $context->audit_id, $inbound );

		$page_exhausted = ( $last === $last_in_page && count( $ids ) < $batch_size );

		$cursor = $page_exhausted
			? array(
				'phase'    => 'orphans',
				'after_id' => 0,
				'menu_ids' => $this->menu_object_ids(),
			)
			: array(
				'phase'    => 'scan',
				'after_id' => $last,
			);

		return new CheckerResult( $issues, $cursor, $scanned );
	}

	/**
	 * Phase two: report pages nothing links to.
	 *
	 * @param AuditContext $context Run state.
	 */
	private function run_orphan_phase( AuditContext $context ): CheckerResult {
		$after      = (int) $context->cursor( 'after_id', 0 );
		$batch_size = $context->batch_size();
		$menu_ids   = array_map( 'intval', (array) $context->cursor( 'menu_ids', array() ) );
		$graph      = $this->read_graph( $context->audit_id );
		$front_id   = (int) get_option( 'page_on_front' );
		$posts_id   = (int) get_option( 'page_for_posts' );

		$ids = ObjectIterator::post_ids( $context->post_types(), $after, $batch_size );

		if ( empty( $ids ) ) {
			$this->clear_graph( $context->audit_id );

			return CheckerResult::done( array(), 0 );
		}

		$issues       = array();
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

			if ( in_array( $id, array( $front_id, $posts_id ), true ) || in_array( $id, $menu_ids, true ) ) {
				continue;
			}

			// WooCommerce wires Cart, Checkout, My Account and Shop into the
			// customer flow (the cart icon, checkout redirect, account menu)
			// rather than through content links, exactly like the front page
			// and posts page are already exempted here.
			if ( in_array( $id, $this->woocommerce_page_ids(), true ) ) {
				continue;
			}

			if ( isset( $graph[ $post->post_name ] ) ) {
				continue;
			}

			if ( $context->seo->is_noindex( 'post', $id ) ) {
				continue;
			}

			$issues[] = $this->issue(
				array(
					'code'         => 'link.orphan',
					'severity'     => Issue::SEVERITY_HIGH,
					'object_type'  => 'post',
					'object_id'    => $post->ID,
					'object_label' => $post->post_title,
					'url'          => (string) get_permalink( $post ),
					'title'        => __( 'Orphan page: nothing links to it', 'seo-automation' ),
					'detail'       => __( 'No other page on the site links here and it is not in a menu. Crawlers reach it only through the sitemap, and it inherits no authority from the rest of the site.', 'seo-automation' ),
					'evidence'     => array(
						'post_type' => $post->post_type,
						'slug'      => $post->post_name,
						'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
					),
					'fixer'        => 'internal_links',
					'fix_mode'     => Issue::MODE_ASSISTED,
					'fix_payload'  => array(
						'target_post_id' => $post->ID,
						'direction'      => 'inbound',
					),
				)
			);

			if ( $context->out_of_time() ) {
				break;
			}
		}

		if ( $last === $last_in_page && count( $ids ) < $batch_size ) {
			$this->clear_graph( $context->audit_id );

			return new CheckerResult( $issues, null, $scanned );
		}

		return new CheckerResult(
			$issues,
			array(
				'phase'    => 'orphans',
				'after_id' => $last,
				'menu_ids' => $menu_ids,
			),
			$scanned
		);
	}

	/**
	 * The content to search for links.
	 *
	 * Page builders keep their markup in postmeta rather than post_content, so
	 * on those sites post_content alone reports links that exist as missing and
	 * pages that are linked as orphans. The builder payloads are appended for
	 * link extraction only — they are never parsed as content or written back.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function linkable_content( \WP_Post $post ): string {
		$content = (string) $post->post_content;

		/**
		 * Filter the postmeta keys searched for internal links.
		 *
		 * Add your builder's key here if links on your pages are being missed.
		 *
		 * @param string[] $keys Meta keys to include.
		 * @param \WP_Post $post Post being scanned.
		 */
		$keys = apply_filters(
			'seo_agent_link_source_meta_keys',
			array(
				'_elementor_data',
				'_et_pb_old_content',
				'panels_data',
				'_cornerstone_data',
				'_fl_builder_data',
				'_vc_post_settings',
			),
			$post
		);

		foreach ( (array) $keys as $key ) {
			$value = get_post_meta( $post->ID, (string) $key, true );

			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			// Builder payloads are JSON with escaped slashes, so unescape before
			// the href regex runs over them.
			$content .= "\n" . str_replace( '\\/', '/', $value );
		}

		return $content;
	}

	/**
	 * Last path segment of a URL, which for WordPress is the slug.
	 *
	 * @param string $url Link href.
	 */
	private function slug_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );
		$last     = (string) end( $segments );

		// Ignore paginated and feed sub-paths so /post/page/2 still credits /post.
		if ( is_numeric( $last ) || in_array( $last, array( 'feed', 'amp', 'embed' ), true ) ) {
			$segments = array_slice( $segments, 0, -1 );
			$last     = (string) end( $segments );
		}

		return rawurldecode( $last );
	}

	/**
	 * WooCommerce's own special pages.
	 *
	 * @return int[]
	 */
	private function woocommerce_page_ids(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}

		$ids = array();

		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Object IDs referenced by any nav menu.
	 *
	 * @return int[]
	 */
	private function menu_object_ids(): array {
		$ids   = array();
		$items = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'publish',
				'numberposts'    => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( (array) $items as $item_id ) {
			$object_id = (int) get_post_meta( (int) $item_id, '_menu_item_object_id', true );
			if ( $object_id > 0 ) {
				$ids[] = $object_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Merge a slice's inbound counts into the stored graph.
	 *
	 * @param int                $audit_id Audit ID.
	 * @param array<string,int>  $inbound  slug => count.
	 */
	private function merge_graph( int $audit_id, array $inbound ): void {
		if ( empty( $inbound ) ) {
			return;
		}

		$key      = $this->graph_key( $audit_id );
		$existing = get_transient( $key );
		$existing = is_array( $existing ) ? $existing : array();

		foreach ( $inbound as $slug => $count ) {
			$existing[ $slug ] = ( $existing[ $slug ] ?? 0 ) + $count;
		}

		set_transient( $key, $existing, DAY_IN_SECONDS );
	}

	/**
	 * The accumulated inbound-link index.
	 *
	 * @param int $audit_id Audit ID.
	 *
	 * @return array<string,int>
	 */
	private function read_graph( int $audit_id ): array {
		$graph = get_transient( $this->graph_key( $audit_id ) );

		return is_array( $graph ) ? $graph : array();
	}

	/**
	 * Drop the index once the audit no longer needs it.
	 *
	 * @param int $audit_id Audit ID.
	 */
	private function clear_graph( int $audit_id ): void {
		delete_transient( $this->graph_key( $audit_id ) );
	}

	/**
	 * Transient key for one audit's link graph.
	 *
	 * @param int $audit_id Audit ID.
	 */
	private function graph_key( int $audit_id ): string {
		return 'seo_agent_linkgraph_' . $audit_id;
	}
}
