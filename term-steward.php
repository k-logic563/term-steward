<?php
/**
 * Plugin Name:       Term Steward
 * Plugin URI:        https://github.com/k-logic563/term-steward
 * Description:       Safely organize WordPress categories and tags in bulk.
 * Version:           0.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Term Steward Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       term-steward
 *
 * @package TermSteward
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TERM_STEWARD_VERSION', '0.1.1' );
define( 'TERM_STEWARD_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/bootstrap/dependencies.php';

if ( ! TermSteward\term_steward_has_runtime_dependencies( __DIR__ ) ) {
	add_action( 'admin_notices', 'TermSteward\\term_steward_render_missing_dependencies_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(
	TERM_STEWARD_PLUGIN_FILE,
	array( TermSteward\Lifecycle::class, 'activate' )
);

register_deactivation_hook(
	TERM_STEWARD_PLUGIN_FILE,
	array( TermSteward\Lifecycle::class, 'deactivate' )
);

TermSteward\Plugin::instance()->register();
