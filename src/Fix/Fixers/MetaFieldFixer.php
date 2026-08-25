<?php
/**
 * Base for the title and description fixers.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix\Fixers;

use SEOAgent\Fix\AbstractFixer;
use SEOAgent\Fix\FixChange;
use SEOAgent\Fix\FixException;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one SEO metadata field, on one post or across a set of them.
 */
abstract class MetaFieldFixer extends AbstractFixer {

	/**
	 * Which adapter field this fixer writes.
	 */
	abstract protected function field(): string;

	/**
	 * Maximum sensible length, used to reject obviously wrong input.
	 */
	abstract protected function max_length(): int;

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array(
			'value' => sprintf(
				/* translators: 1: field name, 2: maximum length. */
				__( 'The %1$s to write, at most %2$d characters. When fixing several pages at once, pass "values" as a map of post ID to string instead.', 'seo-automation' ),
				$this->field(),
				$this->max_length()
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( array $issue, array $input, SeoAdapterInterface $seo ): array {
		$targets = $this->targets( $issue );

		if ( empty( $targets ) ) {
			throw new FixException( esc_html( 'This issue names no page to update.' ), 'no_target' );
		}

		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		$single = $this->value_from( $input, $issue );

		$changes = array();

		foreach ( $targets as $post_id ) {
			$post  = $this->require_post( $post_id );
			$value = $values[ $post_id ] ?? $values[ (string) $post_id ] ?? null;

			// A single value applied to several pages would recreate the very
			// duplication most of these issues are about.
			if ( null === $value ) {
				if ( count( $targets ) > 1 ) {
					throw new FixException(
						esc_html( 'This issue covers several pages, so pass a distinct value per page in "values" keyed by post ID.' ),
						'per_page_values_required',
						array( 'post_ids' => $targets ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is machine-readable metadata for the REST layer, never rendered; the message argument is escaped.
					);
				}

				$value = $single;
			}

			if ( null === $value || '' === trim( (string) $value ) ) {
				throw new FixException(
					esc_html( sprintf( 'No %s supplied for post %d and the issue carries no usable suggestion.', $this->field(), $post_id ) ),
					'input_required',
					array( 'post_id' => $post_id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is machine-readable metadata for the REST layer, never rendered; the message argument is escaped.
				);
			}

			$value = $this->sanitise( (string) $value );

			if ( Text::length( $value ) > $this->max_length() * 1.5 ) {
				throw new FixException(
					esc_html( sprintf(
						'The supplied %1$s is %2$d characters, far beyond the %3$d-character target.',
						$this->field(),
						Text::length( $value ),
						$this->max_length()
					) ),
					'value_too_long',
					array( 'post_id' => $post_id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception context is machine-readable metadata for the REST layer, never rendered; the message argument is escaped.
				);
			}

			$before = $seo->get( $this->field(), 'post', $post_id );

			$changes[] = new FixChange(
				'post',
				$post_id,
				'seo:' . $this->field(),
				$before,
				$value,
				sprintf(
					/* translators: 1: field, 2: post title. */
					__( 'Set the %1$s on "%2$s"', 'seo-automation' ),
					$this->field(),
					$post->post_title
				)
			);
		}

		return $changes;
	}

	/**
	 * Posts this issue covers.
	 *
	 * @param array<string,mixed> $issue Issue row.
	 *
	 * @return int[]
	 */
	private function targets( array $issue ): array {
		$payload = (array) ( $issue['fix_payload'] ?? array() );

		if ( ! empty( $payload['post_ids'] ) && is_array( $payload['post_ids'] ) ) {
			return array_values( array_filter( array_map( 'intval', $payload['post_ids'] ) ) );
		}

		$object_id = (int) ( $issue['object_id'] ?? 0 );

		return $object_id > 0 ? array( $object_id ) : array();
	}

	/**
	 * Strip markup and collapse whitespace — metadata is plain text.
	 *
	 * @param string $value Raw input.
	 */
	private function sanitise( string $value ): string {
		$clean = wp_strip_all_tags( $value, true );

		return trim( preg_replace( '/\s+/u', ' ', $clean ) ?? $clean );
	}
}
