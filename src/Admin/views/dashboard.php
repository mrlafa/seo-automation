<?php
/**
 * Dashboard screen.
 *
 * @package SEOAgent
 *
 * @var array<string,mixed>|null      $audit       Latest completed audit.
 * @var array<string,int>             $by_severity Open issue counts.
 * @var int                           $fixable     Open issues with a fixer.
 * @var array{items:array,total:int}  $top         Highest-impact open issues.
 */

use SEOAgent\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$score = $audit['score'] ?? null;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'SEO Agent', 'seo-automation' ); ?></h1>

	<p>
		<?php AdminMenu::form_open( 'run_audit' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run audit now', 'seo-automation' ); ?></button>
		</form>
		<?php if ( $audit ) : ?>
			<span style="margin-left:12px;color:#646970">
				<?php
				printf(
					/* translators: 1: audit ID, 2: finish time. */
					esc_html__( 'Last completed: audit #%1$d at %2$s UTC', 'seo-automation' ),
					(int) $audit['id'],
					esc_html( (string) $audit['finished_at'] )
				);
				?>
			</span>
		<?php endif; ?>
	</p>

	<div class="seoagent-cards">
		<div class="seoagent-card">
			<h3><?php esc_html_e( 'Health score', 'seo-automation' ); ?></h3>
			<div class="seoagent-score"><?php echo null === $score ? '&mdash;' : (int) $score; ?></div>
		</div>

		<?php foreach ( array( 'critical', 'high', 'medium', 'low', 'info' ) as $severity ) : ?>
			<div class="seoagent-card">
				<h3><?php echo esc_html( ucfirst( $severity ) ); ?></h3>
				<div class="value"><?php echo (int) ( $by_severity[ $severity ] ?? 0 ); ?></div>
			</div>
		<?php endforeach; ?>

		<div class="seoagent-card">
			<h3><?php esc_html_e( 'Fixable now', 'seo-automation' ); ?></h3>
			<div class="value"><?php echo (int) $fixable; ?></div>
		</div>
	</div>

	<?php if ( ! $audit ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No audit has completed yet. Run one to see where the site stands.', 'seo-automation' ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Highest impact right now', 'seo-automation' ); ?></h2>

	<?php if ( empty( $top['items'] ) ) : ?>
		<p><?php esc_html_e( 'Nothing open. Either the site is in good shape or no audit has run.', 'seo-automation' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:90px"><?php esc_html_e( 'Severity', 'seo-automation' ); ?></th>
					<th><?php esc_html_e( 'Issue', 'seo-automation' ); ?></th>
					<th style="width:25%"><?php esc_html_e( 'Where', 'seo-automation' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $top['items'] as $issue ) : ?>
					<tr>
						<td>
							<span class="seoagent-sev seoagent-sev-<?php echo esc_attr( $issue['severity'] ); ?>">
								<?php echo esc_html( $issue['severity'] ); ?>
							</span>
						</td>
						<td>
							<strong><?php echo esc_html( $issue['title'] ); ?></strong>
							<p class="seoagent-detail"><?php echo esc_html( $issue['detail'] ); ?></p>
						</td>
						<td>
							<?php if ( ! empty( $issue['url'] ) ) : ?>
								<a href="<?php echo esc_url( $issue['url'] ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $issue['object_label'] ?: $issue['url'] ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $issue['object_label'] ?: __( 'Site-wide', 'seo-automation' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-issues' ) ); ?>">
				<?php
				printf(
					/* translators: %d: total open issues. */
					esc_html__( 'View all %d open issues', 'seo-automation' ),
					(int) $top['total']
				);
				?>
			</a>
		</p>
	<?php endif; ?>
</div>
