<?php
/**
 * Admin screens.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Admin;

use SEOAgent\Blog\Admin as BlogAdmin;
use SEOAgent\Plugin;
use SEOAgent\Support\Options;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Registers the SEO Automation menu and renders its screens.
 *
 * This is the single top-level admin menu for the whole plugin: SEO
 * auditing screens (this class) plus the content-generation screens
 * (SEOAgent\Blog\Admin) are registered as submenus of one parent, so the
 * suite shows one WP-admin menu entry instead of two.
 *
 * The admin is a thin view over the same services the REST API uses, so the
 * two can never drift: applying a fix from a button and applying it from an
 * agent run the identical code path, change log included.
 */
class AdminMenu {

	private const SLUG = 'seo-agent';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the admin screens.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_agent_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Add the menu and its subpages.
	 *
	 * One top-level "SEO Automation" menu holds both the audit screens (this
	 * class) and the content-generation screens (SEOAgent\Blog\Admin),
	 * so the plugin surfaces as a single entry in wp-admin.
	 */
	public function add_menu(): void {
		$capability = current_user_can( Plugin::CAPABILITY ) ? Plugin::CAPABILITY : 'manage_options';

		add_menu_page(
			__( 'SEO Automation', 'seo-automation' ),
			__( 'SEO Automation', 'seo-automation' ),
			$capability,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);

		add_submenu_page( self::SLUG, __( 'Dashboard', 'seo-automation' ), __( 'Audit: Dashboard', 'seo-automation' ), $capability, self::SLUG, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Issues', 'seo-automation' ), __( 'Audit: Issues', 'seo-automation' ), $capability, self::SLUG . '-issues', array( $this, 'render_issues' ) );
		add_submenu_page( self::SLUG, __( 'Change log', 'seo-automation' ), __( 'Audit: Change log', 'seo-automation' ), $capability, self::SLUG . '-changes', array( $this, 'render_changes' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'seo-automation' ), __( 'Audit: Settings', 'seo-automation' ), $capability, self::SLUG . '-settings', array( $this, 'render_settings' ) );

		// Content-generation screens (formerly TheBlog Automation's own
		// top-level menu) now live as submenus of this same parent.
		if ( class_exists( BlogAdmin::class ) ) {
			BlogAdmin::add_submenus( self::SLUG );
		}
	}

	/**
	 * Screen styles.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_register_style( 'seo-agent-admin', false, array(), SEO_AUTOMATION_VERSION );
		wp_enqueue_style( 'seo-agent-admin' );
		wp_add_inline_style( 'seo-agent-admin', $this->styles() );
	}

	/**
	 * Handle every form post from these screens.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage SEO Agent.', 'seo-automation' ), 403 );
		}

		$action = isset( $_POST['seo_agent_action'] ) ? sanitize_key( wp_unslash( $_POST['seo_agent_action'] ) ) : '';

		check_admin_referer( 'seo_agent_' . $action );

		$notice = '';

		switch ( $action ) {
			case 'run_audit':
				$runner   = $this->plugin->audit_runner();
				$audit_id = $runner->start( array(), array( 'trigger' => 'admin' ) );
				$report   = $runner->run_to_completion( $audit_id, 25.0 );

				$notice = empty( $report['complete'] )
					? sprintf(
						/* translators: %d: audit ID. */
						__( 'Audit #%d started and is running in the background.', 'seo-automation' ),
						$audit_id
					)
					: sprintf(
						/* translators: 1: audit ID, 2: score. */
						__( 'Audit #%1$d finished with a score of %2$s/100.', 'seo-automation' ),
						$audit_id,
						$report['score'] ?? '?'
					);

				// A long audit continues on cron rather than holding the request.
				if ( empty( $report['complete'] ) ) {
					wp_schedule_single_event( time() + 30, Plugin::CRON_HOOK );
				}
				break;

			case 'apply_fix':
				$issue_id = isset( $_POST['issue_id'] ) ? (int) $_POST['issue_id'] : 0;
				$value    = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

