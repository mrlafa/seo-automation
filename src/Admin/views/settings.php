<?php
/**
 * Settings screen.
 *
 * @package SEOAgent
 *
 * @var array<string,mixed>                       $settings   Current settings.
 * @var array<string,\WP_Post_Type>               $post_types Public post types.
 * @var array<string,\WP_Taxonomy>                $taxonomies Public taxonomies.
 * @var string|false                              $new_token  A freshly generated token, shown once.
 * @var \SEOAgent\Seo\SeoAdapterInterface         $seo        Active metadata adapter.
 */

use SEOAgent\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'SEO Agent settings', 'seo-automation' ); ?></h1>

	<?php if ( $new_token ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Your agent token — copy it now, it is not shown again.', 'seo-automation' ); ?></strong></p>
			<p><code style="font-size:14px;user-select:all"><?php echo esc_html( $new_token ); ?></code></p>
			<p><?php esc_html_e( 'Send it as the X-SEO-Agent-Token header alongside normal WordPress authentication.', 'seo-automation' ); ?></p>
		</div>
	<?php endif; ?>

	<?php AdminMenu::form_open( 'save_settings' ); ?>
	<div style="display:block">

		<h2><?php esc_html_e( 'How much the agent may do on its own', 'seo-automation' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Autonomy', 'seo-automation' ); ?></th>
				<td>
					<?php
					$modes = array(
						'review'    => array(
							__( 'Review everything', 'seo-automation' ),
							__( 'Nothing is written without an explicit approval. Fixes can still be previewed freely.', 'seo-automation' ),
						),
						'auto_safe' => array(
							__( 'Apply safe fixes automatically', 'seo-automation' ),
							__( 'Deterministic fixes run unattended. Anything needing written copy still waits for approval.', 'seo-automation' ),
						),
						'auto_all'  => array(
							__( 'Apply everything automatically', 'seo-automation' ),
							__( 'Any fix with sufficient input is applied without asking. Every change remains reversible from the change log.', 'seo-automation' ),
						),
					);

					foreach ( $modes as $value => $mode ) :
						?>
						<label style="display:block;margin-bottom:10px">
							<input type="radio" name="autonomy" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['autonomy'], $value ); ?> />
							<strong><?php echo esc_html( $mode[0] ); ?></strong>
							<span class="description" style="display:block;margin-left:24px"><?php echo esc_html( $mode[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Nightly audits', 'seo-automation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="scheduled_audits_enabled" value="1" <?php checked( ! empty( $settings['scheduled_audits_enabled'] ) ); ?> />
						<?php esc_html_e( 'Run a full audit once a day via WP-Cron', 'seo-automation' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'What gets audited', 'seo-automation' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Post types', 'seo-automation' ); ?></th>
				<td>
					<?php foreach ( $post_types as $post_type ) : ?>
						<label style="display:inline-block;margin:0 16px 6px 0">
							<input type="checkbox" name="audit_post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>"
								<?php checked( in_array( $post_type->name, (array) $settings['audit_post_types'], true ) ); ?> />
							<?php echo esc_html( $post_type->label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Taxonomies', 'seo-automation' ); ?></th>
				<td>
					<?php foreach ( $taxonomies as $taxonomy ) : ?>
						<label style="display:inline-block;margin:0 16px 6px 0">
							<input type="checkbox" name="audit_taxonomies[]" value="<?php echo esc_attr( $taxonomy->name ); ?>"
								<?php checked( in_array( $taxonomy->name, (array) $settings['audit_taxonomies'], true ) ); ?> />
							<?php echo esc_html( $taxonomy->label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Thresholds', 'seo-automation' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These define what counts as a problem. Defaults follow common practice; change them if your site has a reason to differ.', 'seo-automation' ); ?></p>

		<table class="form-table" role="presentation">
			<?php
			$numbers = array(
				'title_min_length'       => __( 'Minimum title length', 'seo-automation' ),
				'title_max_length'       => __( 'Maximum title length', 'seo-automation' ),
				'description_min_length' => __( 'Minimum description length', 'seo-automation' ),
				'description_max_length' => __( 'Maximum description length', 'seo-automation' ),
				'min_word_count'         => __( 'Thin content below (words)', 'seo-automation' ),
				'min_internal_links'     => __( 'Minimum internal links per page', 'seo-automation' ),
				'batch_size'             => __( 'Objects per audit slice', 'seo-automation' ),
				'request_timeout'        => __( 'HTTP timeout (seconds)', 'seo-automation' ),
			);

			foreach ( $numbers as $key => $label ) :
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" class="small-text" min="0" />
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Core Web Vitals', 'seo-automation' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="psi_api_key"><?php esc_html_e( 'PageSpeed Insights API key', 'seo-automation' ); ?></label></th>
				<td>
					<?php $psi_is_set = '' !== trim( (string) $settings['psi_api_key'] ); ?>
					<input type="password" id="psi_api_key" name="psi_api_key" class="regular-text"
						value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( $psi_is_set ? __( 'A key is stored — leave blank to keep it', 'seo-automation' ) : __( 'Not set', 'seo-automation' ) ); ?>" />
					<?php if ( $psi_is_set ) : ?>
						<p>
							<label>
								<input type="checkbox" name="psi_api_key_remove" value="1" />
								<?php esc_html_e( 'Remove the stored key', 'seo-automation' ); ?>
							</label>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'With a key, Core Web Vitals are reported from the field data Google holds for real visits to your site. Without one, the plugin falls back to inspecting your markup for the known causes of poor vitals, and labels those findings as diagnostic rather than measured.', 'seo-automation' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'seo-automation' ); ?></button>
		</p>
	</div>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Agent access', 'seo-automation' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'REST endpoint', 'seo-automation' ); ?></th>
			<td><code><?php echo esc_html( rest_url( 'seo-agent/v1' ) ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Metadata adapter', 'seo-automation' ); ?></th>
			<td>
				<?php echo esc_html( $seo->label() ); ?>
				<p class="description"><?php esc_html_e( 'Detected automatically. Titles and descriptions are read and written through this plugin, so fixes appear wherever you normally edit them.', 'seo-automation' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Token', 'seo-automation' ); ?></th>
			<td>
				<?php if ( '' !== trim( (string) ( $settings['agent_token_hash'] ?? '' ) ) ) : ?>
					<p><?php esc_html_e( 'A token is configured and required on every API call.', 'seo-automation' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'No token configured. API calls need only a WordPress application password and the SEO Agent capability.', 'seo-automation' ); ?></p>
				<?php endif; ?>

				<?php AdminMenu::form_open( 'generate_token' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Generate a new token', 'seo-automation' ); ?></button>
				</form>

				<p class="description">
					<?php esc_html_e( 'The token is an extra factor on top of normal authentication, never a replacement for it. Only its hash is stored, so a database leak does not expose it.', 'seo-automation' ); ?>
				</p>
			</td>
		</tr>
	</table>
</div>
