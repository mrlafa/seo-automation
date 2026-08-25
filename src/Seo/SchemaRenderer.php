<?php
/**
 * Renders structured data this plugin owns.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo;

use SEOAgent\Audit\Checkers\FaqChecker;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Outputs the FAQPage JSON-LD assembled by the FAQ fixer.
 *
 * Deliberately narrow: this does not try to own Article, Product or
 * Organization markup, because on most sites something else already emits
 * those and two competing graphs is worse than one.
 */
final class SchemaRenderer {

	/**
	 * Hook the output.
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'render_faq' ), 20 );
	}

	/**
	 * Print FAQPage JSON-LD when the current page has stored pairs.
	 */
	public static function render_faq(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$pairs   = get_post_meta( $post_id, FaqChecker::FAQ_META_KEY, true );

		if ( ! is_array( $pairs ) || count( $pairs ) < 2 ) {
			return;
		}

		$entities = array();

		foreach ( $pairs as $pair ) {
			$question = trim( (string) ( $pair['question'] ?? '' ) );
			$answer   = trim( (string) ( $pair['answer'] ?? '' ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		if ( count( $entities ) < 2 ) {
			return;
		}

		$graph = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post_id ) . '#faq',
			'mainEntity' => $entities,
		);

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}
}