				$input = array( 'approved' => true );

				if ( '' !== trim( (string) $value ) ) {
					$input['value'] = sanitize_textarea_field( (string) $value );
				}

				$result = $this->plugin->fix_runner()->apply( $issue_id, $input );

				$notice = ! empty( $result['ok'] )
					? sprintf(
						/* translators: %d: number of changes. */
						__( 'Applied %d change(s).', 'seo-automation' ),
						(int) ( $result['count'] ?? 0 )
					)
					: sprintf(
						/* translators: %s: error message. */
						__( 'Could not apply: %s', 'seo-automation' ),
						(string) ( $result['message'] ?? '' )
					);
				break;

			case 'ignore_issue':
				$issue_id = isset( $_POST['issue_id'] ) ? (int) $_POST['issue_id'] : 0;
				$this->plugin->issues()->set_status( $issue_id, 'ignored' );
				$notice = __( 'Issue dismissed.', 'seo-automation' );
				break;

			case 'revert_batch':
				$batch  = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
				$result = $this->plugin->fix_runner()->revert_batch( $batch );
				$notice = sprintf(
					/* translators: %d: number of changes. */
					__( 'Reverted %d change(s).', 'seo-automation' ),
					(int) ( $result['reverted'] ?? 0 )
				);
				break;

			case 'save_settings':
				$this->save_settings();
				$notice = __( 'Settings saved.', 'seo-automation' );
				break;

			case 'generate_token':
				$token = wp_generate_password( 48, false, false );
				Options::update( array( 'agent_token_hash' => hash( 'sha256', $token ) ) );

