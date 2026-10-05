<?php
/**
 * Shared operation-plan tab for the two independent taxonomies.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Admin;

use TermSteward\Application\Execution\ExecutionException;
use TermSteward\Application\Execution\ExecutionErrorCode;
use TermSteward\Application\Execution\ExecutionWorkflow;
use TermSteward\Application\Planning\PlanErrorCode;
use TermSteward\Application\Planning\PlanService;
use TermSteward\Application\Planning\PlanValidationException;
use TermSteward\Application\Planning\PlanWorkflow;
use TermSteward\Domain\Operation\Action;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use WP_Term;

/**
 * Displays both owned plans while preserving separate operation lifecycles.
 */
final class PlanBoard {
	/** Supported taxonomies in display and execution order. */
	private const TAXONOMIES = array( Taxonomy::CATEGORY, Taxonomy::POST_TAG );

	/**
	 * Stores the existing planning and execution services.
	 *
	 * @param OperationRepository $operations Stored operations.
	 * @param PlanWorkflow        $workflow   Draft and preview workflow.
	 * @param PlanService         $plans      Read-only validation.
	 * @param ExecutionWorkflow   $execution Existing bounded execution.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly PlanWorkflow $workflow,
		private readonly PlanService $plans,
		private readonly ExecutionWorkflow $execution
	) {
	}

	/**
	 * Counts only the current administrator's draft items across both taxonomies.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws \Throwable When saved operation data cannot be read.
	 */
	public function draft_count( int $user_id ): int {
		$count = 0;
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $this->operations->find_draft( $user_id, $taxonomy );
			if ( null !== $operation ) {
				$count += count( (array) ( $operation['requested_data']['plan'] ?? array() ) );
			}
		}
		return $count;
	}

	/**
	 * Handles authenticated board POSTs and returns the visible operation state.
	 *
	 * @return array<string, mixed>
	 * @throws PlanValidationException When a submitted board action is invalid.
	 */
	public function handle(): array {
		$user_id = get_current_user_id();
		$state   = array(
			'operations'     => $this->load_operations( $user_id ),
			'errors'         => array(),
			'error_messages' => array(),
			'notice'         => null,
			'notice_type'    => 'success',
			'modal'          => false,
			'results'        => array(),
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Request method is used only for a fixed HTTP verb comparison.
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return $state;
		}
		if ( ! Access::current_user_can_access() ) {
			$state['errors'][] = PlanErrorCode::PERMISSION_DENIED;
			return $state;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Type-checked, unslashed, sanitized, and verified below.
		$posted_nonce = $_POST[ PlanController::NONCE_FIELD ] ?? '';
		$nonce        = is_string( $posted_nonce ) ? sanitize_text_field( wp_unslash( $posted_nonce ) ) : '';
		if ( ! wp_verify_nonce( $nonce, PlanController::NONCE_ACTION ) ) {
			$state['errors'][] = PlanErrorCode::INVALID_NONCE;
			return $state;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; command values are sanitized below.
		$request = wp_unslash( $_POST );
		$command = is_string( $request['plan_command'] ?? null ) ? sanitize_key( $request['plan_command'] ) : '';
		try {
			if ( 'preview_all' === $command ) {
				$this->preview_all( $user_id );
				$state['modal'] = true;
			} elseif ( 'run_all' === $command ) {
				$state['results'] = $this->run_all( $user_id );
				$this->set_execution_feedback( $state );
			} elseif ( 'continue_all' === $command ) {
				$state['results'] = $this->continue_all( $user_id, $request );
				$this->set_execution_feedback( $state );
			} elseif ( 'remove_item' === $command ) {
				$this->require_no_running( $user_id );
				$taxonomy = $this->requested_taxonomy( $request );
				$this->verify_draft_item( $user_id, $taxonomy, $request );
				$index = $this->item_index( $request );
				$this->workflow->remove( $user_id, $taxonomy, $index );
				$state['notice'] = __( 'Removed from the operation plan.', 'term-steward' );
			} elseif ( 'discard_all' === $command ) {
				$this->require_no_running( $user_id );
				if ( '1' !== (string) ( $request['confirmed'] ?? '' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
					throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
				}
				foreach ( self::TAXONOMIES as $taxonomy ) {
					$operation = $this->workflow->current( $user_id, $taxonomy );
					if ( null !== $operation ) {
						$this->workflow->discard( $user_id, $taxonomy );
					}
				}
				$state['notice'] = __( 'Discarded the operation plan.', 'term-steward' );
			} else {
				$state['errors'][] = PlanErrorCode::PLAN_INVALID;
			}
		} catch ( PlanValidationException $exception ) {
			$state['errors'] = $exception->codes();
		} catch ( ExecutionException $exception ) {
			$state['errors'] = array( $exception->error_code() );
			if ( null !== $exception->target_name() ) {
				$state['error_messages'][] = $this->delete_start_error( $exception );
			}
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal diagnostic only.
			}
			$state['errors'] = array( PlanErrorCode::UNKNOWN_ERROR );
		}
		$state['operations'] = $this->load_operations( $user_id );
		return $state;
	}

	/**
	 * Validates every taxonomy before changing either preview state.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws PlanValidationException When there is no valid plan.
	 */
	private function preview_all( int $user_id ): void {
		$operations = $this->load_operations( $user_id );
		$has_plan   = false;
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation ) {
				continue;
			}
			if ( Status::RUNNING->value === $operation['status'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			$plan = (array) ( $operation['requested_data']['plan'] ?? array() );
			if ( array() === $plan ) {
				continue;
			}
			$has_plan = true;
			if ( Status::DRAFT->value === $operation['status'] ) {
				$this->plans->preview( $taxonomy, $plan );
			} else {
				$this->execution->validate_start( (int) $operation['id'], $user_id, $taxonomy );
			}
		}
		if ( ! $has_plan ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null !== $operation && Status::DRAFT->value === $operation['status'] && array() !== (array) ( $operation['requested_data']['plan'] ?? array() ) ) {
				$this->workflow->preview( $user_id, $taxonomy );
			}
		}
	}

	/**
	 * Preflights both previews before the first batch starts.
	 *
	 * @param int $user_id Administrator ID.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When no preview exists.
	 * @throws ExecutionException When a preview cannot be accepted for execution.
	 */
	private function run_all( int $user_id ): array {
		$operations = $this->load_operations( $user_id );
		$start      = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation ) {
				continue;
			}
			try {
				$this->execution->validate_start( (int) $operation['id'], $user_id, $taxonomy );
			} catch ( ExecutionException $exception ) {
				if ( ExecutionErrorCode::NO_STARTABLE_ITEMS === $exception->error_code() ) {
					$this->execution->record_unstartable_failure( (int) $operation['id'], $user_id, $taxonomy, $exception );
				}
				throw $exception;
			}
			$start[ $taxonomy->value ] = (int) $operation['id'];
		}
		if ( array() === $start ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}

		$reservations = array();
		try {
			foreach ( self::TAXONOMIES as $taxonomy ) {
				$id = $start[ $taxonomy->value ] ?? 0;
				if ( 0 !== $id ) {
					$reservations[ $taxonomy->value ] = $this->execution->reserve_start( $id, $user_id, $taxonomy );
				}
			}
			return $this->run_ids( $user_id, $start, $reservations );
		} finally {
			foreach ( $reservations as $taxonomy => $token ) {
				$this->execution->release_reservation( $start[ $taxonomy ], $token );
			}
		}
	}

	/**
	 * Continues only owned, running operations named by the previous result.
	 *
	 * @param int                  $user_id Administrator ID.
	 * @param array<string, mixed> $request Verified request.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When the resume request is invalid.
	 */
	private function continue_all( int $user_id, array $request ): array {
		$ids = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$id = absint( ( (array) ( $request['operation_ids'] ?? array() ) )[ $taxonomy->value ] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}
			$operation = $this->operations->find( $id );
			if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] || ! in_array( $operation['status'], array( Status::RUNNING->value, Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			$ids[ $taxonomy->value ] = $id;
		}
		if ( array() === $ids ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		return $this->run_ids( $user_id, $ids );
	}

	/**
	 * Applies each operation through the existing bounded workflow.
	 *
	 * @param int                   $user_id      Administrator ID.
	 * @param array<string, int>    $ids          Taxonomy-keyed operation IDs.
	 * @param array<string, string> $reservations Optional preflight lock tokens.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When an operation is not owned by the administrator.
	 */
	private function run_ids( int $user_id, array $ids, array $reservations = array() ): array {
		$results = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$id = $ids[ $taxonomy->value ] ?? 0;
			if ( 0 === $id ) {
				continue;
			}
			$operation = $this->operations->find( $id );
			if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			if ( in_array( $operation['status'], array( Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
				$results[ $taxonomy->value ] = $operation;
				continue;
			}
			try {
				$results[ $taxonomy->value ] = $this->execution->run_batch( $id, $user_id, $taxonomy, $reservations[ $taxonomy->value ] ?? null );
			} catch ( ExecutionException $exception ) {
				$operation                   = $this->operations->find( $id ) ?? $operation;
				$operation['board_error']    = $exception->error_code();
				$results[ $taxonomy->value ] = $operation;
			} catch ( \Throwable $exception ) {
				$operation                   = $this->operations->find( $id ) ?? $operation;
				$operation['board_error']    = PlanErrorCode::UNKNOWN_ERROR;
				$results[ $taxonomy->value ] = $operation;
			}
		}
		return $results;
	}

	/**
	 * Loads only operations owned by the current administrator.
	 *
	 * @param int $user_id Administrator ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function load_operations( int $user_id ): array {
		$operations = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $this->execution->latest( $user_id, $taxonomy );
			if ( null === $operation ) {
				$operation = $this->workflow->current( $user_id, $taxonomy );
			}
			if ( null !== $operation && ( Status::DRAFT->value !== $operation['status'] || array() !== (array) ( $operation['requested_data']['plan'] ?? array() ) ) ) {
				$operations[ $taxonomy->value ] = $operation;
			}
		}
		return $operations;
	}

	/**
	 * Keeps progress in the modal only while work is running and reports terminal results inline.
	 *
	 * @param array<string, mixed> $state Board state.
	 */
	private function set_execution_feedback( array &$state ): void {
		$results     = (array) $state['results'];
		$interrupted = false;
		foreach ( $results as $result ) {
			$interrupted = $interrupted || isset( $result['board_error'] );
		}
		$state['modal'] = ! $interrupted && $this->has_running( $results );
		if ( $interrupted && $this->has_running( $results ) ) {
			$state['notice_type'] = 'warning';
			$state['notice']      = __( 'Processing was interrupted. Resume it from the operation plan.', 'term-steward' );
			return;
		}
		if ( $state['modal'] || array() === $results ) {
			return;
		}

		$failed    = false;
		$succeeded = false;
		foreach ( $results as $result ) {
			$failed    = $failed || isset( $result['board_error'] ) || in_array( $result['status'], array( Status::FAILED->value, Status::PARTIAL_FAILED->value ), true );
			$succeeded = $succeeded || in_array( $result['status'], array( Status::COMPLETED->value, Status::PARTIAL_FAILED->value ), true );
		}
		$state['notice_type'] = $failed ? 'warning' : 'success';
		$state['notice']      = $failed
			? ( $succeeded ? __( 'Some items failed. Check the operation history.', 'term-steward' ) : __( 'Processing failed. Check the operation history.', 'term-steward' ) )
			: __( 'Processing completed.', 'term-steward' );
	}

	/**
	 * Refuses plan changes while a previous batch is unfinished.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws PlanValidationException When a batch is running.
	 */
	private function require_no_running( int $user_id ): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( null !== $this->execution->latest( $user_id, $taxonomy ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
		}
	}

	/**
	 * Accepts only a supported taxonomy for an item operation.
	 *
	 * @param array<string, mixed> $request Verified request.
	 * @throws PlanValidationException When invalid.
	 */
	private function requested_taxonomy( array $request ): Taxonomy {
		$value    = is_string( $request['taxonomy'] ?? null ) ? sanitize_key( $request['taxonomy'] ) : '';
		$taxonomy = Taxonomy::tryFrom( $value );
		if ( null === $taxonomy ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
		}
		return $taxonomy;
	}

	/**
	 * Rejects deletion outside the current owned draft or against a changed plan.
	 *
	 * @param int                  $user_id  Administrator ID.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 * @param array<string, mixed> $request  Verified request.
	 * @throws PlanValidationException When the draft or item identity changed.
	 */
	private function verify_draft_item( int $user_id, Taxonomy $taxonomy, array $request ): void {
		$operation = $this->workflow->current( $user_id, $taxonomy );
		$posted_id = is_string( $request['operation_id'] ?? null ) ? $request['operation_id'] : '';
		$posted    = is_string( $request['expected_plan_hash'] ?? null ) ? sanitize_text_field( $request['expected_plan_hash'] ) : '';
		if ( null === $operation || Status::DRAFT->value !== $operation['status'] || ! ctype_digit( $posted_id ) || (int) $operation['id'] !== (int) $posted_id || '' === $posted || ! hash_equals( $this->plans->plan_hash( (array) ( $operation['requested_data']['plan'] ?? array() ) ), $posted ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
	}

	/**
	 * Reads a nonnegative item index from a verified request.
	 *
	 * @param array<string, mixed> $request Verified request.
	 * @throws PlanValidationException When the index is malformed.
	 */
	private function item_index( array $request ): int {
		$value = $request['item_index'] ?? null;
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		return (int) $value;
	}

	/**
	 * Renders the shared plan and one combined preview/result dialog.
	 *
	 * @param array<string, mixed> $state Controller state.
	 */
	public function render( array $state ): void {
		$operations = (array) $state['operations'];
		$count      = 0;
		foreach ( $operations as $operation ) {
			$count += count( (array) ( $operation['requested_data']['plan'] ?? array() ) );
		}
		?>
		<div id="term-steward-board-content">
		<h2><?php echo esc_html__( 'Operation plan', 'term-steward' ); ?></h2>
		<?php if ( array() !== $state['errors'] ) : ?>
			<div class="notice notice-error inline term-steward-plan-notice" role="alert"><p><?php echo esc_html( array() !== (array) ( $state['error_messages'] ?? array() ) ? implode( ' ', $state['error_messages'] ) : implode( ' ', array_map( array( $this, 'error_label' ), $state['errors'] ) ) ); ?></p></div>
		<?php elseif ( is_string( $state['notice'] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( 'warning' === ( $state['notice_type'] ?? 'success' ) ? 'warning' : 'success' ); ?> inline term-steward-plan-notice" role="status"><p><?php echo esc_html( $state['notice'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( array() !== (array) $state['results'] && ! $state['modal'] ) : ?>
			<section class="term-steward-execution-summary" aria-live="polite" aria-label="<?php echo esc_attr__( 'Final execution totals', 'term-steward' ); ?>">
				<?php $this->render_progress( (array) $state['results'] ); ?>
			</section>
		<?php endif; ?>
		<?php if ( 0 === $count ) : ?>
			<p><?php echo esc_html__( 'There is no operation plan yet.', 'term-steward' ); ?><br><?php echo esc_html__( 'Select categories or tags and add them from the action panel.', 'term-steward' ); ?></p>
			<?php if ( $state['modal'] ) : ?>
				<form id="term-steward-board-form" method="post">
					<input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>">
					<input type="hidden" name="view" value="plan">
				</form>
			<?php endif; ?>
		<?php else : ?>
			<?php foreach ( self::TAXONOMIES as $taxonomy ) : ?>
				<?php $operation = $operations[ $taxonomy->value ] ?? null; ?>
				<?php
				if ( null === $operation || array() === (array) ( $operation['requested_data']['plan'] ?? array() ) ) {
					continue;
				}
				$assessment = null;
				if ( Status::DRAFT->value === $operation['status'] ) {
					try {
						$assessment = $this->plans->preview( $taxonomy, (array) $operation['requested_data']['plan'] );
					} catch ( \Throwable $exception ) {
						$assessment = null;
					}
				} elseif ( Status::PREVIEWED->value === $operation['status'] ) {
					$assessment = $operation['requested_data']['preview'] ?? null;
				}
				?>
				<h3><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></h3>
			<div class="term-steward-table-scroll" tabindex="0" role="region" aria-label="<?php /* translators: %s: taxonomy label. */ echo esc_attr( sprintf( __( '%s operation plan', 'term-steward' ), $this->taxonomy_label( $taxonomy ) ) ); ?>">
			<table class="wp-list-table widefat fixed striped term-steward-plan-table"><thead><tr><th scope="col"><?php echo esc_html__( 'Action', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Type', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Target', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Changes', 'term-steward' ); ?></th><th scope="col"><?php echo esc_html__( 'Status', 'term-steward' ); ?></th><th scope="col" class="term-steward-plan-table__delete"><span class="screen-reader-text"><?php echo esc_html__( 'Delete', 'term-steward' ); ?></span></th></tr></thead><tbody>
				<?php foreach ( (array) $operation['requested_data']['plan'] as $index => $item ) : ?>
					<?php
					$warnings     = is_array( $assessment ) ? (array) ( $assessment['items'][ $index ]['warnings'] ?? array() ) : array();
					$status_label = Status::RUNNING->value === $operation['status']
						? __( 'Processing', 'term-steward' )
						: ( null === $assessment || false === ( $operation['preview_current'] ?? true )
							? __( 'Has errors', 'term-steward' )
							: ( array() === $warnings ? __( 'Ready', 'term-steward' ) : __( 'Has warnings', 'term-steward' ) ) );
					?>
					<tr><td><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></td><td><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></td><td><?php echo esc_html( $this->source_names( $item ) ); ?></td><td><?php echo esc_html( $this->change_label( $item ) ); ?></td><td><?php echo esc_html( $status_label ); ?></td><td class="term-steward-plan-table__delete">
					<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
						<form method="post"><input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>"><input type="hidden" name="view" value="plan"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>"><input type="hidden" name="operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><input type="hidden" name="item_index" value="<?php echo esc_attr( (string) $index ); ?>"><input type="hidden" name="expected_plan_hash" value="<?php echo esc_attr( $this->plans->plan_hash( (array) $operation['requested_data']['plan'] ) ); ?>"><button class="button-link-delete term-steward-plan-delete" type="submit" name="plan_command" value="remove_item" aria-label="<?php /* translators: 1: taxonomy, 2: target term names, 3: action name. */ echo esc_attr( sprintf( __( 'Remove %3$s for %1$s "%2$s" from the operation plan', 'term-steward' ), $this->taxonomy_label( $taxonomy ), $this->source_names( $item ), $this->action_label( (string) $item['action'] ) ) ); ?>"><?php echo esc_html__( 'Delete', 'term-steward' ); ?></button></form>
					<?php endif; ?>
				</td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<?php endforeach; ?>
			<form id="term-steward-board-form" method="post">
				<input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>">
				<input type="hidden" name="view" value="plan">
				<div class="term-steward-board-actions"><button type="submit" class="button-link-delete term-steward-discard" name="plan_command" value="discard_all" data-confirm="<?php echo esc_attr__( 'Discard all operation plans being edited?', 'term-steward' ); ?>" <?php disabled( $this->has_running( $operations ) ); ?>><?php echo esc_html__( 'Discard all plans', 'term-steward' ); ?></button><input type="hidden" name="confirmed" value="0">
				<?php if ( $this->has_running( $operations ) ) : ?>
					<?php
					foreach ( $operations as $taxonomy => $operation ) :
						?>
						<input type="hidden" name="operation_ids[<?php echo esc_attr( $taxonomy ); ?>]" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><?php endforeach; ?>
					<button type="submit" class="button button-primary tt-button tt-button--primary" name="plan_command" value="continue_all"><?php echo esc_html__( 'Resume processing', 'term-steward' ); ?></button>
				<?php else : ?>
					<button type="submit" class="button button-primary tt-button tt-button--primary" name="plan_command" value="preview_all"><?php echo esc_html__( 'Review changes', 'term-steward' ); ?></button>
				<?php endif; ?></div>
			</form>
		<?php endif; ?>
		</div>
		<?php if ( $state['modal'] ) : ?>
			<?php $this->render_modal( $state ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the preview or progress dialog.
	 *
	 * @param array<string, mixed> $state Board state.
	 */
	private function render_modal( array $state ): void {
		$results = (array) $state['results'];
		$active  = array() !== $results;
		?>
		<div class="term-steward term-steward-modal term-steward-board-modal" data-auto-open="1" data-running="<?php echo $this->has_running( $results ) ? '1' : '0'; ?>" hidden>
			<div class="term-steward-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="term-steward-preview-heading" tabindex="-1">
				<header class="term-steward-modal__header"><h2 id="term-steward-preview-heading"><?php echo esc_html( $active ? __( 'Execution result', 'term-steward' ) : __( 'Change preview', 'term-steward' ) ); ?></h2><button type="button" class="term-steward-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'term-steward' ); ?>" <?php disabled( $this->has_running( $results ) ); ?>>&times;</button></header>
				<div class="term-steward-modal__body" aria-live="polite">
					<?php if ( $active ) : ?>
						<?php $this->render_results( $results ); ?>
					<?php else : ?>
						<?php $this->render_previews( (array) $state['operations'] ); ?>
					<?php endif; ?>
				</div>
				<footer class="term-steward-modal__footer"><button type="button" class="button tt-button tt-button--secondary term-steward-modal__cancel" <?php disabled( $this->has_running( $results ) ); ?>><?php echo esc_html__( 'Cancel', 'term-steward' ); ?></button>
				<?php if ( $active ) : ?>
					<?php
					foreach ( $results as $taxonomy => $operation ) :
						?>
						<input type="hidden" form="term-steward-board-form" name="operation_ids[<?php echo esc_attr( $taxonomy ); ?>]" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><?php endforeach; ?>
				<?php else : ?>
					<button type="submit" form="term-steward-board-form" class="button button-primary tt-button tt-button--primary" name="plan_command" value="run_all" <?php disabled( array() !== $state['errors'] || $this->has_draft( (array) $state['operations'] ) || $this->has_running( (array) $state['operations'] ) || $this->has_stale_preview( (array) $state['operations'] ) ); ?>><?php echo esc_html__( 'Execute', 'term-steward' ); ?></button>
				<?php endif; ?>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the two previews in taxonomy groups.
	 *
	 * @param array<string, array<string, mixed>> $operations Previewed operations.
	 */
	private function render_previews( array $operations ): void {
		$count = 0;
		foreach ( $operations as $operation ) {
			if ( Status::PREVIEWED->value === $operation['status'] ) {
				foreach ( (array) ( $operation['requested_data']['preview']['items'] ?? array() ) as $item ) {
					$count += Action::DELETE->value === ( $item['action'] ?? '' ) ? count( (array) ( $item['sources'] ?? array() ) ) : 1;
				}
			}
		}
		?>
		<p><?php /* translators: %d: number of planned actions. */ echo esc_html( sprintf( __( 'Actions: %d', 'term-steward' ), $count ) ); ?></p>
		<?php
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation || Status::PREVIEWED->value !== $operation['status'] ) {
				continue;
			}
			$preview = $operation['requested_data']['preview'] ?? null;
			if ( ! is_array( $preview ) ) {
				continue;
			}
			$delete_sources = array();
			foreach ( (array) ( $preview['items'] ?? array() ) as $item ) {
				if ( Action::DELETE->value === ( $item['action'] ?? '' ) ) {
					$delete_sources = array_merge( $delete_sources, (array) ( $item['sources'] ?? array() ) );
				}
			}
			?>
			<h3><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></h3>
			<?php if ( array() !== $delete_sources ) : ?>
				<article class="term-steward-preview-item term-steward-preview-delete"><h4><?php /* translators: %s: taxonomy label. */ echo esc_html( sprintf( __( 'Delete %s', 'term-steward' ), $this->taxonomy_label( $taxonomy ) ) ); ?></h4><p><?php /* translators: %d: number of terms to delete. */ echo esc_html( sprintf( __( 'Terms to delete: %d', 'term-steward' ), count( $delete_sources ) ) ); ?></p><ul class="term-steward-preview-delete__targets">
				<?php foreach ( $delete_sources as $source ) : ?>
					<li><?php echo esc_html( (string) ( $source['name'] ?? '' ) ); ?></li>
				<?php endforeach; ?>
				</ul></article>
			<?php endif; ?>
			<?php foreach ( (array) ( $preview['items'] ?? array() ) as $index => $item ) : ?>
				<?php if ( Action::DELETE->value === ( $item['action'] ?? '' ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<article class="term-steward-preview-item"><h4><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h4><p><?php echo esc_html( implode( '、', array_column( (array) $item['sources'], 'name' ) ) ); ?> → <?php echo esc_html( $this->change_label( $item ) ); ?></p><p><?php /* translators: %d: affected published post count. */ echo esc_html( sprintf( __( 'Affected published posts: %d', 'term-steward' ), count( (array) $item['affected_posts'] ) ) ); ?></p>
				<?php
				foreach ( (array) $item['sources'] as $source ) :
					?>
					<?php
					if ( Action::RENAME->value !== $item['action'] ) :
						?>
					<p><?php echo esc_html( (string) $source['name'] ); ?>：<?php echo esc_html( $source['delete_source'] ? __( 'Delete after processing', 'term-steward' ) : __( 'Retain without deleting', 'term-steward' ) ); ?></p><?php endif; ?><?php endforeach; ?>
				<?php
				if ( array() !== (array) $item['warnings'] ) :
					?>
					<p class="term-steward-message term-steward-message--warning"><?php echo esc_html( implode( ' ', array_map( array( $this, 'warning_label' ), $item['warnings'] ) ) ); ?></p><?php endif; ?>
				<?php
				if ( array() !== (array) $item['affected_posts'] ) :
					?>
					<details class="term-steward-preview-posts" data-taxonomy="<?php echo esc_attr( $taxonomy->value ); ?>" data-operation="<?php echo esc_attr( (string) $operation['id'] ); ?>" data-item="<?php echo esc_attr( (string) $index ); ?>" data-error="<?php echo esc_attr__( 'Could not retrieve the target posts.', 'term-steward' ); ?>"><summary><?php echo esc_html__( 'Review target posts', 'term-steward' ); ?></summary><ul></ul></details><?php endif; ?>
				</article>
			<?php endforeach; ?>
			<?php
		}
	}

	/**
	 * Renders separate results and the combined outcome.
	 *
	 * @param array<string, array<string, mixed>> $results Individual operation results.
	 */
	private function render_results( array $results ): void {
		$failed    = false;
		$succeeded = false;
		foreach ( $results as $result ) {
			$failed    = $failed || isset( $result['board_error'] ) || in_array( $result['status'], array( Status::FAILED->value, Status::PARTIAL_FAILED->value ), true );
			$succeeded = $succeeded || in_array( $result['status'], array( Status::COMPLETED->value, Status::PARTIAL_FAILED->value ), true );
		}
		if ( $failed ) {
			?>
			<p class="term-steward-message term-steward-message--warning"><?php echo esc_html( $succeeded ? __( 'Some items failed. Successful changes have been retained.', 'term-steward' ) : __( 'Processing could not be completed.', 'term-steward' ) ); ?></p>
			<?php
		}
		$this->render_progress( $results );
	}

	/**
	 * Renders persisted item-state counts for running and terminal results.
	 *
	 * The invariant is total = completed + pending + failed + skipped.
	 *
	 * @param array<string, array<string, mixed>> $results Individual operation results.
	 */
	private function render_progress( array $results ): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$result = $results[ $taxonomy->value ] ?? null;
			if ( null === $result ) {
				continue;
			}
			$status = (string) $result['status'];
			$label  = match ( $status ) {
				Status::COMPLETED->value => __( 'Completed', 'term-steward' ),
				Status::RUNNING->value => __( 'Processing', 'term-steward' ),
				Status::PARTIAL_FAILED->value => __( 'Partially failed', 'term-steward' ),
				default => __( 'Failed', 'term-steward' ),
			};
			?>
			<p><strong><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?>：</strong><?php echo esc_html( $label ); ?>
			<?php
			if ( isset( $result['board_error'] ) ) :
				?>
				— <?php echo esc_html( ErrorMessages::label( (string) $result['board_error'] ) ); ?><?php endif; ?></p>
			<?php if ( is_array( $result['progress'] ?? null ) ) : ?>
				<p><?php /* translators: 1: total, 2: completed, 3: pending, 4: failed, 5: skipped, 6: current status. */ echo esc_html( sprintf( __( 'Total: %1$d; completed: %2$d; pending: %3$d; failed: %4$d; skipped: %5$d; current status: %6$s', 'term-steward' ), (int) $result['progress']['total'], (int) $result['progress']['completed'], (int) $result['progress']['pending'], (int) $result['progress']['failed'], (int) $result['progress']['skipped'], $label ) ); ?></p>
			<?php endif; ?>
			<?php
		}
	}

	/**
	 * Checks a status in the currently visible operations.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_draft( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::DRAFT->value === $operation['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks a status in the currently visible operations.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_running( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::RUNNING->value === $operation['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether an existing preview no longer matches current term state.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_stale_preview( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::PREVIEWED->value === $operation['status'] && false === ( $operation['preview_current'] ?? true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns or renders taxonomy-specific labels and destinations.
	 *
	 * @param Taxonomy $taxonomy Supported taxonomy.
	 */
	private function taxonomy_label( Taxonomy $taxonomy ): string {
		return Taxonomy::CATEGORY === $taxonomy ? __( 'Category', 'term-steward' ) : __( 'Tag', 'term-steward' );
	}

	/**
	 * Returns the localized action name.
	 *
	 * @param string $action Stable action name.
	 */
	private function action_label( string $action ): string {
		return match ( $action ) {
			Action::RENAME->value => __( 'Rename', 'term-steward' ),
			Action::MERGE->value => __( 'Merge', 'term-steward' ),
			default => __( 'Delete', 'term-steward' ),
		};
	}

	/**
	 * Returns a readable summary for one item.
	 *
	 * @param array<string, mixed> $item Stored plan or preview item.
	 */
	private function source_names( array $item ): string {
		$names = array();
		foreach ( (array) ( $item['sources'] ?? array() ) as $source ) {
			if ( isset( $source['name'] ) ) {
				$names[] = (string) $source['name'];
			} else {
				$term    = get_term( (int) ( $source['term_id'] ?? 0 ) );
				$names[] = $term instanceof WP_Term ? $term->name : __( 'Missing term', 'term-steward' );
			}
		}
		return implode( '、', $names );
	}

	/**
	 * Returns a readable summary for one item.
	 *
	 * @param array<string, mixed> $item Stored plan or preview item.
	 */
	private function change_label( array $item ): string {
		if ( Action::RENAME->value === $item['action'] ) {
			$label = (string) ( $item['new_name'] ?? '' );
			return null === ( $item['new_slug'] ?? null ) ? $label : $label . ' / ' . (string) $item['new_slug'];
		}
		if ( Action::MERGE->value === $item['action'] ) {
			$destination = $item['destination'] ?? null;
			if ( is_array( $destination ) && isset( $destination['name'] ) ) {
				return (string) $destination['name'];
			}
			$term = is_array( $destination ) ? get_term( (int) ( $destination['term_id'] ?? 0 ) ) : null;
			return $term instanceof WP_Term ? $term->name : __( 'Missing term', 'term-steward' );
		}
		return __( 'Delete term', 'term-steward' );
	}

	/**
	 * Returns a localized preview warning.
	 *
	 * @param string $warning Stable preview warning.
	 */
	private function warning_label( string $warning ): string {
		return match ( $warning ) {
			'used_by_excluded_objects' => __( 'The term will be retained because it is used by excluded posts.', 'term-steward' ),
			'has_child_categories' => __( 'The term will be retained because it has child categories.', 'term-steward' ),
			default => __( 'Review is required before processing.', 'term-steward' ),
		};
	}

	/**
	 * Returns Japanese messages for invalid board requests.
	 *
	 * @param string $code Stable internal error code.
	 */
	private function error_label( string $code ): string {
		return match ( $code ) {
			PlanErrorCode::PLAN_INVALID => __( 'The operation plan changed or cannot be run in its current state. Refresh the page and review it.', 'term-steward' ),
			PlanErrorCode::PERMISSION_DENIED => __( 'You are not allowed to perform this action.', 'term-steward' ),
			PlanErrorCode::INVALID_NONCE => __( 'This action has expired. Refresh the page and try again.', 'term-steward' ),
			PlanErrorCode::TAXONOMY_MISMATCH => __( 'The target category or tag is invalid.', 'term-steward' ),
			PlanErrorCode::UNKNOWN_ERROR => __( 'The operation plan could not be processed. Try again.', 'term-steward' ),
			default => ErrorMessages::label( $code ),
		};
	}

	/**
	 * Returns a target-specific delete preflight error without technical details.
	 *
	 * @param ExecutionException $exception Coded failure with safe target context.
	 */
	private function delete_start_error( ExecutionException $exception ): string {
		$target = '' !== (string) $exception->target_name() ? (string) $exception->target_name() : __( 'Target term', 'term-steward' );
		$reason = match ( $exception->reason() ) {
			'relationships_added' => __( 'It is currently used by another object.', 'term-steward' ),
			'term_missing' => __( 'It has already been deleted or cannot be found.', 'term-steward' ),
			'taxonomy_changed' => __( 'The taxonomy does not match the operation plan.', 'term-steward' ),
			'duplicate_target' => __( 'It was added to the operation plan more than once.', 'term-steward' ),
			default => __( 'The current usage could not be verified.', 'term-steward' ),
		};
		/* translators: 1: deletion target name, 2: reason deletion cannot start. */
		$message = sprintf( __( 'Deletion could not start. "%1$s" %2$s', 'term-steward' ), $target, $reason );
		return ExecutionErrorCode::NO_STARTABLE_ITEMS === $exception->error_code()
			? $message . ' ' . __( 'No target can be processed, so this operation ended as failed. Recreate the plan and review the changes again.', 'term-steward' )
			: $message;
	}
}
