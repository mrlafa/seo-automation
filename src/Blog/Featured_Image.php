<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 5: generate a featured image (if an image provider is configured)
 * and attach it to the post.
 */
class Featured_Image {

	/**
	 * @return int|WP_Error Attachment ID, or WP_Error. Returns 0 (no error)
	 *                       if no image provider is configured — this stage
	 *                       is optional and skips silently in that case.
	 */
	public static function run( $post_id, $title, $keyword ) {
		$provider = AI_Client::image_provider();
		if ( ! $provider ) {
			return 0;
		}

		$prompt = sprintf(
			'A high-quality, editorial blog featured image representing "%s" (topic: %s). ' .
			'Photographic or clean illustrative style, no text or watermarks in the image.',
			$title,
			$keyword
		);

		$image_url = $provider->generate_image( $prompt );
		if ( is_wp_error( $image_url ) ) {
			return $image_url;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $image_url, $post_id, $title, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Product mode: a real product photo already exists, so prefer reusing
	 * it (native set_post_thumbnail() against the existing attachment —
	 * no download, no AI image call) over generating a new one. Falls back
	 * to run() only when the product has no images at all.
	 *
	 * @param array $product Output of WooCommerce::extract_product_data().
	 * @return int|WP_Error Attachment ID, 0 if nothing was set.
	 */
	public static function use_product_image_or_generate( $post_id, array $product, $title, $keyword ) {
		if ( ! empty( $product['images'][0]['attachment_id'] ) ) {
			$attachment_id = (int) $product['images'][0]['attachment_id'];
			set_post_thumbnail( $post_id, $attachment_id );
			return $attachment_id;
		}

		return self::run( $post_id, $title, $keyword );
	}
}
