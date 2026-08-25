<?php
/**
 * Rank Math (free and Pro) adapter.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo\Adapters;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Reads and writes Rank Math's post/term meta and title settings.
 */
final class RankMathAdapter extends AbstractAdapter {

	/** Rank Math's global title/meta defaults. */
	private const TITLES_OPTION = 'rank-math-options-titles';

	/** @var array<string,mixed>|null */
	private $titles_option = null;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'rank_math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key_map(): array {
		return array(
			self::FIELD_TITLE         => array(
				'post' => 'rank_math_title',
				'term' => 'rank_math_title',
			),
			self::FIELD_DESCRIPTION   => array(
				'post' => 'rank_math_description',
				'term' => 'rank_math_description',
			),
			self::FIELD_CANONICAL     => array(
				'post' => 'rank_math_canonical_url',
				'term' => 'rank_math_canonical_url',
			),
			self::FIELD_ROBOTS        => array(
				'post' => 'rank_math_robots',
				'term' => 'rank_math_robots',
			),
			self::FIELD_FOCUS_KEYWORD => array(
				'post' => 'rank_math_focus_keyword',
				'term' => 'rank_math_focus_keyword',
			),
			self::FIELD_OG_TITLE      => array(
				'post' => 'rank_math_facebook_title',
				'term' => 'rank_math_facebook_title',
			),
			self::FIELD_OG_DESC       => array(
				'post' => 'rank_math_facebook_description',
				'term' => 'rank_math_facebook_description',
			),
		);
	}

	/**
	 * Robots is stored as an array of directives, not a string.
	 *
	 * {@inheritDoc}
	 */
	public function set( string $field, string $object_type, int $object_id, ?string $value ): bool {
		if ( self::FIELD_ROBOTS !== $field ) {
			return parent::set( $field, $object_type, $object_id, $value );
		}

		$key = $this->meta_key( $field, $object_type );
		if ( '' === $key || $object_id <= 0 ) {
			return false;
		}

		if ( null === $value || '' === trim( $value ) ) {
			return 'term' === $object_type
				? (bool) delete_term_meta( $object_id, $key )
				: (bool) delete_post_meta( $object_id, $key );
		}

		$directives = array_values(
			array_filter( array_map( 'trim', explode( ',', strtolower( $value ) ) ) )
		);

		$result = 'term' === $object_type
			? update_term_meta( $object_id, $key, $directives )
			: update_post_meta( $object_id, $key, $directives );

		return false !== $result;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_template( string $field, string $object_type, int $object_id ): string {
		$options = $this->titles_option();

		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}

			$suffix = self::FIELD_TITLE === $field ? 'title' : 'description';

			return (string) ( $options[ "tax_{$term->taxonomy}_{$suffix}" ] ?? '' );
		}

		$post_type = (string) get_post_type( $object_id );
		if ( '' === $post_type ) {
			return '';
		}

		$suffix = self::FIELD_TITLE === $field ? 'title' : 'description';

		return (string) ( $options[ "pt_{$post_type}_{$suffix}" ] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function separator(): string {
		$options = $this->titles_option();

		return (string) ( $options['title_separator'] ?? '-' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schema_types( int $post_id ): array {
		$types = array();

		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, 'rank_math_schema_' ) ) {
				continue;
			}

			$type = substr( (string) $key, strlen( 'rank_math_schema_' ) );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}

		if ( ! empty( $types ) ) {
			return array_values( array_unique( $types ) );
		}

		// No per-post override: fall back to the post type's default rich snippet.
		$post_type = (string) get_post_type( $post_id );
		$options   = $this->titles_option();
		$default   = (string) ( $options[ "pt_{$post_type}_default_rich_snippet" ] ?? '' );

		if ( '' === $default || 'off' === $default ) {
			return array();
		}

		return array( ucfirst( $default ) );
	}

	/**
	 * Rank Math's global title settings, loaded once.
	 *
	 * @return array<string,mixed>
	 */
	private function titles_option(): array {
		if ( null === $this->titles_option ) {
			$stored              = get_option( self::TITLES_OPTION, array() );
			$this->titles_option = is_array( $stored ) ? $stored : array();
		}

		return $this->titles_option;
	}
}
