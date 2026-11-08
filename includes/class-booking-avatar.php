<?php
/**
 * Stub — booked avatars are Pro-only.
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-op avatar helpers for Lite.
 */
class Booking_Avatar {

	/**
	 * @return bool
	 */
	public static function map_avatars_enabled() {
		return false;
	}

	/**
	 * @param int $layout_id Unused.
	 * @return bool
	 */
	public static function layout_shows_avatars( $layout_id ) {
		unset( $layout_id );
		return false;
	}

	/**
	 * @param int    $user_id Unused.
	 * @param string $email   Unused.
	 * @return string
	 */
	public static function resolve_avatar_url( $user_id, $email = '' ) {
		unset( $user_id, $email );
		return '';
	}

	/**
	 * @param string $name  Unused.
	 * @param string $email Unused.
	 * @return string
	 */
	public static function initials_from_name( $name, $email = '' ) {
		unset( $email );
		$name = trim( (string) $name );
		return $name !== '' ? strtoupper( substr( $name, 0, 1 ) ) : '';
	}

	/**
	 * @param int $booking_id Unused.
	 * @return array<string, string>
	 */
	public static function guest_payload_for_booking( $booking_id ) {
		unset( $booking_id );
		return array(
			'avatar'   => '',
			'initials' => '',
			'name'     => '',
		);
	}

	/**
	 * @param int    $booking_id Unused.
	 * @param string $email      Unused.
	 */
	public static function maybe_store_user_id( $booking_id, $email = '' ) {
		unset( $booking_id, $email );
	}

	/**
	 * @param array<string, mixed> $data Booking payload.
	 * @return array<string, mixed>
	 */
	public static function apply_logged_in_identity( $data ) {
		return is_array( $data ) ? $data : array();
	}
}
