<?php
/**
 * PickyBooking bootstrap (namespaced plugin body).
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Prevent running alongside another PickyBooking edition (same CPTs / option keys).
 * Uses a load flag so folder names (PRO / Lite / CodeCanyon PickyBooking/) do not matter.
 */
function picky_lite_conflict_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'PickyBooking cannot run while another PickyBooking edition is active. Please deactivate one of them.', 'pickybooking' );
	echo '</p></div>';
}

if ( defined( 'PICKY_PLUGIN_LOADED' ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\picky_lite_conflict_notice' );
	return;
}
define( 'PICKY_PLUGIN_LOADED', 'lite' );

if ( ! defined( 'PICKY_EDITION' ) ) {
	define( 'PICKY_EDITION', 'lite' );
}
if ( ! defined( 'PICKY_PLUGIN_FILE' ) ) {
	define( 'PICKY_PLUGIN_FILE', dirname( __DIR__ ) . '/pickybooking.php' );
}
if ( ! defined( 'PICKY_PLUGIN_DIR' ) ) {
	define( 'PICKY_PLUGIN_DIR', plugin_dir_path( PICKY_PLUGIN_FILE ) );
}

require_once PICKY_PLUGIN_DIR . 'includes/class-info.php';
require_once PICKY_PLUGIN_DIR . 'includes/class-edition.php';
require_once PICKY_PLUGIN_DIR . 'includes/picky-helpers.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/layout-element-defaults.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/cinema-auditorium.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/coworking-floor.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/beauty-salon.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/ev-charging-station.php';
require_once PICKY_PLUGIN_DIR . 'includes/layouts/wedding-ceremony.php';

/**
 * Activation.
 */
function activation() {
	require_once PICKY_PLUGIN_DIR . 'includes/class-activator.php';
	Activator::activate();
}
register_activation_hook( PICKY_PLUGIN_FILE, __NAMESPACE__ . '\\activation' );

/**
 * Boot plugin.
 */
function run() {
	require_once PICKY_PLUGIN_DIR . 'includes/class-plugin.php';
	$plugin = new Plugin();
	$plugin->run();
}
run();

add_filter(
	'admin_body_class',
	function ( $classes ) {
		$post_type = sanitize_key( picky_request_query_value( 'post_type' ) );
		$page      = sanitize_key( picky_request_query_value( 'page' ) );
		if (
			$post_type === 'picky_booking' ||
			$post_type === 'picky_layout' ||
			in_array(
				$page,
				array(
					'layout',
					'picky_layout',
					'picky_settings',
					'settings',
					'picky_calendar',
					'picky_documentation',
					'pickybooking',
				),
				true
			)
		) {
			$classes .= ' picky-admin picky-edition-lite';
		}

		$layout_id = absint( picky_request_query_value( 'layout_id' ) );
		$picky_tab = sanitize_text_field( picky_request_query_value( 'picky-tab' ) );
		if ( $page === 'picky_settings' && $layout_id > 0 && ( $picky_tab === '' || $picky_tab === '9' ) ) {
			$classes .= ' picky-layout-builder-open';
		}

		return $classes;
	}
);
