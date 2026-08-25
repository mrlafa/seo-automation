<?php
/**
 * A single finding produced by a checker.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Audit;

defined( 'ABSPATH' ) || defined( 'SEO_AGENT_TEST' ) || exit;

/**
 * Immutable-ish value object describing one SEO problem.
 */
final class Issue {

	public const SEVERITY_CRITICAL = 'critical';
	public const SEVERITY_HIGH     = 'high';
	public const SEVERITY_MEDIUM   = 'medium';
	public const SEVERITY_LOW      = 'low';
	public const SEVERITY_INFO     = 'info';

	/** Fix can be applied without judgement (deterministic). */
	public const MODE_AUTO = 'auto';
	/** Fix needs generated or chosen content — an agent or a human supplies it. */
	public const MODE_ASSISTED = 'assisted';
	/** No programmatic fix; reported for a human to act on. */
	public const MODE_MANUAL = 'manual';

	/** @var string Checker slug that produced this issue. */
	public $checker = '';

	/** @var string Stable machine code, e.g. 'meta.title.missing'. */
	public $code = '';

	/** @var string One of the SEVERITY_* constants. */
	public $severity = self::SEVERITY_MEDIUM;

	/** @var string 'post' | 'term' | 'media' | 'url' | 'site'. */
	public $object_type = 'site';

	/** @var int */
	public $object_id = 0;

	/** @var string Human label, e.g. the post title. */
	public $object_label = '';

	/** @var string */
	public $url = '';

	/** @var string Short human summary. */
	public $title = '';

	/** @var string Why it matters / what to do. */
	public $detail = '';

	/** @var array<string,mixed> Structured facts an agent can reason over. */
	public $evidence = array();

	/** @var string|null Fixer slug capable of resolving this. */
	public $fixer = null;

	/** @var string One of the MODE_* constants. */
	public $fix_mode = self::MODE_MANUAL;

	/** @var array<string,mixed> Arguments the fixer needs. */
	public $fix_payload = array();

	/** @var string Extra discriminator so multiple issues of one code on one object stay distinct. */
	public $key = '';

	/**
	 * Build from an associative array.
	 *
	 * @param array<string,mixed> $data Field values.
	 */
	public static function make( array $data ): Issue {
		$issue = new self();

		foreach ( $data as $field => $value ) {
			if ( property_exists( $issue, $field ) ) {
				$issue->{$field} = $value;
			}
		}

		return $issue;
	}

	/**
	 * Stable identity across audit runs, so an unchanged problem keeps its
	 * first_seen date and a fixed one can be detected as resolved.
	 */
	public function fingerprint(): string {
		return sha1(
			implode(
				'|',
				array(
					$this->code,
					$this->object_type,
					(string) $this->object_id,
					$this->key,
				)
			)
		);
	}

	/**
	 * Priority score (0-100) combining severity with checker-supplied impact
	 * hints such as traffic or product count.
	 */
	public function impact(): int {
		$base = array(
			self::SEVERITY_CRITICAL => 90,
			self::SEVERITY_HIGH     => 70,
			self::SEVERITY_MEDIUM   => 45,
			self::SEVERITY_LOW      => 25,
			self::SEVERITY_INFO     => 10,
		);

		$score = $base[ $this->severity ] ?? 45;

		// Front page, shop page and other high-traffic templates matter more.
		if ( ! empty( $this->evidence['is_front_page'] ) ) {
			$score += 10;
		}
		if ( ! empty( $this->evidence['affected_count'] ) ) {
			$score += min( 10, (int) $this->evidence['affected_count'] );
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Serialise for storage / REST.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'checker'      => $this->checker,
			'code'         => $this->code,
			'severity'     => $this->severity,
			'object_type'  => $this->object_type,
			'object_id'    => $this->object_id,
			'object_label' => $this->object_label,
			'url'          => $this->url,
			'title'        => $this->title,
			'detail'       => $this->detail,
			'evidence'     => $this->evidence,
			'fixer'        => $this->fixer,
			'fix_mode'     => $this->fix_mode,
			'fix_payload'  => $this->fix_payload,
			'fingerprint'  => $this->fingerprint(),
			'impact'       => $this->impact(),
		);
	}
}
