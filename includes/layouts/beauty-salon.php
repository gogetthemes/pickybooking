<?php
/**
 * Beauty salon sample layout — loads bundled JSON.
 *
 * Canonical preset: samples/beauty-salon.json (curated editor layout).
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'picky_get_beauty_salon_layout_payload' ) ) {
	/**
	 * Load the bundled beauty salon layout payload.
	 *
	 * @return array<string, mixed>
	 */
	function picky_get_beauty_salon_layout_payload() {
		$file = dirname( dirname( __DIR__ ) ) . '/samples/beauty-salon.json';
		if ( ! is_readable( $file ) ) {
			return array(
				'version'         => 2,
				'title'           => 'Beauty Salon',
				'elements'        => array(),
				'_sample_version' => '3.2.0',
			);
		}

		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return array(
				'version'         => 2,
				'title'           => 'Beauty Salon',
				'elements'        => array(),
				'_sample_version' => '3.2.0',
			);
		}

		if ( empty( $data['title'] ) ) {
			$data['title'] = 'Beauty Salon';
		}
		if ( ! isset( $data['elements'] ) || ! is_array( $data['elements'] ) ) {
			$data['elements'] = array();
		}
		if ( ! isset( $data['services_library'] ) || ! is_array( $data['services_library'] ) ) {
			$data['services_library'] = array();
		}

		// Bump when the bundled JSON changes.
		$data['_sample_version'] = '3.2.0';

		return $data;
	}
}
