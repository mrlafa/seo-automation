<?php
/**
 * Previews, applies and reverts fixes.
 *
 * @package SEOAgent
 */

namespace SEOAgent\Fix;

use SEOAgent\Audit\Issue;
use SEOAgent\Database\ChangeRepository;
use SEOAgent\Database\IssueRepository;
use SEOAgent\Seo\SeoAdapterInterface;
use SEOAgent\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place in the plugin that writes on behalf of an issue.
 *
 * Every application is a three-step sequence: plan, record the before value,
 * write. If the write throws, the recorded row is marked failed rather than
 * left looking applied — so the change log never claims a change that did not
 * happen.
 */
class FixRunner {

	/** @var FixerRegistry */
	private $fixers;

	/** @var IssueRepository */
	private $issues;

	/** @var ChangeRepository */
	private $changes;

	/** @var SeoAdapterInterface */
	private $seo;

	/**
	 * @param FixerRegistry       $fixers  Available fixers.
	 * @param IssueRepository     $issues  Issue storage.
	 * @param ChangeRepository    $changes Change log.
	 * @param SeoAdapterInterface $seo     Metadata adapter.
	 */
	public function __construct(
		FixerRegistry $fixers,
		IssueRepository $issues,
		ChangeRepository $changes,
		SeoAdapterInterface $seo
	) {
		$this->fixers  = $fixers;
		$this->issues  = $issues;
		$this->changes = $changes;
		$this->seo     = $seo;
	}

	/**
	 * Work out what a fix would do, without doing it.
	 *
	 * @param int                 $issue_id Issue ID.
	 * @param array<string,mixed> $input    Caller-supplied values.
	 *
	 * @return array<string,mixed>
	 */
	public function preview( int $issue_id, array $input = array() ): array {
		$issue = $this->issues->find( $issue_id );

		if ( ! $issue ) {
			return $this->failure( $issue_id, 'issue_missing', 'That issue no longer exists.' );
		}

		$fixer = $this->fixer_for( $issue );

		if ( ! $fixer ) {
			return $this->failure( $issue_id, 'no_fixer', 'This issue has no automatic fix; it is reported for a person to act on.' );
		}

		try {
			$changes = $fixer->plan( $issue, $input, $this->seo );
		} catch ( FixException $e ) {
			return $this->failure( $issue_id, $e->code_slug(), $e->getMessage(), $e->context() );
		} catch ( \Throwable $e ) {
			return $this->failure( $issue_id, 'plan_failed', $e->getMessage() );
		}

		$changes = array_values( array_filter( $changes, static fn( FixChange $change ) => ! $change->is_noop() ) );

		return array(
			'issue_id' => $issue_id,
			'ok'       => true,
			'applied'  => false,
			'fixer'    => $fixer->slug(),
			'changes'  => array_map( static fn( FixChange $change ) => $change->to_array(), $changes ),
			'count'    => count( $changes ),
		);
	}

	/**
	 * Apply a fix.
	 *
	 * @param int                 $issue_id Issue ID.
	 * @param array<string,mixed> $input    Caller-supplied values.
	 * @param string              $batch    Batch UUID to group related changes.
	 *
	 * @return array<string,mixed>
	 */
	public function apply( int $issue_id, array $input = array(), string $batch = '' ): array {
		$issue = $this->issues->find( $issue_id );

		if ( ! $issue ) {
			return $this->failure( $issue_id, 'issue_missing', 'That issue no longer exists.' );
		}

		$fixer = $this->fixer_for( $issue );

		if ( ! $fixer ) {
			return $this->failure( $issue_id, 'no_fixer', 'This issue has no automatic fix.' );
		}

		$gate = $this->autonomy_gate( $issue, $fixer, $input );

		if ( null !== $gate ) {
			return $gate;
		}

		try {
			$changes = $fixer->plan( $issue, $input, $this->seo );
		} catch ( FixException $e ) {
			return $this->failure( $issue_id, $e->code_slug(), $e->getMessage(), $e->context() );
		} catch ( \Throwable $e ) {
			return $this->failure( $issue_id, 'plan_failed', $e->getMessage() );
		}

		$changes = array_values( array_filter( $changes, static fn( FixChange $change ) => ! $change->is_noop() ) );

		if ( empty( $changes ) ) {
			return $this->failure( $issue_id, 'no_change', 'The planned fix would not change anything.' );
		}

		$batch   = '' !== $batch ? $batch : wp_generate_uuid4();
		$applied = array();
		$errors  = array();

		foreach ( $changes as $change ) {
			// Recorded before the write, so a crash mid-write still leaves the
			// original value on disk.
			$change_id = $this->changes->record(
				array(
					'batch'        => $batch,
					'issue_id'     => $issue_id,
					'fixer'        => $fixer->slug(),
					'object_type'  => $change->object_type,
					'object_id'    => $change->object_id,
					'field'        => $change->field,
					'before_value' => $change->before,
					'after_value'  => $change->after,
					'note'         => $change->note,
					'status'       => 'applied',
				)
			);

			try {
				$fixer->write( $change, $this->seo );
				$applied[] = $change->to_array() + array( 'change_id' => $change_id );
			} catch ( \Throwable $e ) {
				$this->changes->mark_failed( $change_id, $e->getMessage() );
				$errors[] = array(
					'change_id' => $change_id,
					'field'     => $change->field,
					'object_id' => $change->object_id,
					'message'   => $e->getMessage(),
				);
			}
		}

		if ( empty( $applied ) ) {
			$this->issues->set_status( $issue_id, 'failed' );

			return array(
				'issue_id' => $issue_id,
				'ok'       => false,
				'applied'  => false,
				'batch'    => $batch,
				'code'     => 'write_failed',
				'message'  => 'Every planned change failed to write.',
				'errors'   => $errors,
			);
		}

		$this->issues->set_status( $issue_id, 'fixed' );

		return array(
			'issue_id' => $issue_id,
			'ok'       => empty( $errors ),
			'applied'  => true,
			'batch'    => $batch,
			'fixer'    => $fixer->slug(),
			'changes'  => $applied,
			'count'    => count( $applied ),
			'errors'   => $errors,
		);
	}

