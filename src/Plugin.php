<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package SEOAgent
 */

namespace SEOAgent;

use SEOAgent\Admin\AdminMenu;
use SEOAgent\Audit\AuditRunner;
use SEOAgent\Audit\CheckerRegistry;
use SEOAgent\Audit\Verifier;
use SEOAgent\Cli\Commands;
use SEOAgent\Database\AuditRepository;
use SEOAgent\Database\ChangeRepository;
use SEOAgent\Database\IssueRepository;
use SEOAgent\Database\Schema;
use SEOAgent\Fix\FixRunner;
use SEOAgent\Fix\FixerRegistry;
use SEOAgent\Rest\RestController;
use SEOAgent\Seo\AdapterFactory;
use SEOAgent\Seo\LlmsTxt;
use SEOAgent\Seo\SchemaRenderer;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Main plugin class. Wires services together and registers WordPress hooks.
 */
final class Plugin {

	public const CAPABILITY = 'manage_seo_agent';
	public const CRON_HOOK  = 'seo_agent_scheduled_audit';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<string,mixed> Lazily built services. */
	private $services = array();

	/** @var bool */
	private $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks. Safe to call once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// The textdomain is loaded once, by the bootstrap file. Loading it a
		// second time here would be a no-op at best and, when the two calls
		// disagree on the domain name, silently leave half the plugin
		// untranslated — which is what happened before the rename.

		// Schema upgrades on version bump (covers plugin updates that skip activation).
		add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );

		add_action( 'rest_api_init', array( $this->rest(), 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_audit' ) );

		LlmsTxt::register();
		SchemaRenderer::register();

		if ( is_admin() ) {
			$this->admin()->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		/**
		 * Fires once every SEO Agent service is available.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'seo_agent_booted', $this );
	}

	/**
	 * Activation: create tables, grant capability, schedule cron.
	 */
	public static function activate(): void {
		Schema::install();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		update_option( 'seo_agent_db_version', Schema::VERSION, false );
	}

	/**
	 * Deactivation: unschedule cron. Tables and data are preserved.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run dbDelta when the stored schema version is behind.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( 'seo_agent_db_version' ) === Schema::VERSION ) {
			return;
		}

		Schema::install();
		update_option( 'seo_agent_db_version', Schema::VERSION, false );
	}

	/**
	 * Cron entry point: run a full audit in the background.
	 */
	public function run_scheduled_audit(): void {
		if ( ! Options::get( 'scheduled_audits_enabled', true ) ) {
			return;
		}

		$runner = $this->audit_runner();

		// Resume before starting. A full audit on a real site rarely fits in one
		// cron slot — the network-bound checks alone will not — so starting a
		// fresh audit every time would abandon each one part-finished and the
		// site would never see a completed report.
		$audit_id = $this->pending_audit_id();

		if ( null === $audit_id ) {
			$audit_id = $runner->start( array(), array( 'trigger' => 'cron' ) );
		}

		$report = $runner->run_to_completion( $audit_id, $this->cron_budget() );

		// Still going: come back shortly rather than waiting for tomorrow.
		if ( empty( $report['complete'] ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * An audit that was started but never finished, if there is one.
	 *
	 * Anything left running for over a day is treated as dead rather than
	 * resumed, so a checker that wedged cannot block audits forever.
	 */
	private function pending_audit_id(): ?int {
		foreach ( $this->audits()->recent( 5 ) as $audit ) {
			if ( ! in_array( $audit['status'], array( 'queued', 'running' ), true ) ) {
				continue;
			}

			$started = strtotime( (string) ( $audit['started_at'] ?: $audit['created_at'] ) );

			if ( $started && ( time() - $started ) > DAY_IN_SECONDS ) {
				$this->audits()->mark_failed( (int) $audit['id'], 'Abandoned: still unfinished after 24 hours.' );

				continue;
			}

			return (int) $audit['id'];
		}

		return null;
	}

	/**
	 * Seconds a single cron slot may spend auditing.
	 *
	 * Kept well inside PHP's own limit so the process ends by choice rather
	 * than being killed mid-write.
	 */
	private function cron_budget(): float {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return 60.0;
		}

		return (float) max( 15, min( 60, $limit - 10 ) );
	}

	// ---------------------------------------------------------------------
	// Service accessors
	// ---------------------------------------------------------------------

	public function audits(): AuditRepository {
		return $this->service( AuditRepository::class, static fn() => new AuditRepository() );
	}

	public function issues(): IssueRepository {
		return $this->service( IssueRepository::class, static fn() => new IssueRepository() );
	}

	public function changes(): ChangeRepository {
		return $this->service( ChangeRepository::class, static fn() => new ChangeRepository() );
	}

	public function seo(): SeoAdapterInterface {
		return $this->service( SeoAdapterInterface::class, static fn() => AdapterFactory::make() );
	}

	public function checkers(): CheckerRegistry {
		return $this->service( CheckerRegistry::class, static fn() => CheckerRegistry::withDefaults() );
	}

	public function fixers(): FixerRegistry {
		return $this->service( FixerRegistry::class, static fn() => FixerRegistry::withDefaults() );
	}

	public function audit_runner(): AuditRunner {
		return $this->service(
			AuditRunner::class,
			fn() => new AuditRunner( $this->checkers(), $this->audits(), $this->issues(), $this->seo() )
		);
	}

	public function fix_runner(): FixRunner {
		return $this->service(
			FixRunner::class,
			fn() => new FixRunner( $this->fixers(), $this->issues(), $this->changes(), $this->seo() )
		);
	}

	public function verifier(): Verifier {
		return $this->service(
			Verifier::class,
			fn() => new Verifier( $this->checkers(), $this->issues(), $this->seo() )
		);
	}

	public function rest(): RestController {
		return $this->service( RestController::class, fn() => new RestController( $this ) );
	}

	public function admin(): AdminMenu {
		return $this->service( AdminMenu::class, fn() => new AdminMenu( $this ) );
	}

	/**
	 * Resolve (and memoise) a service.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory invoked on first use.
	 *
	 * @return mixed
	 */
	private function service( string $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}

	/**
	 * Replace a service. Used by tests and by integrations that swap adapters.
	 *
	 * @param string $key      Service key.
	 * @param mixed  $instance Replacement instance.
	 */
	public function set( string $key, $instance ): void {
		$this->services[ $key ] = $instance;
	}
}
