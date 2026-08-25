<?php
/**
 * Plugin settings access.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Support;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Thin wrapper over a single serialised option row.
 */
final class Options {

	public const KEY = 'seo_agent_settings';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * Default settings. Anything an agent may tune lives here.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'scheduled_audits_enabled' => true,
			'audit_post_types'         => array( 'post', 'page', 'product' ),
			'audit_taxonomies'         => array( 'category', 'product_cat' ),
			'title_min_length'         => 30,
			'title_max_length'         => 60,
			'description_min_length'   => 70,
			'description_max_length'   => 155,
			'min_word_count'           => 300,
			'min_internal_links'       => 3,
			'max_internal_links'       => 100,
			'duplicate_threshold'      => 0.85,
			'batch_size'               => 50,
			'request_timeout'          => 10,
			'link_check_concurrency'   => 5,
			// Fixes are staged for review unless a fixer is explicitly allow-listed.
			'autonomy'                 => 'review', // review | auto_safe | auto_all.
			'auto_fixers'              => array( 'image_alt', 'meta_description', 'meta_title' ),
			'agent_token_hash'         => '',
			'psi_api_key'              => '',
			'target_locale'            => '',
			// Force a specific metadata adapter instead of detecting one.
			// Empty means detect; see AdapterFactory.
			'seo_adapter'              => '',
		);
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::KEY, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		/**
		 * Filter the effective SEO Agent settings.
		 *
		 * @param array<string,mixed> $settings Merged settings.
		 */
		return apply_filters( 'seo_agent_settings', self::$cache );
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array<string,mixed> $values Values to merge in.
	 *
	 * @return array<string,mixed> The stored settings.
	 */
	public static function update( array $values ): array {
		$stored = get_option( self::KEY, array() );
		$stored = array_merge( is_array( $stored ) ? $stored : array(), $values );

		update_option( self::KEY, $stored, false );
		self::$cache = null;

		return self::all();
	}

	/**
	 * Drop the in-process cache (tests, long-running CLI).
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