	/**
	 * Apply several fixes as one batch.
	 *
	 * @param int[]                    $issue_ids Issue IDs.
	 * @param array<int,array<string,mixed>> $inputs Per-issue input, keyed by issue ID.
	 * @param bool                     $dry_run   Preview only.
	 *
	 * @return array<string,mixed>
	 */
	public function apply_many( array $issue_ids, array $inputs = array(), bool $dry_run = false ): array {
		$batch   = wp_generate_uuid4();
		$results = array();
		$ok      = 0;

		foreach ( $issue_ids as $issue_id ) {
			$issue_id = (int) $issue_id;
			$input    = (array) ( $inputs[ $issue_id ] ?? $inputs[ (string) $issue_id ] ?? array() );

			$result = $dry_run
				? $this->preview( $issue_id, $input )
				: $this->apply( $issue_id, $input, $batch );

			if ( ! empty( $result['ok'] ) ) {
				++$ok;
			}

			$results[] = $result;
		}

		return array(
			'batch'     => $dry_run ? null : $batch,
			'dry_run'   => $dry_run,
			'total'     => count( $results ),
			'succeeded' => $ok,
			'failed'    => count( $results ) - $ok,
			'results'   => $results,
		);
	}

	/**
	 * Put a batch back the way it was.
	 *
	 * @param string $batch Batch UUID.
	 *
	 * @return array<string,mixed>
	 */
	public function revert_batch( string $batch ): array {
		$rows = $this->changes->by_batch( $batch );

		if ( empty( $rows ) ) {
			return array(
				'ok'      => false,
				'code'    => 'batch_missing',
				'message' => 'No changes recorded under that batch.',
			);
		}

		$reverted = 0;
		$errors   = array();

		// Newest first, so overlapping edits to one field unwind in order.
		foreach ( $rows as $row ) {
			if ( 'applied' !== $row['status'] ) {
				continue;
			}

			$fixer = $this->fixers->get( (string) $row['fixer'] );

			if ( ! $fixer ) {
				$errors[] = array(
					'change_id' => $row['id'],
					'message'   => sprintf( 'Fixer "%s" is no longer registered.', $row['fixer'] ),
				);
				continue;
			}

			// A revert is just the same change with before and after swapped.
			$undo = new FixChange(
				(string) $row['object_type'],
				(int) $row['object_id'],
				(string) $row['field'],
				$row['after_value'],
				$row['before_value'],
				sprintf( 'Reverted change #%d', (int) $row['id'] ),
				array( 'json' => $this->looks_like_json( $row['before_value'] ) )
			);

			try {
				$fixer->write( $undo, $this->seo );
				$this->changes->mark_reverted( (int) $row['id'] );
				++$reverted;

				if ( (int) $row['issue_id'] > 0 ) {
					$this->issues->set_status( (int) $row['issue_id'], 'open' );
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'change_id' => $row['id'],
					'message'   => $e->getMessage(),
				);
			}
		}

		return array(
			'ok'       => empty( $errors ),
			'batch'    => $batch,
			'reverted' => $reverted,
			'errors'   => $errors,
		);
	}

