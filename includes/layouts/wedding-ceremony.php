<?php
/**
 * Wedding ceremony seat-map sample — generated layout + WP seed.
 *
 * Symmetrical aisle seating (7×5 each side), altar party, and labels —
 * in the PickyBooking visual language (soft mauve seats on a cool blush canvas).
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'picky_get_wedding_layout_payload' ) ) {
	/**
	 * Build the wedding ceremony layout payload (elements generated in PHP).
	 *
	 * @return array<string, mixed>
	 */
	function picky_get_wedding_layout_payload() {
		$item_color = '#eaabc8';
		$bg_hex     = '#ebe4ea';
		$text_color = '#3a3540';
		$muted      = '#6b6570';

		$elements = array();
		$z        = 1;

		// Soft floor wash.
		$elements[] = picky_layout_base_element(
			array(
				'x'           => 40,
				'y'           => 40,
				'x1'          => 860,
				'y1'          => 1160,
				'id'          => '',
				'unique'      => 'wedding_floor',
				'class'       => 'type-shape',
				'z_index'     => $z++,
				'color'       => '#f5eef3',
				'opacity'     => '1',
				'shape_type'  => 'rectangle',
				'shape_radius'=> '24',
				'show_id'     => '0',
				'seats'       => '0',
				'max_seats'   => '0',
			)
		);

		// Title.
		$elements[] = picky_layout_base_element(
			array(
				'x'                  => 280,
				'y'                  => 56,
				'x1'                 => 620,
				'y1'                 => 96,
				'unique'             => 'wedding_title',
				'class'              => 'type-text',
				'z_index'            => $z++,
				'text'               => 'Ceremony',
				'text_color'         => $text_color,
				'text_color_mode'    => 'custom',
				'font_size'          => '28',
				'font_weight'        => '600',
				'font_family'        => 'inherit',
				'show_id'            => '0',
				'seats'              => '0',
				'max_seats'          => '0',
			)
		);
		$elements[] = picky_layout_base_element(
			array(
				'x'                  => 300,
				'y'                  => 96,
				'x1'                 => 600,
				'y1'                 => 124,
				'unique'             => 'wedding_subtitle',
				'class'              => 'type-text',
				'z_index'            => $z++,
				'text'               => 'Two hearts united!',
				'text_color'         => $muted,
				'text_color_mode'    => 'custom',
				'font_size'          => '14',
				'font_weight'        => 'normal',
				'show_id'            => '0',
				'seats'              => '0',
				'max_seats'          => '0',
			)
		);

		// Arch (decorative rounded frame).
		$elements[] = picky_layout_base_element(
			array(
				'x'                  => 360,
				'y'                  => 140,
				'x1'                 => 540,
				'y1'                 => 280,
				'unique'             => 'wedding_arch',
				'class'              => 'type-shape',
				'z_index'            => $z++,
				'color'              => '#e2d4de',
				'opacity'            => '1',
				'shape_type'         => 'rectangle',
				'shape_radius'       => '90',
				'shape_border_width' => '3',
				'shape_border_color' => '#b89bb0',
				'show_id'            => '0',
				'seats'              => '0',
				'max_seats'          => '0',
			)
		);

		// Floral / barrel clusters (decorative circles).
		foreach ( array(
			array( 250, 170, 'wedding_decor_l1' ),
			array( 220, 210, 'wedding_decor_l2' ),
			array( 270, 210, 'wedding_decor_l3' ),
			array( 620, 170, 'wedding_decor_r1' ),
			array( 590, 210, 'wedding_decor_r2' ),
			array( 650, 210, 'wedding_decor_r3' ),
		) as $d ) {
			$elements[] = picky_layout_base_element(
				array(
					'x'           => $d[0],
					'y'           => $d[1],
					'x1'          => $d[0] + 36,
					'y1'          => $d[1] + 36,
					'unique'      => $d[2],
					'class'       => 'type-shape',
					'z_index'     => $z++,
					'color'       => '#d8c6d2',
					'shape_type'  => 'circle',
					'show_id'     => '0',
					'seats'       => '0',
					'max_seats'   => '0',
				)
			);
		}

		$svc_seat = array( 'svc_ceremony_seat' );

		// Couple under the arch — Bride (B) / Groom (G).
		$couple = array(
			array( 400, 250, 'B', 'wedding_couple_bride', -8 ),
			array( 460, 250, 'G', 'wedding_couple_groom', 8 ),
		);
		foreach ( $couple as $c ) {
			$elements[] = picky_layout_base_element(
				array(
					'x'             => $c[0],
					'y'             => $c[1],
					'x1'            => $c[0] + 44,
					'y1'            => $c[1] + 44,
					'id'            => $c[2],
					'unique'        => $c[3],
					'custom_id'     => $c[2],
					'label'         => $c[2],
					'class'         => 'type-1',
					'z_index'       => $z++,
					'color'         => $item_color,
					'square_radius' => '22',
					'rotate_z'      => (string) $c[4],
					'seats'         => '1',
					'max_seats'     => '1',
					'show_id'       => '1',
					'service_ids'   => $svc_seat,
				)
			);
		}

		// Bridal party — PL1–PL3 (left, top→bottom), PR1–PR3 (right).
		$party_l = array(
			array( 300, 200, 'PL1', -28 ),
			array( 275, 245, 'PL2', -28 ),
			array( 250, 290, 'PL3', -28 ),
		);
		$party_r = array(
			array( 556, 200, 'PR1', 28 ),
			array( 581, 245, 'PR2', 28 ),
			array( 606, 290, 'PR3', 28 ),
		);
		foreach ( array_merge( $party_l, $party_r ) as $p ) {
			$elements[] = picky_layout_base_element(
				array(
					'x'             => $p[0],
					'y'             => $p[1],
					'x1'            => $p[0] + 40,
					'y1'            => $p[1] + 40,
					'id'            => $p[2],
					'unique'        => 'wedding_party_' . strtolower( $p[2] ),
					'custom_id'     => $p[2],
					'label'         => $p[2],
					'class'         => 'type-1',
					'z_index'       => $z++,
					'color'         => $item_color,
					'square_radius' => '18',
					'rotate_z'      => (string) $p[3],
					'seats'         => '1',
					'max_seats'     => '1',
					'show_id'       => '1',
					'service_ids'   => $svc_seat,
				)
			);
		}

		// Aisle label (vertical feel via stacked short text).
		$elements[] = picky_layout_base_element(
			array(
				'x'               => 420,
				'y'               => 620,
				'x1'              => 480,
				'y1'              => 700,
				'unique'          => 'wedding_aisle_label',
				'class'           => 'type-text',
				'z_index'         => $z++,
				'text'            => 'Aisle',
				'text_color'      => '#9a8f98',
				'text_color_mode' => 'custom',
				'font_size'       => '13',
				'font_weight'     => '500',
				'rotate_z'        => '-90',
				'show_id'         => '0',
				'seats'           => '0',
				'max_seats'       => '0',
			)
		);

		// Guest grids: first row only (× 5 cols each side) — kept minimal for markup demos.
		$seat_w = 42;
		$seat_h = 42;
		$gap_x  = 10;
		$gap_y  = 14;
		$rows   = 1;
		$cols   = 5;
		$left_x0  = 90;
		$right_x0 = 520;
		$grid_y0  = 380;

		for ( $r = 0; $r < $rows; $r++ ) {
			$row_letter = chr( 65 + $r ); // A–G
			// Faint row guide (decorative line via thin shape).
			$elements[] = picky_layout_base_element(
				array(
					'x'           => $left_x0 - 8,
					'y'           => $grid_y0 + $r * ( $seat_h + $gap_y ) + $seat_h + 4,
					'x1'          => $left_x0 + $cols * ( $seat_w + $gap_x ) - $gap_x + 8,
					'y1'          => $grid_y0 + $r * ( $seat_h + $gap_y ) + $seat_h + 6,
					'unique'      => 'wedding_guide_l_' . $row_letter,
					'class'       => 'type-shape',
					'z_index'     => $z++,
					'color'       => '#e0d5dc',
					'shape_type'  => 'rectangle',
					'show_id'     => '0',
					'seats'       => '0',
					'max_seats'   => '0',
					'opacity'     => '0.7',
				)
			);
			$elements[] = picky_layout_base_element(
				array(
					'x'           => $right_x0 - 8,
					'y'           => $grid_y0 + $r * ( $seat_h + $gap_y ) + $seat_h + 4,
					'x1'          => $right_x0 + $cols * ( $seat_w + $gap_x ) - $gap_x + 8,
					'y1'          => $grid_y0 + $r * ( $seat_h + $gap_y ) + $seat_h + 6,
					'unique'      => 'wedding_guide_r_' . $row_letter,
					'class'       => 'type-shape',
					'z_index'     => $z++,
					'color'       => '#e0d5dc',
					'shape_type'  => 'rectangle',
					'show_id'     => '0',
					'seats'       => '0',
					'max_seats'   => '0',
					'opacity'     => '0.7',
				)
			);

			for ( $c = 0; $c < $cols; $c++ ) {
				/* L1–L5 left→right; R1–R5 aisle→outer (reading order facing the altar). */
				$id_l = 'L' . ( $c + 1 );
				$id_r = 'R' . ( $c + 1 );
				$x_l  = $left_x0 + $c * ( $seat_w + $gap_x );
				$x_r  = $right_x0 + $c * ( $seat_w + $gap_x );
				$y    = $grid_y0 + $r * ( $seat_h + $gap_y );

				foreach (
					array(
						array( $x_l, $id_l, 'wedding_seat_' . strtolower( $id_l ) ),
						array( $x_r, $id_r, 'wedding_seat_' . strtolower( $id_r ) ),
					) as $seat
				) {
					$elements[] = picky_layout_base_element(
						array(
							'x'             => $seat[0],
							'y'             => $y,
							'x1'            => $seat[0] + $seat_w,
							'y1'            => $y + $seat_h,
							'id'            => $seat[1],
							'unique'        => $seat[2],
							'custom_id'     => $seat[1],
							'label'         => $seat[1],
							'class'         => 'type-1',
							'z_index'       => $z++,
							'color'         => $item_color,
							'square_radius' => '14',
							'seats'         => '1',
							'max_seats'     => '1',
							'show_id'       => '1',
							'service_ids'   => $svc_seat,
						)
					);
				}
			}
		}

		return array(
			'version'                 => 2,
			'title'                   => 'Wedding Ceremony',
			'canvas_width'            => 900,
			'canvas_height'           => 1200,
			'background_canvas'       => function_exists( 'picky_layout_solid_bg_uri' ) ? picky_layout_solid_bg_uri( $bg_hex ) : '',
			'background_canvas_mode'  => 'cover',
			'background_canvas_color' => $bg_hex,
			'transparent_canvas'      => '0',
			'show_seats'              => '0',
			'show_ids'                => '1',
			'show_labels'             => '0',
			'show_item_fill'          => '1',
			'multi_select'            => '1',
			'shortcode_layout'        => '4',
			'show_booked_avatars'     => '1',
			'booking_item_icon'       => '',
			'services_library'        => array(
				array(
					'sid'         => 'svc_ceremony_seat',
					'name'        => 'Ceremony Seat',
					'price'       => 0,
					'duration'    => 180,
					'description' => 'Reserved seat for the ceremony.',
					'parent_sids' => array(),
				),
				array(
					'sid'         => 'svc_addon_program',
					'name'        => 'Printed Program',
					'price'       => 5,
					'duration'    => 1,
					'description' => 'Keepsake ceremony program.',
					'parent_sids' => array( 'svc_ceremony_seat' ),
				),
			),
			'elements'                => $elements,
			'_sample_version'         => '1.3.0',
		);
	}
}

