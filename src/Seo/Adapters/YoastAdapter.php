<?php
/**
 * Yoast SEO adapter.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo\Adapters;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Yoast keeps post data in postmeta but term data in a single serialised
 * option, so term reads and writes are overridden here.
 */
final class YoastAdapter extends AbstractAdapter {

	private const TITLES_OPTION = 'wpseo_titles';
	private const TAX_OPTION    = 'wpseo_taxonomy_meta';

	/** @var array<string,mixed>|null */
	private $titles_option = null;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Options' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key_map(): array {
		return array(
			self::FIELD_TITLE         => array(
				'post' => '_yoast_wpseo_title',
				'term' => 'wpseo_title',
			),
			self::FIELD_DESCRIPTION   => array(
				'post' => '_yoast_wpseo_metadesc',
				'term' => 'wpseo_desc',
			),
			self::FIELD_CANONICAL     => array(
				'post' => '_yoast_wpseo_canonical',
				'term' => 'wpseo_canonical',
			),
			self::FIELD_ROBOTS        => array(
				'post' => '_yoast_wpseo_meta-robots-noindex',
				'term' => 'wpseo_noindex',
			),
			self::FIELD_FOCUS_KEYWORD => array(
				'post' => '_yoast_wpseo_focuskw',
				'term' => 'wpseo_focuskw',
			),
			self::FIELD_OG_TITLE      => array(
				'post' => '_yoast_wpseo_opengraph-title',
				'term' => 'wpseo_opengraph-title',
			),
			self::FIELD_OG_DESC       => array(
				'post' => '_yoast_wpseo_opengraph-description',
				'term' => 'wpseo_opengraph-description',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $field, string $object_type, int $object_id ): ?string {
		if ( 'term' !== $object_type ) {
			return parent::get( $field, $object_type, $object_id );
		}

		$key   = $this->meta_key( $field, 'term' );
		$entry = $this->term_entry( $object_id );

		if ( '' === $key || null === $entry ) {
			return null;
		}

		$value = isset( $entry[ $key ] ) ? trim( (string) $entry[ $key ] ) : '';

		return '' === $value ? null : $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $field, string $object_type, int $object_id, ?string $value ): bool {
		if ( 'term' !== $object_type ) {
			return parent::set( $field, $object_type, $object_id, $value );
		}

		$key = $this->meta_key( $field, 'term' );
		if ( '' === $key ) {
			return false;
		}

		$term = get_term( $object_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$option = get_option( self::TAX_OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		if ( ! isset( $option[ $term->taxonomy ] ) || ! is_array( $option[ $term->taxonomy ] ) ) {
			$option[ $term->taxonomy ] = array();
		}
		if ( ! isset( $option[ $term->taxonomy ][ $object_id ] ) || ! is_array( $option[ $term->taxonomy ][ $object_id ] ) ) {
			$option[ $term->taxonomy ][ $object_id ] = array();
		}

		if ( null === $value || '' === trim( $value ) ) {
			unset( $option[ $term->taxonomy ][ $object_id ][ $key ] );
		} else {
			$option[ $term->taxonomy ][ $object_id ][ $key ] = $value;
		}

		return update_option( self::TAX_OPTION, $option );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_noindex( string $object_type, int $object_id ): bool {
		$value = $this->get( self::FIELD_ROBOTS, $object_type, $object_id );

		// Yoast encodes post robots as '1' (noindex) / '2' (index) and term
		// robots as 'noindex' / 'index'.
		return in_array( (string) $value, array( '1', 'noindex' ), true );
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

			$prefix = self::FIELD_TITLE === $field ? 'title' : 'metadesc';

			return (string) ( $options[ "{$prefix}-tax-{$term->taxonomy}" ] ?? '' );
		}

		$post_type = (string) get_post_type( $object_id );
		if ( '' === $post_type ) {
			return '';
		}

		$prefix = self::FIELD_TITLE === $field ? 'title' : 'metadesc';

		return (string) ( $options[ "{$prefix}-{$post_type}" ] ?? '' );
	}

	/**
	 * Yoast writes variables as %%title%%; normalise to the single-percent form
	 * the base class understands before substituting.
	 *
	 * {@inheritDoc}
	 */
	protected function resolve_variables( string $template, string $object_type, int $object_id ): string {
		$normalised = preg_replace( '/%%([a-z_]+)%%/i', '%$1%', $template );

		// Yoast's names for variables the base class knows under other keys.
		$aliases = array(
			'%sitetitle%' => '%sitename%',
			'%page%'      => '%page%',
			'%primary_category%' => '%category%',
		);
		$normalised = str_replace( array_keys( $aliases ), array_values( $aliases ), (string) $normalised );

		return parent::resolve_variables( $normalised, $object_type, $object_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function separator(): string {
		$options = $this->titles_option();
		$stored  = (string) ( $options['separator'] ?? 'sc-dash' );

		$map = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '⋆',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
			'sc-lt'     => '<',
			'sc-gt'     => '>',
		);

		return $map[ $stored ] ?? '-';
	}

	/**
	 * {@inheritDoc}
	 */
	public function schema_types( int $post_id ): array {
		$page_type    = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true );
		$article_type = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_article_type', true );

		$types = array_filter( array( $page_type, $article_type ) );

		if ( ! empty( $types ) ) {
			return array_values( $types );
		}

		$options   = $this->titles_option();
		$post_type = (string) get_post_type( $post_id );
		$default   = (string) ( $options[ "schema-article-type-{$post_type}" ] ?? '' );

		return '' !== $default && 'None' !== $default ? array( $default ) : array();
	}

	/**
	 * One term's stored Yoast meta.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function term_entry( int $term_id ): ?array {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$option = get_option( self::TAX_OPTION, array() );
		$entry  = $option[ $term->taxonomy ][ $term_id ] ?? null;

		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Yoast's global title settings, loaded once.
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
