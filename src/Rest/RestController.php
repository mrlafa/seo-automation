<?php
/**
 * REST API: the surface an external agent drives.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Rest;

use SEOAgent\Plugin;
use SEOAgent\Seo\AdapterFactory;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Routes under `seo-agent/v1`.
 *
 * Shaped around the loop the agent runs — audit, read issues, plan a fix,
 * apply it, verify it, report — rather than mirroring the database. Every
 * mutating route supports a dry run, and every response carries enough context
 * for the caller to decide what to do next without a second request.
 */
class RestController {

	public const NAMESPACE = 'seo-agent/v1';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register every route.
	 */
	public function register_routes(): void {
		$auth = array( $this, 'authorise' );

		register_rest_route(
			self::NAMESPACE,
			'/site',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_site' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_capabilities' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audits',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_audits' ),
					'permission_callback' => $auth,
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start_audit' ),
					'permission_callback' => $auth,
					'args'                => array(
						'scopes'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
							'description' => 'Checker slugs or group names. Empty runs everything applicable.',
						),
						'post_types' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'run'        => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Run slices immediately rather than only queueing.',
						),
						'budget'     => array(
							'type'        => 'number',
							'default'     => 20,
							'description' => 'Seconds this request may spend running the audit.',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audits/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audit' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audits/(?P<id>\d+)/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'step_audit' ),
				'permission_callback' => $auth,
				'args'                => array(
					'budget' => array(
						'type'    => 'number',
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audits/(?P<id>\d+)/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_issues' ),
				'permission_callback' => $auth,
				'args'                => $this->issue_filter_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_issue' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_issue' ),
					'permission_callback' => $auth,
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'enum'     => array( 'open', 'ignored', 'fixed' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<id>\d+)/fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fix_issue' ),
				'permission_callback' => $auth,
				'args'                => array(
					'dry_run'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Preview the change without writing. Defaults to true deliberately.',
					),
					'approved' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Confirms a reviewed plan, overriding the site autonomy setting.',
					),
					'input'    => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fixes/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fix_batch' ),
				'permission_callback' => $auth,
				'args'                => array(
					'issue_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'inputs'    => array(
						'type'        => 'object',
						'default'     => array(),
						'description' => 'Per-issue input keyed by issue ID.',
					),
					'dry_run'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fixes/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_fixes' ),
				'permission_callback' => $auth,
				'args'                => array(
					'issue_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_changes' ),
				'permission_callback' => $auth,
				'args'                => array(
					'batch'    => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/changes/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => $auth,
				'args'                => array(
					'batch'     => array( 'type' => 'string' ),
					'change_id' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/objects/(?P<type>post|term)/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_object' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// Authorisation
	// -----------------------------------------------------------------

	/**
	 * Who may call these routes.
	 *
	 * Standard WordPress authentication (application passwords over HTTPS) plus
	 * a capability check. A shared token is accepted as a second factor only —
	 * it never grants access on its own, so a leaked token is not a way in.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function authorise( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'seo_agent_unauthenticated',
				__( 'Authenticate with a WordPress application password.', 'seo-automation' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Plugin::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'seo_agent_forbidden',
				__( 'Your account cannot manage SEO Agent.', 'seo-automation' ),
				array( 'status' => 403 )
			);
		}

		$expected = (string) Options::get( 'agent_token_hash', '' );

		if ( '' !== $expected ) {
			$supplied = (string) $request->get_header( 'x-seo-agent-token' );

			if ( '' === $supplied || ! hash_equals( $expected, hash( 'sha256', $supplied ) ) ) {
				return new \WP_Error(
					'seo_agent_bad_token',
					__( 'Missing or invalid X-SEO-Agent-Token header.', 'seo-automation' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	// -----------------------------------------------------------------
	// Site and capabilities
	// -----------------------------------------------------------------

	/**
	 * Everything an agent needs to orient itself on an unfamiliar site.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_site( \WP_REST_Request $request ): \WP_REST_Response {
		global $wp_version;

		$seo = $this->plugin->seo();

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$counts = wp_count_posts( $post_type->name );

			$post_types[ $post_type->name ] = array(
				'label'     => $post_type->label,
				'published' => (int) ( $counts->publish ?? 0 ),
				'draft'     => (int) ( $counts->draft ?? 0 ),
			);
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$taxonomies[ $taxonomy->name ] = array(
				'label'        => $taxonomy->label,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'terms'        => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) ),
			);
		}

		return new \WP_REST_Response(
			array(
				'name'             => get_bloginfo( 'name' ),
				'description'      => get_bloginfo( 'description' ),
				'url'              => home_url( '/' ),
				'admin_url'        => admin_url(),
				'language'         => get_bloginfo( 'language' ),
				'wordpress'        => $wp_version,
				'php'              => PHP_VERSION,
				'plugin_version'   => defined( 'SEO_AUTOMATION_VERSION' ) ? SEO_AUTOMATION_VERSION : 'dev',
				'indexable'        => '1' === (string) get_option( 'blog_public' ),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'seo_plugin'       => array(
					'active'   => $seo->slug(),
					'label'    => $seo->label(),
					'detected' => AdapterFactory::detected(),
				),
				'woocommerce'      => array(
					'active'   => class_exists( 'WooCommerce' ),
					'version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
					'products' => post_type_exists( 'product' ) ? (int) ( wp_count_posts( 'product' )->publish ?? 0 ) : 0,
				),
				'post_types'       => $post_types,
				'taxonomies'       => $taxonomies,
				'theme'            => wp_get_theme()->get( 'Name' ),
				'autonomy'         => Options::get( 'autonomy' ),
			),
			200
		);
	}

	/**
	 * The catalogue of checkers and fixers, so an agent can discover what this
	 * install can do rather than assuming.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_capabilities( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'checkers' => $this->plugin->checkers()->describe(),
				'fixers'   => $this->plugin->fixers()->describe(),
				'severities' => array( 'critical', 'high', 'medium', 'low', 'info' ),
				'fix_modes'  => array( 'auto', 'assisted', 'manual' ),
			),
			200
		);
	}

	// -----------------------------------------------------------------
	// Audits
	// -----------------------------------------------------------------

	/**
	 * Recent audits.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_audits( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			$this->plugin->audits()->recent( (int) $request->get_param( 'per_page' ) ),
			200
		);
	}

	/**
	 * Start an audit, optionally running the first slices inline.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function start_audit( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array( 'trigger' => 'api' );

		$post_types = array_values( array_filter( (array) $request->get_param( 'post_types' ), 'post_type_exists' ) );

		if ( ! empty( $post_types ) ) {
			$args['audit_post_types'] = $post_types;
		}

		$runner   = $this->plugin->audit_runner();
		$audit_id = $runner->start( (array) $request->get_param( 'scopes' ), $args );

		if ( ! $request->get_param( 'run' ) ) {
			return new \WP_REST_Response(
				array(
					'audit_id' => $audit_id,
					'status'   => 'queued',
					'complete' => false,
				),
				201
			);
		}

		$budget = max( 1.0, min( 120.0, (float) $request->get_param( 'budget' ) ) );

		return new \WP_REST_Response( $runner->run_to_completion( $audit_id, $budget ), 201 );
	}

	/**
	 * One audit.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_audit( \WP_REST_Request $request ) {
		$audit = $this->plugin->audits()->find( (int) $request['id'] );

		if ( ! $audit ) {
			return new \WP_Error( 'seo_agent_not_found', __( 'Audit not found.', 'seo-automation' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $audit, 200 );
	}

	/**
	 * Run another slice of an audit.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function step_audit( \WP_REST_Request $request ): \WP_REST_Response {
		$budget = max( 1.0, min( 120.0, (float) $request->get_param( 'budget' ) ) );

		return new \WP_REST_Response(
			$this->plugin->audit_runner()->step( (int) $request['id'], $budget ),
			200
		);
	}

	/**
	 * A readable report for one audit.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_report( \WP_REST_Request $request ) {
		$audit_id = (int) $request['id'];
		$audit    = $this->plugin->audits()->find( $audit_id );

		if ( ! $audit ) {
			return new \WP_Error( 'seo_agent_not_found', __( 'Audit not found.', 'seo-automation' ), array( 'status' => 404 ) );
		}

		$issues = $this->plugin->issues();

		$open = $issues->query(
			array(
				'status'   => array( 'open' ),
				'orderby'  => 'impact',
				'per_page' => 100,
			)
		);

		$by_checker = $issues->counts_by( 'checker', array( 'status' => array( 'open' ) ) );
		$groups     = array();

		foreach ( $this->plugin->checkers()->all() as $slug => $checker ) {
			if ( empty( $by_checker[ $slug ] ) ) {
				continue;
			}

			$group             = $checker->group();
			$groups[ $group ]  = ( $groups[ $group ] ?? 0 ) + (int) $by_checker[ $slug ];
		}

		return new \WP_REST_Response(
			array(
				'audit'       => $audit,
				'score'       => $audit['score'],
				'by_severity' => $issues->counts_by( 'severity', array( 'status' => array( 'open' ) ) ),
				'by_checker'  => $by_checker,
				'by_group'    => $groups,
				'fixable'     => $issues->query(
					array(
						'status'   => array( 'open' ),
						'fixable'  => true,
						'per_page' => 1,
					)
				)['total'],
				'top_issues'  => array_map( array( $this, 'shape_issue' ), $open['items'] ),
				'total_open'  => $open['total'],
			),
			200
		);
	}

	// -----------------------------------------------------------------
	// Issues
	// -----------------------------------------------------------------

	/**
	 * Filter arguments shared by the issue listing.
	 *
	 * @return array<string,mixed>
	 */
	private function issue_filter_args(): array {
		return array(
			'audit_id'    => array( 'type' => 'integer' ),
			'status'      => array(
				'type'    => 'array',
				'items'   => array( 'type' => 'string' ),
				'default' => array( 'open' ),
			),
			'severity'    => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'code'        => array( 'type' => 'string' ),
			'checker'     => array( 'type' => 'string' ),
			'fixer'       => array( 'type' => 'string' ),
			'fix_mode'    => array( 'type' => 'string' ),
			'object_type' => array( 'type' => 'string' ),
			'object_id'   => array( 'type' => 'integer' ),
			'fixable'     => array( 'type' => 'boolean' ),
			'search'      => array( 'type' => 'string' ),
			'orderby'     => array(
				'type'    => 'string',
				'enum'    => array( 'impact', 'severity', 'recent', 'object' ),
				'default' => 'impact',
			),
			'per_page'    => array(
				'type'    => 'integer',
				'default' => 50,
			),
			'page'        => array(
				'type'    => 'integer',
				'default' => 1,
			),
		);
	}

	/**
	 * List issues.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_issues( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array();

		foreach ( array_keys( $this->issue_filter_args() ) as $key ) {
			$value = $request->get_param( $key );

			if ( null !== $value && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		$result = $this->plugin->issues()->query( $filters );

		$response = new \WP_REST_Response(
			array(
				'items' => array_map( array( $this, 'shape_issue' ), $result['items'] ),
				'total' => $result['total'],
				'page'  => (int) $request->get_param( 'page' ),
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * One issue, with everything needed to fix it.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_issue( \WP_REST_Request $request ) {
		$issue = $this->plugin->issues()->find( (int) $request['id'] );

		if ( ! $issue ) {
			return new \WP_Error( 'seo_agent_not_found', __( 'Issue not found.', 'seo-automation' ), array( 'status' => 404 ) );
		}

		$shaped = $this->shape_issue( $issue );

		$fixer = '' !== (string) $issue['fixer'] ? $this->plugin->fixers()->get( (string) $issue['fixer'] ) : null;

		if ( $fixer ) {
			$shaped['fix'] = array(
				'fixer'          => $fixer->slug(),
				'label'          => $fixer->label(),
				'deterministic'  => $fixer->is_deterministic(),
				'required_input' => $fixer->required_input(),
			);
		}

		// The object's current state, so the caller can write a fix without a
		// second round trip to work out what is already there.
		if ( in_array( $issue['object_type'], array( 'post', 'term' ), true ) && $issue['object_id'] > 0 ) {
			$shaped['object'] = $this->describe_object( (string) $issue['object_type'], (int) $issue['object_id'] );
		}

		return new \WP_REST_Response( $shaped, 200 );
	}

	/**
	 * Change an issue's status — used to dismiss false positives.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_issue( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];

		$this->plugin->issues()->set_status( $id, (string) $request->get_param( 'status' ) );

		return new \WP_REST_Response( $this->plugin->issues()->find( $id ), 200 );
	}

	// -----------------------------------------------------------------
	// Fixes
	// -----------------------------------------------------------------

	/**
	 * Preview or apply a fix.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function fix_issue( \WP_REST_Request $request ): \WP_REST_Response {
		$issue_id = (int) $request['id'];
		$input    = (array) $request->get_param( 'input' );
		$runner   = $this->plugin->fix_runner();

		if ( $request->get_param( 'dry_run' ) ) {
			return new \WP_REST_Response( $runner->preview( $issue_id, $input ), 200 );
		}

		if ( $request->get_param( 'approved' ) ) {
			$input['approved'] = true;
		}

		$result = $runner->apply( $issue_id, $input );

		return new \WP_REST_Response( $result, empty( $result['ok'] ) ? 422 : 200 );
	}

	/**
	 * Preview or apply many fixes at once.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function fix_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$issue_ids = array_map( 'intval', (array) $request->get_param( 'issue_ids' ) );
		$inputs    = (array) $request->get_param( 'inputs' );
		$dry_run   = (bool) $request->get_param( 'dry_run' );

		return new \WP_REST_Response(
			$this->plugin->fix_runner()->apply_many( $issue_ids, $inputs, $dry_run ),
			200
		);
	}

	/**
	 * Re-check issues after fixing them.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function verify_fixes( \WP_REST_Request $request ): \WP_REST_Response {
		$issue_ids = array_map( 'intval', (array) $request->get_param( 'issue_ids' ) );

		return new \WP_REST_Response( $this->plugin->verifier()->verify_many( $issue_ids ), 200 );
	}

	// -----------------------------------------------------------------
	// Changes
	// -----------------------------------------------------------------

	/**
	 * The change log.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_changes( \WP_REST_Request $request ): \WP_REST_Response {
		$batch = (string) $request->get_param( 'batch' );

		$rows = '' !== $batch
			? $this->plugin->changes()->by_batch( $batch )
			: $this->plugin->changes()->recent( (int) $request->get_param( 'per_page' ) );

		return new \WP_REST_Response( $rows, 200 );
	}

	/**
	 * Undo a batch or a single change.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function revert( \WP_REST_Request $request ): \WP_REST_Response {
		$batch     = (string) $request->get_param( 'batch' );
		$change_id = (int) $request->get_param( 'change_id' );

		$runner = $this->plugin->fix_runner();

		if ( '' !== $batch ) {
			$result = $runner->revert_batch( $batch );
		} elseif ( $change_id > 0 ) {
			$result = $runner->revert_change( $change_id );
		} else {
			$result = array(
				'ok'      => false,
				'code'    => 'missing_target',
				'message' => 'Pass either a batch or a change_id.',
			);
		}

		return new \WP_REST_Response( $result, empty( $result['ok'] ) ? 422 : 200 );
	}

	// -----------------------------------------------------------------
	// Objects and settings
	// -----------------------------------------------------------------

	/**
	 * Full SEO state of one post or term.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_object( \WP_REST_Request $request ) {
		$described = $this->describe_object( (string) $request['type'], (int) $request['id'] );

		if ( null === $described ) {
			return new \WP_Error( 'seo_agent_not_found', __( 'Object not found.', 'seo-automation' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $described, 200 );
	}

	/**
	 * Current settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = Options::all();

		// The token hash is a credential; never hand it back out.
		unset( $settings['agent_token_hash'] );
		$settings['psi_api_key'] = '' !== (string) $settings['psi_api_key'] ? '(set)' : '';

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_settings( \WP_REST_Request $request ) {
		// The token hash is never writable through the API — it is a credential,
		// and accepting one over the wire would defeat the point of storing only
		// its hash.
		$writable = array_diff( array_keys( Options::defaults() ), array( 'agent_token_hash' ) );
		$updates  = array();
		$rejected = array();

		foreach ( $writable as $key ) {
			$value = $request->get_param( $key );

			if ( null === $value ) {
				continue;
			}

			$clean = $this->sanitise_setting( $key, $value );

			if ( null === $clean ) {
				$rejected[ $key ] = $value;

				continue;
			}

			$updates[ $key ] = $clean;
		}

		if ( ! empty( $rejected ) ) {
			return new \WP_Error(
				'seo_agent_invalid_setting',
				sprintf(
					/* translators: %s: comma-separated setting names. */
					__( 'These settings were rejected as invalid: %s. Nothing was saved.', 'seo-automation' ),
					implode( ', ', array_keys( $rejected ) )
				),
				array(
					'status'   => 400,
					'rejected' => array_keys( $rejected ),
				)
			);
		}

