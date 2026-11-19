<?php
/**
 * Edition flags for PickyBooking (WordPress.org free build).
 *
 * WP.org guideline #5 (no trialware): Pro-only capabilities are omitted from this
 * build entirely — they are not present as locked/paid unlocks. Soft upsell links
 * and disabled UI stubs pointing to the commercial product are allowed on admin pages.
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
		$feature  = sanitize_key( (string) $feature );
		$pro_only = array(
			'payments'          => true,
			'stripe'            => true,
			'woocommerce_pay'   => true,
			'paypal'            => true,
			'sms'               => true,
			'integrations'      => true, // CRM / webhooks gallery (AI is free — see below).
			'hubspot'           => true,
			'mailchimp'         => true,
			'zapier'            => true,
			'make'              => true,
			'n8n'               => true,
			'google_calendar'   => true,
			'elementor'         => true,
			'decorative'        => true,
			'booked_avatars'    => true,
			'import_export'     => true,
			'wizard_smart'      => true,
			'wizard_smart_map'  => true,
			'preset_import_pro' => true,
			// 'ai' is intentionally free in Lite.
		);
		if ( isset( $pro_only[ $feature ] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Integration / payment section slugs that are Pro-locked in Lite (AI excluded).
	 *
	 * @return string[]
	 */
	public static function pro_locked_section_slugs() {
		return array(
			'picky_sec_sms',
			'picky_sec_vonage',
			// Pricing (amount/currency) stays Free. Online checkout cards are Pro-locked.
			'picky_sec_pay_woocommerce',
			'picky_sec_pay_stripe',
			'picky_sec_pay_paypal',
			'picky_sec_hubspot',
			'picky_sec_mailchimp',
			'picky_sec_n8n',
			'picky_sec_zapier',
			'picky_sec_make',
			'picky_sec_gcal',
		);
	}

	/**
	 * @param string $sec_slug Section slug.
	 * @return bool
	 */
	public static function is_pro_locked_section( $sec_slug ) {
		if ( self::is_pro() ) {
			return false;
		}
		$sec_slug = (string) $sec_slug;
		if ( $sec_slug === 'picky_sec_ai' ) {
			return false;
		}
		return in_array( $sec_slug, self::pro_locked_section_slugs(), true );
	}

	/**
	 * Wizard shortcode_layout values allowed in this build.
	 *
	 * @return string[]
	 */
	public static function allowed_wizard_layouts() {
		if ( self::is_pro() ) {
			return array( '1', '2', '3', '4' );
		}
		return array( '1', '3' );
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clamp_wizard_layout( $value ) {
		$value   = (string) $value;
		$allowed = self::allowed_wizard_layouts();
		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}
		return '1';
	}

	/**
	 * Shared Pro lock badge (brand green / black).
	 *
	 * Default label is "Pro only" with a sidebar-style lock icon.
	 * Pass a custom $label (e.g. "Pro") for compact tiles without the lock.
	 *
	 * @param string $tip   Optional tooltip text.
	 * @param string $label Optional visible label (default: "Pro only").
	 * @return string
	 */
	public static function pro_badge_html( $tip = '', $label = '' ) {
		$tip      = $tip !== '' ? $tip : __( 'Available in PickyBooking Pro', 'pickybooking' );
		$is_short = $label !== '';
		$text     = $is_short ? $label : __( 'Pro only', 'pickybooking' );
		$icon     = $is_short ? '' : '<i class="icon-lock" aria-hidden="true"></i>';
		return '<span class="picky-pro-badge" aria-label="' . esc_attr( $tip ) . '" title="' . esc_attr( $tip ) . '">'
			. $icon
			. '<span class="picky-pro-badge__text">' . esc_html( $text ) . '</span>'
			. '</span>';
	}

	/**
	 * @param string $message Short message.
	 * @return string
	 */
	public static function pro_tip_html( $message = '' ) {
		if ( $message === '' ) {
			$message = __( 'This feature is part of PickyBooking Pro.', 'pickybooking' );
		}
		return '<span class="picky-pro-tip">'
			. '<span class="picky-pro-tip__text">' . esc_html( $message ) . '</span> '
			. '<a class="picky-pro-tip__link" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more', 'pickybooking' ) . '</a>'
			. '</span>';
	}

	/**
	 * Builder palette — bookable always; decorative included for display (Pro-locked in Lite UI).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function tables_palette() {
		return Info::$TABLES;
	}

	/**
	 * Whether a palette data-type is decorative (Pro in Lite).
	 *
	 * @param string $data_type Element data-type.
	 * @return bool
	 */
	public static function is_decorative_type( $data_type ) {
		return in_array( (string) $data_type, array( 'type-wall', 'type-text', 'type-shape', 'type-icon' ), true );
	}
}
