<?php
/**
 * Writes SEO metadata on taxonomy terms.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term title and description fixer.
 */
final class TermMetaFixer extends AbstractFixer {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'term_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Set archive title or description', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value' => __( 'The title or description text. Which one is taken from the issue.', 'seo-automation' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );
		$term_id = (int) ( $payload['term_id'] ?? $issue['object_id'] ?? 0 );
		$term    = $this->require_term( $term_id );

		$field = (string) ( $payload['field'] ?? SeoAdapterInterface::FIELD_DESCRIPTION );

		if ( ! in_array( $field, array( SeoAdapterInterface::FIELD_TITLE, SeoAdapterInterface::FIELD_DESCRIPTION ), true ) ) {
			throw new FixException(
				esc_html( sprintf( 'Field "%s" is not one this fixer handles.', $field ) ),
				'unsupported_field'
			);
		}

		$value = $this->value_from( $input, $issue );

		if ( null === $value ) {
			throw new FixException(
				esc_html( sprintf( 'No %s supplied for the "%s" archive.', $field, $term->name ) ),
				'input_required',
				array( 'term_id' => $term_id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is machine-readable metadata for the REST layer, never rendered; the message argument is escaped.
			);
		}

		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) ?? $value );

		$limit = SeoAdapterInterface::FIELD_TITLE === $field
			? (int) Options::get( 'title_max_length', 60 )
			: (int) Options::get( 'description_max_length', 155 );

		if ( Text::length( $value ) > $limit * 1.5 ) {
			throw new FixException(
				esc_html( sprintf( 'The supplied %1$s is %2$d characters against a %3$d-character target.', $field, Text::length( $value ), $limit ) ),
				'value_too_long'
			);
		}

		$before = $seo->get( $field, 'term', $term_id );

		return array(
			new FixChange(
				'term',
				$term_id,
				'seo:' . $field,
				$before,
				$value,
				sprintf(
					/* translators: 1: field name, 2: term name. */
					__( 'Set the %1$s on the "%2$s" archive', 'seo-automation' ),
					$field,
					$term->name
				)
			),
		);
	}
}
