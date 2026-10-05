<?php
/**
 * Term Steward admin page.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Admin;

use TermSteward\Application\Planning\PlanService;
use TermSteward\Application\Planning\PlanWorkflow;
use TermSteward\Application\Execution\ExecutionWorkflow;
use TermSteward\Application\Execution\ItemExecutor;
use TermSteward\Application\Undo\UndoItemExecutor;
use TermSteward\Application\Undo\UndoErrorCode;
use TermSteward\Application\Undo\UndoPlanner;
use TermSteward\Application\Undo\UndoWorkflow;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use TermSteward\Infrastructure\Persistence\OperationItemRepository;
use TermSteward\Infrastructure\Persistence\OperationLock;
use TermSteward\Infrastructure\Persistence\ChangeJournalRepository;
use TermSteward\Infrastructure\Persistence\DatabaseTransaction;
use TermSteward\Infrastructure\Taxonomy\TermInventoryQuery;

/**
 * Registers and renders the read-only taxonomy inventory screen.
 */
final class Page {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'term-steward';

	/**
	 * Adds the page below the Tools menu for authorized users.
	 */
	public function register_menu(): void {
		if ( ! Access::current_user_can_access() ) {
			return;
		}

		add_management_page(
			esc_html__( 'Term Steward', 'term-steward' ),
			esc_html__( 'Term Steward', 'term-steward' ),
			'manage_categories',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the Phase 3 screen styles only on the Term Steward admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'term-steward-admin',
			plugins_url( 'assets/css/admin.css', TERM_STEWARD_PLUGIN_FILE ),
			array(),
			TERM_STEWARD_VERSION
		);
		wp_enqueue_script(
			'term-steward-admin',
			plugins_url( 'assets/js/admin.js', TERM_STEWARD_PLUGIN_FILE ),
			array(),
			TERM_STEWARD_VERSION,
			true
		);
		wp_enqueue_script(
			'term-steward-plan-board',
			plugins_url( 'assets/js/plan-board.js', TERM_STEWARD_PLUGIN_FILE ),
			array(),
			TERM_STEWARD_VERSION,
			true
		);
		wp_enqueue_script(
			'term-steward-history',
			plugins_url( 'assets/js/history.js', TERM_STEWARD_PLUGIN_FILE ),
			array(),
			TERM_STEWARD_VERSION,
			true
		);
		wp_localize_script(
			'term-steward-history',
			'termStewardHistory',
			array(
				'interrupted'     => __( 'Undo was interrupted. You can resume it from operation history.', 'term-steward' ),
				'cannotContinue'  => __( 'Undo could not continue.', 'term-steward' ),
				'progressStopped' => __( 'Undo was interrupted because server progress could not be verified. Resume it from operation history.', 'term-steward' ),
				'resultTitle'     => __( 'Undo result', 'term-steward' ),
				'stoppedTitle'    => __( 'Undo interrupted', 'term-steward' ),
				'closeResult'     => __( 'Close', 'term-steward' ),
				'showDetails'     => __( 'View details', 'term-steward' ),
				'collapse'        => __( 'Close', 'term-steward' ),
				'success'         => __( 'Success: ', 'term-steward' ),
				'warning'         => __( 'Warning: ', 'term-steward' ),
				'failure'         => __( 'Failed: ', 'term-steward' ),
				// translators: 1: processed count, 2: total count, 3: succeeded count, 4: failed count, 5: remaining count.
				'progress'        => __( 'Progress: %1$d / %2$d; successful: %3$d; failed: %4$d; remaining: %5$d', 'term-steward' ),
			)
		);
	}

