<?php
/**
 * Inventory page-size and navigation integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use ReflectionMethod;
use TermSteward\Admin\Page;
use TermSteward\Domain\Operation\Taxonomy;
use WP_UnitTestCase;

/** Verifies the shared category and tag inventory navigation. */
final class InventoryPaginationTest extends WP_UnitTestCase {
	/**
	 * Original query values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Original request method.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/**
	 * Admin page under test.
	 *
	 * @var Page
	 */
	private Page $page;

	/** Prepares an authorized read-only admin request. */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test setup preserves GET state.
		$this->original_get = $_GET;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup preserves the request method.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->page = new Page();
	}

	/** Restores request globals. */
	public function tear_down(): void {
		$_GET = $this->original_get;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Page size is shared, defaults to 20, and rejects unsupported input. */
	public function test_allowed_page_sizes_and_invalid_values(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			for ( $index = 1; $index <= 23; $index++ ) {
				self::factory()->term->create(
					array(
						'taxonomy' => $taxonomy,
						'name'     => sprintf( 'Page Size %s %03d', $taxonomy, $index ),
					)
				);
			}
			$base    = array(
				'taxonomy' => $taxonomy,
				's'        => 'Page Size ' . $taxonomy,
			);
			$default = $this->render_page( $base );
			$this->assertSame( 20, $this->table_row_count( $default ) );
			$this->assertSame( 2, substr_count( $default, 'value="20"  selected=' ) );
			foreach ( array( 20, 50, 100 ) as $size ) {
				$output = $this->render_page( array_merge( $base, array( 'per_page' => (string) $size ) ) );
				$this->assertSame( min( 23, $size ), $this->table_row_count( $output ) );
				$this->assertSame( 2, substr_count( $output, 'value="' . $size . '"  selected=' ) );
			}
		}
		foreach ( array( '', '0', '-20', '25', '1000', 'abc' ) as $invalid ) {
			$output = $this->render_page(
				array(
					'taxonomy' => 'post_tag',
					's'        => 'Page Size post_tag',
					'per_page' => $invalid,
				)
			);
			$this->assertSame( 20, $this->table_row_count( $output ), 'Invalid size: ' . $invalid );
		}
	}

	/** Both table controls preserve filters without nesting forms or duplicate IDs. */
	public function test_table_controls_and_navigation_preserve_filters(): void {
		for ( $index = 1; $index <= 45; $index++ ) {
			self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => sprintf( 'Navigation Tag %03d', $index ),
				)
			);
		}
		$output = $this->render_page(
			array(
				'taxonomy' => 'post_tag',
				's'        => 'Navigation Tag',
				'orderby'  => 'published_count',
				'order'    => 'desc',
				'unused'   => '1',
				'per_page' => '20',
				'paged'    => '2',
				'nonce'    => 'must-not-leak',
			)
		);
		$this->assertSame( 20, $this->table_row_count( $output ) );
		$this->assertSame( 2, substr_count( $output, 'Showing 21–40 of 45 items' ) );
		$this->assertSame( 2, substr_count( $output, 'aria-current="page" aria-label="Page 2"' ) );
		$this->assertSame( 2, substr_count( $output, 'class="tablenav-pages term-steward-pagination"' ) );
		$this->assertStringContainsString( 'class="manage-column sorted desc" aria-sort="descending"', $output );
		$this->assertStringContainsString( 'class="manage-column sortable asc"', $output );
		$this->assertStringContainsString( 'orderby=name', $output );
		$this->assertStringContainsString( 'order=asc', $output );
		$this->assertSame( 2, substr_count( $output, 'name="per_page" form="term-steward-page-size-' ) );
		$this->assertSame( 2, substr_count( $output, '>Apply</button>' ) );
		$this->assertLessThan( strpos( $output, 'term-steward-inventory-table' ), strpos( $output, 'term-steward-table-nav--top' ) );
		$this->assertLessThan( strpos( $output, 'term-steward-table-nav--bottom' ), strpos( $output, 'term-steward-inventory-table' ) );
		$this->assertStringNotContainsString( '>Items per page<', $this->filter_panel( $output ) );
		preg_match_all( '/\bid="([^"]+)"/', $output, $ids );
		$this->assertCount( count( array_unique( $ids[1] ) ), $ids[1] );
		preg_match_all( '/<\/?form\b[^>]*>/', $output, $forms );
		$open = false;
		foreach ( $forms[0] as $form ) {
			if ( str_starts_with( $form, '</' ) ) {
				$this->assertTrue( $open );
				$open = false;
			} else {
				$this->assertFalse( $open, 'Forms must not be nested.' );
				$open = true;
			}
		}
		$this->assertFalse( $open );
		preg_match_all( '/<a class="button tt-button tt-button--pagination term-steward-page-link" href="([^"]+)" aria-label="([^"]+)">/', $output, $links, PREG_SET_ORDER );
		$labels = array();
		foreach ( $links as $link ) {
			$url = html_entity_decode( $link[1], ENT_QUOTES, 'UTF-8' );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertSame( 'post_tag', $query['taxonomy'] );
			$this->assertSame( 'Navigation Tag', $query['s'] );
			$this->assertSame( 'published_count', $query['orderby'] );
			$this->assertSame( 'desc', $query['order'] );
			$this->assertSame( '1', $query['unused'] );
			$this->assertSame( '20', $query['per_page'] );
			$this->assertArrayNotHasKey( 'nonce', $query );
			$labels[ $link[2] ] = $query['paged'];
		}
		$this->assertSame( '1', $labels['Go to the first page'] );
		$this->assertSame( '1', $labels['Go to the previous page'] );
		$this->assertSame( '3', $labels['Go to the next page'] );
		$this->assertSame( '3', $labels['Go to the last page'] );
		$this->assertSame( '1', $labels['Go to page 1'] );
		$this->assertSame( '3', $labels['Go to page 3'] );
		$this->assertStringNotContainsString( 'name="paged"', $output );
		foreach ( array( 'top', 'bottom' ) as $position ) {
			$this->assertSame( 1, preg_match( '/<form id="term-steward-page-size-' . $position . '-form"[^>]*>(.*?)<\/form>/s', $output, $form_match ) );
			$this->assertStringContainsString( 'name="taxonomy" value="post_tag"', $form_match[1] );
			$this->assertStringContainsString( 'name="s" value="Navigation Tag"', $form_match[1] );
			$this->assertStringContainsString( 'name="orderby" value="published_count"', $form_match[1] );
			$this->assertStringContainsString( 'name="order" value="desc"', $form_match[1] );
			$this->assertStringContainsString( 'name="unused" value="1"', $form_match[1] );
			$this->assertStringNotContainsString( 'name="paged"', $form_match[1] );
			$this->assertStringNotContainsString( 'nonce', $form_match[1] );
		}
		$changed_size = $this->render_page(
			array(
				'taxonomy' => 'post_tag',
				's'        => 'Navigation Tag',
				'per_page' => '50',
			)
		);
		$this->assertStringContainsString( 'Showing 1–45 of 45 items', $changed_size );
		$this->assertSame( 45, $this->table_row_count( $changed_size ) );
		$negative_page = $this->render_page(
			array(
				'taxonomy' => 'post_tag',
				's'        => 'Navigation Tag',
				'paged'    => '-2',
			)
		);
		$this->assertStringContainsString( 'Showing 1–20 of 45 items', $negative_page );
		$overflow_page = $this->render_page(
			array(
				'taxonomy' => 'post_tag',
				's'        => 'Navigation Tag',
				'paged'    => '999',
			)
		);
		$this->assertStringContainsString( 'Showing 41–45 of 45 items', $overflow_page );
		$this->assertSame( 5, $this->table_row_count( $overflow_page ) );
	}

	/** Current, boundary, omitted, single-page, and empty states remain clear. */
	public function test_numbered_navigation_boundaries_and_empty_state(): void {
		$middle = $this->render_navigation( 25, 50, 1000 );
		$this->assertSame( 2, substr_count( $middle, 'term-steward-page-ellipsis' ) );
		foreach ( array( 'Go to page 24', 'Page 25', 'Go to page 26', 'Go to the first page', 'Go to the last page' ) as $label ) {
			$this->assertStringContainsString( $label, $middle );
		}
		$this->assertStringNotContainsString( 'aria-label="Go to page 23"', $middle );
		$this->assertStringContainsString( 'aria-current="page"', $middle );
		$this->assertSame( 1, preg_match( '/<span class="button tt-button tt-button--pagination term-steward-page-link term-steward-page-current"[^>]*>25<\/span>/', $middle ) );
		$first = $this->render_navigation( 1, 50, 1000 );
		$this->assertSame( 2, substr_count( $first, 'term-steward-page-disabled' ) );
		$this->assertSame( 2, substr_count( $first, 'aria-disabled="true"' ) );
		$this->assertStringContainsString( 'aria-label="Go to page 3"', $first );
		$this->assertStringNotContainsString( 'aria-label="Go to page 4"', $first );
		$third = $this->render_navigation( 3, 50, 1000 );
		$this->assertStringContainsString( 'aria-label="Go to page 4"', $third );
		$this->assertStringNotContainsString( 'aria-label="Go to page 5"', $third );
		$near_last = $this->render_navigation( 48, 50, 1000 );
		$this->assertStringContainsString( 'aria-label="Go to page 47"', $near_last );
		$this->assertStringContainsString( 'aria-label="Go to page 49"', $near_last );
		$last = $this->render_navigation( 50, 50, 1000 );
		$this->assertSame( 2, substr_count( $last, 'term-steward-page-disabled' ) );
		$this->assertStringContainsString( 'aria-label="Go to page 48"', $last );
		$single = $this->render_navigation( 1, 1, 12 );
		$this->assertStringNotContainsString( 'term-steward-pagination', $single );
		$this->assertStringContainsString( 'Showing 1–12 of 12 items', $single );
		$empty = $this->render_navigation( 1, 0, 0 );
		$this->assertStringContainsString( 'No matching items', $empty );
		$this->assertStringNotContainsString( 'Showing 1–0 of 0 items', $empty );
	}

	/**
	 * Renders an authorized inventory page.
	 *
	 * @param array<string, string> $query Request query values.
	 */
	private function render_page( array $query ): string {
		$_GET = $query;
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	/**
	 * Counts only the displayed term table rows.
	 *
	 * @param string $html Rendered admin page.
	 */
	private function table_row_count( string $html ): int {
		preg_match( '/<tbody>(.*?)<\/tbody>/s', $html, $matches );
		return substr_count( $matches[1] ?? '', '<tr ' );
	}

	/**
	 * Returns the search panel HTML.
	 *
	 * @param string $html Rendered admin page.
	 */
	private function filter_panel( string $html ): string {
		$start = strpos( $html, 'term-steward-filter-panel' );
		$end   = strpos( $html, '</details>', $start );
		return substr( $html, $start, $end - $start );
	}

	/**
	 * Invokes the navigation renderer with known totals to cover long ranges.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Total pages.
	 * @param int $total Total terms.
	 */
	private function render_navigation( int $page, int $pages, int $total ): string {
		$method = new ReflectionMethod( Page::class, 'render_pagination' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke(
			$this->page,
			'top',
			Taxonomy::POST_TAG,
			'',
			'name',
			'asc',
			false,
			array(
				'total'       => $total,
				'page'        => $page,
				'total_pages' => $pages,
				'per_page'    => 20,
			)
		);
		return (string) ob_get_clean();
	}
}
