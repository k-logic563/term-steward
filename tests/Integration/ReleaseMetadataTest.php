<?php
/**
 * WordPress.org release metadata tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use WP_UnitTestCase;

/** Verifies metadata required by the WordPress.org review fix. */
final class ReleaseMetadataTest extends WP_UnitTestCase {
	/** The WordPress.org readme is English and identifies version 0.1.1. */
	public function test_wordpress_org_readme_metadata_and_sections(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local release file in a test.
		$readme = file_get_contents( dirname( TERM_STEWARD_PLUGIN_FILE ) . '/readme.txt' );
		$this->assertIsString( $readme );
		$this->assertStringContainsString( "Contributors: klogic563\n", $readme );
		$this->assertStringContainsString( "Stable tag: 0.1.1\n", $readme );
		foreach ( array( '== Description ==', '== Installation ==', '== Frequently Asked Questions ==', '== Screenshots ==', '== Changelog ==', '== Upgrade Notice ==' ) as $section ) {
			$this->assertStringContainsString( $section, $readme );
		}
		$this->assertDoesNotMatchRegularExpression( '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $readme );

		$this->assertSame( 1, preg_match( '/License URI:[^\n]+\n\n([^\n]+)\n\n== Description ==/', $readme, $matches ) );
		$short_description = $matches[1];
		$this->assertNotSame( '', $short_description );
		$this->assertLessThanOrEqual( 150, strlen( $short_description ) );
	}

	/** Composer production metadata matches the plugin namespace. */
	public function test_composer_production_metadata(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local release file in a test.
		$json = file_get_contents( dirname( TERM_STEWARD_PLUGIN_FILE ) . '/composer.json' );
		$this->assertIsString( $json );
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'klogic563/term-steward', $data['name'] );
		$this->assertSame( 'src/', $data['autoload']['psr-4']['TermSteward\\'] );
	}
}
