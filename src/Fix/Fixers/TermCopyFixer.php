<?php
/**
 * Writes the on-page description of a taxonomy archive.
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
 * The term description is the visible introduction on a category page, as
 * distinct from its meta description. Most themes render it above the listing.
 */
final class TermCopyFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'term_copy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Write archive introduction', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value' => __( 'Introductory copy for the archive page. Basic HTML is allowed. Write for the shopper or reader landing here, not for the crawler.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$term_id = (int) ( $payload['term_id'] ?? $issue['object_id'] ?? 0 );
		$term    = $this->require_term( $term_id );

		$value = $this->value_from( $input, $issue );

		if ( null === $value ) {
			throw new FixException(
				sprintf( 'No copy supplied for the "%s" archive.', $term->name ),
				'input_required',
				array( 'term_id' => $term_id )
			);
		}

		$value = wp_kses_post( $value );

		if ( Text::word_count( $value ) < 20 ) {
			throw new FixException(
				'Archive copy under 20 words does not do the job the missing-copy issue was raised for.',
				'value_too_short',
				array( 'word_count' => Text::word_count( $value ) )
			);
		}

		return array(
			new FixChange(
				'term',
				$term_id,
				'term:description',
				(string) $term->description,
				$value,
				sprintf(
					/* translators: %s: term name. */
					__( 'Added introductory copy to the "%s" archive', 'seo-automation' ),
					$term->name
				)
			),
		);
	}
}
