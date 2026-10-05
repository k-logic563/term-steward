<?php
/**
 * Runtime dependency guard loaded before the Composer autoloader.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns whether the Composer runtime autoloader is available.
 *
 * @param string $plugin_directory Absolute plugin directory.
 */
function term_steward_has_runtime_dependencies( string $plugin_directory ): bool {
	return is_readable( $plugin_directory . '/vendor/autoload.php' );
}

/**
 * Explains why the plugin remained inactive when dependencies are missing.
 */
function term_steward_render_missing_dependencies_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'Term Steward could not start because vendor/autoload.php is missing.',
				'term-steward'
			);
			?>
			<code>docker compose run --rm composer install</code>
		</p>
	</div>
	<?php
}
