<?php
/**
 * Layout catalog — bundled presets + user layouts for the Layouts tab gallery.
 *
 * @package PickyBooking
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog and import helpers for layouts.
 */
class Layout_Library {

	/**
	 * Bundled preset definitions (JSON under samples/).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function bundled_presets() {
		$dir = trailingslashit( dirname( dirname( __FILE__ ) ) ) . 'samples/';

		$presets = array(
			array(
				'key'          => 'restaurant',
				'title'        => __( 'Restaurant', 'pickybooking' ),
				'description'  => __( 'Dining floor with tables, booths, and bar zones — ready for table reservations.', 'pickybooking' ),
				'file'         => $dir . 'restaurant.json',
				'preview_bg'   => '#e8f0f8',
				'preview_item' => '#7eb0d9',
				'preview_id'   => 'R1',
			),
			array(
				'key'          => 'wedding',
				'title'        => __( 'Wedding Ceremony', 'pickybooking' ),
				'description'  => __( 'Ceremony seat map with aisle, altar party, and guest grids — great for showing booked avatars.', 'pickybooking' ),
				'file'         => $dir . 'wedding-ceremony.json',
				'preview_bg'   => '#ebe4ea',
				'preview_item' => '#c9b5c4',
				'preview_id'   => 'A1',
			),
			array(
				'key'          => 'football_match',
				'title'        => __( 'Football Match Player Booking', 'pickybooking' ),
				'description'  => __( 'Pitch layout with player positions — book spots for a match or training session.', 'pickybooking' ),
				'file'         => $dir . 'football-match-player-booking.json',
				'preview_bg'   => '#e4f0e6',
				'preview_item' => '#6db87a',
				'preview_id'   => 'P1',
			),
			array(
				'key'          => 'beauty_salon',
				'title'        => __( 'Beauty Salon', 'pickybooking' ),
				'description'  => __( 'Nail stations, styling, spa beds, and private rooms — with services bound by zone.', 'pickybooking' ),
				'file'         => $dir . 'beauty-salon.json',
				'preview_bg'   => '#f4eef2',
				'preview_item' => '#c9b0bc',
				'preview_id'   => 'S1',
			),
			array(
				'key'          => 'cinema',
				'title'        => __( 'Cinema Auditorium', 'pickybooking' ),
				'description'  => __( 'Seat map with walls and centre aisles — ideal for theaters and cinemas.', 'pickybooking' ),
				'file'         => $dir . 'cinema-auditorium.json',
				'preview_bg'   => '#efeaf8',
				'preview_item' => '#c5bedc',
				'preview_id'   => 'A1',
			),
			array(
				'key'          => 'coworking',
				'title'        => __( 'Coworking', 'pickybooking' ),
				'description'  => __( 'Hot desks, private offices, and meeting rooms in a soft sage palette.', 'pickybooking' ),
				'file'         => $dir . 'coworking-floor.json',
				'preview_bg'   => '#eef5f1',
				'preview_item' => '#7ebfa0',
				'preview_id'   => 'D1',
			),
			array(
				'key'          => 'ev_charging',
				'title'        => __( 'EV Charging Station', 'pickybooking' ),
				'description'  => __( 'Charging bays with driveway cues — EV / parking floor plan.', 'pickybooking' ),
				'file'         => $dir . 'ev-charging-station.json',
				'preview_bg'   => '#e8f4f8',
				'preview_item' => '#7ec8d9',
				'preview_id'   => 'A1',
			),
		);

		/**
		 * Filter bundled layout library presets.
		 *
		 * @param array<int, array<string, string>> $presets Preset definitions.
		 */
		return apply_filters( 'picky_layout_library_bundled_presets', $presets );
	}

	/**
	 * Layout post IDs used to regenerate bundled preset JSON (admin/dev sync).
	 *
	 * @return array<string, int> preset key => layout post ID.
	 */
	public static function preset_source_layout_ids() {
		return array(
			'restaurant'     => 348,
			'wedding'        => 3065,
			'football_match' => 326,
			'beauty_salon'   => 227,
			'cinema'         => 290,
			'coworking'      => 203,
			'ev_charging'    => 296,
		);
	}

	/**
	 * Decode stored layout elements from post meta.
	 *
	 * @param mixed $raw Post meta value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function decode_stored_layout_elements( $raw ) {
		if ( $raw === null || $raw === '' ) {
			return array();
		}
		$s   = (string) $raw;
		$try = array_values( array_unique( array( $s, rawurldecode( $s ), urldecode( $s ) ) ) );
		foreach ( $try as $candidate ) {
			$data = json_decode( (string) $candidate, true );
			if ( is_array( $data ) ) {
				$out = array();
				foreach ( $data as $el ) {
					if ( is_array( $el ) ) {
						$out[] = $el;
					}
				}
				return $out;
			}
		}
		return array();
	}

	/**
	 * Export a picky_layout post to a v2 JSON preset file.
	 *
	 * @param int    $layout_id Layout post ID.
	 * @param string $file_path Absolute path to write.
	 * @return true|\WP_Error
	 */
	public static function export_layout_to_json_file( $layout_id, $file_path ) {
		$layout_id = absint( $layout_id );
		if ( $layout_id <= 0 || get_post_type( $layout_id ) !== 'picky_layout' ) {
			return new \WP_Error( 'picky_export_layout', __( 'Invalid layout.', 'pickybooking' ) );
		}

		$defaults = Info::get_settings();
		$canvas_w = (int) get_post_meta( $layout_id, '_picky_layout_canvas_width', true );
		$canvas_h = (int) get_post_meta( $layout_id, '_picky_layout_canvas_height', true );
		if ( $canvas_w <= 0 ) {
			$canvas_w = max( 500, min( 2500, (int) $defaults['canvas_width'] ) );
		} else {
			$canvas_w = max( 500, min( 2500, $canvas_w ) );
		}
		if ( $canvas_h <= 0 ) {
			$canvas_h = max( 500, min( 2500, (int) $defaults['canvas_height'] ) );
		} else {
			$canvas_h = max( 500, min( 2500, $canvas_h ) );
		}

		$services_lib = array();
		$services_raw = get_post_meta( $layout_id, '_picky_layout_services_library', true );
		if ( is_string( $services_raw ) && $services_raw !== '' ) {
			$decoded_services = json_decode( $services_raw, true );
			if ( is_array( $decoded_services ) ) {
				$services_lib = $decoded_services;
			}
		} elseif ( is_array( $services_raw ) ) {
			$services_lib = $services_raw;
		}

		$raw_canvas = get_post_meta( $layout_id, '_picky_layout_bg_canvas', true );
		$raw_table  = get_post_meta( $layout_id, '_picky_layout_bg_table', true );
		$bg_canvas  = is_numeric( $raw_canvas ) ? (string) wp_get_attachment_url( (int) $raw_canvas ) : trim( (string) $raw_canvas );
		$bg_table   = is_numeric( $raw_table ) ? (string) wp_get_attachment_url( (int) $raw_table ) : trim( (string) $raw_table );

		$export = array(
			'version'                => 2,
			'title'                  => (string) get_the_title( $layout_id ),
			'canvas_width'           => $canvas_w,
			'canvas_height'          => $canvas_h,
			'background_canvas'      => is_string( $bg_canvas ) ? $bg_canvas : '',
			'background_table'       => is_string( $bg_table ) ? $bg_table : '',
			'background_canvas_mode' => (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', true ),
			'background_canvas_color'=> (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas_color', true ),
			'background_table_color' => (string) get_post_meta( $layout_id, '_picky_layout_bg_table_color', true ),
			'transparent_canvas'     => ( get_post_meta( $layout_id, '_picky_layout_transparent_canvas', true ) === '1' ) ? '1' : '0',
			'show_seats'             => (string) get_post_meta( $layout_id, '_picky_layout_show_seats', true ),
			'show_ids'               => (string) get_post_meta( $layout_id, '_picky_layout_show_ids', true ),
			'show_labels'            => (string) get_post_meta( $layout_id, '_picky_layout_show_labels', true ),
			'show_item_fill'         => (string) get_post_meta( $layout_id, '_picky_layout_show_item_fill', true ),
			'show_booked_avatars'    => (string) get_post_meta( $layout_id, '_picky_layout_show_booked_avatars', true ),
			'shortcode_layout'       => (string) get_post_meta( $layout_id, '_picky_layout_shortcode_layout', true ),
			'multi_select'           => (string) get_post_meta( $layout_id, '_picky_layout_multi_select', true ),
			'event_mode'             => (string) get_post_meta( $layout_id, '_picky_layout_event_mode', true ),
			'event_date'             => (string) get_post_meta( $layout_id, '_picky_layout_event_date', true ),
			'event_time'             => (string) get_post_meta( $layout_id, '_picky_layout_event_time', true ),
			'event_show_label'       => (string) get_post_meta( $layout_id, '_picky_layout_event_show_label', true ),
			'booking_item_icon'      => Booking_Icons::sanitize( (string) get_post_meta( $layout_id, Booking_Icons::META_KEY, true ) ),
			'services_library'       => $services_lib,
			'elements'               => self::decode_stored_layout_elements( get_post_meta( $layout_id, '_picky_layout_position', true ) ),
			'source_post_id'         => $layout_id,
		);

		if ( $export['background_canvas'] === '' ) {
			unset( $export['background_canvas'] );
		}
		if ( $export['background_table'] === '' ) {
			unset( $export['background_table'] );
		}
		if ( $export['background_canvas_mode'] === '' ) {
			unset( $export['background_canvas_mode'] );
		}
		if ( $export['background_canvas_color'] === '' ) {
			unset( $export['background_canvas_color'] );
		}
		if ( $export['background_table_color'] === '' ) {
			unset( $export['background_table_color'] );
		}

		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || $json === '' ) {
			return new \WP_Error( 'picky_export_json', __( 'Could not encode layout as JSON.', 'pickybooking' ) );
		}

		$dir = dirname( $file_path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'picky_export_dir', __( 'Could not create preset directory.', 'pickybooking' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file_path, $json ) ) {
			return new \WP_Error( 'picky_export_write', __( 'Could not write preset file.', 'pickybooking' ) );
		}

		return true;
	}

	/**
	 * Regenerate all bundled preset JSON files from live layout posts.
	 *
	 * @return array<string, true|\WP_Error>
	 */
	public static function sync_bundled_presets_from_layouts() {
		$results = array();
		$dir     = trailingslashit( dirname( dirname( __FILE__ ) ) ) . 'samples/';
		foreach ( self::bundled_presets() as $preset ) {
			$key  = isset( $preset['key'] ) ? (string) $preset['key'] : '';
			$file = isset( $preset['file'] ) ? (string) $preset['file'] : '';
			if ( $key === '' || $file === '' ) {
				continue;
			}
			$sources = self::preset_source_layout_ids();
			if ( ! isset( $sources[ $key ] ) ) {
				continue;
			}
			$results[ $key ] = self::export_layout_to_json_file( (int) $sources[ $key ], $file );
		}
		unset( $dir );
		return $results;
	}

	/**
	 * Guess soft preview colors from a layout post (bg meta / first seat color).
	 *
	 * @param int $layout_id Layout post ID.
	 * @return array{bg:string,item:string}
	 */
	public static function preview_colors_for_layout( $layout_id ) {
		$bg   = '#efeaf8';
		$item = '#c5bedc';

		$els = Layout_Preview::decode_elements( get_post_meta( $layout_id, '_picky_layout_position', true ) );

		foreach ( $els as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$color = isset( $el['color'] ) ? (string) $el['color'] : '';
			if ( $class === 'type-shape' && isset( $el['unique'] ) && strpos( (string) $el['unique'], 'floor' ) !== false && $color !== '' ) {
				$bg = $color;
			}
			if ( in_array( $class, array( 'type-1', 'type-2', 'type-3', 'type-4', 'type-6', 'type-square', 'type-circle', 'type-table' ), true ) && $color !== '' ) {
				$item = $color;
				break;
			}
		}

		return array(
			'bg'   => $bg,
			'item' => $item,
		);
	}

	/**
	 * Bundled keys used to hide seeded sample posts from the user list.
	 *
	 * @return array<int, string>
	 */
	public static function bundled_keys() {
		$keys = array();
		foreach ( self::bundled_presets() as $preset ) {
			if ( ! empty( $preset['key'] ) ) {
				$keys[] = (string) $preset['key'];
			}
		}
		return $keys;
	}

	/**
	 * Whether a layout post is an old demo seed hidden from “Your layouts” / nav.
	 *
	 * @param int $layout_id Layout post ID.
	 * @return bool
	 */
	public static function is_seeded_sample_layout( $layout_id ) {
		$layout_id  = absint( $layout_id );
		$sample_key = (string) get_post_meta( $layout_id, '_picky_sample_key', true );
		return $sample_key !== '' && in_array( $sample_key, self::bundled_keys(), true );
	}

	/**
	 * User-owned layouts (same filter as the gallery), as WP_Post objects.
	 *
	 * @param string $orderby WP_Query orderby field.
	 * @param string $order   ASC or DESC.
	 * @return array<int, \WP_Post>
	 */
	public static function get_user_layout_posts( $orderby = 'title', $order = 'ASC' ) {
		$layouts = get_posts(
			array(
				'post_type'      => 'picky_layout',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => $orderby,
				'order'          => $order,
			)
		);
		$out     = array();
		foreach ( $layouts as $post ) {
			if ( self::is_seeded_sample_layout( (int) $post->ID ) ) {
				continue;
			}
			$out[] = $post;
		}
		return $out;
	}

	/**
	 * User (saved) layout cards — excludes seeded sample posts covered by presets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_user_items() {
		$items = array();

		foreach ( self::get_user_layout_posts( 'modified', 'DESC' ) as $post ) {
			$lid = (int) $post->ID;
			$colors = self::preview_colors_for_layout( $lid );
			$title  = get_the_title( $post );
			if ( $title === '' ) {
				/* translators: %d: layout post ID */
				$title = sprintf( __( 'Layout #%d', 'pickybooking' ), $lid );
			}
			$items[] = array(
				'id'           => 'layout:' . $lid,
				'source'       => 'layout',
				'key'          => '',
				'title'        => $title,
				'description'  => '',
				'preview_bg'   => $colors['bg'],
				'preview_item' => $colors['item'],
				'preview_id'   => 'A1',
				'preview_url'  => Layout_Preview::url_for_layout( $lid ),
				'file'         => '',
				'layout_id'    => $lid,
				'edit_url'     => self::builder_url( $lid ),
				'delete_url'   => (string) wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'picky_delete_layout',
							'layout_id' => $lid,
						),
						admin_url( 'admin-post.php' )
					),
					'picky_delete_layout_' . $lid
				),
				'duplicate_url' => (string) wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'picky_duplicate_layout',
							'layout_id' => $lid,
						),
						admin_url( 'admin-post.php' )
					),
					'picky_duplicate_layout_' . $lid
				),
			);
		}

		return $items;
	}

	/**
	 * Bundled preset cards.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_preset_items() {
		$items = array();

		foreach ( self::bundled_presets() as $preset ) {
			$file        = isset( $preset['file'] ) ? (string) $preset['file'] : '';
			$php_payload = isset( $preset['php_payload'] ) ? (string) $preset['php_payload'] : '';
			$has_file    = ( $file !== '' && is_readable( $file ) );
			$has_php     = ( $php_payload !== '' && function_exists( $php_payload ) );
			if ( ! $has_file && ! $has_php ) {
				continue;
			}
			$bg = isset( $preset['preview_bg'] ) ? (string) $preset['preview_bg'] : '#efeaf8';
			$preview_url = '';
			if ( $has_file && class_exists( '\\Picky\\Layout_Preview' ) ) {
				$preview_url = Layout_Preview::url_for_json_file( $file, $bg );
			}
			$items[] = array(
				'id'           => 'bundled:' . (string) $preset['key'],
				'source'       => 'bundled',
				'key'          => (string) $preset['key'],
				'title'        => (string) $preset['title'],
				'description'  => isset( $preset['description'] ) ? (string) $preset['description'] : '',
				'preview_bg'   => $bg,
				'preview_item' => isset( $preset['preview_item'] ) ? (string) $preset['preview_item'] : '#c5bedc',
				'preview_id'   => isset( $preset['preview_id'] ) ? (string) $preset['preview_id'] : 'A1',
				'preview_url'  => $preview_url,
				'file'         => $has_file ? $file : '',
				'php_payload'  => $php_payload,
				'layout_id'    => 0,
				'edit_url'     => '',
			);
		}

		return $items;
	}

	/**
	 * All catalog cards: user layouts first, then presets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_items() {
		return array_merge( self::get_user_items(), self::get_preset_items() );
	}

	/**
	 * Builder URL for a layout.
	 *
	 * @param int $layout_id Layout ID.
	 * @return string
	 */
	public static function builder_url( $layout_id ) {
		$layout_id = absint( $layout_id );
		return admin_url(
			add_query_arg(
				array(
					'page'      => 'picky_settings',
					'picky-tab'   => '9',
					'layout_id' => $layout_id,
				),
				'admin.php'
			)
		);
	}

	/**
	 * Resolve a library item by id (bundled:key or layout:ID).
	 *
	 * @param string $item_id Library item id.
	 * @return array<string, mixed>|null
	 */
	public static function get_item( $item_id ) {
		$item_id = sanitize_text_field( (string) $item_id );
		foreach ( self::get_items() as $item ) {
			if ( isset( $item['id'] ) && (string) $item['id'] === $item_id ) {
				return $item;
			}
		}
		// Allow importing a bundled preset even if get_items filters oddly.
		if ( strpos( $item_id, 'bundled:' ) === 0 ) {
			$key = substr( $item_id, 8 );
			foreach ( self::get_preset_items() as $item ) {
				if ( isset( $item['key'] ) && (string) $item['key'] === $key ) {
					return $item;
				}
			}
		}
		if ( strpos( $item_id, 'layout:' ) === 0 ) {
			$lid = absint( substr( $item_id, 7 ) );
			if ( $lid && get_post_type( $lid ) === 'picky_layout' ) {
				return array(
					'id'        => 'layout:' . $lid,
					'source'    => 'layout',
					'layout_id' => $lid,
					'title'     => get_the_title( $lid ),
					'file'      => '',
				);
			}
		}
		return null;
	}

	/**
	 * Create a new picky_layout from a library item (JSON preset or clone).
	 *
	 * @param string $item_id Library item id.
	 * @return int|\WP_Error New layout ID or error.
	 */
	public static function import_item( $item_id ) {
		$item = self::get_item( $item_id );
		if ( ! $item ) {
			return new \WP_Error( 'picky_library_missing', __( 'Layout preset not found.', 'pickybooking' ) );
		}

		if ( (string) $item['source'] === 'bundled' ) {
			$php_fn = isset( $item['php_payload'] ) ? (string) $item['php_payload'] : '';
			if ( $php_fn !== '' && function_exists( $php_fn ) && (string) ( $item['key'] ?? '' ) === 'wedding' ) {
				// Import the wedding payload as a new layout. Do not seed a demo Page.
			}
			$file = (string) ( $item['file'] ?? '' );
			if ( $file !== '' ) {
				return self::import_from_json_file( $file, (string) $item['title'] );
			}
			if ( $php_fn !== '' && function_exists( $php_fn ) ) {
				$payload = call_user_func( $php_fn );
				if ( ! is_array( $payload ) || empty( $payload['elements'] ) ) {
					return new \WP_Error( 'picky_library_empty', __( 'No layout elements found in the preset.', 'pickybooking' ) );
				}
				// Write a temporary import via apply helper when available.
				if ( (string) ( $item['key'] ?? '' ) === 'wedding' && function_exists( 'picky_apply_wedding_layout_meta' ) ) {
					$layout_id = wp_insert_post(
						array(
							'post_title'  => (string) $item['title'],
							'post_type'   => 'picky_layout',
							'post_status' => 'publish',
						),
						true
					);
					if ( is_wp_error( $layout_id ) ) {
						return $layout_id;
					}
					$layout_id = absint( $layout_id );
					picky_apply_wedding_layout_meta( $layout_id, $payload );
					delete_post_meta( $layout_id, '_picky_sample_key' );
					return $layout_id;
				}
			}
			return new \WP_Error( 'picky_library_file', __( 'Preset file is not readable.', 'pickybooking' ) );
		}

		$source_id = absint( $item['layout_id'] );
		if ( ! $source_id || get_post_type( $source_id ) !== 'picky_layout' ) {
			return new \WP_Error( 'picky_library_missing', __( 'Source layout not found.', 'pickybooking' ) );
		}

		return self::clone_layout( $source_id );
	}

	/**
	 * Import a layout from a JSON file path (v2 payload).
	 *
	 * @param string $file  Absolute path.
	 * @param string $title Optional title override.
	 * @return int|\WP_Error
	 */
	public static function import_from_json_file( $file, $title = '' ) {
		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'picky_library_file', __( 'Preset file is not readable.', 'pickybooking' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $file );
		if ( ! is_string( $json ) || $json === '' ) {
			return new \WP_Error( 'picky_library_file', __( 'Preset file is empty.', 'pickybooking' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'picky_library_json', __( 'Preset JSON is invalid.', 'pickybooking' ) );
		}

		if ( $title === '' && isset( $decoded['title'] ) ) {
			$title = sanitize_text_field( (string) $decoded['title'] );
		}
		if ( $title === '' ) {
			$title = __( 'Imported Layout', 'pickybooking' );
		}

		$elements = array();
		if ( isset( $decoded['elements'] ) && is_array( $decoded['elements'] ) ) {
			$elements = $decoded['elements'];
		} elseif ( array_values( $decoded ) === $decoded ) {
			$elements = $decoded;
		}
		if ( empty( $elements ) ) {
			return new \WP_Error( 'picky_library_empty', __( 'No layout elements found in the preset.', 'pickybooking' ) );
		}

		$layout_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'picky_layout',
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $layout_id ) ) {
			return $layout_id;
		}
		$layout_id = absint( $layout_id );

		update_post_meta( $layout_id, '_picky_layout_position', rawurlencode( wp_json_encode( $elements ) ) );
		update_post_meta( $layout_id, '_picky_layout_canvas_width', isset( $decoded['canvas_width'] ) ? absint( $decoded['canvas_width'] ) : 1100 );
		update_post_meta( $layout_id, '_picky_layout_canvas_height', isset( $decoded['canvas_height'] ) ? absint( $decoded['canvas_height'] ) : 900 );

		$flags = array(
			'transparent_canvas' => '_picky_layout_transparent_canvas',
			'show_seats'         => '_picky_layout_show_seats',
			'show_ids'           => '_picky_layout_show_ids',
			'show_labels'        => '_picky_layout_show_labels',
			'show_item_fill'     => '_picky_layout_show_item_fill',
			'show_booked_avatars'=> '_picky_layout_show_booked_avatars',
			'multi_select'       => '_picky_layout_multi_select',
			'shortcode_layout'   => '_picky_layout_shortcode_layout',
			'event_mode'         => '_picky_layout_event_mode',
			'event_date'         => '_picky_layout_event_date',
			'event_time'         => '_picky_layout_event_time',
			'event_show_label'   => '_picky_layout_event_show_label',
			'booking_item_icon'  => Booking_Icons::META_KEY,
		);
		foreach ( $flags as $key => $meta_key ) {
			if ( ! isset( $decoded[ $key ] ) || (string) $decoded[ $key ] === '' ) {
				continue;
			}
			if ( $key === 'booking_item_icon' ) {
				$icon = Booking_Icons::sanitize( $decoded[ $key ] );
				if ( $icon !== '' ) {
					update_post_meta( $layout_id, $meta_key, $icon );
				}
				continue;
			}
			update_post_meta( $layout_id, $meta_key, sanitize_text_field( (string) $decoded[ $key ] ) );
		}

		if ( ! empty( $decoded['background_canvas'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas', (string) $decoded['background_canvas'] );
		}
		if ( ! empty( $decoded['background_canvas_mode'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', sanitize_key( (string) $decoded['background_canvas_mode'] ) );
		}
		if ( ! empty( $decoded['background_canvas_color'] ) ) {
			$c = picky_sanitize_hex_color( (string) $decoded['background_canvas_color'] );
			if ( is_string( $c ) && $c !== '' ) {
				update_post_meta( $layout_id, '_picky_layout_bg_canvas_color', $c );
			}
		}
		if ( ! empty( $decoded['background_table_color'] ) ) {
			$c = picky_sanitize_hex_color( (string) $decoded['background_table_color'] );
			if ( is_string( $c ) && $c !== '' ) {
				update_post_meta( $layout_id, '_picky_layout_bg_table_color', $c );
			}
		}
		if ( isset( $decoded['services_library'] ) && is_array( $decoded['services_library'] ) ) {
			update_post_meta( $layout_id, '_picky_layout_services_library', wp_json_encode( $decoded['services_library'] ) );
		} else {
			update_post_meta( $layout_id, '_picky_layout_services_library', wp_json_encode( array() ) );
		}

		update_post_meta( $layout_id, '_picky_from_library', '1' );
		Layout_Preview::refresh_svg_meta( $layout_id );

		return $layout_id;
	}

	/**
	 * Duplicate an existing layout post (deep copy of layout meta).
	 *
	 * @param int $source_id Source layout ID.
	 * @return int|\WP_Error
	 */
	public static function clone_layout( $source_id ) {
		$source_id = absint( $source_id );
		$source    = get_post( $source_id );
		if ( ! $source || $source->post_type !== 'picky_layout' ) {
			return new \WP_Error( 'picky_library_missing', __( 'Source layout not found.', 'pickybooking' ) );
		}

		/* translators: %s: original layout title */
		$new_title = sprintf( __( 'Copy of %s', 'pickybooking' ), $source->post_title );

		$layout_id = wp_insert_post(
			array(
				'post_title'  => $new_title,
				'post_type'   => 'picky_layout',
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $layout_id ) ) {
			return $layout_id;
		}
		$layout_id = absint( $layout_id );

		$meta_keys = array(
			'_picky_layout_position',
			'_picky_layout_canvas_width',
			'_picky_layout_canvas_height',
			'_picky_layout_bg_canvas',
			'_picky_layout_bg_table',
			'_picky_layout_bg_canvas_mode',
			'_picky_layout_bg_canvas_color',
			'_picky_layout_bg_table_color',
			'_picky_layout_transparent_canvas',
			'_picky_layout_show_seats',
			'_picky_layout_show_ids',
			'_picky_layout_show_labels',
			'_picky_layout_show_item_fill',
			'_picky_layout_show_booked_avatars',
			'_picky_layout_multi_select',
			'_picky_layout_event_mode',
			'_picky_layout_event_date',
			'_picky_layout_event_time',
			'_picky_layout_event_show_label',
			'_picky_layout_shortcode_layout',
			'_picky_layout_services_library',
			Booking_Icons::META_KEY,
			Layout_Preview::META_SVG,
		);

		foreach ( $meta_keys as $key ) {
			$val = get_post_meta( $source_id, $key, true );
			if ( $val === '' || $val === null ) {
				continue;
			}
			update_post_meta( $layout_id, $key, $val );
		}

		// Prefer a fresh SVG; copy screenshot attachment ID only as temporary thumb.
		$preview_id = (int) get_post_meta( $source_id, Layout_Preview::META_ATTACHMENT, true );
		if ( $preview_id > 0 ) {
			update_post_meta( $layout_id, Layout_Preview::META_ATTACHMENT, $preview_id );
		}
		Layout_Preview::refresh_svg_meta( $layout_id );

		update_post_meta( $layout_id, '_picky_from_library', '1' );
		update_post_meta( $layout_id, '_picky_cloned_from', $source_id );

		return $layout_id;
	}

	/**
	 * Layouts tab URL (gallery). Optional hash to jump to presets.
	 *
	 * @param string $hash Optional fragment without #.
	 * @return string
	 */
	public static function library_url( $hash = 'picky-layout-presets' ) {
		$url = admin_url(
			add_query_arg(
				array(
					'page'    => 'picky_settings',
					'picky-tab' => '9',
				),
				'admin.php'
			)
		);
		if ( is_string( $hash ) && $hash !== '' ) {
			$url .= '#' . ltrim( $hash, '#' );
		}
		return $url;
	}
}
