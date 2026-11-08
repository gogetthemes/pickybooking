<?php
/**
 * Plugin Name:       PickyBooking
 * Plugin URI:        https://pickybooking.com/
 * Description:       Free visual booking plugin: drag-and-drop floor plans with bookable items, guest booking shortcode, and email notifications. Upgrade to PickyBooking Pro for payments, integrations, decorative layers, and more.
 * Version:           1.0.18
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            GoGetThemes
 * Author URI:        https://gogetthemes.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pickybooking
 * Domain Path:       /languages
 *
 * @package Picky
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/plugin-bootstrap.php';
