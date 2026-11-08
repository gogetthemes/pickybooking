<?php
/**
 * Edition flags for PickyBooking (WordPress.org free build).
 *
 * WP.org guideline #5 (no trialware): Pro-only capabilities are omitted from this
 * build entirely — they are not present as locked/paid unlocks. Soft upsell links
 * to the commercial PickyBooking product are allowed on admin/docs pages only.
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lite / Pro feature matrix.
 */
class Edition {

	const EDITION = 'lite';

	const PRO_URL = 'https://pickybooking.com/#pricing';

	const LITE_URL = 'https://pickybooking.com/';

	/**
	 * @return bool
	 */
	public static function is_lite() {
		return true;
	}

	/**
	 * @return bool
	 */
	public static function is_pro() {
		return false;
	}

	/**
	 * Whether a named capability ships in this build.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function allows( $feature ) {
		$feature = sanitize_key( (string) $feature );
		$pro_only = array(
			'payments'         => true,
			'stripe'           => true,
			'woocommerce_pay'  => true,
			'paypal'           => true,
			'sms'              => true,
			'integrations'     => true,
			'ai'               => true,
			'hubspot'          => true,
			'mailchimp'        => true,
			'zapier'           => true,
			'make'             => true,
			'n8n'              => true,
			'google_calendar'  => true,
			'elementor'        => true,
			'decorative'       => true,
			'booked_avatars'   => true,
			'import_export'    => true,
		);
		if ( isset( $pro_only[ $feature ] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Builder palette. Lite ships bookable presets only; decorative presets are not in this build.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function tables_palette() {
		return Info::$TABLES;
	}
}
