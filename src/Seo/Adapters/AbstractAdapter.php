<?php
/**
 * Shared behaviour for meta-key-backed SEO adapters.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo\Adapters;

use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Content;
use SEOAgent\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the common case: fields stored in postmeta/termmeta, titles
 * containing %variables%, and defaults inherited from the plugin's settings.
 */
abstract class AbstractAdapter implements SeoAdapterInterface {

	/**
	 * Map of field => array( 'post' => meta key, 'term' => meta key ).
	 *
	 * @return array<string,array<string,string>>
	 */
	abstract protected function key_map(): array;

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $field ): bool {
		return '' !== $this->meta_key( $field, 'post' ) || '' !== $this->meta_key( $field, 'term' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function meta_key( string $field, string $object_type = 'post' ): string {
		$map = $this->key_map();

		return $map[ $field ][ $object_type ] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $field, string $object_type, int $object_id ): ?string {
		$key = $this->meta_key( $field, $object_type );
		if ( '' === $key || $object_id <= 0 ) {
			return null;
		}

		$value = 'term' === $object_type
			? get_term_meta( $object_id, $key, true )
			: get_post_meta( $object_id, $key, true );

		if ( is_array( $value ) ) {
			$value = implode( ',', array_map( 'strval', $value ) );
		}

		$value = is_string( $value ) ? trim( $value ) : '';

		return '' === $value ? null : $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $field, string $object_type, int $object_id, ?string $value ): bool {
		$key = $this->meta_key( $field, $object_type );
		if ( '' === $key || $object_id <= 0 ) {
			return false;
		}

		if ( null === $value || '' === trim( $value ) ) {
			return 'term' === $object_type
				? (bool) delete_term_meta( $object_id, $key )
				: (bool) delete_post_meta( $object_id, $key );
		}

		$result = 'term' === $object_type
			? update_term_meta( $object_id, $key, $value )
			: update_post_meta( $object_id, $key, $value );

		return false !== $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function effective_title( string $object_type, int $object_id ): string {
		$stored = $this->get( self::FIELD_TITLE, $object_type, $object_id );

		if ( null === $stored ) {
			$stored = $this->default_template( self::FIELD_TITLE, $object_type, $object_id );
		}

		if ( '' === (string) $stored ) {
			return $this->fallback_title( $object_type, $object_id );
		}

		return $this->resolve_variables( (string) $stored, $object_type, $object_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function effective_description( string $object_type, int $object_id ): string {
		$stored = $this->get( self::FIELD_DESCRIPTION, $object_type, $object_id );

		if ( null === $stored ) {
			$stored = $this->default_template( self::FIELD_DESCRIPTION, $object_type, $object_id );
		}

		if ( '' === (string) $stored ) {
			return '';
		}

		return $this->resolve_variables( (string) $stored, $object_type, $object_id );
	}

	/**
	 * Plugin-level default template for a post type or taxonomy. Adapters that
	 * expose settings override this.
	 *
	 * @param string $field       Field name.
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 */
	protected function default_template( string $field, string $object_type, int $object_id ): string {
		return '';
	}

	/**
	 * What WordPress itself would render when no SEO metadata exists at all.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 */
	protected function fallback_title( string $object_type, int $object_id ): string {
		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );

			return $term && ! is_wp_error( $term ) ? (string) $term->name : '';
		}

		return (string) get_the_title( $object_id );
	}

	/**
	 * Substitute the %variables% shared by every major SEO plugin.
	 *
	 * Deliberately conservative: unknown variables are left in place so a
	 * checker can flag "this title still contains an unresolved variable"
	 * rather than silently reporting a wrong length.
	 *
	 * @param string $template    Raw stored template.
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 */
	protected function resolve_variables( string $template, string $object_type, int $object_id ): string {
		$replacements = $this->variable_values( $object_type, $object_id );

		$resolved = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		// Collapse the artefacts of empty replacements: " | " at either end, doubled separators.
		$separator = preg_quote( $this->separator(), '/' );
		$resolved  = preg_replace( "/\s*{$separator}\s*({$separator}\s*)+/u", ' ' . $this->separator() . ' ', $resolved );
		$resolved  = preg_replace( "/^\s*{$separator}\s*|\s*{$separator}\s*$/u", '', (string) $resolved );

		return trim( preg_replace( '/\s+/u', ' ', (string) $resolved ) ?? '' );
	}

	/**
	 * Values for the supported template variables.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 *
	 * @return array<string,string>
	 */
	protected function variable_values( string $object_type, int $object_id ): array {
		$values = array(
			'%sitename%'     => get_bloginfo( 'name' ),
			'%sitedesc%'     => get_bloginfo( 'description' ),
			'%sep%'          => $this->separator(),
			'%currentyear%'  => gmdate( 'Y' ),
			'%currentmonth%' => gmdate( 'F' ),
			'%currentdate%'  => gmdate( get_option( 'date_format' ) ?: 'Y-m-d' ),
			'%page%'         => '',
			'%pagenumber%'   => '',
			'%pagetotal%'    => '',
		);

		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$values['%term%']            = (string) $term->name;
				$values['%term_title%']      = (string) $term->name;
				$values['%term_description%'] = Text::plain( (string) $term->description );
				$values['%category%']        = (string) $term->name;
				$values['%category_title%']  = (string) $term->name;
				$values['%title%']           = (string) $term->name;
			}

			return $values;
		}

		$post = get_post( $object_id );
		if ( ! $post ) {
			return $values;
		}

		$values['%title%']       = (string) $post->post_title;
		$values['%post_title%']  = (string) $post->post_title;
		$values['%excerpt%']     = $this->post_excerpt( $post );
		$values['%excerpt_only%'] = Text::plain( (string) $post->post_excerpt );
		$values['%date%']        = (string) get_the_date( '', $post );
		$values['%modified%']    = (string) get_the_modified_date( '', $post );
		$values['%name%']        = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		$values['%category%']    = $this->primary_term_name( $post );
		$values['%parent_title%'] = $post->post_parent ? (string) get_the_title( $post->post_parent ) : '';

		if ( function_exists( 'wc_get_product' ) && 'product' === $post->post_type ) {
			$product = wc_get_product( $object_id );
			if ( $product ) {
				$values['%price%'] = (string) wp_strip_all_tags( (string) $product->get_price_html() );
				$values['%sku%']   = (string) $product->get_sku();
			}
		}

		return $values;
	}

	/**
	 * Excerpt, generated from content when not hand-written.
	 *
	 * @param \WP_Post $post Post object.
	 */
	protected function post_excerpt( \WP_Post $post ): string {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return Text::plain( (string) $post->post_excerpt );
		}

		// Every SEO plugin's default description template falls back to this
		// when no manual excerpt exists, so on a block-based builder this is
		// the single highest-traffic place to get right: get it wrong and
		// every such page's auto-generated description comes back empty.
		return Text::truncate( Text::plain( Content::rendered( (string) $post->post_content ) ), 155, '' );
	}

	/**
	 * First category-like term attached to a post.
	 *
	 * @param \WP_Post $post Post object.
	 */
	protected function primary_term_name( \WP_Post $post ): string {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->hierarchical ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				return (string) $terms[0]->name;
			}
		}

		return '';
	}

	/**
	 * Title separator character. Adapters override with the plugin's setting.
	 */
	protected function separator(): string {
		return '-';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_noindex( string $object_type, int $object_id ): bool {
		$robots = $this->get( self::FIELD_ROBOTS, $object_type, $object_id );

		return null !== $robots && false !== stripos( $robots, 'noindex' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schema_types( int $post_id ): array {
		return array();
	}
}
