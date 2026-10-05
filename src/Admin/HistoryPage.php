<?php
/**
 * Operation history and Undo administration UI.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Admin;

use TermSteward\Application\Undo\UndoErrorCode;
use TermSteward\Application\Undo\UndoException;
use TermSteward\Application\Undo\UndoPlanner;
use TermSteward\Application\Undo\UndoWorkflow;
use TermSteward\Domain\Operation\Action;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Persistence\ChangeJournalRepository;
use TermSteward\Infrastructure\Persistence\OperationItemRepository;
use TermSteward\Infrastructure\Persistence\OperationRepository;

/** Handles the owner-scoped audit list, detail, and Undo commands. */
final class HistoryPage {
	public const NONCE_ACTION  = 'term_steward_undo';
	public const NONCE_FIELD   = 'term_steward_undo_nonce';
	public const LOG_PAGE_SIZE = 100;

	/**
	 * Creates the owner-scoped history screen.
	 *
	 * @param OperationRepository     $operations Operation storage.
	 * @param OperationItemRepository $items      Fixed item storage.
	 * @param ChangeJournalRepository $journal    Actual changes.
	 * @param UndoPlanner             $planner    Undo assessor.
	 * @param UndoWorkflow            $workflow   Bounded Undo workflow.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly OperationItemRepository $items,
		private readonly ChangeJournalRepository $journal,
		private readonly UndoPlanner $planner,
		private readonly UndoWorkflow $workflow
	) {
	}

	/** Renders and handles the complete history tab. */
	public function render(): void {
		$user_id = get_current_user_id();
		$error   = null;
		$modal   = null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Used only for a fixed HTTP verb comparison.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! Access::current_user_can_access() ) {
				$error = UndoErrorCode::INVALID_OPERATION;
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized and verified immediately below.
				$nonce = is_string( $_POST[ self::NONCE_FIELD ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
					$error = 'invalid_nonce';
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; fixed command and IDs sanitized below.
					$request = wp_unslash( $_POST );
					$command = is_string( $request['undo_command'] ?? null ) ? sanitize_key( $request['undo_command'] ) : '';
					try {
						if ( 'preview_undo' === $command ) {
							$original_id = absint( $request['original_operation_id'] ?? 0 );
							$this->assert_taxonomy( $original_id, $user_id, $request );
							$modal = $this->planner->preview( $original_id, $user_id );
						} elseif ( in_array( $command, array( 'run_undo', 'continue_undo' ), true ) ) {
							$undo_id = absint( $request['undo_operation_id'] ?? 0 );
							$this->assert_taxonomy( $undo_id, $user_id, $request );
							$modal = $this->workflow->run_batch( $undo_id, $user_id );
						} else {
							$error = UndoErrorCode::INVALID_OPERATION;
						}
					} catch ( UndoException $exception ) {
						$error = $exception->error_code();
					} catch ( \Throwable $exception ) {
						$this->log_internal_error( $exception );
						$error = UndoErrorCode::JOURNAL_FAILED;
					}
				}
			}
		}

