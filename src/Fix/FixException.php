<?php
/**
 * Raised when a fix cannot be planned or applied.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries a machine-readable code alongside the message so the REST layer can
 * distinguish "you need to give me a title" from "that post does not exist".
 */
final class FixException extends \RuntimeException {

	/** @var string */
	private $code_slug;

	/** @var array<string,mixed> */
	private $context;

	/**
	 * @param string              $message   Human message.
	 * @param string              $code_slug Machine code.
	 * @param array<string,mixed> $context   Extra detail.
	 */
	public function __construct( string $message, string $code_slug = 'fix_failed', array $context = array() ) {
		parent::__construct( $message );

		$this->code_slug = $code_slug;
		$this->context   = $context;
	}

	/**
	 * Machine-readable code.
	 */
	public function code_slug(): string {
		return $this->code_slug;
	}

	/**
	 * Extra detail for the caller.
	 *
	 * @return array<string,mixed>
	 */
	public function context(): array {
		return $this->context;
	}
}
