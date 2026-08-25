<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the full research -> write -> optimize -> internal link ->
 * featured image -> SEO integration pipeline for a single queued item,
 * always landing the result in WordPress's native "pending review" status
 * so a human approves before anything goes live.
 *
 * Dispatches on the queue row's content source: 'topic' (the original
 * keyword workflow, untouched) or 'product_url' (WooCommerce product
 * grounded generation). Both converge on finalize_post() so the review
 * queue, approval gate, logging, and notifications work identically for
 * either source.
 */
class Pipeline {

	/**
	 * @return int|WP_Error The created post ID, or WP_Error on failure.
	 */
	public static function run_for_topic( $topic_id ) {
		$source = CPT_Topic::get_content_source( $topic_id );

		CPT_Topic::set_status( $topic_id, 'processing' );

		try {
			if ( 'product_url' === $source ) {
				return self::run_product_pipeline( $topic_id );
			}
			return self::run_topic_pipeline( $topic_id );
		} catch ( \Exception $e ) {
			CPT_Topic::set_status( $topic_id, 'error', $e->getMessage() );
			Logger::error( "Pipeline failed for topic #{$topic_id}: " . $e->getMessage() );
			return new \WP_Error( 'theblog_pipeline_failed', $e->getMessage() );
		}
	}

	/**
	 * Original Topic/Keyword workflow — unchanged behavior.
	 */
	protected static function run_topic_pipeline( $topic_id ) {
		$keyword = get_the_title( $topic_id );
		if ( ! $keyword ) {
			throw new \Exception( esc_html__( 'Topic not found.', 'seo-automation' ) );
		}

		Logger::info( "Pipeline started for topic #{$topic_id}: {$keyword}" );

		$brief = Research::run( $keyword );
		if ( is_wp_error( $brief ) ) {
			throw new \Exception( esc_html( 'Research failed: ' . $brief->get_error_message() ));
		}

		$draft = Writer::run( $keyword, $brief );
		if ( is_wp_error( $draft ) ) {
			throw new \Exception( esc_html( 'Writing failed: ' . $draft->get_error_message() ));
		}

		$seo = SEO_Optimizer::run( $keyword, $draft, $brief );
		if ( is_wp_error( $seo ) ) {
			throw new \Exception( esc_html( 'SEO optimization failed: ' . $seo->get_error_message() ));
		}

		$content = Internal_Linking::run( $draft['content_html'], $keyword );

		return self::finalize_post( $topic_id, $keyword, $draft, $seo, $content );
	}

