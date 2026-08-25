<?php
/**
 * WP-CLI commands.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Cli;

use SEOAgent\Plugin;
use SEOAgent\Support\Options;
use WP_CLI;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * `wp seo-agent <command>`.
 *
 * The CLI is the same engine as the REST API, which matters for a coding
 * agent: anything it can do over HTTP it can also do over SSH, and the output
 * is JSON on request so it pipes straight into other tooling.
 */
class Commands {

	/**
	 * Register the command namespace.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'seo-agent', self::class );
	}

	/**
	 * Run an audit.
	 *
	 * ## OPTIONS
	 *
	 * [--scopes=<scopes>]
	 * : Comma-separated checker slugs or group names. Default: everything applicable.
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types to cover.
	 *
	 * [--budget=<seconds>]
	 * : Maximum seconds to spend. Default 300.
	 *
	 * [--format=<format>]
	 * : table or json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seo-agent audit
	 *     wp seo-agent audit --scopes=meta_title,meta_description
	 *     wp seo-agent audit --scopes=commerce --post-types=product --format=json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function audit( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();
		$scopes = $this->csv( $assoc_args['scopes'] ?? '' );

		$run_args = array( 'trigger' => 'cli' );

		$post_types = $this->csv( $assoc_args['post-types'] ?? '' );
		if ( ! empty( $post_types ) ) {
			$run_args['audit_post_types'] = $post_types;
		}

		$runner   = $plugin->audit_runner();
		$audit_id = $runner->start( $scopes, $run_args );

		WP_CLI::log( sprintf( 'Started audit #%d.', $audit_id ) );

		$budget   = (float) ( $assoc_args['budget'] ?? 300 );
		$deadline = microtime( true ) + $budget;
		$report   = array();

		do {
			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0 ) {
				WP_CLI::warning( 'Time budget exhausted; the audit is resumable.' );
				break;
			}

			$report = $runner->step( $audit_id, min( 20.0, $remaining ) );

			WP_CLI::log(
				sprintf(
					'  %d%% — %d of %d checkers done',
					$report['progress']['percent'] ?? 0,
					$report['progress']['checkers_done'] ?? 0,
					$report['progress']['checkers_total'] ?? 0
				)
			);
		} while ( empty( $report['complete'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );

			return;
		}

		$totals = $report['totals']['by_severity'] ?? array();

		WP_CLI::success(
			sprintf(
				'Audit #%d complete. Score %s/100.',
				$audit_id,
				null === $report['score'] ? '?' : $report['score']
			)
		);

		$rows = array();
		foreach ( array( 'critical', 'high', 'medium', 'low', 'info' ) as $severity ) {
			$rows[] = array(
				'severity' => $severity,
				'open'     => (int) ( $totals[ $severity ] ?? 0 ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'severity', 'open' ) );
	}

	/**
	 * List open issues.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<severity>]
	 * : Comma-separated severities.
	 *
	 * [--code=<code>]
	 * : Filter by issue code.
	 *
	 * [--checker=<checker>]
	 * : Filter by checker slug.
	 *
	 * [--fixable]
	 * : Only issues that have a fixer.
	 *
	 * [--limit=<limit>]
	 * : Rows to show. Default 30.
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default table.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function issues( array $args, array $assoc_args ): void {
		$filters = array(
			'status'   => array( 'open' ),
			'per_page' => (int) ( $assoc_args['limit'] ?? 30 ),
		);

		if ( ! empty( $assoc_args['severity'] ) ) {
			$filters['severity'] = $this->csv( $assoc_args['severity'] );
		}
		foreach ( array( 'code', 'checker' ) as $key ) {
			if ( ! empty( $assoc_args[ $key ] ) ) {
				$filters[ $key ] = $assoc_args[ $key ];
			}
		}
		if ( isset( $assoc_args['fixable'] ) ) {
			$filters['fixable'] = true;
		}

		$result = Plugin::instance()->issues()->query( $filters );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );

			return;
		}

		$rows = array();
		foreach ( $result['items'] as $issue ) {
			$rows[] = array(
				'id'       => $issue['id'],
				'severity' => $issue['severity'],
				'code'     => $issue['code'],
				'object'   => mb_strimwidth( (string) $issue['object_label'], 0, 40, '…' ),
				'fixer'    => (string) $issue['fixer'],
				'title'    => mb_strimwidth( (string) $issue['title'], 0, 50, '…' ),
			);
		}

		WP_CLI::log( sprintf( '%d open issues (showing %d).', $result['total'], count( $rows ) ) );
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'severity', 'code', 'object', 'fixer', 'title' ) );
	}

	/**
	 * Preview or apply a fix.
	 *
	 * ## OPTIONS
	 *
	 * <issue-id>
	 * : The issue to fix.
	 *
	 * [--apply]
	 * : Actually write the change. Without this flag the fix is only previewed.
	 *
	 * [--input=<json>]
	 * : JSON object of fixer input, e.g. '{"value":"A better title"}'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seo-agent fix 412
	 *     wp seo-agent fix 412 --apply --input='{"value":"Merino Wool Socks | Northfield"}'
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function fix( array $args, array $assoc_args ): void {
		$issue_id = (int) ( $args[0] ?? 0 );

		if ( $issue_id <= 0 ) {
			WP_CLI::error( 'Pass an issue ID.' );
		}

		$input = array();

		if ( ! empty( $assoc_args['input'] ) ) {
			$decoded = json_decode( (string) $assoc_args['input'], true );

			if ( ! is_array( $decoded ) ) {
				WP_CLI::error( '--input must be a JSON object.' );
			}

			$input = $decoded;
		}

		$runner = Plugin::instance()->fix_runner();
		$apply  = isset( $assoc_args['apply'] );

		if ( $apply ) {
			$input['approved'] = true;
		}

		$result = $apply ? $runner->apply( $issue_id, $input ) : $runner->preview( $issue_id, $input );

		if ( empty( $result['ok'] ) ) {
			WP_CLI::error( sprintf( '[%s] %s', $result['code'] ?? 'error', $result['message'] ?? 'Failed.' ) );
		}

		foreach ( (array) ( $result['changes'] ?? array() ) as $change ) {
			WP_CLI::log( sprintf( '%s #%d %s', $change['object_type'], $change['object_id'], $change['field'] ) );
			WP_CLI::log( sprintf( '  - %s', $this->preview_value( $change['before'] ) ) );
			WP_CLI::log( sprintf( '  + %s', $this->preview_value( $change['after'] ) ) );
		}

		if ( ! $apply ) {
			WP_CLI::success( sprintf( 'Previewed %d change(s). Re-run with --apply to write.', $result['count'] ?? 0 ) );

			return;
		}

		WP_CLI::success( sprintf( 'Applied %d change(s). Batch %s.', $result['count'] ?? 0, $result['batch'] ?? '' ) );
	}

	/**
	 * Re-check issues after fixing them.
	 *
	 * ## OPTIONS
	 *
	 * <issue-ids>
	 * : Comma-separated issue IDs.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$ids = array_map( 'intval', $this->csv( $args[0] ?? '' ) );

		if ( empty( $ids ) ) {
			WP_CLI::error( 'Pass one or more issue IDs.' );
		}

		$result = Plugin::instance()->verifier()->verify_many( $ids );

		foreach ( $result['results'] as $row ) {
			if ( empty( $row['ok'] ) ) {
				WP_CLI::warning( sprintf( 'Issue %d: %s', $row['issue_id'] ?? 0, $row['message'] ?? 'failed' ) );
				continue;
			}

			WP_CLI::log(
				sprintf(
					'Issue %d (%s): %s',
					$row['issue_id'],
					$row['code'],
					! empty( $row['resolved'] ) ? 'resolved' : 'still present'
				)
			);
		}

		WP_CLI::success( sprintf( '%d of %d resolved.', $result['resolved'], $result['total'] ) );
	}

	/**
	 * Undo applied changes.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : The batch UUID reported when the fix was applied.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function revert( array $args, array $assoc_args ): void {
		$batch = (string) ( $args[0] ?? '' );

		if ( '' === $batch ) {
			WP_CLI::error( 'Pass a batch UUID.' );
		}

		$result = Plugin::instance()->fix_runner()->revert_batch( $batch );

		if ( empty( $result['ok'] ) ) {
			foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
				WP_CLI::warning( $error['message'] ?? 'Unknown error.' );
			}
		}

		WP_CLI::success( sprintf( 'Reverted %d change(s).', $result['reverted'] ?? 0 ) );
	}

	/**
	 * Show or set an agent token.
	 *
	 * The token is stored hashed and acts as a second factor on top of normal
	 * WordPress authentication.
	 *
	 * ## OPTIONS
	 *
	 * [--generate]
	 * : Generate and print a new token. This is the only time it is shown.
	 *
	 * [--clear]
	 * : Remove the token requirement.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function token( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['clear'] ) ) {
			Options::update( array( 'agent_token_hash' => '' ) );
			WP_CLI::success( 'Token requirement removed.' );

			return;
		}

		if ( isset( $assoc_args['generate'] ) ) {
			$token = wp_generate_password( 48, false, false );
			Options::update( array( 'agent_token_hash' => hash( 'sha256', $token ) ) );

			WP_CLI::success( 'Token generated. Store it now — it is not recoverable.' );
			WP_CLI::line( $token );

			return;
		}

		$configured = '' !== (string) Options::get( 'agent_token_hash', '' );
		WP_CLI::log( $configured ? 'A token is configured.' : 'No token configured.' );
	}

	/**
	 * Split a comma-separated flag into a clean array.
	 *
	 * @param string $value Raw flag value.
	 *
	 * @return string[]
	 */
	private function csv( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Shorten a value for terminal display.
	 *
	 * @param string|null $value Value.
	 */
	private function preview_value( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '(empty)';
		}

		$flat = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );

		return mb_strimwidth( $flat, 0, 160, '…' );
	}
}
