<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- This template is include()d from inside a class method, so the variables below are function-scoped locals, not globals.

$posts = Review_Queue::get_pending_posts( 50 );
?>
<div class="wrap theblog-wrap">
	<h1><?php esc_html_e( 'Review Queue', 'seo-automation' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every AI-generated draft lands here first. Nothing publishes until you approve it.', 'seo-automation' ); ?></p>

	<?php if ( empty( $posts ) ) : ?>
		<div class="theblog-panel"><p><?php esc_html_e( 'Nothing waiting for review right now.', 'seo-automation' ); ?></p></div>
	<?php endif; ?>

	<?php foreach ( $posts as $post ) :
		$focus_kw = get_post_meta( $post->ID, '_theblog_primary_keyword', true );
		if ( ! $focus_kw ) {
			$focus_kw = get_post_meta( $post->ID, '_theblog_focus_keyword', true );
		}
		if ( ! $focus_kw ) {
			$focus_kw = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
		}
		if ( ! $focus_kw ) {
			$focus_kw = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
		}
		$density        = get_post_meta( $post->ID, '_theblog_keyword_density', true );
		$content_source = get_post_meta( $post->ID, '_theblog_content_source', true );
		$product_id     = (int) get_post_meta( $post->ID, '_theblog_product_id', true );
		$faqs           = get_post_meta( $post->ID, '_theblog_faqs', true );
		$seo_analysis   = get_post_meta( $post->ID, '_theblog_seo_analysis', true );
		$thumbnail      = get_the_post_thumbnail( $post->ID, array( 80, 80 ) );
		?>
		<div class="theblog-panel theblog-review-item">
			<div class="theblog-review-thumb">
				<?php
				// $thumbnail is an <img> tag from core; wp_kses_post keeps the
				// markup while stripping anything a filter may have injected.
				echo $thumbnail
					? wp_kses_post( $thumbnail )
					: '<div class="theblog-review-thumb-placeholder"></div>';
				?>
			</div>
			<div class="theblog-review-body">
				<h2><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></h2>
				<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ) ); ?></p>
				<p class="theblog-meta-row">
					<?php if ( 'product_url' === $content_source ) : ?>
						<span><strong><?php esc_html_e( 'Source:', 'seo-automation' ); ?></strong> <?php esc_html_e( 'WooCommerce Product', 'seo-automation' ); ?><?php if ( $product_id ) : ?> (<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php esc_html_e( 'view product', 'seo-automation' ); ?></a>)<?php endif; ?></span>
					<?php endif; ?>
					<?php if ( $focus_kw ) : ?><span><strong><?php esc_html_e( 'Primary keyword:', 'seo-automation' ); ?></strong> <?php echo esc_html( $focus_kw ); ?></span><?php endif; ?>
					<?php if ( '' !== $density ) : ?><span><strong><?php esc_html_e( 'Keyword density:', 'seo-automation' ); ?></strong> <?php echo esc_html( $density ); ?>%</span><?php endif; ?>
					<span><strong><?php esc_html_e( 'Author:', 'seo-automation' ); ?></strong> <?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></span>
				</p>

				<?php if ( ! empty( $faqs ) && is_array( $faqs ) ) : ?>
					<details class="theblog-faq-details">
						<summary>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of FAQ entries generated for this post. */
									_n( '%d FAQ generated', '%d FAQs generated', count( $faqs ), 'seo-automation' ),
									count( $faqs )
								)
							);
							?>
						</summary>
						<ul>
							<?php foreach ( $faqs as $faq ) : ?>
								<li><strong><?php echo esc_html( $faq['question'] ); ?></strong><br><?php echo esc_html( $faq['answer'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>

				<?php if ( ! empty( $seo_analysis ) && is_array( $seo_analysis ) ) : ?>
					<div class="theblog-seo-analysis">
						<strong><?php esc_html_e( 'SEO Analysis', 'seo-automation' ); ?></strong>
						<div class="theblog-seo-scores">
							<div>
								<span class="theblog-seo-score-badge"><?php echo (int) $seo_analysis['rank_math_score']; ?>/100</span>
								<span class="theblog-seo-score-label"><?php esc_html_e( 'Rank Math compatibility', 'seo-automation' ); ?></span>
							</div>
							<div>
								<span class="theblog-seo-score-badge"><?php echo (int) $seo_analysis['yoast_score']; ?>/100</span>
								<span class="theblog-seo-score-label"><?php esc_html_e( 'Yoast compatibility', 'seo-automation' ); ?></span>
							</div>
						</div>

						<?php if ( ! empty( $seo_analysis['categories'] ) && is_array( $seo_analysis['categories'] ) ) : ?>
							<?php foreach ( $seo_analysis['categories'] as $category ) : ?>
								<details class="theblog-seo-category">
									<summary>
										<?php echo esc_html( $category['label'] ); ?>
										<span class="theblog-seo-category-count">
											<?php echo count( array_filter( $category['checks'] ) ) . '/' . count( $category['checks'] ); ?>
										</span>
									</summary>
									<ul class="theblog-seo-checks">
										<?php foreach ( $category['checks'] as $label => $passed ) : ?>
											<li class="<?php echo $passed ? 'pass' : 'fail'; ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $label ) ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endforeach; ?>
						<?php endif; ?>

						<?php if ( ! empty( $seo_analysis['technical'] ) ) : ?>
							<details class="theblog-seo-category theblog-seo-technical">
								<summary><?php echo esc_html( $seo_analysis['technical']['label'] ); ?></summary>
								<ul class="theblog-seo-checks">
									<?php foreach ( $seo_analysis['technical']['checks'] as $label => $passed ) : ?>
										<li class="<?php echo $passed ? 'pass' : 'fail'; ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $label ) ) ); ?></li>
									<?php endforeach; ?>
								</ul>
								<p class="description"><?php echo esc_html( $seo_analysis['technical']['note'] ); ?></p>
							</details>
						<?php endif; ?>

						<?php if ( ! empty( $seo_analysis['warnings'] ) ) : ?>
							<div class="theblog-seo-warnings">
								<?php foreach ( $seo_analysis['warnings'] as $warning ) : ?>
									<div>⚠ <?php echo esc_html( $warning ); ?></div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<p class="theblog-seo-disclaimer"><?php echo esc_html( $seo_analysis['disclaimer'] ); ?></p>
					</div>
				<?php endif; ?>

				<div class="theblog-review-actions">
					<a class="button" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'seo-automation' ); ?></a>
					<a class="button" href="<?php echo esc_url( get_preview_post_link( $post->ID ) ); ?>" target="_blank"><?php esc_html_e( 'Preview', 'seo-automation' ); ?></a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'theblog_review_' . $post->ID ); ?>
						<input type="hidden" name="action" value="theblog_approve_post" />
						<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve & Publish', 'seo-automation' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Reject and move this draft to Trash?', 'seo-automation' ) ); ?>');">
						<?php wp_nonce_field( 'theblog_review_' . $post->ID ); ?>
						<input type="hidden" name="action" value="theblog_reject_post" />
						<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reject', 'seo-automation' ); ?></button>
					</form>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
