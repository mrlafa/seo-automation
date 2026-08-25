<?php
/**
 * Indexability and robots.txt checks.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit\Checkers;

use SEOAgent\Audit\AuditContext;
use SEOAgent\Audit\Issue;
use SEOAgent\Audit\SiteChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checks that can render every other finding irrelevant: if the site tells
 * crawlers to go away, nothing else matters.
 */
final class RobotsChecker extends SiteChecker {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'robots';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Crawlability', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): string {
		return 'technical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Checks the site-wide indexing switch and robots.txt for rules that block search engines.', 'seo-automation' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function check_site( AuditContext $context ): array {
		$issues = array();

		// The single most damaging setting in WordPress, and the easiest to
		// leave switched on after a site launches.
		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'robots.discourage_search_engines',
					'severity'    => Issue::SEVERITY_CRITICAL,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'Search engines are discouraged site-wide', 'seo-automation' ),
					'detail'      => __( 'Settings → Reading has "Discourage search engines from indexing this site" enabled. WordPress is emitting a site-wide noindex; nothing on this site can rank until it is turned off.', 'seo-automation' ),
					'url'         => admin_url( 'options-reading.php' ),
					'evidence'    => array( 'blog_public' => get_option( 'blog_public' ) ),
					'fixer'       => 'site_visibility',
					'fix_mode'    => Issue::MODE_AUTO,
					'fix_payload' => array( 'blog_public' => 1 ),
				)
			);
		}

		return array_merge( $issues, $this->check_robots_txt( $context ) );
	}

	/**
	 * Fetch and inspect robots.txt.
	 *
	 * @param AuditContext $context Run state.
	 *
	 * @return Issue[]
	 */
	private function check_robots_txt( AuditContext $context ): array {
		$url      = $this->robots_url();
		$response = $context->fetcher->get( $url, HOUR_IN_SECONDS );

		if ( '' !== $response['error'] || $response['status'] >= 400 ) {
			// A missing robots.txt is permissive, not fatal — but it also means
			// no sitemap hint, which is worth saying once.
			return array(
				$this->issue(
					array(
						'code'        => 'robots.txt.unreachable',
						'severity'    => Issue::SEVERITY_LOW,
						'object_type' => 'site',
						'object_id'   => 0,
						'title'       => __( 'robots.txt is not reachable', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: 1: URL, 2: HTTP status or error. */
							__( '%1$s returned %2$s. Crawling still works without it, but you lose the place to declare your sitemap.', 'seo-automation' ),
							$url,
							'' !== $response['error'] ? $response['error'] : (string) $response['status']
						),
						'url'         => $url,
						'evidence'    => array(
							'status' => $response['status'],
							'error'  => $response['error'],
						),
						'fix_mode'    => Issue::MODE_MANUAL,
					)
				),
			);
		}

		$body   = $response['body'];
		$issues = array();
		$rules  = $this->parse_robots( $body );

		$wildcard = $rules['*'] ?? array(
			'disallow' => array(),
			'allow'    => array(),
		);

		if ( in_array( '/', $wildcard['disallow'], true ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'robots.txt.blocks_site',
					'severity'    => Issue::SEVERITY_CRITICAL,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'robots.txt blocks the entire site', 'seo-automation' ),
					'detail'      => __( 'robots.txt contains "Disallow: /" for all user agents. No search engine will crawl anything.', 'seo-automation' ),
					'url'         => $url,
					'evidence'    => array(
						'rules'   => $wildcard,
						'excerpt' => substr( $body, 0, 1000 ),
					),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		// Blocking the asset directories stops Google rendering the page as a
		// visitor sees it, which affects both layout assessment and CWV.
		foreach ( array( '/wp-content/', '/wp-includes/', '/wp-content/themes/', '/wp-content/plugins/' ) as $path ) {
			if ( in_array( $path, $wildcard['disallow'], true ) && ! in_array( $path, $wildcard['allow'], true ) ) {
				$issues[] = $this->issue(
					array(
						'code'        => 'robots.txt.blocks_assets',
						'severity'    => Issue::SEVERITY_HIGH,
						'object_type' => 'site',
						'object_id'   => 0,
						'key'         => $path,
						'title'       => __( 'robots.txt blocks CSS or JavaScript', 'seo-automation' ),
						'detail'      => sprintf(
							/* translators: %s: blocked path. */
							__( '"%s" is disallowed. Google renders pages before judging them, so blocking assets makes your pages look broken to the crawler.', 'seo-automation' ),
							$path
						),
						'url'         => $url,
						'evidence'    => array( 'path' => $path ),
						'fix_mode'    => Issue::MODE_MANUAL,
					)
				);
			}
		}

		if ( ! preg_match( '/^\s*sitemap:/im', $body ) ) {
			$issues[] = $this->issue(
				array(
					'code'        => 'robots.txt.no_sitemap',
					'severity'    => Issue::SEVERITY_LOW,
					'object_type' => 'site',
					'object_id'   => 0,
					'title'       => __( 'robots.txt does not declare a sitemap', 'seo-automation' ),
					'detail'      => __( 'Adding a Sitemap: line is the standard way to point every crawler at your sitemap without registering it anywhere.', 'seo-automation' ),
					'url'         => $url,
					'evidence'    => array( 'excerpt' => substr( $body, 0, 1000 ) ),
					'fix_mode'    => Issue::MODE_MANUAL,
				)
			);
		}

		return $issues;
	}

	/**
	 * robots.txt always lives at the domain root, even for a subdirectory install.
	 */
	private function robots_url(): string {
		$parts  = wp_parse_url( home_url() );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return sprintf( '%s://%s%s/robots.txt', $scheme, $host, $port );
	}

	/**
	 * Group robots.txt directives by user agent.
	 *
	 * @param string $body robots.txt contents.
	 *
	 * @return array<string,array{disallow:string[],allow:string[]}>
	 */
	private function parse_robots( string $body ): array {
		$rules   = array();
		$current = array();

		// Consecutive User-agent lines share the rule block that follows them,
		// which is how most sites block a list of AI crawlers in one go. The
		// group only closes once a rule has actually been applied to it.
		$group_open = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: array() as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) ?? '' );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			[ $directive, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$directive             = strtolower( $directive );

			if ( 'user-agent' === $directive ) {
				$agent = strtolower( $value );

				if ( ! isset( $rules[ $agent ] ) ) {
					$rules[ $agent ] = array(
						'disallow' => array(),
						'allow'    => array(),
					);
				}

				// A user-agent line directly after another extends the group;
				// one after a rule starts a new group.
				if ( $group_open ) {
					$current = array();
				}

				$current[]  = $agent;
				$group_open = false;

				continue;
			}

			if ( empty( $current ) ) {
				continue;
			}

			if ( ! in_array( $directive, array( 'disallow', 'allow' ), true ) || '' === $value ) {
				continue;
			}

			$group_open = true;

			foreach ( $current as $agent ) {
				$rules[ $agent ][ $directive ][] = $value;
			}
		}

		return $rules;
	}

	/**
	 * Expose the parsed rules to other checkers (the AI visibility check reads
	 * the same file and there is no reason to fetch it twice).
	 *
	 * @param string $body robots.txt contents.
	 *
	 * @return array<string,array{disallow:string[],allow:string[]}>
	 */
	public static function parse( string $body ): array {
		return ( new self() )->parse_robots( $body );
	}

	/**
	 * Public accessor for the canonical robots.txt URL.
	 */
	public static function url(): string {
		return ( new self() )->robots_url();
	}
}
