<?php
/**
 * Core Web Vitals investigation.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\SiteChecker;
use SEOAgent\Support\Html;
use SEOAgent\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two modes.
 *
 * With a PageSpeed Insights API key configured, this reports the field data
 * Google actually holds for the site — the numbers that count for ranking.
 * Without one it falls back to inspecting the rendered HTML for the specific
 * causes that produce bad vitals, which is diagnostic rather than measured and
 * is labelled as such. It never claims a measured score it does not have.
 */
final class CoreWebVitalsChecker extends SiteChecker {

	private const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/** Images above this size are a likely LCP problem. */
	private const LARGE_IMAGE_BYTES = 300000;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'core_web_vitals';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Core Web Vitals', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Reports field data from PageSpeed Insights when a key is configured, and otherwise inspects the page for the causes of poor vitals.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_site( AuditContext $context ): array {
		$key = (string) Options::get( 'psi_api_key', '' );

		if ( '' !== $key ) {
			$measured = $this->measure( home_url( '/' ), $key, $context );

			if ( ! empty( $measured ) ) {
				return $measured;
			}
		}

		return $this->diagnose( $context );
	}

	/**
	 * Ask PageSpeed Insights for the site's field data.
	 *
	 * @param string       $url     URL to test.
	 * @param string       $key     API key.
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function measure( string $url, string $key, AuditContext $context ): array {
		$request = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'key'      => $key,
				'strategy' => 'mobile',
			),
			self::PSI_ENDPOINT
		);

		$response = wp_remote_get( $request, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['loadingExperience']['metrics'] ) ) {
			return array();
		}

		$metrics = $data['loadingExperience']['metrics'];

		$thresholds = array(
			'LARGEST_CONTENTFUL_PAINT_MS'      => array(
				'label' => 'LCP',
				'good'  => 2500,
				'poor'  => 4000,
				'unit'  => 'ms',
				'cause' => __( 'Usually an oversized hero image, a slow server response, or a render-blocking stylesheet.', 'seo-automation' ),
			),
			'CUMULATIVE_LAYOUT_SHIFT_SCORE'    => array(
				'label' => 'CLS',
				'good'  => 10,
				'poor'  => 25,
				'unit'  => '/100',
				'cause' => __( 'Usually images or ads without reserved dimensions, or a web font swapping in late.', 'seo-automation' ),
			),
			'INTERACTION_TO_NEXT_PAINT'        => array(
				'label' => 'INP',
				'good'  => 200,
				'poor'  => 500,
				'unit'  => 'ms',
				'cause' => __( 'Usually heavy JavaScript on the main thread — sliders, chat widgets and tracking scripts.', 'seo-automation' ),
			),
		);

		$issues = array();

		foreach ( $thresholds as $metric => $spec ) {
			if ( empty( $metrics[ $metric ]['percentile'] ) ) {
				continue;
			}

			$value = (int) $metrics[ $metric ]['percentile'];

			if ( $value <= $spec['good'] ) {
				continue;
			}

			$poor = $value > $spec['poor'];

			$issues[] = $this->issue(
				array(
					'code'         => 'cwv.' . strtolower( $spec['label'] ) . '.failing',
					'severity'     => $poor ? Issue::SEVERITY_HIGH : Issue::SEVERITY_MEDIUM,
					'object_type'  => 'site',
					'object_id'    => 0,
					'object_label' => $spec['label'],
					'url'          => $url,
					'title'        => sprintf(
						/* translators: 1: metric name, 2: rating. */
						__( '%1$s is %2$s for real visitors', 'seo-automation' ),
						$spec['label'],
						$poor ? __( 'poor', 'seo-automation' ) : __( 'below the "good" threshold', 'seo-automation' )
					),
					'detail'       => sprintf(
						/* translators: 1: metric, 2: value, 3: unit, 4: good threshold, 5: likely cause. */
						__( '%1$s at the 75th percentile is %2$d%3$s against a "good" threshold of %4$d. %5$s', 'seo-automation' ),
						$spec['label'],
						$value,
						$spec['unit'],
						$spec['good'],
						$spec['cause']
					),
					'evidence'     => array(
						'metric'     => $metric,
						'percentile' => $value,
						'category'   => $metrics[ $metric ]['category'] ?? '',
						'source'     => 'crux_field_data',
						'strategy'   => 'mobile',
					),
					'fix_mode'     => Issue::MODE_MANUAL,
				)
			);
		}

		return $issues;
	}

	/**
	 * Inspect the rendered home page for known causes of poor vitals.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function diagnose( AuditContext $context ): array {
		$url      = home_url( '/' );
		$response = $context->fetcher->get( $url, HOUR_IN_SECONDS );

		if ( '' !== $response['error'] || $response['status'] >= 400 ) {
			return array();
		}

		$body   = $response['body'];
		$head   = $this->head( $body );
		$issues = array();

		$note = __( 'Diagnosed from the page markup, not measured from real visits. Add a PageSpeed Insights API key in the settings for field data.', 'seo-automation' );

		// Images without intrinsic dimensions are the single most common CLS cause.
		$images       = Html::images( $body );
		$no_dimensions = array_filter(
			$images,
			static fn( $image ) => '' === $image['width'] || '' === $image['height']
		);

		if ( count( $no_dimensions ) > 0 ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'cwv.images.no_dimensions',
					'severity'    => Issue::SEVERITY_MEDIUM,
					'object_type' => 'site',
					'object_id'   => 0,
					'url'         => $url,
					'title'       => __( 'Images render without declared dimensions', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: 1: count, 2: total images, 3: methodology note. */
						__( '%1$d of %2$d images on the home page have no width and height attribute, so the browser cannot reserve space and the layout jumps as they load. %3$s', 'seo-automation' ),
						count( $no_dimensions ),
						count( $images ),
						$note
					),
					'evidence'    => array(
						'without_dimensions' => count( $no_dimensions ),
						'total_images'       => count( $images ),
						'affected_count'     => count( $no_dimensions ),
						'samples'            => array_slice( wp_list_pluck( array_values( $no_dimensions ), 'src' ), 0, 10 ),
						'source'             => 'markup_heuristic',
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		// Scripts in <head> without defer or async block the parser.
		if ( preg_match_all( '/<script\b([^>]*)>/i', $head, $matches, PREG_SET_ORDER ) ) {
			$blocking = array();

			foreach ( $matches as $match ) {
				$attributes = Html::attributes( $match[1] );

				if ( empty( $attributes['src'] ) ) {
					continue;
				}
				if ( isset( $attributes['defer'] ) || isset( $attributes['async'] ) ) {
					continue;
				}
				if ( isset( $attributes['type'] ) && 'module' === $attributes['type'] ) {
					continue;
				}

				$blocking[] = $attributes['src'];
			}

			if ( count( $blocking ) >= 3 ) {
				$issues[] = $this->issue(
					array(
						'code'        => 'cwv.render_blocking_scripts',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'object_type' => 'site',
						'object_id'   => 0,
						'url'         => $url,
						'title'       => __( 'Render-blocking scripts in the document head', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: count, 2: methodology note. */
							__( '%1$d external scripts load in the head with neither defer nor async. Each one stops the parser until it has been fetched and executed, delaying first paint. %2$s', 'seo-automation' ),
							count( $blocking ),
							$note
						),
						'evidence'    => array(
							'scripts'        => array_slice( $blocking, 0, 15 ),
							'affected_count' => count( $blocking ),
							'source'         => 'markup_heuristic',
						),
						'fix_mode'    => Issue::MODE_MANUAL,
					)
				);
			}
		}

		$stylesheets = preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $head );

		if ( $stylesheets >= 10 ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'cwv.too_many_stylesheets',
					'severity'    => Issue::SEVERITY_LOW,
					'object_type' => 'site',
					'object_id'   => 0,
					'url'         => $url,
					'title'       => __( 'Many separate stylesheets', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: 1: count, 2: methodology note. */
						__( 'The head loads %1$d stylesheets. Every one is a render-blocking request; this is the usual signature of several plugins each shipping their own CSS. %2$s', 'seo-automation' ),
						$stylesheets,
						$note
					),
					'evidence'    => array(
						'stylesheets'    => $stylesheets,
						'affected_count' => $stylesheets,
						'source'         => 'markup_heuristic',
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		$issues = array_merge( $issues, $this->check_large_images() );

		return $issues;
	}

	/**
	 * Media library images heavy enough to hurt LCP.
	 *
	 * Checked against the stored file size rather than by downloading, so this
	 * costs one indexed query regardless of library size.
	 *
	 * @return Issue[]
	 */
	private function check_large_images(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.guid
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = 'attachment'
				   AND p.post_mime_type LIKE %s
				 ORDER BY p.ID DESC
				 LIMIT 300",
				'image/%'
			),
			ARRAY_A
		) ?: array();

		$heavy   = array();
		$uploads = wp_get_upload_dir();

		foreach ( $rows as $row ) {
			$file = get_attached_file( (int) $row['ID'] );

			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$size = (int) filesize( $file );

			if ( $size > self::LARGE_IMAGE_BYTES ) {
				$heavy[] = array(
					'id'    => (int) $row['ID'],
					'file'  => str_replace( trailingslashit( $uploads['basedir'] ), '', $file ),
					'bytes' => $size,
				);
			}
		}

		if ( count( $heavy ) < 3 ) {
			return array();
		}

		usort( $heavy, static fn( $a, $b ) => $b['bytes'] <=> $a['bytes'] );

		return array(
			$this->issue(
				array(
					'code'        => 'cwv.images.oversized',
					'severity'    => Issue::SEVERITY_MEDIUM,
					'object_type' => 'site',
					'object_id'   => 0,
					'url'         => admin_url( 'upload.php' ),
					'title'       => __( 'Oversized images in the media library', 'seo-automation' ),
					'detail'      => sprintf(
						/* translators: 1: count, 2: size threshold in KB. */
						__( '%1$d recently uploaded images exceed %2$dKB. Whenever one of these is the largest element on a page it sets LCP on its own.', 'seo-automation' ),
						count( $heavy ),
						(int) ( self::LARGE_IMAGE_BYTES / 1000 )
					),
					'evidence'    => array(
						'images'         => array_slice( $heavy, 0, 20 ),
						'affected_count' => count( $heavy ),
						'threshold'      => self::LARGE_IMAGE_BYTES,
						'source'         => 'filesystem',
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			),
		);
	}

	/**
	 * The document head, or the first chunk of the document if it has none.
	 *
	 * @param string $html Rendered HTML.
	 */
	private function head( string $html ): string {
		if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $match ) ) {
			return $match[1];
		}

		return substr( $html, 0, 20000 );
	}
}