	/**
	 * WooCommerce Product workflow: resolve the product fresh (it may have
	 * changed, or been deleted, since it was queued), extract its data via
	 * native WC APIs, then run the same research -> write -> optimize
	 * shape as the topic pipeline, grounded in the product's long
	 * description.
	 */
	protected static function run_product_pipeline( $topic_id ) {
		if ( ! WooCommerce::is_active() ) {
			throw new \Exception( esc_html__( 'WooCommerce is not active on this site.', 'seo-automation' ) );
		}

		$product_id = (int) get_post_meta( $topic_id, '_theblog_product_id', true );
		$product_post = $product_id ? get_post( $product_id ) : null;

		if ( ! $product_post || 'product' !== $product_post->post_type ) {
			throw new \Exception( esc_html__( 'The linked WooCommerce product could not be found.', 'seo-automation' ) );
		}
		if ( 'trash' === $product_post->post_status ) {
			throw new \Exception( esc_html__( 'This product has been deleted. Remove this queue entry.', 'seo-automation' ) );
		}

		$wc_product = wc_get_product( $product_id );
		if ( ! $wc_product ) {
			throw new \Exception( esc_html__( 'This product could not be loaded.', 'seo-automation' ) );
		}

		$product = WooCommerce::extract_product_data( $wc_product );

		Logger::info( "Pipeline started for topic #{$topic_id}: product #{$product_id} ({$product['title']})" );

		if ( '' === trim( $product['long_description'] ) ) {
			throw new \Exception( esc_html__( 'Product found, but the long description is empty. Add a long description before generating SEO content.', 'seo-automation' ) );
		}

		$brief = Product_Analysis::run( $product );
		if ( is_wp_error( $brief ) ) {
			throw new \Exception( esc_html( 'Product analysis failed: ' . $brief->get_error_message() ));
		}

		// The user saw (and could edit) the keyword suggested at "Analyze
		// Product" time, and that's what was stored on the queue row when
		// they added it to the queue. That approved value takes precedence
		// over whatever this fresh analysis call happens to suggest now —
		// otherwise the focus keyword would silently drift on every
		// "Generate Again", diverging from what the user reviewed.
		$approved_keyword = get_post_meta( $topic_id, '_theblog_primary_keyword', true );
		if ( $approved_keyword ) {
			$brief['primary_keyword'] = $approved_keyword;
		}
		$approved_secondary = get_post_meta( $topic_id, '_theblog_secondary_keywords', true );
		if ( ! empty( $approved_secondary ) && is_array( $approved_secondary ) ) {
			$brief['secondary_keywords'] = $approved_secondary;
		}

		$seo_options = get_post_meta( $topic_id, '_theblog_seo_options', true );
		$seo_options = is_array( $seo_options ) ? $seo_options : array();
		$want_faqs   = ! isset( $seo_options['faq'] ) || $seo_options['faq'];
		$want_links  = ! isset( $seo_options['internal_links'] ) || $seo_options['internal_links'];
		$want_alt    = ! isset( $seo_options['alt_text'] ) || $seo_options['alt_text'];

		$word_range = get_post_meta( $topic_id, '_theblog_article_length', true );
		$word_range = $word_range ? $word_range : '1500-2000';

		// Cap the grounding text fed into the writer prompt — an unusually
		// long description (some run to several thousand words) can crowd
		// out the model's output token budget and produce truncated,
		// unparseable JSON. The writer already has the analysis brief for
		// structure; this is just the source material to stay grounded in.
		$grounding_text = $product['long_description'];
		if ( strlen( $grounding_text ) > 8000 ) {
			$grounding_text = substr( $grounding_text, 0, 8000 ) . '… [truncated]';
		}

		$draft = Writer::run( $product['title'], $brief, $grounding_text, $word_range, $want_faqs );
		if ( is_wp_error( $draft ) ) {
			throw new \Exception( esc_html( 'Writing failed: ' . $draft->get_error_message() ));
		}

		$primary_keyword = $brief['primary_keyword'];

		$seo = SEO_Optimizer::run( $primary_keyword, $draft, $brief, $want_alt ? $product['images'] : array() );
		if ( is_wp_error( $seo ) ) {
			throw new \Exception( esc_html( 'SEO optimization failed: ' . $seo->get_error_message() ));
		}

		$content = $draft['content_html'];
		if ( $want_links ) {
			$content = Internal_Linking::run( $content, $primary_keyword, 0 );
			$content = Internal_Linking::ensure_product_cta( $content, $product );
		}

		return self::finalize_post( $topic_id, $primary_keyword, $draft, $seo, $content, $product, $brief );
	}

