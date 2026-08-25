<?php
/**
 * Stores FAQ pairs for structured data output.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Audit\Checkers\FaqChecker;
use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Saves question and answer pairs that the schema renderer turns into FAQPage
 * JSON-LD.
 *
 * The pairs are stored rather than injected into the content, so the visible
 * page is untouched and the markup can be regenerated or removed cleanly. The
 * answers are taken verbatim from what is already published — inventing an
 * answer that is not on the page would be marking up content that does not
 * exist, which is exactly what earns a structured data penalty.
 */
final class FaqSchemaFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'faq_schema';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Add FAQ structured data', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_deterministic(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'pairs' => __( 'Optional. A list of {question, answer} objects to store instead of the ones detected on the page. Every answer must already appear in the visible content.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$post_id = (int) ( $payload['post_id'] ?? $issue['object_id'] ?? 0 );
		$post    = $this->require_post( $post_id );

		$pairs = isset( $input['pairs'] ) && is_array( $input['pairs'] )
			? $input['pairs']
			: (array) ( $payload['pairs'] ?? array() );

		$clean   = array();
		$visible = Text::plain( (string) $post->post_content );

		foreach ( $pairs as $pair ) {
			$question = trim( wp_strip_all_tags( (string) ( $pair['question'] ?? '' ), true ) );
			$answer   = trim( wp_strip_all_tags( (string) ( $pair['answer'] ?? '' ), true ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			// Marking up an answer the visitor cannot see is a structured data
			// violation, so anything not present in the content is dropped.
			$probe = Text::truncate( $answer, 60, '' );

			if ( '' !== $probe && ! Text::contains( $visible, $probe ) ) {
				continue;
			}

			$clean[] = array(
				'question' => $question,
				'answer'   => Text::truncate( $answer, 1000, '' ),
			);
		}

		if ( count( $clean ) < 2 ) {
			throw new FixException(
				'Fewer than two question and answer pairs could be verified against the visible page content.',
				'insufficient_pairs',
				array( 'verified' => count( $clean ) )
			);
		}

		$before = get_post_meta( $post_id, FaqChecker::FAQ_META_KEY, true );

		return array(
			new FixChange(
				'post',
				$post_id,
				'meta:' . FaqChecker::FAQ_META_KEY,
				empty( $before ) ? null : (string) wp_json_encode( $before ),
				(string) wp_json_encode( $clean ),
				sprintf(
					/* translators: 1: number of pairs, 2: post title. */
					__( 'Stored %1$d FAQ pairs for structured data on "%2$s"', 'seo-automation' ),
					count( $clean ),
					$post->post_title
				),
				array( 'json' => true )
			),
		);
	}
}
