<?php
/**
 * Shared plumbing for every checker.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applicability, issue construction and URL helpers.
 */
abstract class AbstractChecker implements CheckerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function is_applicable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Build an issue pre-filled with this checker's slug.
	 *
	 * @param array<string,mixed> $data Issue fields.
	 */
	protected function issue( array $data ): Issue {
		$data['checker'] = $this->slug();

		return Issue::make( $data );
	}

	/**
	 * Is WooCommerce active?
	 */
	protected function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The site's host, for distinguishing internal from external links.
	 */
	protected function site_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Is a URL on this site?
	 *
	 * @param string $url Absolute or relative URL.
	 */
	protected function is_internal( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		// Root-relative, query-only and fragment-only links are internal by definition.
		if ( 0 === strpos( $url, '/' ) || 0 === strpos( $url, '#' ) || 0 === strpos( $url, '?' ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}

		return strtolower( $host ) === $this->site_host();
	}

	/**
	 * Is this a link scheme we should never treat as a page?
	 *
	 * @param string $url Link href.
	 */
	protected function is_non_http( string $url ): bool {
		return (bool) preg_match( '/^(mailto|tel|sms|javascript|data|ftp|skype|whatsapp):/i', $url );
	}
}
