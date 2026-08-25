<?php
/**
 * FAQ content and schema opportunity checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\PostChecker;
use SEOAgent\Support\Content;
use SEOAgent\Support\Html;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds pages that already answer questions in their headings but do not say
 * so in structured data, and FAQ sections whose answers are too vague to be
 * quoted by an answer engine.
 */
final class FaqChecker extends PostChecker {

	/** Meta key holding the FAQ pairs this plugin renders. */
	public const FAQ_META_KEY = '_seo_agent_faq';

	/** Below this many question headings a page is not an FAQ. */
	private const MIN_QUESTIONS = 3;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'faq';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'FAQ optimisation', 'seo-automation' );
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
		return __( 'Finds question-and-answer content that is not marked up as an FAQ, and answers too vague to be quoted.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_post( \WP_Post $post, AuditContext $context ): array {
		$questions = $this->extract_questions( Content::rendered( (string) $post->post_content ) );

		if ( count( $questions ) < self::MIN_QUESTIONS ) {
			return array();
		}

		$base = array(
			'object_type'  => 'post',
			'object_id'    => $post->ID,
			'object_label' => $post->post_title,
			'url'          => (string) get_permalink( $post ),
		);

		$issues       = array();
		$has_faq_meta = ! empty( get_post_meta( $post->ID, self::FAQ_META_KEY, true ) );
		$schema_types = array_map( 'strtolower', $context->seo->schema_types( $post->ID ) );
		$has_schema   = $has_faq_meta || in_array( 'faqpage', $schema_types, true ) || in_array( 'faq', $schema_types, true );

		if ( ! $has_schema ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'        => 'faq.schema.missing',
						'severity'    => Issue::SEVERITY_MEDIUM,
						'title'       => __( 'Question content is not marked up as an FAQ', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: %d: number of questions found. */
							__( 'This page answers %d questions in its headings but emits no FAQPage structured data. Marking it up is what makes those answers eligible to be surfaced directly.', 'seo-automation' ),
							count( $questions )
						),
						'evidence'    => array(
							'questions'      => array_slice( wp_list_pluck( $questions, 'question' ), 0, 20 ),
							'affected_count' => count( $questions ),
							'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
						),
						'fixer'       => 'faq_schema',
						'fix_mode'    => Issue::MODE_AUTO,
						'fix_payload' => array(
							'post_id' => $post->ID,
							'pairs'   => array_slice( $questions, 0, 20 ),
						),
					)
				)
			);
		}

		// An answer engine quotes self-contained answers. One that opens with
		// "It depends" or runs to a single clause gives it nothing to lift.
		$weak = array();
		foreach ( $questions as $pair ) {
			$words = Text::word_count( $pair['answer'] );

			if ( $words > 0 && $words < 20 ) {
				$weak[] = array(
					'question' => $pair['question'],
					'words'    => $words,
				);
			}
		}

		if ( ! empty( $weak ) ) {
			$issues[] = $this->issue(
				array_merge(
					$base,
					array(
						'code'     => 'faq.answer.too_short',
						'severity' => Issue::SEVERITY_LOW,
						'title'    => __( 'FAQ answers are too short to stand alone', 'seo-automation' ),
						'detail'   => sprintf(
							/* translators: %d: number of short answers. */
							__( '%d answers run to fewer than 20 words. An answer that only makes sense in context cannot be quoted on its own by a search or answer engine.', 'seo-automation' ),
							count( $weak )
						),
						'evidence' => array(
							'answers'        => array_slice( $weak, 0, 20 ),
							'affected_count' => count( $weak ),
						),
						'fix_mode' => Issue::MODE_MANUAL,
					)
				)
			);
		}

		return $issues;
	}

	/**
	 * Pull question/answer pairs out of a page's heading structure.
	 *
	 * A question heading owns everything up to the next heading of the same or
	 * higher level — the same rule a reader applies.
	 *
	 * @param string $content Post content.
	 *
	 * @return array<int,array{question:string,answer:string}>
	 */
	private function extract_questions( string $content ): array {
		$headings = Html::headings( $content );

		if ( empty( $headings ) ) {
			return array();
		}

		$pairs = array();
		$count = count( $headings );

		for ( $i = 0; $i < $count; $i++ ) {
			$heading = $headings[ $i ];
			$text    = trim( $heading['text'] );

			if ( ! $this->is_question( $text ) ) {
				continue;
			}

			$start = strpos( $content, $heading['raw'] );
			if ( false === $start ) {
				continue;
			}

			$start += strlen( $heading['raw'] );
			$end    = strlen( $content );

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $headings[ $j ]['level'] <= $heading['level'] ) {
					$next = strpos( $content, $headings[ $j ]['raw'], $start );
					if ( false !== $next ) {
						$end = $next;
					}
					break;
				}
			}

			$answer = Text::plain( substr( $content, $start, $end - $start ) );

			$pairs[] = array(
				'question' => $text,
				'answer'   => $answer,
			);
		}

		return $pairs;
	}

	/**
	 * Does this heading read as a question?
	 *
	 * @param string $text Heading text.
	 */
	private function is_question( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}

		if ( '?' === substr( $text, -1 ) ) {
			return true;
		}

		// Question-word openers without the punctuation still read as questions.
		return (bool) preg_match( '/^(how|what|why|when|where|who|which|can|do|does|is|are|should|will)\b/i', $text );
	}
}
