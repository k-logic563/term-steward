<?php
/**
 * Plugin bootstrap integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Admin\HistoryPage;
use TermSteward\Admin\PlanController;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Lifecycle;
use TermSteward\Plugin;
use WP_UnitTestCase;

/**
 * Verifies installation metadata and bootstrap hooks.
 */
final class PluginBootstrapTest extends WP_UnitTestCase {
	/**
	 * The main plugin file loads and declares the expected metadata.
	 */
	public function test_plugin_bootstraps_with_valid_headers(): void {
		$headers = get_file_data(
			TERM_STEWARD_PLUGIN_FILE,
			array(
				'name'        => 'Plugin Name',
				'version'     => 'Version',
				'requiresWP'  => 'Requires at least',
				'requiresPHP' => 'Requires PHP',
				'textDomain'  => 'Text Domain',
				'description' => 'Description',
			)
		);

		$this->assertSame( 'Term Steward', $headers['name'] );
		$this->assertSame( '0.1.1', $headers['version'] );
		$this->assertSame( '6.6', $headers['requiresWP'] );
		$this->assertSame( '8.2', $headers['requiresPHP'] );
		$this->assertSame( 'term-steward', $headers['textDomain'] );
		$this->assertSame( 'Safely organize WordPress categories and tags in bulk.', $headers['description'] );
		$this->assertSame( '0.1.1', TERM_STEWARD_VERSION );
		$this->assertStringEndsWith( '/term-steward.php', TERM_STEWARD_PLUGIN_FILE );
		$this->assertStringStartsWith( 'TermSteward\\', Plugin::class );
		$this->assertFalse( defined( 'TAXONOMY_TIDY_VERSION' ) );
		$this->assertFalse( class_exists( 'TaxonomyTidy\\Plugin' ) );
		$this->assertSame(
			10,
			has_action( 'admin_menu', array( Plugin::instance()->admin_page(), 'register_menu' ) )
		);
		$this->assertSame(
			10,
			has_action( 'admin_enqueue_scripts', array( Plugin::instance()->admin_page(), 'enqueue_assets' ) )
		);
		$this->assertSame( 10, has_action( 'wp_ajax_term_steward_preview_posts', array( Plugin::instance()->admin_page(), 'preview_posts' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_term_steward_undo_batch', array( Plugin::instance()->admin_page(), 'undo_batch' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_term_steward_history_logs', array( Plugin::instance()->admin_page(), 'history_logs' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_taxonomy_tidy_preview_posts' ) );
		$this->assertFalse( has_action( 'wp_ajax_taxonomy_tidy_undo_batch' ) );
		$this->assertFalse( has_action( 'wp_ajax_taxonomy_tidy_history_logs' ) );
		$this->assertSame( 'term_steward_plan', PlanController::NONCE_ACTION );
		$this->assertSame( 'term_steward_nonce', PlanController::NONCE_FIELD );
		$this->assertSame( 'term_steward_undo', HistoryPage::NONCE_ACTION );
		$this->assertSame( 'term_steward_undo_nonce', HistoryPage::NONCE_FIELD );
		$this->assertFalse( has_action( 'init', 'TermSteward\\term_steward_load_translations' ) );
		$this->assertFalse( has_action( 'init', 'TaxonomyTidy\\load_translations' ) );
	}

	/**
	 * Admin styles and scripts are limited to the Term Steward Tools screen.
	 */
	public function test_admin_styles_are_enqueued_only_for_plugin_screen(): void {
		$page = Plugin::instance()->admin_page();

		wp_dequeue_style( 'term-steward-admin' );
		wp_dequeue_script( 'term-steward-admin' );
		$page->enqueue_assets( 'tools_page_other-plugin' );
		$this->assertFalse( wp_style_is( 'term-steward-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'term-steward-admin', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'taxonomy-tidy-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'taxonomy-tidy-admin', 'enqueued' ) );

		$page->enqueue_assets( 'tools_page_term-steward' );
		$this->assertTrue( wp_style_is( 'term-steward-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'term-steward-admin', 'enqueued' ) );
		wp_dequeue_style( 'term-steward-admin' );
		wp_dequeue_script( 'term-steward-admin' );
	}

	/**
	 * Lifecycle callbacks install the current schema without failing.
	 */
	public function test_lifecycle_callbacks_install_schema(): void {
		Lifecycle::activate();
		Lifecycle::deactivate();

		$this->assertSame( Schema::VERSION, Schema::stored_version() );
	}
}
