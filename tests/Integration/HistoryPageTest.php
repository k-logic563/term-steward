<?php
/**
 * Phase 6 history administration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Admin\Page;
use TermSteward\Admin\HistoryPage;
use TermSteward\Application\Undo\UndoException;
use TermSteward\Application\Undo\UndoItemExecutor;
use TermSteward\Application\Undo\UndoPlanner;
use TermSteward\Application\Undo\UndoWorkflow;
use TermSteward\Domain\Operation\Action;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use TermSteward\Infrastructure\Persistence\OperationItemRepository;
use TermSteward\Infrastructure\Persistence\ChangeJournalRepository;
use TermSteward\Infrastructure\Persistence\DatabaseTransaction;
use TermSteward\Infrastructure\Persistence\OperationLock;
use WP_UnitTestCase;

/** Verifies history filtering, localization, ownership, and read security. */
final class HistoryPageTest extends WP_UnitTestCase {
	/**
	 * Preserved query values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Preserved posted values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post;

	/**
	 * Preserved request method.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/** Installs storage and initializes a GET request. */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->clear_rows();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test preserves request state.
		$this->original_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test preserves request state.
		$this->original_post = $_POST;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test preserves request state.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_GET                      = array( 'view' => 'history' );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/** Restores request state and persistence. */
	public function tear_down(): void {
		$this->clear_rows();
		$_GET  = $this->original_get;
		$_POST = $this->original_post;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Only started operations owned by the current administrator appear. */
	public function test_history_excludes_drafts_previews_and_other_owners(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$draft      = $repository->create( $user_id, Taxonomy::CATEGORY, $this->plan( 'rename' ) );
		$preview    = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'delete' ) );
		$repository->transition( $preview, Status::PREVIEWED );
		$running = $repository->create( $user_id, Taxonomy::CATEGORY, $this->plan( 'merge' ) );
		$repository->transition( $running, Status::PREVIEWED );
		$repository->transition( $running, Status::RUNNING );
		$complete = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $complete, Status::PREVIEWED );
		$repository->transition( $complete, Status::RUNNING );
		$repository->transition( $complete, Status::COMPLETED );
		$other = $repository->create( $other_id, Taxonomy::CATEGORY, $this->plan( 'delete' ) );
		$repository->transition( $other, Status::PREVIEWED );
		$repository->transition( $other, Status::RUNNING );
		$repository->transition( $other, Status::FAILED );

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Running', $output );
		$this->assertStringContainsString( 'Completed', $output );
		$this->assertStringContainsString( 'role="region" aria-label="Operation history list"', $output );
		$this->assertStringContainsString( '<th scope="col">Started</th>', $output );
		$this->assertStringContainsString( '<th scope="col"><span class="screen-reader-text">Details</span></th>', $output );
		$this->assertStringContainsString( 'history_id=' . $running, $output );
		$this->assertStringContainsString( 'history_id=' . $complete, $output );
		$this->assertStringNotContainsString( 'history_id=' . $draft, $output );
		$this->assertStringNotContainsString( 'history_id=' . $preview, $output );
		$this->assertStringNotContainsString( 'history_id=' . $other, $output );
		$this->assertCount( 2, $repository->history( $user_id, 1, 20 )['items'] );
	}

	/** An operation ID owned by another administrator does not expose its detail. */
	public function test_history_detail_rejects_another_owner(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$other      = $repository->create( $other_id, Taxonomy::CATEGORY, $this->plan( 'rename' ) );
		$repository->transition( $other, Status::PREVIEWED );
		$repository->transition( $other, Status::RUNNING );
		$repository->transition( $other, Status::COMPLETED );
		$_GET['history_id'] = (string) $other;

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'The operation history or undo process could not be verified.', $output );
		$this->assertStringNotContainsString( 'Operation #' . $other . ' details', $output );
	}

	/** The lazy log service rejects an operation ID owned by another administrator. */
	public function test_history_logs_reject_another_owner(): void {
		$user_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$operation  = $repository->create( $other_id, Taxonomy::CATEGORY, $this->plan( 'rename' ) );
		$repository->transition( $operation, Status::PREVIEWED );
		$repository->transition( $operation, Status::RUNNING );

		$this->expectException( UndoException::class );
		$this->history_page()->history_logs( $operation, $user_id, 1 );
	}

	/** An Undo preview POST without a valid nonce creates no child operation. */
	public function test_undo_preview_requires_nonce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$original   = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $original, Status::PREVIEWED );
		$repository->transition( $original, Status::RUNNING );
		$repository->transition( $original, Status::COMPLETED );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'view'                  => 'history',
			'undo_command'          => 'preview_undo',
			'original_operation_id' => (string) $original,
			'taxonomy'              => 'post_tag',
		);

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'This action has expired.', $output );
		$this->assertCount( 1, $repository->history( $user_id, 1, 20 )['items'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Assertion against the isolated custom audit table.
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i', Tables::operations( $GLOBALS['wpdb'] ) ) ) );
	}

	/** A valid Undo preview is modal-only and remains absent from started history. */
	public function test_undo_preview_renders_required_modal_actions(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id    = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'After',
				'slug'     => 'history-undo',
			)
		);
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$original   = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $original, Status::PREVIEWED );
		$repository->transition( $original, Status::RUNNING );
		$repository->transition( $original, Status::COMPLETED );
		$items   = new OperationItemRepository( $GLOBALS['wpdb'] );
		$item_id = $items->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$items->mark_completed( $item_id );
		( new ChangeJournalRepository( $GLOBALS['wpdb'] ) )->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Before' ), array( 'name' => 'After' ), $term_id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'view'                   => 'history',
			'undo_command'           => 'preview_undo',
			'original_operation_id'  => (string) $original,
			'taxonomy'               => 'post_tag',
			HistoryPage::NONCE_FIELD => wp_create_nonce( HistoryPage::NONCE_ACTION ),
		);

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'role="dialog"', $output );
		$this->assertStringContainsString( 'Undo preview', $output );
		$this->assertStringContainsString( 'Undo target:', $output );
		$this->assertStringNotContainsString( 'Undo operation: #', $output );
		$this->assertStringContainsString( '>Cancel</button>', $output );
		$this->assertStringContainsString( 'name="undo_command" value="run_undo">Undo</button>', $output );
		$this->assertCount( 1, $repository->history( $user_id, 1, 20 )['items'] );
		$this->assertSame( array(), $repository->started_undos( $original ) );
	}

	/** Detail HTML contains only five prioritized log rows and a lazy expansion control. */
	public function test_history_detail_limits_initial_logs_to_five(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$operations = new OperationRepository( $GLOBALS['wpdb'] );
		$items      = new OperationItemRepository( $GLOBALS['wpdb'] );
		$journal    = new ChangeJournalRepository( $GLOBALS['wpdb'] );
		$operation  = $operations->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$operations->transition( $operation, Status::PREVIEWED );
		$operations->transition( $operation, Status::RUNNING );
		$operations->transition( $operation, Status::COMPLETED );
		$types = array( 'name_changed', 'slug_changed', 'destination_added', 'source_removed', 'source_retained', 'item_failed' );
		foreach ( $types as $index => $type ) {
			$item_id = $items->add( $operation, 'log:' . $index, Action::RENAME, array( 'before' => array( 'name' => 'History target ' . $index ) ) );
			$items->mark_completed( $item_id );
			$journal->record_once( $operation, $item_id, 'log:' . $index, $type, array( 'name' => 'Before ' . $index ), array( 'name' => 'After ' . $index ) );
		}
		$_GET['history_id'] = (string) $operation;

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertSame( 5, substr_count( $output, '<li class="term-steward-log ' ) );
		$this->assertStringContainsString( 'Total: 6; successful: 4; warnings: 1; errors: 1', $output );
		$this->assertStringContainsString( 'aria-expanded="false"', $output );
		$this->assertStringContainsString( '>View details</button>', $output );
		$this->assertStringNotContainsString( 'term_taxonomy_id', $output );
		$this->assertStringNotContainsString( 'item_failed', $output );
	}

	/**
	 * Returns a minimal stored request used only for history labels.
	 *
	 * @param string $action Operation action.
	 */
	private function plan( string $action ): array {
		return array(
			'plan' => array(
				array(
					'action'  => $action,
					'sources' => array( array( 'term_id' => 1 ) ),
				),
			),
		);
	}

	/** Returns the fully wired history service used by its authenticated endpoints. */
	private function history_page(): HistoryPage {
		$operations = new OperationRepository( $GLOBALS['wpdb'] );
		$items      = new OperationItemRepository( $GLOBALS['wpdb'] );
		$journal    = new ChangeJournalRepository( $GLOBALS['wpdb'] );
		$planner    = new UndoPlanner( $operations, $items, $journal, new OperationLock( $GLOBALS['wpdb'] ) );
		$workflow   = new UndoWorkflow( $operations, $items, new OperationLock( $GLOBALS['wpdb'] ), $planner, new UndoItemExecutor( $journal ), new DatabaseTransaction( $GLOBALS['wpdb'] ) );
		return new HistoryPage( $operations, $items, $journal, $planner, $workflow );
	}

	/** Clears isolated custom persistence tables. */
	private function clear_rows(): void {
		global $wpdb;
		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated integration tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
