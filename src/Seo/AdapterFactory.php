<?php
/**
 * Picks the adapter matching the site's installed SEO plugin.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo;

use SEOAgent\Seo\Adapters\NativeAdapter;
use SEOAgent\Seo\Adapters\RankMathAdapter;
use SEOAgent\Seo\Adapters\SeoPressAdapter;
use SEOAgent\Seo\Adapters\YoastAdapter;
use SEOAgent\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detection in priority order, with a settings and filter override.
 */
final class AdapterFactory {

	/**
	 * Adapter classes in detection order.
	 *
	 * @return array<string,class-string<SeoAdapterInterface>>
	 */
	public static function available(): array {
		return array(
			'rank_math' => RankMathAdapter::class,
			'yoast'     => YoastAdapter::class,
			'seopress'  => SeoPressAdapter::class,
			'native'    => NativeAdapter::class,
		);
	}

	/**
	 * Build the adapter this site should use.
	 */
	public static function make(): SeoAdapterInterface {
		$available = self::available();
		$forced    = (string) Options::get( 'seo_adapter', '' );

		if ( '' !== $forced && isset( $available[ $forced ] ) ) {
			$adapter = new $available[ $forced ]();
		} else {
			$adapter = self::detect( $available );
		}

		/**
		 * Filter the SEO metadata adapter.
		 *
		 * @param SeoAdapterInterface $adapter Detected adapter.
		 */
		return apply_filters( 'seo_agent_adapter', $adapter );
	}

	/**
	 * First active adapter, falling back to the native one.
	 *
	 * @param array<string,class-string<SeoAdapterInterface>> $available Candidates.
	 */
	private static function detect( array $available ): SeoAdapterInterface {
		foreach ( $available as $class ) {
			/** @var SeoAdapterInterface $candidate */
			$candidate = new $class();

			if ( $candidate->is_active() ) {
				if ( $candidate instanceof NativeAdapter ) {
					// Nothing else claimed the metadata, so start rendering it.
					$candidate->register_head_output();
				}

				return $candidate;
			}
		}

		return new NativeAdapter();
	}

	/**
	 * Which SEO plugins are active, for the site-context report.
	 *
	 * @return array<string,bool>
	 */
	public static function detected(): array {
		$detected = array();

		foreach ( self::available() as $slug => $class ) {
			if ( 'native' === $slug ) {
				continue;
			}

			/** @var SeoAdapterInterface $candidate */
			$candidate         = new $class();
			$detected[ $slug ] = $candidate->is_active();
		}

		return $detected;
	}
}
