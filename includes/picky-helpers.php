<?php
/**
 * Global helper functions for the PickyBooking plugin.
 *
 * Kept in the GLOBAL namespace on purpose: callers from both `namespace Picky`
 * classes and bare partials (e.g. admin/partials/view_layout.php) need to
 * reach these helpers with a single short name.
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'picky_sanitize_hex_color' ) ) {
    /**
     * Sanitize a hex color value, accepting an optional alpha channel.
     *
     * WordPress' built-in `sanitize_hex_color()` only allows #rgb and
     * #rrggbb — it returns null for #rrggbbaa, dropping per-color opacity
     * dialed in from the inspector's color pickers. This helper accepts
     * #rgb, #rrggbb, and #rrggbbaa (hex8) so saved layouts retain alpha.
     *
     * Returns the lowercased hex on success, an empty string for an empty
     * input, or null for an invalid value (mirroring core's contract).
     *
     * @param mixed $color Raw color value.
     * @return string|null
     */
    function picky_sanitize_hex_color( $color ) {
        $color = is_string( $color ) ? trim( $color ) : '';
        if ( '' === $color ) {
            return '';
        }
        if ( preg_match( '/^#(?:[A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color ) ) {
            return strtolower( $color );
        }
        return null;
    }
}

if ( ! function_exists( 'picky_default_canvas_bg_color' ) ) {
	/**
	 * Default canvas plate color (matches .picky-draggable-wrap).
	 *
	 * @return string
	 */
	function picky_default_canvas_bg_color() {
		return '#f1f1f1';
	}
}

if ( ! function_exists( 'picky_default_item_bg_color' ) ) {
	/**
	 * Default bookable-item fill when no per-item color is set.
	 *
	 * @return string
	 */
	function picky_default_item_bg_color() {
		if ( class_exists( '\Picky\Info' ) && isset( \Picky\Info::$DEFAULT_OPTIONS['color_main_tables'] ) ) {
			$def = picky_sanitize_hex_color( (string) \Picky\Info::$DEFAULT_OPTIONS['color_main_tables'] );
			if ( is_string( $def ) && $def !== '' ) {
				return $def;
			}
		}
		return '#e5e5e5';
	}
}

if ( ! function_exists( 'picky_layout_canvas_bg_color' ) ) {
	/**
	 * Resolved canvas background color for a layout.
	 *
	 * @param int  $layout_id Layout post ID.
	 * @param bool $with_default When true, fall back to factory default if unset.
	 * @return string Hex color (may be empty when $with_default is false).
	 */
	function picky_layout_canvas_bg_color( $layout_id, $with_default = true ) {
		$layout_id = absint( $layout_id );
		$raw       = $layout_id ? (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas_color', true ) : '';
		$hex       = picky_sanitize_hex_color( $raw );
		if ( is_string( $hex ) && $hex !== '' ) {
			return $hex;
		}
		return $with_default ? picky_default_canvas_bg_color() : '';
	}
}

if ( ! function_exists( 'picky_layout_item_bg_color' ) ) {
	/**
	 * Resolved default bookable-item fill for a layout.
	 *
	 * @param int  $layout_id Layout post ID.
	 * @param bool $with_default When true, fall back to factory default if unset.
	 * @return string Hex color (may be empty when $with_default is false).
	 */
	function picky_layout_item_bg_color( $layout_id, $with_default = true ) {
		$layout_id = absint( $layout_id );
		$raw       = $layout_id ? (string) get_post_meta( $layout_id, '_picky_layout_bg_table_color', true ) : '';
		$hex       = picky_sanitize_hex_color( $raw );
		if ( is_string( $hex ) && $hex !== '' ) {
			return $hex;
		}
		return $with_default ? picky_default_item_bg_color() : '';
	}
}

if ( ! function_exists( 'picky_normalize_booking_date' ) ) {
	/**
	 * Normalize a date to m/d/Y (booking storage / datepicker).
	 *
	 * @param mixed $raw HTML date (Y-m-d) or m/d/Y.
	 * @return string
	 */
	function picky_normalize_booking_date( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
			return $m[2] . '/' . $m[3] . '/' . $m[1];
		}
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m ) ) {
			return sprintf( '%02d/%02d/%04d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return '';
	}
}