	/**
	 * Revert a single change.
	 *
	 * @param int $change_id Change ID.
	 *
	 * @return array<string,mixed>
	 */
	public function revert_change( int $change_id ): array {
		$row = $this->changes->find( $change_id );

		if ( ! $row ) {
			return array(
				'ok'      => false,
				'code'    => 'change_missing',
				'message' => 'No such change.',
			);
		}

		if ( 'applied' !== $row['status'] ) {
			return array(
				'ok'      => false,
				'code'    => 'not_applied',
				'message' => sprintf( 'That change is marked "%s" and cannot be reverted.', $row['status'] ),
			);
		}

		$fixer = $this->fixers->get( (string) $row['fixer'] );

		if ( ! $fixer ) {
			return array(
				'ok'      => false,
				'code'    => 'fixer_missing',
				'message' => sprintf( 'Fixer "%s" is no longer registered.', $row['fixer'] ),
			);
		}

		$undo = new FixChange(
			(string) $row['object_type'],
			(int) $row['object_id'],
			(string) $row['field'],
			$row['after_value'],
			$row['before_value'],
			sprintf( 'Reverted change #%d', $change_id ),
			array( 'json' => $this->looks_like_json( $row['before_value'] ) )
		);

		try {
			$fixer->write( $undo, $this->seo );
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'code'    => 'revert_failed',
				'message' => $e->getMessage(),
			);
		}

		$this->changes->mark_reverted( $change_id );

		if ( (int) $row['issue_id'] > 0 ) {
			$this->issues->set_status( (int) $row['issue_id'], 'open' );
		}

		return array(
			'ok'        => true,
			'change_id' => $change_id,
		);
	}

	/**
	 * Refuse to apply when the site's autonomy setting says a human should look
	 * first.
	 *
	 * @param array<string,mixed> $issue Issue row.
	 * @param FixerInterface      $fixer Fixer.
	 * @param array<string,mixed> $input Caller input.
	 *
	 * @return array<string,mixed>|null Failure payload, or null to proceed.
	 */
	private function autonomy_gate( array $issue, FixerInterface $fixer, array $input ): ?array {
		// An explicit approval from the caller — a person clicking Apply, or an
		// agent acting on a reviewed plan — always wins.
		if ( ! empty( $input['approved'] ) ) {
			return null;
		}

		$autonomy = (string) Options::get( 'autonomy', 'review' );

		if ( 'auto_all' === $autonomy ) {
			return null;
		}

		if ( 'auto_safe' === $autonomy ) {
			$allowed = (array) Options::get( 'auto_fixers', array() );

			if ( $fixer->is_deterministic() || in_array( $fixer->slug(), $allowed, true ) ) {
				return null;
			}
		}

		if ( Issue::MODE_AUTO === ( $issue['fix_mode'] ?? '' ) && $fixer->is_deterministic() && 'review' !== $autonomy ) {
			return null;
		}

		return $this->failure(
			(int) $issue['id'],
			'approval_required',
			sprintf(
				'The site autonomy setting is "%s", so this fix needs explicit approval. Preview it first, then re-send with "approved": true.',
				$autonomy
			),
			array(
				'autonomy' => $autonomy,
				'fixer'    => $fixer->slug(),
			)
		);
	}

	/**
	 * The fixer an issue names, if it is registered.
	 *
	 * @param array<string,mixed> $issue Issue row.
	 */
	private function fixer_for( array $issue ): ?FixerInterface {
		$slug = (string) ( $issue['fixer'] ?? '' );

		return '' !== $slug ? $this->fixers->get( $slug ) : null;
	}

	/**
	 * Would this stored value decode as structured data?
	 *
	 * @param string|null $value Stored value.
	 */
	private function looks_like_json( ?string $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}

		$first = $value[0];

		return ( '{' === $first || '[' === $first ) && null !== json_decode( $value, true );
	}

	/**
	 * Uniform failure payload.
	 *
	 * @param int                 $issue_id Issue ID.
	 * @param string              $code     Machine code.
	 * @param string              $message  Human message.
	 * @param array<string,mixed> $context  Extra detail.
	 *
	 * @return array<string,mixed>
	 */
	private function failure( int $issue_id, string $code, string $message, array $context = array() ): array {
		return array(
			'issue_id' => $issue_id,
			'ok'       => false,
			'applied'  => false,
			'code'     => $code,
			'message'  => $message,
			'context'  => $context,
		);
	}
}
