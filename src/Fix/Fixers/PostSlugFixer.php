<?php
/**
 * Rewrites post slugs.
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
 * Changing a slug changes the URL, which breaks every existing link to the page
 * unless a redirect goes with it. The old permalink is recorded in the change
 * note and in the change log so the redirect can be created — and so reverting
 * genuinely puts the old URL back.
 */
final class PostSlugFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'post_slug';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Change page slug', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value'           => __( 'The new slug, lowercase and hyphen-separated.', 'seo-automation' ),
			'acknowledge_301' => __( 'Must be true. Confirms you accept that the old URL stops working unless you add a 301 redirect to the new one.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$post_id = (int) ( $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$value = $this->value_from( $input, $issue );

		if ( null === $value ) {
			throw new FixException( 'No slug supplied.', 'input_required', array( 'post_id' => $post_id ) );
		}

		$new_slug = sanitize_title( $value );

		if ( '' === $new_slug ) {
			throw new FixException( 'The supplied slug reduces to nothing after sanitisation.', 'invalid_slug' );
		}

		if ( $new_slug === $post->post_name ) {
			throw new FixException( 'That is already the slug.', 'no_change' );
		}

		// The URL is a published contract with everyone who has linked to it.
		// Changing it without acknowledging the consequence is not something
		// this fixer will do silently.
		if ( empty( $input['acknowledge_301'] ) ) {
			throw new FixException(
				sprintf(
					'Changing this slug will break %s. Set "acknowledge_301" to true to proceed, and add a 301 redirect from the old path to the new one.',
					(string) get_permalink( $post )
				),
				'redirect_acknowledgement_required',
				array(
					'old_url'  => (string) get_permalink( $post ),
					'old_slug' => (string) $post->post_name,
					'new_slug' => $new_slug,
				)
			);
		}

		return array(
			new FixChange(
				'post',
				$post_id,
				'post:post_name',
				(string) $post->post_name,
				$new_slug,
				sprintf(
					/* translators: 1: old URL, 2: new slug. */
					__( 'Changed slug on "%1$s" to "%2$s" — add a 301 from the old URL.', 'seo-automation' ),
					(string) get_permalink( $post ),
					$new_slug
				),
				array( 'old_url' => (string) get_permalink( $post ) )
			),
		);
	}
}
