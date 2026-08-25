<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$settings   = Settings::all();
$topics     = CPT_Topic::get_all( 200 );
$counts     = array_fill_keys( array_keys( CPT_Topic::statuses() ), 0 );
foreach ( $topics as $t ) {
	$status = CPT_Topic::get_status( $t->ID );
	if ( isset( $counts[ $status ] ) ) {
		$counts[ $status ]++;
	}
}
$pending_review = count( Review_Queue::get_pending_posts( 200 ) );
$provider       = AI_Client::text_provider();
$provider_ready = $provider && $provider->is_configured();
?>
<div class="wrap theblog-wrap">
	<h1><?php esc_html_e( 'TheBlog Automation', 'seo-automation' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Research, write, optimize, and publish from one system. Run on autopilot or stay hands-on.', 'seo-automation' ); ?></p>

	<?php if ( ! $provider_ready ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			printf(
				/* translators: %s settings link */
				esc_html__( 'No AI provider is configured yet. Add an API key on the %s screen to start generating content.', 'seo-automation' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=theblog-settings' ) ) . '">' . esc_html__( 'Settings', 'seo-automation' ) . '</a>'
			);
			?>
		</p></div>
	<?php endif; ?>

	<div class="theblog-cards">
		<div class="theblog-card">
			<span class="theblog-card-number"><?php echo (int) $counts['queued']; ?></span>
			<span class="theblog-card-label"><?php esc_html_e( 'Queued Topics', 'seo-automation' ); ?></span>
		</div>
		<div class="theblog-card">
			<span class="theblog-card-number"><?php echo (int) $pending_review; ?></span>
			<span class="theblog-card-label"><?php esc_html_e( 'Awaiting Your Review', 'seo-automation' ); ?></span>
		</div>
		<div class="theblog-card">
			<span class="theblog-card-number"><?php echo (int) $counts['published']; ?></span>
			<span class="theblog-card-label"><?php esc_html_e( 'Published by TheBlog', 'seo-automation' ); ?></span>
		</div>
		<div class="theblog-card">
			<span class="theblog-card-number"><?php echo (int) $counts['error']; ?></span>
			<span class="theblog-card-label"><?php esc_html_e( 'Errors', 'seo-automation' ); ?></span>
		</div>
	</div>

	<div class="theblog-panel">
		<h2><?php esc_html_e( 'Status', 'seo-automation' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'AI Provider', 'seo-automation' ); ?></td>
					<td><?php echo esc_html( $provider ? $provider->get_name() : '-' ); ?> — <?php echo $provider_ready ? esc_html__( 'Configured', 'seo-automation' ) : esc_html__( 'Not configured', 'seo-automation' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Autopilot', 'seo-automation' ); ?></td>
					<td><?php echo $settings['autopilot_enabled'] ? esc_html__( 'Enabled', 'seo-automation' ) : esc_html__( 'Disabled', 'seo-automation' ); ?> (<?php echo esc_html( $settings['autopilot_interval'] ); ?>)</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Approval Gate', 'seo-automation' ); ?></td>
					<td><?php esc_html_e( 'Always on — every generated post lands as "Pending Review" until you approve it.', 'seo-automation' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'SEO Integration', 'seo-automation' ); ?></td>
					<td>
						<?php
						if ( SEO_Integration::is_yoast_active() ) {
							esc_html_e( 'Yoast SEO detected — metadata is pushed there.', 'seo-automation' );
						} elseif ( SEO_Integration::is_rankmath_active() ) {
							esc_html_e( 'Rank Math detected — metadata is pushed there.', 'seo-automation' );
						} else {
							esc_html_e( 'No SEO plugin detected — using built-in fallback meta tags.', 'seo-automation' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=theblog-topics' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add a Topic', 'seo-automation' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=theblog-review-queue' ) ); ?>" class="button"><?php esc_html_e( 'Go to Review Queue', 'seo-automation' ); ?></a>
	</p>
</div>
