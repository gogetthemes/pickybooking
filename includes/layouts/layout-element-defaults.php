<?php
/**
 * Shared defaults for sample / preset layout elements.
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'picky_layout_base_element' ) ) {
	/**
	 * Shared defaults for a layout element.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	function picky_layout_base_element( array $overrides ) {
		$defaults = array(
			'x'                  => 0,
			'y'                  => 0,
			'x1'                 => 100,
			'y1'                 => 100,
			'id'                 => '',
			'unique'             => '',
			'class'              => 'type-1',
			'rotate'             => '1',
			'rotate_z'           => '0',
			'rotate_x'           => '0',
			'rotate_y'           => '0',
			'big_side'           => 'w',
			'seats'              => '1',
			'max_seats'          => '1',
			'label'              => '',
			'label_position'     => 'bottom',
			'label_font_size'    => '12',
			'label_color'        => '#333333',
			'label_font_weight'  => 'normal',
			'show_id'            => '1',
			'custom_id'          => '',
			'custom_url'         => '',
			'color'              => '',
			'opacity'            => '1',
			'text'               => '',
			'text_color'         => '#333333',
			'text_color_mode'    => 'custom',
			'font_size'          => '16',
			'line_height'        => '',
			'font_family'        => 'inherit',
			'font_weight'        => 'normal',
			'shape_type'         => 'rectangle',
			'shape_radius'       => '',
			'shape_border_width' => '0',
			'shape_border_color' => '',
			'icon_key'           => '',
			'square_radius'      => '',
			'padding'            => '10',
			'service_ids'        => array(),
		);

		return array_merge( $defaults, $overrides );
	}
}

if ( ! function_exists( 'picky_layout_solid_bg_uri' ) ) {
	/**
	 * Tiny SVG data-URI used as canvas background-image.
	 *
	 * @param string $hex Color (#rrggbb).
	 * @return string
	 */
	function picky_layout_solid_bg_uri( $hex ) {
		$hex = (string) $hex;
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '#eeeeee';
		}
		$bg_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect fill="' . $hex . '" width="8" height="8"/></svg>';
		return 'data:image/svg+xml,' . rawurlencode( $bg_svg );
	}
}
