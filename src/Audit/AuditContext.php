<?php
/**
 * Per-run state handed to every checker.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;
use SEOAgent\Support\PageFetcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the audit's arguments, the SEO adapter, an HTTP fetcher and the
 * current checker's cursor.
 */
final class AuditContext {

	/** @var int */
	public $audit_id;

	/** @var array<string,mixed> Run arguments. */
	public $args;

	/** @var array<string,mixed> Cursor for the checker currently running. */
	public $cursor;

	/** @var SeoAdapterInterface */
	public $seo;

	/** @var PageFetcher */
	public $fetcher;

	/** @var float Unix timestamp after which the checker should yield. */
	public $deadline;

	/** @var array<string,mixed> Scratch space shared between checkers in one run. */
	public $shared = array();

	/**
	 * @param int                 $audit_id Audit ID.
	 * @param array<string,mixed> $args     Run arguments.
	 * @param SeoAdapterInterface $seo      Metadata adapter.
	 * @param PageFetcher|null    $fetcher  HTTP fetcher.
	 */
	public function __construct( int $audit_id, array $args, SeoAdapterInterface $seo, ?PageFetcher $fetcher = null ) {
		$this->audit_id = $audit_id;
		$this->args     = $args;
		$this->seo      = $seo;
		$this->fetcher  = $fetcher ?? new PageFetcher();
		$this->cursor   = array();
		$this->deadline = microtime( true ) + 30;
	}

	/**
	 * A run argument, falling back to the plugin setting then a literal default.
	 *
	 * @param string $key     Argument name.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function arg( string $key, $default = null ) {
		if ( array_key_exists( $key, $this->args ) ) {
			return $this->args[ $key ];
		}

		return Options::get( $key, $default );
	}

	/**
	 * Post types this run should cover.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = (array) $this->arg( 'audit_post_types', array( 'post', 'page' ) );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );

		return $types ?: array( 'post' );
	}

	/**
	 * Taxonomies this run should cover.
	 *
	 * @return string[]
	 */
	public function taxonomies(): array {
		$taxonomies = (array) $this->arg( 'audit_taxonomies', array( 'category' ) );

		return array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );
	}

	/**
	 * How many objects a checker should process per slice.
	 */
	public function batch_size(): int {
		return max( 5, min( 500, (int) $this->arg( 'batch_size', 50 ) ) );
	}

	/**
	 * Has this slice used up its time budget?
	 */
	public function out_of_time(): bool {
		return microtime( true ) >= $this->deadline;
	}

	/**
	 * Cursor value with a default.
	 *
	 * @param string $key     Cursor key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function cursor( string $key, $default = null ) {
		return $this->cursor[ $key ] ?? $default;
	}
}
