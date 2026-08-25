<?php
/**
 * Renders stored content the way a visitor would actually see it.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Support;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Bridges the gap between what is stored in post_content and what is on the
 * page.
 *
 * Block-based page builders — Divi 5 and any block with a PHP render
 * callback — can store the real text as JSON inside a block's attributes
 * rather than as static HTML between the block comments. Reading
 * post_content directly then sees a fully built page as empty, because there
 * is no HTML there for Text::plain() to strip down to prose; the words exist
 * only inside a callback that has not run yet.
 *
 * do_blocks() is WordPress's own block pipeline: it parses the block
 * comments and invokes each block's registered render_callback, including
 * ones a third-party plugin registered. That makes it the only reliable way
 * to recover real text outside of an actual front-end request.
 *
 * This is for reading content only. Fixers that edit post_content directly
 * (headings, inline alt text, links) must keep working on the raw, unrendered
 * value — writing back the rendered form would permanently replace the block
 * source and break the page in the visual builder.
 */
final class Content {

	/**
	 * Render stored content through WordPress's block pipeline when it looks
	 * like it needs to be.
	 *
	 * @param string $raw Raw post_content.
	 */
	public static function rendered( string $raw ): string {
		// No block markers at all: this is classic-editor content, and running
		// it through do_blocks() would cost a full pass over every post on the
		// site for no benefit.
		if ( '' === $raw || false === strpos( $raw, '<!-- wp:' ) ) {
			return $raw;
		}

		if ( ! function_exists( 'do_blocks' ) ) {
			return $raw;
		}

		return (string) do_blocks( $raw );
	}
}
