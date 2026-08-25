<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$logs = Logger::get_logs();
?>
<div class="wrap theblog-wrap">
	<h1><?php esc_html_e( 'Logs', 'seo-automation' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all logs?', 'seo-automation' ) ); ?>');">
		<?php wp_nonce_field( 'theblog_clear_logs' ); ?>
		<input type="hidden" name="action" value="theblog_clear_logs" />
		<button type="submit" class="button"><?php esc_html_e( 'Clear Logs', 'seo-automation' ); ?></button>
	</form>

	<table class="widefat striped theblog-logs-table">
		<thead>
			<tr>
				<th style="width:160px;"><?php esc_html_e( 'Time', 'seo-automation' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Level', 'seo-automation' ); ?></th>
				<th><?php esc_html_e( 'Message', 'seo-automation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No log entries yet.', 'seo-automation' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $logs as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( $entry['time'] ); ?></td>
					<td><span class="theblog-log-level theblog-log-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['level'] ); ?></span></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
