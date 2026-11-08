<?php
/**
 * Cinema auditorium sample layout — loads bundled JSON + WP seed.
 *
 * The canonical preset lives in samples/cinema-auditorium.json (exported from
 * the curated editor layout). This file applies it to the seeded sample post.
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'picky_get_cinema_layout_payload' ) ) {
	/**
	 * Load the bundled cinema auditorium layout payload.
	 *
	 * @return array<string, mixed>
	 */
	function picky_get_cinema_layout_payload() {
		$file = dirname( dirname( __DIR__ ) ) . '/samples/cinema-auditorium.json';
		if ( ! is_readable( $file ) ) {
			return array(
				'version'         => 2,
				'title'           => 'Cinema Auditorium',
				'elements'        => array(),
				'_sample_version' => '2.3.0',
			);
		}

		$raw  = file_get_contents( $file );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return array(
				'version'         => 2,
				'title'           => 'Cinema Auditorium',
				'elements'        => array(),
				'_sample_version' => '2.3.0',
			);
		}

		if ( empty( $data['title'] ) ) {
			$data['title'] = 'Cinema Auditorium';
		}
		if ( ! isset( $data['elements'] ) || ! is_array( $data['elements'] ) ) {
			$data['elements'] = array();
		}

		// Bump when the bundled JSON changes so the seeded WP post re-applies.
		$data['_sample_version'] = '2.3.0';

		return $data;
	}
}

if ( ! function_exists( 'picky_apply_cinema_layout_meta' ) ) {
	/**
	 * Write layout meta from a v2 payload onto a picky_layout post.
	 *
	 * @param int                  $layout_id Post ID.
	 * @param array<string, mixed> $payload   From picky_get_cinema_layout_payload().
	 * @return void
	 */
	function picky_apply_cinema_layout_meta( $layout_id, array $payload ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id ) {
			return;
		}

		$elements = isset( $payload['elements'] ) && is_array( $payload['elements'] )
			? $payload['elements']
			: array();

		update_post_meta( $layout_id, '_picky_layout_position', rawurlencode( wp_json_encode( $elements ) ) );
		update_post_meta( $layout_id, '_picky_layout_canvas_width', absint( $payload['canvas_width'] ?? 1200 ) );
		update_post_meta( $layout_id, '_picky_layout_canvas_height', absint( $payload['canvas_height'] ?? 820 ) );
		update_post_meta( $layout_id, '_picky_layout_transparent_canvas', (string) ( $payload['transparent_canvas'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_seats', (string) ( $payload['show_seats'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_ids', (string) ( $payload['show_ids'] ?? '1' ) );
		update_post_meta( $layout_id, '_picky_layout_show_labels', (string) ( $payload['show_labels'] ?? '0' ) );
		update_post_meta( $layout_id, '_picky_layout_show_item_fill', (string) ( $payload['show_item_fill'] ?? '1' ) );
		update_post_meta( $layout_id, '_picky_layout_multi_select', (string) ( $payload['multi_select'] ?? '1' ) );

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
		update_post_meta( $layout_id, '_picky_sample_key', 'cinema' );

		if ( ! empty( $payload['background_canvas'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas', (string) $payload['background_canvas'] );
		} else {
			delete_post_meta( $layout_id, '_picky_layout_bg_canvas' );
		}
		if ( ! empty( $payload['background_canvas_mode'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', sanitize_key( (string) $payload['background_canvas_mode'] ) );
		}
		if ( ! empty( $payload['background_canvas_color'] ) ) {
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

		$ver = isset( $payload['_sample_version'] ) ? (string) $payload['_sample_version'] : '2.2.0';
		update_post_meta( $layout_id, '_picky_sample_version', $ver );
		update_option( 'picky_sample_cinema_layout_version', $ver, false );

		if ( class_exists( '\\Picky\\Layout_Preview' ) ) {
			\Picky\Layout_Preview::refresh_svg_meta( $layout_id );
			\Picky\Layout_Preview::clear_attachment( $layout_id );
			\Picky\Layout_Preview::publish_svg_attachment( $layout_id );
		}
	}
}

if ( ! function_exists( 'picky_find_sample_post_id' ) ) {
	/**
	 * Find a post by sample key meta.
	 *
	 * @param string $post_type Post type.
	 * @param string $sample_key Meta value for _picky_sample_key.
	 * @return int
	 */
	function picky_find_sample_post_id( $post_type, $sample_key ) {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Layout seed lookup by meta
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => '_picky_sample_key',
				'meta_value'             => $sample_key,
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return absint( $query->posts[0] );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	}
}

if ( ! function_exists( 'picky_maybe_seed_cinema_layout' ) ) {
	/**
	 * Idempotently create the Cinema Auditorium layout + demo page.
	 *
	 * @param bool $force Skip the is_admin() gate (activation / CLI).
	 * @return void
	 */
	function picky_maybe_seed_cinema_layout( $force = false ) {
		if ( ! $force ) {
			if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return;
			}
		} elseif ( ! current_user_can( 'manage_options' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// Force path still requires an elevated user unless WP-CLI / CLI SAPI.
			if ( ! is_user_logged_in() ) {
				if ( PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' ) {
					return;
				}
			} elseif ( ! user_can( get_current_user_id(), 'manage_options' ) ) {
				return;
			}
		}

		$payload   = picky_get_cinema_layout_payload();
		$layout_id = picky_find_sample_post_id( 'picky_layout', 'cinema' );
		$want_ver  = isset( $payload['_sample_version'] ) ? (string) $payload['_sample_version'] : '2.2.0';

		if ( ! $layout_id ) {
			$layout_id = wp_insert_post(
				array(
					'post_title'  => isset( $payload['title'] ) ? (string) $payload['title'] : 'Cinema Auditorium',
					'post_type'   => 'picky_layout',
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $layout_id ) || ! $layout_id ) {
				return;
			}

			$layout_id = absint( $layout_id );
			picky_apply_cinema_layout_meta( $layout_id, $payload );
			update_option( 'picky_sample_cinema_layout_id', $layout_id, false );
		} else {
			$have_ver = (string) get_post_meta( $layout_id, '_picky_sample_version', true );
			if ( $have_ver !== $want_ver ) {
				picky_apply_cinema_layout_meta( $layout_id, $payload );
			}
			update_option( 'picky_sample_cinema_layout_id', $layout_id, false );
		}

		// Sold plugin must not auto-create Pages. Layout CPT only.
		return;

		$page_id   = picky_find_sample_post_id( 'page', 'cinema_page' );
		$shortcode = '[pickybooking id="' . $layout_id . '"]';

		if ( ! $page_id ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => 'Cinema Demo',
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => $shortcode . "\n",
				),
				true
			);

			if ( is_wp_error( $page_id ) || ! $page_id ) {
				return;
			}

			$page_id = absint( $page_id );
			update_post_meta( $page_id, '_picky_sample_key', 'cinema_page' );
			update_option( 'picky_sample_cinema_page_id', $page_id, false );
			return;
		}

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
		update_option( 'picky_sample_cinema_page_id', $page_id, false );
	}
}
