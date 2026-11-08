<?php

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The class containing informatin about the plugin.
 */
class Info
{
    /**
     * The plugin slug.
     *
     * @var string
     */
    const SLUG = 'pickybooking';

    /**
     * Main plugin PHP file basename inside the plugin directory (must match the deployed filename).
     *
     * @var string
     */
    const MAIN_FILE = 'pickybooking.php';

    /**
     * The plugin version.
     *
     * @var string
     */
    const VERSION = '1.0.18';

    /**
     * The name for the entry in the options table.
     *
     * @var string
     */
    const OPTION_NAME = 'picky_settings';

    /**
     * Legacy option name used before the rebrand to "PickyBooking".
     * Kept so {@see Activator} can migrate settings on activation and
     * runtime callers can fall back to it when the new key is missing.
     *
     * @var string
     */
    const LEGACY_OPTION_NAME = 'picky_settings_legacy';

    /**
     * Increment when the built-in public color defaults change. Used to one-time
     * migrate options that still match the previous factory palette.
     */
    const COLOR_DEFAULTS_REVISION = 13;

    /**
     * Option key: last applied {@see self::COLOR_DEFAULTS_REVISION}.
     */
    const OPTION_COLOR_DEFAULTS_REVISION = 'picky_color_defaults_revision';

    /**
     * Script/style enqueue handles. Never use .js or .css in the handle (ThemeForest / WordPress best practice).
     *
     * @see https://developer.wordpress.org/reference/functions/wp_enqueue_script/
     */
    const ASSET_HANDLE_APP                = self::SLUG;
    /** Shared CSS custom properties; load before {@see self::ASSET_HANDLE_APP}. */
    const ASSET_HANDLE_TOKENS             = 'picky-design-tokens';
    const ASSET_HANDLE_SELECT2_STYLE     = 'picky-select2-styles';
    const ASSET_HANDLE_SELECT2_SCRIPT    = 'picky-select2-script';
    const ASSET_HANDLE_JQUERY_UI         = 'picky-jquery-ui';
    const ASSET_HANDLE_CLIPBOARD         = 'picky-clipboard';
    const ASSET_HANDLE_CUSTOM_IMAGE      = 'picky-custom-image-loader';
    /** Settings surface styles (component + rail + integration strips); loaded after {@see self::ASSET_HANDLE_APP}. */
    const ASSET_HANDLE_ADMIN_SETTINGS    = 'picky-admin-settings';

