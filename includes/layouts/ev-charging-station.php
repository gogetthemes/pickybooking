<?php
/**
 * EV charging station sample layout — loads bundled JSON.
 *
 * Canonical preset: samples/ev-charging-station.json (curated editor layout).
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'picky_get_ev_charging_layout_payload' ) ) {
	/**
	 * Load the bundled EV charging layout payload.
	 *
	 * @return array<string, mixed>
	 */
	function picky_get_ev_charging_layout_payload() {
		$file = dirname( dirname( __DIR__ ) ) . '/samples/ev-charging-station.json';
		if ( ! is_readable( $file ) ) {
			return array(
				'version'         => 2,
				'title'           => 'EV Charging Station',
				'elements'        => array(),
				'_sample_version' => '1.0.0',
			);
		}

		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return array(
				'version'         => 2,
				'title'           => 'EV Charging Station',
				'elements'        => array(),
				'_sample_version' => '1.0.0',
			);
		}

		if ( empty( $data['title'] ) ) {
			$data['title'] = 'EV Charging Station';
		}
		if ( ! isset( $data['elements'] ) || ! is_array( $data['elements'] ) ) {
			$data['elements'] = array();
		}
		if ( ! isset( $data['services_library'] ) || ! is_array( $data['services_library'] ) ) {
			$data['services_library'] = array();
		}

		$data['_sample_version'] = '1.0.0';

		return $data;
	}
}