if ( ! function_exists( 'picky_apply_wedding_layout_meta' ) ) {
	/**
	 * Write wedding layout meta onto a picky_layout post.
	 *
	 * @param int                  $layout_id Post ID.
	 * @param array<string, mixed> $payload   From picky_get_wedding_layout_payload().
	 * @return void
	 */
	function picky_apply_wedding_layout_meta( $layout_id, array $payload ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id ) {
			return;
		}

		$elements = isset( $payload['elements'] ) && is_array( $payload['elements'] )
			? $payload['elements']
			: array();

		update_post_meta( $layout_id, '_picky_layout_position', rawurlencode( wp_json_encode( $elements ) ) );
		update_post_meta( $layout_id, '_picky_layout_canvas_width', absint( $payload['canvas_width'] ?? 900 ) );
		update_post_meta( $layout_id, '_picky_layout_canvas_height', absint( $payload['canvas_height'] ?? 1200 ) );
		update_post_meta( $layout_id, '_picky_layout_transparent_canvas', (string) ( $payload['transparent_canvas'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_seats', (string) ( $payload['show_seats'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_ids', (string) ( $payload['show_ids'] ?? '1' ) );
		update_post_meta( $layout_id, '_picky_layout_show_labels', (string) ( $payload['show_labels'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_item_fill', (string) ( $payload['show_item_fill'] ?? '1' ) );
		update_post_meta( $layout_id, '_picky_layout_multi_select', (string) ( $payload['multi_select'] ?? '1' ) );

		/*
		 * Preserve operator-chosen wizard / avatar flags on sample re-apply.
		 * Otherwise admin_init seed (version bump) silently undoes Save Layout.
		 */
		$existing_wizard  = (string) get_post_meta( $layout_id, '_picky_layout_shortcode_layout', true );
		$existing_avatars = (string) get_post_meta( $layout_id, '_picky_layout_show_booked_avatars', true );
		$default_wizard   = (string) ( $payload['shortcode_layout'] ?? '4' );
		$default_avatars  = (string) ( $payload['show_booked_avatars'] ?? '1' );
		if ( in_array( $existing_wizard, array( '1', '2', '3', '4' ), true ) ) {
			update_post_meta( $layout_id, '_picky_layout_shortcode_layout', $existing_wizard );
		} else {
			update_post_meta( $layout_id, '_picky_layout_shortcode_layout', $default_wizard );
		}
		if ( $existing_avatars === '0' || $existing_avatars === '1' ) {
			update_post_meta( $layout_id, '_picky_layout_show_booked_avatars', $existing_avatars );
		} else {
			update_post_meta( $layout_id, '_picky_layout_show_booked_avatars', $default_avatars );
		}

		$item_icon = isset( $payload['booking_item_icon'] ) ? \Picky\Booking_Icons::sanitize( $payload['booking_item_icon'] ) : '';
		if ( $item_icon !== '' ) {
			update_post_meta( $layout_id, \Picky\Booking_Icons::META_KEY, $item_icon );
		} else {
			delete_post_meta( $layout_id, \Picky\Booking_Icons::META_KEY );
		}

		$services = isset( $payload['services_library'] ) && is_array( $payload['services_library'] )
			? $payload['services_library']
			: array();
		update_post_meta( $layout_id, '_picky_layout_services_library', wp_json_encode( $services ) );
		update_post_meta( $layout_id, '_picky_sample_key', 'wedding' );

		if ( ! empty( $payload['background_canvas'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas', (string) $payload['background_canvas'] );
		} else {
			delete_post_meta( $layout_id, '_picky_layout_bg_canvas' );
		}
		if ( ! empty( $payload['background_canvas_mode'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', sanitize_key( (string) $payload['background_canvas_mode'] ) );
		}
		if ( ! empty( $payload['background_canvas_color'] ) && function_exists( 'picky_sanitize_hex_color' ) ) {
			$canvas_color = picky_sanitize_hex_color( (string) $payload['background_canvas_color'] );
			if ( $canvas_color ) {
				update_post_meta( $layout_id, '_picky_layout_bg_canvas_color', $canvas_color );
			}
		}

		$title = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '';
		if ( $title !== '' ) {
			wp_update_post(
				array(
					'ID'         => $layout_id,
					'post_title' => $title,
				)
			);
		}

		$ver = isset( $payload['_sample_version'] ) ? (string) $payload['_sample_version'] : '1.0.0';
		update_post_meta( $layout_id, '_picky_sample_version', $ver );
		update_option( 'picky_sample_wedding_layout_version', $ver, false );

		if ( class_exists( '\\Picky\\Layout_Preview' ) ) {
			\Picky\Layout_Preview::refresh_svg_meta( $layout_id );
			\Picky\Layout_Preview::clear_attachment( $layout_id );
			\Picky\Layout_Preview::publish_svg_attachment( $layout_id );
		}
	}
}

if ( ! function_exists( 'picky_maybe_seed_wedding_layout' ) ) {
	/**
	 * Idempotently create the Wedding Ceremony layout + demo page.
	 *
	 * @param bool $force Skip the is_admin() gate (activation / CLI).
	 * @return int Layout ID (0 on failure).
	 */
	function picky_maybe_seed_wedding_layout( $force = false ) {
		if ( ! $force ) {
			if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
				return 0;
			}
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return 0;
			}
		}

		if ( ! function_exists( 'picky_find_sample_post_id' ) ) {
			return 0;
		}

		$payload   = picky_get_wedding_layout_payload();
		$layout_id = picky_find_sample_post_id( 'picky_layout', 'wedding' );
		$want_ver  = isset( $payload['_sample_version'] ) ? (string) $payload['_sample_version'] : '1.0.0';

		if ( ! $layout_id ) {
			$layout_id = wp_insert_post(
				array(
					'post_title'  => isset( $payload['title'] ) ? (string) $payload['title'] : 'Wedding Ceremony',
					'post_type'   => 'picky_layout',
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $layout_id ) || ! $layout_id ) {
				return 0;
			}
			$layout_id = absint( $layout_id );
			picky_apply_wedding_layout_meta( $layout_id, $payload );
			update_option( 'picky_sample_wedding_layout_id', $layout_id, false );
		} else {
			$have_ver = (string) get_post_meta( $layout_id, '_picky_sample_version', true );
			if ( $have_ver !== $want_ver ) {
				picky_apply_wedding_layout_meta( $layout_id, $payload );
			}
			update_option( 'picky_sample_wedding_layout_id', $layout_id, false );
		}

		// Sold plugin must not auto-create Pages. Layout CPT only.
		return $layout_id;

		$page_id   = picky_find_sample_post_id( 'page', 'wedding_page' );
		$shortcode = '[pickybooking id="' . $layout_id . '"]';

		if ( ! $page_id ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => 'Wedding Ceremony Demo Booking',
					'post_name'    => 'wedding-ceremony-demo-booking',
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => $shortcode . "\n",
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				return $layout_id;
			}
			$page_id = absint( $page_id );
			update_post_meta( $page_id, '_picky_sample_key', 'wedding_page' );
			update_option( 'picky_sample_wedding_page_id', $page_id, false );
		} else {
			$page = get_post( $page_id );
			if ( $page instanceof WP_Post ) {
				$content = (string) $page->post_content;
				/* Only inject shortcode when missing — never overwrite a user-chosen layout id. */
				if ( strpos( $content, '[pickybooking' ) === false ) {
					wp_update_post(
						array(
							'ID'           => $page_id,
							'post_content' => $shortcode . "\n",
						)
					);
				}
			}
			update_option( 'picky_sample_wedding_page_id', $page_id, false );
		}

		return $layout_id;
	}
}