    public static $DEFAULT_OPTIONS = array(
        'canvas_width' => '888',
        'canvas_height' => '700',
        'canvas_elements_size' => 'medium',
        'background-canvas' => '',
        'background-canvas-mode' => 'cover',
        'background-table' => '',
        'background-seat' => '',
        'position' => '',
        // Singular and plural label for the reservable thing the plugin manages.
        // Lets the same plugin be used for tables, rooms, courts, desks, etc.
        // The plural defaults to "<singular>s" if left empty (see picky_items_label()).
        'reservable_item_name'  => 'Table',
        'reservable_items_name' => 'Tables',

        'form_message' => 'Manager will call you back',
        'form_confirm' => '1',
        'form_confirm_text' => 'I\'m confirm that i give rights to use my personal data <a target="_blank" href="#">Link</a>',
        'form_email' => '',
        'form_thx' => '',
        'send_customer_email' => '1',

        'shortcode_layout' => '1',
        'container_align' => 'center',
        'shop_people_amount' => '0',
        'show_email' => '0',
        'show_phone' => '1',
        'show_people' => '1',
        'show_seats' => '1',
        'show_id_badge' => '1',
        'show_element_label' => '1',
        // Staggered enter + hover scale on bookable map items (public shortcode).
        'animate_bookable_items' => '1',
        'show_time_to' => '0',
        // Mobile table selection behavior:
        // 0 - hide canvas (current behavior), 1 - show scaled canvas, 2 - show dropdown list of tables
        'mobile_table_select' => '1',
        // Booking behavior:
        // exclusive - any reservation blocks the whole item for the interval
        // capacity  - reservations consume capacity up to item's max persons (requires people amount)
        'booking_mode' => 'exclusive',
        'days_ahead' => '30',
        'table_booked_time' => '2',
        'table_before_booked_time' => '2',
        'work_days_monday' => '1', 
        'work_days_tuesday' => '1', 
        'work_days_wednesday' => '1', 
        'work_days_thursday' => '1', 
        'work_days_friday' => '1', 
        'work_days_saturday' => '1', 
        'work_days_sunday' => '1', 

        'work_days_monday_more' => '0', 
        'work_days_tuesday_more' => '0', 
        'work_days_wednesday_more' => '0', 
        'work_days_thursday_more' => '0', 
        'work_days_friday_more' => '0', 
        'work_days_saturday_more' => '0', 
        'work_days_sunday_more' => '0', 

        'work_days_monday_time_start' => '09:00', 
        'work_days_tuesday_time_start' => '09:00', 
        'work_days_wednesday_time_start' => '09:00', 
        'work_days_thursday_time_start' => '09:00', 
        'work_days_friday_time_start' => '09:00', 
        'work_days_saturday_time_start' => '09:00', 
        'work_days_sunday_time_start' => '09:00', 

        'work_days_monday_time_end' => '23:00', 
        'work_days_tuesday_time_end' => '23:00', 
        'work_days_wednesday_time_end' => '23:00', 
        'work_days_thursday_time_end' => '23:00', 
        'work_days_friday_time_end' => '23:00', 
        'work_days_saturday_time_end' => '23:00', 
        'work_days_sunday_time_end' => '23:00', 

        'work_days_monday_time_start_more' => '09:00', 
        'work_days_tuesday_time_start_more' => '09:00', 
        'work_days_wednesday_time_start_more' => '09:00', 
        'work_days_thursday_time_start_more' => '09:00', 
        'work_days_friday_time_start_more' => '09:00', 
        'work_days_saturday_time_start_more' => '09:00', 
        'work_days_sunday_time_start_more' => '09:00', 

        'work_days_monday_time_end_more' => '23:00', 
        'work_days_tuesday_time_end_more' => '23:00', 
        'work_days_wednesday_time_end_more' => '23:00', 
        'work_days_thursday_time_end_more' => '23:00', 
        'work_days_friday_time_end_more' => '23:00', 
        'work_days_saturday_time_end_more' => '23:00', 
        'work_days_sunday_time_end_more' => '23:00', 

        // Public shortcode: 4 neutral grays (+ white) — no warm stone, no blue slate.
        // Palette: #f5f5f5 light · #e5e5e5 soft · #a3a3a3 muted · #171717 / #262626 dark.
        'color_main_main' => '#f5f5f5',
        'color_main_tables' => '#e5e5e5',
        'color_main_seats' => '#a3a3a3',
        'color_main_max_seats' => '#262626',
        'color_main_max_seats_text' => '#111111',
        'color_main_table_id' => '#525252',
        'color_main_table_id_text' => '#111111',

        'color_datepicker_datepicker_text_all' => '#737373',
        'color_datepicker_datepicker' => '#ffffff',
        'color_datepicker_datepicker_text' => '#171717',
        'color_datepicker_datepicker_disabled' => '#ffffff',
        'color_datepicker_datepicker_text_disabled' => '#a3a3a3',
        'color_datepicker_datepicker_active' => '#ffffff',
        'color_datepicker_datepicker_text_active' => '#171717',
        'color_datepicker_datepicker_arrow' => '#404040',
        'color_datepicker_datepicker_arrow_hover' => '#f5f5f5',
        'color_datepicker_datepicker_arrow_background' => '#ffffff',
        'color_datepicker_datepicker_arrow_disabled' => '#a3a3a3',
        'color_datepicker_datepicker_arrow_background_disabled' => '#e5e5e5',

        'color_form_book_now' => '#221c24',
        'color_form_book_now_text' => '#fafafa',
        // Booking form body background (extra step panels in multi-step flows).
        'color_form_form' => '#ffffff',
        'color_form_big_titles' => '#171717',
        'color_form_booking_title' => '#171717',
        'color_form_small_titles' => '#525252',
        'color_form_small_descriptions' => '#a3a3a3',
        'color_form_inputs' => '#ffffff',
        'color_form_inputs_text' => '#171717',
        'color_form_error' => '#404040',
        'color_form_error_text' => '#fafafa',
        'color_form_message' => '#f5f5f5',
        'color_form_message_text' => '#525252',
    );

