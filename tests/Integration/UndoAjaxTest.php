<?php
/**
 * Undo Ajax response integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Admin\HistoryPage;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use WPAjaxDieContinueException;
use WP_Ajax_UnitTestCase;

/**
 * Verifies client-safe terminal responses from the authenticated Undo endpoint.
 *
 * @group ajax
 */
final class UndoAjaxTest extends WP_Ajax_UnitTestCase {
	/** Installs empty plugin tables for mutation assertions. */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->clear_rows();
	}

	/** Removes isolated audit rows. */
	public function tear_down(): void {
		$this->clear_rows();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** An invalid nonce returns one terminal 403 with safe recovery guidance. */
	public function test_invalid_nonce_returns_non_retryable_403_without_mutation(): void {
		$this->_setRole( 'administrator' );
		$_POST = array(
			HistoryPage::NONCE_FIELD => 'invalid-nonce',
			'undo_operation_id'      => '999',
			'taxonomy'               => 'post_tag',
		);
		try {
			$this->_handleAjax( 'term_steward_undo_batch' );
			$this->fail( 'The Ajax response must terminate after sending JSON.' );
		} catch ( WPAjaxDieContinueException $exception ) {
			// Expected terminal response from wp_send_json_error().
			$this->assertSame( '', $exception->getMessage() );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertFalse( $response['data']['retryable'] );
		$this->assertSame( 'Your session or credentials are no longer valid. Reload the page, sign in again if needed, and resume the operation.', $response['data']['message'] );
		$this->assertSame( array( 0, 0, 0 ), $this->table_counts() );
	}

	/** Returns operation, item, and journal row counts. */
	private function table_counts(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Assertions against isolated custom audit tables.
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::operations( $wpdb ) ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::items( $wpdb ) ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::changes( $wpdb ) ) ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/** Clears plugin audit tables between tests. */
	private function clear_rows(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated test cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::changes( $wpdb ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::items( $wpdb ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::operations( $wpdb ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
