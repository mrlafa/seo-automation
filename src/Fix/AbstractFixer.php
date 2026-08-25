<?php
/**
 * Shared write dispatch for fixers.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

use SEOAgent\Seo\SeoAdapterInterface;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Implements write() once, for every storage location a fix can target.
 *
 * Field names are namespaced by where the value lives — `seo:title`,
 * `post:post_content`, `meta:_wp_attachment_image_alt`, `option:blog_public`
 * — so a single writer covers every fixer, and reverting is the same code path
 * with the before and after values swapped.
 */
abstract class AbstractFixer implements FixerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function is_deterministic(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( FixChange $change, SeoAdapterInterface $seo ): bool {
		[ $scope, $name ] = $this->split_field( $change->field );

		switch ( $scope ) {
			case 'seo':
				return $seo->set( $name, $change->object_type, $change->object_id, $change->after );

			case 'post':
				return $this->write_post_column( $change, $name );

			case 'meta':
				return $this->write_meta( $change, $name );

			case 'termmeta':
				$result = null === $change->after
					? delete_term_meta( $change->object_id, $name )
					: update_term_meta( $change->object_id, $name, $change->after );

				return false !== $result;

			case 'term':
				return $this->write_term_column( $change, $name );

			case 'option':
				return update_option( $name, $change->after );

			default:
				throw new FixException(
					sprintf( 'Unknown field scope "%s".', $scope ),
					'unknown_field_scope',
					array( 'field' => $change->field )
				);
		}
	}

	/**
	 * Write a column on the posts table.
	 *
	 * @param FixChange $change Change.
	 * @param string    $column Column name.
	 */
	private function write_post_column( FixChange $change, string $column ): bool {
		$allowed = array( 'post_content', 'post_title', 'post_excerpt', 'post_name', 'post_status' );

		if ( ! in_array( $column, $allowed, true ) ) {
			throw new FixException(
				sprintf( 'Column "%s" is not writable.', $column ),
				'column_not_writable'
			);
		}

		$result = wp_update_post(
			array(
				'ID'     => $change->object_id,
				$column  => (string) $change->after,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new FixException( $result->get_error_message(), 'post_update_failed' );
		}

		return true;
	}

	/**
	 * Write post meta.
	 *
	 * @param FixChange $change Change.
	 * @param string    $key    Meta key.
	 */
	private function write_meta( FixChange $change, string $key ): bool {
		if ( null === $change->after || '' === $change->after ) {
			return false !== delete_post_meta( $change->object_id, $key );
		}

		$value = $change->after;

		// Meta that is stored structured rather than as a string round-trips
		// through JSON in the change log.
		if ( ! empty( $change->meta['json'] ) ) {
			$decoded = json_decode( $change->after, true );
			$value   = null === $decoded ? $change->after : $decoded;
		}

		return false !== update_post_meta( $change->object_id, $key, $value );
	}

	/**
	 * Write a term field.
	 *
	 * @param FixChange $change Change.
	 * @param string    $field  Field name.
	 */
	private function write_term_column( FixChange $change, string $field ): bool {
		$allowed = array( 'description', 'name', 'slug' );

		if ( ! in_array( $field, $allowed, true ) ) {
			throw new FixException(
				sprintf( 'Term field "%s" is not writable.', $field ),
				'field_not_writable'
			);
		}

		$term = get_term( $change->object_id );

		if ( ! $term || is_wp_error( $term ) ) {
			throw new FixException( 'Term not found.', 'term_missing' );
		}

		$result = wp_update_term( $change->object_id, $term->taxonomy, array( $field => (string) $change->after ) );

		if ( is_wp_error( $result ) ) {
			throw new FixException( $result->get_error_message(), 'term_update_failed' );
		}

		return true;
	}

	/**
	 * Split `scope:name` into its parts.
	 *
	 * @param string $field Namespaced field.
	 *
	 * @return array{0:string,1:string}
	 */
	private function split_field( string $field ): array {
		$parts = explode( ':', $field, 2 );

		if ( 2 !== count( $parts ) ) {
			throw new FixException(
				sprintf( 'Field "%s" is missing its scope prefix.', $field ),
				'malformed_field'
			);
		}

		return array( $parts[0], $parts[1] );
	}

	/**
	 * Fetch the post an issue refers to, or fail loudly.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function require_post( int $post_id ): \WP_Post {
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new FixException(
				sprintf( 'Post %d no longer exists.', $post_id ),
				'post_missing',
				array( 'post_id' => $post_id )
			);
		}

		return $post;
	}

	/**
	 * Fetch the term an issue refers to, or fail loudly.
	 *
	 * @param int $term_id Term ID.
	 */
	protected function require_term( int $term_id ): \WP_Term {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			throw new FixException(
				sprintf( 'Term %d no longer exists.', $term_id ),
				'term_missing',
				array( 'term_id' => $term_id )
			);
		}

		return $term;
	}

	/**
	 * First non-empty value from the caller's input, the issue's suggestion, or
	 * nothing.
	 *
	 * @param array<string,mixed> $input Caller input.
	 * @param array<string,mixed> $issue Issue row.
	 * @param string              $key   Input key.
	 */
	protected function value_from( array $input, array $issue, string $key = 'value' ): ?string {
		if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
			return trim( (string) $input[ $key ] );
		}

		$suggestion = $issue['fix_payload']['suggestion'] ?? '';

		return '' !== trim( (string) $suggestion ) ? trim( (string) $suggestion ) : null;
	}
}
