<?php
/**
 * WooCommerce product SEO checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the product fields that drive rich results and Merchant listings.
 *
 * Most of these are not "SEO copy" problems — they are missing structured
 * facts (price, SKU, availability, brand) without which a product cannot
 * produce a valid Product snippet no matter how good the description is.
 */
final class ProductSeoChecker extends PostChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'product_seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Product SEO', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'commerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Checks WooCommerce products for the fields that rich results and shopping surfaces require.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_applicable(): bool {
		return $this->has_woocommerce();
	}

	/**
	 * Only products, regardless of what else the run covers.
	 *
	 * {@inheritDoc}
	 */
	protected function post_types( AuditContext $context ): array {
		return post_type_exists( 'product' ) ? array( 'product' ) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return array();
		}

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
		);

		$evidence = array(
			'sku'          => (string) $product->get_sku(),
			'price'        => (string) $product->get_price(),
			'stock_status' => (string) $product->get_stock_status(),
			'type'         => (string) $product->get_type(),
			'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
		);

		$issues = array();

		if ( '' === trim( (string) $product->get_short_description() ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'product.short_description.missing',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Product has no short description', 'seo-automation' ),
						'detail'      => __( 'The short description is what appears beside the price and is the natural source for the meta description. Without it the product page opens with nothing but attributes.', 'seo-automation' ),
						'evidence'    => $evidence,
						'fixer'       => 'product_short_description',
						'fix_mode'    => Issue::MODE_ASSISTED,
						'fix_payload' => array( 'product_id' => $post->ID ),
					)
				)
			);
		}

		if ( Text::word_count( (string) $post->post_content ) < 50 ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.description.thin',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Product description is very thin', 'seo-automation' ),
						'detail'   => __( 'Under 50 words of description gives search engines almost nothing to distinguish this product from every other listing of the same item.', 'seo-automation' ),
						'evidence' => array_merge( $evidence, array( 'word_count' => Text::word_count( (string) $post->post_content ) ) ),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		if ( ! has_post_thumbnail( $post->ID ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.image.missing',
						'severity' => Issue::SEVERITY_HIGH,
						'title'    => __( 'Product has no main image', 'seo-automation' ),
						'detail'   => __( 'Product rich results require an image. Without one this product cannot appear as a product snippet or in shopping surfaces.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		if ( '' === trim( (string) $product->get_sku() ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.sku.missing',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Product has no SKU', 'seo-automation' ),
						'detail'   => __( 'The SKU populates the product identifier in structured data and is how feeds match this item across channels.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		// A variable product carries prices on its variations, so only simple
		// products are judged on their own price field.
		if ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' ) && '' === (string) $product->get_price() ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.price.missing',
						'severity' => Issue::SEVERITY_HIGH,
						'title'    => __( 'Product has no price', 'seo-automation' ),
						'detail'   => __( 'Product structured data requires an offer with a price. A priceless product produces invalid Product schema and is dropped from rich results.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		if ( 'outofstock' === $product->get_stock_status() && ! $context->seo->is_noindex( 'post', $post->ID ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.out_of_stock_indexable',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Out-of-stock product is indexable', 'seo-automation' ),
						'detail'   => __( 'This is fine if the product is coming back — keep the URL and the schema availability accurate. If it is gone for good, redirect it rather than leaving a dead-end result in the index.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		$categories = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_array( $categories ) && empty( $categories ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.category.missing',
						'severity' => Issue::SEVERITY_MEDIUM,
						'title'    => __( 'Product is not in any category', 'seo-automation' ),
						'detail'   => __( 'An uncategorised product is reachable only from search and the shop listing. It receives no internal links from category pages, which is where most product authority comes from.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		$issues = array_merge( $issues, $this->check_identifiers( $product, $post, $base, $evidence ) );

		return $issues;
	}

	/**
	 * Brand and GTIN, which shopping surfaces increasingly expect.
	 *
	 * WooCommerce core has no canonical field for either, so several common
	 * conventions are accepted before reporting one as absent.
	 *
	 * @param object              $product  WooCommerce product.
	 * @param \WP_Post            $post     Product post.
	 * @param array<string,mixed> $base     Shared issue fields.
	 * @param array<string,mixed> $evidence Shared evidence.
	 *
	 * @return Issue[]
	 */
	private function check_identifiers( $product, \WP_Post $post, array $base, array $evidence ): array {
		$issues = array();

		$has_brand = false;
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$has_brand = true;
				break;
			}
		}

		if ( ! $has_brand && '' === (string) get_post_meta( $post->ID, '_wc_brand', true ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.brand.missing',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'Product has no brand', 'seo-automation' ),
						'detail'   => __( 'Brand is a recommended property of Product structured data and is used to match listings across shopping surfaces.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		$gtin_keys = array( '_wpm_gtin_code', '_gtin', 'gtin', '_ean', 'ean', '_upc', '_mpn' );
		$has_gtin  = false;

		foreach ( $gtin_keys as $key ) {
			if ( '' !== trim( (string) get_post_meta( $post->ID, $key, true ) ) ) {
				$has_gtin = true;
				break;
			}
		}

		if ( ! $has_gtin ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'product.gtin.missing',
						'severity' => Issue::SEVERITY_INFO,
						'title'    => __( 'Product has no GTIN, EAN or MPN', 'seo-automation' ),
						'detail'   => __( 'A global identifier lets shopping surfaces match this listing to the same product elsewhere. Optional, but it is what unlocks price comparison placement.', 'seo-automation' ),
						'evidence' => $evidence,
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return $issues;
	}
}
