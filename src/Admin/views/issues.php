<?php
/**
 * Issue queue screen.
 *
 * @package SEOAgent
 *
 * @var array{items:array,total:int}                    $result   Query result.
 * @var array<string,\SEOAgent\Audit\CheckerInterface>   $checkers Registered checkers.
 * @var \SEOAgent\Fix\FixerRegistry                      $fixers   Registered fixers.
 * @var array<string,mixed>                              $filters  Active filters.
 */

use SEOAgent\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$current_status   = $filters['status'][0] ?? 'open';
$current_severity = $filters['severity'][0] ?? '';
$current_checker  = $filters['checker'] ?? '';
$page             = (int) ( $filters['page'] ?? 1 );
$total_pages      = (int) ceil( $result['total'] / 50 );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Issues', 'seo-automation' ); ?></h1>

	<form method="get" style="margin:16px 0">
		<input type="hidden" name="page" value="seo-agent-issues" />

		<select name="status">
			<?php foreach ( array( 'open', 'fixed', 'ignored', 'failed' ) as $status ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current_status, $status ); ?>>
					<?php echo esc_html( ucfirst( $status ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="severity">
			<option value=""><?php esc_html_e( 'Any severity', 'seo-automation' ); ?></option>
			<?php foreach ( array( 'critical', 'high', 'medium', 'low', 'info' ) as $severity ) : ?>
				<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( $current_severity, $severity ); ?>>
					<?php echo esc_html( ucfirst( $severity ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="checker">
			<option value=""><?php esc_html_e( 'Any check', 'seo-automation' ); ?></option>
			<?php foreach ( $checkers as $slug => $checker ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_checker, $slug ); ?>>
					<?php echo esc_html( $checker->label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Search issues', 'seo-automation' ); ?>" />

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'seo-automation' ); ?></button>
	</form>

	<p>
		<?php
		printf(
			/* translators: %d: number of issues. */
			esc_html__( '%d issues match.', 'seo-automation' ),
			(int) $result['total']
		);
		?>
	</p>

	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th style="width:80px"><?php esc_html_e( 'Severity', 'seo-automation' ); ?></th>
				<th><?php esc_html_e( 'Issue', 'seo-automation' ); ?></th>
				<th style="width:20%"><?php esc_html_e( 'Where', 'seo-automation' ); ?></th>
				<th style="width:30%"><?php esc_html_e( 'Fix', 'seo-automation' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'Nothing matches those filters.', 'seo-automation' ); ?></td></tr>
		<?php endif; ?>

		<?php
		foreach ( $result['items'] as $issue ) :
			$fixer      = '' !== (string) $issue['fixer'] ? $fixers->get( (string) $issue['fixer'] ) : null;
			$suggestion = (string) ( $issue['fix_payload']['suggestion'] ?? '' );
			$needs_text = $fixer && array_key_exists( 'value', $fixer->required_input() );
			?>
			<tr>
				<td>
					<span class="seoagent-sev seoagent-sev-<?php echo esc_attr( $issue['severity'] ); ?>">
						<?php echo esc_html( $issue['severity'] ); ?>
					</span>
				</td>
				<td>
					<strong><?php echo esc_html( $issue['title'] ); ?></strong>
					<p class="seoagent-detail"><?php echo esc_html( $issue['detail'] ); ?></p>
					<code style="font-size:11px"><?php echo esc_html( $issue['code'] ); ?></code>
				</td>
				<td>
					<?php if ( ! empty( $issue['url'] ) ) : ?>
						<a href="<?php echo esc_url( $issue['url'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $issue['object_label'] ?: $issue['url'] ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $issue['object_label'] ?: __( 'Site-wide', 'seo-automation' ) ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $issue['evidence']['edit_url'] ) ) : ?>
						<br /><a href="<?php echo esc_url( $issue['evidence']['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'seo-automation' ); ?></a>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( ! $fixer ) : ?>
						<em><?php esc_html_e( 'Needs a person — no safe automatic fix.', 'seo-automation' ); ?></em>
					<?php elseif ( 'open' !== $issue['status'] ) : ?>
						<em><?php echo esc_html( ucfirst( (string) $issue['status'] ) ); ?></em>
					<?php else : ?>
						<?php AdminMenu::form_open( 'apply_fix' ); ?>
							<input type="hidden" name="issue_id" value="<?php echo (int) $issue['id']; ?>" />

							<?php if ( $needs_text ) : ?>
								<textarea name="value" rows="2" style="width:100%" placeholder="<?php esc_attr_e( 'Value to write', 'seo-automation' ); ?>"><?php echo esc_textarea( $suggestion ); ?></textarea>
							<?php endif; ?>

							<button type="submit" class="button button-small button-primary">
								<?php echo esc_html( $fixer->label() ); ?>
							</button>
						</form>

						<?php AdminMenu::form_open( 'ignore_issue' ); ?>
							<input type="hidden" name="issue_id" value="<?php echo (int) $issue['id']; ?>" />
							<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'seo-automation' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns escaped markup.
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'total'   => $total_pages,
					'current' => $page,
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
