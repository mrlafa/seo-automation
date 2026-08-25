<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "theblog_topic" is the queue item: a keyword/topic waiting to be
 * researched, written, optimized, and (after approval) published.
 */
class CPT_Topic {

	const POST_TYPE = 'theblog_topic';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Topics', 'seo-automation' ),
				'public'          => false,
				'show_ui'         => false, // we render our own list screen
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Valid lifecycle values for the _theblog_status meta field.
	 */
	public static function statuses() {
		return array(
			'queued'     => __( 'Queued', 'seo-automation' ),
			'processing' => __( 'Processing', 'seo-automation' ),
			'ready'      => __( 'Ready for Review', 'seo-automation' ),
			'published'  => __( 'Published', 'seo-automation' ),
			'rejected'   => __( 'Rejected', 'seo-automation' ),
			'error'      => __( 'Error', 'seo-automation' ),
		);
	}

	/**
	 * Where a queue row's content comes from. 'topic' is the original,
	 * still-default mode; 'product_url' is the new WooCommerce mode.
	 */
	public static function content_sources() {
		return array(
			'topic'       => __( 'Topic / Keyword', 'seo-automation' ),
			'product_url' => __( 'WooCommerce Product', 'seo-automation' ),
		);
	}

	/**
	 * Article "content type". Only one is implemented today; the enum
	 * exists so Product Review / Buying Guide / Comparison / etc. can be
	 * added later without touching the queue schema or pipeline branching.
	 */
	public static function content_types() {
		return array(
			'seo_blog_article' => __( 'SEO Blog Article', 'seo-automation' ),
		);
	}

	public static function article_lengths() {
		return array(
			'800-1200'   => __( '800–1200 words', 'seo-automation' ),
			'1200-1800'  => __( '1200–1800 words', 'seo-automation' ),
			'1500-2000'  => __( '1500–2000 words', 'seo-automation' ),
			'2000-2800'  => __( '2000–2800 words', 'seo-automation' ),
		);
	}