		$requested_page = $this->query_id( 'history_page' );
		$page           = 0 === $requested_page ? 1 : $requested_page;
		$history        = $this->operations->history( $user_id, $page, 20 );
		$detail         = null;
		$detail_id      = $this->query_id( 'history_id' );
		if ( 0 < $detail_id ) {
			$candidate = $this->operations->find_owned( $detail_id, $user_id );
			if ( null !== $candidate && null !== $candidate['started_at'] ) {
				$detail = $candidate;
			} else {
				$error = UndoErrorCode::INVALID_OPERATION;
			}
		}
		if ( null !== $error ) {
			$status = in_array( $error, array( UndoErrorCode::STALE_PREVIEW, UndoErrorCode::LOCKED, UndoErrorCode::IN_PROGRESS, UndoErrorCode::ALREADY_UNDONE, UndoErrorCode::NOT_RESUMABLE, UndoErrorCode::DUPLICATE ), true ) ? 409 : 400;
			status_header( $status );
		}
		?>
		<div id="term-steward-history-content">
		<h2><?php echo esc_html__( 'Operation history', 'term-steward' ); ?></h2>
		<?php if ( null !== $error ) : ?>
			<div class="notice notice-error inline term-steward-history-error" role="alert" tabindex="-1"><p><?php echo esc_html( $this->error_label( $error ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( null !== $detail ) : ?>
			<?php $this->render_detail( $detail, $user_id ); ?>
		<?php else : ?>
			<?php $this->render_list( $history, $user_id ); ?>
		<?php endif; ?>
		</div>
		<?php
		if ( null !== $modal ) {
			$this->render_modal( $modal );
		}
	}

	/**
	 * Runs one authenticated bounded batch and returns only client-safe progress.
	 *
	 * Terminal operations are returned unchanged so a response retry cannot turn a
	 * completed Undo into a misleading error.
	 *
	 * @param int      $undo_id Undo operation ID.
	 * @param int      $user_id Current administrator ID.
	 * @param Taxonomy $taxonomy Posted taxonomy allow-list value.
	 * @return array<string, int|string|bool>
	 * @throws UndoException When identity, state, or locking prevents continuation.
	 */
	public function continue_undo( int $undo_id, int $user_id, Taxonomy $taxonomy ): array {
		$undo   = $this->operations->find_owned( $undo_id, $user_id );
		$parent = null === $undo ? null : $this->operations->find_owned( (int) $undo['parent_operation_id'], $user_id );
		if ( null === $undo || null === $undo['parent_operation_id'] || null === $parent || 'undo' !== ( $undo['requested_data']['kind'] ?? '' ) || $taxonomy->value !== $undo['taxonomy'] || $parent['taxonomy'] !== $undo['taxonomy'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable internal code only.
			throw new UndoException( UndoErrorCode::INVALID_OPERATION );
		}
		if ( in_array( $undo['status'], array( Status::UNDONE->value, Status::UNDO_PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
			$undo['progress'] = $this->items->progress( $undo_id );
		} else {
			$undo = $this->workflow->run_batch( $undo_id, $user_id );
		}
		$progress  = is_array( $undo['progress'] ?? null ) ? $undo['progress'] : array();
		$processed = (int) ( $progress['completed'] ?? 0 ) + (int) ( $progress['failed'] ?? 0 ) + (int) ( $progress['skipped'] ?? 0 );
		$remaining = (int) ( $progress['pending'] ?? 0 );
		$status    = (string) $undo['status'];

		return array(
			'processed'    => $processed,
			'succeeded'    => (int) ( $progress['completed'] ?? 0 ),
			'failed'       => (int) ( $progress['failed'] ?? 0 ),
			'remaining'    => $remaining,
			'total'        => (int) ( $progress['total'] ?? 0 ),
			'skipped'      => (int) ( $progress['skipped'] ?? 0 ),
			'status'       => $status,
			'status_label' => $this->status_label( $status ),
			'has_more'     => Status::UNDOING->value === $status && 0 < $remaining,
		);
	}

	/**
	 * Returns one owner-scoped, bounded page of safe history log labels.
	 *
	 * @param int $operation_id Operation ID.
	 * @param int $user_id      Current administrator ID.
	 * @param int $page         One-based page.
	 * @return array<string, mixed>
	 * @throws UndoException When the operation is missing, unstarted, or belongs to another user.
	 */
	public function history_logs( int $operation_id, int $user_id, int $page ): array {
		$operation = $this->operations->find_owned( $operation_id, $user_id );
		$parent    = null === $operation || null === $operation['parent_operation_id'] ? null : $this->operations->find_owned( (int) $operation['parent_operation_id'], $user_id );
		if ( null === $operation || null === $operation['started_at'] || ( null !== $operation['parent_operation_id'] && ( null === $parent || $parent['taxonomy'] !== $operation['taxonomy'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable internal code only.
			throw new UndoException( UndoErrorCode::INVALID_OPERATION );
		}
		$result          = $this->journal->history_page( $operation_id, $page, self::LOG_PAGE_SIZE );
		$result['items'] = array_map(
			fn( array $change ): array => array(
				'severity' => $this->change_severity( (string) $change['change_type'] ),
				'label'    => $this->change_label( $change ),
				'date'     => $this->date_label( (string) $change['created_at'] ),
			),
			$result['items']
		);
		return $result;
	}

	/**
	 * Requires an owned operation in the posted taxonomy.
	 *
	 * @param int                  $operation_id Operation ID.
	 * @param int                  $user_id      Current administrator ID.
	 * @param array<string, mixed> $request      Verified request values.
	 * @throws UndoException When identity or taxonomy does not match.
	 */
	private function assert_taxonomy( int $operation_id, int $user_id, array $request ): void {
		$operation = $this->operations->find_owned( $operation_id, $user_id );
		$taxonomy  = Taxonomy::tryFrom( sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) );
		if ( null === $operation || null === $taxonomy || $taxonomy->value !== $operation['taxonomy'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable internal code only.
			throw new UndoException( UndoErrorCode::INVALID_OPERATION );
		}
	}

	/**
	 * Renders the paginated history table.
	 *
	 * @param array<string, mixed> $history Page of operations.
	 * @param int                  $user_id Current administrator ID.
	 */
	private function render_list( array $history, int $user_id ): void {
		if ( array() === $history['items'] ) {
			?>
			<p><?php echo esc_html__( 'No operations have been run.', 'term-steward' ); ?></p>
			<?php
			return;
		}
		?>
		<div class="term-steward-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__( 'Operation history list', 'term-steward' ); ?>">
		<table class="wp-list-table widefat fixed striped term-steward-history-table">
			<thead><tr><th scope="col"><?php echo esc_html__( 'Started', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Type', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Action', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Targets', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Changes', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Result', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Undo', 'term-steward' ); ?></th><th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Details', 'term-steward' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $history['items'] as $operation ) : ?>
				<?php $assessment = null === $operation['parent_operation_id'] ? $this->planner->assess( (int) $operation['id'], $user_id ) : null; ?>
				<tr><td><?php echo esc_html( $this->date_label( (string) $operation['started_at'] ) ); ?></td><td><?php echo esc_html( $this->taxonomy_label( (string) $operation['taxonomy'] ) ); ?></td><td><?php echo esc_html( $this->action_summary( $operation ) ); ?></td><td><?php echo esc_html( (string) $this->target_count( $operation ) ); ?></td><td><?php echo esc_html( (string) $this->change_count( (int) $operation['id'] ) ); ?></td><td><?php echo esc_html( $this->status_label( (string) $operation['status'] ) ); ?></td><td><?php echo esc_html( null === $assessment ? $this->undo_result_label( $operation ) : $this->availability_label( (string) $assessment['availability'] ) ); ?></td><td><a href="<?php echo esc_url( $this->history_url( array( 'history_id' => (int) $operation['id'] ) ) ); ?>"><?php echo esc_html__( 'Details', 'term-steward' ); ?></a></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php $this->render_pagination( $history ); ?>
		<?php
	}

	/**
	 * Renders a safe human-readable operation detail.
	 *
	 * @param array<string, mixed> $operation Owned started operation.
	 * @param int                  $user_id   Current administrator ID.
	 */
	private function render_detail( array $operation, int $user_id ): void {
		$changes    = $this->journal->history_preview( (int) $operation['id'] );
		$log_counts = $this->journal->history_counts( (int) $operation['id'] );
		$assessment = null === $operation['parent_operation_id'] ? $this->planner->assess( (int) $operation['id'], $user_id ) : null;
		$result     = is_array( $operation['result_data'] ) ? $operation['result_data'] : array();
		?>
		<p><a href="<?php echo esc_url( $this->history_url() ); ?>">&larr; <?php echo esc_html__( 'Back to operation history', 'term-steward' ); ?></a></p>
		<h3><?php echo esc_html__( 'Operation details', 'term-steward' ); ?></h3>
		<dl class="term-steward-history-detail">
			<dt><?php echo esc_html__( 'Started', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->date_label( (string) $operation['started_at'] ) ); ?></dd>
			<dt><?php echo esc_html__( 'Completed', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->date_label( (string) ( $operation['completed_at'] ?? '' ) ) ); ?></dd>
			<dt><?php echo esc_html__( 'Type', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->taxonomy_label( (string) $operation['taxonomy'] ) ); ?></dd>
			<dt><?php echo esc_html__( 'Actions performed', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->action_summary( $operation ) ); ?></dd>
			<dt><?php echo esc_html__( 'Target terms', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->target_label( $operation ) ); ?></dd>
			<dt><?php echo esc_html__( 'Result', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->status_label( (string) $operation['status'] ) ); ?></dd>
			<dt><?php echo esc_html__( 'Successful items', 'term-steward' ); ?></dt><dd><?php echo esc_html( (string) (int) ( $result['completed'] ?? 0 ) ); ?></dd>
			<dt><?php echo esc_html__( 'Failed items', 'term-steward' ); ?></dt><dd><?php echo esc_html( (string) (int) ( $result['failed'] ?? 0 ) ); ?></dd>
			<dt><?php echo esc_html__( 'Processed items', 'term-steward' ); ?></dt><dd><?php echo esc_html( (string) ( (int) ( $result['total'] ?? 0 ) - (int) ( $result['pending'] ?? 0 ) ) ); ?></dd>
			<dt><?php echo esc_html__( 'Warning count', 'term-steward' ); ?></dt><dd><?php echo esc_html( (string) $log_counts['warning'] ); ?></dd>
			<dt><?php echo esc_html__( 'Warnings', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->message_summary( $operation['warnings'], true ) ); ?></dd>
			<dt><?php echo esc_html__( 'Errors', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->message_summary( $operation['errors'], false ) ); ?></dd>
			<dt><?php echo esc_html__( 'Undo status', 'term-steward' ); ?></dt><dd><?php echo esc_html( $this->undo_state_label( $operation ) ); ?></dd>
			<?php if ( null !== $operation['parent_operation_id'] && in_array( $operation['status'], array( Status::UNDO_PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) : ?>
				<dt><?php echo esc_html__( 'Remaining items', 'term-steward' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: remaining Undo item count. */ __( '%d items', 'term-steward' ), $this->remaining_count( $operation ) ) ); ?></dd>
				<dt><?php echo esc_html__( 'Retry', 'term-steward' ); ?></dt><dd><?php echo esc_html__( 'Automatic retry is not available. Check the current status.', 'term-steward' ); ?></dd>
			<?php endif; ?>
			<?php
			if ( null !== $operation['parent_operation_id'] ) :
				?>
				<dt><?php echo esc_html__( 'Undo target', 'term-steward' ); ?></dt><dd><a href="<?php echo esc_url( $this->history_url( array( 'history_id' => (int) $operation['parent_operation_id'] ) ) ); ?>"><?php echo esc_html__( 'View original operation', 'term-steward' ); ?></a></dd><?php endif; ?>
		</dl>
		<?php $this->render_change_summary( $operation, $changes, $log_counts ); ?>
		<?php if ( null !== $assessment ) : ?>
			<h3><?php echo esc_html__( 'Undo availability', 'term-steward' ); ?></h3>
			<p><strong><?php echo esc_html( $this->availability_label( (string) $assessment['availability'] ) ); ?></strong><br><?php echo esc_html( $this->reason_label( (string) $assessment['reason'] ) ); ?></p>
			<?php if ( 'none' !== $assessment['availability'] ) : ?>
			<form method="post"><input type="hidden" name="view" value="history"><input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><input type="hidden" name="original_operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $operation['taxonomy'] ); ?>"><button type="submit" class="button button-secondary term-steward-undo-preview" name="undo_command" value="preview_undo"><?php echo esc_html__( 'Undo changes', 'term-steward' ); ?></button></form>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( null !== $operation['parent_operation_id'] && Status::UNDOING->value === $operation['status'] ) : ?>
			<form method="post" class="term-steward-undo-resume"><input type="hidden" name="view" value="history"><input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><input type="hidden" name="undo_operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $operation['taxonomy'] ); ?>"><button type="submit" class="button button-primary" name="undo_command" value="continue_undo"><?php echo esc_html__( 'Resume undo', 'term-steward' ); ?></button></form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders only human-readable actual changes.
	 *
	 * @param array<string, mixed>       $operation Operation row.
	 * @param list<array<string, mixed>> $changes  Preview journal entries.
	 * @param array<string, int>         $counts   Journal severity totals.
	 */
	private function render_change_summary( array $operation, array $changes, array $counts ): void {
		if ( 0 === $counts['total'] ) {
			return;
		}
		$region_id = 'term-steward-history-logs-' . (int) $operation['id'];
		?>
		<section class="term-steward-history-logs" data-operation="<?php echo esc_attr( (string) $operation['id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" data-error="<?php echo esc_attr__( 'Could not retrieve the logs. Try again.', 'term-steward' ); ?>">
		<h3><?php echo esc_html__( 'Recent logs', 'term-steward' ); ?></h3>
		<p class="term-steward-log-counts"><?php echo esc_html( sprintf( /* translators: 1: total logs, 2: successful logs, 3: warnings, 4: errors. */ __( 'Total: %1$d; successful: %2$d; warnings: %3$d; errors: %4$d', 'term-steward' ), $counts['total'], $counts['success'], $counts['warning'], $counts['error'] ) ); ?></p>
		<ul id="<?php echo esc_attr( $region_id ); ?>" class="term-steward-change-summary" aria-live="polite">
		<?php
		foreach ( $changes as $change ) {
			?>
			<li class="term-steward-log term-steward-log--<?php echo esc_attr( $this->change_severity( (string) $change['change_type'] ) ); ?>"><strong><?php echo esc_html( $this->severity_label( (string) $change['change_type'] ) ); ?></strong> <?php echo esc_html( $this->change_label( $change ) ); ?></li>
			<?php
		}
		?>
		</ul>
		<?php
		if ( 5 < $counts['total'] ) :
			?>
			<button type="button" class="button-link tt-link-button term-steward-log-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $region_id ); ?>"><?php echo esc_html__( 'View details', 'term-steward' ); ?></button><?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders the Undo preview or progress dialog.
	 *
	 * @param array<string, mixed> $undo Undo operation.
	 */
	private function render_modal( array $undo ): void {
		$preview    = is_array( $undo['requested_data']['preview'] ?? null ) ? $undo['requested_data']['preview'] : array();
		$running    = Status::UNDOING->value === $undo['status'];
		$previewing = Status::UNDO_PREVIEWED->value === $undo['status'];
		?>
		<div class="term-steward term-steward-modal term-steward-history-modal" data-auto-open="1" data-auto-continue="<?php echo $running ? '1' : '0'; ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" hidden><div class="term-steward-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="term-steward-undo-heading" tabindex="-1">
		<header class="term-steward-modal__header"><h2 id="term-steward-undo-heading"><?php echo esc_html( $previewing ? __( 'Undo preview', 'term-steward' ) : __( 'Undo result', 'term-steward' ) ); ?></h2><button type="button" class="term-steward-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'term-steward' ); ?>" <?php disabled( $running ); ?>>&times;</button></header>
		<div class="term-steward-modal__body" aria-live="polite" aria-atomic="true">
		<?php if ( $previewing ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: target term names. */ __( 'Undo target: %s', 'term-steward' ), $this->undo_target_label( $undo ) ) ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: %s: taxonomy label. */ __( 'Target: %s', 'term-steward' ), $this->taxonomy_label( (string) $undo['taxonomy'] ) ) ); ?></p>
			<p><strong><?php echo esc_html( $this->availability_label( (string) $preview['availability'] ) ); ?></strong></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: restored terms, 2: restored assignments, 3: removed assignments. */ __( 'Terms to restore: %1$d; assignments to restore: %2$d; assignments to remove: %3$d', 'term-steward' ), (int) $preview['restore_terms'], (int) $preview['restore_assignments'], (int) $preview['remove_assignments'] ) ); ?></p>
			<?php
			if ( array() !== (array) $preview['conflicts'] ) :
				?>
				<p class="term-steward-message term-steward-message--warning"><?php echo esc_html( sprintf( /* translators: %d: conflict count. */ __( '%d items cannot be undone because of conflicts.', 'term-steward' ), count( $preview['conflicts'] ) ) ); ?></p>
				<ul class="term-steward-conflicts">
				<?php foreach ( $preview['conflicts'] as $conflict ) : ?>
					<li><strong><?php echo esc_html( $this->conflict_target_label( (string) $conflict['type'] ) ); ?></strong><br><?php echo esc_html( $this->conflict_plan_label( (string) $conflict['type'] ) ); ?><br><?php echo esc_html( $this->conflict_current_label( (string) $conflict['reason'] ) ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<p><strong><?php echo esc_html( $this->status_label( (string) $undo['status'] ) ); ?></strong></p>
			<?php $progress = is_array( $undo['progress'] ?? null ) ? $undo['progress'] : (array) $undo['result_data']; ?>
			<p class="term-steward-undo-progress"><?php echo esc_html( sprintf( /* translators: 1: completed count, 2: total count, 3: failed count, 4: remaining count. */ __( 'Progress: %1$d / %2$d; successful: %1$d; failed: %3$d; remaining: %4$d', 'term-steward' ), (int) ( $progress['completed'] ?? 0 ), (int) ( $progress['total'] ?? 0 ), (int) ( $progress['failed'] ?? 0 ), (int) ( $progress['pending'] ?? 0 ) ) ); ?></p>
		<?php endif; ?>
		</div>
		<footer class="term-steward-modal__footer"><button type="button" class="button tt-button tt-button--secondary term-steward-modal__cancel" <?php disabled( $running ); ?>><?php echo esc_html__( 'Cancel', 'term-steward' ); ?></button>
		<form method="post"><input type="hidden" name="view" value="history"><input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><input type="hidden" name="undo_operation_id" value="<?php echo esc_attr( (string) $undo['id'] ); ?>"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $undo['taxonomy'] ); ?>">
		<?php
		if ( $previewing ) :
			?>
			<button type="submit" class="button button-primary tt-button tt-button--primary" name="undo_command" value="run_undo"><?php echo esc_html__( 'Undo', 'term-steward' ); ?></button>
			<?php
			endif;
		?>
		</form></footer></div></div>
		<?php
	}

	/**
	 * Renders numerical server-side pagination.
	 *
	 * @param array<string, mixed> $history Page metadata.
	 */
	private function render_pagination( array $history ): void {
		if ( 1 >= (int) $history['total_pages'] ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'history_page', '%#%', $this->history_url() ),
				'format'    => '',
				'current'   => (int) $history['page'],
				'total'     => (int) $history['total_pages'],
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'term-steward' ),
				'next_text' => __( 'Next', 'term-steward' ),
			)
		);
		if ( is_string( $links ) ) {
			?>
			<nav class="tablenav-pages" aria-label="<?php echo esc_attr__( 'Operation history pagination', 'term-steward' ); ?>"><?php echo wp_kses_post( $links ); ?></nav>
			<?php
		}
	}

	/**
	 * Builds an owner-facing history URL.
	 *
	 * @param array<string, mixed> $args Additional query values.
	 */
	private function history_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => Page::SLUG,
					'view' => 'history',
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Reads a positive read-only history query ID.
	 *
	 * @param string $key Query parameter name.
	 */
	private function query_id( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only value is unslashed, scalar checked, sanitized, and digit checked below.
		$value = wp_unslash( $_GET[ $key ] ?? '' );
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * Returns a localized action summary.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function action_summary( array $operation ): string {
		if ( null !== $operation['parent_operation_id'] ) {
			return __( 'Undo', 'term-steward' );
		}
		$actions = array_unique( array_column( (array) ( $operation['requested_data']['plan'] ?? array() ), 'action' ) );
		$labels  = array_map(
			static fn( string $action ): string => match ( $action ) {
				Action::RENAME->value => __( 'Rename', 'term-steward' ),
				Action::MERGE->value => __( 'Merge', 'term-steward' ),
				default => __( 'Delete', 'term-steward' ),
			},
			$actions
		);
		return array() === $labels ? __( 'Completed operation', 'term-steward' ) : implode( '、', $labels );
	}

	/**
	 * Counts requested source terms or Undo items.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function target_count( array $operation ): int {
		if ( null !== $operation['parent_operation_id'] ) {
			return (int) ( $operation['result_data']['total'] ?? count( (array) ( $operation['requested_data']['preview']['items'] ?? array() ) ) );
		}
		$count = 0;
		foreach ( (array) ( $operation['requested_data']['plan'] ?? array() ) as $item ) {
			$count += count( (array) ( $item['sources'] ?? array() ) );
		}
		return $count;
	}

	/**
	 * Returns the persisted target term names without looking up deleted terms.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function target_label( array $operation ): string {
		$names   = array();
		$preview = (array) ( $operation['requested_data']['preview']['items'] ?? array() );
		foreach ( $preview as $item ) {
			foreach ( (array) ( $item['sources'] ?? array() ) as $source ) {
				if ( '' !== (string) ( $source['name'] ?? '' ) ) {
					$names[] = (string) $source['name'];
				}
			}
		}
		if ( array() === $names ) {
			foreach ( $this->items->find_for_operation( (int) $operation['id'] ) as $item ) {
				$payload  = (array) $item['payload'];
				$snapshot = (array) ( $payload['source_snapshot'] ?? $payload['snapshot'] ?? $payload['before'] ?? array() );
				if ( '' !== (string) ( $snapshot['name'] ?? '' ) ) {
					$names[] = (string) $snapshot['name'];
				}
			}
		}
		return array() === $names ? __( 'No record', 'term-steward' ) : implode( '、', array_unique( $names ) );
	}

	/**
	 * Returns target names for the original operation of an Undo.
	 *
	 * @param array<string, mixed> $undo Undo operation.
	 */
	private function undo_target_label( array $undo ): string {
		$original = $this->operations->find( (int) ( $undo['parent_operation_id'] ?? 0 ) );
		return null === $original ? __( 'No record', 'term-steward' ) : $this->target_label( $original );
	}

	/**
	 * Counts actual mutations for one operation.
	 *
	 * @param int $operation_id Operation ID.
	 */
	private function change_count( int $operation_id ): int {
		return count( array_filter( $this->journal->find_for_operation( $operation_id ), fn( array $change ): bool => $this->is_actual_change( (string) $change['change_type'] ) ) );
	}

	/**
	 * Distinguishes mutations from audit-only journal events.
	 *
	 * @param string $type Journal change type.
	 */
	private function is_actual_change( string $type ): bool {
		return ! in_array( $type, array( 'destination_existing', 'source_retained', 'item_failed', 'undo_item_failed' ), true );
	}

	/**
	 * Returns the user-facing severity for one journal event.
	 *
	 * @param string $type Journal change type.
	 */
	private function change_severity( string $type ): string {
		if ( in_array( $type, array( 'item_failed', 'undo_item_failed' ), true ) ) {
			return 'error';
		}
		return 'source_retained' === $type ? 'warning' : 'success';
	}

	/**
	 * Returns a localized severity label without relying on color.
	 *
	 * @param string $type Journal change type.
	 */
	private function severity_label( string $type ): string {
		return match ( $this->change_severity( $type ) ) {
			'error' => __( 'Failed: ', 'term-steward' ),
			'warning' => __( 'Warning: ', 'term-steward' ),
			default => __( 'Success: ', 'term-steward' ),
		};
	}

	/**
	 * Counts unique published posts appearing in relationship changes.
	 *
	 * @param list<array<string, mixed>> $changes Journal entries.
	 */
	private function affected_post_count( array $changes ): int {
		$ids = array();
		foreach ( $changes as $change ) {
			if ( in_array( $change['change_type'], array( 'destination_added', 'destination_existing', 'source_removed', 'source_restored', 'destination_removed' ), true ) && null !== $change['object_id'] ) {
				$ids[] = (int) $change['object_id'];
			}
		}
		return count( array_unique( $ids ) );
	}

	/**
	 * Counts safely retained merge sources.
	 *
	 * @param list<array<string, mixed>> $changes Journal entries.
	 */
	private function retained_count( array $changes ): int {
		return count( array_filter( $changes, static fn( array $change ): bool => 'source_retained' === $change['change_type'] ) );
	}

	/**
	 * Returns a localized persisted status.
	 *
	 * @param string $status Stored status.
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			Status::RUNNING->value => __( 'Running', 'term-steward' ),
			Status::COMPLETED->value => __( 'Completed', 'term-steward' ),
			Status::PARTIAL_FAILED->value => __( 'Partially failed', 'term-steward' ),
			Status::FAILED->value => __( 'Failed', 'term-steward' ),
			Status::UNDO_PREVIEWED->value => __( 'Undo previewed', 'term-steward' ),
			Status::UNDOING->value => __( 'Undoing', 'term-steward' ),
			Status::UNDONE->value => __( 'Undone', 'term-steward' ),
			Status::UNDO_PARTIAL_FAILED->value => __( 'Partially undone', 'term-steward' ),
			default => __( 'Not run', 'term-steward' ),
		};
	}

	/**
	 * Returns the three-level Undo availability label.
	 *
	 * @param string $availability Full, partial, or none.
	 */
	private function availability_label( string $availability ): string {
		return match ( $availability ) {
			'full' => __( 'Fully undoable', 'term-steward' ),
			'partial' => __( 'Partially undoable', 'term-steward' ),
			default => __( 'Cannot be undone', 'term-steward' ),
		};
	}

	/**
	 * Returns an Undo operation outcome for the history table.
	 *
	 * @param array<string, mixed> $operation Undo operation.
	 */
	private function undo_result_label( array $operation ): string {
		return match ( (string) $operation['status'] ) {
			Status::UNDONE->value => __( 'Undone', 'term-steward' ),
			Status::UNDO_PARTIAL_FAILED->value => __( 'Partially undone', 'term-steward' ),
			Status::UNDOING->value => __( 'Undoing', 'term-steward' ),
			default => __( 'Undo failed', 'term-steward' ),
		};
	}

	/**
	 * Returns a non-technical Undo availability reason.
	 *
	 * @param string $reason Stable reason code.
	 */
	private function reason_label( string $reason ): string {
		return match ( $reason ) {
			'state_matches' => __( 'The current state matches the state immediately after execution.', 'term-steward' ),
			'some_conflicts' => __( 'Items that no longer match will remain unchanged; only safe items can be undone.', 'term-steward' ),
			'journal_missing' => __( 'The change history required for restoration is unavailable.', 'term-steward' ),
			'undo_already_started' => __( 'Undo has already started for this operation.', 'term-steward' ),
			'status_not_undoable' => __( 'Undo cannot start from the current operation status.', 'term-steward' ),
			default => __( 'The current state cannot be safely restored.', 'term-steward' ),
		};
	}

	/**
	 * Returns a localized supported taxonomy name.
	 *
	 * @param string $taxonomy Stored taxonomy.
	 */
	private function taxonomy_label( string $taxonomy ): string {
		return Taxonomy::CATEGORY->value === $taxonomy ? __( 'Category', 'term-steward' ) : __( 'Tag', 'term-steward' );
	}

	/**
	 * Formats a stored UTC timestamp in the WordPress timezone.
	 *
	 * @param string $date Stored UTC MySQL timestamp.
	 */
	private function date_label( string $date ): string {
		if ( '' === $date ) {
			return __( '—', 'term-steward' );
		}
		$timestamp = strtotime( $date . ' UTC' );
		return false === $timestamp ? __( '—', 'term-steward' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Returns a safe localized warning or error summary.
	 *
	 * @param mixed $messages Stored message collection.
	 * @param bool  $warning  Whether this is a warning collection.
	 */
	private function message_summary( mixed $messages, bool $warning ): string {
		if ( ! is_array( $messages ) || array() === $messages ) {
			return __( 'None', 'term-steward' );
		}
		$parts = array();
		foreach ( $messages as $key => $value ) {
			$count = is_numeric( $value ) ? (int) $value : 1;
			if ( 'source_retained' === $key ) {
				$parts[] = sprintf( /* translators: %d: retained term count. */ __( 'Terms retained for safety: %d', 'term-steward' ), $count );
			} elseif ( 'conflicts' === $key ) {
				$parts[] = sprintf( /* translators: %d: conflict count. */ __( 'Conflicts: %d', 'term-steward' ), $count );
			} elseif ( 'item_failures' === $key ) {
				$parts[] = sprintf( /* translators: %d: failed item count. */ __( 'Failed items: %d', 'term-steward' ), $count );
			} elseif ( 'start_failure' === $key ) {
				$parts[] = __( 'No target could be processed, so the operation did not start and ended as failed. Recreate the plan and review the changes again.', 'term-steward' );
			} else {
				$parts[] = $warning
					? sprintf( /* translators: %d: warning count. */ __( 'Warnings: %d', 'term-steward' ), $count )
					: sprintf( /* translators: %d: error count. */ __( 'Errors: %d', 'term-steward' ), $count );
			}
		}
		return implode( '、', $parts );
	}

	/**
	 * Returns whether and how the original operation has been undone.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function undo_state_label( array $operation ): string {
		if ( null !== $operation['parent_operation_id'] ) {
			return $this->status_label( (string) $operation['status'] );
		}
		$undos = $this->operations->started_undos( (int) $operation['id'] );
		return array() === $undos ? __( 'Not run', 'term-steward' ) : $this->status_label( (string) $undos[0]['status'] );
	}

	/**
	 * Counts failed, conflicting, or pending inverse items.
	 *
	 * @param array<string, mixed> $operation Undo operation.
	 */
	private function remaining_count( array $operation ): int {
		$result = is_array( $operation['result_data'] ) ? $operation['result_data'] : array();
		return (int) ( $result['failed'] ?? 0 ) + (int) ( $result['pending'] ?? 0 ) + (int) ( $result['preview_conflicts'] ?? 0 );
	}

	/**
	 * Returns the non-technical object type involved in a conflict.
	 *
	 * @param string $type Journal change type.
	 */
	private function conflict_target_label( string $type ): string {
		return 'merge_relationship' === $type ? __( 'Target post assignment', 'term-steward' ) : __( 'Target term', 'term-steward' );
	}

	/**
	 * Returns the intended inverse action without exposing raw snapshots.
	 *
	 * @param string $type Journal change type.
	 */
	private function conflict_plan_label( string $type ): string {
		return match ( $type ) {
			'name_changed' => __( 'Planned restoration: restore the original name', 'term-steward' ),
			'slug_changed' => __( 'Planned restoration: restore the original slug', 'term-steward' ),
			'merge_relationship' => __( 'Planned restoration: restore the original post assignment', 'term-steward' ),
			default => __( 'Planned restoration: recreate the deleted term', 'term-steward' ),
		};
	}

	/**
	 * Returns a safe description of why current state was preserved.
	 *
	 * @param string $reason Stable conflict reason.
	 */
	private function conflict_current_label( string $reason ): string {
		return match ( $reason ) {
			'term_or_slug_exists', 'value_conflict' => __( 'Current state: the same name or slug is in use. No changes were made.', 'term-steward' ),
			'parent_missing' => __( 'Current state: the original parent category no longer exists. No changes were made.', 'term-steward' ),
			'post_missing_or_changed' => __( 'Current state: the target post is missing or is not a published standard post. No changes were made.', 'term-steward' ),
			'assignment_changed' => __( 'Current state: the post assignment changed after execution. No changes were made.', 'term-steward' ),
			'source_changed', 'destination_changed', 'value_changed' => __( 'Current state: the term changed after execution. No changes were made.', 'term-steward' ),
			default => __( 'Current state: the information required for safe restoration could not be verified. No changes were made.', 'term-steward' ),
		};
	}

	/**
	 * Returns a human-readable actual change.
	 *
	 * @param array<string, mixed> $change Journal entry.
	 */
	private function change_label( array $change ): string {
		$type    = (string) $change['change_type'];
		$payload = is_array( $change['item_payload'] ?? null ) ? $change['item_payload'] : array();
		$source  = (array) ( $payload['source_snapshot'] ?? $payload['snapshot'] ?? $payload['before'] ?? array() );
		$target  = (string) ( $source['name'] ?? '' );
		$name    = '' === $target ? __( 'Target term', 'term-steward' ) : sprintf( /* translators: %s: term name. */ __( 'Term "%s"', 'term-steward' ), $target );
		return match ( $type ) {
			'name_changed' => sprintf( /* translators: 1: old name, 2: new name. */ __( 'Changed the name from "%1$s" to "%2$s".', 'term-steward' ), (string) ( $change['before_data']['name'] ?? '' ), (string) ( $change['after_data']['name'] ?? '' ) ),
			'slug_changed' => sprintf( /* translators: 1: term label, 2: old slug, 3: new slug. */ __( 'Changed the slug for %1$s from "%2$s" to "%3$s".', 'term-steward' ), $name, (string) ( $change['before_data']['slug'] ?? '' ), (string) ( $change['after_data']['slug'] ?? '' ) ),
			'destination_added' => sprintf( /* translators: %s: source term label. */ __( 'Merged %s and added the destination post assignment.', 'term-steward' ), $name ),
			'destination_existing' => sprintf( /* translators: %s: source term label. */ __( 'The merge destination for %s was already assigned to the post.', 'term-steward' ), $name ),
			'source_removed' => sprintf( /* translators: %s: source term label. */ __( 'Removed the post assignment from source %s.', 'term-steward' ), $name ),
			'source_deleted', 'term_deleted' => sprintf( /* translators: %s: deleted term name. */ __( 'Deleted term "%s"', 'term-steward' ), (string) ( $change['before_data']['name'] ?? '' ) ),
			'term_restored' => sprintf( /* translators: %s: restored term name. */ __( 'Restored term "%s"', 'term-steward' ), (string) ( $change['after_data']['name'] ?? '' ) ),
			'source_restored' => sprintf( /* translators: %s: source term label. */ __( 'Restored the post assignment for %s.', 'term-steward' ), $name ),
			'destination_removed' => sprintf( /* translators: %s: source term label. */ __( 'Removed the destination assignment added by the original operation for %s.', 'term-steward' ), $name ),
			'name_restored' => sprintf( /* translators: %s: restored name. */ __( 'Restored the name to "%s".', 'term-steward' ), (string) ( $change['after_data']['name'] ?? '' ) ),
			'slug_restored' => sprintf( /* translators: 1: term label, 2: restored slug. */ __( 'Restored the slug for %1$s to "%2$s".', 'term-steward' ), $name, (string) ( $change['after_data']['slug'] ?? '' ) ),
			'source_retained' => sprintf( /* translators: %s: retained term label. */ __( '%s was retained because the safety conditions were not met.', 'term-steward' ), $name ),
			'item_failed', 'undo_item_failed' => sprintf( /* translators: %s: failed term label. */ __( '%s could not be processed because of a conflict or state change.', 'term-steward' ), $name ),
			default => __( 'Recorded the processing result.', 'term-steward' ),
		};
	}

	/**
	 * Maps internal error codes to safe localized text.
	 *
	 * @param string $code Stable error code.
	 */
	private function error_label( string $code ): string {
		return match ( $code ) {
			'invalid_nonce' => __( 'This action has expired. Refresh the page and try again.', 'term-steward' ),
			UndoErrorCode::NOT_AVAILABLE => __( 'This operation cannot be safely undone.', 'term-steward' ),
			UndoErrorCode::STALE_PREVIEW => __( 'The state changed after the preview, so undo did not start. Review it again.', 'term-steward' ),
			UndoErrorCode::LOCKED => __( 'Another operation is running. Try again later.', 'term-steward' ),
			UndoErrorCode::IN_PROGRESS => __( 'Undo is already running. Check its current status in operation history.', 'term-steward' ),
			UndoErrorCode::ALREADY_UNDONE => __( 'This operation has already been undone.', 'term-steward' ),
			UndoErrorCode::NOT_RESUMABLE => __( 'This undo process is complete and cannot be started again. Check the result in operation history.', 'term-steward' ),
			UndoErrorCode::DUPLICATE => __( 'Multiple undo records exist for this operation, so safety could not be verified. A new undo was not started.', 'term-steward' ),
			default => __( 'The operation history or undo process could not be verified.', 'term-steward' ),
		};
	}

	/**
	 * Writes technical details only to the configured debug log.
	 *
	 * @param \Throwable $exception Internal failure.
	 */
	private function log_internal_error( \Throwable $exception ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal details are never rendered.
			error_log( sprintf( 'Term Steward history error: %s: %s', $exception::class, $exception->getMessage() ) );
		}
	}
}
