<?php
/**
 * Sets canonical URLs.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Writes a canonical URL, either correcting a malformed one or pointing a
 * near-duplicate at the version that should rank.
 */
final class CanonicalFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'canonical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set canonical URL', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'canonical' => __( 'The absolute URL that should rank. Omit to use the value the issue proposes.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$post_id = (int) ( $payload['post_id'] ?? $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$canonical = trim( (string) ( $input['canonical'] ?? $payload['canonical'] ?? $payload['canonical_target'] ?? '' ) );

		if ( '' === $canonical ) {
			throw new FixException( 'No canonical URL supplied.', 'input_required', array( 'post_id' => $post_id ) );
		}

		if ( ! preg_match( '#^https?://#i', $canonical ) ) {
			throw new FixException(
				sprintf( '"%s" is not an absolute URL. Canonicals must include the scheme and host.', $canonical ),
				'invalid_canonical'
			);
		}

		$canonical = esc_url_raw( $canonical );

		// Pointing a page at itself is the default behaviour anyway; storing it
		// explicitly is harmless but pointing it at a 404 is not.
		if ( untrailingslashit( $canonical ) === untrailingslashit( (string) get_permalink( $post ) ) ) {
			$note = __( 'Set an explicit self-referencing canonical', 'seo-automation' );
		} else {
			$note = sprintf(
				/* translators: 1: post title, 2: canonical target. */
				__( 'Pointed "%1$s" at %2$s as the canonical version', 'seo-automation' ),
				$post->post_title,
				$canonical
			);
		}

		$before = $seo->get( SeoAdapterInterface::FIELD_CANONICAL, 'post', $post_id );

		return array(
			new FixChange(
				'post',
				$post_id,
				'seo:' . SeoAdapterInterface::FIELD_CANONICAL,
				$before,
				$canonical,
				$note
			),
		);
	}
}