if ( ! function_exists( 'picky_booking_date_to_html' ) ) {
	/**
	 * Convert m/d/Y to Y-m-d for <input type="date">.
	 *
	 * @param string $mdy Booking date.
	 * @return string
	 */
	function picky_booking_date_to_html( $mdy ) {
		$mdy = picky_normalize_booking_date( $mdy );
		if ( ! preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $mdy, $m ) ) {
			return '';
		}
		return $m[3] . '-' . $m[1] . '-' . $m[2];
	}
}

if ( ! function_exists( 'picky_normalize_booking_time' ) ) {
	/**
	 * Keep only a time that exists in the plugin slot list (HH:MM).
	 *
	 * @param mixed $raw Raw time.
	 * @return string
	 */
	function picky_normalize_booking_time( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $raw ) ) {
			return '';
		}
		$all = ( isset( \Picky\Info::$TIME_ALL ) && is_array( \Picky\Info::$TIME_ALL ) ) ? \Picky\Info::$TIME_ALL : array();
		return in_array( $raw, $all, true ) ? $raw : '';
	}
}

if ( ! function_exists( 'picky_layout_event_slot' ) ) {
	/**
	 * Per-layout event mode: calendar (default) or a single fixed date+time.
	 *
	 * @param int $layout_id Layout post ID.
	 * @return array{mode:string,date:string,time:string,active:bool,show_label:bool,label:string}
	 */
	function picky_layout_event_slot( $layout_id ) {
		$layout_id = absint( $layout_id );
		$mode      = $layout_id ? (string) get_post_meta( $layout_id, '_picky_layout_event_mode', true ) : '';
		$date      = $layout_id ? picky_normalize_booking_date( (string) get_post_meta( $layout_id, '_picky_layout_event_date', true ) ) : '';
		$time      = $layout_id ? picky_normalize_booking_time( (string) get_post_meta( $layout_id, '_picky_layout_event_time', true ) ) : '';
		$active    = ( $mode === 'once' && $date !== '' && $time !== '' );
		$show_raw  = $layout_id ? (string) get_post_meta( $layout_id, '_picky_layout_event_show_label', true ) : '';
		$show_label = $active && $show_raw !== '0';
		$label     = '';
		if ( $active ) {
			$parts = explode( '/', $date );
			$ts    = 0;
			if ( count( $parts ) === 3 ) {
				$ts = mktime(
					(int) substr( $time, 0, 2 ),
					(int) substr( $time, 3, 2 ),
					0,
					(int) $parts[0],
					(int) $parts[1],
					(int) $parts[2]
				);
			}
			$label = $ts
				? wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $ts )
				: ( $date . ' · ' . $time );
		}
		return array(
			'mode'       => $active ? 'once' : 'calendar',
			'date'       => $active ? $date : '',
			'time'       => $active ? $time : '',
			'active'     => $active,
			'show_label' => $show_label,
			'label'      => $label,
		);
	}
}

if ( ! function_exists( 'picky_booking_status_canonical' ) ) {
	/**
	 * Map legacy unprefixed booking statuses to picky_* slugs.
	 *
	 * @param string $status Raw post status.
	 * @return string
	 */
	function picky_booking_status_canonical( $status ) {
		$map = array(
			'requests'        => 'picky_requests',
			'reserved'        => 'picky_reserved',
			'canceled'        => 'picky_canceled',
			'picky_requests'  => 'picky_requests',
			'picky_reserved'  => 'picky_reserved',
			'picky_canceled'  => 'picky_canceled',
		);
		$key = (string) $status;
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}
}

if ( ! function_exists( 'picky_status_is' ) ) {
	/**
	 * Whether a stored status matches a booking status (legacy or prefixed).
	 *
	 * @param string $status Stored post status.
	 * @param string $want   Canonical or legacy slug (reserved|requests|canceled).
	 * @return bool
	 */
	function picky_status_is( $status, $want ) {
		return picky_booking_status_canonical( $status ) === picky_booking_status_canonical( $want );
	}
}

if ( ! function_exists( 'picky_booking_status_query' ) ) {
	/**
	 * Registered booking statuses for WP_Query (unprefixed slugs are not registered
	 * and WordPress drops them from post_status, which can hide or over-include rows).
	 *
	 * @return array<int, string>
	 */
	function picky_booking_status_query() {
		return array( 'picky_requests', 'picky_reserved', 'picky_canceled' );
	}
}