				set_transient( 'seo_agent_new_token', $token, 5 * MINUTE_IN_SECONDS );
				$notice = __( 'Token generated.', 'seo-automation' );
				break;
		}

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG );

		wp_safe_redirect( add_query_arg( 'seo_agent_notice', rawurlencode( $notice ), $redirect ) );
		exit;
	}

	/**
	 * Persist the settings form.
	 */
	private function save_settings(): void {
		$updates = array();

		$integers = array(
			'title_min_length',
			'title_max_length',
			'description_min_length',
			'description_max_length',
			'min_word_count',
			'min_internal_links',
			'batch_size',
			'request_timeout',
		);

		foreach ( $integers as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$updates[ $key ] = max( 0, (int) wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( isset( $_POST['autonomy'] ) ) {
			$autonomy = sanitize_key( wp_unslash( $_POST['autonomy'] ) );

			if ( in_array( $autonomy, array( 'review', 'auto_safe', 'auto_all' ), true ) ) {
				$updates['autonomy'] = $autonomy;
			}
		}

		$updates['scheduled_audits_enabled'] = ! empty( $_POST['scheduled_audits_enabled'] );

		// The API key is write-only from this screen: the field always renders
		// empty, so a blank submission means "leave the stored key alone"
		// rather than "delete it" — otherwise saving any unrelated setting
		// would silently wipe the key. Removal is an explicit checkbox.
		if ( ! empty( $_POST['psi_api_key_remove'] ) ) {
			$updates['psi_api_key'] = '';
		} elseif ( isset( $_POST['psi_api_key'] ) ) {
			$submitted = sanitize_text_field( wp_unslash( $_POST['psi_api_key'] ) );

			if ( '' !== trim( $submitted ) ) {
				$updates['psi_api_key'] = $submitted;
			}
		}

		if ( isset( $_POST['audit_post_types'] ) && is_array( $_POST['audit_post_types'] ) ) {
			$updates['audit_post_types'] = array_values(
				array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['audit_post_types'] ) ), 'post_type_exists' )
			);
		}

		if ( isset( $_POST['audit_taxonomies'] ) && is_array( $_POST['audit_taxonomies'] ) ) {
			$updates['audit_taxonomies'] = array_values(
				array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['audit_taxonomies'] ) ), 'taxonomy_exists' )
			);
		}

		Options::update( $updates );
	}

	// -----------------------------------------------------------------
	// Screens
	// -----------------------------------------------------------------

	/**
	 * Dashboard screen.
	 */
	public function render_dashboard(): void {
		$audit  = $this->plugin->audits()->latest_completed();
		$issues = $this->plugin->issues();

		$by_severity = $issues->counts_by( 'severity', array( 'status' => array( 'open' ) ) );
		$fixable     = $issues->query(
			array(
				'status'   => array( 'open' ),
				'fixable'  => true,
				'per_page' => 1,
			)
		)['total'];

		$top = $issues->query(
			array(
				'status'   => array( 'open' ),
				'orderby'  => 'impact',
				'per_page' => 10,
			)
		);

		include __DIR__ . '/views/dashboard.php';
	}

	/**
	 * Issues screen.
	 */
	public function render_issues(): void {
		$filters = array(
			'status'   => array( isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open' ),
			'per_page' => 50,
			'page'     => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
			'orderby'  => 'impact',
		);

		if ( ! empty( $_GET['severity'] ) ) {
			$filters['severity'] = array( sanitize_key( wp_unslash( $_GET['severity'] ) ) );
		}
		if ( ! empty( $_GET['checker'] ) ) {
			$filters['checker'] = sanitize_key( wp_unslash( $_GET['checker'] ) );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}

		$result   = $this->plugin->issues()->query( $filters );
		$checkers = $this->plugin->checkers()->all();
		$fixers   = $this->plugin->fixers();

		include __DIR__ . '/views/issues.php';
	}

	/**
	 * Change log screen.
	 */
	public function render_changes(): void {
		$changes = $this->plugin->changes()->recent( 100 );

		include __DIR__ . '/views/changes.php';
	}

	/**
	 * Settings screen.
	 */
	public function render_settings(): void {
		$settings   = Options::all();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$new_token  = get_transient( 'seo_agent_new_token' );
		$seo        = $this->plugin->seo();

		if ( $new_token ) {
			delete_transient( 'seo_agent_new_token' );
		}

		include __DIR__ . '/views/settings.php';
	}

	/**
	 * Render the admin notice carried through the redirect.
	 */
	public static function notice(): void {
		if ( empty( $_GET['seo_agent_notice'] ) ) {
			return;
		}

		$notice = sanitize_text_field( rawurldecode( wp_unslash( $_GET['seo_agent_notice'] ) ) );

		if ( '' === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}

	/**
	 * Open a form that posts to admin-post.php with a nonce.
	 *
	 * @param string $action Action name.
	 */
	public static function form_open( string $action ): void {
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="seo_agent_action" />' );
		printf( '<input type="hidden" name="seo_agent_action" value="%s" />', esc_attr( $action ) );

		wp_nonce_field( 'seo_agent_' . $action );
	}

	/**
	 * CSS for the screens. Inline so the plugin ships without asset files.
	 */
	private function styles(): string {
		return '
		.seoagent-score { font-size: 56px; font-weight: 300; line-height: 1; }
		.seoagent-cards { display: flex; flex-wrap: wrap; gap: 16px; margin: 20px 0; }
		.seoagent-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px 20px; min-width: 150px; }
		.seoagent-card h3 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #646970; }
		.seoagent-card .value { font-size: 28px; font-weight: 400; }
		.seoagent-sev { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
		.seoagent-sev-critical { background: #d63638; color: #fff; }
		.seoagent-sev-high { background: #e65054; color: #fff; }
		.seoagent-sev-medium { background: #dba617; color: #1d2327; }
		.seoagent-sev-low { background: #dcdcde; color: #1d2327; }
		.seoagent-sev-info { background: #f0f0f1; color: #646970; }
		.seoagent-detail { color: #646970; margin: 4px 0 0; }
		.seoagent-diff { font-family: Menlo, Consolas, monospace; font-size: 12px; white-space: pre-wrap; }
		.seoagent-diff .before { color: #d63638; }
		.seoagent-diff .after { color: #007017; }
		';
	}
}
