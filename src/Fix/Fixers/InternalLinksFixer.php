<?php
/**
 * Inserts internal links into existing content.
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
 * Links an existing phrase in the content to another page.
 *
 * Only phrases already present are linked — nothing is appended, no "related
 * posts" block is bolted on. The caller chooses the phrase and the target,
 * because deciding what deserves a link is editorial judgement, and a link
 * inserted around the wrong phrase is worse than no link at all.
 */
final class InternalLinksFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'internal_links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Add internal links', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'links' => __( 'A list of {source_post_id, phrase, target_post_id} objects. The phrase must already appear as plain text in the source page.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$links = isset( $input['links'] ) && is_array( $input['links'] ) ? $input['links'] : array();

		if ( empty( $links ) ) {
			throw new FixException(
				'Pass "links" describing which phrase in which page should link where.',
				'input_required',
				array(
					'target_post_id' => $issue['fix_payload']['target_post_id'] ?? null,
					'post_id'        => $issue['fix_payload']['post_id'] ?? $issue['object_id'] ?? null,
				)
			);
		}

		// Group by source page so several links into one page are a single edit.
		$by_source = array();

		foreach ( $links as $link ) {
			$source = (int) ( $link['source_post_id'] ?? 0 );

			if ( $source > 0 ) {
				$by_source[ $source ][] = $link;
			}
		}

		if ( empty( $by_source ) ) {
			throw new FixException( 'No usable source page in the supplied links.', 'no_target' );
		}

		$changes = array();

		foreach ( $by_source as $source_id => $entries ) {
			$post    = $this->require_post( $source_id );
			$content = (string) $post->post_content;
			$updated = $content;
			$applied = 0;

			foreach ( $entries as $entry ) {
				$phrase    = trim( (string) ( $entry['phrase'] ?? '' ) );
				$target_id = (int) ( $entry['target_post_id'] ?? 0 );

				if ( '' === $phrase || $target_id <= 0 ) {
					continue;
				}

				if ( $target_id === $source_id ) {
					throw new FixException( 'A page cannot link to itself.', 'self_link' );
				}

				$target_url = (string) get_permalink( $target_id );

				if ( '' === $target_url ) {
					throw new FixException(
						sprintf( 'Target post %d has no permalink.', $target_id ),
						'target_missing'
					);
				}

				$result = $this->link_phrase( $updated, $phrase, $target_url );

				if ( null === $result ) {
					throw new FixException(
						sprintf( 'The phrase "%1$s" was not found as unlinked text in "%2$s".', $phrase, $post->post_title ),
						'phrase_not_found',
						array(
							'source_post_id' => $source_id,
							'phrase'         => $phrase,
						)
					);
				}

				$updated = $result;
				++$applied;
			}

			if ( 0 === $applied || $updated === $content ) {
				continue;
			}

			$changes[] = new FixChange(
				'post',
				$source_id,
				'post:post_content',
				$content,
				$updated,
				sprintf(
					/* translators: 1: number of links, 2: post title. */
					__( 'Added %1$d internal links to "%2$s"', 'seo-automation' ),
					$applied,
					$post->post_title
				)
			);
		}

		if ( empty( $changes ) ) {
			throw new FixException( 'Nothing was changed.', 'no_change' );
		}

		return $changes;
	}

	/**
	 * Wrap the first unlinked occurrence of a phrase in an anchor.
	 *
	 * @param string $content Post content.
	 * @param string $phrase  Phrase to link.
	 * @param string $url     Destination.
	 *
	 * @return string|null Updated content, or null when the phrase was not found.
	 */
	private function link_phrase( string $content, string $phrase, string $url ): ?string {
		// Skip occurrences inside an existing anchor, inside an HTML attribute,
		// or inside a heading — nesting anchors produces invalid markup.
		$pattern = sprintf(
			'#(?<![\w>])%s(?![\w<])(?![^<]*</a>)(?![^<]*>)#i',
			preg_quote( $phrase, '#' )
		);

		$replacement = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			str_replace( '$', '\$', esc_html( $phrase ) )
		);

		$result = preg_replace( $pattern, $replacement, $content, 1, $count );

		if ( null === $result || 0 === $count ) {
			return null;
		}

		return $result;
	}
}
