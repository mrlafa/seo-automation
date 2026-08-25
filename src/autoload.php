<?php
/**
 * PSR-4 style autoloader for the SEOAgent namespace.
 *
 * Deliberately dependency-free: the plugin must install on shared hosting
 * without a vendor/ directory. Composer is used for dev tooling only.
 *
 * @package SEOAgent
 */

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SEOAgent\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
