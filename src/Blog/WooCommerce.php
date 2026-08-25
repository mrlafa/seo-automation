<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a WooCommerce product from a URL and extracts its data using
 * native WordPress/WooCommerce APIs — no HTML scraping. url_to_postid()
 * only resolves URLs that already match this site's rewrite rules, so a
 * URL for a different site or a non-existent path simply fails to
 * resolve; there is no outbound HTTP request involved at all.
 */
class WooCommerce {

	public static function is_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * @return WC_Product|WP_Error
	 */
	public static function resolve_product_from_url( $url ) {
		if ( ! self::is_active() ) {
			return new \WP_Error( 'theblog_wc_inactive', __( 'WooCommerce is not active on this site.', 'seo-automation' ) );
		}

		$url = esc_url_raw( trim( (string) $url ) );
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'theblog_invalid_url', __( 'That does not look like a valid URL.', 'seo-automation' ) );
		}

		// Drop query string / fragment before resolving; url_to_postid()
		// matches against rewrite rules, which don't include the query.
		$path_only = strtok( $url, '?' );
		$path_only = strtok( $path_only, '#' );

		$post_id = url_to_postid( $path_only );

		if ( ! $post_id ) {
			// Retry with the trailing slash toggled — permalink structures vary.
			$alt       = ( '/' === substr( $path_only, -1 ) ) ? rtrim( $path_only, '/' ) : trailingslashit( $path_only );
			$post_id   = url_to_postid( $alt );
		}

		if ( ! $post_id ) {
			return new \WP_Error( 'theblog_product_not_found', __( 'Could not resolve a WordPress post/product from that URL. Make sure it is a URL on this site.', 'seo-automation' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || 'product' !== $post->post_type ) {
			return new \WP_Error( 'theblog_not_a_product', __( 'That URL does not point to a WooCommerce product.', 'seo-automation' ) );
		}

		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'theblog_product_deleted', __( 'This product has been deleted (it is in the Trash).', 'seo-automation' ) );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return new \WP_Error( 'theblog_product_not_found', __( 'This product could not be loaded.', 'seo-automation' ) );
		}

		return $product;
	}

	/**
	 * Pull everything the AI pipeline needs, using native getters only.
	 * The long description is the primary content source; everything
	 * else here is explicitly "supporting information" per the pipeline
	 * design — see Product_Analysis.
	 *
	 * @return array
	 */
	public static function extract_product_data( WC_Product $product ) {
		$product_id = $product->get_id();

		$long_description  = trim( wp_strip_all_tags( $product->get_description(), true ) );
		$short_description = trim( wp_strip_all_tags( $product->get_short_description(), true ) );

		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$tags       = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name(), $product );
			if ( $attribute->is_taxonomy() ) {
				$terms  = wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
				$values = $terms;
			} else {
				$values = $attribute->get_options();
			}
			if ( ! empty( $values ) ) {
				$attributes[ $name ] = array_map( 'strval', $values );
			}
		}

		$brand = self::get_brand( $product_id );

		$images = self::get_images( $product );

		$existing_seo = self::get_existing_seo_meta( $product_id );

		$related_ids = function_exists( 'wc_get_related_products' ) ? wc_get_related_products( $product_id, 4 ) : array();
		$related     = array();
		foreach ( $related_ids as $related_id ) {
			$related[] = array(
				'id'    => $related_id,
				'title' => get_the_title( $related_id ),
				'url'   => get_permalink( $related_id ),
			);
		}

		return array(
			'id'                 => $product_id,
			'title'              => $product->get_name(),
			'permalink'          => get_permalink( $product_id ),
			'sku'                => $product->get_sku(),
			'long_description'   => $long_description,
			'short_description'  => $short_description,
			'categories'         => array_values( (array) $categories ),
			'tags'               => array_values( (array) $tags ),
			'attributes'         => $attributes,
			'brand'              => $brand,
			'images'             => $images,
			'existing_seo_title' => $existing_seo['title'],
			'existing_meta_desc' => $existing_seo['description'],
			'related_products'   => $related,
			'category_links'     => self::get_category_links( $product_id ),
		);
	}

	protected static function get_brand( $product_id ) {
		// Common brand taxonomies added by WooCommerce Brands / third-party
		// brand plugins. Skip gracefully if none are registered.
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					return $terms[0];
				}
			}
		}
		return '';
	}

	protected static function get_images( WC_Product $product ) {
		$images         = array();
		$attachment_ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );

		foreach ( $attachment_ids as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( ! $url ) {
				continue;
			}
			$images[] = array(
				'attachment_id' => $attachment_id,
				'url'           => $url,
				'existing_alt'  => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'title'         => get_the_title( $attachment_id ),
			);
		}

		return $images;
	}

	protected static function get_existing_seo_meta( $product_id ) {
		$title       = get_post_meta( $product_id, '_yoast_wpseo_title', true );
		$description = get_post_meta( $product_id, '_yoast_wpseo_metadesc', true );

		if ( ! $title ) {
			$title = get_post_meta( $product_id, 'rank_math_title', true );
		}
		if ( ! $description ) {
			$description = get_post_meta( $product_id, 'rank_math_description', true );
		}

		return array(
			'title'       => $title ? $title : '',
			'description' => $description ? $description : '',
		);
	}

	protected static function get_category_links( $product_id ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );
		$links = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( is_wp_error( $term_link ) ) {
					continue;
				}
				$links[] = array(
					'name' => $term->name,
					'url'  => $term_link,
				);
			}
		}
		return $links;
	}
}
