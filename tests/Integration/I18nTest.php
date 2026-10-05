<?php
/**
 * Plugin internationalization integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TermSteward\Plugin;
use WP_UnitTestCase;

use function TermSteward\term_steward_render_missing_dependencies_notice;

/**
 * Verifies English gettext sources and the WordPress.org language-pack setup.
 */
final class I18nTest extends WP_UnitTestCase {
	/**
	 * Query parameters present before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/** Starts each test with the source-string fallback. */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The test preserves read-only query state; it does not process a request.
		$this->original_get = $_GET;
		$_GET               = array();
		unload_textdomain( 'term-steward', true );
	}

	/** Restores request data and the current user. */
	public function tear_down(): void {
		unload_textdomain( 'term-steward', true );
		$_GET = $this->original_get;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** A site without a downloaded language pack uses English source strings. */
	public function test_site_without_language_pack_renders_english_source_strings(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		Plugin::instance()->admin_page()->render();
		$output = (string) ob_get_clean();

		foreach ( array( 'Categories', 'Search panel', 'Find by keyword', 'Apply conditions', 'Published posts', 'Total relationships', 'Action panel', 'Selected targets', 'Action method', 'Changes', 'Add to plan' ) as $english ) {
			$this->assertStringContainsString( $english, $output );
		}

		ob_start();
		term_steward_render_missing_dependencies_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Term Steward could not start', $notice );
	}

	/** Product PHP and JavaScript contain no Japanese gettext source or fallback text. */
	public function test_product_sources_contain_no_japanese_user_facing_literals(): void {
		$root  = dirname( TERM_STEWARD_PLUGIN_FILE );
		$paths = array( $root . '/src', $root . '/bootstrap', $root . '/assets' );

		foreach ( $paths as $path ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
			foreach ( $files as $file ) {
				if ( ! $file->isFile() || ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local product files in a test.
				$contents = file_get_contents( $file->getPathname() );
				$this->assertIsString( $contents );
				$this->assertDoesNotMatchRegularExpression( '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $contents, $file->getPathname() );
			}
		}
	}

	/** Every gettext call in product PHP uses the plugin text domain. */
	public function test_product_gettext_calls_use_term_steward_domain(): void {
		$root  = dirname( TERM_STEWARD_PLUGIN_FILE );
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local product files in a test.
			$contents = file_get_contents( $file->getPathname() );
			$this->assertIsString( $contents );
			preg_match_all( '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*\'[^\']*\'\s*,\s*\'([^\']+)\'/', $contents, $matches );
			foreach ( $matches[1] as $domain ) {
				$this->assertSame( 'term-steward', $domain, $file->getPathname() );
			}
		}
	}
}