	/**
	 * @param string $keyword      Topic title, or the resolved product title in product mode.
	 * @param string $scheduled_at MySQL datetime, blank = now.
	 * @param array  $extra {
	 *     Optional. Additional queue-row metadata. All keys are optional
	 *     and additive — omitting them preserves the original Topic/Keyword
	 *     behavior exactly.
	 *
	 *     @type string $content_source      'topic' (default) or 'product_url'.
	 *     @type int    $product_id          WooCommerce product post ID (product mode).
	 *     @type string $product_url         The submitted product URL (product mode).
	 *     @type string $content_type        One of self::content_types() keys.
	 *     @type string $article_length      One of self::article_lengths() keys.
	 *     @type string $primary_keyword     Pre-resolved primary keyword, if already known.
	 *     @type array  $secondary_keywords  Pre-resolved secondary keywords, if already known.
	 *     @type array  $seo_options         {rank_math, yoast, faq, internal_links, alt_text} booleans.
	 * }
	 * @return int|WP_Error
	 */
	public static function create( $keyword, $scheduled_at = '', array $extra = array() ) {
		$topic_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => wp_strip_all_tags( $keyword ),
				'post_status' => 'publish', // internal CPT, "publish" just means "active row"
			),
			true
		);

		if ( is_wp_error( $topic_id ) ) {
			return $topic_id;
		}

		$content_source = in_array( $extra['content_source'] ?? '', array_keys( self::content_sources() ), true )
			? $extra['content_source']
			: 'topic';

		$content_type = in_array( $extra['content_type'] ?? '', array_keys( self::content_types() ), true )
			? $extra['content_type']
			: 'seo_blog_article';

		$article_length = in_array( $extra['article_length'] ?? '', array_keys( self::article_lengths() ), true )
			? $extra['article_length']
			: '1500-2000';

		$default_seo_options = array(
			'rank_math'      => true,
			'yoast'          => true,
			'faq'            => true,
			'internal_links' => true,
			'alt_text'       => true,
		);
		$seo_options = wp_parse_args( $extra['seo_options'] ?? array(), $default_seo_options );

		update_post_meta( $topic_id, '_theblog_status', 'queued' );
		update_post_meta( $topic_id, '_theblog_scheduled_at', $scheduled_at ? $scheduled_at : current_time( 'mysql' ) );
		update_post_meta( $topic_id, '_theblog_generated_post_id', 0 );
		update_post_meta( $topic_id, '_theblog_error', '' );
		update_post_meta( $topic_id, '_theblog_content_source', $content_source );
		update_post_meta( $topic_id, '_theblog_content_type', $content_type );
		update_post_meta( $topic_id, '_theblog_article_length', $article_length );
		update_post_meta( $topic_id, '_theblog_seo_options', $seo_options );

		if ( 'product_url' === $content_source ) {
			update_post_meta( $topic_id, '_theblog_product_id', (int) ( $extra['product_id'] ?? 0 ) );
			update_post_meta( $topic_id, '_theblog_product_url', esc_url_raw( $extra['product_url'] ?? '' ) );
		}

		if ( ! empty( $extra['primary_keyword'] ) ) {
			update_post_meta( $topic_id, '_theblog_primary_keyword', sanitize_text_field( $extra['primary_keyword'] ) );
		}
		if ( ! empty( $extra['secondary_keywords'] ) && is_array( $extra['secondary_keywords'] ) ) {
			update_post_meta( $topic_id, '_theblog_secondary_keywords', array_map( 'sanitize_text_field', $extra['secondary_keywords'] ) );
		}

		return $topic_id;
	}

	/**
	 * Backward-compatible: rows created before this field existed have no
	 * meta at all, and should behave exactly as the original Topic/Keyword rows.
	 */
	public static function get_content_source( $topic_id ) {
		$source = get_post_meta( $topic_id, '_theblog_content_source', true );
		return $source ? $source : 'topic';
	}

	public static function get_primary_keyword( $topic_id ) {
		$kw = get_post_meta( $topic_id, '_theblog_primary_keyword', true );
		return $kw ? $kw : get_the_title( $topic_id );
	}

	/**
	 * Is there already a non-terminal queue row for this product?
	 * Used to prevent duplicate queue entries in product mode.
	 */
	public static function has_active_product_entry( $product_id ) {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_theblog_product_id',
						'value' => (int) $product_id,
					),
					array(
						'key'     => '_theblog_status',
						'value'   => array( 'queued', 'processing' ),
						'compare' => 'IN',
					),
				),
			)
		);

		return ! empty( $existing );
	}

	public static function set_status( $topic_id, $status, $error_message = '' ) {
		update_post_meta( $topic_id, '_theblog_status', $status );
		if ( 'error' === $status ) {
			update_post_meta( $topic_id, '_theblog_error', $error_message );
		}
		if ( 'processing' === $status ) {
			update_post_meta( $topic_id, '_theblog_processing_since', time() );
		}
	}

	/**
	 * A topic stuck in "processing" (e.g. the request that was running the
	 * pipeline died mid-run — timeout, restart) with no recovery path
	 * otherwise, since nothing ever moves it out of that state on its own.
	 */
	public static function is_stuck_processing( $topic_id, $stale_after_seconds = 600 ) {
		if ( 'processing' !== self::get_status( $topic_id ) ) {
			return false;
		}
		$since = (int) get_post_meta( $topic_id, '_theblog_processing_since', true );
		return $since && ( time() - $since ) > $stale_after_seconds;
	}

	public static function get_status( $topic_id ) {
		return get_post_meta( $topic_id, '_theblog_status', true );
	}

	public static function get_due_queued( $limit = 5 ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'meta_value',
				'meta_key'       => '_theblog_scheduled_at',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_theblog_status',
						'value' => 'queued',
					),
					array(
						'key'     => '_theblog_scheduled_at',
						'value'   => current_time( 'mysql' ),
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
				),
			)
		);
	}

	public static function get_all( $limit = 50 ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}
}
