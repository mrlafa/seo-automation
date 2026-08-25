<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoints for the admin UI. Currently just product analysis — the
 * only step in the "Add Content" flow that needs a page-reload-free
 * round trip (URL -> resolved product -> AI-suggested primary keyword)
 * before the user commits to adding it to the queue.
 */
class Ajax {

	public static function init() {
		add_action( 'wp_ajax_theblog_analyze_product', array( __CLASS__, 'analyze_product' ) );
	}

	public static function analyze_product() {
		check_ajax_referer( 'theblog_analyze_product', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'seo-automation' ) ), 403 );
		}

		$url = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : '';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a product URL first.', 'seo-automation' ) ) );
		}

		if ( ! WooCommerce::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is not active on this site.', 'seo-automation' ) ) );
		}

		$product = WooCommerce::resolve_product_from_url( $url );
		if ( is_wp_error( $product ) ) {
			wp_send_json_error( array( 'message' => $product->get_error_message() ) );
		}

		$product_id = $product->get_id();

		if ( CPT_Topic::has_active_product_entry( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is already queued or currently generating. Check the queue below.', 'seo-automation' ) ) );
		}

		$data = WooCommerce::extract_product_data( $product );

		if ( '' === trim( $data['long_description'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Product found, but the long description is empty. Add a long description before generating SEO content.', 'seo-automation' ) ) );
		}

		$brief = Product_Analysis::run( $data );
		if ( is_wp_error( $brief ) ) {
			wp_send_json_error( array( 'message' => $brief->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'product_id'         => $product_id,
				'product_title'      => $data['title'],
				'product_url'        => $data['permalink'],
				'image_url'          => $data['images'][0]['url'] ?? '',
				'primary_keyword'    => $brief['primary_keyword'],
				'secondary_keywords' => $brief['secondary_keywords'],
			)
		);
	}
}
