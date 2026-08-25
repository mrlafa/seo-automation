<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Approve / Reject actions on the Review Queue admin screen.
 * This is the human approval gate: nothing the pipeline produces goes
 * live until an admin explicitly approves it here.
 */
class Review_Queue {

	public static function init() {
		add_action( 'admin_post_theblog_approve_post', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_theblog_reject_post', array( __CLASS__, 'handle_reject' ) );
	}

	public static function get_pending_posts( $limit = 20 ) {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'posts_per_page' => $limit,
				'meta_key'       => '_theblog_generated',
				'meta_value'     => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	public static function handle_approve() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'theblog_review_' . $post_id );

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to publish posts.', 'seo-automation' ) );
		}

		if ( $post_id ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			$topic_id = get_post_meta( $post_id, '_theblog_topic_id', true );
			if ( $topic_id ) {
				CPT_Topic::set_status( $topic_id, 'published' );
			}

			Logger::info( "Post #{$post_id} approved and published by user #" . get_current_user_id() );
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', 'approved', admin_url( 'admin.php?page=theblog-review-queue' ) ) );
		exit;
	}

	public static function handle_reject() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'theblog_review_' . $post_id );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete posts.', 'seo-automation' ) );
		}

		if ( $post_id ) {
			wp_trash_post( $post_id );

			$topic_id = get_post_meta( $post_id, '_theblog_topic_id', true );
			if ( $topic_id ) {
				CPT_Topic::set_status( $topic_id, 'rejected' );
			}

			Logger::info( "Post #{$post_id} rejected by user #" . get_current_user_id() );
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', 'rejected', admin_url( 'admin.php?page=theblog-review-queue' ) ) );
		exit;
	}
}
