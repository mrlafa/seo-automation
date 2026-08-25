<?php
/**
 * Publishes an llms.txt.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\LlmsTxt;
use SEOAgent\Seo\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the llms.txt body in an option; the plugin serves it at /llms.txt.
 *
 * Writing an actual file to the web root would need filesystem permissions the
 * plugin may not have and would survive uninstalling, so it is served rather
 * than written.
 */
final class LlmsTxtFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'llms_txt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Publish llms.txt', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'content' => __( 'The llms.txt body in Markdown. Omit to generate one from the site name, tagline and top-level pages.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$content = trim( (string) ( $input['content'] ?? '' ) );

		if ( '' === $content ) {
			$content = LlmsTxt::generate();
		}

		if ( strlen( $content ) > 200000 ) {
			throw new FixException( esc_html( 'The llms.txt body is implausibly large.' ), 'value_too_long' );
		}

		$before = (string) get_option( LlmsTxt::OPTION, '' );

		if ( $before === $content ) {
			throw new FixException( esc_html( 'That is already the published llms.txt.' ), 'no_change' );
		}

		return array(
			new FixChange(
				'option',
				0,
				'option:' . LlmsTxt::OPTION,
				'' === $before ? null : $before,
				$content,
				sprintf(
					/* translators: %s: llms.txt URL. */
					__( 'Published %s', 'seo-automation' ),
					home_url( '/llms.txt' )
				)
			),
		);
	}
}
