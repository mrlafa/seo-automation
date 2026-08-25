<?php
/**
 * Contract for reading and writing SEO metadata regardless of which SEO
 * plugin owns it.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every checker and fixer talks to metadata through this interface, so
 * supporting a new SEO plugin means writing one class and nothing else.
 */
interface SeoAdapterInterface {

	public const FIELD_TITLE         = 'title';
	public const FIELD_DESCRIPTION   = 'description';
	public const FIELD_CANONICAL     = 'canonical';
	public const FIELD_ROBOTS        = 'robots';
	public const FIELD_FOCUS_KEYWORD = 'focus_keyword';
	public const FIELD_OG_TITLE      = 'og_title';
	public const FIELD_OG_DESC       = 'og_description';

	/**
	 * Machine identifier, e.g. 'rank_math'.
	 */
	public function slug(): string;

	/**
	 * Human label for reports.
	 */
	public function label(): string;

	/**
	 * Is the backing plugin installed and active?
	 */
	public function is_active(): bool;

	/**
	 * Does this adapter handle the given field?
	 *
	 * @param string $field One of the FIELD_* constants.
	 */
	public function supports( string $field ): bool;

	/**
	 * The postmeta/termmeta key backing a field, or '' when the field is not
	 * stored as simple meta.
	 *
	 * Exposed so bulk audits can find every post missing a description with a
	 * single indexed query instead of one call per post.
	 *
	 * @param string $field       One of the FIELD_* constants.
	 * @param string $object_type 'post' or 'term'.
	 */
	public function meta_key( string $field, string $object_type = 'post' ): string;

	/**
	 * Raw stored value, before variable substitution. Null when unset.
	 *
	 * @param string $field       One of the FIELD_* constants.
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 */
	public function get( string $field, string $object_type, int $object_id ): ?string;

	/**
	 * Write a value. Passing null deletes it.
	 *
	 * @param string      $field       One of the FIELD_* constants.
	 * @param string      $object_type 'post' or 'term'.
	 * @param int         $object_id   Post or term ID.
	 * @param string|null $value       New value.
	 *
	 * @return bool True when the store was updated.
	 */
	public function set( string $field, string $object_type, int $object_id, ?string $value ): bool;

	/**
	 * The title a search engine would see: the stored template with variables
	 * resolved, or the plugin-level default for the post type when unset.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 */
	public function effective_title( string $object_type, int $object_id ): string;

	/**
	 * The description a search engine would see, resolved the same way.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 */
	public function effective_description( string $object_type, int $object_id ): string;

	/**
	 * Is this object excluded from indexing?
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 */
	public function is_noindex( string $object_type, int $object_id ): bool;

	/**
	 * Schema types the SEO plugin will output for a post, best-effort.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string[]
	 */
	public function schema_types( int $post_id ): array;
}
