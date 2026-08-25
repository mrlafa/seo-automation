<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 6: push SEO metadata into whichever SEO plugin is active
 * (Yoast, Rank Math), or fall back to the plugin's own meta + a
 * lightweight <head> output when neither is present.
 */
class SEO_Integration {

	public static function apply( $post_id, array $seo ) {
		$title       = $seo['seo_title'] ?? '';
		$description = $seo['meta_description'] ?? '';
		$focus_kw    = $seo['focus_keyword'] ?? '';

		if ( self::is_yoast_active() ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_kw );
			return 'yoast';
		}

		if ( self::is_rankmath_active() ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, 'rank_math_description', $description );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
			return 'rankmath';
		}

		// Fallback: store our own meta and render basic tags in <head>.
		update_post_meta( $post_id, '_theblog_seo_title', $title );
		update_post_meta( $post_id, '_theblog_seo_description', $description );
		update_post_meta( $post_id, '_theblog_focus_keyword', $focus_kw );

		return 'fallback';
	}

	public static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function is_rankmath_active() {
		return class_exists( 'RankMath' );
	}

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_fallback_head_tags' ), 1 );
	}

	/**
	 * Only fires when neither Yoast nor Rank Math is active, so we never
	 * emit duplicate meta tags alongside a real SEO plugin.
	 */
	public static function output_fallback_head_tags() {
		if ( self::is_yoast_active() || self::is_rankmath_active() ) {
			return;
		}

		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id     = get_the_ID();
		$title       = get_post_meta( $post_id, '_theblog_seo_title', true );
		$description = get_post_meta( $post_id, '_theblog_seo_description', true );

		if ( $title ) {
			echo '<meta name="theblog-seo-title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
	}
}

add_action( 'init', array( 'SEO_Integration', 'init' ) );
