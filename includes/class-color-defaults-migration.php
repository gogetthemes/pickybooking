<?php
/**
 * One-time migration: apply new default colors when saved values still match old factory defaults.
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrates stored color settings after the default palette in {@see Info::$DEFAULT_OPTIONS} changes.
 */
class Color_Defaults_Migration {

	/**
	 * Run on plugins_loaded if the revision option is behind {@see Info::COLOR_DEFAULTS_REVISION}.
	 */
	public static function apply() {
		$target = (int) Info::COLOR_DEFAULTS_REVISION;
		if ( $target < 1 ) {
			return;
		}

		// Always: `color_main_main` = "Booking wrapper & calendar column background" — often left on a
		// random lime (e.g. #81d742) from the WP picker. Fix even when revision migration is already up to date.
		$opts = get_option( Info::OPTION_NAME, null );
		if ( is_array( $opts ) && ! empty( $opts ) && self::replace_garish_booking_wrapper_bg( $opts ) ) {
			update_option( Info::OPTION_NAME, $opts );
		}

		$done = (int) get_option( Info::OPTION_COLOR_DEFAULTS_REVISION, 0 );
		if ( $done >= $target ) {
			return;
		}

		$opts = get_option( Info::OPTION_NAME, null );
		if ( ! is_array( $opts ) || empty( $opts ) ) {
			update_option( Info::OPTION_COLOR_DEFAULTS_REVISION, $target );
			return;
		}

		$current = Info::$DEFAULT_OPTIONS;
		$changed = false;

		$legacy_stack = array(
			self::legacy_v1_colors(),
			self::legacy_v2_colors(),
			self::legacy_v4_colors(),
			self::legacy_v5_colors(),
			self::legacy_v6_colors(),
			self::legacy_v7_colors(),
			self::legacy_v8_colors(),
			self::legacy_v9_colors(),
			self::legacy_v10_colors(),
			self::legacy_v11_colors(),
			self::legacy_v12_colors(),
		);

		$aliases = self::superseded_color_aliases();

		foreach ( $current as $key => $def_val ) {
			if ( 0 !== strpos( (string) $key, 'color_' ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $opts ) ) {
				continue;
			}
			$cur = $opts[ $key ];
			$matched = false;
			foreach ( $legacy_stack as $legacy ) {
				if ( ! array_key_exists( $key, $legacy ) ) {
					continue;
				}
				if ( self::colors_equal( $cur, $legacy[ $key ] ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched && isset( $aliases[ $key ] ) && is_array( $aliases[ $key ] ) ) {
				foreach ( $aliases[ $key ] as $old ) {
					if ( self::colors_equal( $cur, $old ) ) {
						$matched = true;
						break;
					}
				}
			}
			if ( $matched ) {
				$opts[ $key ] = $def_val;
				$changed      = true;
			}
		}

		// Legacy: primary button was empty string (theme default). Adopt the new solid default once.
		if ( isset( $current['color_form_book_now'] ) && self::is_emptyish( $opts['color_form_book_now'] ?? null ) ) {
			$opts['color_form_book_now'] = $current['color_form_book_now'];
			$changed                      = true;
		}

		if ( $changed ) {
			update_option( Info::OPTION_NAME, $opts );
		}

		/*
		 * Only advance revision when the palette is actually migrated (or was already
		 * non-legacy / already equal to current defaults). Bumping the revision without
		 * writing new colors would strand sites on old values while skipping future runs.
		 */
		$still_legacy    = self::options_contain_recognized_legacy( $opts, $legacy_stack, $current );
		$fully_current   = self::all_color_options_match_defaults( $opts, $current );
		if ( $changed || ! $still_legacy || $fully_current ) {
			update_option( Info::OPTION_COLOR_DEFAULTS_REVISION, $target );
		}
	}

	/**
	 * "Booking wrapper & calendar column background" — replace acid limes and old plugin mints.
	 *
	 * @param array<string, mixed> $opts Reference to plugin options.
	 * @return bool True if a value was updated.
	 */
	private static function replace_garish_booking_wrapper_bg( array &$opts ) {
		if ( ! isset( $opts['color_main_main'] ) ) {
			return false;
		}
		$def = isset( Info::$DEFAULT_OPTIONS['color_main_main'] ) ? (string) Info::$DEFAULT_OPTIONS['color_main_main'] : '#f5f5f5';
		$saved = self::normalize_color_string( $opts['color_main_main'] );
		if ( $saved === self::normalize_color_string( $def ) ) {
			return false;
		}
		$garish = array(
			'81d742',
			'7fffd4',
			'66e6bb',
			'76d246',
			'7fd742',
		);
		if ( in_array( $saved, $garish, true ) ) {
			$opts['color_main_main'] = $def;
			return true;
		}
		return false;
	}

	/**
	 * True if any color still matches a legacy factory value that differs from current shipping defaults.
	 *
	 * @param array<string, mixed>  $opts         Saved options.
	 * @param array<int, array<string, string>> $legacy_stack Legacy maps (oldest first).
	 * @param array<string, mixed>  $current      {@see Info::$DEFAULT_OPTIONS} snapshot.
	 */
	private static function options_contain_recognized_legacy( $opts, $legacy_stack, $current ) {
		$aliases = self::superseded_color_aliases();
		foreach ( $opts as $key => $val ) {
			if ( 0 !== strpos( (string) $key, 'color_' ) ) {
				continue;
			}
			foreach ( $legacy_stack as $legacy ) {
				if ( ! isset( $legacy[ $key ] ) ) {
					continue;
				}
				if ( ! self::colors_equal( $val, $legacy[ $key ] ) ) {
					continue;
				}
				// Legacy row matches shipping default — not "stuck" on an old theme.
				if ( isset( $current[ $key ] ) && self::colors_equal( $legacy[ $key ], $current[ $key ] ) ) {
					continue;
				}
				return true;
			}
			if ( isset( $aliases[ $key ] ) && is_array( $aliases[ $key ] ) ) {
				foreach ( $aliases[ $key ] as $old ) {
					if ( ! self::colors_equal( $val, $old ) ) {
						continue;
					}
					if ( isset( $current[ $key ] ) && self::colors_equal( $old, $current[ $key ] ) ) {
						continue;
					}
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $opts    Saved options.
	 * @param array<string, mixed> $current {@see Info::$DEFAULT_OPTIONS}
	 */
	private static function all_color_options_match_defaults( $opts, $current ) {
		foreach ( $current as $key => $def ) {
			if ( 0 !== strpos( (string) $key, 'color_' ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $opts ) ) {
				return false;
			}
			if ( ! self::colors_equal( $opts[ $key ], $def ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $a Saved value.
	 * @param mixed $b Old default from theme version 1.
	 */
	private static function colors_equal( $a, $b ) {
		return self::normalize_color_string( $a ) === self::normalize_color_string( $b );
	}

	/**
	 * @param mixed $v Color or keyword.
	 */
	private static function normalize_color_string( $v ) {
		if ( null === $v || false === $v ) {
			return '';
		}
		$s = trim( (string) $v );
		if ( $s === '' ) {
			return '';
		}
		if ( strcasecmp( $s, 'transparent' ) === 0 ) {
			return 'transparent';
		}
		if ( $s[0] === '#' ) {
			$s = substr( $s, 1 );
		}
		$s = strtolower( $s );
		if ( strlen( $s ) === 3 && ctype_xdigit( $s ) ) {
			$s = $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
		}
		if ( strlen( $s ) === 6 && ctype_xdigit( $s ) ) {
			return $s;
		}
		return strtolower( trim( (string) $v ) );
	}

	/**
	 * @param mixed $v Value.
	 */
	private static function is_emptyish( $v ) {
		if ( null === $v || false === $v ) {
			return true;
		}
		return trim( (string) $v ) === '';
	}

	/**
	 * Factory defaults before the neutral “premium” palette (revision 2).
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v1_colors() {
		return array(
			'color_main_main'            => '#7fffd4',
			'color_main_tables'          => '#dcdcdc',
			'color_main_seats'           => '#aeb1c1',
			'color_main_max_seats'       => '#9c9c9c',
			'color_main_max_seats_text'  => '#ffffff',
			'color_main_table_id'        => '#898daa',
			'color_main_table_id_text'   => '#fff',
			'color_datepicker_datepicker_text_all'   => '#333',
			'color_datepicker_datepicker'            => '#fff',
			'color_datepicker_datepicker_text'       => '#333',
			'color_datepicker_datepicker_disabled'  => '#66e6bb',
			'color_datepicker_datepicker_text_disabled' => '#5ab28c',
			'color_datepicker_datepicker_active'     => '#fff',
			'color_datepicker_datepicker_text_active' => '#333',
			'color_datepicker_datepicker_arrow'      => '#221c24',
			'color_datepicker_datepicker_arrow_hover' => '#333',
			'color_datepicker_datepicker_arrow_background' => 'transparent',
			'color_datepicker_datepicker_arrow_disabled' => '#7fffd4',
			'color_datepicker_datepicker_arrow_background_disabled' => '#66e6bb',
			'color_form_book_now_text'  => '#fff',
			'color_form_form'          => '#fff',
			'color_form_big_titles'     => '#000',
			'color_form_booking_title'  => '#333',
			'color_form_small_titles'   => '#777',
			'color_form_small_descriptions' => '#bbb',
			'color_form_inputs'         => '#e3e3e3',
			'color_form_inputs_text'    => '#333',
			'color_form_error'          => '#e75e5c',
			'color_form_error_text'     => '#fff',
			'color_form_message'        => '#f5f5dc',
			'color_form_message_text'   => '#a7a495',
		);
	}

	/**
	 * Defaults shipped as revision 2 (cool gray + charcoal) before warm-stone revision 3.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v2_colors() {
		return array(
			'color_main_main'            => '#eef0f4',
			'color_main_tables'          => '#c5cad6',
			'color_main_seats'           => '#8b93a5',
			'color_main_max_seats'       => '#2d333d',
			'color_main_max_seats_text'  => '#f5f6f8',
			'color_main_table_id'        => '#3d4555',
			'color_main_table_id_text'   => '#f5f6f8',
			'color_datepicker_datepicker_text_all'   => '#3d4451',
			'color_datepicker_datepicker'            => '#ffffff',
			'color_datepicker_datepicker_text'      => '#1a1d24',
			'color_datepicker_datepicker_disabled'  => '#e8eaef',
			'color_datepicker_datepicker_text_disabled' => '#9aa3b2',
			'color_datepicker_datepicker_active'     => '#1a1d24',
			'color_datepicker_datepicker_text_active' => '#ffffff',
			'color_datepicker_datepicker_arrow'     => '#1a1d24',
			'color_datepicker_datepicker_arrow_hover' => '#eef0f4',
			'color_datepicker_datepicker_arrow_background' => '#ffffff',
			'color_datepicker_datepicker_arrow_disabled' => '#b8c0cc',
			'color_datepicker_datepicker_arrow_background_disabled' => '#e8eaef',
			'color_form_book_now'        => '#1a1d24',
			'color_form_book_now_text'   => '#ffffff',
			'color_form_form'            => '#fafbfc',
			'color_form_big_titles'      => '#1a1d24',
			'color_form_booking_title'   => '#1a1d24',
			'color_form_small_titles'    => '#5c6370',
			'color_form_small_descriptions' => '#8b939e',
			'color_form_inputs'          => '#f0f2f5',
			'color_form_inputs_text'     => '#1a1d24',
			'color_form_error'           => '#d94c4a',
			'color_form_error_text'     => '#ffffff',
			'color_form_message'         => '#e8f1f5',
			'color_form_message_text'   => '#3d5a66',
		);
	}

	/**
	 * Defaults shipped as revision 4 before datepicker "white cell" refresh (revision 5).
	 *
	 * Keep only keys changed in revision 5 so default users are migrated once.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v4_colors() {
		return array(
			'color_datepicker_datepicker_disabled'   => '#e5e5e5',
			'color_datepicker_datepicker_active'     => '#1c1917',
			'color_datepicker_datepicker_text_active' => '#fafaf9',
		);
	}

	/**
	 * Defaults shipped as revision 5 before soft-gray wrapper refresh (revision 6).
	 *
	 * Keep only changed keys so only untouched defaults are migrated.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v5_colors() {
		return array(
			'color_main_main' => '#f5f5f5',
		);
	}

	/**
	 * Defaults shipped as revision 6 before pure-white form surfaces (revision 7).
	 *
	 * Keep only changed keys so only untouched defaults are migrated.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v6_colors() {
		return array(
			'color_form_form'   => '#ffffff',
			'color_form_inputs' => '#f5f5f5',
		);
	}

	/**
	 * Defaults shipped as revision 7 (warm-stone map) before slate-gray revision 8.
	 *
	 * Keep only keys that change so customized sites are left alone.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v7_colors() {
		return array(
			'color_main_tables'          => '#c9bfb2',
			'color_main_seats'           => '#8c8578',
			'color_main_max_seats'       => '#3d3830',
			'color_main_max_seats_text'  => '#faf8f3',
			'color_main_table_id'        => '#5c5248',
			'color_main_table_id_text'   => '#faf8f3',
			'color_datepicker_datepicker_text_all' => '#525252',
			'color_datepicker_datepicker_text' => '#171717',
			'color_datepicker_datepicker_text_disabled' => '#a3a3a3',
			'color_datepicker_datepicker_text_active' => '#171717',
			'color_datepicker_datepicker_arrow' => '#404040',
			'color_datepicker_datepicker_arrow_hover' => '#f5f5f5',
			'color_datepicker_datepicker_arrow_disabled' => '#a3a3a3',
			'color_datepicker_datepicker_arrow_background_disabled' => '#e5e5e5',
			'color_form_book_now'        => '#1c1917',
			'color_form_book_now_text'   => '#fafaf9',
			'color_form_big_titles'      => '#171717',
			'color_form_booking_title'   => '#171717',
			'color_form_small_titles'    => '#525252',
			'color_form_small_descriptions' => '#a8a29e',
			'color_form_inputs_text'     => '#1c1917',
			'color_form_error'           => '#b91c1c',
			'color_form_error_text'      => '#fff7ed',
			'color_form_message'         => '#ecfdf3',
			'color_form_message_text'    => '#14532d',
		);
	}

	/**
	 * Defaults shipped as revision 8 (blue-slate) before neutral-gray revision 9.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v8_colors() {
		return array(
			'color_main_main'            => '#eef0f4',
			'color_main_tables'          => '#cbd5e1',
			'color_main_seats'           => '#94a3b8',
			'color_main_max_seats'       => '#334155',
			'color_main_max_seats_text'  => '#f8fafc',
			'color_main_table_id'        => '#475569',
			'color_main_table_id_text'   => '#f8fafc',
			'color_datepicker_datepicker_text_all' => '#717171',
			'color_datepicker_datepicker_text' => '#0f172a',
			'color_datepicker_datepicker_text_disabled' => '#94a3b8',
			'color_datepicker_datepicker_text_active' => '#0f172a',
			'color_datepicker_datepicker_arrow' => '#475569',
			'color_datepicker_datepicker_arrow_hover' => '#f1f5f9',
			'color_datepicker_datepicker_arrow_disabled' => '#94a3b8',
			'color_datepicker_datepicker_arrow_background_disabled' => '#e2e8f0',
			'color_form_book_now'        => '#0f172a',
			'color_form_book_now_text'   => '#f8fafc',
			'color_form_big_titles'      => '#0f172a',
			'color_form_booking_title'   => '#0f172a',
			'color_form_small_titles'    => '#475569',
			'color_form_small_descriptions' => '#94a3b8',
			'color_form_inputs_text'     => '#0f172a',
			'color_form_error'           => '#b91c1c',
			'color_form_error_text'      => '#f8fafc',
			'color_form_message'         => '#ecfdf3',
			'color_form_message_text'    => '#14532d',
		);
	}

	/**
	 * Defaults shipped as revision 9 (neutral charcoal CTA) before brand-mint revision 10.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v9_colors() {
		return array(
			'color_form_book_now'      => '#171717',
			'color_form_book_now_text' => '#fafafa',
		);
	}

	/**
	 * Sites that reached revision 10 while Submit button was still charcoal
	 * (defaults flipped to mint after the revision flag was already written).
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v10_colors() {
		return array(
			'color_form_book_now'      => '#171717',
			'color_form_book_now_text' => '#fafafa',
		);
	}

	/**
	 * Revision 11 briefly used plugin mint as the public CTA default.
	 * Frontend CTA is now separate (client color, default #221c24).
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v11_colors() {
		return array(
			'color_form_book_now'      => '#79fed1',
			'color_form_book_now_text' => '#111111',
		);
	}

	/**
	 * Defaults shipped as revision 12 (white map ID / seats text) before black-on-plate revision 13.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_v12_colors() {
		return array(
			'color_main_max_seats_text' => '#fafafa',
			'color_main_table_id_text'  => '#fafafa',
		);
	}

	/**
	 * Extra factory / near-factory hexes seen in the wild (warm stone variants)
	 * that did not land as exact single-map legacy rows.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function superseded_color_aliases() {
		return array(
			'color_main_main'           => array( '#eef0f4', '#faf8f3', '#ebe6df' ),
			'color_main_tables'         => array( '#c9bfb2', '#cbd5e1', '#d6d3d1' ),
			'color_main_seats'          => array( '#8c8578', '#94a3b8', '#a8a29e' ),
			'color_main_max_seats'      => array( '#3d3830', '#334155', '#1c1917', '#44403c' ),
			'color_main_max_seats_text' => array( '#faf8f3', '#f8fafc', '#fafaf9', '#fafafa', '#ffffff', '#fff' ),
			'color_main_table_id'       => array( '#5c5248', '#475569', '#44403c', '#57534e' ),
			'color_main_table_id_text'  => array( '#faf8f3', '#f8fafc', '#fafaf9', '#fafafa', '#ffffff', '#fff' ),
			'color_datepicker_datepicker_text_all' => array( '#525252', '#717171', '#57534e', '#78716c' ),
			'color_datepicker_datepicker_text' => array( '#171717', '#1c1917', '#0f172a', '#44403c' ),
			'color_datepicker_datepicker_text_disabled' => array( '#a3a3a3', '#94a3b8', '#a8a29e', '#c4b8a8' ),
			'color_datepicker_datepicker_text_active' => array( '#171717', '#1c1917', '#0f172a' ),
			'color_datepicker_datepicker_arrow' => array( '#404040', '#475569', '#44403c', '#1c1917' ),
			'color_datepicker_datepicker_arrow_hover' => array( '#f5f5f5', '#f1f5f9', '#f5f0e8', '#ebe6df' ),
			'color_datepicker_datepicker_arrow_disabled' => array( '#a3a3a3', '#94a3b8', '#c4b8a8', '#a8a29e' ),
			'color_datepicker_datepicker_arrow_background_disabled' => array( '#e5e5e5', '#e2e8f0', '#ebe6df', '#f5f0e8' ),
			'color_form_book_now'       => array( '#1c1917', '#0f172a', '#44403c', '#171717', '#1a1d24', '#79fed1' ),
			'color_form_book_now_text'  => array( '#fafaf9', '#f8fafc', '#faf8f3', '#fafafa', '#ffffff', '#fff', '#111111', '#111' ),
			'color_form_big_titles'     => array( '#171717', '#1c1917', '#0f172a', '#44403c' ),
			'color_form_booking_title'  => array( '#171717', '#1c1917', '#0f172a', '#44403c' ),
			'color_form_small_titles'   => array( '#525252', '#475569', '#57534e', '#78716c' ),
			'color_form_small_descriptions' => array( '#a8a29e', '#94a3b8', '#a3a3a3', '#c4b8a8' ),
			'color_form_inputs_text'    => array( '#1c1917', '#0f172a', '#171717', '#44403c' ),
			'color_form_error'          => array( '#b91c1c', '#e75e5c', '#d94c4a' ),
			'color_form_error_text'     => array( '#fff7ed', '#f8fafc', '#ffffff', '#fafaf9' ),
			'color_form_message'        => array( '#ecfdf3', '#e8f1f5', '#f5f5dc' ),
			'color_form_message_text'   => array( '#14532d', '#3d5a66', '#a7a495' ),
		);
	}
}