	/**
	 * Shared tail for both pipelines: create the post (always "pending"
	 * review), attach SEO metadata/featured image, run the SEO validator,
	 * update the queue row, and notify.
	 *
	 * @param array|null $product Product data (product mode only), or null for topic mode.
	 * @param array|null $brief   The research/analysis brief, used for secondary keywords on the validator.
	 */
	protected static function finalize_post( $topic_id, $keyword, array $draft, array $seo, $content, $product = null, $brief = null ) {
		$post_data = array(
			'post_title'   => wp_strip_all_tags( $draft['title'] ),
			'post_name'    => $seo['slug'] ?? '',
			'post_content' => $content,
			'post_excerpt' => wp_strip_all_tags( $draft['excerpt'] ?? '' ),
			'post_status'  => 'pending',
			'post_type'    => 'post',
		);

		$default_category = (int) Settings::get( 'default_category' );
		if ( $default_category ) {
			$post_data['post_category'] = array( $default_category );
		}

		$default_author = (int) Settings::get( 'default_author' );
		if ( ! $default_author ) {
			// wp_insert_post() falls back to the current user, which is 0
			// during a WP-Cron autopilot run (no logged-in user). Fall
			// back to the site's first administrator instead of 0.
			$default_author = self::get_fallback_author_id();
		}
		if ( $default_author ) {
			$post_data['post_author'] = $default_author;
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \Exception( esc_html( 'Could not create post: ' . $post_id->get_error_message() ));
		}

		update_post_meta( $post_id, '_theblog_generated', 1 );
		update_post_meta( $post_id, '_theblog_topic_id', $topic_id );
		update_post_meta( $post_id, '_theblog_keyword_density', $seo['keyword_density'] );
		update_post_meta( $post_id, '_theblog_content_source', $product ? 'product_url' : 'topic' );
		update_post_meta( $post_id, '_theblog_primary_keyword', $keyword );

		if ( $product ) {
			update_post_meta( $post_id, '_theblog_product_id', $product['id'] );
			if ( ! empty( $draft['faqs'] ) ) {
				update_post_meta( $post_id, '_theblog_faqs', $draft['faqs'] );
			}
		}

		SEO_Integration::apply( $post_id, $seo );

		if ( ! empty( $seo['image_alt_text'] ) ) {
			foreach ( $seo['image_alt_text'] as $attachment_id => $alt_text ) {
				update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			}
		}

		if ( $product ) {
			$image_result = Featured_Image::use_product_image_or_generate( $post_id, $product, $draft['title'], $keyword );
		} else {
			$image_result = Featured_Image::run( $post_id, $draft['title'], $keyword );
		}
		if ( is_wp_error( $image_result ) ) {
			Logger::error( "Featured image failed for post #{$post_id}: " . $image_result->get_error_message() );
		}
		$has_featured_image = ! is_wp_error( $image_result ) && (int) $image_result > 0;

		$seo_analysis = SEO_Validator::validate(
			array(
				'post_id'                       => $post_id,
				'article_title'                 => $draft['title'],
				'content_html'                  => $content,
				'seo_title'                     => $seo['seo_title'] ?? '',
				'meta_description'              => $seo['meta_description'] ?? '',
				'slug'                          => $post_data['post_name'],
				'primary_keyword'               => $keyword,
				'secondary_keywords'            => $brief['secondary_keywords'] ?? ( $seo['secondary_keywords'] ?? array() ),
				'has_internal_link'             => (bool) preg_match( '/<a\s+href=/i', $content ),
				'has_faq'                       => ! empty( $draft['faqs'] ) || false !== stripos( $content, 'faq' ) || false !== stripos( $content, 'frequently asked' ),
				'has_image_alt'                 => ! empty( $seo['image_alt_text'] ),
				'has_featured_image'            => $has_featured_image,
				'external_reference_suggestion' => $seo['external_reference_suggestion'] ?? '',
				'content_source'                => $product ? 'product_url' : 'topic',
				'product'                       => $product,
				'brief'                         => $brief,
				'requires_toc'                  => ! empty( $draft['requires_toc'] ),
				'image_alt_texts'               => ! empty( $seo['image_alt_text'] ) ? array_values( $seo['image_alt_text'] ) : array(),
			)
		);
		update_post_meta( $post_id, '_theblog_seo_analysis', $seo_analysis );

		update_post_meta( $topic_id, '_theblog_generated_post_id', $post_id );
		update_post_meta( $topic_id, '_theblog_primary_keyword', $keyword );
		CPT_Topic::set_status( $topic_id, 'ready' );

		Logger::info( "Pipeline complete for topic #{$topic_id}. Post #{$post_id} is pending review." );

		self::notify_ready_for_review( $post_id, $keyword );

		do_action( 'theblog_pipeline_complete', $post_id, $topic_id );

		return $post_id;
	}

	protected static function notify_ready_for_review( $post_id, $keyword ) {
		$to = Settings::get( 'notify_email' );
		if ( ! $to ) {
			return;
		}

		$subject = sprintf( '[%s] New draft ready for review: %s', get_bloginfo( 'name' ), $keyword );
		$link    = admin_url( 'admin.php?page=theblog-review-queue' );
		$message = sprintf(
			"A new AI-generated draft is ready for your review:\n\n%s\n\nReview it here: %s",
			get_the_title( $post_id ),
			$link
		);

		wp_mail( $to, $subject, $message );
	}

	/**
	 * Site's first administrator, used as the post author when autopilot
	 * runs with no logged-in user and no default author is configured.
	 */
	protected static function get_fallback_author_id() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}
}
