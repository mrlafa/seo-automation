<?php
/**
 * HTTP access to the site's own rendered pages and to outbound links.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches URLs with a short-lived cache so several checkers can inspect the
 * same rendered page without re-requesting it.
 */
class PageFetcher {

	private const CACHE_GROUP = 'seo_agent_pages';

	/** @var array<string,array<string,mixed>> Per-request memo. */
	private $memo = array();

	/** @var int */
	private $timeout;

	/**
	 * @param int|null $timeout Request timeout in seconds.
	 */
	public function __construct( ?int $timeout = null ) {
		$this->timeout = $timeout ?? (int) Options::get( 'request_timeout', 10 );
	}

	/**
	 * GET a URL and return status, headers and body.
	 *
	 * @param string $url       Absolute URL.
	 * @param int    $cache_ttl Seconds to cache; 0 disables caching.
	 *
	 * @return array{status:int,body:string,headers:array<string,string>,error:string,elapsed:float}
	 */
	public function get( string $url, int $cache_ttl = 300 ): array {
		$key = 'get_' . md5( $url );

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		if ( $cache_ttl > 0 ) {
			$cached = get_transient( self::CACHE_GROUP . '_' . $key );
			if ( is_array( $cached ) ) {
				$this->memo[ $key ] = $cached;

				return $cached;
			}
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $this->timeout,
				'redirection'         => 5,
				'user-agent'          => $this->user_agent(),
				'sslverify'           => true,
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);

		$result = $this->normalise( $response, microtime( true ) - $started );

		if ( $cache_ttl > 0 && '' === $result['error'] ) {
			set_transient( self::CACHE_GROUP . '_' . $key, $result, $cache_ttl );
		}

		$this->memo[ $key ] = $result;

		return $result;
	}

	/**
	 * Check a URL's reachability as cheaply as possible.
	 *
	 * Tries HEAD first, then falls back to GET, because a meaningful share of
	 * hosts (and most CDNs fronting WordPress) answer HEAD with 405 or 403
	 * while serving GET perfectly well — treating those as broken links would
	 * flood the report with false positives.
	 *
	 * @param string $url       Absolute URL.
	 * @param int    $cache_ttl Seconds to cache.
	 *
	 * @return array{status:int,error:string,final_url:string,method:string,redirected:bool}
	 */
	public function check( string $url, int $cache_ttl = DAY_IN_SECONDS ): array {
		$key = 'check_' . md5( $url );

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		if ( $cache_ttl > 0 ) {
			$cached = get_transient( self::CACHE_GROUP . '_' . $key );
			if ( is_array( $cached ) ) {
				$this->memo[ $key ] = $cached;

				return $cached;
			}
		}

		$args = array(
			'timeout'     => $this->timeout,
			'redirection' => 5,
			'user-agent'  => $this->user_agent(),
			'sslverify'   => true,
		);

		$method   = 'HEAD';
		$response = wp_remote_head( $url, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) || $status >= 400 || 0 === $status ) {
			$method   = 'GET';
			$response = wp_remote_get( $url, array_merge( $args, array( 'limit_response_size' => 65536 ) ) );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}

		$final_url = '';
		if ( ! is_wp_error( $response ) && isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
			$http_response = $response['http_response'];
			if ( method_exists( $http_response, 'get_response_object' ) ) {
				$raw = $http_response->get_response_object();
				if ( $raw && isset( $raw->url ) ) {
					$final_url = (string) $raw->url;
				}
			}
		}

		$result = array(
			'status'     => $status,
			'error'      => is_wp_error( $response ) ? $response->get_error_message() : '',
			'final_url'  => $final_url,
			'method'     => $method,
			'redirected' => '' !== $final_url && untrailingslashit( $final_url ) !== untrailingslashit( $url ),
		);

		if ( $cache_ttl > 0 ) {
			set_transient( self::CACHE_GROUP . '_' . $key, $result, $cache_ttl );
		}

		$this->memo[ $key ] = $result;

		return $result;
	}

	/**
	 * Identify ourselves honestly so site owners can see this traffic in logs.
	 */
	private function user_agent(): string {
		return sprintf(
			'SEOAgent/%s (+%s) WordPress/%s',
			defined( 'SEO_AUTOMATION_VERSION' ) ? SEO_AUTOMATION_VERSION : 'dev',
			home_url( '/' ),
			get_bloginfo( 'version' )
		);
	}

	/**
	 * Convert a wp_remote_* response into our flat shape.
	 *
	 * @param array<string,mixed>|\WP_Error $response Raw response.
	 * @param float                         $elapsed  Seconds taken.
	 *
	 * @return array{status:int,body:string,headers:array<string,string>,error:string,elapsed:float}
	 */
	private function normalise( $response, float $elapsed ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'body'    => '',
				'headers' => array(),
				'error'   => $response->get_error_message(),
				'elapsed' => $elapsed,
			);
		}

		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		$flat = array();
		foreach ( $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $flat,
			'error'   => '',
			'elapsed' => $elapsed,
		);
	}

	/**
	 * Forget everything cached for a URL, so a post-fix verification sees the
	 * page as it is now rather than as it was during the audit.
	 *
	 * @param string $url Absolute URL.
	 */
	public function forget( string $url ): void {
		foreach ( array( 'get_', 'check_' ) as $prefix ) {
			$key = $prefix . md5( $url );
			unset( $this->memo[ $key ] );
			delete_transient( self::CACHE_GROUP . '_' . $key );
		}
	}
}
