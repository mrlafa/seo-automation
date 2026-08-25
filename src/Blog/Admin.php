<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	/**
	 * Parent menu slug this screen's submenus are registered under.
	 * The single top-level menu is created by SEOAgent\Admin\AdminMenu;
	 * this class only adds its own submenu items to it so the whole
	 * plugin shows one menu entry.
	 */
	const PARENT_SLUG = 'seo-agent';

	public static function init() {
		// Menu registration is invoked directly by SEOAgent\Admin\AdminMenu
		// via add_submenus() so both feature sets share one top-level menu.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_theblog_add_topic', array( __CLASS__, 'handle_add_topic' ) );
		add_action( 'admin_post_theblog_generate_now', array( __CLASS__, 'handle_generate_now' ) );
		add_action( 'admin_post_theblog_delete_topic', array( __CLASS__, 'handle_delete_topic' ) );
		add_action( 'admin_post_theblog_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_theblog_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Add this feature set's screens under the shared "SEO Automation" parent menu.
	 *
	 * @param string $parent_slug Parent menu slug to attach to.
	 */
	public static function add_submenus( $parent_slug = self::PARENT_SLUG ) {
		add_submenu_page( $parent_slug, __( 'Dashboard', 'seo-automation' ), __( 'Content: Dashboard', 'seo-automation' ), 'edit_posts', 'theblog-dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( $parent_slug, __( 'Topics', 'seo-automation' ), __( 'Content: Topics', 'seo-automation' ), 'edit_posts', 'theblog-topics', array( __CLASS__, 'render_topics' ) );
		add_submenu_page( $parent_slug, __( 'Review Queue', 'seo-automation' ), __( 'Content: Review Queue', 'seo-automation' ), 'edit_posts', 'theblog-review-queue', array( __CLASS__, 'render_review_queue' ) );
		add_submenu_page( $parent_slug, __( 'Settings', 'seo-automation' ), __( 'Content: Settings', 'seo-automation' ), 'manage_options', 'theblog-settings', array( __CLASS__, 'render_settings' ) );
		add_submenu_page( $parent_slug, __( 'Logs', 'seo-automation' ), __( 'Content: Logs', 'seo-automation' ), 'manage_options', 'theblog-logs', array( __CLASS__, 'render_logs' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'theblog' ) === false ) {
			return;
		}
		wp_enqueue_style( 'theblog-admin', SEO_AUTOMATION_URL . 'src/Blog/assets/css/admin.css', array(), SEO_AUTOMATION_VERSION );

		wp_enqueue_script( 'theblog-admin', SEO_AUTOMATION_URL . 'src/Blog/assets/js/admin.js', array( 'jquery' ), SEO_AUTOMATION_VERSION, true );
		wp_localize_script(
			'theblog-admin',
			'TheblogAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'analyzeNonce'     => wp_create_nonce( 'theblog_analyze_product' ),
				'wooCommerceActive' => WooCommerce::is_active(),
				'i18n'             => array(
					'analyzing'      => __( 'Analyzing product…', 'seo-automation' ),
					'analyzeProduct' => __( 'Analyze Product', 'seo-automation' ),
					'enterUrl'       => __( 'Enter a product URL first.', 'seo-automation' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'seo-automation' ),
					'productFound'   => __( 'Product Found ✓', 'seo-automation' ),
				),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * Renderers
	 * ---------------------------------------------------------------- */

	public static function render_dashboard() {
		include __DIR__ . '/views/dashboard.php';
	}

	public static function render_topics() {
		include __DIR__ . '/views/topics.php';
	}

	public static function render_review_queue() {
		include __DIR__ . '/views/review-queue.php';
	}

	public static function render_settings() {
		include __DIR__ . '/views/settings.php';
	}

	public static function render_logs() {
		include __DIR__ . '/views/logs.php';
	}

	/* -------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	public static function handle_add_topic() {
		check_admin_referer( 'theblog_add_topic' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-automation' ) );
		}

		$content_source = isset( $_POST['content_source'] ) && 'product_url' === $_POST['content_source'] ? 'product_url' : 'topic';
		$scheduled_at   = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
		$scheduled_at   = $scheduled_at ? gmdate( 'Y-m-d H:i:s', strtotime( $scheduled_at ) ) : current_time( 'mysql' );

		$extra = array(
			'content_source' => $content_source,
			'content_type'   => isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : 'seo_blog_article',
			'article_length' => isset( $_POST['article_length'] ) ? sanitize_text_field( wp_unslash( $_POST['article_length'] ) ) : '1500-2000',
			'seo_options'    => array(
				'rank_math'      => ! empty( $_POST['seo_rank_math'] ),
				'yoast'          => ! empty( $_POST['seo_yoast'] ),
				'faq'            => ! empty( $_POST['seo_faq'] ),
				'internal_links' => ! empty( $_POST['seo_internal_links'] ),
				'alt_text'       => ! empty( $_POST['seo_alt_text'] ),
			),
		);

		if ( 'product_url' === $content_source ) {
			$product_id      = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
			$product_url     = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : '';
			$product_title   = isset( $_POST['product_title'] ) ? sanitize_text_field( wp_unslash( $_POST['product_title'] ) ) : '';
			$primary_keyword = isset( $_POST['primary_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_keyword'] ) ) : '';
			$secondary_raw   = isset( $_POST['secondary_keywords'] )
				? sanitize_textarea_field( wp_unslash( $_POST['secondary_keywords'] ) )
				: '';
			$secondary       = $secondary_raw ? json_decode( $secondary_raw, true ) : array();
			$secondary       = is_array( $secondary )
				? array_values( array_filter( array_map( 'sanitize_text_field', $secondary ) ) )
				: array();

			if ( ! $product_id || ! $product_title ) {
				wp_safe_redirect( add_query_arg( 'theblog_notice', 'product_not_analyzed', admin_url( 'admin.php?page=theblog-topics' ) ) );
				exit;
			}

			if ( CPT_Topic::has_active_product_entry( $product_id ) ) {
				wp_safe_redirect( add_query_arg( 'theblog_notice', 'duplicate_product', admin_url( 'admin.php?page=theblog-topics' ) ) );
				exit;
			}

			$extra['product_id']         = $product_id;
			$extra['product_url']        = $product_url;
			$extra['primary_keyword']    = $primary_keyword;
			$extra['secondary_keywords'] = is_array( $secondary ) ? $secondary : array();

			$result = CPT_Topic::create( $product_title, $scheduled_at, $extra );
			$notice = is_wp_error( $result ) ? 'topic_error' : 'topic_added';
		} else {
			$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

			if ( '' === $keyword ) {
				$notice = 'topic_empty';
			} else {
				$result = CPT_Topic::create( $keyword, $scheduled_at, $extra );
				$notice = is_wp_error( $result ) ? 'topic_error' : 'topic_added';
			}
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', $notice, admin_url( 'admin.php?page=theblog-topics' ) ) );
		exit;
	}

	public static function handle_generate_now() {
		$topic_id = isset( $_POST['topic_id'] ) ? (int) $_POST['topic_id'] : 0;
		check_admin_referer( 'theblog_generate_' . $topic_id );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-automation' ) );
		}

		$notice = 'generated';

		if ( $topic_id ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 180 );
			}
			$result = Pipeline::run_for_topic( $topic_id );
			if ( is_wp_error( $result ) ) {
				$notice = 'generate_error';
			}
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', $notice, admin_url( 'admin.php?page=theblog-topics' ) ) );
		exit;
	}

	public static function handle_delete_topic() {
		$topic_id = isset( $_POST['topic_id'] ) ? (int) $_POST['topic_id'] : 0;
		check_admin_referer( 'theblog_delete_topic_' . $topic_id );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-automation' ) );
		}

		if ( $topic_id ) {
			wp_delete_post( $topic_id, true );
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', 'topic_deleted', admin_url( 'admin.php?page=theblog-topics' ) ) );
		exit;
	}

	public static function handle_save_settings() {
		check_admin_referer( 'theblog_save_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-automation' ) );
		}

		$clean = Settings::sanitize( wp_unslash( $_POST ) );
		Settings::update( $clean );
		Scheduler::reschedule();

		// Catch the "picked a provider, forgot to also paste its key" mistake
		// at save time rather than letting it surface later as a cryptic
		// "API key is not set" error mid-generation.
		$key_map = array(
			'anthropic' => 'anthropic_api_key',
			'openai'    => 'openai_api_key',
			'deepseek'  => 'deepseek_api_key',
		);
		$notice = 'settings_saved';
		if ( isset( $key_map[ $clean['ai_provider'] ] ) && empty( $clean[ $key_map[ $clean['ai_provider'] ] ] ) ) {
			$notice = 'settings_saved_no_key';
		}

		wp_safe_redirect( add_query_arg( 'theblog_notice', $notice, admin_url( 'admin.php?page=theblog-settings' ) ) );
		exit;
	}

	public static function handle_clear_logs() {
		check_admin_referer( 'theblog_clear_logs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-automation' ) );
		}

		Logger::clear();

		wp_safe_redirect( add_query_arg( 'theblog_notice', 'logs_cleared', admin_url( 'admin.php?page=theblog-logs' ) ) );
		exit;
	}

	/* -------------------------------------------------------------------
	 * Notices
	 * ---------------------------------------------------------------- */

	public static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state (filters, paging, notice text). No action is taken and nothing is written, so a nonce would serve no purpose; each value is still sanitised.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( empty( $_GET['theblog_notice'] ) || 0 !== strpos( $page, 'theblog' ) ) {
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['theblog_notice'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'topic_added'          => array( 'success', __( 'Added to the queue.', 'seo-automation' ) ),
			'topic_empty'          => array( 'error', __( 'Please enter a topic/keyword.', 'seo-automation' ) ),
			'topic_error'          => array( 'error', __( 'Could not add to the queue.', 'seo-automation' ) ),
			'topic_deleted'        => array( 'success', __( 'Entry deleted.', 'seo-automation' ) ),
			'product_not_analyzed' => array( 'error', __( 'Click "Analyze Product" and wait for it to succeed before adding to the queue.', 'seo-automation' ) ),
			'duplicate_product'    => array( 'error', __( 'This product is already queued or currently generating.', 'seo-automation' ) ),
			'generated'       => array( 'success', __( 'Draft generated and sent to the Review Queue.', 'seo-automation' ) ),
			'generate_error'  => array( 'error', __( 'Generation failed. Check the Logs screen for details.', 'seo-automation' ) ),
			'approved'        => array( 'success', __( 'Post approved and published.', 'seo-automation' ) ),
			'rejected'        => array( 'success', __( 'Post rejected and moved to Trash.', 'seo-automation' ) ),
			'settings_saved'  => array( 'success', __( 'Settings saved.', 'seo-automation' ) ),
			'settings_saved_no_key' => array( 'warning', __( 'Settings saved — but the selected Text Provider has no API key entered. Generation will fail until you add one.', 'seo-automation' ) ),
			'logs_cleared'    => array( 'success', __( 'Logs cleared.', 'seo-automation' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $notice ];
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}
}
