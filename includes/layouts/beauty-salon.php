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

		// Preview URLs for relative samples/… paths (install materializes into Media Library).
		$plugin_root = dirname( dirname( __DIR__ ) );
		$plugin_url  = plugins_url( '/', $plugin_root . '/pickybooking.php' );
		foreach ( $data['services_library'] as &$svc ) {
			if ( ! is_array( $svc ) ) {
				continue;
			}
			$url = isset( $svc['image_url'] ) ? trim( (string) $svc['image_url'] ) : '';
			if ( $url === '' || preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', $url ), '/' );
			if ( strpos( $rel, 'samples/' ) !== 0 ) {
				continue;
			}
			if ( is_readable( $plugin_root . '/' . $rel ) ) {
				$svc['visual']      = 'image';
				$svc['image_url']   = $plugin_url . $rel;
				$svc['image_ratio'] = ! empty( $svc['image_ratio'] ) ? (string) $svc['image_ratio'] : 'square';
				$svc['image_id']    = 0;
				$svc['icon']        = '';
			}
		}
		unset( $svc );

		// Bump when the bundled JSON / service images change.
		$data['_sample_version'] = '3.4.0';

		return $data;
	}
}
