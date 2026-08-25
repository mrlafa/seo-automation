<?php
/**
 * Change log screen.
 *
 * @package SEOAgent
 *
 * @var array<int,array<string,mixed>> $changes Recent changes.
 */

use SEOAgent\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$grouped = array();
foreach ( $changes as $change ) {
	$grouped[ (string) $change['batch'] ][] = $change;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Change log', 'seo-automation' ); ?></h1>

	<p><?php esc_html_e( 'Every write this plugin has made, with the value it replaced. Anything here can be put back exactly as it was.', 'seo-automation' ); ?></p>

	<?php if ( empty( $grouped ) ) : ?>
		<p><?php esc_html_e( 'Nothing has been changed yet.', 'seo-automation' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $grouped as $batch => $rows ) : ?>
		<?php
		$applied  = array_filter( $rows, static fn( $row ) => 'applied' === $row['status'] );
		$when     = (string) ( $rows[0]['applied_at'] ?? '' );
		$user     = get_userdata( (int) ( $rows[0]['applied_by'] ?? 0 ) );
		?>
		<h2 style="margin-top:28px;font-size:14px">
			<?php
			printf(
				/* translators: 1: number of changes, 2: timestamp, 3: user name. */
				esc_html__( '%1$d change(s) at %2$s UTC by %3$s', 'seo-automation' ),
				count( $rows ),
				esc_html( $when ),
				esc_html( $user ? $user->display_name : __( 'the agent', 'seo-automation' ) )
			);
			?>
			<?php if ( ! empty( $applied ) ) : ?>
				<?php AdminMenu::form_open( 'revert_batch' ); ?>
					<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>" />
					<button type="submit" class="button button-small"><?php esc_html_e( 'Revert this batch', 'seo-automation' ); ?></button>
				</form>
			<?php endif; ?>
		</h2>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th style="width:22%"><?php esc_html_e( 'Target', 'seo-automation' ); ?></th>
					<th><?php esc_html_e( 'Change', 'seo-automation' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Status', 'seo-automation' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $row['note'] ?: $row['field'] ); ?>
						<br /><code style="font-size:11px"><?php echo esc_html( $row['field'] ); ?></code>
					</td>
					<td class="seoagent-diff">
						<div class="before">− <?php echo esc_html( mb_strimwidth( (string) $row['before_value'], 0, 300, '…' ) ?: '(empty)' ); ?></div>
						<div class="after">+ <?php echo esc_html( mb_strimwidth( (string) $row['after_value'], 0, 300, '…' ) ?: '(empty)' ); ?></div>
					</td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
</div>