	/**
	 * Loads preview post titles only when the administrator expands the list.
	 */
	public function preview_posts(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'term-steward' ) ), 403 );
		}
		check_ajax_referer( PlanController::NONCE_ACTION, PlanController::NONCE_FIELD );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$request      = wp_unslash( $_POST );
		$taxonomy     = Taxonomy::tryFrom( sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) );
		$operation_id = absint( $request['operation_id'] ?? 0 );
		$item_index   = isset( $request['item_index'] ) && is_scalar( $request['item_index'] ) && ctype_digit( (string) $request['item_index'] ) ? (int) $request['item_index'] : -1;
		if ( null === $taxonomy || 0 === $operation_id || 0 > $item_index ) {
			wp_send_json_error( array( 'message' => __( 'The preview could not be verified.', 'term-steward' ) ), 400 );
		}
		$operation = ( new OperationRepository( $GLOBALS['wpdb'] ) )->find( $operation_id );
		if ( null === $operation || get_current_user_id() !== (int) $operation['user_id'] || $operation['taxonomy'] !== $taxonomy->value || 'previewed' !== $operation['status'] ) {
			wp_send_json_error( array( 'message' => __( 'The preview could not be verified.', 'term-steward' ) ), 403 );
		}
		$posts = $operation['requested_data']['preview']['items'][ $item_index ]['affected_posts'] ?? null;
		if ( ! is_array( $posts ) ) {
			wp_send_json_error( array( 'message' => __( 'The target posts could not be verified.', 'term-steward' ) ), 400 );
		}
		wp_send_json_success( array( 'titles' => array_values( array_map( static fn( array $post ): string => (string) $post['title'], $posts ) ) ) );
	}

	/** Processes exactly one bounded Undo batch for the browser-side continuation loop. */
	public function undo_batch(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_send_json_error(
				array(
					'message'   => __( 'Access denied.', 'term-steward' ),
					'retryable' => false,
				),
				403
			);
		}
		// Return a client-safe JSON error instead of WordPress' bare "-1" response,
		// so an expired session can be distinguished from a network failure.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read and verified immediately below.
		$nonce = is_string( $_POST[ HistoryPage::NONCE_FIELD ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ HistoryPage::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, HistoryPage::NONCE_ACTION ) ) {
			wp_send_json_error(
				array(
					'message'   => __( 'Your session or credentials are no longer valid. Reload the page, sign in again if needed, and resume the operation.', 'term-steward' ),
					'retryable' => false,
				),
				403
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$request  = wp_unslash( $_POST );
		$undo_id  = absint( $request['undo_operation_id'] ?? 0 );
		$taxonomy = Taxonomy::tryFrom( sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) );
		if ( 0 === $undo_id || null === $taxonomy ) {
			wp_send_json_error(
				array(
					'message'   => __( 'The undo process could not be verified.', 'term-steward' ),
					'retryable' => false,
				),
				400
			);
		}
		try {
			wp_send_json_success( $this->history_service()->continue_undo( $undo_id, get_current_user_id(), $taxonomy ) );
		} catch ( \TermSteward\Application\Undo\UndoException $exception ) {
			$status = in_array( $exception->error_code(), array( UndoErrorCode::STALE_PREVIEW, UndoErrorCode::LOCKED, UndoErrorCode::IN_PROGRESS, UndoErrorCode::ALREADY_UNDONE, UndoErrorCode::NOT_RESUMABLE, UndoErrorCode::DUPLICATE ), true ) ? 409 : 400;
			wp_send_json_error(
				array(
					'message'   => $this->undo_error_message( $exception->error_code() ),
					'retryable' => false,
				),
				$status
			);
		} catch ( \Throwable $exception ) {
			$this->log_ajax_error( $exception );
			wp_send_json_error(
				array(
					'message'   => __( 'Undo was interrupted. You can resume it from operation history.', 'term-steward' ),
					'retryable' => false,
				),
				500
			);
		}
	}

	/** Returns one bounded, owner-scoped page of human-readable journal logs. */
	public function history_logs(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'term-steward' ) ), 403 );
		}
		check_ajax_referer( HistoryPage::NONCE_ACTION, HistoryPage::NONCE_FIELD );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$request      = wp_unslash( $_POST );
		$operation_id = absint( $request['operation_id'] ?? 0 );
		$page_value   = is_scalar( $request['log_page'] ?? null ) ? sanitize_text_field( (string) $request['log_page'] ) : '';
		$page         = ctype_digit( $page_value ) && 0 < (int) $page_value ? (int) $page_value : 1;
		try {
			wp_send_json_success( $this->history_service()->history_logs( $operation_id, get_current_user_id(), $page ) );
		} catch ( \TermSteward\Application\Undo\UndoException $exception ) {
			wp_send_json_error( array( 'message' => __( 'The logs could not be verified.', 'term-steward' ) ), 403 );
		} catch ( \Throwable $exception ) {
			$this->log_ajax_error( $exception );
			wp_send_json_error( array( 'message' => __( 'Could not retrieve the logs. Try again.', 'term-steward' ) ), 500 );
		}
	}

	/** Creates the history service with the existing repositories and safe workflow. */
	private function history_service(): HistoryPage {
		global $wpdb;
		$operations = new OperationRepository( $wpdb );
		$items      = new OperationItemRepository( $wpdb );
		$journal    = new ChangeJournalRepository( $wpdb );
		$planner    = new UndoPlanner( $operations, $items, $journal, new OperationLock( $wpdb ) );
		$workflow   = new UndoWorkflow( $operations, $items, new OperationLock( $wpdb ), $planner, new UndoItemExecutor( $journal ), new DatabaseTransaction( $wpdb ) );
		return new HistoryPage( $operations, $items, $journal, $planner, $workflow );
	}

	/**
	 * Maps server stop conditions to safe Japanese messages.
	 *
	 * @param string $code Stable Undo error code.
	 */
	private function undo_error_message( string $code ): string {
		return match ( $code ) {
			UndoErrorCode::LOCKED => __( 'Another operation is running. Check its status in operation history.', 'term-steward' ),
			UndoErrorCode::STALE_PREVIEW => __( 'The state changed after the preview, so undo did not start. Review it again.', 'term-steward' ),
			UndoErrorCode::IN_PROGRESS => __( 'Undo is already running. Check its status in operation history.', 'term-steward' ),
			UndoErrorCode::ALREADY_UNDONE => __( 'This operation has already been undone.', 'term-steward' ),
			UndoErrorCode::NOT_RESUMABLE => __( 'This undo process is complete and cannot be resumed. Check the result in operation history.', 'term-steward' ),
			UndoErrorCode::DUPLICATE => __( 'Multiple undo records were detected, so processing did not start.', 'term-steward' ),
			default => __( 'Undo could not continue. Check its status in operation history.', 'term-steward' ),
		};
	}

	/**
	 * Writes internal Ajax failures only to the configured debug log.
	 *
	 * @param \Throwable $exception Internal failure.
	 */
	private function log_ajax_error( \Throwable $exception ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal details are not returned to the browser.
			error_log( sprintf( 'Term Steward Ajax error: %s: %s', $exception::class, $exception->getMessage() ) );
		}
	}

	/**
	 * Renders the taxonomy inventory after repeating the full access check.
	 */
	public function render(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_die(
				esc_html__( 'You are not allowed to access Term Steward.', 'term-steward' ),
				esc_html__( 'Access denied', 'term-steward' ),
				array( 'response' => 403 )
			);
		}

		global $wpdb;
		$operations = new OperationRepository( $wpdb );
		$items      = new OperationItemRepository( $wpdb );
		$plans      = new PlanService();
		$workflow   = new PlanWorkflow( $operations, $plans );
		$execution  = new ExecutionWorkflow(
			$operations,
			$items,
			new OperationLock( $wpdb ),
			$plans,
			new ItemExecutor( new ChangeJournalRepository( $wpdb ) ),
			new DatabaseTransaction( $wpdb )
		);
		$journal    = new ChangeJournalRepository( $wpdb );
		$board      = new PlanBoard( $operations, $workflow, $plans, $execution );
		$view       = $this->request_string( 'view' );
		if ( 'plan' === $view || 'history' === $view ) {
			$board_state = 'plan' === $view ? $board->handle() : null;
			?>
			<div class="wrap term-steward term-steward-screen">
				<h1><?php echo esc_html__( 'Term Steward', 'term-steward' ); ?></h1>
				<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Views', 'term-steward' ); ?>">
					<?php $this->render_tab( Taxonomy::CATEGORY, null, __( 'Category', 'term-steward' ) ); ?>
					<?php $this->render_tab( Taxonomy::POST_TAG, null, __( 'Tag', 'term-steward' ) ); ?>
					<?php $this->render_aux_tab( 'plan', $view, __( 'Operation plan', 'term-steward' ), $board->draft_count( get_current_user_id() ) ); ?>
					<?php $this->render_aux_tab( 'history', $view, __( 'Operation history', 'term-steward' ) ); ?>
				</nav>
				<?php if ( null !== $board_state ) : ?>
					<?php $board->render( $board_state ); ?>
				<?php else : ?>
					<?php
					$undo_planner = new UndoPlanner( $operations, $items, $journal, new OperationLock( $wpdb ) );
					$undo         = new UndoWorkflow(
						$operations,
						$items,
						new OperationLock( $wpdb ),
						$undo_planner,
						new UndoItemExecutor( $journal ),
						new DatabaseTransaction( $wpdb )
					);
					( new HistoryPage( $operations, $items, $journal, $undo_planner, $undo ) )->render();
					?>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		$taxonomy       = Taxonomy::POST_TAG->value === $this->request_string( 'taxonomy' )
			? Taxonomy::POST_TAG
			: Taxonomy::CATEGORY;
		$search         = $this->request_string( 's' );
		$orderby        = 'published_count' === $this->request_string( 'orderby' ) ? 'published_count' : 'name';
		$order          = 'desc' === $this->request_string( 'order' ) ? 'desc' : 'asc';
		$page           = $this->requested_page();
		$per_page       = $this->requested_per_page();
		$unused         = '1' === $this->request_string( 'unused' );
		$query          = new TermInventoryQuery( $wpdb );
		$inventory      = $query->find( $taxonomy, $search, $page, $per_page, $orderby, $order, $unused );
		$taxonomy_label = Taxonomy::CATEGORY === $taxonomy
			? __( 'Categories', 'term-steward' )
			: __( 'Tags', 'term-steward' );
		$type_label     = Taxonomy::CATEGORY === $taxonomy
			? __( 'Category', 'term-steward' )
			: __( 'Tag', 'term-steward' );
		$conditions     = $this->active_conditions( $search, $orderby, $order, $unused );
		$plan_state     = ( new PlanController( $workflow, $execution ) )->handle( $taxonomy );
		?>
		<div class="wrap term-steward term-steward-screen">
			<h1><?php echo esc_html__( 'Term Steward', 'term-steward' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Taxonomy views', 'term-steward' ); ?>">
				<?php $this->render_tab( Taxonomy::CATEGORY, $taxonomy, __( 'Category', 'term-steward' ) ); ?>
				<?php $this->render_tab( Taxonomy::POST_TAG, $taxonomy, __( 'Tag', 'term-steward' ) ); ?>
				<?php $this->render_aux_tab( 'plan', '', __( 'Operation plan', 'term-steward' ), $board->draft_count( get_current_user_id() ) ); ?>
				<?php $this->render_aux_tab( 'history', '', __( 'Operation history', 'term-steward' ) ); ?>
			</nav>

			<h2><?php echo esc_html( $taxonomy_label ); ?></h2>

			<details class="term-steward-panel term-steward-filter-panel">
				<summary class="term-steward-panel__summary" aria-expanded="false">
					<span class="term-steward-panel__heading">
						<span class="term-steward-panel__icon" aria-hidden="true"></span>
						<span><?php echo esc_html__( 'Search panel', 'term-steward' ); ?></span>
					</span>
					<span class="term-steward-filter-summary">
						<span class="term-steward-filter-summary__count">
							<?php echo esc_html( $this->condition_count_label( count( $conditions ) ) ); ?>
						</span>
						<?php if ( array() !== $conditions ) : ?>
							<span class="term-steward-filter-summary__conditions" aria-label="<?php echo esc_attr__( 'Active conditions', 'term-steward' ); ?>">
								<?php foreach ( $conditions as $condition ) : ?>
									<span class="term-steward-filter-summary__condition"><?php echo esc_html( $condition ); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</span>
				</summary>

				<form class="term-steward-filter-form" method="get" aria-label="<?php echo esc_attr__( 'Search and filter terms', 'term-steward' ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
					<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>">

					<div class="term-steward-filter-form__groups">
						<section class="term-steward-filter-group term-steward-filter-group--keyword" aria-labelledby="term-steward-keyword-heading">
							<h3 id="term-steward-keyword-heading"><?php echo esc_html__( 'Find by keyword', 'term-steward' ); ?></h3>
							<label for="term-steward-search"><?php echo esc_html__( 'Keyword', 'term-steward' ); ?></label>
							<input class="tt-control" id="term-steward-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" aria-describedby="term-steward-search-description">
							<p id="term-steward-search-description" class="description">
								<?php echo esc_html__( 'Search by name or slug.', 'term-steward' ); ?>
							</p>
						</section>

						<section class="term-steward-filter-group term-steward-filter-group--scope" aria-labelledby="term-steward-scope-heading">
							<h3 id="term-steward-scope-heading"><?php echo esc_html__( 'Filter displayed terms', 'term-steward' ); ?></h3>
							<label class="term-steward-checkbox-label" for="term-steward-unused">
								<input id="term-steward-unused" type="checkbox" name="unused" value="1" <?php checked( $unused ); ?>>
								<span><?php echo esc_html__( 'Globally unused only', 'term-steward' ); ?></span>
							</label>
						</section>

						<section class="term-steward-filter-group term-steward-filter-group--sort" aria-labelledby="term-steward-sort-heading">
							<h3 id="term-steward-sort-heading"><?php echo esc_html__( 'Sort order', 'term-steward' ); ?></h3>
							<div class="term-steward-sort-fields">
								<div class="term-steward-field">
									<label for="term-steward-orderby"><?php echo esc_html__( 'Sort by', 'term-steward' ); ?></label>
									<select class="tt-control" id="term-steward-orderby" name="orderby">
										<option value="name" <?php selected( $orderby, 'name' ); ?>><?php echo esc_html__( 'Name', 'term-steward' ); ?></option>
										<option value="published_count" <?php selected( $orderby, 'published_count' ); ?>><?php echo esc_html__( 'Published posts', 'term-steward' ); ?></option>
									</select>
								</div>
								<div class="term-steward-field">
									<label for="term-steward-order"><?php echo esc_html__( 'Direction', 'term-steward' ); ?></label>
									<select class="tt-control" id="term-steward-order" name="order">
										<option value="asc" <?php selected( $order, 'asc' ); ?>><?php echo esc_html__( 'Ascending', 'term-steward' ); ?></option>
										<option value="desc" <?php selected( $order, 'desc' ); ?>><?php echo esc_html__( 'Descending', 'term-steward' ); ?></option>
									</select>
								</div>
							</div>
						</section>
					</div>

					<div class="term-steward-filter-actions">
						<button type="submit" class="button button-primary tt-button tt-button--primary">
							<?php echo esc_html__( 'Apply conditions', 'term-steward' ); ?>
						</button>
						<a class="button button-secondary tt-button tt-button--secondary" href="<?php echo esc_url( $this->reset_url( $taxonomy, $per_page ) ); ?>">
							<?php echo esc_html__( 'Reset conditions', 'term-steward' ); ?>
						</a>
					</div>
				</form>
			</details>

			<?php $this->render_page_size_forms( $taxonomy, $search, $orderby, $order, $unused ); ?>
			<?php
			( new PlanningPanel() )->render(
				$taxonomy,
				$type_label,
				$inventory,
				$plan_state,
				fn( string $position ) => $this->render_pagination( $position, $taxonomy, $search, $orderby, $order, $unused, $inventory ),
				fn( string $key, string $label ) => $this->render_sort_header( $key, $label, $taxonomy, $search, $orderby, $order, $unused, $per_page )
			);
			?>
		</div>
		<?php
	}

	/**
	 * Reads and sanitizes one read-only list parameter.
	 *
	 * @param string $key Query-string key.
	 */
	private function request_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed below, type-checked, and sanitized before use; no state changes occur.
		$value = wp_unslash( $_GET[ $key ] ?? '' );

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/** Reads only the three supported inventory page sizes. */
	private function requested_per_page(): int {
		$value = $this->request_string( 'per_page' );
		return in_array( $value, array( '20', '50', '100' ), true ) ? (int) $value : 20;
	}

	/** Reads a positive page number without converting negative values to positive ones. */
	private function requested_page(): int {
		$value = $this->request_string( 'paged' );
		return ctype_digit( $value ) && 0 < (int) $value ? (int) $value : 1;
	}

	/**
	 * Renders one category or tag navigation tab.
	 *
	 * @param Taxonomy $tab     Taxonomy represented by the tab.
	 * @param Taxonomy $current Currently displayed taxonomy.
	 * @param string   $label   Translated tab label.
	 */
	private function render_tab( Taxonomy $tab, ?Taxonomy $current, string $label ): void {
		$url   = add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $tab->value,
			),
			admin_url( 'tools.php' )
		);
		$class = $current === $tab ? ' nav-tab-active' : '';
		?>
		<a class="nav-tab<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $current === $tab ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
		</a>
		<?php
	}

	/**
	 * Renders the shared plan or future history tab.
	 *
	 * @param string   $tab     Tab key.
	 * @param string   $current Current tab key.
	 * @param string   $label   Localized label.
	 * @param int|null $count   Current administrator's draft-item count.
	 */
	private function render_aux_tab( string $tab, string $current, string $label, ?int $count = null ): void {
		$url        = add_query_arg(
			array(
				'page' => self::SLUG,
				'view' => $tab,
			),
			admin_url( 'tools.php' )
		);
		$accessible = null === $count ? $label : sprintf(
			/* translators: 1: tab name, 2: number of draft items. */
			__( '%1$s (%2$d)', 'term-steward' ),
			$label,
			$count
		);
		?>
		<a class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $accessible ); ?>" <?php echo $tab === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?>
		<?php
		if ( null !== $count && 0 < $count ) :
			?>
			<span class="term-steward-tab-count"><?php echo esc_html( (string) $count ); ?></span><?php endif; ?></a>
		<?php
	}

	/**
	 * Returns a parent name only for categories.
	 *
	 * @param Taxonomy        $taxonomy    Current taxonomy.
	 * @param int|string|null $parent_name Stored parent name.
	 */
	private function parent_label( Taxonomy $taxonomy, int|string|null $parent_name ): string {
		if ( Taxonomy::CATEGORY === $taxonomy && is_string( $parent_name ) && '' !== $parent_name ) {
			return $parent_name;
		}

		return __( '—', 'term-steward' );
	}

	/**
	 * Returns the translated usage classification.
	 *
	 * @param string $usage Internal usage classification.
	 */
	private function usage_label( string $usage ): string {
		if ( 'published' === $usage ) {
			return __( 'Used by published posts', 'term-steward' );
		}

		if ( 'excluded_only' === $usage ) {
			return __( 'Used outside published posts', 'term-steward' );
		}

		return __( 'Globally unused', 'term-steward' );
	}

	/**
	 * Returns translated summaries for conditions that differ from defaults.
	 *
	 * @param string $search  Search value.
	 * @param string $orderby Sort field.
	 * @param string $order   Sort direction.
	 * @param bool   $unused  Global-unused filter.
	 * @return list<string>
	 */
	private function active_conditions( string $search, string $orderby, string $order, bool $unused ): array {
		$conditions = array();

		if ( '' !== $search ) {
			$conditions[] = sprintf(
				/* translators: %s: taxonomy search keyword. */
				__( 'Keyword: %s', 'term-steward' ),
				$search
			);
		}

		if ( $unused ) {
			$conditions[] = __( 'Globally unused', 'term-steward' );
		}

		if ( 'name' !== $orderby || 'asc' !== $order ) {
			$sort_field   = 'published_count' === $orderby
				? __( 'Published posts', 'term-steward' )
				: __( 'Name', 'term-steward' );
			$direction    = 'desc' === $order
				? __( 'Descending', 'term-steward' )
				: __( 'Ascending', 'term-steward' );
			$conditions[] = $sort_field . ' · ' . $direction;
		}

		return $conditions;
	}

	/**
	 * Returns the translated active-condition count.
	 *
	 * @param int $count Number of active conditions.
	 */
	private function condition_count_label( int $count ): string {
		if ( 0 === $count ) {
			return __( 'No active conditions', 'term-steward' );
		}

		return sprintf(
			/* translators: %d: number of active search, filter, or sort conditions. */
			_n( '%d active condition', '%d active conditions', $count, 'term-steward' ),
			$count
		);
	}

	/**
	 * Returns the current taxonomy URL without search, filter, sort, or page state.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param int      $per_page Current page size, which is not a search condition.
	 */
	private function reset_url( Taxonomy $taxonomy, int $per_page ): string {
		return add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $taxonomy->value,
				'per_page' => $per_page,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Creates two GET forms outside the existing selection and action POST form.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param string   $search   Current keyword.
	 * @param string   $orderby  Current sort field.
	 * @param string   $order    Current sort direction.
	 * @param bool     $unused   Current usage filter.
	 */
	private function render_page_size_forms( Taxonomy $taxonomy, string $search, string $orderby, string $order, bool $unused ): void {
		foreach ( array( 'top', 'bottom' ) as $position ) {
			?>
			<form id="term-steward-page-size-<?php echo esc_attr( $position ); ?>-form" method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
				<?php
				if ( '' !== $search ) :
					?>
					<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
				<?php
				if ( 'name' !== $orderby ) :
					?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>"><?php endif; ?>
				<?php
				if ( 'asc' !== $order ) :
					?>
					<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>"><?php endif; ?>
				<?php
				if ( $unused ) :
					?>
					<input type="hidden" name="unused" value="1"><?php endif; ?>
			</form>
			<?php
		}
	}

	/**
	 * Renders matching controls directly above or below the inventory table.
	 *
	 * @param string               $position  Top or bottom controls.
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @param string               $search    Search value.
	 * @param string               $orderby   Sort field.
	 * @param string               $order     Sort direction.
	 * @param bool                 $unused    Global-unused filter.
	 * @param array<string, mixed> $inventory Paginated query result.
	 */
	private function render_pagination(
		string $position,
		Taxonomy $taxonomy,
		string $search,
		string $orderby,
		string $order,
		bool $unused,
		array $inventory
	): void {
		$form_id   = 'term-steward-page-size-' . $position . '-form';
		$select_id = 'term-steward-page-size-' . $position;
		$total     = (int) $inventory['total'];
		$page      = (int) $inventory['page'];
		$pages     = (int) $inventory['total_pages'];
		$per_page  = (int) $inventory['per_page'];
		$start     = 0 === $total ? 0 : ( $page - 1 ) * $per_page + 1;
		$end       = min( $total, $page * $per_page );
		?>
		<div class="tablenav term-steward-table-nav term-steward-table-nav--<?php echo esc_attr( $position ); ?>">
			<div class="term-steward-page-size"><label for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html__( 'Items per page', 'term-steward' ); ?></label><select class="tt-control" id="<?php echo esc_attr( $select_id ); ?>" name="per_page" form="<?php echo esc_attr( $form_id ); ?>">
				<?php foreach ( array( 20, 50, 100 ) as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php /* translators: %d: number of terms per page. */ echo esc_html( sprintf( __( '%d items', 'term-steward' ), $option ) ); ?></option>
				<?php endforeach; ?>
			</select><button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button tt-button tt-button--secondary"><?php echo esc_html__( 'Apply', 'term-steward' ); ?></button></div>
			<span class="term-steward-page-range">
			<?php
			if ( 0 === $total ) {
				echo esc_html__( 'No matching items', 'term-steward' );
			} else {
				/* translators: 1: filtered total, 2: first visible item, 3: last visible item. */
				echo esc_html( sprintf( __( 'Showing %2$s–%3$s of %1$s items', 'term-steward' ), number_format_i18n( $total ), number_format_i18n( $start ), number_format_i18n( $end ) ) );
			}
			?>
			</span>
			<?php if ( 1 < $pages ) : ?>
				<nav class="tablenav-pages term-steward-pagination" aria-label="<?php echo esc_attr( 'top' === $position ? __( 'Top pagination', 'term-steward' ) : __( 'Bottom pagination', 'term-steward' ) ); ?>">
					<?php $this->render_page_button( '<<', 1, __( 'Go to the first page', 'term-steward' ), 1 === $page, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php $this->render_page_button( '<', $page - 1, __( 'Go to the previous page', 'term-steward' ), 1 === $page, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php
					$previous = 0;
					foreach ( $this->visible_pages( $page, $pages ) as $number ) {
						if ( 1 < $number - $previous ) {
							?>
							<span class="term-steward-page-ellipsis" aria-hidden="true">…</span>
							<?php
						}
						if ( $number === $page ) {
							?>
							<span class="button tt-button tt-button--pagination term-steward-page-link term-steward-page-current" aria-current="page" aria-label="<?php /* translators: %d: current page number. */ echo esc_attr( sprintf( __( 'Page %d', 'term-steward' ), $number ) ); ?>"><?php echo esc_html( (string) $number ); ?></span>
							<?php
						} else {
							/* translators: %d: target page number. */
							$this->render_page_button( (string) $number, $number, sprintf( __( 'Go to page %d', 'term-steward' ), $number ), false, $taxonomy, $search, $orderby, $order, $unused, $per_page );
						}
						$previous = $number;
					}
					?>
					<?php $this->render_page_button( '>', $page + 1, __( 'Go to the next page', 'term-steward' ), $page === $pages, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php $this->render_page_button( '>>', $pages, __( 'Go to the last page', 'term-steward' ), $page === $pages, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns the first, last, current, and adjacent page numbers.
	 *
	 * @param int $current Current page number.
	 * @param int $total   Total number of pages.
	 * @return list<int>
	 */
	private function visible_pages( int $current, int $total ): array {
		if ( 7 >= $total ) {
			return range( 1, $total );
		}
		$numbers = array( 1, $total, $current - 1, $current, $current + 1 );
		if ( 3 >= $current ) {
			$numbers = array_merge( $numbers, array( 2, 3 ) );
		}
		if ( $current >= $total - 2 ) {
			$numbers = array_merge( $numbers, array( $total - 2, $total - 1 ) );
		}
		$numbers = array_values( array_unique( array_filter( $numbers, static fn( int $number ): bool => 0 < $number && $number <= $total ) ) );
		sort( $numbers );
		return $numbers;
	}

	/**
	 * Renders an enabled link or a noninteractive boundary control.
	 *
	 * @param string   $text     Visible symbol or page number.
	 * @param int      $target   Target page number.
	 * @param string   $label    Accessible control label.
	 * @param bool     $disabled Whether the control is unavailable.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param string   $search   Current keyword.
	 * @param string   $orderby  Current sort field.
	 * @param string   $order    Current sort direction.
	 * @param bool     $unused   Current usage filter.
	 * @param int      $per_page Current page size.
	 */
	private function render_page_button( string $text, int $target, string $label, bool $disabled, Taxonomy $taxonomy, string $search, string $orderby, string $order, bool $unused, int $per_page ): void {
		if ( $disabled ) {
			?>
			<span class="button tt-button tt-button--pagination term-steward-page-link term-steward-page-disabled" aria-disabled="true" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $text ); ?></span>
			<?php
			return;
		}
		$args = array(
			'page'     => self::SLUG,
			'taxonomy' => $taxonomy->value,
			'per_page' => $per_page,
			'paged'    => $target,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( 'name' !== $orderby ) {
			$args['orderby'] = $orderby;
		}
		if ( 'asc' !== $order ) {
			$args['order'] = $order;
		}
		if ( $unused ) {
			$args['unused'] = '1';
		}
		$url = add_query_arg( $args, admin_url( 'tools.php' ) );
		?>
		<a class="button tt-button tt-button--pagination term-steward-page-link" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $text ); ?></a>
		<?php
	}

	/**
	 * Renders a sortable inventory heading while preserving list conditions.
	 *
	 * @param string   $key       Supported sort key.
	 * @param string   $label     Localized heading label.
	 * @param Taxonomy $taxonomy  Current taxonomy.
	 * @param string   $search    Search value.
	 * @param string   $orderby   Current sort key.
	 * @param string   $order     Current direction.
	 * @param bool     $unused    Global-unused filter.
	 * @param int      $per_page  Current page size.
	 */
	private function render_sort_header( string $key, string $label, Taxonomy $taxonomy, string $search, string $orderby, string $order, bool $unused, int $per_page ): void {
		$active     = $key === $orderby;
		$next_order = $active && 'asc' === $order ? 'desc' : 'asc';
		$args       = array(
			'page'     => self::SLUG,
			'taxonomy' => $taxonomy->value,
			'orderby'  => $key,
			'order'    => $next_order,
			'per_page' => $per_page,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( $unused ) {
			$args['unused'] = '1';
		}
		$url = add_query_arg( $args, admin_url( 'tools.php' ) );
		?>
		<th scope="col" class="manage-column <?php echo esc_attr( $active ? 'sorted ' . $order : 'sortable asc' ); ?>" <?php echo $active ? 'aria-sort="' . esc_attr( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a href="<?php echo esc_url( $url ); ?>"><span><?php echo esc_html( $label ); ?></span><span class="sorting-indicators" aria-hidden="true"><span class="sorting-indicator asc"></span><span class="sorting-indicator desc"></span></span></a></th>
		<?php
	}
}
