<?php
/**
 * Serves and generates /llms.txt.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo;

use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * llms.txt is a plain-Markdown index pointing answer engines at the pages you
 * most want them to read. This stores the body in an option and serves it at
 * the root path.
 */
final class LlmsTxt {

	public const OPTION = 'seo_agent_llms_txt';

	/**
	 * Hook the request handler.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'maybe_serve' ) );
	}

	/**
	 * Serve the stored body when /llms.txt is requested.
	 *
	 * @param \WP $wp Request object.
	 */
	public static function maybe_serve( $wp ): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( 'llms.txt' !== trim( $path, '/' ) ) {
			return;
		}

		$body = (string) get_option( self::OPTION, '' );

		if ( '' === $body ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text document, escaping would corrupt it.
		exit;
	}

	/**
	 * Build a starting llms.txt from the site's own structure.
	 *
	 * Lists top-level pages and the largest categories, which is a reasonable
	 * first approximation of "what this site is for".
	 */
	public static function generate(): string {
		$lines = array();

		$lines[] = '# ' . get_bloginfo( 'name' );
		$lines[] = '';

		$tagline = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_parent'    => 0,
				'numberposts'    => 25,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $pages ) ) {
			$lines[] = '## Pages';
			$lines[] = '';

			foreach ( $pages as $page ) {
				$summary = Text::truncate(
					Text::plain( '' !== $page->post_excerpt ? $page->post_excerpt : $page->post_content ),
					120,
					''
				);

				$lines[] = sprintf(
					'- [%s](%s)%s',
					$page->post_title,
					get_permalink( $page ),
					'' !== $summary ? ': ' . $summary : ''
				);
			}

			$lines[] = '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category',
				'hide_empty' => true,
				'number'     => 25,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$lines[] = '## Categories';
			$lines[] = '';

			foreach ( $terms as $term ) {
				$summary = Text::truncate( Text::plain( (string) $term->description ), 120, '' );

				$lines[] = sprintf(
					'- [%s](%s)%s',
					$term->name,
					get_term_link( $term ),
					'' !== $summary ? ': ' . $summary : ''
				);
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