		Options::update( $updates );

		return $this->get_settings( $request );
	}

	/**
	 * Coerce and validate one setting.
	 *
	 * Returns null when the value cannot be made sense of, so a malformed
	 * request fails loudly rather than writing nonsense into an option that
	 * other code then trusts.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Supplied value.
	 *
	 * @return mixed|null Sanitised value, or null when invalid.
	 */
	private function sanitise_setting( string $key, $value ) {
		$bounded_integers = array(
			'title_min_length'       => array( 10, 200 ),
			'title_max_length'       => array( 10, 200 ),
			'description_min_length' => array( 20, 400 ),
			'description_max_length' => array( 20, 400 ),
			'min_word_count'         => array( 0, 10000 ),
			'min_internal_links'     => array( 0, 100 ),
			'max_internal_links'     => array( 1, 1000 ),
			'batch_size'             => array( 5, 500 ),
			'request_timeout'        => array( 1, 60 ),
			'link_check_concurrency' => array( 1, 20 ),
		);

		if ( isset( $bounded_integers[ $key ] ) ) {
			if ( ! is_numeric( $value ) ) {
				return null;
			}

			[ $min, $max ] = $bounded_integers[ $key ];

			return max( $min, min( $max, (int) $value ) );
		}

		switch ( $key ) {
			case 'scheduled_audits_enabled':
				return (bool) rest_sanitize_boolean( $value );

			case 'duplicate_threshold':
				if ( ! is_numeric( $value ) ) {
					return null;
				}

				return max( 0.5, min( 1.0, (float) $value ) );

			case 'autonomy':
				return in_array( $value, array( 'review', 'auto_safe', 'auto_all' ), true ) ? $value : null;

			case 'seo_adapter':
				$available = array_keys( AdapterFactory::available() );

				return ( '' === $value || in_array( $value, $available, true ) ) ? $value : null;

			case 'audit_post_types':
				if ( ! is_array( $value ) ) {
					return null;
				}

				return array_values( array_filter( array_map( 'sanitize_key', $value ), 'post_type_exists' ) );

			case 'audit_taxonomies':
				if ( ! is_array( $value ) ) {
					return null;
				}

				return array_values( array_filter( array_map( 'sanitize_key', $value ), 'taxonomy_exists' ) );

			case 'auto_fixers':
				if ( ! is_array( $value ) ) {
					return null;
				}

				$known = array_keys( $this->plugin->fixers()->all() );

				return array_values( array_intersect( array_map( 'sanitize_key', $value ), $known ) );

			case 'psi_api_key':
			case 'target_locale':
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
	}

	// -----------------------------------------------------------------
	// Shaping
	// -----------------------------------------------------------------

	/**
	 * Trim an issue row down to what a caller needs.
	 *
	 * @param array<string,mixed> $issue Issue row.
	 *
	 * @return array<string,mixed>
	 */
	private function shape_issue( array $issue ): array {
		return array(
			'id'           => (int) $issue['id'],
			'code'         => $issue['code'],
			'checker'      => $issue['checker'],
			'severity'     => $issue['severity'],
			'status'       => $issue['status'],
			'impact'       => (int) $issue['impact'],
			'title'        => $issue['title'],
			'detail'       => $issue['detail'],
			'object_type'  => $issue['object_type'],
			'object_id'    => (int) $issue['object_id'],
			'object_label' => $issue['object_label'],
			'url'          => $issue['url'],
			'evidence'     => $issue['evidence'],
			'fixer'        => $issue['fixer'],
			'fix_mode'     => $issue['fix_mode'],
			'fix_payload'  => $issue['fix_payload'],
			'first_seen'   => $issue['first_seen'],
			'last_seen'    => $issue['last_seen'],
		);
	}

	/**
	 * Everything about one post or term that bears on its SEO.
	 *
	 * @param string $type 'post' or 'term'.
	 * @param int    $id   Object ID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function describe_object( string $type, int $id ): ?array {
		$seo = $this->plugin->seo();

		if ( 'term' === $type ) {
			$term = get_term( $id );

			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}

			return array(
				'type'        => 'term',
				'id'          => $id,
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'count'       => (int) $term->count,
				'url'         => (string) get_term_link( $term ),
				'description' => (string) $term->description,
				'seo'         => $this->seo_state( $seo, 'term', $id ),
			);
		}

		$post = get_post( $id );

		if ( ! $post ) {
			return null;
		}

		return array(
			'type'         => 'post',
			'id'           => $id,
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'title'        => $post->post_title,
			'slug'         => $post->post_name,
			'url'          => (string) get_permalink( $post ),
			'edit_url'     => get_edit_post_link( $id, 'raw' ),
			'excerpt'      => (string) $post->post_excerpt,
			'word_count'   => Text::word_count( (string) $post->post_content ),
			'content'      => (string) $post->post_content,
			'plain_text'   => Text::truncate( Text::plain( (string) $post->post_content ), 4000, '…' ),
			'published'    => $post->post_date_gmt,
			'modified'     => $post->post_modified_gmt,
			'seo'          => $this->seo_state( $seo, 'post', $id ),
			'schema_types' => $seo->schema_types( $id ),
		);
	}

	/**
	 * The SEO fields, both stored and effective.
	 *
	 * @param SeoAdapterInterface $seo  Adapter.
	 * @param string              $type Object type.
	 * @param int                 $id   Object ID.
	 *
	 * @return array<string,mixed>
	 */
	private function seo_state( SeoAdapterInterface $seo, string $type, int $id ): array {
		return array(
			'adapter'              => $seo->slug(),
			'stored_title'         => $seo->get( SeoAdapterInterface::FIELD_TITLE, $type, $id ),
			'stored_description'   => $seo->get( SeoAdapterInterface::FIELD_DESCRIPTION, $type, $id ),
			'effective_title'      => $seo->effective_title( $type, $id ),
			'effective_description' => $seo->effective_description( $type, $id ),
			'canonical'            => $seo->get( SeoAdapterInterface::FIELD_CANONICAL, $type, $id ),
			'robots'               => $seo->get( SeoAdapterInterface::FIELD_ROBOTS, $type, $id ),
			'focus_keyword'        => $seo->get( SeoAdapterInterface::FIELD_FOCUS_KEYWORD, $type, $id ),
			'noindex'              => $seo->is_noindex( $type, $id ),
		);
	}
}
