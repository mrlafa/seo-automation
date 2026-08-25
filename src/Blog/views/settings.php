<?php

namespace SEOAgent\Blog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = Settings::all();

/**
 * Render a write-only credential field.
 *
 * The stored value is never echoed back into the page: the field renders
 * empty and a blank submission means "keep the stored key". Removal is an
 * explicit checkbox, so saving an unrelated setting can never wipe a key.
 *
 * @param string $name  Setting key / input name.
 * @param string $value Currently stored value.
 */
$theblog_secret_field = static function ( $name, $value ) {
	$is_set = '' !== trim( (string) $value );
	?>
	<input type="password" autocomplete="new-password" class="regular-text"
		id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value=""
		placeholder="<?php echo esc_attr( $is_set ? __( 'A key is stored — leave blank to keep it', 'seo-automation' ) : __( 'Not set', 'seo-automation' ) ); ?>" />
	<?php if ( $is_set ) : ?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>_remove" value="1" />
				<?php esc_html_e( 'Remove the stored key', 'seo-automation' ); ?>
			</label>
		</p>
	<?php endif; ?>
	<?php
};
?>
<div class="wrap theblog-wrap">
	<h1><?php esc_html_e( 'TheBlog Automation Settings', 'seo-automation' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'theblog_save_settings' ); ?>
		<input type="hidden" name="action" value="theblog_save_settings" />

		<div class="theblog-panel">
			<h2><?php esc_html_e( 'AI Provider', 'seo-automation' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ai_provider"><?php esc_html_e( 'Text Provider', 'seo-automation' ); ?></label></th>
					<td>
						<select name="ai_provider" id="ai_provider">
							<option value="anthropic" <?php selected( $s['ai_provider'], 'anthropic' ); ?>>Anthropic (Claude)</option>
							<option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>>OpenAI (GPT)</option>
							<option value="deepseek" <?php selected( $s['ai_provider'], 'deepseek' ); ?>>DeepSeek</option>
						</select>
						<p class="description"><?php esc_html_e( 'Used for research, writing, and SEO metadata.', 'seo-automation' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'seo-automation' ); ?></label></th>
					<td><?php $theblog_secret_field( 'anthropic_api_key', $s['anthropic_api_key'] ); ?></td>
				</tr>
				<tr>
					<th><label for="anthropic_model"><?php esc_html_e( 'Anthropic Model', 'seo-automation' ); ?></label></th>
					<td><input type="text" class="regular-text" id="anthropic_model" name="anthropic_model" value="<?php echo esc_attr( $s['anthropic_model'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'seo-automation' ); ?></label></th>
					<td><?php $theblog_secret_field( 'openai_api_key', $s['openai_api_key'] ); ?></td>
				</tr>
				<tr>
					<th><label for="openai_model"><?php esc_html_e( 'OpenAI Model', 'seo-automation' ); ?></label></th>
					<td><input type="text" class="regular-text" id="openai_model" name="openai_model" value="<?php echo esc_attr( $s['openai_model'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="deepseek_api_key"><?php esc_html_e( 'DeepSeek API Key', 'seo-automation' ); ?></label></th>
					<td><?php $theblog_secret_field( 'deepseek_api_key', $s['deepseek_api_key'] ); ?></td>
				</tr>
				<tr>
					<th><label for="deepseek_model"><?php esc_html_e( 'DeepSeek Model', 'seo-automation' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="deepseek_model" name="deepseek_model" value="<?php echo esc_attr( $s['deepseek_model'] ); ?>" />
						<p class="description"><?php esc_html_e( 'e.g. deepseek-chat (fast, cheap) or deepseek-reasoner. DeepSeek does not offer image generation — pick OpenAI below for featured images if needed.', 'seo-automation' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="theblog-panel">
			<h2><?php esc_html_e( 'Featured Images', 'seo-automation' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="image_provider"><?php esc_html_e( 'Image Provider', 'seo-automation' ); ?></label></th>
					<td>
						<select name="image_provider" id="image_provider">
							<option value="none" <?php selected( $s['image_provider'], 'none' ); ?>><?php esc_html_e( 'Disabled (skip featured images)', 'seo-automation' ); ?></option>
							<option value="openai" <?php selected( $s['image_provider'], 'openai' ); ?>>OpenAI (DALL·E) — <?php esc_html_e( 'uses the OpenAI key above', 'seo-automation' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="image_model"><?php esc_html_e( 'Image Model', 'seo-automation' ); ?></label></th>
					<td><input type="text" class="regular-text" id="image_model" name="image_model" value="<?php echo esc_attr( $s['image_model'] ); ?>" /></td>
				</tr>
			</table>
		</div>

		<div class="theblog-panel">
			<h2><?php esc_html_e( 'Autopilot', 'seo-automation' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable Autopilot', 'seo-automation' ); ?></th>
					<td>
						<label><input type="checkbox" name="autopilot_enabled" value="1" <?php checked( $s['autopilot_enabled'] ); ?> /> <?php esc_html_e( 'Automatically process due, queued topics on a schedule.', 'seo-automation' ); ?></label>
						<p class="description"><?php esc_html_e( 'Autopilot never publishes directly — every draft still lands in the Review Queue for your approval.', 'seo-automation' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="autopilot_interval"><?php esc_html_e( 'Run Interval', 'seo-automation' ); ?></label></th>
					<td>
						<select name="autopilot_interval" id="autopilot_interval">
							<option value="hourly" <?php selected( $s['autopilot_interval'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'seo-automation' ); ?></option>
							<option value="twicedaily" <?php selected( $s['autopilot_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'seo-automation' ); ?></option>
							<option value="daily" <?php selected( $s['autopilot_interval'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'seo-automation' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="max_posts_per_run"><?php esc_html_e( 'Max Posts per Run', 'seo-automation' ); ?></label></th>
					<td><input type="number" min="1" max="10" id="max_posts_per_run" name="max_posts_per_run" value="<?php echo esc_attr( $s['max_posts_per_run'] ); ?>" /></td>
				</tr>
			</table>
		</div>

		<div class="theblog-panel">
			<h2><?php esc_html_e( 'Publishing Defaults', 'seo-automation' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="default_category"><?php esc_html_e( 'Default Category', 'seo-automation' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'name'             => 'default_category',
								'id'               => 'default_category',
								'selected'         => $s['default_category'],
								'show_option_none' => __( 'Use WordPress default', 'seo-automation' ),
								'hide_empty'       => false,
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th><label for="default_author"><?php esc_html_e( 'Default Author', 'seo-automation' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'             => 'default_author',
								'id'               => 'default_author',
								'selected'         => $s['default_author'],
								'show_option_none' => __( 'Current user at generation time', 'seo-automation' ),
								'who'              => 'authors',
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th><label for="internal_link_limit"><?php esc_html_e( 'Internal Links per Post', 'seo-automation' ); ?></label></th>
					<td><input type="number" min="0" max="10" id="internal_link_limit" name="internal_link_limit" value="<?php echo esc_attr( $s['internal_link_limit'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="notify_email"><?php esc_html_e( 'Notify Email', 'seo-automation' ); ?></label></th>
					<td><input type="email" class="regular-text" id="notify_email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Sent when a new draft is ready for review.', 'seo-automation' ); ?></p></td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'seo-automation' ) ); ?>
	</form>
</div>
