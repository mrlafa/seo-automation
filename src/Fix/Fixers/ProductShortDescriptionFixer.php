<?php
/**
 * Writes the WooCommerce short description.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * WooCommerce stores the short description in `post_excerpt`.
 */
final class ProductShortDescriptionFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'product_short_description';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Write product short description', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value' => __( 'The short description shown beside the price. Lead with what the product is and who it is for.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload    = (array) ( $issue['fix_payload'] ?? array() );
		$product_id = (int) ( $payload['product_id'] ?? $issue['object_id'] ?? 0 );
		$post       = $this->require_post( $product_id );

		if ( 'product' !== $post->post_type ) {
			throw new FixException(
				sprintf( 'Post %d is not a product.', $product_id ),
				'not_a_product'
			);
		}

		$value = $this->value_from( $input, $issue );

		if ( null === $value ) {
			throw new FixException(
				sprintf( 'No short description supplied for "%s".', $post->post_title ),
				'input_required',
				array( 'product_id' => $product_id )
			);
		}

		$value = wp_kses_post( $value );

		if ( Text::word_count( $value ) < 10 ) {
			throw new FixException(
				'A short description under 10 words does not replace the missing one meaningfully.',
				'value_too_short'
			);
		}

		return array(
			new FixChange(
				'post',
				$product_id,
				'post:post_excerpt',
				(string) $post->post_excerpt,
				$value,
				sprintf(
					/* translators: %s: product name. */
					__( 'Added a short description to "%s"', 'seo-automation' ),
					$post->post_title
				)
			),
		);
	}
}
