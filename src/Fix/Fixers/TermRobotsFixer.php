<?php
/**
 * Sets robots directives on taxonomy terms.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excludes thin or empty archives from the index.
 *
 * Deterministic — the directive is fixed and the issue already established
 * that the archive does not warrant indexing — but note it always pairs
 * noindex with follow, so the links on the page still pass value on.
 */
final class TermRobotsFixer extends AbstractFixer {

	/** Directives this fixer will write. */
	private const ALLOWED = array( 'noindex,follow', 'index,follow', 'noindex,nofollow' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'term_robots';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set archive indexing rule', 'seo-automation' );
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
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$term_id = (int) ( $payload['term_id'] ?? $issue['object_id'] ?? 0 );
		$term    = $this->require_term( $term_id );

		$robots = strtolower( trim( (string) ( $input['robots'] ?? $payload['robots'] ?? 'noindex,follow' ) ) );
		$robots = preg_replace( '/\s*,\s*/', ',', $robots ) ?? $robots;

		if ( ! in_array( $robots, self::ALLOWED, true ) ) {
			throw new FixException(
				esc_html( sprintf( '"%s" is not a directive this fixer writes. Allowed: %s.', $robots, implode( ', ', self::ALLOWED ) ) ),
				'invalid_directive'
			);
		}

		if ( ! $seo->supports( SeoAdapterInterface::FIELD_ROBOTS ) ) {
			throw new FixException(
				esc_html( sprintf( '%s does not expose a robots field for terms.', $seo->label() ) ),
				'unsupported_by_adapter'
			);
		}

		$before = $seo->get( SeoAdapterInterface::FIELD_ROBOTS, 'term', $term_id );

		return array(
			new FixChange(
				'term',
				$term_id,
				'seo:' . SeoAdapterInterface::FIELD_ROBOTS,
				$before,
				$robots,
				sprintf(
					/* translators: 1: directive, 2: term name. */
					__( 'Set "%1$s" on the "%2$s" archive', 'seo-automation' ),
					$robots,
					$term->name
				)
			),
		);
	}
}
