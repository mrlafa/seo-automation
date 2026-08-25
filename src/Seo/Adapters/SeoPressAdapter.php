<?php
/**
 * SEOPress adapter.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes SEOPress post/term meta.
 */
final class SeoPressAdapter extends AbstractAdapter {

	private const TITLES_OPTION = 'seopress_titles_option_name';

	/** @var array<string,mixed>|null */
	private $titles_option = null;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'SEOPress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key_map(): array {
		return array(
			self::FIELD_TITLE         => array(
				'post' => '_seopress_titles_title',
				'term' => '_seopress_titles_title',
			),
			self::FIELD_DESCRIPTION   => array(
				'post' => '_seopress_titles_desc',
				'term' => '_seopress_titles_desc',
			),
			self::FIELD_CANONICAL     => array(
				'post' => '_seopress_robots_canonical',
				'term' => '_seopress_robots_canonical',
			),
			self::FIELD_ROBOTS        => array(
				'post' => '_seopress_robots_index',
				'term' => '_seopress_robots_index',
			),
			self::FIELD_FOCUS_KEYWORD => array(
				'post' => '_seopress_analysis_target_kw',
				'term' => '_seopress_analysis_target_kw',
			),
			self::FIELD_OG_TITLE      => array(
				'post' => '_seopress_social_fb_title',
				'term' => '_seopress_social_fb_title',
			),
			self::FIELD_OG_DESC       => array(
				'post' => '_seopress_social_fb_desc',
				'term' => '_seopress_social_fb_desc',
			),
		);
	}

	/**
	 * SEOPress stores noindex as the literal string 'yes'.
	 *
	 * {@inheritDoc}
	 */
	public function is_noindex( string $object_type, int $object_id ): bool {
		return 'yes' === (string) $this->get( self::FIELD_ROBOTS, $object_type, $object_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_template( string $field, string $object_type, int $object_id ): string {
		$options = $this->titles_option();
		$suffix  = self::FIELD_TITLE === $field ? 'title' : 'desc';

		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return '';
			}

			return (string) ( $options['seopress_titles_tax_titles'][ $term->taxonomy ][ $suffix ] ?? '' );
		}

		$post_type = (string) get_post_type( $object_id );

		return (string) ( $options['seopress_titles_single_titles'][ $post_type ][ $suffix ] ?? '' );
	}

	/**
	 * SEOPress uses %%variable%% like Yoast.
	 *
	 * {@inheritDoc}
	 */
	protected function resolve_variables( string $template, string $object_type, int $object_id ): string {
		$normalised = preg_replace( '/%%([a-z_]+)%%/i', '%$1%', $template );
		$normalised = str_replace(
			array( '%post_title%', '%sitetitle%', '%post_excerpt%', '%_category_title%' ),
			array( '%title%', '%sitename%', '%excerpt%', '%category%' ),
			(string) $normalised
		);

		return parent::resolve_variables( $normalised, $object_type, $object_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function separator(): string {
		$options = $this->titles_option();

		return (string) ( $options['seopress_titles_sep'] ?: '-' );
	}

	/**
	 * SEOPress's global title settings, loaded once.
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
