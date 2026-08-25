<?php
/**
 * Structured data checks against rendered pages.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AbstractChecker;
use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\CheckerResult;
use SEOAgent\Audit\Issue;
use SEOAgent\Support\Html;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Structured data can only be judged from the rendered page, because it is
 * assembled at render time from the theme, the SEO plugin and WooCommerce.
 * Fetching every URL is out of the question, so this samples one representative
 * page per template and reports findings against the template.
 */
final class SchemaChecker extends AbstractChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'schema';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Structured data', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'schema';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Samples one page per template and checks the JSON-LD it emits for validity and completeness.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( AuditContext $context ): CheckerResult {
		$queue = $context->cursor( 'queue' );

		if ( null === $queue ) {
			$queue = $this->build_sample( $context );
		}

		$queue  = array_values( (array) $queue );
		$issues = array();
		$done   = 0;

		while ( ! empty( $queue ) ) {
			$target = array_shift( $queue );
			++$done;

			$issues = array_merge( $issues, $this->inspect( (array) $target, $context ) );

			if ( $context->out_of_time() ) {
				break;
			}
		}

		if ( empty( $queue ) ) {
			return new CheckerResult( $issues, null, $done );
		}

		return new CheckerResult( $issues, array( 'queue' => $queue ), $done );
	}

	/**
	 * One representative URL per template on the site.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_sample( AuditContext $context ): array {
		$sample = array(
			array(
				'url'      => home_url( '/' ),
				'template' => 'front_page',
				'label'    => __( 'Home page', 'seo-automation' ),
				'expects'  => array( 'Organization', 'WebSite' ),
			),
		);

		foreach ( $context->post_types() as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'DESC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			if ( empty( $ids ) ) {
				continue;
			}

			$id = (int) $ids[0];

			$sample[] = array(
				'url'      => (string) get_permalink( $id ),
				'template' => 'single_' . $post_type,
				'label'    => sprintf(
					/* translators: %s: post type name. */
					__( 'Single %s', 'seo-automation' ),
					$post_type
				),
				'post_id'  => $id,
				'expects'  => $this->expected_types( $post_type ),
			);
		}

		foreach ( $context->taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'number'     => 1,
					'hide_empty' => true,
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$sample[] = array(
				'url'      => (string) get_term_link( $terms[0] ),
				'template' => 'archive_' . $taxonomy,
				'label'    => sprintf(
					/* translators: %s: taxonomy name. */
					__( '%s archive', 'seo-automation' ),
					$taxonomy
				),
				'expects'  => array( 'BreadcrumbList' ),
			);
		}

		return $sample;
	}

	/**
	 * Schema types a template should be emitting.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return string[]
	 */
	private function expected_types( string $post_type ): array {
		if ( 'product' === $post_type ) {
			return array( 'Product', 'BreadcrumbList' );
		}

		if ( 'post' === $post_type ) {
			return array( 'Article', 'BreadcrumbList' );
		}

		return array( 'BreadcrumbList' );
	}

	/**
	 * Fetch one sampled URL and judge its structured data.
	 *
	 * @param array<string,mixed> $target  Sample entry.
	 * @param AuditContext        $context Run state.
	 *
	 * @return Issue[]
	 */
	private function inspect( array $target, AuditContext $context ): array {
		$url = (string) ( $target['url'] ?? '' );

		if ( '' === $url ) {
			return array();
		}

		$response = $context->fetcher->get( $url, HOUR_IN_SECONDS );

		if ( '' !== $response['error'] || $response['status'] >= 400 ) {
			return array();
		}

		$body   = $response['body'];
		$graphs = Html::json_ld( $body );

		$base = array(
			'object_type'  => 'url',
			'object_id'    => (int) ( $target['post_id'] ?? 0 ),
			'object_label' => (string) ( $target['label'] ?? $url ),
			'url'          => $url,
			'key'          => (string) ( $target['template'] ?? $url ),
		);

		// A page with ld+json blocks that none of them parse is worse than none:
		// the intent is there and the output is silently discarded.
		if ( empty( $graphs ) ) {
			$has_blocks = (bool) preg_match( '#application/ld\+json#i', $body );

			return array(
				$this->issue(
					array_merge(
						$base,
						array(
							'code'     => $has_blocks ? 'schema.invalid_json' : 'schema.missing',
							'severity' => $has_blocks ? Issue::SEVERITY_HIGH : Issue::SEVERITY_MEDIUM,
							'title'    => $has_blocks
								? __( 'Structured data is present but does not parse', 'seo-automation' )
								: __( 'Template emits no structured data', 'seo-automation' ),
							'detail'   => $has_blocks
								? sprintf(
									/* translators: %s: sampled URL. */
									__( '%s contains JSON-LD blocks that fail to decode, so search engines discard them entirely.', 'seo-automation' ),
									$url
								)
								: sprintf(
									/* translators: 1: template label, 2: expected types. */
									__( 'The %1$s template outputs no JSON-LD. Expected at least: %2$s.', 'seo-automation' ),
									(string) ( $target['label'] ?? '' ),
									implode( ', ', (array) ( $target['expects'] ?? array() ) )
								),
							'evidence' => array(
								'template' => $target['template'] ?? '',
								'expects'  => $target['expects'] ?? array(),
							),
							'fix_mode' => Issue::MODE_MANUAL,
						)
					)
				),
			);
		}

		$nodes = array();
		foreach ( $graphs as $graph ) {
			foreach ( Html::flatten_json_ld( $graph ) as $node ) {
				$nodes[] = $node;
			}
		}

		$present = array();
		foreach ( $nodes as $node ) {
			foreach ( Html::node_types( $node ) as $type ) {
				$present[] = $type;
			}
		}
		$present = array_values( array_unique( $present ) );

		$issues = array();

		foreach ( (array) ( $target['expects'] ?? array() ) as $expected ) {
			if ( $this->has_type( $present, $expected ) ) {
				continue;
			}

			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'schema.type.missing',
						'severity' => 'Product' === $expected ? Issue::SEVERITY_HIGH : Issue::SEVERITY_LOW,
						'key'      => ( $target['template'] ?? $url ) . '|' . $expected,
						'title'    => sprintf(
							/* translators: %s: schema type. */
							__( 'No %s structured data', 'seo-automation' ),
							$expected
						),
						'detail'   => sprintf(
							/* translators: 1: schema type, 2: template label, 3: types found. */
							__( 'The %2$s template does not emit %1$s. Found instead: %3$s.', 'seo-automation' ),
							$expected,
							(string) ( $target['label'] ?? '' ),
							$present ? implode( ', ', $present ) : __( 'nothing', 'seo-automation' )
						),
						'evidence' => array(
							'expected' => $expected,
							'present'  => $present,
							'template' => $target['template'] ?? '',
						),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return array_merge( $issues, $this->check_product_nodes( $nodes, $base ) );
	}

	/**
	 * Product schema without a usable offer is rejected outright.
	 *
	 * @param array<int,array<string,mixed>> $nodes Flattened schema nodes.
	 * @param array<string,mixed>            $base  Shared issue fields.
	 *
	 * @return Issue[]
	 */
	private function check_product_nodes( array $nodes, array $base ): array {
		$issues = array();

		foreach ( $nodes as $node ) {
			if ( ! $this->has_type( Html::node_types( $node ), 'Product' ) ) {
				continue;
			}

			$missing = array();

			foreach ( array( 'name', 'image' ) as $property ) {
				if ( empty( $node[ $property ] ) ) {
					$missing[] = $property;
				}
			}

			$offers = $node['offers'] ?? null;

			if ( empty( $offers ) ) {
				$missing[] = 'offers';
			} else {
				$offer = isset( $offers[0] ) && is_array( $offers[0] ) ? $offers[0] : $offers;

				if ( is_array( $offer ) ) {
					foreach ( array( 'price', 'priceCurrency', 'availability' ) as $property ) {
						if ( empty( $offer[ $property ] ) ) {
							$missing[] = 'offers.' . $property;
						}
					}
				}
			}

			if ( empty( $missing ) ) {
				continue;
			}

			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'schema.product.incomplete',
						'severity' => Issue::SEVERITY_HIGH,
						'key'      => ( $base['key'] ?? '' ) . '|product',
						'title'    => __( 'Product structured data is incomplete', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %s: comma-separated property names. */
							__( 'The Product node is missing %s. Google requires these for a product rich result and will drop the whole node without them.', 'seo-automation' ),
							implode( ', ', $missing )
						),
						'evidence' => array(
							'missing'        => $missing,
							'affected_count' => count( $missing ),
						),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Does the type list contain this type, allowing for subtypes?
	 *
	 * @param string[] $present  Types found.
	 * @param string   $expected Type wanted.
	 */
	private function has_type( array $present, string $expected ): bool {
		foreach ( $present as $type ) {
			if ( 0 === strcasecmp( $type, $expected ) ) {
				return true;
			}

			// NewsArticle and BlogPosting both satisfy an Article expectation.
			if ( 'Article' === $expected && preg_match( '/Article|BlogPosting|NewsArticle/i', $type ) ) {
				return true;
			}
		}

		return false;
	}
}
