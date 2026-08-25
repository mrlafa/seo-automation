<?php
/**
 * Fallback adapter for sites with no SEO plugin.
 *
 * Stores metadata under our own keys and renders the corresponding head tags.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo\Adapters;

use SEOAgent\Support\Text;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Owns the metadata itself when nothing else does.
 */
final class NativeAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'native';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'SEO Agent (built in)', 'seo-automation' );
	}

	/**
	 * Always available — it is the fallback.
	 *
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key_map(): array {
		return array(
			self::FIELD_TITLE         => array(
				'post' => '_seo_agent_title',
				'term' => '_seo_agent_title',
			),
			self::FIELD_DESCRIPTION   => array(
				'post' => '_seo_agent_description',
				'term' => '_seo_agent_description',
			),
			self::FIELD_CANONICAL     => array(
				'post' => '_seo_agent_canonical',
				'term' => '_seo_agent_canonical',
			),
			self::FIELD_ROBOTS        => array(
				'post' => '_seo_agent_robots',
				'term' => '_seo_agent_robots',
			),
			self::FIELD_FOCUS_KEYWORD => array(
				'post' => '_seo_agent_focus_keyword',
				'term' => '_seo_agent_focus_keyword',
			),
			self::FIELD_OG_TITLE      => array(
				'post' => '_seo_agent_og_title',
				'term' => '_seo_agent_og_title',
			),
			self::FIELD_OG_DESC       => array(
				'post' => '_seo_agent_og_description',
				'term' => '_seo_agent_og_description',
			),
		);
	}

	/**
	 * Render the description and canonical tags. WordPress core already
	 * handles <title> through theme support, so only the gaps are filled.
	 */
	public function register_head_output(): void {
		add_action( 'wp_head', array( $this, 'render_head' ), 1 );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
	}

	/**
	 * Print meta description, canonical and robots for the current request.
	 */
	public function render_head(): void {
		[ $object_type, $object_id ] = $this->current_object();

		if ( 0 === $object_id ) {
			return;
		}

		$description = $this->effective_description( $object_type, $object_id );
		if ( '' !== $description ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( Text::truncate( $description, 160, '' ) )
			);
		}

		$canonical = $this->get( self::FIELD_CANONICAL, $object_type, $object_id );
		if ( null !== $canonical ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
		}

		$robots = $this->get( self::FIELD_ROBOTS, $object_type, $object_id );
		if ( null !== $robots ) {
			printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( $robots ) );
		}
	}

	/**
	 * Override the document title when one is stored.
	 *
	 * @param array<string,string> $parts Title parts.
	 *
	 * @return array<string,string>
	 */
	public function filter_title_parts( array $parts ): array {
		[ $object_type, $object_id ] = $this->current_object();

		if ( 0 === $object_id ) {
			return $parts;
		}

		$stored = $this->get( self::FIELD_TITLE, $object_type, $object_id );
		if ( null === $stored ) {
			return $parts;
		}

		return array( 'title' => $this->resolve_variables( $stored, $object_type, $object_id ) );
	}

	/**
	 * Identify what the current request is showing.
	 *
	 * @return array{0:string,1:int}
	 */
	private function current_object(): array {
		if ( is_singular() ) {
			return array( 'post', (int) get_queried_object_id() );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return array( 'term', (int) get_queried_object_id() );
		}

		return array( 'site', 0 );
	}
}