if ( ! function_exists( 'picky_booking_status_occupying' ) ) {
	/**
	 * Statuses that occupy a calendar slot (active requests + reserved).
	 *
	 * @return array<int, string>
	 */
	function picky_booking_status_occupying() {
		return array( 'picky_requests', 'picky_reserved' );
	}
}

if ( ! function_exists( 'picky_migrate_booking_post_statuses' ) ) {
	/**
	 * Rename leftover generic post statuses to prefixed slugs.
	 *
	 * @return void
	 */
	function picky_migrate_booking_post_statuses() {
		global $wpdb;
		$map = array(
			'requests' => 'picky_requests',
			'reserved' => 'picky_reserved',
			'canceled' => 'picky_canceled',
		);
		foreach ( $map as $from => $to ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_status = %s WHERE post_type IN ('picky_booking','picky_order') AND post_status = %s",
					$to,
					$from
				)
			);
		}
		update_option( 'picky_booking_status_prefixed', '1', false );
	}
}

if ( ! function_exists( 'picky_request_query_bag' ) ) {
	/**
	 * Sanitized copy of the current URL query.
	 *
	 * Read-only screens use this instead of $_GET / $_REQUEST. Values are
	 * sanitized as soon as they are parsed. The result is cached for the request.
	 *
	 * @return array<string, mixed>
	 */
	function picky_request_query_bag() {
		static $bag = null;
		if ( is_array( $bag ) ) {
			return $bag;
		}
		$bag = array();
		if ( ! isset( $_SERVER['QUERY_STRING'] ) || ! is_string( $_SERVER['QUERY_STRING'] ) || $_SERVER['QUERY_STRING'] === '' ) {
			return $bag;
		}
		$parsed       = array();
		$query_string = wp_strip_all_tags( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		wp_parse_str( $query_string, $parsed );
		if ( ! is_array( $parsed ) ) {
			return $bag;
		}
		$bag = map_deep(
			$parsed,
			static function ( $value ) {
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				return sanitize_text_field( (string) $value );
			}
		);
		return $bag;
	}
}

if ( ! function_exists( 'picky_request_query_value' ) ) {
	/**
	 * One sanitized query parameter. Arrays use the last value, then sanitize again.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	function picky_request_query_value( $key ) {
		$key = is_string( $key ) ? $key : '';
		if ( $key === '' ) {
			return '';
		}
		$bag = picky_request_query_bag();
		if ( ! isset( $bag[ $key ] ) ) {
			return '';
		}
		$raw = $bag[ $key ];
		if ( is_array( $raw ) ) {
			$last = end( $raw );
			$raw  = is_scalar( $last ) ? sanitize_text_field( (string) $last ) : '';
		}
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		return sanitize_text_field( (string) $raw );
	}
}

if ( ! function_exists( 'picky_plugin_root_dir' ) ) {
	/**
	 * Absolute path to the active plugin root (directory containing pickybooking.php).
	 *
	 * @return string
	 */
	function picky_plugin_root_dir() {
		return dirname( __DIR__ );
	}
}

if ( ! function_exists( 'picky_services_library_resolve_bundled_image_path' ) ) {
	/**
	 * Map a service image_url to a readable local file under the plugin (samples/…).
	 *
	 * @param string $image_url Relative samples/… path, plugin URL, or absolute file path.
	 * @return string Absolute filesystem path or empty.
	 */
	function picky_services_library_resolve_bundled_image_path( $image_url ) {
		$image_url = is_string( $image_url ) ? trim( $image_url ) : '';
		if ( $image_url === '' ) {
			return '';
		}

		$root = picky_plugin_root_dir();

		// Absolute readable path.
		if ( strpos( $image_url, '/' ) === 0 && is_readable( $image_url ) ) {
			return $image_url;
		}

		// Relative plugin path (samples/…).
		$rel = ltrim( str_replace( '\\', '/', $image_url ), '/' );
		if ( strpos( $rel, 'samples/' ) === 0 ) {
			$abs = $root . '/' . $rel;
			if ( is_readable( $abs ) ) {
				return $abs;
			}
		}

		// Plugin URL → local file (…/plugins/pickybooking/samples/…).
		if ( preg_match( '#/wp-content/plugins/[^/]+/(samples/.+)$#i', $image_url, $m ) ) {
			$abs = $root . '/' . ltrim( str_replace( '\\', '/', (string) $m[1] ), '/' );
			if ( is_readable( $abs ) ) {
				return $abs;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'picky_services_library_materialize_images' ) ) {
	/**
	 * Import bundled service images into the Media Library and rewrite image_id/image_url.
	 *
	 * Relative paths like samples/{preset}-images/foo.jpg (or plugin sample URLs) are
	 * sideloaded so preset installs ship working attachment URLs on any site.
	 *
	 * @param array<int, array<string, mixed>> $services  Services library rows.
	 * @param int                              $layout_id Optional parent post for attachments.
	 * @return array<int, array<string, mixed>>
	 */
	function picky_services_library_materialize_images( array $services, $layout_id = 0 ) {
		$layout_id = absint( $layout_id );

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		foreach ( $services as $i => $svc ) {
			if ( ! is_array( $svc ) ) {
				continue;
			}
			$url = isset( $svc['image_url'] ) ? trim( (string) $svc['image_url'] ) : '';
			if ( $url === '' ) {
				continue;
			}

			$existing_id = isset( $svc['image_id'] ) ? absint( $svc['image_id'] ) : 0;
			if ( $existing_id > 0 && wp_attachment_is_image( $existing_id ) ) {
				$att = wp_get_attachment_image_url( $existing_id, 'medium_large' );
				if ( ! $att ) {
					$att = wp_get_attachment_url( $existing_id );
				}
				if ( is_string( $att ) && $att !== '' ) {
					$services[ $i ]['visual']      = 'image';
					$services[ $i ]['image_id']    = $existing_id;
					$services[ $i ]['image_url']   = $att;
					$services[ $i ]['image_ratio'] = isset( $svc['image_ratio'] ) && (string) $svc['image_ratio'] !== ''
						? sanitize_key( (string) $svc['image_ratio'] )
						: 'square';
					$services[ $i ]['icon']        = '';
					continue;
				}
			}

			$path = picky_services_library_resolve_bundled_image_path( $url );
			if ( $path === '' ) {
				// Keep remote http(s) URLs as-is (export/import between sites).
				if ( preg_match( '#^https?://#i', $url ) ) {
					$services[ $i ]['visual'] = 'image';
				}
				continue;
			}

			$basename = wp_basename( $path );
			$tmp      = wp_tempnam( $basename );
			if ( ! is_string( $tmp ) || $tmp === '' ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$copied = file_put_contents( $tmp, file_get_contents( $path ) );
			if ( false === $copied ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				continue;
			}

			$file_array = array(
				'name'     => $basename,
				'tmp_name' => $tmp,
			);
			$title = isset( $svc['name'] ) ? sanitize_text_field( (string) $svc['name'] ) : $basename;
			$aid   = media_handle_sideload( $file_array, $layout_id, $title );
			if ( is_wp_error( $aid ) || ! $aid ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				// Fallback: public plugin URL so the card still shows an image.
				$rel = 'samples/' . ltrim( str_replace( '\\', '/', substr( $path, strlen( picky_plugin_root_dir() ) ) ), '/' );
				$rel = preg_replace( '#^/+#', '', str_replace( 'samples/samples/', 'samples/', $rel ) );
				if ( strpos( $path, picky_plugin_root_dir() . '/samples/' ) === 0 ) {
					$rel = 'samples/' . substr( $path, strlen( picky_plugin_root_dir() . '/samples/' ) );
					$services[ $i ]['visual']      = 'image';
					$services[ $i ]['image_id']    = 0;
					$services[ $i ]['image_url']   = plugins_url( $rel, picky_plugin_root_dir() . '/pickybooking.php' );
					$services[ $i ]['image_ratio'] = 'square';
					$services[ $i ]['icon']        = '';
				}
				continue;
			}

			$aid = (int) $aid;
			$att = wp_get_attachment_image_url( $aid, 'medium_large' );
			if ( ! $att ) {
				$att = wp_get_attachment_url( $aid );
			}
			$services[ $i ]['visual']      = 'image';
			$services[ $i ]['image_id']    = $aid;
			$services[ $i ]['image_url']   = is_string( $att ) ? $att : '';
			$services[ $i ]['image_ratio'] = isset( $svc['image_ratio'] ) && (string) $svc['image_ratio'] !== ''
				? sanitize_key( (string) $svc['image_ratio'] )
				: 'square';
			$services[ $i ]['icon']        = '';
		}

		return $services;
	}
}
