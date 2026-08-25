<?php
/**
 * One field-level edit a fixer intends to make.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The unit of both application and rollback.
 *
 * A fixer never writes directly; it produces these, the runner records them
 * with their before value, and only then applies them. That ordering is what
 * guarantees every change is reversible.
 */
final class FixChange {

	/** @var string 'post' | 'term' | 'media' | 'option' | 'file'. */
	public $object_type;

	/** @var int */
	public $object_id;

	/** @var string Logical field name, meaningful to the fixer that made it. */
	public $field;

	/** @var string|null Current value. */
	public $before;

	/** @var string|null Value to write. */
	public $after;

	/** @var string Human explanation of this specific edit. */
	public $note;

	/** @var array<string,mixed> Anything the fixer needs to perform the write. */
	public $meta;

	/**
	 * @param string              $object_type Object type.
	 * @param int                 $object_id   Object ID.
	 * @param string              $field       Field name.
	 * @param string|null         $before      Current value.
	 * @param string|null         $after       New value.
	 * @param string              $note        Explanation.
	 * @param array<string,mixed> $meta        Fixer-specific data.
	 */
	public function __construct(
		string $object_type,
		int $object_id,
		string $field,
		?string $before,
		?string $after,
		string $note = '',
		array $meta = array()
	) {
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->field       = $field;
		$this->before      = $before;
		$this->after       = $after;
		$this->note        = $note;
		$this->meta        = $meta;
	}

	/**
	 * Would this change actually alter anything?
	 */
	public function is_noop(): bool {
		return (string) $this->before === (string) $this->after;
	}

	/**
	 * Serialise for preview output.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'object_type' => $this->object_type,
			'object_id'   => $this->object_id,
			'field'       => $this->field,
			'before'      => $this->before,
			'after'       => $this->after,
			'note'        => $this->note,
			'noop'        => $this->is_noop(),
		);
	}
}
