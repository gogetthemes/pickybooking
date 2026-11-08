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