    public static $TIME_ALL = array(
        0  => '00:30',
        1  => '01:00',
        2  => '01:30',
        3  => '02:00',
        4  => '02:30',
        5  => '03:00',
        6  => '03:30',
        7  => '04:00',
        8  => '04:30',
        9  => '05:00',
        10  => '05:30',
        11  => '06:00',
        12  => '06:30',
        13  => '07:00',
        14  => '07:30',
        15  => '08:00',
        16  => '08:30',
        17  => '09:00',
        18  => '09:30',
        19  => '10:00',
        20  => '10:30',
        21  => '11:00',
        22  => '11:30',
        23  => '12:00',
        24  => '12:30',
        25  => '13:00',
        26  => '13:30',
        27  => '14:00',
        28  => '14:30',
        29  => '15:00',
        30  => '15:30',
        31  => '16:00',
        32  => '16:30',
        33  => '17:00',
        34  => '17:30',
        35  => '18:00',
        36  => '18:30',
        37  => '19:00',
        38  => '19:30',
        39  => '20:00',
        40  => '20:30',
        41  => '21:00',
        42  => '21:30',
        43  => '22:00',
        44  => '22:30',
        45  => '23:00',
        46  => '23:30',
        47  => '00:00',
    );

    public static $TABLES = array(
        array(
            'title' => 'Square',
            'data-type' => 'type-1',
            'data-max-seats' => '0',
            'data-seats' => '1',
            'data-rotate' => '1',
            'data-big-side' => 'w',
        ),
        array(
            'title' => 'Circle',
            'data-type' => 'type-4',
            'data-max-seats' => '0',
            'data-seats' => '1',
            'data-rotate' => '1',
            'data-big-side' => 'w',
        ),
    );

    /**
     * Retrieves the plugin title from the main plugin file.
     *
     * @return string The plugin title
     */
    public static function get_plugin_title() {
        $path = plugin_dir_path(dirname(__FILE__)).self::MAIN_FILE;
        $data = function_exists('get_plugin_data') ? get_plugin_data($path) : array();
        return (isset($data['Name']) && !empty($data['Name'])) ? (string)$data['Name'] : 'PickyBooking';
    }

    public static function get_plugin_path() {
        $path = plugin_dir_path(dirname(__FILE__));
        return $path;
    }

    /**
     * Defensive accessor for the plugin settings array.
     *
     * get_option() can legitimately return false (key missing) or an array
     * with a few keys missing (older installs, partial saves, options edited
     * by hand, multisite quirks). Direct $opts['days_ahead'] then triggers
     * "Undefined index" notices on PHP 7 and fatal arithmetic errors on
     * PHP 8+ ("Unsupported operand types: string * int" / null arithmetic).
     *
     * This helper:
     *   1. Reads OPTION_NAME, falling back to LEGACY_OPTION_NAME so we keep
     *      working on sites that didn't re-activate after the rebrand.
     *   2. Coerces a non-array result to an empty array.
     *   3. wp_parse_args()s it on top of $DEFAULT_OPTIONS so EVERY documented
     *      key is guaranteed present.
     *
     * Use it instead of get_option(self::OPTION_NAME) wherever the result is
     * dereferenced as an array.
     *
     * @return array<string, mixed>
     */
    public static function get_settings() {
        $raw = get_option( self::OPTION_NAME );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            $legacy_key = defined( __CLASS__ . '::LEGACY_OPTION_NAME' ) ? self::LEGACY_OPTION_NAME : 'picky_settings_legacy';
            if ( $legacy_key && $legacy_key !== self::OPTION_NAME ) {
                $legacy = get_option( $legacy_key );
                if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                    $raw = $legacy;
                }
            }
        }
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        if ( function_exists( 'wp_parse_args' ) ) {
            return wp_parse_args( $raw, self::$DEFAULT_OPTIONS );
        }
        return array_merge( self::$DEFAULT_OPTIONS, $raw );
    }
}
