<?php
namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Public_Plugin {

	private $plugin_slug;
    private $version;
    private $option_name;
    private $settings;
    private $settings_group;


    public function __construct($plugin_slug, $version, $option_name) {
        $this->plugin_slug = $plugin_slug;
        $this->version = $version;
        $this->option_name = $option_name;
        $raw = get_option($this->option_name);
        // Runtime fallback: read legacy option key when the new key is missing.
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            $legacy_key = defined( __NAMESPACE__ . '\\Info::LEGACY_OPTION_NAME' ) ? Info::LEGACY_OPTION_NAME : 'picky_settings_legacy';
            if ( $legacy_key && $legacy_key !== $this->option_name ) {
                $legacy = get_option( $legacy_key );
                if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                    $raw = $legacy;
                }
            }
        }
        $this->settings = is_array($raw)
            ? wp_parse_args($raw, Info::$DEFAULT_OPTIONS)
            : Info::$DEFAULT_OPTIONS;
        $this->settings_group = $this->option_name.'_group';
    }

    /**
     * Print shortcode CSS registered after wp_head, using the core style printer.
     *
     * @return void
     */
    public function picky_print_booking_dynamic_style() {
        wp_print_styles( 'picky-booking-dynamic' );
    }

    /**
     * Singular label for the reservable item ("Table" / "Room" / "Court" / …).
     * Falls back to the i18n default "Table" so existing sites keep current copy.
     *
     * @return string
     */
    public function picky_item_label() {
        $opts = is_array( $this->settings ) ? $this->settings : get_option( $this->option_name );
        $name = ( is_array( $opts ) && isset( $opts['reservable_item_name'] ) )
            ? trim( (string) $opts['reservable_item_name'] )
            : '';
        if ( $name === '' ) {
            return __( 'Table', 'pickybooking' );
        }
        return sanitize_text_field( $name );
    }

    /**
     * Plural label for the reservable item. If the option is empty, derive it
     * from the singular by appending an "s" so legacy installs keep working.
     *
     * @return string
     */
    public function picky_items_label() {
        $opts = is_array( $this->settings ) ? $this->settings : get_option( $this->option_name );
        $plural = ( is_array( $opts ) && isset( $opts['reservable_items_name'] ) )
            ? trim( (string) $opts['reservable_items_name'] )
            : '';
        if ( $plural !== '' ) {
            return sanitize_text_field( $plural );
        }
        $singular = $this->picky_item_label();
        return sanitize_text_field( $singular . 's' );
    }

    /**
     * Normalize H:i to match INFO::$TIME_ALL values (leading zeros) for array_search / UI options.
     *
     * @param string $time Raw time from meta or request.
     * @return string Normalized 'H:i' or empty if not parseable.
     */
    private function normalize_time_slot( $time ) {
        if ( ! is_string( $time ) ) {
            $time = (string) $time;
        }
        $time = trim( $time );
        if ( $time === '' || ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
            return $time;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ( $h < 0 || $h > 23 || $i < 0 || $i > 59 ) {
            return $time;
        }
        return sprintf( '%02d:%02d', $h, $i );
    }

    /**
     * One data-time exclude row; optionally carries booker guest payload for avatars.
     *
     * @param string                    $date  Y-m-d.
     * @param string                    $time  H:i.
     * @param array<string,string>|null $guest Guest payload.
     * @return array<string,mixed>
     */
    private function picky_time_exclude_row( $date, $time, $guest = null ) {
        $row = array(
            'date' => (string) $date,
            'time' => (string) $time,
        );
        if ( is_array( $guest ) ) {
            $name     = isset( $guest['name'] ) ? (string) $guest['name'] : '';
            $avatar   = isset( $guest['avatar'] ) ? (string) $guest['avatar'] : '';
            $initials = isset( $guest['initials'] ) ? (string) $guest['initials'] : '';
            if ( $name !== '' || $avatar !== '' || $initials !== '' ) {
                $row['guest'] = array(
                    'name'     => $name,
                    'avatar'   => $avatar,
                    'initials' => $initials !== '' ? $initials : '?',
                );
            }
        }
        return $row;
    }

    /**
     * Lean map bookings for one layout (no WP_Post / full meta hydration).
     *
     * Loading every picky_booking via get_posts(-1) + get_metadata OOMs on large demo datasets.
     *
     * @param int $layout_id Layout post ID; 0 = all layouts.
     * @return list<array<string,mixed>>
     */
    private function picky_load_map_bookings( $layout_id ) {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs absint-joined; static meta keys
        global $wpdb;
        $layout_id = absint( $layout_id );

        $select = "SELECT p.ID AS post_id, p.post_author AS post_author,
                MAX(CASE WHEN m.meta_key = '_picky_booking_id' THEN m.meta_value END) AS booking_id,
                MAX(CASE WHEN m.meta_key = '_picky_booking_unique' THEN m.meta_value END) AS uniq,
                MAX(CASE WHEN m.meta_key = '_picky_booking_date' THEN m.meta_value END) AS booking_date,
                MAX(CASE WHEN m.meta_key = '_picky_booking_time' THEN m.meta_value END) AS booking_time,
                MAX(CASE WHEN m.meta_key = '_picky_booking_time_to' THEN m.meta_value END) AS booking_time_to,
                MAX(CASE WHEN m.meta_key = '_picky_payment_status' THEN m.meta_value END) AS pay_status,
                MAX(CASE WHEN m.meta_key = '_picky_booking_order_id' THEN m.meta_value END) AS order_id,
                MAX(CASE WHEN m.meta_key = '_picky_booking_user_id' THEN m.meta_value END) AS user_id
            FROM {$wpdb->posts} p";
        $meta_join = " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN (
                '_picky_booking_id','_picky_booking_unique','_picky_booking_date','_picky_booking_time',
                '_picky_booking_time_to','_picky_payment_status','_picky_booking_order_id','_picky_booking_user_id'
            )
            WHERE p.post_type = 'picky_booking' AND p.post_status IN ('picky_reserved','picky_requests','reserved','requests')
            GROUP BY p.ID, p.post_author";

        if ( $layout_id > 0 ) {
            $sql = $select . " INNER JOIN {$wpdb->postmeta} AS layout ON layout.post_id = p.ID AND layout.meta_key = '_picky_reservation_layout_id' AND layout.meta_value = %s" . $meta_join;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql built from $wpdb->* table names + %s placeholder; value prepared below.
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, (string) $layout_id ), ARRAY_A );
        } else {
            $sql = $select . $meta_join;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fully static SQL, no user input.
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        }
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array();
        }

        $order_ids = array();
        foreach ( $rows as $row ) {
            $oid = absint( $row['order_id'] ?? 0 );
            if ( $oid > 0 ) {
                $order_ids[ $oid ] = $oid;
            }
        }
        $failed_orders = array();
        if ( $order_ids ) {
            $in            = implode( ',', array_map( 'absint', $order_ids ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a comma-separated list of absint IDs only.
            $failed_orders = $wpdb->get_col(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_picky_order_payment_status' AND meta_value = 'init_failed' AND post_id IN ({$in})"
            );
            $failed_orders = array_fill_keys( array_map( 'absint', (array) $failed_orders ), true );
        }

        $booked = array();
        foreach ( $rows as $row ) {
            $pay = isset( $row['pay_status'] ) ? sanitize_key( (string) $row['pay_status'] ) : '';
            if ( $pay === 'init_failed' ) {
                continue;
            }
            $oid = absint( $row['order_id'] ?? 0 );
            if ( $oid > 0 && isset( $failed_orders[ $oid ] ) ) {
                continue;
            }
            $time_to = isset( $row['booking_time_to'] ) ? $this->normalize_time_slot( (string) $row['booking_time_to'] ) : '';
            $booked_row = array(
                'id'      => (string) ( $row['booking_id'] ?? '' ),
                'date'    => (string) ( $row['booking_date'] ?? '' ),
                'time'    => $this->normalize_time_slot( (string) ( $row['booking_time'] ?? '' ) ),
                'post_ID' => absint( $row['post_id'] ?? 0 ),
                'unique'  => (string) ( $row['uniq'] ?? '' ),
                'user_id' => absint( $row['user_id'] ?? 0 ),
            );
            if ( $booked_row['user_id'] <= 0 ) {
                $booked_row['user_id'] = absint( $row['post_author'] ?? 0 );
            }
            if ( $time_to !== '' ) {
                $booked_row['time_to'] = $time_to;
            }
            $booked[] = $booked_row;
        }
        return $booked;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Attach guest payloads once per WP user (not once per booking).
     *
     * @param list<array<string,mixed>> $booked Booked rows.
     * @return void
     */
    private function picky_attach_map_booking_guests( &$booked ) {
        if ( empty( $booked ) || ! class_exists( __NAMESPACE__ . '\\Booking_Avatar' ) ) {
            return;
        }
        $user_ids = array();
        foreach ( $booked as $row ) {
            $uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
            if ( $uid > 0 ) {
                $user_ids[ $uid ] = $uid;
            }
        }
        if ( empty( $user_ids ) ) {
            return;
        }
        $cache = array();
        foreach ( $user_ids as $uid ) {
            if ( user_can( $uid, 'manage_options' ) ) {
                continue;
            }
            $user  = get_userdata( $uid );
            $name  = $user ? (string) $user->display_name : '';
            $email = $user && ! empty( $user->user_email ) ? (string) $user->user_email : '';
            if ( $name === '' && $user ) {
                $name = (string) $user->user_login;
            }
            if ( $name === '' ) {
                continue;
            }
            $cache[ $uid ] = array(
                'name'     => $name,
                'avatar'   => Booking_Avatar::resolve_avatar_url( $uid, $email ),
                'initials' => Booking_Avatar::initials_from_name( $name, $email ),
            );
        }
        foreach ( $booked as $k => $row ) {
            $uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
            if ( $uid > 0 && isset( $cache[ $uid ] ) ) {
                $booked[ $k ]['guest'] = $cache[ $uid ];
            }
        }
    }

    /**
     * Index map bookings by canvas item id.
     *
     * @param list<array<string,mixed>> $booked Booked rows.
     * @return array<string, list<array<string,mixed>>>
     */
    private function picky_bookings_by_item_id( $booked ) {
        $out = array();
        foreach ( $booked as $row ) {
            $iid = isset( $row['id'] ) ? (string) $row['id'] : '';
            if ( $iid === '' ) {
                continue;
            }
            if ( ! isset( $out[ $iid ] ) ) {
                $out[ $iid ] = array();
            }
            $out[ $iid ][] = $row;
        }
        return $out;
    }

    /**
     * Calendar date/time excludes for one canvas item.
     *
     * @param list<array<string,mixed>> $item_bookings Bookings for this item.
     * @param array                     $time_all      Slot list.
     * @param array                     $options       Plugin settings.
     * @return array{0:list<string>,1:list<array<string,mixed>>}
     */
    private function picky_element_booking_excludes( $item_bookings, $time_all, $options ) {
        $data_exclude = array();
        $time_exclude = array();
        if ( empty( $item_bookings ) || ! is_array( $item_bookings ) ) {
            return array( $data_exclude, $time_exclude );
        }
        $time_all = is_array( $time_all ) ? $time_all : array();
        $counts   = array();
        foreach ( $item_bookings as $value ) {
            $d = isset( $value['date'] ) ? (string) $value['date'] : '';
            if ( $d === '' ) {
                continue;
            }
            if ( ! isset( $counts[ $d ] ) ) {
                $counts[ $d ] = 0;
            }
            $counts[ $d ]++;
        }
        $slot_n = count( $time_all );
        foreach ( $counts as $date => $n ) {
            if ( $slot_n > 0 && $n >= $slot_n ) {
                $data_exclude[] = $date;
            }
        }
        $booked_h = $this->picky_int_option_hours( $options['table_booked_time'] ?? 0 );
        $before_h = $this->picky_int_option_hours( $options['table_before_booked_time'] ?? 0 );
        foreach ( $item_bookings as $value ) {
            $guest = ( ! empty( $value['guest'] ) && is_array( $value['guest'] ) ) ? $value['guest'] : null;
            $time_exclude[] = $this->picky_time_exclude_row( $value['date'] ?? '', $value['time'] ?? '', $guest );
            $booked_time = array_search( $value['time'] ?? '', $time_all, true );
            $booked_time_difference = '';
            if ( isset( $value['time_to'] ) && $value['time_to'] !== '' ) {
                $booked_time_to = array_search( $value['time_to'], $time_all, true );
                if ( $booked_time !== false && $booked_time_to !== false && $booked_time_to > $booked_time ) {
                    $booked_time_difference = $booked_time_to - $booked_time;
                }
            }
            if ( $booked_time === false ) {
                continue;
            }
            if ( $booked_h > 0 && $booked_time_difference === '' ) {
                for ( $i = 1; $i <= $booked_h * 2 - 1; $i++ ) {
                    if ( ! empty( $time_all[ $booked_time + $i ] ) ) {
                        $time_exclude[] = $this->picky_time_exclude_row( $value['date'], $time_all[ $booked_time + $i ], $guest );
                    }
                }
            } elseif ( $booked_time_difference !== '' ) {
                for ( $i = 1; $i <= $booked_time_difference - 1; $i++ ) {
                    if ( ! empty( $time_all[ $booked_time + $i ] ) ) {
                        $time_exclude[] = $this->picky_time_exclude_row( $value['date'], $time_all[ $booked_time + $i ], $guest );
                    }
                }
            }
            if ( $before_h > 0 ) {
                for ( $i = 1; $i <= $before_h * 2 - 1; $i++ ) {
                    if ( ! empty( $time_all[ $booked_time - $i ] ) ) {
                        $time_exclude[] = $this->picky_time_exclude_row( $value['date'], $time_all[ $booked_time - $i ], $guest );
                    }
                }
            }
        }
        return array( $data_exclude, $time_exclude );
    }

    /**
     * Integer hours from settings (empty or non-numeric => 0) for safe arithmetic in PHP 8+.
     *
     * @param mixed $value Option value from saved settings.
     * @return int
     */
    private function picky_int_option_hours( $value ): int {
        if ( $value === null || $value === false ) {
            return 0;
        }
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( $value === '' ) {
                return 0;
            }
        }
        if ( is_numeric( $value ) ) {
            return max( 0, (int) $value );
        }
        return 0;
    }

    /**
     * Build consecutive day descriptors in the WordPress site timezone.
     *
     * @param int $days_ahead
     * @return array<int,array<string,string>>
     */
    private function picky_build_wp_local_days_ahead( $days_ahead ) {
        $days = array();
        $total = max( 0, (int) $days_ahead );
        if ( $total === 0 ) {
            return $days;
        }
        $tz = wp_timezone();
        $base = new \DateTimeImmutable( 'today', $tz );
        for ( $i = 0; $i < $total; $i++ ) {
            $dt = $base->modify( '+' . $i . ' days' );
            $days[$i] = array(
                'number' => $dt->format( 'm/d/Y' ),
                'string' => $dt->format( 'l' ),
            );
        }
        return $days;
    }

    // check if restaurant work on each day of the week
    function daysOff(){
    	$days_off = array();
    	$opts = $this->settings;

        $days_map = array(
            'work_days_monday'    => 'Monday',
            'work_days_tuesday'   => 'Tuesday',
            'work_days_wednesday' => 'Wednesday',
            'work_days_thursday'  => 'Thursday',
            'work_days_friday'    => 'Friday',
            'work_days_saturday'  => 'Saturday',
            'work_days_sunday'    => 'Sunday',
        );

        foreach ($days_map as $key => $day) {
            $val = isset($opts[$key]) ? intval($opts[$key]) : 0;
            if ($val !== 1) {
                $days_off[] = $day;
            }
        }

        return $days_off;
    }

    function in_array_r($needle, $haystack, $strict = false) {
        foreach ($haystack as $item) {
            if (($strict ? $item === $needle : $item === $needle) || (is_array($item) && in_array_r($needle, $item, $strict))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wizard footer: step dots above Continue / Book CTA.
     *
     * @param bool   $payment_required Whether the final CTA is "Pay & Book".
     * @param string $form_message     Optional manager message HTML (already sanitized upstream).
     * @return string
     */
    private function picky_wizard_footer_html( $payment_required, $form_message = '' ) {
        $book_label = $payment_required ? __( 'Pay & Book', 'pickybooking' ) : __( 'Book Now', 'pickybooking' );
        $html  = '<div class="picky-wizard-footer">';
        $html .= '<div class="picky-wizard-dots" role="tablist" aria-label="' . esc_attr__( 'Booking steps', 'pickybooking' ) . '">';
        $html .= '<button type="button" class="picky-wizard-dot is-active" data-step="1" aria-label="' . esc_attr__( 'Step 1: Date and time', 'pickybooking' ) . '"></button>';
        $html .= '<button type="button" class="picky-wizard-dot" data-step="2" aria-label="' . esc_attr__( 'Step 2: Services', 'pickybooking' ) . '"></button>';
        $html .= '<button type="button" class="picky-wizard-dot" data-step="3" aria-label="' . esc_attr__( 'Step 3: Contact details', 'pickybooking' ) . '"></button>';
        $html .= '</div>';
        // One CTA only: label switches Continue → Pay & Book on the last step (JS).
        $html .= '<button type="button" class="picky-wizard-cta"';
        $html .= ' data-label-continue="' . esc_attr__( 'Continue', 'pickybooking' ) . '"';
        $html .= ' data-label-book="' . esc_attr( $book_label ) . '">';
        $html .= '<span class="picky-wizard-cta-text">' . esc_html__( 'Continue', 'pickybooking' ) . '</span>';
        $html .= ' <span class="picky-wizard-continue-arrow" aria-hidden="true">→</span>';
        $html .= '</button>';
        if ( $form_message !== '' ) {
            $html .= '<div class="picky-message">' . wp_kses_post( $form_message ) . '</div>';
        }
        $html .= '<div class="picky-send-respons-error"></div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Heading for a wizard step panel.
     *
     * @param string $text Heading text.
     * @return string
     */
    private function picky_wizard_step_title_html( $text ) {
        return '<h3 class="picky-wizard-step-title">' . esc_html( $text ) . '</h3>';
    }

    /**
     * Soft WP login / register strip when Membership is open (never required to book).
     *
     * @param array<string,mixed> $options Plugin settings (unused; kept for call sites).
     * @return string
     */
    private function picky_booking_account_bar_html( $options = array() ) {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI used for return URL via esc_url
        unset( $options );
        if ( ! Booking_Avatar::map_avatars_enabled() ) {
            return '';
        }

        $redirect = home_url( '/' );
        if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
            $host = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) );
            $uri  = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
            if ( $host !== '' && $uri !== '' ) {
                $redirect = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
            }
        }
        $redirect = esc_url_raw( $redirect );

        $html = '<div class="picky-account-bar">';
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $uid  = (int) $user->ID;
            $name = (string) $user->display_name;
            if ( $name === '' ) {
                $name = (string) $user->user_login;
            }
            $avatar = get_avatar( $uid, 40, 'mp', $name, array( 'class' => 'picky-account-bar__avatar' ) );
            $html  .= '<div class="picky-account-bar__user">';
            $html  .= $avatar;
            $html  .= '<div class="picky-account-bar__meta">';
            $html  .= '<span class="picky-account-bar__label">' . esc_html__( 'Booking as', 'pickybooking' ) . '</span>';
            $html  .= '<strong class="picky-account-bar__name">' . esc_html( $name ) . '</strong>';
            $html  .= '</div>';
            $html  .= '<a class="picky-account-bar__link" href="' . esc_url( wp_logout_url( $redirect ) ) . '">' . esc_html__( 'Log out', 'pickybooking' ) . '</a>';
            $html  .= '</div>';
        } else {
            $login_url    = wp_login_url( $redirect );
            $register_url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), wp_registration_url() );
            $html        .= '<div class="picky-account-bar__guest">';
            $html        .= '<p class="picky-account-bar__msg">' . esc_html__( 'Log in to show your avatar on the map. You can also book as a guest.', 'pickybooking' ) . '</p>';
            $html        .= '<div class="picky-account-bar__actions">';
            $html        .= '<a class="button picky-account-bar__btn" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'pickybooking' ) . '</a>';
            $html        .= '<a class="button button-primary picky-account-bar__btn" href="' . esc_url( $register_url ) . '">' . esc_html__( 'Register', 'pickybooking' ) . '</a>';
            $html        .= '</div>';
            $html        .= '</div>';
        }
        $html .= '</div>';
        return $html;
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    /**
     * Prefill values for name/email from the logged-in WP user.
     *
     * @return array{name:string,email:string,logged_in:bool,readonly:string}
     */
    private function picky_booking_contact_identity() {
        if ( ! is_user_logged_in() ) {
            return array(
                'name'      => '',
                'email'     => '',
                'logged_in' => false,
                'readonly'  => '',
            );
        }
        $user = wp_get_current_user();
        $name = trim( (string) $user->display_name );
        if ( $name === '' ) {
            $name = trim( (string) $user->user_login );
        }
        return array(
            'name'      => $name,
            'email'     => (string) $user->user_email,
            'logged_in' => true,
            'readonly'  => ' readonly',
        );
    }

    /**
     * Slot button grids (start / optional end) — filled by JS; selects stay for sync.
     *
     * @param bool $show_time_to Whether end-time grid is needed.
     * @return string
     */
    private function picky_slot_grids_html( $show_time_to = false, $label_class = 'picky-input-label' ) {
        $label_class = trim( (string) $label_class );
        if ( $label_class === '' ) {
            $label_class = 'picky-input-label';
        }
        $html  = '<div class="picky-slot-picker">';
        $html .= '<div class="picky-slot-picker-block picky-slot-picker-start">';
        $html .= '<label class="' . esc_attr( $label_class ) . '">' . esc_html__( 'Select the start time', 'pickybooking' ) . '</label>';
        $html .= '<div class="picky-slot-grid" data-slot-role="start" role="listbox" aria-label="' . esc_attr__( 'Available start times', 'pickybooking' ) . '"></div>';
        $html .= '<div class="picky-error picky-error-empty picky-slot-error-start">' . esc_html__( 'Please, chose time', 'pickybooking' ) . '</div>';
        $html .= '</div>';
        if ( $show_time_to ) {
            $html .= '<div class="picky-slot-picker-block picky-slot-picker-end" style="display:none;">';
            $html .= '<label class="' . esc_attr( $label_class ) . '">' . esc_html__( 'Select the end time', 'pickybooking' ) . '</label>';
            $html .= '<div class="picky-slot-grid" data-slot-role="end" role="listbox" aria-label="' . esc_attr__( 'Available end times', 'pickybooking' ) . '"></div>';
            $html .= '<div class="picky-error picky-error-time picky-slot-error-end" data-error-text="' . esc_attr__( 'Invalid end time', 'pickybooking' ) . '">' . esc_html__( 'Invalid end time', 'pickybooking' ) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    function daysAhead(){
    	$days_ahead = isset($this->settings['days_ahead']) ? intval($this->settings['days_ahead']) : 30;
        return $days_ahead;
    }

    // check if restaurant work on each day of the week and in which time
    function timesOff(){
    	$times_off = array();
        $opts = $this->settings;

        $days = array(
            0  => 'monday',
            2  => 'tuesday',
            4  => 'wednesday',
            6  => 'thursday',
            8  => 'friday',
            10 => 'saturday',
            12 => 'sunday',
        );

        foreach ($days as $idx => $day) {
            $day_uc = ucfirst($day);
            $start_key = "work_days_{$day}_time_start";
            $end_key   = "work_days_{$day}_time_end";
            
            $times_off[$idx] = array(
                'time_start' => isset($opts[$start_key]) ? $opts[$start_key] : '',
                'time_end'   => isset($opts[$end_key]) ? $opts[$end_key] : '',
                'day'        => $day_uc
            );

            // Check if there is a 'more' schedule enabled
            $more_check_key = "work_days_{$day}_more";
            if (isset($opts[$more_check_key]) && !empty($opts[$more_check_key])) {
                $start_more_key = "work_days_{$day}_time_start_more";
                $end_more_key   = "work_days_{$day}_time_end_more";
                $times_off[$idx]['time_start_more'] = isset($opts[$start_more_key]) ? $opts[$start_more_key] : '';
                $times_off[$idx]['time_end_more']   = isset($opts[$end_more_key]) ? $opts[$end_more_key] : '';
            }
        }

        return $times_off;
    }



	/**
	 * Keep only valid CSS class tokens from a shortcode attribute.
	 *
	 * @param mixed $classes Raw class string.
	 * @return string
	 */
	private function picky_sanitize_class_list( $classes ) {
		$parts = preg_split( '/\s+/', (string) $classes );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$out = array();
		foreach ( $parts as $part ) {
			$class = sanitize_html_class( $part );
			if ( $class !== '' ) {
				$out[] = $class;
			}
		}
		return implode( ' ', $out );
	}

	function rest_booking( $atts, $content ) {

        $atts = shortcode_atts(
            array(
                'picky_shortcode_class' => '',
                'picky_shortcode_id'    => '',
                'id'                    => '0',
            ),
            $atts
        );
        $picky_shortcode_class = $this->picky_sanitize_class_list( isset( $atts['picky_shortcode_class'] ) ? $atts['picky_shortcode_class'] : '' );
        $picky_shortcode_id    = sanitize_html_class( isset( $atts['picky_shortcode_id'] ) ? (string) $atts['picky_shortcode_id'] : '' );
        $id                    = isset( $atts['id'] ) ? $atts['id'] : '0';

        $layout_id = intval( $id );

	    // Defensive option read: the shortcode renders on every public page
	    // and was the most likely path to surface "Undefined index" notices
	    // on PHP 7 / fatal arithmetic on PHP 8+ for empty option rows.
	    $options = Info::get_settings();
	    // Element scale is a CSS class token (small / medium / big in the stylesheet). Canvas width and height stay numeric and are not limited here.
	    $canvas_size = isset( $options['canvas_elements_size'] ) ? sanitize_html_class( (string) $options['canvas_elements_size'] ) : '';
	    $options['canvas_elements_size'] = $canvas_size !== '' ? $canvas_size : 'medium';

        // Button text: "Pay & Book" whenever "Require online payment" is on AND a
        // provider is configured AND the configured amount source can yield a
        // chargeable total (fixed > 0, services-mode anything, or fallback configured).
        // The exact amount is computed server-side from selected services + deposit_amount.
        $picky_payment_amount_source = isset( $options['payment_amount_source'] ) ? (string) $options['payment_amount_source'] : 'fixed';
        $picky_deposit_amount        = isset( $options['deposit_amount'] ) ? (float) $options['deposit_amount'] : 0.0;
        $picky_amount_can_be_charged = (
            $picky_payment_amount_source === 'services'
            || $picky_payment_amount_source === 'services_or_fixed'
            || ( $picky_payment_amount_source === 'fixed' && $picky_deposit_amount > 0 )
        );
        $picky_payment_required = (
            class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'payments' )
            && isset( $options['deposit_enabled'] ) && $options['deposit_enabled'] === '1'
            && isset( $options['payment_provider'] ) && in_array( (string) $options['payment_provider'], array( 'stripe', 'woocommerce' ), true )
            && $picky_amount_can_be_charged
        );

        // Use per-layout canvas dimensions when stored — keeps each layout independent
        // of global canvas_width/height setting changes.
        if ( is_array( $options ) && $layout_id > 0 ) {
            $per_layout_w = (int) get_post_meta( $layout_id, '_picky_layout_canvas_width',  true );
            $per_layout_h = (int) get_post_meta( $layout_id, '_picky_layout_canvas_height', true );
            if ( $per_layout_w > 0 ) { $options['canvas_width']  = $per_layout_w; }
            if ( $per_layout_h > 0 ) { $options['canvas_height'] = $per_layout_h; }

            // Per-layout wizard (Classic / Smart / Smart Map / Simple). Empty meta = site default.
            $picky_layout_wizard = (string) get_post_meta( $layout_id, '_picky_layout_shortcode_layout', true );
            if ( in_array( $picky_layout_wizard, array( '1', '2', '3', '4' ), true ) ) {
                $options['shortcode_layout'] = $picky_layout_wizard;
            }
        }

        // Per-layout: no grid / gray behind the floor plan on the public shortcode.
        $picky_canvas_transparent_bg = ( $layout_id > 0 && get_post_meta( $layout_id, '_picky_layout_transparent_canvas', true ) === '1' );
        $picky_hide_item_fill        = ( $layout_id > 0 && get_post_meta( $layout_id, '_picky_layout_show_item_fill', true ) === '0' );
        $picky_canvas_wrap_class     = $picky_canvas_transparent_bg ? ' picky-canvas-transparent-bg' : '';
        $picky_canvas_shell_class    = $picky_canvas_transparent_bg ? ' picky-canvas-transparent-bg' : '';
        if ( $picky_hide_item_fill ) {
            $picky_canvas_shell_class .= ' picky-no-item-fill';
        }
        $picky_layout_skin_class = 'picky-layout-skin-' . max( 0, (int) $layout_id );
        $picky_canvas_shell_class .= ' ' . $picky_layout_skin_class;
        // Public map motion: enter stagger + hover scale (default on).
        if ( isset( $options['animate_bookable_items'] ) && (string) $options['animate_bookable_items'] === '0' ) {
            $picky_canvas_shell_class .= ' picky-no-bookable-anim';
        }

        // Per-layout backgrounds from layout builder (with fallback to global settings).
        $layout_bg_canvas = '';
        $layout_bg_table  = '';
        if ( $layout_id > 0 ) {
            $raw_canvas = get_post_meta( $layout_id, '_picky_layout_bg_canvas', true );
            if ( is_numeric( $raw_canvas ) && intval( $raw_canvas ) > 0 ) {
                $layout_bg_canvas = (string) wp_get_attachment_url( intval( $raw_canvas ) );
            } else if ( is_scalar( $raw_canvas ) ) {
                $layout_bg_canvas = (string) $raw_canvas;
            }
            $raw_table = get_post_meta( $layout_id, '_picky_layout_bg_table', true );
            if ( is_numeric( $raw_table ) && intval( $raw_table ) > 0 ) {
                $layout_bg_table = (string) wp_get_attachment_url( intval( $raw_table ) );
            } else if ( is_scalar( $raw_table ) ) {
                $layout_bg_table = (string) $raw_table;
            }
        }
        if ( $layout_bg_canvas === '' && isset( $options['background-canvas'] ) && is_scalar( $options['background-canvas'] ) ) {
            $layout_bg_canvas = (string) $options['background-canvas'];
        }
        if ( $layout_bg_table === '' && isset( $options['background-table'] ) && is_scalar( $options['background-table'] ) ) {
            $layout_bg_table = (string) $options['background-table'];
        }

        $layout_bg_canvas_color = picky_layout_canvas_bg_color( $layout_id, false );
        $layout_bg_table_color  = picky_layout_item_bg_color( $layout_id, false );

	    $time_all = INFO::$TIME_ALL;
	    $default_options = INFO::$DEFAULT_OPTIONS;
        $output = "";
        
        // Add security nonce field for AJAX verification.
        // Use class="picky-nonce" so the JS can find it scoped to the form even
        // when several shortcodes are rendered on the same page (duplicate
        // ids would otherwise yield the wrong/empty value for any but the first).
        $output .= '<input type="hidden" id="picky_nonce" class="picky-nonce" name="picky_nonce" value="' . esc_attr( wp_create_nonce( 'picky_send_book_action' ) ) . '">';

	    if($options){

            $form_message = '';
            if(isset($options['form_message']) && !empty($options['form_message'])){
                $form_message = $options['form_message'];
            } 

            $form_confirm = '';
            if(isset($options['form_confirm']) && !empty($options['form_confirm'])){
                if(isset($options['form_confirm_text']) && !empty($options['form_confirm_text'])){
                    $form_confirm .= '<div class="picky-input-wrap">';
                        $form_confirm .= '<div class="picky-input-inner">';

                            $form_confirm .= '<div class="picky-confirm-checkbox">';
                                $form_confirm .= '<div class="picky-confirm-checkbox-wrap">';
                                    $form_confirm .= '<input type="checkbox" class="picky-checkbox" id="picky_booking_form_confirm" name="picky_booking_form_confirm" value="1">';
                                $form_confirm .= '</div>';
                                $form_confirm .= '<div class="picky-confirm-checkbox-text-wrap">';
                                    $form_confirm .= '<label class="picky-confirm-label" for="picky_booking_form_confirm">';
                                        $form_confirm .= wp_kses_post( $options['form_confirm_text'] );
                                    $form_confirm .= '</label>';
                                $form_confirm .= '</div>';
                            $form_confirm .= '</div>';

                            $form_confirm .= '<div class="picky-error picky-error-empty picky-confirm-error">'.esc_html__('Please, check the checkbox', 'pickybooking').'</div>';

                        $form_confirm .= '</div>';    
                    $form_confirm .= '</div>'; 
                }
            }
               
            

            // Get layout position from CPT post meta (id param) or fall back to legacy option
            if ( $layout_id > 0 && get_post_type( $layout_id ) === 'picky_layout' ) {
                $canvas_data = get_post_meta( $layout_id, '_picky_layout_position', true );
            } else {
                $canvas_data = isset( $options['position'] ) ? $options['position'] : '';
            }

	    	$postion = urldecode($canvas_data);
		    $jsonPosition = json_decode($postion, TRUE);

		    if(!empty($canvas_data)){
                $max_people_option = 10;
                if ( is_array( $jsonPosition ) ) {
                    foreach ( $jsonPosition as $position_element ) {
                        if ( is_array( $position_element ) && isset( $position_element['max_seats'] ) ) {
                            $max_people_option = max( $max_people_option, intval( $position_element['max_seats'] ) );
                        }
                    }
                }
                $people_all = range( 1, max( 1, $max_people_option ) );

		    	// Never dump reservation history into the shortcode HTML: tens of thousands
		    	// of rows OOM PHP and crash WP's HTML tag processor (white screen). Occupancy
		    	// and avatars load per selected date/time via get_available_tables.
		    	$booked = array();
		      	
                $getOption = function($option, $options){
                    $default_options = INFO::$DEFAULT_OPTIONS;
                    if(isset($options[$option]) && $options[$option] !== ""){
                        return $options[$option];
                    }
                    if(isset($default_options[$option])){
                        return $default_options[$option];
                    }
                    return '';
                };

                /* Settings → Visibility on the booking form & layout: map badges/labels. */
                $picky_show_seats_on_map   = (string) $getOption( 'show_seats', $options ) === '1';
                $picky_show_people_on_map = (string) $getOption( 'show_people', $options ) === '1';
                $picky_show_ids_on_map    = (string) $getOption( 'show_id_badge', $options ) === '1';
                $picky_show_labels_on_map = (string) $getOption( 'show_element_label', $options ) === '1';
                $picky_show_booked_avatars = ( $layout_id > 0 )
                    ? Booking_Avatar::layout_shows_avatars( $layout_id )
                    : false;
                $picky_booked_avatars_tone = 'color';
                if ( $layout_id > 0 ) {
                    $tone_meta = (string) get_post_meta( $layout_id, '_picky_layout_booked_avatars_tone', true );
                    if ( $tone_meta === 'bw' ) {
                        $picky_booked_avatars_tone = 'bw';
                    }
                }
                if ( $layout_id > 0 && get_post_type( $layout_id ) === 'picky_layout' ) {
                    $picky_meta_seats = get_post_meta( $layout_id, '_picky_layout_show_seats', true );
                    if ( $picky_meta_seats === '0' || $picky_meta_seats === '1' ) {
                        $picky_show_seats_on_map = ( $picky_meta_seats === '1' );
                    }
                    $picky_meta_ids = get_post_meta( $layout_id, '_picky_layout_show_ids', true );
                    if ( $picky_meta_ids === '0' || $picky_meta_ids === '1' ) {
                        $picky_show_ids_on_map = ( $picky_meta_ids === '1' );
                    }
                    $picky_meta_labels = get_post_meta( $layout_id, '_picky_layout_show_labels', true );
                    if ( $picky_meta_labels === '0' || $picky_meta_labels === '1' ) {
                        $picky_show_labels_on_map = ( $picky_meta_labels === '1' );
                    }
                }

                // Attach guest payloads only for bookings made by logged-in WP users.
                if ( $picky_show_booked_avatars && ! empty( $booked ) ) {
                    $this->picky_attach_map_booking_guests( $booked );
                }
                $booked_by_item_id = $this->picky_bookings_by_item_id( $booked );

                $color_main = $getOption('color_main_main', $options);
                $color_tables = $getOption('color_main_tables', $options);
                $color_seats = $getOption('color_main_seats', $options);
                $color_max_seats = $getOption('color_main_max_seats', $options);
                $color_max_seats_text = $getOption('color_main_max_seats_text', $options);
                $color_table_id = $getOption('color_main_table_id', $options);
                $color_table_id_text = $getOption('color_main_table_id_text', $options);

                $color_datepicker_text_all  = $getOption('color_datepicker_datepicker_text_all', $options);
                $color_datepicker = $getOption('color_datepicker_datepicker', $options);
                $color_datepicker_text = $getOption('color_datepicker_datepicker_text', $options);
                $color_datepicker_disabled = $getOption('color_datepicker_datepicker_disabled', $options);
                $color_datepicker_text_disabled= $getOption('color_datepicker_datepicker_text_disabled',$options);
                $color_datepicker_active = $getOption('color_datepicker_datepicker_active', $options);
                $color_datepicker_text_active = $getOption('color_datepicker_datepicker_text_active', $options);
                $color_datepicker_arrow = $getOption('color_datepicker_datepicker_arrow', $options);
                $color_datepicker_arrow_hover = $getOption('color_datepicker_datepicker_arrow_hover', $options);
                $color_datepicker_arrow_background = $getOption('color_datepicker_datepicker_arrow_background', $options);
                $color_datepicker_arrow_disabled = $getOption('color_datepicker_datepicker_arrow_disabled', $options);
                $color_datepicker_arrow_background_disabled = $getOption('color_datepicker_datepicker_arrow_background_disabled', $options);

                $color_form = $getOption('color_form_form', $options);
                $color_small_titles = $getOption('color_form_small_titles', $options);
                $color_small_descriptions = $getOption('color_form_small_descriptions', $options);
                $color_big_titles = $getOption('color_form_big_titles', $options);
                $color_booking_title = $getOption('color_form_booking_title', $options);
                $color_book_now = $getOption('color_form_book_now', $options);
                $color_book_now_text = $getOption('color_form_book_now_text', $options);
                $color_inputs = $getOption('color_form_inputs', $options);
                $color_inputs_text = $getOption('color_form_inputs_text', $options);
                $color_error = $getOption('color_form_error', $options);
                $color_error_text = $getOption('color_form_error_text', $options);
                $color_message = $getOption('color_form_message', $options);
                $color_message_text = $getOption('color_form_message_text', $options);

                $picky_css_hex = static function( $c ) {
                    $hex = picky_sanitize_hex_color( (string) $c );
                    return ( is_string( $hex ) && $hex !== '' ) ? $hex : '';
                };
                foreach ( array(
                    'color_main', 'color_tables', 'color_seats', 'color_max_seats', 'color_max_seats_text', 'color_table_id', 'color_table_id_text',
                    'color_datepicker_text_all', 'color_datepicker', 'color_datepicker_text', 'color_datepicker_disabled', 'color_datepicker_text_disabled',
                    'color_datepicker_active', 'color_datepicker_text_active', 'color_datepicker_arrow', 'color_datepicker_arrow_hover',
                    'color_datepicker_arrow_background', 'color_datepicker_arrow_disabled', 'color_datepicker_arrow_background_disabled',
                    'color_form', 'color_small_titles', 'color_small_descriptions', 'color_big_titles', 'color_booking_title',
                    'color_book_now', 'color_book_now_text', 'color_inputs', 'color_inputs_text', 'color_error', 'color_error_text',
                    'color_message', 'color_message_text',
                ) as $picky_ck ) {
                    $$picky_ck = $picky_css_hex( $$picky_ck );
                }

                $picky_booking_css = '';
                    if($color_main !== ""){
                        // .picky-tables-datapicker-wrap omitted — structural only; shell tint is .picky-tables-wrap (+ calendar rules).
                        $picky_booking_css .= '.picky-element-table:hover, 
                        .picky-tables-wrap, 
                        .picky-tables-wrap-third .picky-input-wrap.picky-calendar-left, 
                        .picky-tables-wrap-default .picky-input-wrap.picky-calendar-left{
                            background-color: ' . esc_attr( $color_main ) . '!important;
                        }';
                    }

                    if($color_tables !== "" && ! $picky_hide_item_fill){
                        // builder-v2: table visuals live in .picky-element-shape; .picky-element-table is forced transparent in CSS.
                        // Apply global table color only when the item doesn't already define its own color or background image.
                        // Exclude text elements (.type-text) and shape elements (.type-shape) so they keep their own appearance.
                        $picky_booking_css .= '.picky-tables-wrap .picky-element:not(.type-text):not(.type-shape):not(.type-icon) .picky-element-shape:not([style*="background-image"]):not([style*="background-color"]){
                            background-color: ' . esc_attr( $color_tables ) . '!important;
                        }';
                    }

                    // Per-layout canvas / item plate colors (Layout appearance in the builder).
                    if ( ! $picky_canvas_transparent_bg && $layout_bg_canvas_color !== '' ) {
                        // Solid color must replace the default dotted canvas_back.png plate.
                        $picky_booking_css .= '.' . esc_attr( $picky_layout_skin_class ) . '.picky-tables-wrap{background-color:' . esc_attr( $layout_bg_canvas_color ) . '!important;}';
                        $picky_booking_css .= '.' . esc_attr( $picky_layout_skin_class ) . ' .picky-draggable-wrap:not(.picky-canvas-transparent-bg){background-color:' . esc_attr( $layout_bg_canvas_color ) . '!important;background-image:none!important;}';
                        $picky_booking_css .= '.' . esc_attr( $picky_layout_skin_class ) . ' .picky-draggable:not(.picky-canvas-transparent-bg){background-color:' . esc_attr( $layout_bg_canvas_color ) . ';background-image:none;}';
                    }
                    if ( ! $picky_hide_item_fill && $layout_bg_table_color !== '' ) {
                        $picky_booking_css .= '.' . esc_attr( $picky_layout_skin_class ) . ' .picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon) .picky-element-shape:not([style*="background-image"]):not([style*="background-color"]){background-color:' . esc_attr( $layout_bg_table_color ) . '!important;}';
                    }

                    if($color_seats !== ""){
                        $picky_booking_css .= '.picky-element .picky-element-seats-wrap .picky-element-seat{
                            background-color: ' . esc_attr( $color_seats ) . '!important;
                        }';
                    }

                    if($color_max_seats !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-element .picky-element-seats{
                            /* Capacity badge is unboxed on the map — ignore fill from settings. */
                            background-color: transparent!important;
                        }';
                    }

                    if($color_max_seats_text !== ""){
                        $picky_booking_css .= '.picky-tables-wrap{--picky-map-seats-color:' . esc_attr( $color_max_seats_text ) . ';}';
                        $picky_booking_css .= '.picky-tables-wrap .picky-element .picky-element-seats{
                            color: ' . esc_attr( $color_max_seats_text ) . '!important;
                        }';
                    } elseif ( $color_table_id_text !== '' ) {
                        /* Keep hover capacity badge aligned with ID text color. */
                        $picky_booking_css .= '.picky-tables-wrap{--picky-map-seats-color:' . esc_attr( $color_table_id_text ) . ';}';
                        $picky_booking_css .= '.picky-tables-wrap .picky-element .picky-element-seats{
                            color: ' . esc_attr( $color_table_id_text ) . '!important;
                        }';
                    }

                    if($color_table_id !== ""){
                        /* ID is unboxed text on the plate — do not paint a badge fill. */
                        $picky_booking_css .= '.picky-tables-wrap .picky-element .picky-element-id{
                            background-color: transparent!important;
                        }';
                    }

                    if($color_table_id_text !== ""){
                        $picky_booking_css .= '.picky-tables-wrap{--picky-map-id-color:' . esc_attr( $color_table_id_text ) . ';}';
                        $picky_booking_css .= '.picky-tables-wrap .picky-element .picky-element-id{
                            color: ' . esc_attr( $color_table_id_text ) . '!important;
                        }';
                    }

                    // Public CTA colors from Layout Colors → Submit button (not plugin --picky-brand).
                    if ( $color_book_now !== '' ) {
                        $picky_booking_css .= '.picky-tables-wrap{--brand:' . esc_attr( $color_book_now ) . ';}';
                        $picky_booking_css .= '.picky-send-booking,.picky-wizard .picky-wizard-cta,.picky-selection-tray-book-btn{
                            background-color: ' . esc_attr( $color_book_now ) . '!important;
                            background: ' . esc_attr( $color_book_now ) . '!important;
                        }';
                    }

                    if ( $color_book_now_text !== '' ) {
                        $picky_booking_css .= '.picky-tables-wrap{--brand-text:' . esc_attr( $color_book_now_text ) . ';}';
                        $picky_booking_css .= '.picky-send-booking,.picky-wizard .picky-wizard-cta,.picky-selection-tray-book-btn{
                            color: ' . esc_attr( $color_book_now_text ) . '!important;
                        }';
                    }

                    if($color_form !== ""){
                        // Apply the same base form/panel background to every booking flow
                        // (Classic, Smart, and Simple) for consistent out-of-the-box colors.
                        $picky_booking_css .= '.picky-tables-wrap .picky-order-wrap,
                        .picky-tables-wrap .picky-form-booking,
                        .picky-tables-wrap .picky-form-booking-second,
                        .picky-tables-wrap .picky-form-booking-third,
                        .picky-tables-wrap .picky-form-thx {
                            background-color: ' . esc_attr( $color_form ) . '!important;
                        }';
                    }

                    if($color_big_titles !== ""){
                        $picky_booking_css .= '.picky-input-label-big{
                            color: ' . esc_attr( $color_big_titles ) . '!important;
                        }';
                    }
                    if($color_booking_title){
                        $picky_booking_css .= '.picky-form-booking-title{
                            color: ' . esc_attr( $color_booking_title ) . '!important;
                        }';
                    }

                    if($color_small_titles !== ""){
                        $picky_booking_css .= '.picky-input-label{
                            color: ' . esc_attr( $color_small_titles ) . '!important;
                        }';
                    }

                    if($color_small_descriptions !== ""){
                        $picky_booking_css .= '.picky-small-text, .picky-confirm-checkbox-text-wrap .picky-confirm-label a, .picky-confirm-checkbox-text-wrap .picky-confirm-label{
                            color: ' . esc_attr( $color_small_descriptions ) . '!important;
                        }';
                    }

                    if($color_datepicker_text_all !==''){
                        $picky_booking_css .= '.ui-datepicker .ui-datepicker-title .ui-datepicker-month, .ui-datepicker .ui-datepicker-title .ui-datepicker-year, .ui-datepicker .ui-datepicker-calendar th span{
                            color: ' . esc_attr( $color_datepicker_text_all ) . '!important;
                        }';
                    }
                    if($color_datepicker !== ""){
                        $picky_booking_css .= '.picky-datepicker .ui-datepicker-calendar td:not(.ui-state-disabled) a {
                            background-color: ' . esc_attr( $color_datepicker ) . '!important;
                        }';
                    }
                    if($color_datepicker_text !== ""){
                        $picky_booking_css .= '.picky-datepicker .ui-datepicker-calendar td:not(.ui-state-disabled) a {
                            color: ' . esc_attr( $color_datepicker_text ) . '!important;
                        }';
                    }

                    if($color_datepicker_disabled !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td.ui-state-disabled span {
                            background-color: ' . esc_attr( $color_datepicker_disabled ) . '!important;
                        }';
                    }
                    if($color_datepicker_text_disabled !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td.ui-state-disabled span {
                            color: ' . esc_attr( $color_datepicker_text_disabled ) . '!important;
                        }';
                        $picky_booking_css .= '.picky-datepicker .ui-datepicker-prev.ui-state-disabled, .picky-datepicker .ui-datepicker-next.ui-state-disabled{
                            color: ' . esc_attr( $color_datepicker_text_disabled ) . '!important;
                        }';
                    }

                    if($color_datepicker_active !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-current-day:not(.ui-state-disabled) a,
                        .picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td a.ui-state-active {
                            background-color: ' . esc_attr( $color_datepicker_active ) . '!important;
                        }';
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td a.ui-state-active::before {
                            border-color: ' . esc_attr( $color_datepicker_active ) . '!important;
                        }';
                    }
                    if($color_datepicker_text_active !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-current-day:not(.ui-state-disabled) a,
                        .picky-tables-wrap .picky-datepicker .ui-datepicker-calendar td a.ui-state-active {
                            color: ' . esc_attr( $color_datepicker_text_active ) . '!important;
                        }';
                    }
                    if($color_datepicker_arrow !== ""){
                        $picky_booking_css .= '.picky-datepicker .ui-datepicker-prev:not(.ui-state-disabled), .picky-datepicker .ui-datepicker-next:not(.ui-state-disabled){
                            color: ' . esc_attr( $color_datepicker_arrow ) . '!important;
                        }';
                    }
                    if($color_datepicker_arrow_hover !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-prev:not(.ui-state-disabled):hover, .picky-tables-wrap .picky-datepicker .ui-datepicker-next:not(.ui-state-disabled):hover {
                            background-color: ' . esc_attr( $color_datepicker_arrow_hover ) . '!important;
                        }';
                    }
                    if($color_datepicker_arrow_background !== ""){
                        $picky_booking_css .= '.picky-datepicker .ui-datepicker-prev:not(.ui-state-disabled), .picky-datepicker .ui-datepicker-next:not(.ui-state-disabled){
                            background-color: ' . esc_attr( $color_datepicker_arrow_background ) . '!important;
                        }';
                    }
                    if($color_datepicker_arrow_disabled !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-prev.ui-state-disabled::before, .picky-tables-wrap .picky-datepicker .ui-datepicker-next.ui-state-disabled::before {
                            color: ' . esc_attr( $color_datepicker_arrow_disabled ) . '!important;
                        }';
                    }
                    if($color_datepicker_arrow_background_disabled !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .picky-datepicker .ui-datepicker-prev.ui-state-disabled, .picky-tables-wrap .picky-datepicker .ui-datepicker-next.ui-state-disabled {
                            background-color: ' . esc_attr( $color_datepicker_arrow_background_disabled ) . '!important;
                        }';
                    }
                    

                    if($color_inputs !== ""){
                        $picky_booking_css .= '.picky-tables-wrap .select2-container--default .select2-selection--single,
                        .picky-tables-wrap input,
                        .picky-tables-wrap select {
                            background-color: ' . esc_attr( $color_inputs ) . '!important;
                        }';
                    }

                    if($color_inputs_text !== ""){
                        $picky_booking_css .= '.select2-container--default .select2-selection--single .select2-selection__rendered,
                        .picky-tables-wrap input,
                        .picky-tables-wrap select{
                            color: ' . esc_attr( $color_inputs_text ) . '!important;
                        }';
                    }

                    if($color_error !== ""){
                        $picky_booking_css .= '.picky-error{
                            background-color: ' . esc_attr( $color_error ) . '!important;
                        }';
                        $picky_booking_css .= '.picky-error:before{
                            border-color: transparent ' . esc_attr( $color_error ) . ' transparent transparent!important;
                        }';
                    }

                    if($color_error_text !== ""){
                        $picky_booking_css .= '.picky-error{
                            color: ' . esc_attr( $color_error_text ) . '!important;
                        }';
                    }

                    if($color_message !== ""){
                        $picky_booking_css .= '.picky-message{
                            background-color: ' . esc_attr( $color_message ) . '!important;
                        }';
                    }

                    if($color_message_text !== ""){
                        $picky_booking_css .= '.picky-message{
                            color: ' . esc_attr( $color_message_text ) . '!important;
                        }';
                    }

                    $picky_wz_form_bg = $color_form !== '' ? $color_form : '#ffffff';
                    $picky_wz_ink = $color_small_titles !== '' ? $color_small_titles : '#222222';
                    $picky_wz_dp_text = $color_datepicker_text_all !== '' ? $color_datepicker_text_all : ( $color_datepicker_text !== '' ? $color_datepicker_text : $picky_wz_ink );
                    $picky_wz_dp_active_bg = $color_datepicker_active !== '' ? $color_datepicker_active : $picky_wz_form_bg;
                    $picky_wz_dp_active_text = $color_datepicker_text_active !== '' ? $color_datepicker_text_active : $picky_wz_dp_text;
                    $picky_wz_input_bg = $color_inputs !== '' ? $color_inputs : $picky_wz_form_bg;
                    $picky_wz_input_text = $color_inputs_text !== '' ? $color_inputs_text : $picky_wz_ink;
                    $picky_booking_css .= '.picky-tables-wrap{--picky-form-bg:' . esc_attr( $picky_wz_form_bg ) . ';--picky-form-ink:' . esc_attr( $picky_wz_ink ) . ';--picky-dp-text:' . esc_attr( $picky_wz_dp_text ) . ';--picky-dp-active-bg:' . esc_attr( $picky_wz_dp_active_bg ) . ';--picky-dp-active-text:' . esc_attr( $picky_wz_dp_active_text ) . ';--picky-input-bg:' . esc_attr( $picky_wz_input_bg ) . ';--picky-input-text:' . esc_attr( $picky_wz_input_text ) . ';}';

                    // Wizard layout skin — colors from Settings (Public map / Date picker / Booking form).
                    $picky_booking_css .= '
                    .picky-tables-wrap .picky-form-booking:has(.picky-wizard),
                    .picky-tables-wrap .picky-form-booking-second:has(.picky-wizard),
                    .picky-tables-wrap .picky-form-booking-third:has(.picky-wizard){
                        background:var(--picky-form-bg,#ffffff)!important;
                        background-color:var(--picky-form-bg,#ffffff)!important;
                    }
                    .picky-wizard,
                    .picky-wizard .picky-wizard-contacts,
                    .picky-wizard .picky-order-wrap{
                        background:var(--picky-form-bg,#ffffff)!important;
                        background-color:var(--picky-form-bg,#ffffff)!important;
                        box-shadow:none!important;
                    }
                    .picky-wizard .picky-input-wrap.picky-calendar-left,
                    .picky-wizard .picky-input-wrap-datepicker,
                    .picky-wizard .picky-tables-datapicker-wrap,
                    .picky-wizard .picky-tables-datapicker-block{
                        background:var(--picky-form-bg,#ffffff)!important;
                        background-color:var(--picky-form-bg,#ffffff)!important;
                    }
                    .picky-wizard .picky-wizard-cta,
                    .picky-wizard .picky-wizard-cta.is-book,
                    .picky-wizard .picky-send-booking.picky-wizard-cta{
                        background:var(--brand,#221c24)!important;
                        background-color:var(--brand,#221c24)!important;
                        background-image:none!important;
                        color:var(--brand-text,#fafafa)!important;
                        text-transform:none!important;
                        letter-spacing:0!important;
                        text-shadow:none!important;
                        box-shadow:none!important;
                        border:0!important;
                        border-radius:12px!important;
                        width:100%!important;
                        max-width:100%!important;
                        min-height:3.15rem!important;
                        padding:0.9rem 1.25rem!important;
                        font-size:1.05rem!important;
                        font-weight:600!important;
                        display:flex!important;
                        align-items:center!important;
                        justify-content:center!important;
                    }
                    .picky-wizard .picky-wizard-cta:hover,
                    .picky-wizard .picky-wizard-cta.is-book:hover{
                        filter:brightness(0.92);
                    }
                    .picky-wizard .picky-send-booking-wrap{display:none!important;}
                    .picky-wizard-datetime{
                        display:grid!important;
                        grid-template-columns:minmax(240px,1.15fr) minmax(200px,1fr)!important;
                        gap:1.25rem!important;
                        width:100%!important;
                    }
                    .picky-tables-wrap-default .picky-wizard .picky-input-wrap.picky-calendar-left,
                    .picky-wizard .picky-input-wrap.picky-calendar-left,
                    .picky-wizard .picky-input-wrap-datepicker{
                        width:100%!important;
                        min-width:0!important;
                        max-width:100%!important;
                        display:block!important;
                        background:var(--picky-form-bg,#ffffff)!important;
                        background-color:var(--picky-form-bg,#ffffff)!important;
                    }
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td a,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td span{
                        background:transparent!important;
                        background-color:transparent!important;
                        color:var(--picky-dp-text,#222222)!important;
                        border-radius:999px!important;
                        box-shadow:none!important;
                        border:0!important;
                    }
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-other-month a,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-other-month span{
                        color:#dddddd!important;
                    }
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td a:hover{
                        background:color-mix(in srgb, var(--picky-dp-text,#222222) 8%, var(--picky-form-bg,#ffffff))!important;
                        background-color:color-mix(in srgb, var(--picky-dp-text,#222222) 8%, var(--picky-form-bg,#ffffff))!important;
                        color:var(--picky-dp-text,#222222)!important;
                    }
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-today a:not(.ui-state-active){
                        background:transparent!important;
                        color:var(--picky-dp-text,#222222)!important;
                        box-shadow:inset 0 0 0 1.5px #dddddd!important;
                        border:0!important;
                    }
                    .picky-tables-wrap .picky-wizard .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-current-day:not(.ui-state-disabled) a,
                    .picky-tables-wrap .picky-wizard .picky-datepicker .ui-datepicker-calendar td a.ui-state-active,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td.ui-datepicker-current-day a,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td a.ui-state-active{
                        background:var(--picky-dp-active-bg,#ffffff)!important;
                        background-color:var(--picky-dp-active-bg,#ffffff)!important;
                        color:var(--picky-dp-active-text,#222222)!important;
                        box-shadow:none!important;
                        border:2px solid var(--picky-dp-active-text,#222222)!important;
                        font-weight:600!important;
                    }
                    .picky-tables-wrap .picky-wizard .picky-datepicker .ui-datepicker-calendar td a.ui-state-active::before,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td a::before,
                    .picky-wizard .picky-datepicker .ui-datepicker-calendar td span::before{
                        display:none!important;
                        opacity:0!important;
                        content:none!important;
                        box-shadow:none!important;
                        border:0!important;
                    }
.picky-wizard .picky-input-wrap.picky-calendar-left .picky-datepicker,
.picky-wizard .picky-tables-datapicker-block .picky-datepicker{
    border:0!important;
    border-radius:0!important;
    box-shadow:none!important;
    padding:0!important;
    background:transparent!important;
    max-width:100%!important;
    width:100%!important;
    margin:0!important;
}
/* Exactly one calendar frame — on .picky-datepicker only */
.picky-wizard .picky-classic-datetime .picky-wizard-datetime-cal > .picky-input-wrap.picky-calendar-left,
.picky-wizard .picky-classic-datetime .picky-wizard-datetime-cal > .picky-input-wrap-datepicker,
.picky-wizard .picky-smart-datetime .picky-wizard-datetime-cal > .picky-input-wrap.picky-calendar-left,
.picky-wizard .picky-smart-datetime .picky-wizard-datetime-cal > .picky-input-wrap-datepicker,
.picky-wizard .picky-classic-datetime .picky-tables-datapicker-wrap,
.picky-wizard .picky-classic-datetime .picky-tables-datapicker-block,
.picky-wizard .picky-smart-datetime .picky-tables-datapicker-wrap,
.picky-wizard .picky-smart-datetime .picky-tables-datapicker-block{
    background:transparent!important;
    background-color:transparent!important;
    border:0!important;
    border-radius:0!important;
    padding:0!important;
    margin:0!important;
    box-shadow:none!important;
    width:100%!important;
    max-width:100%!important;
}
.picky-tables-wrap .picky-wizard .picky-classic-datetime .picky-wizard-datetime-cal .picky-datepicker,
.picky-tables-wrap .picky-wizard .picky-smart-datetime .picky-wizard-datetime-cal .picky-datepicker,
.picky-wizard .picky-classic-datetime .picky-wizard-datetime-cal .picky-datepicker,
.picky-wizard .picky-smart-datetime .picky-wizard-datetime-cal .picky-datepicker{
    border:1px solid #dddddd!important;
    border-radius:16px!important;
    box-shadow:none!important;
    padding:0.85rem 0.75rem!important;
    background:var(--picky-form-bg,#ffffff)!important;
    max-width:100%!important;
    width:100%!important;
    margin:0!important;
    box-sizing:border-box!important;
}
.picky-tables-wrap .picky-wizard .picky-classic-datetime .picky-wizard-datetime-cal .picky-datepicker .ui-datepicker,
.picky-tables-wrap .picky-wizard .picky-smart-datetime .picky-wizard-datetime-cal .picky-datepicker .ui-datepicker{
    border:0!important;
    background:transparent!important;
    padding:0!important;
    box-shadow:none!important;
    width:100%!important;
    max-width:100%!important;
}
.picky-tables-wrap .picky-form-booking .picky-wizard-datetime .picky-datetime-col-title{
    display:block!important;
    visibility:visible!important;
    font-size:1.05rem!important;
    font-weight:600!important;
    margin:0 0 0.65rem!important;
    color:var(--picky-form-ink,#222222)!important;
}
.picky-wizard .picky-classic-datetime .picky-slot-grid,
.picky-wizard .picky-smart-datetime .picky-slot-grid{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:0.4rem!important;
    width:100%!important;
    max-width:100%!important;
    box-sizing:border-box!important;
    overflow-x:hidden!important;
    overflow-y:auto!important;
}
.picky-wizard .picky-classic-datetime .picky-slot-btn,
.picky-wizard .picky-smart-datetime .picky-slot-btn{
    min-width:0!important;
    width:100%!important;
    box-sizing:border-box!important;
    padding:0.5rem 0.2rem!important;
    min-height:2.5rem!important;
}
                    .picky-wizard .picky-wizard-contacts input.picky-input,
                    .picky-wizard input.picky-input{
                        background:var(--picky-input-bg,#ffffff)!important;
                        background-color:var(--picky-input-bg,#ffffff)!important;
                        border:1px solid #b0b0b0!important;
                        border-radius:12px!important;
                        color:var(--picky-input-text,#222222)!important;
                        box-shadow:none!important;
                        text-align:left!important;
                        padding-right:2.6rem!important;
                    }
                    .picky-wizard .picky-wizard-contacts input.picky-input:focus{
                        border-color:var(--picky-input-text,#222222)!important;
                    }
                    .picky-wizard .picky-wizard-contacts input.picky-input.is-valid{
                        border-color:#008a05!important;
                    }
                    .picky-wizard .picky-wizard-contacts input.picky-input.is-invalid,
                    .picky-wizard .picky-wizard-contacts input.picky-input.picky-error-input{
                        border-color:#c13515!important;
                    }
                    .picky-wizard .picky-slot-btn{
                        background:var(--picky-form-bg,#ffffff)!important;
                        border:1px solid #dddddd!important;
                        color:var(--picky-form-ink,#222222)!important;
                        border-radius:12px!important;
                        box-shadow:none!important;
                    }
                    .picky-wizard .picky-slot-btn.is-selected{
                        background:var(--picky-form-bg,#ffffff)!important;
                        border:2px solid var(--picky-form-ink,#222222)!important;
                        color:var(--picky-form-ink,#222222)!important;
                    }
                    .picky-wizard .picky-tile.is-selected,
                    .picky-wizard .picky-svc-tile.is-selected,
                    .picky-wizard .picky-selected-item-row.picky-tile.is-selected{
                        background:var(--picky-form-bg,#ffffff)!important;
                        border-color:#dddddd!important;
                        border-width:1px!important;
                        color:var(--picky-form-ink,#222222)!important;
                        box-shadow:none!important;
                    }
                    .picky-wizard .picky-selected-item-row.picky-tile:hover{
                        border-color:var(--picky-form-ink,#222222)!important;
                        box-shadow:inset 0 0 0 1px var(--picky-form-ink,#222222)!important;
                    }
                    .picky-wizard .picky-input-label,
                    .picky-wizard .picky-wizard-step-title{
                        color:var(--picky-form-ink,#222222)!important;
                    }
                    .picky-wizard-contacts .picky-input-control{
                        position:relative!important;
                        display:block!important;
                        width:100%!important;
                    }
                    .picky-tables-wrap .picky-wizard-contacts .picky-input-control .picky-input-valid-icon:not(.is-ok){
                        display:none!important;
                        visibility:hidden!important;
                        opacity:0!important;
                        font-size:0!important;
                        position:absolute!important;
                    }
                    .picky-wizard-contacts .picky-input-valid-icon.is-ok,
                    .picky-wizard-contacts .picky-input-control:has(input.picky-input.is-valid)>.picky-input-valid-icon{
                        display:flex!important;
                        visibility:visible!important;
                        opacity:1!important;
                        align-items:center!important;
                        justify-content:center!important;
                        position:absolute!important;
                        top:50%!important;
                        right:0.85rem!important;
                        left:auto!important;
                        bottom:auto!important;
                        transform:translateY(-50%)!important;
                        background:#12b76a!important;
                        border-radius:999px!important;
                        width:1.25rem!important;
                        height:1.25rem!important;
                        z-index:6!important;
                        color:transparent!important;
                        font-size:0!important;
                        font-weight:400!important;
                        line-height:0!important;
                        margin:0!important;
                        padding:0!important;
                        overflow:hidden!important;
                        box-shadow:none!important;
                    }
                    /* Geometric ✓ — even on every OS/font (no unicode glyph). */
                    .picky-wizard-contacts .picky-input-valid-icon.is-ok::after,
                    .picky-wizard-contacts .picky-input-control:has(input.picky-input.is-valid)>.picky-input-valid-icon::after{
                        content:""!important;
                        display:block!important;
                        box-sizing:border-box!important;
                        width:0.32rem!important;
                        height:0.58rem!important;
                        margin:-0.08rem 0 0!important;
                        padding:0!important;
                        border:solid #fff!important;
                        border-width:0 2px 2px 0!important;
                        background:transparent!important;
                        color:transparent!important;
                        font-size:0!important;
                        line-height:0!important;
                        position:static!important;
                        transform:rotate(45deg)!important;
                    }
                    .picky-wizard-contacts .picky-input-valid-icon.is-ok::before,
                    .picky-wizard-contacts .picky-input-valid-icon.is-warn{
                        display:none!important;
                        content:none!important;
                    }
                    .picky-wizard-contacts .picky-error,
                    .picky-tables-wrap .picky-form-booking .picky-wizard-contacts .picky-error{
                        position:static!important;
                        transform:none!important;
                        width:100%!important;
                        margin:0.35rem 0 0!important;
                        padding:0!important;
                        background:transparent!important;
                        color:#c13515!important;
                        font-size:0.85rem!important;
                        font-weight:500!important;
                        text-align:left!important;
                        right:auto!important;
                        top:auto!important;
                        border-radius:0!important;
                        box-shadow:none!important;
                    }
                    .picky-wizard-contacts .picky-error::before{
                        display:none!important;
                        content:none!important;
                    }
                    ';

                if ( $picky_booking_css !== '' ) {
                    // Printed in the footer, after picky-frontend.css, so layout colors win over stylesheet defaults.
                    $picky_style_handle = 'picky-booking-dynamic';
                    if ( ! wp_style_is( $picky_style_handle, 'registered' ) ) {
                        wp_register_style( $picky_style_handle, false, array(), $this->version );
                    }
                    wp_enqueue_style( $picky_style_handle );
                    wp_add_inline_style( $picky_style_handle, $picky_booking_css );
                    if ( did_action( 'wp_footer' ) ) {
                        wp_print_styles( $picky_style_handle );
                    } elseif ( ! has_action( 'wp_footer', array( $this, 'picky_print_booking_dynamic_style' ) ) ) {
                        add_action( 'wp_footer', array( $this, 'picky_print_booking_dynamic_style' ), 5 );
                    }
                }

                $container_align = isset($options['container_align']) ? $options['container_align'] : 'center';
                if(!in_array($container_align, array('left','center','right'), true)){
                    $container_align = 'center';
                }
                // Smart layout: cap outer wrapper to design canvas width (matches classic visual width) so the
                // column does not shrink-wrap before the floor plan is shown; alignment uses horizontal margins.
                $picky_tables_wrap_all_attr = '';
                $wrap_all_classes = 'picky-root picky-tables-wrap-all picky-align-'.esc_attr((string)$container_align);
                if(isset($options['shortcode_layout']) && !empty($options['shortcode_layout']) && in_array( (string) $options['shortcode_layout'], array( '2', '4' ), true )){
                    $wrap_all_classes .= ' picky-shortcode-smart';
                    if ( (string) $options['shortcode_layout'] === '4' ) {
                        $wrap_all_classes .= ' picky-shortcode-smart-map';
                    }
                    $cw_smart = isset($options['canvas_width']) ? intval($options['canvas_width']) : 0;
                    if($cw_smart > 0){
                        $sty = 'width:100%;max-width:'.$cw_smart.'px;box-sizing:border-box;';
                        if($container_align === 'center'){
                            $sty .= 'margin-left:auto;margin-right:auto;';
                        } elseif($container_align === 'right'){
                            $sty .= 'margin-left:auto;margin-right:0;';
                        } else {
                            $sty .= 'margin-left:0;margin-right:auto;';
                        }
                        $picky_tables_wrap_all_attr = ' style="'.esc_attr((string)$sty).'"';
                    }
                }
                $output .= '<div class="'.$wrap_all_classes.'" data-picky-context="frontend"'.$picky_tables_wrap_all_attr.'>';


                // Services library & multi-select for this layout
                $picky_layout_library = get_post_meta( $layout_id, '_picky_layout_services_library', true );
                if ( ! is_string( $picky_layout_library ) || $picky_layout_library === '' ) { $picky_layout_library = '[]'; }
                $picky_layout_multi_select = (string) get_post_meta( $layout_id, '_picky_layout_multi_select', true );
                $picky_event_slot          = function_exists( 'picky_layout_event_slot' ) ? picky_layout_event_slot( $layout_id ) : array( 'active' => false, 'mode' => 'calendar', 'date' => '', 'time' => '', 'show_label' => false, 'label' => '' );
                $picky_event_once          = ! empty( $picky_event_slot['active'] );
                $picky_event_once_class    = $picky_event_once ? ' picky-event-once' : '';
                $picky_pay_currency_code = isset( $options['deposit_currency'] ) ? strtoupper( sanitize_text_field( (string) $options['deposit_currency'] ) ) : 'USD';
                $picky_pay_currency_symbol = '$';
                if ( $picky_pay_currency_code === 'EUR' ) { $picky_pay_currency_symbol = '€'; }
                elseif ( $picky_pay_currency_code === 'GBP' ) { $picky_pay_currency_symbol = '£'; }
                elseif ( $picky_pay_currency_code !== '' && $picky_pay_currency_code !== 'USD' ) { $picky_pay_currency_symbol = $picky_pay_currency_code . ' '; }
                $picky_wrap_library_attr = ' data-services-library="' . esc_attr( $picky_layout_library ) . '"'
                    . ' data-multi-select="' . esc_attr( $picky_layout_multi_select ) . '"'
                    . ' data-booking-item-icon="' . esc_attr( Booking_Icons::sanitize( (string) get_post_meta( $layout_id, Booking_Icons::META_KEY, true ) ) ) . '"'
                    . ' data-picky-currency="' . esc_attr( $picky_pay_currency_symbol ) . '"'
                    . ' data-picky-deposit="' . esc_attr( (string) $picky_deposit_amount ) . '"'
                    . ' data-picky-pay-source="' . esc_attr( $picky_payment_amount_source ) . '"'
                    . ' data-picky-pay-required="' . ( $picky_payment_required ? '1' : '0' ) . '"'
                    . ' data-show-booked-avatars="' . ( $picky_show_booked_avatars ? '1' : '0' ) . '"'
                    . ' data-booked-avatars-tone="' . esc_attr( $picky_booked_avatars_tone ) . '"'
                    . ' data-event-mode="' . ( $picky_event_once ? 'once' : 'calendar' ) . '"'
                    . ' data-event-date="' . esc_attr( (string) ( $picky_event_slot['date'] ?? '' ) ) . '"'
                    . ' data-event-time="' . esc_attr( (string) ( $picky_event_slot['time'] ?? '' ) ) . '"'
                    . ' data-event-show-label="' . ( ! empty( $picky_event_slot['show_label'] ) ? '1' : '0' ) . '"'
                    . ' data-picky-logged-in="' . ( is_user_logged_in() ? '1' : '0' ) . '"';
                $picky_contact_id = $this->picky_booking_contact_identity();

                // shortcode output html
                // 2
                // 2 = Smart (date first), 4 = Smart Map (layout first + compact bottom datetime)
                if(isset($options['shortcode_layout']) && !empty($options['shortcode_layout']) && in_array( (string) $options['shortcode_layout'], array( '2', '4' ), true )){
                    $is_smart_map = ( (string) $options['shortcode_layout'] === '4' );
                    
                    // Mobile selection mode (canvas / dropdown) for Smart Layout too
                    $mobile_select = isset($options['mobile_table_select']) ? strval($options['mobile_table_select']) : '0';
                    $mobile_class = '';
                    // For Smart Layout, "hide canvas" still needs a table picker on mobile,
                    // so treat mode 0 like dropdown to avoid a dead-end flow.
                    if($mobile_select === '1'){
                        $mobile_class = ' picky-mobile-canvas';
                    } else if($mobile_select === '2' || $mobile_select === '0'){
                        $mobile_class = ' picky-mobile-dropdown';
                    }

                    $smart_map_class = $is_smart_map ? ' picky-tables-wrap-smart-map picky-smart-table-picker-visible' : '';
                    $picky_wrap_class = 'picky-tables-wrap picky-tables-size-' . $options['canvas_elements_size'] . ' picky-tables-wrap-second' . $smart_map_class . $mobile_class . $picky_canvas_shell_class . ' ' . $picky_shortcode_class . $picky_event_once_class;
                    $output .= '<div class="' . esc_attr( $picky_wrap_class ) . '" id="' . esc_attr( (string) $picky_shortcode_id ) . '"' . $picky_wrap_library_attr . '>';

                        $output .= '<div class="picky-relative">';

                        $nonce = wp_create_nonce('picky_send_book_action');
                        $output .= '<input type="hidden" id="picky_nonce" class="picky-nonce" name="picky_nonce" value="' . esc_attr( $nonce ) . '">';

                        $output .= '<div class="picky-tables-datapicker-wrap' . ( $is_smart_map ? ' picky-smart-map-bar-wrap' : '' ) . '">';
                            $output .= '<div class="picky-tables-datapicker picky-tables-datapicker-block">';
                                // data-date
                                $data_exclude = array();
                                // data-time
                                $time_exclude = array();

                                $days_ahead = $this->daysAhead();
                                $days_off = $this->daysOff();
                                $times_off = $this->timesOff();



                                // create array with all days before last day - $days_ahead. In number and string view
                                $weak_days_ahead = $this->picky_build_wp_local_days_ahead( $days_ahead );
                                
                                
                                // foreach day in allowed period check to exclude times and days from settings of plugin
                                $not_work_time_array = array();
                                $key_start_time = 0;
                                $key_end_time = 0;
                                $time_all_fake = array();
                                $time_all_fake_more = array();
                                $key_not_work_time = 0;
                                foreach ($weak_days_ahead as $key => $value) {
                                    $value_string = $value['string'];
                                    $value_number = $value['number'];
                                    $time_all_fake = $time_all;
                                    $time_all_fake_more = $time_all;
                                    $key_start_time = null;
                                    $key_end_time = null;
                                    $key_start_time_more = null;
                                    $key_end_time_more = null;
                                    if(in_array( $value['string'], $days_off)){ // add days to exclude because of days of the week
                                        $data_exclude[] = $value['number'];
                                    } else { // add times to exclude because of days of the week and work time in this day
                                        if($times_off){
                                            foreach ($times_off as $key => $day) {
                                                if($day['day'] === $value['string']){
                                                    foreach ($time_all as $key => $time_all_value) {
                                                        if(isset($day['time_start']) && !empty($day['time_start'])){
                                                            if($time_all_value === $day['time_start']){
                                                                $key_start_time = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_end']) && !empty($day['time_end'])){
                                                            if($time_all_value === $day['time_end']){
                                                                $key_end_time = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_start_more']) && !empty($day['time_start_more'])){
                                                            if($time_all_value === $day['time_start_more']){
                                                                $key_start_time_more = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_end_more']) && !empty($day['time_end_more'])){
                                                            if($time_all_value === $day['time_end_more']){
                                                                $key_end_time_more = $key;
                                                            }
                                                        } 
                                                    }
                                                }
                                            }

                                            // first times
                                            $length = $key_end_time - $key_start_time;
                                            if($length < 0){
                                                $length = $key_end_time + count($time_all) - $key_start_time;
                                            }
                                            array_splice($time_all_fake, $key_start_time, $length+1);

                                            
                                            // second times
                                            if(isset($key_end_time_more) && !empty($key_end_time_more) 
                                            && isset($key_start_time_more) && !empty($key_start_time_more)
                                            && $key_start_time_more !== null && $key_end_time_more !== null ){
                                                $length_more = $key_end_time_more - $key_start_time_more;
                                                if($length_more < 0){
                                                    $length_more = $key_end_time_more + count($time_all) - $key_start_time_more;
                                                }
                                                $time_all_fake_more = array_slice($time_all_fake_more, $key_start_time_more, $length_more+1);

                                                if(isset($time_all_fake_more) && !empty($time_all_fake_more)){
                                                    $time_all_new = array(); 
                                                    foreach ($time_all_fake_more as $val) { 
                                                      if (($key = array_search($val, $time_all_fake, TRUE))!==false) { 
                                                         $time_all_new[] = $val; 
                                                         unset($time_all_fake[$key]); 
                                                      } 
                                                    } 
                                                }


                                            }

                                            // add times to exclude
                                            foreach ($time_all_fake as $key => $value_fake) {
                                                $time_exclude[] = array(
                                                    'date' => $value_number,
                                                    'time' => $value_fake
                                                );
                                            }

                                        }
                                    }
                                }


                                // convert to json string
                                $data_exclude = urlencode(json_encode($data_exclude));
                                $time_exclude = urlencode(json_encode($time_exclude));

                                // Defensive: get_option(...)['days_ahead'] crashed on PHP 8+
                                // when get_option returned false or an array missing the key
                                // ("Cannot access offset of type bool", or string * int below).
                                $picky_settings = Info::get_settings();
                                $data_maxdate = intval( $picky_settings['days_ahead'] );
                                $time_before  = $this->picky_int_option_hours( $options['table_before_booked_time'] ?? 0 );
                                $time_after   = $this->picky_int_option_hours( $options['table_booked_time'] ?? 0 );
                                $show_time_to = ( isset( $options['show_time_to'] ) && $options['show_time_to'] === '1' );

                                // Classic-style: calendar left, AJAX slot tiles right.
                                // Smart Map (4): compact bottom bar — popup date + native time selects.
                                if ( $is_smart_map ) {
                                    $output .= '<div class="picky-wizard-datetime picky-smart-map-datetime">';
                                    $output .= '<div class="picky-smart-map-bar" role="group" aria-label="' . esc_attr__( 'Date and time', 'pickybooking' ) . '">';

                                    if ( $picky_event_once && ! empty( $picky_event_slot['show_label'] ) && (string) $picky_event_slot['label'] !== '' ) {
                                        $output .= '<div class="picky-event-once-label">' . esc_html( (string) $picky_event_slot['label'] ) . '</div>';
                                    }

                                    $output .= '<div class="picky-smart-map-field picky-smart-map-field-date">';
                                    $output .= '<label class="picky-smart-map-label screen-reader-text" for="picky_smart_map_date_' . esc_attr( (string) $layout_id ) . '">' . esc_html__( 'Date', 'pickybooking' ) . '</label>';
                                    $output .= '<input type="text" readonly autocomplete="off" id="picky_smart_map_date_' . esc_attr( (string) $layout_id ) . '" placeholder="' . esc_attr__( 'Select date', 'pickybooking' ) . '" data-maxdate="' . esc_attr( (string) $data_maxdate ) . '" data-time="' . esc_attr( (string) $time_exclude ) . '" data-date="' . esc_attr( (string) $data_exclude ) . '" data-before-time="' . intval( $time_before ) . '" data-after-time="' . intval( $time_after ) . '" class="picky-datepicker picky-smart-map-date-input" value="' . esc_attr( $picky_event_once ? (string) $picky_event_slot['date'] : '' ) . '" aria-label="' . esc_attr__( 'Select date', 'pickybooking' ) . '" />';
                                    $output .= '<div class="picky-error picky-error-empty">' . esc_html__( 'Please, pick the date', 'pickybooking' ) . '</div>';
                                    $output .= '</div>';

                                    $output .= '<div class="picky-wizard-datetime-slots picky-input-wrap-time picky-smart-map-times">';
                                    $output .= '<div class="picky-smart-map-field picky-smart-map-field-time">';
                                    $output .= '<label class="picky-smart-map-label screen-reader-text" for="picky_booking_time_fake">' . esc_html__( 'Time', 'pickybooking' ) . '</label>';
                                    $output .= '<div class="picky-input-wrap picky-input-inner">';
                                    $output .= '<select class="picky-select picky-smart-map-select" data-placeholder="' . esc_attr__( 'Select time', 'pickybooking' ) . '" id="picky_booking_time_fake" name="picky_booking_time_fake" aria-label="' . esc_attr__( 'Select time', 'pickybooking' ) . '">';
                                    $output .= '<option value="">' . esc_html__( 'Select time', 'pickybooking' ) . '</option>';
                                    foreach ( $time_all as $key => $value ) {
                                        $picky_once_sel = ( $picky_event_once && (string) $value === (string) $picky_event_slot['time'] ) ? ' selected="selected"' : '';
                                        $output .= '<option value="' . esc_attr( (string) $value ) . '"' . $picky_once_sel . '>' . esc_html( (string) $value ) . '</option>';
                                    }
                                    $output .= '</select>';
                                    $output .= '</div>';
                                    $output .= '<div class="picky-error picky-error-empty">' . esc_html__( 'Please, chose time', 'pickybooking' ) . '</div>';
                                    $output .= '</div>';
                                    if ( $show_time_to ) {
                                        $output .= '<div class="picky-smart-map-field picky-smart-map-field-time-to picky-input-wrap-time-to">';
                                        $output .= '<label class="picky-smart-map-label screen-reader-text" for="picky_booking_time_to">' . esc_html__( 'End', 'pickybooking' ) . '</label>';
                                        $output .= '<div class="picky-input-wrap picky-input-inner">';
                                        $output .= '<select class="picky-select picky-smart-map-select" data-placeholder="' . esc_attr__( 'End time', 'pickybooking' ) . '" id="picky_booking_time_to" name="picky_booking_time_to" aria-label="' . esc_attr__( 'End time', 'pickybooking' ) . '">';
                                        foreach ( $time_all as $key => $value ) {
                                            $output .= '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( (string) $value ) . '</option>';
                                        }
                                        $output .= '</select>';
                                        $output .= '</div>';
                                        $output .= '</div>';
                                    }
                                    $output .= '</div>'; // .picky-smart-map-times

                                    // Actions live in the same card as date/time (merged dock).
                                    $output .= '<div class="picky-smart-map-actions picky-selection-tray" aria-live="polite">';
                                    $output .= '<div class="picky-selection-tray-inner">';
                                    $output .= '<div class="picky-selection-tray-summary">';
                                    $output .= '<span class="picky-selection-tray-count" data-empty-label="' . esc_attr__( 'Select on the map', 'pickybooking' ) . '">' . esc_html__( 'Select on the map', 'pickybooking' ) . '</span>';
                                    $output .= '<span class="picky-selection-tray-total" style="display:none;"></span>';
                                    $output .= '</div>';
                                    $output .= '<button type="button" class="picky-selection-tray-book-btn" disabled aria-disabled="true">';
                                    $output .= esc_html( $picky_payment_required ? __( 'Pay & Book', 'pickybooking' ) : __( 'Book Now', 'pickybooking' ) );
                                    $output .= '</button>';
                                    $output .= '</div>';
                                    $output .= '</div>';

                                    $output .= '</div>'; // .picky-smart-map-bar
                                    $output .= '<div class="picky-smart-availability-note">' . esc_html__( 'Available for this timeslot tables are highlighted on canvas, please select your table.', 'pickybooking' ) . '</div>';
                                    $output .= '</div>'; // .picky-wizard-datetime
                                } else {
                                $output .= '<div class="picky-wizard-datetime picky-smart-datetime">';
                                if ( $picky_event_once && ! empty( $picky_event_slot['show_label'] ) && (string) $picky_event_slot['label'] !== '' ) {
                                    $output .= '<div class="picky-event-once-label">' . esc_html( (string) $picky_event_slot['label'] ) . '</div>';
                                }
                                $output .= '<div class="picky-wizard-datetime-cal">';
                                $output .= '<label class="picky-input-label-big picky-datetime-col-title">'.esc_html__('Choose the Date', 'pickybooking').'</label>';
                                $output .= '<div class="picky-input-wrap picky-input-wrap-datepicker picky-calendar-left">';
                                    $output .= '<div data-maxdate="'.esc_attr((string)$data_maxdate).'" data-time="'.esc_attr((string)$time_exclude).'" data-date="'.esc_attr((string)$data_exclude).'" data-before-time="'.intval( $time_before ).'" data-after-time="'.intval( $time_after ).'" class="picky-datepicker"></div>';
                                    $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, pick the date', 'pickybooking').'</div>';
                                $output .= '</div>';
                                $output .= '</div>'; // .picky-wizard-datetime-cal

                                $output .= '<div class="picky-wizard-datetime-slots picky-input-wrap-time" style="display:none;">';
                                $output .= $this->picky_slot_grids_html( $show_time_to, 'picky-input-label picky-datetime-col-title' );
                                // Hidden selects kept for JS sync + canvas availability (same pattern as Classic).
                                $output .= '<div class="picky-input-wrap picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<select class="picky-select" data-placeholder="'.esc_attr__( 'Select your time', 'pickybooking' ).'" id="picky_booking_time_fake" name="picky_booking_time_fake">';
                                            $output .= '<option value=""></option>';
                                            foreach ( $time_all as $key => $value ) {
                                                $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                            }
                                        $output .= '</select>';
                                    $output .= '</div>';
                                $output .= '</div>';
                                if ( $show_time_to ) {
                                    $output .= '<div class="picky-input-wrap picky-input-wrap-time-to picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<select class="picky-select" data-placeholder="'.esc_attr__('Choose time', 'pickybooking').'" id="picky_booking_time_to" name="picky_booking_time_to">';
                                                foreach ( $time_all as $key => $value ) {
                                                    $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                                }
                                            $output .= '</select>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                }
                                $output .= '</div>'; // .picky-wizard-datetime-slots
                                $output .= '<div class="picky-smart-availability-note">'.esc_html__('Available for this timeslot tables are highlighted on canvas, please select your table.', 'pickybooking').'</div>';
                                $output .= '</div>'; // .picky-wizard-datetime
                                }

                                

                            $output .= '</div>';
                        $output .= '</div>';

                            $output .= '<div class="picky-draggable-wrap' . esc_attr( $picky_canvas_wrap_class ) . '" data-picky-render="builder-v2">';
                            $draggable_style = "";
                            $draggable_style .= ' style="';
                            // Use px (same as admin builder). Em sizes break when theme/plugin
                            // font-size rules change — item left/top stay in px and drift off the bg.
                            if($options['canvas_width']){
                                $draggable_style .= 'width: '.intval($options['canvas_width']).'px;';
                            }
                            if($options['canvas_height']){
                                $draggable_style .= 'height: '.intval($options['canvas_height']).'px;';
                            }
                            if ( ! $picky_canvas_transparent_bg && $layout_bg_canvas_color !== '' ) {
                                $draggable_style .= 'background-color:' . esc_attr( $layout_bg_canvas_color ) . ';';
                            }
                            if($layout_bg_canvas !== ''){  
                                $draggable_style .= 'background-image: url('.esc_url((string)$layout_bg_canvas) .');';
                                $layout_bg_mode = get_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', true );
                                $bg_mode = is_string( $layout_bg_mode ) && $layout_bg_mode !== ''
                                    ? $layout_bg_mode
                                    : ( isset( $options['background-canvas-mode'] ) && $options['background-canvas-mode'] !== ''
                                        ? $options['background-canvas-mode']
                                        : Info::$DEFAULT_OPTIONS['background-canvas-mode'] );
                                if($bg_mode === 'cover'){
                                    $draggable_style .= 'background-size:cover;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'contain'){
                                    $draggable_style .= 'background-size:contain;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'stretch'){
                                    $draggable_style .= 'background-size:100% 100%;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'repeat-x'){
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat-x;background-position:top left;';
                                } else if($bg_mode === 'repeat-y'){
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat-y;background-position:top left;';
                                } else if($bg_mode === 'center'){
                                    $draggable_style .= 'background-size:auto;background-repeat:no-repeat;background-position:center center;';
                                } else {
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat;background-position:top left;';
                                }
                            } 
                            $draggable_style .= '"';
                            $ch_attr = isset($options['canvas_height']) ? intval($options['canvas_height']) : 0;
                            $output .= '<div data-width="'.intval($options['canvas_width']).'" data-height="'.esc_attr((string)$ch_attr).'" class="picky-draggable" '.$draggable_style.'>';
                            $output .= '';
                                $i_array = 0;
                                if(isset($jsonPosition) && !empty($jsonPosition)){
                                    // foreach element
                                    foreach ($jsonPosition as $key => $val) {            
                                        if(is_array($val)) { 
                                            $w = $val['x1']-$val['x'];
                                            $h = $val['y1']-$val['y'];
                                            $class = "";
                                            if($val['class'] !== ""){
                                                $class = $val['class'];
                                            }
                                            // style
                                            $el_left = $val['x'];
                                            $el_top = $val['y'];
                                            $element_style = "left: ".$val['x']."px; top: ".$val['y']."px;";
                                            $z_stack = isset( $val['z_index'] ) ? intval( $val['z_index'] ) : ( 10 + (int) $i_array );
                                            if ( $z_stack < 1 ) {
                                                $z_stack = 10 + (int) $i_array;
                                            }
                                            $element_style .= ' z-index: ' . $z_stack . ';';
                                            $element_style_table = '';
                                            // Do not apply legacy background-table per item in builder-v2.
                                            // Item visuals are controlled by .picky-element-shape (color/custom image).
                                            // Builder item size (saved as x1/y1). Apply to all items so resized items render identically on frontend.
                                            $w_i = (isset($w) && intval($w) > 0) ? intval($w) : 100;
                                            $h_i = (isset($h) && intval($h) > 0) ? intval($h) : 100;
                                            $element_style_table .= ' width: '.$w_i.'px; height: '.$h_i.'px;';
                                            

                                            // data-date / data-time from this item's bookings only (indexed; not O(n²) over all rows).
                                            $picky_item_bookings = isset( $booked_by_item_id[ (string) $val['id'] ] ) ? $booked_by_item_id[ (string) $val['id'] ] : array();
                                            list( $data_exclude, $time_exclude ) = $this->picky_element_booking_excludes( $picky_item_bookings, $time_all, $options );
                                            $data_exclude = urlencode(json_encode($data_exclude));
                                            $time_exclude = urlencode(json_encode($time_exclude));


                                            $rotate_z = isset($val['rotate_z']) ? intval($val['rotate_z']) : 0;
                                            $rx = isset($val['rotate_x']) ? intval($val['rotate_x']) : 0;
                                            $ry = isset($val['rotate_y']) ? intval($val['rotate_y']) : 0;
                                            // Backward compat (old preset field)
                                            if((!isset($val['rotate_x']) && !isset($val['rotate_y'])) && isset($val['rotate_3d'])){
                                                $rotate_3d = intval($val['rotate_3d']);
                                                if($rotate_3d === 1){ $rx = 25; $ry = 0; }
                                                if($rotate_3d === 2){ $rx = -25; $ry = 0; }
                                                if($rotate_3d === 3){ $rx = 0; $ry = 25; }
                                                if($rotate_3d === 4){ $rx = 0; $ry = -25; }
                                            }
                                            $custom_url = isset($val['custom_url']) ? esc_url((string)$val['custom_url']) : '';
                                            // Match frontend JS model: Z on table (or table-wrap for text), 3D tilt on shape only.
                                            // Putting rotateZ on the shape caused a load-time spin (CSS transition:all).
                                            $is_text_el = ( isset( $val['class'] ) && (string) $val['class'] === 'type-text' );
                                            $shape_style = 'transform: perspective(600px) rotateX('.$rx.'deg) rotateY('.$ry.'deg);';
                                            $table_style = '';
                                            $table_wrap_style_extra = '';
                                            $overlay_style_extra = '';
                                            if ( $is_text_el ) {
                                                $table_wrap_style_extra = 'transform:rotateZ(' . intval( $rotate_z ) . 'deg);transform-origin:center center;';
                                                // Match builder: do not counter-rotate the overlay for text
                                                // (avoids a full-size ghost plate; overlay chrome is unused on public).
                                                $overlay_style_extra = '';
                                            } else {
                                                $table_style = 'transform:rotateZ(' . intval( $rotate_z ) . 'deg);transform-origin:center center;';
                                            }
                                            $opacity = isset($val['opacity']) ? floatval($val['opacity']) : 1;
                                            if($opacity < 0){ $opacity = 0; }
                                            if($opacity > 1){ $opacity = 1; }
                                            $shape_style .= 'opacity:'.$opacity.';';
                                            // Background image priority: custom_url -> background-table (shapes skip table-bg fallback)
                                            if($val['class'] !== 'type-text' && $val['class'] !== 'type-shape' && $val['class'] !== 'type-icon' && $val['class'] !== 'type-wall'){
                                                if($custom_url){
                                                    $shape_style .= 'background-image:url('.$custom_url.');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                                } else if($layout_bg_table !== ''){
                                                    $shape_style .= 'background-image:url('.esc_url((string)$layout_bg_table).');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                                }
                                            } else if($val['class'] === 'type-shape' && $custom_url){
                                                $shape_style .= 'background-image:url('.$custom_url.');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                            }
                                            if(isset($val['color']) && $val['color'] !== ''){
                                                if($val['class'] === 'type-text'){
                                                    $text_bg_style = 'background-color:'.esc_attr((string)$val['color']).';';
                                                } elseif ( $val['class'] === 'type-icon' ) {
                                                    $shape_style .= 'color:'.esc_attr((string)$val['color']).';background:transparent;';
                                                } elseif ( ! $custom_url ) {
                                                    // Skip solid fill when a custom image is set (plate would show through PNGs),
                                                    // or when this layout hides item background fill on the public site.
                                                    $picky_is_bookable_plate = ! in_array( (string) $val['class'], array( 'type-text', 'type-shape', 'type-icon', 'type-wall' ), true );
                                                    if ( ! $picky_hide_item_fill || ! $picky_is_bookable_plate ) {
                                                        $shape_style .= 'background-color:'.esc_attr((string)$val['color']).';';
                                                    }
                                                }
                                            }
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-shape' ) {
                                                $picky_shape_type = isset( $val['shape_type'] ) ? (string) $val['shape_type'] : 'rectangle';
                                                if ( $picky_shape_type === 'circle' ) {
                                                    $shape_style .= 'border-radius:50%;';
                                                } elseif ( $picky_shape_type === 'triangle' ) {
                                                    $shape_style .= 'border-radius:0;';
                                                } elseif ( isset( $val['shape_radius'] ) && (string) $val['shape_radius'] !== '' ) {
                                                    $picky_sr = max( 0, min( 120, intval( $val['shape_radius'] ) ) );
                                                    $shape_style .= 'border-radius:' . $picky_sr . 'px;';
                                                } elseif ( $picky_shape_type === 'rounded' ) {
                                                    $shape_style .= 'border-radius:15px;';
                                                } elseif ( $picky_shape_type === 'rectangle' ) {
                                                    $shape_style .= 'border-radius:0;';
                                                }
                                                $picky_sbw = isset( $val['shape_border_width'] ) ? max( 0, min( 32, intval( $val['shape_border_width'] ) ) ) : 0;
                                                if ( $picky_sbw > 0 ) {
                                                    $picky_sbc = ( isset( $val['shape_border_color'] ) && (string) $val['shape_border_color'] !== '' ) ? (string) $val['shape_border_color'] : '#333333';
                                                    $shape_style .= 'border:' . $picky_sbw . 'px solid ' . esc_attr( $picky_sbc ) . ';box-sizing:border-box;';
                                                }
                                            } elseif ( isset( $val['class'] ) && $val['class'] === 'type-wall' ) {
                                                // Empty = CSS default (2px). Explicit 0 = sharp. 1–120 = override.
                                                if ( isset( $val['wall_radius'] ) && (string) $val['wall_radius'] !== '' ) {
                                                    $picky_wr = max( 0, min( 120, intval( $val['wall_radius'] ) ) );
                                                    $shape_style .= '--picky-wall-radius:' . $picky_wr . 'px;border-radius:' . $picky_wr . 'px;';
                                                }
                                            } elseif ( isset( $val['class'] ) && ! in_array( (string) $val['class'], array( 'type-wall', 'type-text', 'type-shape', 'type-icon', 'type-4', 'type-circle' ), true ) ) {
                                                // Empty or 0 = CSS default (8px). Only positive overrides write inline radius.
                                                // !important beats theme nuclear border-radius:0 / forced 8px before JS runs.
                                                if ( isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' ) {
                                                    $picky_sq_r_first = max( 0, min( 120, intval( $val['square_radius'] ) ) );
                                                    if ( $picky_sq_r_first > 0 ) {
                                                        $shape_style .= 'border-radius:' . $picky_sq_r_first . 'px!important;';
                                                    }
                                                }
                                            }
                                            $picky_label_position = isset( $val['label_position'] ) ? (string) $val['label_position'] : 'bottom';
                                            if ( ! in_array( $picky_label_position, array( 'top', 'bottom' ), true ) ) {
                                                $picky_label_position = 'bottom';
                                            }
                                            $picky_pub_label_fs = isset( $val['label_font_size'] ) ? max( 8, min( 200, intval( $val['label_font_size'] ) ) ) : 12;
                                            $picky_pub_label_c  = isset( $val['label_color'] ) ? picky_sanitize_hex_color( (string) $val['label_color'] ) : '';
                                            if ( ! $picky_pub_label_c ) {
                                                $picky_pub_label_c = '#333333';
                                            }
                                            $picky_pub_label_w    = isset( $val['label_font_weight'] ) ? (string) $val['label_font_weight'] : 'normal';
                                            $picky_pub_label_w_ok = array( 'normal', 'bold', '300', '400', '500', '600', '700', '800', '900' );
                                            if ( ! in_array( $picky_pub_label_w, $picky_pub_label_w_ok, true ) ) {
                                                $picky_pub_label_w = 'normal';
                                            }
                                            $picky_pub_label_style_attr = 'font-size:' . intval( $picky_pub_label_fs ) . 'px;color:' . esc_attr( $picky_pub_label_c ) . ';font-weight:' . esc_attr( $picky_pub_label_w ) . ';';
                                            $picky_show_id = '1';
                                            if ( isset( $val['show_id'] ) && (string) $val['show_id'] === '0' ) {
                                                $picky_show_id = '0';
                                            }
                                            if ( ! $picky_show_ids_on_map ) {
                                                $picky_show_id = '0';
                                            }
                                            $picky_id_badge_style = ( $picky_show_id === '0' ) ? 'display:none !important;' : '';
                                            $picky_pub_shape_attrs = '';
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-shape' ) {
                                                $picky_pub_shape_radius = ( isset( $val['shape_radius'] ) && (string) $val['shape_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['shape_radius'] ) ) ) : '';
                                                $picky_pub_shape_bw     = isset( $val['shape_border_width'] ) ? max( 0, min( 32, intval( $val['shape_border_width'] ) ) ) : 0;
                                                $picky_pub_shape_bc     = ( isset( $val['shape_border_color'] ) && (string) $val['shape_border_color'] !== '' ) ? (string) $val['shape_border_color'] : '#333333';
                                                $picky_pub_shape_attrs  = ' data-shape-radius="' . esc_attr( $picky_pub_shape_radius ) . '" data-shape-border-width="' . esc_attr( (string) $picky_pub_shape_bw ) . '" data-shape-border-color="' . esc_attr( $picky_pub_shape_bc ) . '"';
                                            }
                                            $picky_pub_square_attrs = '';
                                            if ( isset( $val['class'] ) && ! in_array( (string) $val['class'], array( 'type-wall', 'type-text', 'type-shape', 'type-icon', 'type-4', 'type-circle' ), true ) ) {
                                                $picky_pub_sq_r = ( isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['square_radius'] ) ) ) : '';
                                                // Omit 0 — same as unset (CSS default). Avoids data-square-radius="0" re-applying sharp corners in JS.
                                                if ( $picky_pub_sq_r !== '' && (int) $picky_pub_sq_r > 0 ) {
                                                    $picky_pub_square_attrs = ' data-square-radius="' . esc_attr( $picky_pub_sq_r ) . '"';
                                                }
                                            }
                                            $picky_pub_wall_attrs = '';
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-wall' && isset( $val['wall_radius'] ) && (string) $val['wall_radius'] !== '' ) {
                                                $picky_pub_wr = (string) max( 0, min( 120, intval( $val['wall_radius'] ) ) );
                                                $picky_pub_wall_attrs = ' data-wall-radius="' . esc_attr( $picky_pub_wr ) . '"';
                                            }

                                            $output .= '<div 
                                                class="picky-element '.esc_attr((string)$class).' rotate-'.esc_attr((string)$val['rotate']).'" 
                                                data-date="'.esc_attr((string)$data_exclude).'" 
                                                data-time="'.esc_attr((string)$time_exclude).'" 
                                                data-left="'.esc_attr((string)$el_left).'" 
                                                data-top="'.esc_attr((string)$el_top).'" 
                                                data-id="'.esc_attr(isset($val['id']) ? (string)$val['id'] : '').'" 
                                                data-show-id="'.esc_attr( $picky_show_id ).'" 
                                                data-unique="'.esc_attr(isset($val['unique']) ? (string)$val['unique'] : '').'" 
                                                data-seats="'.esc_attr(isset($val['seats']) ? (string)$val['seats'] : '').'" 
                                                data-max-seats="'.esc_attr(isset($val['max_seats']) ? (string)$val['max_seats'] : '').'" 
                                                data-big-side="'.esc_attr(isset($val['big_side']) ? (string)$val['big_side'] : '').'" 
                                                data-type="'.esc_attr(isset($val['class']) ? (string)$val['class'] : '').'" 
                                                data-rotate="'.esc_attr(isset($val['rotate']) ? (string)$val['rotate'] : '').'" 
                                                data-rotate-z="'.esc_attr((string)$rotate_z).'" 
                                                data-rotate-x="'.esc_attr((string)$rx).'" 
                                                data-rotate-y="'.esc_attr((string)$ry).'" 
                                                data-z-index="'.esc_attr( (string) $z_stack ).'" 
                                                data-opacity="'.esc_attr((string)$opacity).'" 
                                                data-custom-url="'.esc_attr((string)$custom_url).'" 
                                                data-text="'.(isset($val['text']) ? esc_attr((string)$val['text']) : '').'" 
                                                data-text-color="'.esc_attr( picky_sanitize_hex_color( isset( $val['text_color'] ) ? (string) $val['text_color'] : '#333333' ) ?: '#333333' ).'" 
                                                data-text-color-mode="'.( ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom' ).'" 
                                                data-font-size="'.(isset($val['font_size']) ? esc_attr((string)$val['font_size']) : '16').'" 
                                                data-line-height="'.( ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' ) ? esc_attr( (string) max( 8, min( 400, intval( $val['line_height'] ) ) ) ) : '' ).'"
                                                data-font-family="'.(isset($val['font_family']) ? esc_attr((string)$val['font_family']) : 'inherit').'" 
                                                data-font-weight="'.(isset($val['font_weight']) ? esc_attr((string)$val['font_weight']) : 'normal').'" data-padding="'.(isset($val['padding']) ? esc_attr((string)$val['padding']) : '10').'" data-color="'.(isset($val['color']) ? esc_attr((string)$val['color']) : '#ffffff').'" 
                                                data-shape-type="'.(isset($val['shape_type']) ? esc_attr((string)$val['shape_type']) : 'rectangle').'"'.$picky_pub_shape_attrs.$picky_pub_square_attrs.$picky_pub_wall_attrs.' 
                                                data-icon="'.esc_attr( isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '' ).'"
                                                data-label-position="'.esc_attr( $picky_label_position ).'"
                                                data-service-ids="'.esc_attr( ( isset( $val['service_ids'] ) && is_array( $val['service_ids'] ) ) ? (string) wp_json_encode( $val['service_ids'] ) : '[]' ).'"
                                                data-custom-id="'.esc_attr( isset( $val['custom_id'] ) ? (string) $val['custom_id'] : '' ).'"
                                                style="'. esc_attr((string)$element_style).'">
                                                    <div class="picky-element-table-wrap" style="'.esc_attr((string)$element_style_table . $table_wrap_style_extra).'">
                                                        <div class="picky-element-table"'.( $table_style !== '' ? ' style="'.esc_attr( (string) $table_style ).'"' : '' ).'>
                                                            ';
                                                            $inner_shape = '';
                                                            if(isset($val['class']) && $val['class'] === 'type-text'){
                                                                $text = isset($val['text']) ? (string)$val['text'] : '';
                                                                $fs = isset($val['font_size']) ? intval($val['font_size']) : 16;
                                                                if ( $fs < 8 ) { $fs = 8; }
                                                                $lh = ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' )
                                                                    ? max( 8, min( 400, intval( $val['line_height'] ) ) )
                                                                    : $fs;
                                                                $ff = isset($val['font_family']) ? (string)$val['font_family'] : 'inherit';
                                                                // Theme body fonts differ from wp-admin — lock "inherit" to the admin stack
                                                                // so shrink-wrapped labels land on the same pixels after rotate.
                                                                if ( $ff === '' || $ff === 'inherit' ) {
                                                                    $ff = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
                                                                }
                                                                $fw = isset($val['font_weight']) ? (string)$val['font_weight'] : 'normal';
                                                                $t_color = picky_sanitize_hex_color( isset( $val['text_color'] ) ? (string) $val['text_color'] : '#333333' ) ?: '#333333';
                                                                $t_color_mode = ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom';
                                                                $resolved_text_color = ( $t_color_mode === 'theme' ) ? 'inherit' : esc_attr((string)$t_color);
                                                                $t_pad = isset( $val['padding'] ) ? max( 0, min( 120, intval( $val['padding'] ) ) ) : 10;
                                                                /* Same as builder: padding on .picky-element-text-content; shape shrink-wraps to glyphs. */
                                                                $inner_shape = '<div class="picky-element-text-content" style="font-size:'.$fs.'px;--picky-text-line-height:'.$lh.'px;line-height:'.$lh.'px;font-family:'.esc_attr((string)$ff).'; font-weight:'.esc_attr((string)$fw).'; color:'.$resolved_text_color.';padding:'.$t_pad.'px;">'.esc_html((string)$text).'</div>';
                                                            } elseif ( isset( $val['class'] ) && $val['class'] === 'type-icon' ) {
                                                                $picky_pub_icon = isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '';
                                                                if ( $picky_pub_icon === '' ) {
                                                                    $picky_pub_icon = 'star';
                                                                }
                                                                $inner_shape = '<span class="picky-element-icon-wrap">' . wp_kses( \Picky\Booking_Icons::svg_markup( $picky_pub_icon, 'picky-bicon picky-element-icon' ), \Picky\Booking_Icons::kses_allowed() ) . '</span>';
                                                            }
                                                            $output .= '<div class="picky-element-shape" style="'.esc_attr((string)$shape_style).'">'.$inner_shape.'</div>
                                                        </div>
                                                        <div class="picky-element-overlay"'.( $overlay_style_extra !== '' ? ' style="'.esc_attr( (string) $overlay_style_extra ).'"' : '' ).'>
                                                            <div class="picky-element-id" style="'.esc_attr( $picky_id_badge_style ).'">'. esc_html( ( isset( $val['custom_id'] ) && (string) $val['custom_id'] !== '' ) ? (string) $val['custom_id'] : (string) $val['id'] ).'</div>
                                                            <div class="picky-element-avatar" hidden aria-hidden="true"></div>';
                                                        // Capacity badge: always render for bookable items (shown on hover via CSS).
                                                        // Decorative chair dots remain controlled by show_seats elsewhere.
                                                        $picky_el_class = isset( $val['class'] ) ? (string) $val['class'] : '';
                                                        $picky_is_bookable = ! in_array( $picky_el_class, array( 'type-wall', 'type-text', 'type-shape', 'type-icon' ), true );
                                                        if ( $picky_is_bookable ) {
                                                            $picky_cap = '';
                                                            if ( isset( $val['max_seats'] ) && (string) $val['max_seats'] !== '' ) {
                                                                $picky_cap = (string) $val['max_seats'];
                                                            } elseif ( isset( $val['seats'] ) && (string) $val['seats'] !== '' ) {
                                                                $picky_cap = (string) $val['seats'];
                                                            }
                                                            if ( $picky_cap === '' ) {
                                                                $picky_cap = '1';
                                                            }
                                                            $output .= '<div class="picky-element-seats">' . esc_html( $picky_cap ) . '</div>';
                                                        }
                                                        // Label
                                                        if ( $picky_show_labels_on_map && isset($val['label']) && $val['label'] !== '' ) {
                                                            $output .= '<div class="picky-element-label" style="'.esc_attr( $picky_pub_label_style_attr ).'">'.esc_html((string)$val['label']).'</div>';
                                                        }

                                                        $output .= '</div>
                                                    </div>
                                                </div>';

                                            $i_array++;

                                        }
                                    }
                                }
                            $output .= '</div>';

                        /* Smart: booking + thank-you sit in an overlay layer above the floor plan (same width box as the canvas wrapper). */
                        $output .= '<div class="picky-smart-booking-overlay" aria-hidden="true">';

                        $output .= '<div class="picky-form-back"></div>';

                        $output .= '<div class="picky-form-thx">';
                            $output .= '<div class="picky-close"></div>';
                            $output .= '<div class="picky-form-thx-title">';
                                if(isset($options['form_thx']) && $options['form_thx'] !== ''){
                                    $output .= wp_kses_post( $options['form_thx'] );
                                } else {
                                    $output .= esc_html__( 'Thank You!', 'pickybooking' ) . '<br />' . esc_html__( 'We will call you back soon.', 'pickybooking' );
                                }
                            $output .= '</div>';
                        $output .= '</div>';

                        $output .= '<form class="picky-form-booking picky-form-booking-second" data-picky-wizard-smart="1">';
                            $output .= wp_nonce_field('send_book','_wpnonce',true,false);
                            $output .= '<input type="hidden" name="picky_layout_id" value="' . esc_attr( (string)$layout_id ) . '">';
                            // Legacy single-item hidden fields kept for backward compat (auto-populated by JS for non-multi-select layouts)
                            $output .= '<input type="hidden" name="picky_booking_id" id="picky_booking_id" value="">';
                            $output .= '<input type="hidden" name="picky_booking_unique" id="picky_booking_unique" value="">';
                            $output .= '<input type="hidden" name="picky_booking_seats" id="picky_booking_seats" value="">';
                            // Multi-item container — JS injects hidden inputs: picky_items[0][unique], picky_items[0][service_id], etc.
                            $output .= '<div class="picky-items-hidden-container" id="picky_items_container"></div>';
                            $output .= '<div class="picky-close"></div>';

                            $output .= '<div class="picky-wizard" data-picky-wizard="1" data-picky-start-step="2">';
                            $output .= '<div class="picky-wizard-steps-viewport"><div class="picky-wizard-steps-track">';

                            // Step 1: date/time already chosen outside — summary + edit hint
                            $output .= '<div class="picky-wizard-step" data-step="1" aria-hidden="true">';
                            $output .= $this->picky_wizard_step_title_html( __( 'Date & time', 'pickybooking' ) );
                            $output .= '<div class="picky-wizard-smart-datetime-summary">';
                            $output .= '<p class="picky-wizard-smart-datetime-lead">'.esc_html__( 'Date and time were selected on the booking map. Change them there, or continue to services.', 'pickybooking' ).'</p>';
                            $output .= '<div class="picky-booking-datetime-chip picky-chip-visible" role="status">';
                                $output .= '<span class="picky-chip-date"></span>';
                                $output .= '<span class="picky-chip-sep">·</span>';
                                $output .= '<span class="picky-chip-time"></span>';
                            $output .= '</div>';
                            $output .= '</div>';
                            $output .= '<input type="hidden" class="picky-input"  id="picky_booking_date" name="picky_booking_date" value="' . esc_attr( $picky_event_once ? (string) $picky_event_slot['date'] : '' ) . '">';
                            $output .= '<input type="hidden" class="picky-input"  id="picky_booking_time" name="picky_booking_time" value="' . esc_attr( $picky_event_once ? (string) $picky_event_slot['time'] : '' ) . '">';
                            $output .= '</div>'; // step 1

                            // Step 2: booked items + optional services
                            $output .= '<div class="picky-wizard-step is-active" data-step="2">';
                            // Single dynamic title (e.g. "Book Table #3") — filled by pickyUpdateBookingFormTitle.
                            $output .= '<div class="picky-wizard-step-title picky-form-booking-title"'
                                /* translators: %s: value. */
                                .' data-text="'.esc_attr( sprintf( __( 'Book %s', 'pickybooking' ), $this->picky_item_label() ) ).' #"'
                                /* translators: %s: value. */
                                .' data-text-multi="'.esc_attr( sprintf( __( 'Book %s', 'pickybooking' ), $this->picky_items_label() ) ).' #"'
                                .'></div>';
                            if($mobile_select === '2' || $mobile_select === '0'){
                                $output .= '<div class="picky-input-wrap picky-input-wrap-mobile-table">';
                                    $output .= '<label class="picky-input-label-big">'.esc_html__( 'Select the table', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        /* translators: %s: value. */
                                        $output .= '<select class="picky-select picky-mobile-table-select" data-placeholder="'.esc_attr( sprintf( __( 'Choose %s', 'pickybooking' ), $this->picky_item_label() ) ).'" id="picky_booking_table_mobile" name="picky_booking_table_mobile">';
                                            $output .= '<option value=""></option>';
                                        $output .= '</select>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }
                            $output .= '<div class="picky-input-wrap picky-input-wrap-table-summary picky-items-summary-wrap" style="display:none;" aria-live="polite">';
                                /* translators: %s: value. */
                                $output .= '<label class="picky-input-label-big picky-wizard-hide-label">'.esc_html( sprintf( __( 'Your %s', 'pickybooking' ), $this->picky_items_label() ) ).'</label>';
                                $output .= '<div class="picky-selected-items-list picky-tile-grid" id="picky_selected_items_list"></div>';
                                $output .= '<div class="picky-items-selection-notice" style="display:none;"></div>';
                            $output .= '</div>';

                            $output .= '<div class="picky-input-wrap picky-services-panel-wrap" style="display:none;">';
                                $output .= '<div class="picky-services-add-lead">';
                                    $output .= '<span class="picky-services-add-text">'.esc_html__( 'Add services you need', 'pickybooking' ).'</span>';
                                $output .= '</div>';
                                $output .= '<div class="picky-services-panel" id="picky_services_panel"></div>';
                            $output .= '</div>';

                            // Compact booking meta — below services (not between chips and services).
                            $output .= '<div class="picky-booking-meta-compact" style="display:none;" aria-live="polite">';
                                $output .= '<div class="picky-table-summary-line"><span class="picky-table-summary-label">'.esc_html( $this->picky_item_label() ).'</span> <strong class="picky-table-summary-id"></strong></div>';
                                $output .= '<div class="picky-table-summary-line"><span class="picky-table-summary-label">'.esc_html__( 'Your date and time', 'pickybooking' ).'</span> <strong class="picky-table-summary-datetime"></strong></div>';
                                $output .= '<div class="picky-table-summary-line"><span class="picky-table-summary-label">'.esc_html__( 'Max. seats', 'pickybooking' ).'</span> <strong class="picky-table-summary-max"></strong></div>';
                            $output .= '</div>';

                            $output .= '<div class="picky-order-total-wrap" style="display:none;">';
                                $output .= '<span class="picky-order-total-label">'.esc_html__( 'Total', 'pickybooking' ).'</span> ';
                                $output .= '<strong class="picky-order-total"></strong>';
                            $output .= '</div>';
                            $output .= '</div>'; // step 2

                            // Step 3: contacts
                            $output .= '<div class="picky-wizard-step" data-step="3" aria-hidden="true">';
                            $output .= $this->picky_wizard_step_title_html( __( 'Contact details', 'pickybooking' ) );
                            $output .= '<div class="picky-wizard-contacts">';
                            $output .= $this->picky_booking_account_bar_html( $options );
                            $shop_people_amount = $getOption('shop_people_amount', $options);
                            $booking_mode = $getOption('booking_mode', $options);
                            if((isset($shop_people_amount) && $shop_people_amount === '1') || $booking_mode === 'capacity'){
                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__( 'People amount', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-small-text-wrap">';
                                        $output .= '<span class="picky-small-text picky-max-people-amount-text">'.esc_html__( 'for this table maximum amount is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-max-people-amount"></span>';
                                        $output .= '<br><span class="picky-small-text picky-free-people-amount-text" style="display:none;">'.esc_html__( 'free space for selected time is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-free-people-amount" style="display:none;"></span>';
                                    $output .= '</div>';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<select class="picky-people-select" data-placeholder="'.esc_attr__('Select amount of people', 'pickybooking').'" id="picky_booking_people" name="picky_booking_people">';
                                            foreach ($people_all as $key => $value) {
                                                $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                            }
                                        $output .= '</select>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }

                            $output .= '<div class="picky-input-wrap">';
                                $output .= '<label class="picky-input-label">'.esc_html__( 'Name', 'pickybooking' ).'</label>';
                                $output .= '<div class="picky-input-inner">';
                                    $output .= '<div class="picky-input-control">';
                                    $output .= '<input type="text" class="picky-input" id="picky_booking_name" name="picky_booking_name" autocomplete="name" value="' . esc_attr( $picky_contact_id['name'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                    $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                    $output .= '</div>';
                                    $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                $output .= '</div>';
                            $output .= '</div>';

                            if ( isset( $options['show_phone'] ) && $options['show_phone'] === '1' ) {
                            $output .= '<div class="picky-input-wrap">';
                                $output .= '<label class="picky-input-label">'.esc_html__('Phone Number', 'pickybooking').'</label>';
                                $output .= '<div class="picky-input-inner">';
                                    $output .= '<div class="picky-input-control">';
                                    $output .= '<input type="text" class="picky-input" id="picky_booking_phone" name="picky_booking_phone" autocomplete="tel">';
                                    $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                    $output .= '</div>';
                                    $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '<div class="picky-error picky-error-phone">'.esc_html__('Please, fill correct phone number', 'pickybooking').'</div>';
                                $output .= '</div>';
                            $output .= '</div>';
                            }

                            if ( ( isset( $options['show_email'] ) && $options['show_email'] === '1' ) || ! empty( $picky_contact_id['logged_in'] ) ) {
                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__('E-mail', 'pickybooking').'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<div class="picky-input-control">';
                                        $output .= '<input type="text" class="picky-input" id="picky_booking_email" name="picky_booking_email" autocomplete="email" value="' . esc_attr( $picky_contact_id['email'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                        $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                        $output .= '<div class="picky-error picky-error-email">'.esc_html__('Please, fill correct email', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }

                            $output .= $form_confirm;
                            $output .= '</div>'; // .picky-wizard-contacts
                            $output .= '</div>'; // step 3
                            $output .= '</div></div>'; // track + viewport

                            $output .= $this->picky_wizard_footer_html( $picky_payment_required, $form_message );
                            $output .= '</div>'; // .picky-wizard
                        $output .= '</form>';

                        $output .= '</div>'; // .picky-smart-booking-overlay

                        // Selection tray — Classic Smart (2) only. Smart Map (4) embeds actions in the date/time bar.
                        if ( empty( $is_smart_map ) ) {
                        $output .= '<div class="picky-selection-tray" style="display:none;" aria-live="polite">';
                            $output .= '<div class="picky-selection-tray-inner">';
                                $output .= '<div class="picky-selection-tray-summary">';
                                    $output .= '<span class="picky-selection-tray-count"></span>';
                                    $output .= '<span class="picky-selection-tray-total" style="display:none;"></span>';
                                $output .= '</div>';
                                $output .= '<button type="button" class="picky-selection-tray-book-btn">';
                                    $output .= esc_html( $picky_payment_required ? __( 'Pay & Book', 'pickybooking' ) : __( 'Book Now', 'pickybooking' ) );
                                $output .= '</button>';
                            $output .= '</div>';
                        $output .= '</div>';
                        }

                        $output .= '</div>'; // .picky-draggable-wrap

                        $output .= '</div>'; // relative

                    $output .= '</div>'; 

                // 3
                } else if(isset($options['shortcode_layout']) && !empty($options['shortcode_layout']) && $options['shortcode_layout'] === '3'){ // 3

                    $picky_wrap_class = 'picky-tables-wrap picky-tables-size-' . $options['canvas_elements_size'] . ' picky-tables-wrap-third' . $picky_canvas_shell_class . ' ' . $picky_shortcode_class . $picky_event_once_class;
                    $output .= '<div class="' . esc_attr( $picky_wrap_class ) . '" id="' . esc_attr( (string) $picky_shortcode_id ) . '"' . $picky_wrap_library_attr . '>';

                        $output .= '<div class="picky-form-back"></div>';

                        $output .= '<div class="picky-form-thx">';
                            $output .= '<div class="picky-close"></div>';
                            $output .= '<div class="picky-form-thx-title">';
                                if(isset($options['form_thx']) && $options['form_thx'] !== ''){
                                    $output .= wp_kses_post( $options['form_thx'] );
                                } else {
                                    $output .= esc_html__( 'Thank You!', 'pickybooking' ) . '<br />' . esc_html__( 'We will call you back soon.', 'pickybooking' );
                                }
                            $output .= '</div>';
                        $output .= '</div>';

                        $output .= '<form class="picky-form-booking">';
                            $output .= wp_nonce_field('send_book','_wpnonce',true,false);
                            $output .= '<input type="hidden" name="picky_layout_id" value="' . esc_attr( (string)$layout_id ) . '">';
                            
                            // data-date
                            $data_exclude = array();
                            // data-time
                            $time_exclude = array();

                            $days_ahead = $this->daysAhead();
                            $days_off = $this->daysOff();
                            $times_off = $this->timesOff();

                            // create array with all days before last day - $days_ahead. In number and string view
                            $weak_days_ahead = $this->picky_build_wp_local_days_ahead( $days_ahead );
                            
                            
                            // foreach day in allowed period check to exclude times and days from settings of plugin
                            $not_work_time_array = array();
                            $key_start_time = 0;
                            $key_end_time = 0;
                            $time_all_fake = array();
                            $time_all_fake_more = array();
                            $key_not_work_time = 0;
                            foreach ($weak_days_ahead as $key => $value) {
                                $value_string = $value['string'];
                                $value_number = $value['number'];
                                $time_all_fake = $time_all;
                                $time_all_fake_more = $time_all;
                                $key_start_time = null;
                                $key_end_time = null;
                                $key_start_time_more = null;
                                $key_end_time_more = null;
                                if(in_array( $value['string'], $days_off)){ // add days to exclude because of days of the week
                                    $data_exclude[] = $value['number'];
                                } else { // add times to exclude because of days of the week and work time in this day
                                    if($times_off){
                                        foreach ($times_off as $key => $day) {
                                            if($day['day'] === $value['string']){
                                                foreach ($time_all as $key => $time_all_value) {
                                                    if(isset($day['time_start']) && !empty($day['time_start'])){
                                                        if($time_all_value === $day['time_start']){
                                                            $key_start_time = $key;
                                                        }
                                                    } 
                                                    if(isset($day['time_end']) && !empty($day['time_end'])){
                                                        if($time_all_value === $day['time_end']){
                                                            $key_end_time = $key;
                                                        }
                                                    } 
                                                    if(isset($day['time_start_more']) && !empty($day['time_start_more'])){
                                                        if($time_all_value === $day['time_start_more']){
                                                            $key_start_time_more = $key;
                                                        }
                                                    } 
                                                    if(isset($day['time_end_more']) && !empty($day['time_end_more'])){
                                                        if($time_all_value === $day['time_end_more']){
                                                            $key_end_time_more = $key;
                                                        }
                                                    } 
                                                }
                                            }
                                        }

                                        // first times
                                        $length = $key_end_time - $key_start_time;
                                        if($length < 0){
                                            $length = $key_end_time + count($time_all) - $key_start_time;
                                        }
                                        array_splice($time_all_fake, $key_start_time, $length+1);

                                        
                                        // second times
                                        if(isset($key_end_time_more) && !empty($key_end_time_more) 
                                        && isset($key_start_time_more) && !empty($key_start_time_more)
                                        && $key_start_time_more !== null && $key_end_time_more !== null ){
                                            $length_more = $key_end_time_more - $key_start_time_more;
                                            if($length_more < 0){
                                                $length_more = $key_end_time_more + count($time_all) - $key_start_time_more;
                                            }
                                            $time_all_fake_more = array_slice($time_all_fake_more, $key_start_time_more, $length_more+1);

                                            if(isset($time_all_fake_more) && !empty($time_all_fake_more)){
                                                $time_all_new = array(); 
                                                foreach ($time_all_fake_more as $val) { 
                                                  if (($key = array_search($val, $time_all_fake, TRUE))!==false) { 
                                                     $time_all_new[] = $val; 
                                                     unset($time_all_fake[$key]); 
                                                  } 
                                                } 
                                            }


                                        }

                                        // add times to exclude
                                        foreach ($time_all_fake as $key => $value_fake) {
                                            $time_exclude[] = array(
                                                'date' => $value_number,
                                                'time' => $value_fake
                                            );
                                        }

                                    }
                                }
                            }


                            // convert to json string
                            $data_exclude = urlencode(json_encode($data_exclude));
                            $time_exclude = urlencode(json_encode($time_exclude));

                            // Defensive option read — see Info::get_settings() docblock.
                            $picky_settings = Info::get_settings();
                            $data_maxdate = intval( $picky_settings['days_ahead'] );

                            $time_before = $this->picky_int_option_hours( $options['table_before_booked_time'] ?? 0 );
                            $time_after  = $this->picky_int_option_hours( $options['table_booked_time'] ?? 0 );
                            $show_time_to = ( isset( $options['show_time_to'] ) && $options['show_time_to'] === '1' );

                            $output .= '<div class="picky-wizard" data-picky-wizard="1">';
                            $output .= '<div class="picky-wizard-steps-viewport"><div class="picky-wizard-steps-track">';

                            // Step 1: date + slots
                            $output .= '<div class="picky-wizard-step is-active" data-step="1">';
                            $output .= $this->picky_wizard_step_title_html( __( 'Date & time', 'pickybooking' ) );
                            $output .= '<div class="picky-wizard-datetime">';
                            $output .= '<div class="picky-wizard-datetime-cal">';
                            $output .= '<div class="picky-input-wrap picky-calendar-left picky-tables-datapicker-block">';
                                $output .= '<label class="picky-input-label-big">'.esc_html__('Choose the Date', 'pickybooking').'</label>';
                                $output .= '<input type="hidden" class="picky-input"  id="picky_booking_date" name="picky_booking_date" value="' . esc_attr( $picky_event_once ? (string) $picky_event_slot['date'] : '' ) . '">';
                                $output .= '<div data-maxdate="'.esc_attr((string)$data_maxdate).'" data-time="'.esc_attr((string)$time_exclude).'" data-date="'.esc_attr((string)$data_exclude).'" data-before-time="'.intval( $time_before ).'" data-after-time="'.intval( $time_after ).'" class="picky-datepicker"></div>';
                                $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, pick the date', 'pickybooking').'</div>';
                            $output .= '</div>';
                            $output .= '</div>';
                            $output .= '<div class="picky-wizard-datetime-slots">';
                            $output .= $this->picky_slot_grids_html( $show_time_to );
                            $output .= '<div class="picky-input-wrap picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                $output .= '<div class="picky-input-inner">';
                                    $output .= '<select class="picky-select" data-placeholder="'.esc_attr__('Choose time', 'pickybooking').'" id="picky_booking_time" name="picky_booking_time">';
                                        foreach ($time_all as $key => $value) {
                                            $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                        }
                                    $output .= '</select>';
                                $output .= '</div>';
                            $output .= '</div>';
                            if ( $show_time_to ) {
                                $output .= '<div class="picky-input-wrap picky-input-wrap-time-to picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<select class="picky-select" data-placeholder="'.esc_attr__('Choose time', 'pickybooking').'" id="picky_booking_time_to" name="picky_booking_time_to">';
                                            foreach ($time_all as $key => $value) {
                                                $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                            }
                                        $output .= '</select>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }
                            $output .= '</div>';
                            $output .= '</div>';
                            $output .= '</div>'; // step 1

                            // Step 2: people / capacity (Simple has no multi services — keep as optional people step)
                            $output .= '<div class="picky-wizard-step" data-step="2" aria-hidden="true">';
                            $output .= $this->picky_wizard_step_title_html( __( 'Booking options', 'pickybooking' ) );
                            $output .= '<div class="picky-form-booking-third">';
                                $booking_mode = $getOption('booking_mode', $options);
                                if((isset($options['shop_people_amount']) && $options['shop_people_amount'] === '1') || $booking_mode === 'capacity'){
                                    $output .= '<div class="picky-input-wrap">';
                                        $output .= '<label class="picky-input-label">'.esc_html__( 'People amount', 'pickybooking' ).'</label>';
                                        $output .= '<div class="picky-small-text-wrap">';
                                            $output .= '<span class="picky-small-text picky-max-people-amount-text">'.esc_html__( 'for this table maximum amount is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-max-people-amount"></span>';
                                            $output .= '<br><span class="picky-small-text picky-free-people-amount-text" style="display:none;">'.esc_html__( 'free space for selected time is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-free-people-amount" style="display:none;"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<select class="picky-people-select" data-placeholder="'.esc_attr__('Select amount of people', 'pickybooking').'" id="picky_booking_people" name="picky_booking_people">';
                                                foreach ($people_all as $key => $value) {
                                                    $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                                }
                                            $output .= '</select>';
                                            $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                } else {
                                    $output .= '<p class="picky-wizard-step-skip-note">'.esc_html__( 'No extra options for this booking. Continue to your details.', 'pickybooking' ).'</p>';
                                }
                            $output .= '</div>';
                            $output .= '</div>'; // step 2

                            // Step 3: contacts
                            $output .= '<div class="picky-wizard-step" data-step="3" aria-hidden="true">';
                            $output .= $this->picky_wizard_step_title_html( __( 'Contact details', 'pickybooking' ) );
                            $output .= '<div class="picky-wizard-contacts">';
                            $output .= $this->picky_booking_account_bar_html( $options );
                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__( 'Name', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<div class="picky-input-control">';
                                        $output .= '<input type="text" class="picky-input" id="picky_booking_name" name="picky_booking_name" autocomplete="name" value="' . esc_attr( $picky_contact_id['name'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                        $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';

                                if ( isset( $options['show_phone'] ) && $options['show_phone'] === '1' ) {
                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__( 'Phone Number', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                    $output .= '<div class="picky-input-control">';
                                    $output .= '<input type="text" class="picky-input" id="picky_booking_phone" name="picky_booking_phone" autocomplete="tel">';
                                        $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                    $output .= '</div>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                        $output .= '<div class="picky-error picky-error-phone">'.esc_html__('Please, fill correct phone number', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                                }

                                if ( ( isset( $options['show_email'] ) && $options['show_email'] === '1' ) || ! empty( $picky_contact_id['logged_in'] ) ) {
                                    $output .= '<div class="picky-input-wrap">';
                                        $output .= '<label class="picky-input-label">'.esc_html__('E-mail', 'pickybooking').'</label>';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<div class="picky-input-control">';
                                            $output .= '<input type="text" class="picky-input" id="picky_booking_email" name="picky_booking_email" autocomplete="email" value="' . esc_attr( $picky_contact_id['email'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                            $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                            $output .= '</div>';
                                            $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                            $output .= '<div class="picky-error picky-error-email">'.esc_html__('Please, fill correct email', 'pickybooking').'</div>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                }

                                $output .= $form_confirm;
                            $output .= '</div>';
                            $output .= '</div>'; // step 3
                            $output .= '</div></div>'; // track + viewport

                            $output .= $this->picky_wizard_footer_html( $picky_payment_required, $form_message );
                            $output .= '</div>'; // .picky-wizard

                        $output .= '</form>';
                    $output .= '</div>'; 

                // 1
                } else { // Default
                    $to_small = '';
                    if(intval($options['canvas_width']) < 850){
                        $to_small = 'picky-tables-wrap-small-w';
                    }
                    $mobile_select = isset($options['mobile_table_select']) ? strval($options['mobile_table_select']) : '0';
                    $mobile_class = '';
                    if($mobile_select === '1'){
                        $mobile_class = ' picky-mobile-canvas';
                    } else if($mobile_select === '2'){
                        $mobile_class = ' picky-mobile-dropdown';
                    }
                    $picky_wrap_class = 'picky-tables-wrap picky-tables-size-' . $options['canvas_elements_size'] . ' ' . $to_small . ' picky-tables-wrap-default' . $mobile_class . $picky_canvas_shell_class . ' ' . $picky_shortcode_class . $picky_event_once_class;
                    $output .= '<div class="' . esc_attr( $picky_wrap_class ) . '" id="' . esc_attr( (string) $picky_shortcode_id ) . '"' . $picky_wrap_library_attr . '>';
    			    	$output .= '<div class="picky-draggable-wrap' . esc_attr( $picky_canvas_wrap_class ) . '" data-picky-render="builder-v2">';
                            $draggable_style = "";
                            $draggable_style .= ' style="';
                            // Use px (same as admin builder). Em sizes break when theme/plugin
                            // font-size rules change — item left/top stay in px and drift off the bg.
                            if($options['canvas_width']){
                                $draggable_style .= 'width: '.intval($options['canvas_width']).'px;';
                            }
                            if($options['canvas_height']){
                                $draggable_style .= 'height: '.intval($options['canvas_height']).'px;';
                            }
                            if ( ! $picky_canvas_transparent_bg && $layout_bg_canvas_color !== '' ) {
                                $draggable_style .= 'background-color:' . esc_attr( $layout_bg_canvas_color ) . ';';
                            }
                            if($layout_bg_canvas !== ''){  
                                $draggable_style .= 'background-image: url('.esc_url((string)$layout_bg_canvas) .');';
                                $layout_bg_mode = get_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', true );
                                $bg_mode = is_string( $layout_bg_mode ) && $layout_bg_mode !== ''
                                    ? $layout_bg_mode
                                    : ( isset($options['background-canvas-mode']) && $options['background-canvas-mode'] !== ''
                                        ? $options['background-canvas-mode']
                                        : Info::$DEFAULT_OPTIONS['background-canvas-mode'] );
                                if($bg_mode === 'cover'){
                                    $draggable_style .= 'background-size:cover;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'contain'){
                                    $draggable_style .= 'background-size:contain;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'stretch'){
                                    $draggable_style .= 'background-size:100% 100%;background-repeat:no-repeat;background-position:center center;';
                                } else if($bg_mode === 'repeat-x'){
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat-x;background-position:top left;';
                                } else if($bg_mode === 'repeat-y'){
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat-y;background-position:top left;';
                                } else if($bg_mode === 'center'){
                                    $draggable_style .= 'background-size:auto;background-repeat:no-repeat;background-position:center center;';
                                } else {
                                    $draggable_style .= 'background-size:auto;background-repeat:repeat;background-position:top left;';
                                }
                            } 
                            $draggable_style .= '"';
                            $ch_attr_def = isset($options['canvas_height']) ? intval($options['canvas_height']) : 0;
    				        $output .= '<div data-width="'.intval($options['canvas_width']).'" data-height="'.esc_attr((string)$ch_attr_def).'" class="picky-draggable"  '.$draggable_style.'';
                            $output .= '>';
    				            $i_array = 0;
    				            if(isset($jsonPosition) && !empty($jsonPosition)){
    				            	// foreach element
    				                foreach ($jsonPosition as $key => $val) {            
    				                    if(is_array($val)) { 
                                            $w = $val['x1']-$val['x'];
                                            $h = $val['y1']-$val['y'];
    				                        $class = "";
    				                        if($val['class'] !== ""){
    				                        	$class = $val['class'];
    				                        }
    				                        // style
                                            $el_left = $val['x'];
                                            $el_top = $val['y'];
                                            $element_style = "left: ".$val['x']."px; top: ".$val['y']."px;";
                                            $z_stack = isset( $val['z_index'] ) ? intval( $val['z_index'] ) : ( 10 + (int) $i_array );
                                            if ( $z_stack < 1 ) {
                                                $z_stack = 10 + (int) $i_array;
                                            }
                                            $element_style .= ' z-index: ' . $z_stack . ';';
                                            $element_style_table = '';
                                            // Do not apply legacy background-table per item in builder-v2.
                                            // Item visuals are controlled by .picky-element-shape (color/custom image).
                                            // Builder item size (saved as x1/y1). Apply to all items so resized items render identically on frontend.
                                            $w_i = (isset($w) && intval($w) > 0) ? intval($w) : 100;
                                            $h_i = (isset($h) && intval($h) > 0) ? intval($h) : 100;
                                            $element_style_table .= ' width: '.$w_i.'px; height: '.$h_i.'px;';
    				                        

    								        // data-date / data-time from this item's bookings only.
    								        $picky_item_bookings = isset( $booked_by_item_id[ (string) $val['id'] ] ) ? $booked_by_item_id[ (string) $val['id'] ] : array();
    								        list( $data_exclude, $time_exclude ) = $this->picky_element_booking_excludes( $picky_item_bookings, $time_all, $options );
    								        $data_exclude = urlencode(json_encode($data_exclude));
    								        $time_exclude = urlencode(json_encode($time_exclude));



                                            $rotate_z = isset($val['rotate_z']) ? intval($val['rotate_z']) : 0;
                                            $rx = isset($val['rotate_x']) ? intval($val['rotate_x']) : 0;
                                            $ry = isset($val['rotate_y']) ? intval($val['rotate_y']) : 0;
                                            // Backward compat (old preset field)
                                            if((!isset($val['rotate_x']) && !isset($val['rotate_y'])) && isset($val['rotate_3d'])){
                                                $rotate_3d = intval($val['rotate_3d']);
                                                if($rotate_3d === 1){ $rx = 25; $ry = 0; }
                                                if($rotate_3d === 2){ $rx = -25; $ry = 0; }
                                                if($rotate_3d === 3){ $rx = 0; $ry = 25; }
                                                if($rotate_3d === 4){ $rx = 0; $ry = -25; }
                                            }
                                            $custom_url = isset($val['custom_url']) ? esc_url((string)$val['custom_url']) : '';
                                            // Match frontend JS model: Z on table (or table-wrap for text), 3D tilt on shape only.
                                            // Putting rotateZ on the shape caused a load-time spin (CSS transition:all).
                                            $is_text_el = ( isset( $val['class'] ) && (string) $val['class'] === 'type-text' );
                                            $shape_style = 'transform: perspective(600px) rotateX('.$rx.'deg) rotateY('.$ry.'deg);';
                                            $table_style = '';
                                            $table_wrap_style_extra = '';
                                            $overlay_style_extra = '';
                                            if ( $is_text_el ) {
                                                $table_wrap_style_extra = 'transform:rotateZ(' . intval( $rotate_z ) . 'deg);transform-origin:center center;';
                                                // Match builder: do not counter-rotate the overlay for text
                                                // (avoids a full-size ghost plate; overlay chrome is unused on public).
                                                $overlay_style_extra = '';
                                            } else {
                                                $table_style = 'transform:rotateZ(' . intval( $rotate_z ) . 'deg);transform-origin:center center;';
                                            }
                                            $opacity = isset($val['opacity']) ? floatval($val['opacity']) : 1;
                                            if($opacity < 0){ $opacity = 0; }
                                            if($opacity > 1){ $opacity = 1; }
                                            $shape_style .= 'opacity:'.$opacity.';';
                                            // Background image priority: custom_url -> background-table (shapes skip table-bg fallback)
                                            if($val['class'] !== 'type-text' && $val['class'] !== 'type-shape' && $val['class'] !== 'type-icon' && $val['class'] !== 'type-wall'){
                                                if($custom_url){
                                                    $shape_style .= 'background-image:url('.$custom_url.');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                                } else if($layout_bg_table !== ''){
                                                    $shape_style .= 'background-image:url('.esc_url((string)$layout_bg_table).');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                                }
                                            } else if($val['class'] === 'type-shape' && $custom_url){
                                                $shape_style .= 'background-image:url('.$custom_url.');background-size:cover;background-position:center;background-repeat:no-repeat;';
                                            }
                                            if($val['class'] !== 'type-text' && isset($val['color']) && $val['color'] !== '' && ! $custom_url){
                                                if ( $val['class'] === 'type-icon' ) {
                                                    $shape_style .= 'color:'.esc_attr((string)$val['color']).';background:transparent;';
                                                } else {
                                                    $picky_is_bookable_plate2 = ! in_array( (string) $val['class'], array( 'type-text', 'type-shape', 'type-icon', 'type-wall' ), true );
                                                    if ( ! $picky_hide_item_fill || ! $picky_is_bookable_plate2 ) {
                                                        $shape_style .= 'background-color:'.esc_attr((string)$val['color']).';';
                                                    }
                                                }
                                            }
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-shape' ) {
                                                $picky_shape_type2 = isset( $val['shape_type'] ) ? (string) $val['shape_type'] : 'rectangle';
                                                if ( $picky_shape_type2 === 'circle' ) {
                                                    $shape_style .= 'border-radius:50%;';
                                                } elseif ( $picky_shape_type2 === 'triangle' ) {
                                                    $shape_style .= 'border-radius:0;';
                                                } elseif ( isset( $val['shape_radius'] ) && (string) $val['shape_radius'] !== '' ) {
                                                    $picky_sr2 = max( 0, min( 120, intval( $val['shape_radius'] ) ) );
                                                    $shape_style .= 'border-radius:' . $picky_sr2 . 'px;';
                                                } elseif ( $picky_shape_type2 === 'rounded' ) {
                                                    $shape_style .= 'border-radius:15px;';
                                                } elseif ( $picky_shape_type2 === 'rectangle' ) {
                                                    $shape_style .= 'border-radius:0;';
                                                }
                                                $picky_sbw2 = isset( $val['shape_border_width'] ) ? max( 0, min( 32, intval( $val['shape_border_width'] ) ) ) : 0;
                                                if ( $picky_sbw2 > 0 ) {
                                                    $picky_sbc2 = ( isset( $val['shape_border_color'] ) && (string) $val['shape_border_color'] !== '' ) ? (string) $val['shape_border_color'] : '#333333';
                                                    $shape_style .= 'border:' . $picky_sbw2 . 'px solid ' . esc_attr( $picky_sbc2 ) . ';box-sizing:border-box;';
                                                }
                                            } elseif ( isset( $val['class'] ) && $val['class'] === 'type-wall' ) {
                                                if ( isset( $val['wall_radius'] ) && (string) $val['wall_radius'] !== '' ) {
                                                    $picky_wr2 = max( 0, min( 120, intval( $val['wall_radius'] ) ) );
                                                    $shape_style .= '--picky-wall-radius:' . $picky_wr2 . 'px;border-radius:' . $picky_wr2 . 'px;';
                                                }
                                            } elseif ( isset( $val['class'] ) && ! in_array( (string) $val['class'], array( 'type-wall', 'type-text', 'type-shape', 'type-icon', 'type-4', 'type-circle' ), true ) ) {
                                                if ( isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' ) {
                                                    $picky_sq_r_inline = max( 0, min( 120, intval( $val['square_radius'] ) ) );
                                                    if ( $picky_sq_r_inline > 0 ) {
                                                        $shape_style .= 'border-radius:' . $picky_sq_r_inline . 'px!important;';
                                                    }
                                                }
                                            }
                                            $picky_label_position = isset( $val['label_position'] ) ? (string) $val['label_position'] : 'bottom';
                                            if ( ! in_array( $picky_label_position, array( 'top', 'bottom' ), true ) ) {
                                                $picky_label_position = 'bottom';
                                            }
                                            $picky_pub_label_fs2 = isset( $val['label_font_size'] ) ? max( 8, min( 200, intval( $val['label_font_size'] ) ) ) : 12;
                                            $picky_pub_label_c2  = isset( $val['label_color'] ) ? picky_sanitize_hex_color( (string) $val['label_color'] ) : '';
                                            if ( ! $picky_pub_label_c2 ) {
                                                $picky_pub_label_c2 = '#333333';
                                            }
                                            $picky_pub_label_w2    = isset( $val['label_font_weight'] ) ? (string) $val['label_font_weight'] : 'normal';
                                            $picky_pub_label_w_ok2 = array( 'normal', 'bold', '300', '400', '500', '600', '700', '800', '900' );
                                            if ( ! in_array( $picky_pub_label_w2, $picky_pub_label_w_ok2, true ) ) {
                                                $picky_pub_label_w2 = 'normal';
                                            }
                                            $picky_pub_label_style_attr2 = 'font-size:' . intval( $picky_pub_label_fs2 ) . 'px;color:' . esc_attr( $picky_pub_label_c2 ) . ';font-weight:' . esc_attr( $picky_pub_label_w2 ) . ';';
                                            $picky_show_id = '1';
                                            if ( isset( $val['show_id'] ) && (string) $val['show_id'] === '0' ) {
                                                $picky_show_id = '0';
                                            }
                                            if ( ! $picky_show_ids_on_map ) {
                                                $picky_show_id = '0';
                                            }
                                            $picky_id_badge_style = ( $picky_show_id === '0' ) ? 'display:none !important;' : '';
                                            $picky_pub_shape_attrs2 = '';
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-shape' ) {
                                                $picky_pub_shape_radius2 = ( isset( $val['shape_radius'] ) && (string) $val['shape_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['shape_radius'] ) ) ) : '';
                                                $picky_pub_shape_bw2     = isset( $val['shape_border_width'] ) ? max( 0, min( 32, intval( $val['shape_border_width'] ) ) ) : 0;
                                                $picky_pub_shape_bc2     = ( isset( $val['shape_border_color'] ) && (string) $val['shape_border_color'] !== '' ) ? (string) $val['shape_border_color'] : '#333333';
                                                $picky_pub_shape_attrs2  = ' data-shape-radius="' . esc_attr( $picky_pub_shape_radius2 ) . '" data-shape-border-width="' . esc_attr( (string) $picky_pub_shape_bw2 ) . '" data-shape-border-color="' . esc_attr( $picky_pub_shape_bc2 ) . '"';
                                            }
                                            $picky_pub_square_attrs2 = '';
                                            if ( isset( $val['class'] ) && ! in_array( (string) $val['class'], array( 'type-wall', 'type-text', 'type-shape', 'type-icon', 'type-4', 'type-circle' ), true ) ) {
                                                $picky_pub_sq_r2 = ( isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['square_radius'] ) ) ) : '';
                                                if ( $picky_pub_sq_r2 !== '' && (int) $picky_pub_sq_r2 > 0 ) {
                                                    $picky_pub_square_attrs2 = ' data-square-radius="' . esc_attr( $picky_pub_sq_r2 ) . '"';
                                                }
                                            }
                                            $picky_pub_wall_attrs2 = '';
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-wall' && isset( $val['wall_radius'] ) && (string) $val['wall_radius'] !== '' ) {
                                                $picky_pub_wr2_attr = (string) max( 0, min( 120, intval( $val['wall_radius'] ) ) );
                                                $picky_pub_wall_attrs2 = ' data-wall-radius="' . esc_attr( $picky_pub_wr2_attr ) . '"';
                                            }

                                            $output .= '<div 
                                                class="picky-element '.esc_attr((string)$class).' rotate-'.esc_attr((string)$val['rotate']).'" 
                                                data-date="'.esc_attr((string)$data_exclude).'" 
                                                data-time="'.esc_attr((string)$time_exclude).'"
                                                data-left="'.esc_attr((string)$el_left).'" 
                                                data-top="'.esc_attr((string)$el_top).'" 
                                                data-seats-size="'.''.'"
                                                data-id="'.esc_attr((string)$val['id']).'" 
                                                data-show-id="'.esc_attr( $picky_show_id ).'" 
                                                data-unique="'.esc_attr((string)$val['unique']).'" 
                                                data-seats="'.esc_attr((string)$val['seats']).'" 
                                                data-max-seats="'.esc_attr((string)$val['max_seats']).'" 
                                                data-big-side="'.esc_attr((string)$val['big_side']).'" 
                                                data-type="'.esc_attr((string)$val['class']).'" 
                                                data-rotate="'.esc_attr((string)$val['rotate']).'" 
                                                data-rotate-z="'.esc_attr((string)$rotate_z).'" 
                                                data-rotate-x="'.esc_attr((string)$rx).'" 
                                                data-rotate-y="'.esc_attr((string)$ry).'" 
                                                data-z-index="'.esc_attr( (string) $z_stack ).'" 
                                                data-opacity="'.esc_attr((string)$opacity).'" 
                                                data-custom-url="'.esc_attr((string)$custom_url).'" 
                                                data-text="'.(isset($val['text']) ? esc_attr((string)$val['text']) : '').'" 
                                                data-text-color="'.esc_attr( picky_sanitize_hex_color( isset( $val['text_color'] ) ? (string) $val['text_color'] : '#333333' ) ?: '#333333' ).'" 
                                                data-text-color-mode="'.( ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom' ).'" 
                                                data-font-size="'.(isset($val['font_size']) ? esc_attr((string)$val['font_size']) : '16').'" 
                                                data-line-height="'.( ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' ) ? esc_attr( (string) max( 8, min( 400, intval( $val['line_height'] ) ) ) ) : '' ).'"
                                                data-font-family="'.(isset($val['font_family']) ? esc_attr((string)$val['font_family']) : 'inherit').'" 
                                                data-font-weight="'.(isset($val['font_weight']) ? esc_attr((string)$val['font_weight']) : 'normal').'" data-padding="'.(isset($val['padding']) ? esc_attr((string)$val['padding']) : '10').'" data-color="'.(isset($val['color']) ? esc_attr((string)$val['color']) : '#ffffff').'" 
                                                data-shape-type="'.(isset($val['shape_type']) ? esc_attr((string)$val['shape_type']) : 'rectangle').'"'.$picky_pub_shape_attrs2.$picky_pub_square_attrs2.$picky_pub_wall_attrs2.' 
                                                data-icon="'.esc_attr( isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '' ).'"
                                                data-label-position="'.esc_attr( $picky_label_position ).'"
                                                data-service-ids="'.esc_attr( ( isset( $val['service_ids'] ) && is_array( $val['service_ids'] ) ) ? (string) wp_json_encode( $val['service_ids'] ) : '[]' ).'"
                                                data-custom-id="'.esc_attr( isset( $val['custom_id'] ) ? (string) $val['custom_id'] : '' ).'"
                                                style="'. esc_attr((string)$element_style).'">
                                                    <div class="picky-element-table-wrap" style="'.esc_attr( (string) $element_style_table . $table_wrap_style_extra ).'">
                                                        <div class="picky-element-table"'.( $table_style !== '' ? ' style="'.esc_attr( (string) $table_style ).'"' : '' ).'>
                                                            ';
                                                            $inner_shape = '';
                                                            if($val['class'] === 'type-text'){
                                                                $text = isset($val['text']) ? $val['text'] : '';
                                                                $fs = isset($val['font_size']) ? intval($val['font_size']) : 16;
                                                                if ( $fs < 8 ) { $fs = 8; }
                                                                $lh = ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' )
                                                                    ? max( 8, min( 400, intval( $val['line_height'] ) ) )
                                                                    : $fs;
                                                                $ff = isset($val['font_family']) ? $val['font_family'] : 'inherit';
                                                                if ( $ff === '' || $ff === 'inherit' ) {
                                                                    $ff = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
                                                                }
                                                                $fw = isset($val['font_weight']) ? $val['font_weight'] : 'normal';
                                                                $t_color = picky_sanitize_hex_color( isset( $val['text_color'] ) ? (string) $val['text_color'] : '#333333' ) ?: '#333333';
                                                                $t_color_mode = ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom';
                                                                $t_bg = picky_sanitize_hex_color( isset( $val['color'] ) ? (string) $val['color'] : '' ) ?: '';
                                                                $resolved_text_color = ( $t_color_mode === 'theme' ) ? 'inherit' : esc_attr((string)$t_color);
                                                                $t_bg_css = ( is_string( $t_bg ) && $t_bg !== '' ) ? 'background-color:' . esc_attr( (string) $t_bg ) . ';' : '';
                                                                $t_pad = isset( $val['padding'] ) ? max( 0, min( 120, intval( $val['padding'] ) ) ) : 10;
                                                                /* Same as builder: padding on .picky-element-text-content; shape shrink-wraps to glyphs. */
                                                                $inner_shape = '<div class="picky-element-text-content" style="font-size:'.$fs.'px;--picky-text-line-height:'.$lh.'px;line-height:'.$lh.'px;font-family:'.esc_attr((string)$ff).'; font-weight:'.esc_attr((string)$fw).'; color:'.$resolved_text_color.';padding:'.$t_pad.'px;'.$t_bg_css.'">'.esc_html((string)$text).'</div>';
                                                            } elseif ( isset( $val['class'] ) && $val['class'] === 'type-icon' ) {
                                                                $picky_pub_icon2 = isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '';
                                                                if ( $picky_pub_icon2 === '' ) {
                                                                    $picky_pub_icon2 = 'star';
                                                                }
                                                                $inner_shape = '<span class="picky-element-icon-wrap">' . wp_kses( \Picky\Booking_Icons::svg_markup( $picky_pub_icon2, 'picky-bicon picky-element-icon' ), \Picky\Booking_Icons::kses_allowed() ) . '</span>';
                                                            }
                                                            $output .= '<div class="picky-element-shape" style="'.esc_attr((string)$shape_style).'">'.$inner_shape.'</div>
                                                        </div>
                                                        <div class="picky-element-overlay"'.( $overlay_style_extra !== '' ? ' style="'.esc_attr( (string) $overlay_style_extra ).'"' : '' ).'>
                                                            <div class="picky-element-id" style="'.esc_attr( $picky_id_badge_style ).'">'. esc_html( ( isset( $val['custom_id'] ) && (string) $val['custom_id'] !== '' ) ? (string) $val['custom_id'] : (string) $val['id'] ).'</div>
                                                            <div class="picky-element-avatar" hidden aria-hidden="true"></div>';
                                                        // Capacity badge: always render for bookable items (shown on hover via CSS).
                                                        $picky_el_class = isset( $val['class'] ) ? (string) $val['class'] : '';
                                                        $picky_is_bookable = ! in_array( $picky_el_class, array( 'type-wall', 'type-text', 'type-shape', 'type-icon' ), true );
                                                        if ( $picky_is_bookable ) {
                                                            $picky_cap = '';
                                                            if ( isset( $val['max_seats'] ) && (string) $val['max_seats'] !== '' ) {
                                                                $picky_cap = (string) $val['max_seats'];
                                                            } elseif ( isset( $val['seats'] ) && (string) $val['seats'] !== '' ) {
                                                                $picky_cap = (string) $val['seats'];
                                                            }
                                                            if ( $picky_cap === '' ) {
                                                                $picky_cap = '1';
                                                            }
                                                            $output .= '<div class="picky-element-seats">' . esc_html( $picky_cap ) . '</div>';
                                                        }
                                                        // Label
                                                        if ( $picky_show_labels_on_map && isset($val['label']) && $val['label'] !== '' ) {
                                                            $output .= '<div class="picky-element-label" style="'.esc_attr( $picky_pub_label_style_attr2 ).'">'.esc_html((string)$val['label']).'</div>';
                                                        }

                                                        // Seat markers are deprecated in builder-v2 frontend.
                                                        // Keep only center value / max persons UI.
                                                        $output .= '</div>
                                                    </div>
                                                </div>';

    				                        $i_array++;

    				                    }
    				                }
    				            }
    			            $output .= '</div>';

                            // Selection tray — appears at the bottom of the canvas when ≥1 item is selected.
                            // Lets users multi-select before opening the form popup.
                            $output .= '<div class="picky-selection-tray" style="display:none;" aria-live="polite">';
                                $output .= '<div class="picky-selection-tray-inner">';
                                    $output .= '<div class="picky-selection-tray-summary">';
                                        $output .= '<span class="picky-selection-tray-count"></span>';
                                        $output .= '<span class="picky-selection-tray-total" style="display:none;"></span>';
                                    $output .= '</div>';
                                    $output .= '<button type="button" class="picky-selection-tray-book-btn">';
                                        $output .= esc_html( $picky_payment_required ? __( 'Pay & Book', 'pickybooking' ) : __( 'Book Now', 'pickybooking' ) );
                                    $output .= '</button>';
                                $output .= '</div>';
                            $output .= '</div>';

                            // Close canvas wrapper before overlays/forms so .picky-draggable-wrap overflow does not clip popups.
                            $output .= '</div>';

                        $output .= '<div class="picky-smart-booking-overlay" aria-hidden="true">';

                            // BACK
                            $output .= '<div class="picky-form-back"></div>';

                            // THX
                            $output .= '<div class="picky-form-thx">';
                                $output .= '<div class="picky-close"></div>';
                                $output .= '<div class="picky-form-thx-title">';
                                    if(isset($options['form_thx']) && $options['form_thx'] !== ''){
                                        $output .= wp_kses_post( $options['form_thx'] );
                                    } else {
                                        $output .= esc_html__( 'Thank You!', 'pickybooking' ) . '<br />' . esc_html__( 'We will call you back soon.', 'pickybooking' );
                                    }
                                $output .= '</div>';
                            $output .= '</div>';


                            // FORM
                        $output .= '<form class="picky-form-booking picky-form-booking-second">';
                                $output .= wp_nonce_field('send_book','_wpnonce',true,false);
                            $output .= '<input type="hidden" name="picky_layout_id" value="' . esc_attr( (string)$layout_id ) . '">';
                            $output .= '<input type="hidden" name="picky_booking_id" id="picky_booking_id" value="">';
                            $output .= '<input type="hidden" name="picky_booking_unique" id="picky_booking_unique" value="">';
                                $output .= '<input type="hidden" name="picky_booking_seats" id="picky_booking_seats" value="">';
                                // Multi-item container — JS injects hidden inputs: picky_items[0][unique], picky_items[0][service_id], etc.
                                $output .= '<div class="picky-items-hidden-container" id="picky_items_container"></div>';
                                $output .= '<div class="picky-form-booking-header">';
                                    $output .= '<div class="picky-form-booking-title"'
                                        /* translators: %s: value. */
                                        .' data-text="'.esc_attr( sprintf( __( 'Book %s', 'pickybooking' ), $this->picky_item_label() ) ).' #"'
                                        /* translators: %s: value. */
                                        .' data-text-multi="'.esc_attr( sprintf( __( 'Book %s', 'pickybooking' ), $this->picky_items_label() ) ).' #"'
                                        .'></div>';
                                    $output .= '<div class="picky-booking-datetime-chip" role="button" tabindex="0" aria-expanded="false">';
                                        $output .= '<span class="picky-chip-date"></span>';
                                        $output .= '<span class="picky-chip-sep">·</span>';
                                        $output .= '<span class="picky-chip-time"></span>';
                                        $output .= '<span class="picky-chip-edit" aria-hidden="true">✎</span>';
                                    $output .= '</div>';
                                    $output .= '<button type="button" class="picky-close picky-close-btn" aria-label="'.esc_attr__( 'Close', 'pickybooking' ).'"></button>';
                                $output .= '</div>';

                            // data-date
                                $data_exclude = array();
                                // data-time
                                $time_exclude = array();

                                $days_ahead = $this->daysAhead();
                                $days_off = $this->daysOff();
                                $times_off = $this->timesOff();

                                // create array with all days before last day - $days_ahead. In number and string view
                                $weak_days_ahead = $this->picky_build_wp_local_days_ahead( $days_ahead );
                               
                                // foreach day in allowed period check to exclude times and days from settings of plugin
                                $not_work_time_array = array();
                                $key_start_time = 0;
                                $key_end_time = 0;
                                $time_all_fake = array();
                                $time_all_fake_more = array();
                                $key_not_work_time = 0;
                                foreach ($weak_days_ahead as $key => $value) {
                                    $value_string = $value['string'];
                                    $value_number = $value['number'];
                                    $time_all_fake = $time_all;
                                    $time_all_fake_more = $time_all;
                                    $key_start_time = null;
                                    $key_end_time = null;
                                    $key_start_time_more = null;
                                    $key_end_time_more = null;
                                    if(in_array( $value['string'], $days_off)){ // add days to exclude because of days of the week
                                        $data_exclude[] = $value['number'];
                                    } else { // add times to exclude because of days of the week and work time in this day
                                        if($times_off){
                                            foreach ($times_off as $key => $day) {
                                                if($day['day'] === $value['string']){
                                                    foreach ($time_all as $key => $time_all_value) {
                                                        if(isset($day['time_start']) && !empty($day['time_start'])){
                                                            if($time_all_value === $day['time_start']){
                                                                $key_start_time = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_end']) && !empty($day['time_end'])){
                                                            if($time_all_value === $day['time_end']){
                                                                $key_end_time = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_start_more']) && !empty($day['time_start_more'])){
                                                            if($time_all_value === $day['time_start_more']){
                                                                $key_start_time_more = $key;
                                                            }
                                                        } 
                                                        if(isset($day['time_end_more']) && !empty($day['time_end_more'])){
                                                            if($time_all_value === $day['time_end_more']){
                                                                $key_end_time_more = $key;
                                                            }
                                                        } 
                                                    }
                                                }
                                            }

                                            // first times
                                            $length = $key_end_time - $key_start_time;
                                            if($length < 0){
                                                $length = $key_end_time + count($time_all) - $key_start_time;
                                            }
                                            array_splice($time_all_fake, $key_start_time, $length+1);

                                            
                                            // second times
                                            if(isset($key_end_time_more) && !empty($key_end_time_more) 
                                            && isset($key_start_time_more) && !empty($key_start_time_more)
                                            && $key_start_time_more !== null && $key_end_time_more !== null ){
                                                $length_more = $key_end_time_more - $key_start_time_more;
                                                if($length_more < 0){
                                                    $length_more = $key_end_time_more + count($time_all) - $key_start_time_more;
                                                }
                                                $time_all_fake_more = array_slice($time_all_fake_more, $key_start_time_more, $length_more+1);

                                                if(isset($time_all_fake_more) && !empty($time_all_fake_more)){
                                                    $time_all_new = array(); 
                                                    foreach ($time_all_fake_more as $val) { 
                                                      if (($key = array_search($val, $time_all_fake, TRUE))!==false) { 
                                                         $time_all_new[] = $val; 
                                                         unset($time_all_fake[$key]); 
                                                      } 
                                                    } 
                                                }


                                            }

                                            // add times to exclude
                                            foreach ($time_all_fake as $key => $value_fake) {
                                                $time_exclude[] = array(
                                                    'date' => $value_number,
                                                    'time' => $value_fake
                                                );
                                            }

                                        }
                                    }
                                }

                                // convert to json string
                                $data_exclude = urlencode(json_encode($data_exclude));
                                $time_exclude = urlencode(json_encode($time_exclude));

                                // Defensive option read — see Info::get_settings() docblock.
                                $picky_settings = Info::get_settings();
                                $data_maxdate = intval( $picky_settings['days_ahead'] );

                                $time_before = $this->picky_int_option_hours( $options['table_before_booked_time'] ?? 0 );
                                $time_after  = $this->picky_int_option_hours( $options['table_booked_time'] ?? 0 );

                                $show_time_to = ( isset( $options['show_time_to'] ) && $options['show_time_to'] === '1' );

                                // Wizard: Date+slots → Services → Contacts
                                $output .= '<div class="picky-wizard" data-picky-wizard="1"' . ( $picky_event_once ? ' data-picky-start-step="2"' : '' ) . '>';
                                $output .= '<div class="picky-wizard-steps-viewport"><div class="picky-wizard-steps-track">';

                                // Step 1: calendar + slot buttons (column titles like Smart — no centered step title)
                                $output .= '<div class="picky-wizard-step is-active" data-step="1">';
                                $output .= '<div class="picky-wizard-datetime picky-classic-datetime">';
                                $output .= '<div class="picky-wizard-datetime-cal">';
                                $output .= '<label class="picky-input-label-big picky-datetime-col-title">'.esc_html__( 'Date & time', 'pickybooking' ).'</label>';
                                $output .= '<div class="picky-input-wrap picky-input-wrap-datepicker picky-calendar-left">';
                                    $output .= '<input type="hidden" class="picky-input"  id="picky_booking_date" name="picky_booking_date" value="' . esc_attr( $picky_event_once ? (string) $picky_event_slot['date'] : '' ) . '">';
                                    $output .= '<div data-maxdate="'.esc_attr((string)$data_maxdate).'" data-time="'.esc_attr((string)$time_exclude).'" data-date="'.esc_attr((string)$data_exclude).'" data-before-time="'.intval( $time_before ).'" data-after-time="'.intval( $time_after ).'" class="picky-datepicker"></div>';
                                    $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, pick the date', 'pickybooking').'</div>';
                                $output .= '</div>';
                                $output .= '</div>'; // .picky-wizard-datetime-cal

                                $output .= '<div class="picky-wizard-datetime-slots">';
                                $output .= $this->picky_slot_grids_html( $show_time_to, 'picky-input-label picky-datetime-col-title' );
                                // Hidden selects kept for JS/select2 sync + form POST.
                                $output .= '<div class="picky-input-wrap picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<select class="picky-select" data-placeholder="'.esc_attr__('Choose time', 'pickybooking').'" id="picky_booking_time" name="picky_booking_time">';
                                            foreach ($time_all as $key => $value) {
                                                $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                            }
                                        $output .= '</select>';
                                    $output .= '</div>';
                                $output .= '</div>';
                                if ( $show_time_to ) {
                                    $output .= '<div class="picky-input-wrap picky-input-wrap-time-to picky-slot-select-fallback" style="display:none;" aria-hidden="true">';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<select class="picky-select" data-placeholder="'.esc_attr__('Choose time', 'pickybooking').'" id="picky_booking_time_to" name="picky_booking_time_to">';
                                                foreach ($time_all as $key => $value) {
                                                    $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                                }
                                            $output .= '</select>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                }
                                $output .= '</div>'; // .picky-wizard-datetime-slots
                                $output .= '</div>'; // .picky-wizard-datetime
                                $output .= '</div>'; // step 1

                                // Step 2: booked items + optional services
                                // Title lives in .picky-form-booking-header ("Book Table #3") — no duplicate step title.
                                $output .= '<div class="picky-wizard-step" data-step="2" aria-hidden="true">';
                                $output .= '<div class="picky-form-section picky-form-section-items">';
                            if($mobile_select === '2'){
                                $output .= '<div class="picky-input-wrap picky-input-wrap-mobile-table">';
                                    /* translators: %s: value. */
                                    $output .= '<label class="picky-input-label-big">'.esc_html( sprintf( __( 'Select the %s', 'pickybooking' ), $this->picky_item_label() ) ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        /* translators: %s: value. */
                                        $output .= '<select class="picky-select picky-mobile-table-select" data-placeholder="'.esc_attr( sprintf( __( 'Choose %s', 'pickybooking' ), $this->picky_item_label() ) ).'" id="picky_booking_table_mobile" name="picky_booking_table_mobile">';
                                            $output .= '<option value=""></option>';
                                        $output .= '</select>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }
                                $output .= '<div class="picky-input-wrap picky-input-wrap-table-summary picky-items-summary-wrap" style="display:none;" aria-live="polite">';
                                    /* translators: %s: value. */
                                    $output .= '<label class="picky-input-label-big picky-wizard-hide-label">'.esc_html( sprintf( __( 'Your %s', 'pickybooking' ), $this->picky_items_label() ) ).'</label>';
                                    $output .= '<div class="picky-selected-items-list picky-tile-grid" id="picky_selected_items_list"></div>';
                                    $output .= '<div class="picky-items-selection-notice" style="display:none;"></div>';
                                $output .= '</div>';

                                $output .= '<div class="picky-input-wrap picky-services-panel-wrap" style="display:none;">';
                                    $output .= '<div class="picky-services-add-lead">';
                                        $output .= '<span class="picky-services-add-text">'.esc_html__( 'Add services you need', 'pickybooking' ).'</span>';
                                    $output .= '</div>';
                                    $output .= '<div class="picky-services-panel" id="picky_services_panel"></div>';
                                $output .= '</div>';

                                $output .= '<div class="picky-order-total-wrap" style="display:none;">';
                                    $output .= '<span class="picky-order-total-label">'.esc_html__( 'Total', 'pickybooking' ).'</span> ';
                                    $output .= '<strong class="picky-order-total"></strong>';
                                $output .= '</div>';
                                $output .= '</div>'; // .picky-form-section-items
                                $output .= '</div>'; // step 2

                                // Step 3: contacts
                                $output .= '<div class="picky-wizard-step" data-step="3" aria-hidden="true">';
                                $output .= $this->picky_wizard_step_title_html( __( 'Contact details', 'pickybooking' ) );
                                $output .= '<div class="picky-order-wrap picky-wizard-contacts">';
                                $output .= $this->picky_booking_account_bar_html( $options );

                                $booking_mode = $getOption('booking_mode', $options);
                                if((isset($options['shop_people_amount']) && $options['shop_people_amount'] === '1') || $booking_mode === 'capacity'){
                                    $output .= '<div class="picky-input-wrap">';
                                        $output .= '<label class="picky-input-label">'.esc_html__( 'People amount', 'pickybooking' ).'</label>';
                                        $output .= '<div class="picky-small-text-wrap">';
                                            $output .= '<span class="picky-small-text picky-max-people-amount-text">'.esc_html__( 'for this table maximum amount is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-max-people-amount"></span>';
                                            $output .= '<br><span class="picky-small-text picky-free-people-amount-text" style="display:none;">'.esc_html__( 'free space for selected time is:', 'pickybooking' ).'&nbsp;</span><span class="picky-small-text picky-free-people-amount" style="display:none;"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<select class="picky-people-select" data-placeholder="'.esc_attr__('Select amount of people', 'pickybooking').'" id="picky_booking_people" name="picky_booking_people">';
                                                foreach ($people_all as $key => $value) {
                                                    $output .= '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
                                                }
                                            $output .= '</select>';
                                            $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                }

                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__( 'Name', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<div class="picky-input-control">';
                                        $output .= '<input type="text" class="picky-input" id="picky_booking_name" name="picky_booking_name" autocomplete="name" value="' . esc_attr( $picky_contact_id['name'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                        $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';

                                if ( isset( $options['show_phone'] ) && $options['show_phone'] === '1' ) {
                                $output .= '<div class="picky-input-wrap">';
                                    $output .= '<label class="picky-input-label">'.esc_html__( 'Phone Number', 'pickybooking' ).'</label>';
                                    $output .= '<div class="picky-input-inner">';
                                        $output .= '<div class="picky-input-control">';
                                        $output .= '<input type="text" class="picky-input" id="picky_booking_phone" name="picky_booking_phone" autocomplete="tel">';
                                        $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                        $output .= '</div>';
                                        $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                        $output .= '<div class="picky-error picky-error-phone">'.esc_html__('Please, fill correct phone number', 'pickybooking').'</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                                }

                                if ( ( isset( $options['show_email'] ) && $options['show_email'] === '1' ) || ! empty( $picky_contact_id['logged_in'] ) ) {
                                    $output .= '<div class="picky-input-wrap">';
                                        $output .= '<label class="picky-input-label">'.esc_html__('E-mail', 'pickybooking').'</label>';
                                        $output .= '<div class="picky-input-inner">';
                                            $output .= '<div class="picky-input-control">';
                                            $output .= '<input type="text" class="picky-input" id="picky_booking_email" name="picky_booking_email" autocomplete="email" value="' . esc_attr( $picky_contact_id['email'] ) . '"' . $picky_contact_id['readonly'] . '>';
                                            $output .= '<span class="picky-input-valid-icon" aria-hidden="true"></span>';
                                            $output .= '</div>';
                                            $output .= '<div class="picky-error picky-error-empty">'.esc_html__('Please, fill the field', 'pickybooking').'</div>';
                                            $output .= '<div class="picky-error picky-error-email">'.esc_html__('Please, fill correct email', 'pickybooking').'</div>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                }

                                $output .= $form_confirm;
                                $output .= '</div>'; // .picky-order-wrap
                                $output .= '</div>'; // step 3
                                $output .= '</div></div>'; // track + viewport

                                $output .= $this->picky_wizard_footer_html( $picky_payment_required, $form_message );
                                $output .= '</div>'; // .picky-wizard
                            $output .= '</form>';

                        $output .= '</div>'; // .picky-smart-booking-overlay

    			    $output .= '</div>';

                }

                $output .= '</div>';

			    return $output;
			}
		}
	}


	function register_shortcodes() {
		// Primary shortcode.
		add_shortcode( 'pickybooking', array( $this, 'rest_booking' ) );
		// Single canonical shortcode.
	}

	function register_post_types() {
		// ── picky_layout: floor plan canvases ─────────────────────────────
		register_post_type( 'picky_layout', array(
			'labels' => array(
				'name'          => __( 'Layouts', 'pickybooking' ),
				'singular_name' => __( 'Layout', 'pickybooking' ),
				'add_new'       => __( 'Add New Layout', 'pickybooking' ),
				'add_new_item'  => __( 'Add New Layout', 'pickybooking' ),
				'edit_item'     => __( 'Edit Layout', 'pickybooking' ),
				'search_items'  => __( 'Search Layouts', 'pickybooking' ),
				'not_found'     => __( 'No Layouts found', 'pickybooking' ),
			),
			'description'        => __( 'Floor plan layouts for table reservation.', 'pickybooking' ),
			'public'             => false,
			'show_ui'            => true,
			'publicly_queryable' => false,
			'exclude_from_search'=> true,
			'show_in_menu'       => false,
			'hierarchical'       => false,
			'rewrite'            => false,
			'query_var'          => false,
			'supports'           => array( 'title' ),
			'has_archive'        => false,
		) );

		// ── picky_booking: reservations (bookings) ─────────────────────────────────
		$item_singular = $this->picky_item_label();
		$item_plural   = $this->picky_items_label();
		$args = array(
			'labels' => array(
				/* translators: %s: configurable item name plural (e.g. Tables, Rooms, Courts) */
				'name'               => sprintf( __( '%s Reservations', 'pickybooking' ), $item_plural ),
				/* translators: %s: configurable item name singular (e.g. Table, Room, Court) */
				'singular_name'      => sprintf( __( '%s Reservation', 'pickybooking' ), $item_singular ),
				/* translators: %s: configurable item name singular */
				'add_new'            => sprintf( __( 'Add %s Reservation', 'pickybooking' ), $item_singular ),
				/* translators: %s: configurable item name singular */
				'add_new_item'       => sprintf( __( 'Add New %s Reservation', 'pickybooking' ), $item_singular ),
				'edit'               => __( 'Edit', 'pickybooking' ),
				'edit_item'          => __( 'Edit Reservation', 'pickybooking' ),
				'new_item'           => __( 'New Reservation', 'pickybooking' ),
				'view'               => __( 'View Reservation', 'pickybooking' ),
				'view_item'          => __( 'View Reservation', 'pickybooking' ),
				'search_items'       => __( 'Search Reservations', 'pickybooking' ),
				'not_found'          => __( 'No Reservations found', 'pickybooking' ),
				'not_found_in_trash' => __( 'No Reservations found in trash', 'pickybooking' ),
				'parent'             => __( 'Parent Reservations', 'pickybooking' ),
			),
			/* translators: %s: configurable item name plural */
			'description' => sprintf( __( 'This is where %s reservations are stored.', 'pickybooking' ), $item_plural ),
			'public' => false,
			'show_ui' => true,
			'publicly_queryable' => false,
			'exclude_from_search' => true,
			'show_in_menu' => false,
			'hierarchical' => false,
			'show_in_nav_menus' => true,
			'rewrite' => false,
			'query_var' => true,
			'supports' => array( 'title' ),
			'has_archive' => false
		);

		register_post_type( 'picky_booking', $args );

		// ── picky_order: parent order grouping N child picky_booking posts ─────────
		register_post_type( 'picky_order', array(
			'labels' => array(
				'name'               => __( 'Orders', 'pickybooking' ),
				'singular_name'      => __( 'Order', 'pickybooking' ),
				'add_new'            => __( 'Add Order', 'pickybooking' ),
				'add_new_item'       => __( 'Add New Order', 'pickybooking' ),
				'edit_item'          => __( 'Edit Order', 'pickybooking' ),
				'search_items'       => __( 'Search Orders', 'pickybooking' ),
				'not_found'          => __( 'No Orders found', 'pickybooking' ),
				'not_found_in_trash' => __( 'No Orders found in trash', 'pickybooking' ),
			),
			'description'         => __( 'PickyBooking customer orders (groups of child bookings).', 'pickybooking' ),
			'public'              => false,
			'show_ui'             => true,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_menu'        => false,
			'hierarchical'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			'has_archive'         => false,
		) );
	}
	
	function register_post_meta(){
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
	}

	function add_meta_boxes(){
		/* translators: %s: configurable item name singular (e.g. Table, Room, Court) */
		$details_label = sprintf( __( '%s Reservation Details', 'pickybooking' ), $this->picky_item_label() );
		add_meta_box( 'picky-booking-info', $details_label, array( $this, 'render_location_info' ), 'picky_booking', 'normal', 'high' );
		add_meta_box( 'picky-order-info', __( 'Order Details', 'pickybooking' ), array( $this, 'render_order_info' ), 'picky_order', 'normal', 'high' );
		add_meta_box( 'picky-order-items', __( 'Order Items', 'pickybooking' ), array( $this, 'render_order_items' ), 'picky_order', 'normal', 'default' );
	}

	function render_location_info($post){
		$post_id = $post->ID;
		$id = get_post_meta( $post_id, '_picky_booking_id', true );
		$unique = get_post_meta( $post_id, '_picky_booking_unique', true );
		$date = get_post_meta( $post_id, '_picky_booking_date', true );
		$time = get_post_meta( $post_id, '_picky_booking_time', true );
        $time_to = get_post_meta( $post_id, '_picky_booking_time_to', true );
		$seats = get_post_meta( $post_id, '_picky_booking_seats', true );
        $people = get_post_meta( $post_id, '_picky_booking_people', true );
		$name = get_post_meta( $post_id, '_picky_booking_name', true );
		$phone = get_post_meta( $post_id, '_picky_booking_phone', true );
        $email = get_post_meta( $post_id, '_picky_booking_email', true );
		$time_all = INFO::$TIME_ALL;
		?>
		<div class="panel-wrap picky-booking-wrap">
            <table class="form-table">
            	<tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Table Number', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<input type="text" name="_picky_booking_id"  id="_picky_booking_id" value="<?php echo esc_attr( (string)$id ); ?>">
                    </td>
                </tr>
                <tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Date', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<input type="text" name="_picky_booking_date" placeholder="Example: 05/17/2018" required id="_picky_booking_date" value="<?php echo esc_attr( (string)$date ); ?>">
                    </td>
                </tr>
                <tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Time', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<select class="picky-input" id="_picky_booking_time" required name="_picky_booking_time">
                    		<?php foreach ($time_all as $key => $value) {
                    			if ( (string) $value === (string) $time ) { ?>
                    				<option selected value="<?php echo esc_attr( (string)$value ); ?>"><?php echo esc_html( (string)$value ); ?></option>
                    			<?php } else { ?>
                    				<option value="<?php echo esc_attr( (string)$value ); ?>"><?php echo esc_html( (string)$value ); ?></option>
                    			<?php }
                    		} ?>
		        		</select>
                    </td>
                </tr>

                <?php if($time_to){ ?>
                    <tr class="form-field">
                        <th>
                            <label><?php esc_html_e( 'Time to reserve to', 'pickybooking' ); ?> </label>
                        </th>
                        <td>
                            <select class="picky-input" id="_picky_booking_time_to" required name="_picky_booking_time_to">
                                <?php foreach ($time_all as $key => $value) {
                                    if ( (string) $value === (string) $time_to ) { ?>
                                        <option selected value="<?php echo esc_attr( (string)$value ); ?>"><?php echo esc_html( (string)$value ); ?></option>
                                    <?php } else { ?>
                                        <option value="<?php echo esc_attr( (string)$value ); ?>"><?php echo esc_html( (string)$value ); ?></option>
                                    <?php }
                                } ?>
                            </select>
                        </td>
                    </tr>
                <?php } ?>

                <tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Table Seats', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<input type="text" name="_picky_booking_seats"  id="_picky_booking_seats" value="<?php echo esc_attr( (string)$seats ); ?>">
                    </td>
                </tr>
                <?php if($people){ ?>
                 <tr class="form-field">
                    <th>
                        <label><?php esc_html_e( 'People Amount', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                        <input type="text" name="_picky_booking_people"  id="_picky_booking_people" value="<?php echo esc_attr( (string)$people ); ?>">
                    </td>
                </tr>
                <?php } ?>
				<tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Name', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<input type="text" name="_picky_booking_name" required id="_picky_booking_name" value="<?php echo esc_attr( (string)$name ); ?>">
                    </td>
                </tr>
                <tr class="form-field">
                    <th>
                    	<label><?php esc_html_e( 'Phone Number', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                    	<input type="text" name="_picky_booking_phone" id="_picky_booking_phone" value="<?php echo esc_attr( (string)$phone ); ?>">
                    </td>
                </tr>
                <tr class="form-field">
                    <th>
                        <label><?php esc_html_e( 'E-mail', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                        <input type="text" name="_picky_booking_email"  id="_picky_booking_email" value="<?php echo esc_attr( (string)$email ); ?>">
                    </td>
                </tr>
                <?php if ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'payments' ) ) : ?>
                <tr class="form-field">
                    <th>
                        <label><?php esc_html_e( 'Payment', 'pickybooking' ); ?> </label>
                    </th>
                    <td>
                        <?php
                        $payment_status = sanitize_key( (string) get_post_meta( $post_id, '_picky_payment_status', true ) );
                        $payment_provider = sanitize_key( (string) get_post_meta( $post_id, '_picky_payment_provider', true ) );
                        $payment_amount = (float) get_post_meta( $post_id, '_picky_payment_amount', true );
                        $payment_currency = sanitize_text_field( (string) get_post_meta( $post_id, '_picky_payment_currency', true ) );
                        if ( $payment_currency === '' ) {
                            $payment_currency = 'USD';
                        }
                        $payment_amount_text = number_format_i18n( $payment_amount, 2 ) . ' ' . $payment_currency;
                        $payment_link_html = '—';
                        $woo_order_id = (int) get_post_meta( $post_id, '_picky_wc_order_id', true );
                        $stripe_session_id = (string) get_post_meta( $post_id, '_picky_stripe_session_id', true );
                        if ( $woo_order_id > 0 ) {
                            $payment_link_html = '<a href="' . esc_url( admin_url( 'post.php?post=' . $woo_order_id . '&action=edit' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Woo order', 'pickybooking' ) . '</a>';
                        } elseif ( $stripe_session_id !== '' ) {
                            $payment_link_html = esc_html__( 'Online payment reference on file', 'pickybooking' );
                        }

                        if ( $payment_status === '' || $payment_status === 'not_required' || $payment_provider === 'none' ) {
                            echo esc_html__( 'No payment required', 'pickybooking' );
                        } elseif ( $payment_status === 'paid' ) {
                            echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#16a34a;color:#fff;font-weight:600;">&#10003; ' . esc_html__( 'Payment Succeeded', 'pickybooking' ) . '</span><br>';
                            echo '<small>' . esc_html( $payment_amount_text ) . '</small><br>';
                            echo wp_kses(
                                $payment_link_html,
                                array(
                                    'a' => array(
                                        'href'   => true,
                                        'target' => true,
                                        'rel'    => true,
                                    ),
                                )
                            );
                        } elseif ( $payment_status === 'pending' ) {
                            echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#f59e0b;color:#111;font-weight:600;">' . esc_html__( 'Pending payment', 'pickybooking' ) . '</span><br>';
                            echo '<small>' . esc_html( $payment_amount_text ) . '</small>';
                        } else {
                            echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#dc2626;color:#fff;font-weight:600;">' . esc_html__( 'Payment failed', 'pickybooking' ) . '</span><br>';
                            echo '<small>' . esc_html( $payment_amount_text ) . '</small>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'integrations' ) ) : ?>
                <tr class="form-field picky-hubspot-status">
                    <th>
                        <label><?php esc_html_e( 'HubSpot', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $hubspot_on = isset( $this->settings['hubspot_enabled'] ) && (string) $this->settings['hubspot_enabled'] === '1';
                        $hubspot_deals = isset( $this->settings['hubspot_sync_deals'] ) && (string) $this->settings['hubspot_sync_deals'] === '1';
                        $hs_contact = (string) get_post_meta( $post_id, '_picky_hubspot_contact_id', true );
                        $hs_deal    = (string) get_post_meta( $post_id, '_picky_hubspot_deal_id', true );
                        $hs_err     = (string) get_post_meta( $post_id, '_picky_hubspot_sync_error', true );
                        if ( ! $hubspot_on ) {
                            echo '<p class="description">' . esc_html__( 'HubSpot sync is turned off in plugin settings (Payments / HubSpot tab).', 'pickybooking' ) . '</p>';
                        } else {
                            echo '<p><strong>' . esc_html__( 'Contact record ID', 'pickybooking' ) . '</strong> — ';
                            echo $hs_contact !== '' ? '<code>' . esc_html( $hs_contact ) . '</code>' : '—';
                            echo '</p>';
                            if ( $hubspot_deals ) {
                                echo '<p><strong>' . esc_html__( 'Deal record ID', 'pickybooking' ) . '</strong> — ';
                                echo $hs_deal !== '' ? '<code>' . esc_html( $hs_deal ) . '</code>' : '—';
                                echo '</p>';
                            }
                            if ( $hs_err !== '' ) {
                                echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:8px 0 0;">' . esc_html( $hs_err ) . '</p>';
                            } elseif ( $hubspot_deals && $hs_deal !== '' ) {
                                echo '<p class="description">' . esc_html__( 'Deal should appear in HubSpot under Deals (try “All pipelines” or search for “Reservation”).', 'pickybooking' ) . '</p>';
                            } elseif ( $hubspot_deals && $hs_deal === '' && $hs_contact !== '' ) {
                                echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'No deal was synced for this booking. Check the HubSpot error above if shown; otherwise verify deals scopes, pipeline settings, and submit a new test booking.', 'pickybooking' ) . '</p>';
                            } else {
                                echo '<p class="description">' . esc_html__( 'In HubSpot CRM, search by this contact’s email or phone. For deals, search “Reservation” or filter by All pipelines.', 'pickybooking' ) . '</p>';
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr class="form-field picky-mailchimp-status">
                    <th>
                        <label><?php esc_html_e( 'Mailchimp', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $mc_on = isset( $this->settings['mailchimp_enabled'] ) && (string) $this->settings['mailchimp_enabled'] === '1';
                        $mc_status = (string) get_post_meta( $post_id, '_picky_mailchimp_status', true );
                        $mc_err    = (string) get_post_meta( $post_id, '_picky_mailchimp_sync_error', true );
                        
                        if ( ! $mc_on ) {
                            echo '<p class="description">' . esc_html__( 'Mailchimp sync is turned off in plugin settings (Payments / Mailchimp tab).', 'pickybooking' ) . '</p>';
                        } else {
                            if ( $mc_status === 'synced' ) {
                                echo '<p class="description" style="color:#16a34a;font-weight:bold;">' . esc_html__( 'Successfully synced to Mailchimp Audience.', 'pickybooking' ) . '</p>';
                            }
                            if ( $mc_err !== '' ) {
                                echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:8px 0 0;">' . esc_html( $mc_err ) . '</p>';
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr class="form-field picky-n8n-status">
                    <th>
                        <label><?php esc_html_e( 'Automation (n8n)', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $n8n_on = isset( $this->settings['n8n_webhook_enabled'] ) && (string) $this->settings['n8n_webhook_enabled'] === '1';
                        $n8n_err = (string) get_post_meta( $post_id, '_picky_n8n_webhook_error', true );
                        $n8n_sent = (string) get_post_meta( $post_id, '_picky_n8n_webhook_sent_at', true );
                        if ( ! $n8n_on ) {
                            echo '<p class="description">' . esc_html__( 'Booking webhook is off in plugin settings (Automation webhook / n8n section).', 'pickybooking' ) . '</p>';
                        } elseif ( $n8n_err !== '' ) {
                            echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:0;">' . esc_html( $n8n_err ) . '</p>';
                        } elseif ( $n8n_sent !== '' && ctype_digit( $n8n_sent ) ) {
                            echo '<p class="description">' . esc_html(
                                sprintf(
                                    /* translators: %s: localized date/time */
                                    __( 'Webhook reported success (Unix time %s). Check your n8n Executions tab.', 'pickybooking' ),
                                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $n8n_sent )
                                )
                            ) . '</p>';
                        } else {
                            echo '<p class="description">' . esc_html__( 'No webhook delivery recorded for this booking yet (e.g. created before webhook was enabled).', 'pickybooking' ) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
                <tr class="form-field picky-zapier-status">
                    <th>
                        <label><?php esc_html_e( 'Zapier', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $zap_on   = isset( $this->settings['zapier_webhook_enabled'] ) && (string) $this->settings['zapier_webhook_enabled'] === '1';
                        $zap_err  = (string) get_post_meta( $post_id, '_picky_zapier_webhook_error', true );
                        $zap_sent = (string) get_post_meta( $post_id, '_picky_zapier_webhook_sent_at', true );
                        if ( ! $zap_on ) {
                            echo '<p class="description">' . esc_html__( 'Zapier webhook is off in plugin settings (Payments tab → Zapier).', 'pickybooking' ) . '</p>';
                        } elseif ( $zap_err !== '' ) {
                            echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:0;">' . esc_html( $zap_err ) . '</p>';
                        } elseif ( $zap_sent !== '' && ctype_digit( $zap_sent ) ) {
                            echo '<p class="description">' . esc_html(
                                sprintf(
                                    /* translators: %s: localized date/time */
                                    __( 'Zapier webhook reported success (%s). Check your Zap history in Zapier.', 'pickybooking' ),
                                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $zap_sent )
                                )
                            ) . '</p>';
                        } else {
                            echo '<p class="description">' . esc_html__( 'No Zapier delivery recorded for this booking yet (e.g. created before the Zap was enabled).', 'pickybooking' ) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
                <tr class="form-field picky-make-status">
                    <th>
                        <label><?php esc_html_e( 'Make.com', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $make_on   = isset( $this->settings['make_webhook_enabled'] ) && (string) $this->settings['make_webhook_enabled'] === '1';
                        $make_err  = (string) get_post_meta( $post_id, '_picky_make_webhook_error', true );
                        $make_sent = (string) get_post_meta( $post_id, '_picky_make_webhook_sent_at', true );
                        if ( ! $make_on ) {
                            echo '<p class="description">' . esc_html__( 'Make.com webhook is off in plugin settings (Payments tab → Make.com).', 'pickybooking' ) . '</p>';
                        } elseif ( $make_err !== '' ) {
                            echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:0;">' . esc_html( $make_err ) . '</p>';
                        } elseif ( $make_sent !== '' && ctype_digit( $make_sent ) ) {
                            echo '<p class="description">' . esc_html(
                                sprintf(
                                    /* translators: %s: localized date/time */
                                    __( 'Make.com webhook reported success (%s). Check scenario history in Make.', 'pickybooking' ),
                                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $make_sent )
                                )
                            ) . '</p>';
                        } else {
                            echo '<p class="description">' . esc_html__( 'No Make.com delivery recorded for this booking yet (e.g. created before the scenario was enabled).', 'pickybooking' ) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
                <tr class="form-field picky-gcal-status">
                    <th>
                        <label><?php esc_html_e( 'Google Calendar', 'pickybooking' ); ?></label>
                    </th>
                    <td>
                        <?php
                        $gcal_on   = isset( $this->settings['gcal_enabled'] ) && (string) $this->settings['gcal_enabled'] === '1';
                        $gcal_err  = (string) get_post_meta( $post_id, '_picky_gcal_sync_error', true );
                        $gcal_synced_at = (string) get_post_meta( $post_id, '_picky_gcal_synced_at', true );
                        $gcal_event_id = (string) get_post_meta( $post_id, '_picky_gcal_event_id', true );
                        $gcal_status = (string) get_post_meta( $post_id, '_picky_gcal_sync_status', true );

                        if ( ! $gcal_on ) {
                            echo '<p class="description">' . esc_html__( 'Google Calendar sync is turned off in plugin settings (Integrations tab → Google Calendar).', 'pickybooking' ) . '</p>';
                        } elseif ( $gcal_err !== '' ) {
                            echo '<p class="notice notice-error inline" style="padding:8px 12px;margin:0;">' . esc_html( $gcal_err ) . '</p>';
                        } elseif ( $gcal_status === 'synced' && $gcal_event_id !== '' ) {
                            $calendar_id = isset( $this->settings['gcal_calendar_id'] ) ? sanitize_text_field( (string) $this->settings['gcal_calendar_id'] ) : 'primary';
                            $event_url = 'https://calendar.google.com/calendar/event?eid=' . rawurlencode( base64_encode( $gcal_event_id . ' ' . $calendar_id ) );
                            echo '<p style="color:#16a34a;font-weight:bold;">' . esc_html__( '✓ Synced to Google Calendar', 'pickybooking' ) . '</p>';
                            if ( $gcal_synced_at !== '' && ctype_digit( $gcal_synced_at ) ) {
                                echo '<p class="description">' . esc_html(
                                    sprintf(
                                        /* translators: %s: localized date/time */
                                        __( 'Synced at %s', 'pickybooking' ),
                                        wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $gcal_synced_at )
                                    )
                                ) . '</p>';
                            }
                            echo '<p><a href="' . esc_url( $event_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Google Calendar', 'pickybooking' ) . '</a></p>';
                        } else {
                            echo '<p class="description">' . esc_html__( 'Not synced to Google Calendar yet.', 'pickybooking' ) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endif; ?>

                <input type="hidden" name="_picky_booking_unique" id="_picky_booking_unique" value="<?php echo esc_attr( (string)$unique ); ?>">
                    
            </table>
        </div>
		<?php
	}

	function render_order_info( $post ) {
		$post_id  = $post->ID;
		$name     = (string) get_post_meta( $post_id, '_picky_order_customer_name', true );
		$email    = (string) get_post_meta( $post_id, '_picky_order_email', true );
		$phone    = (string) get_post_meta( $post_id, '_picky_order_phone', true );
		$date     = (string) get_post_meta( $post_id, '_picky_order_date', true );
		$time     = (string) get_post_meta( $post_id, '_picky_order_time', true );
		$currency = (string) get_post_meta( $post_id, '_picky_order_currency', true );
		$total    = (float)  get_post_meta( $post_id, '_picky_order_total', true );
		$count    = (int)    get_post_meta( $post_id, '_picky_order_item_count', true );
		$layout_id = (int)  get_post_meta( $post_id, '_picky_order_layout_id', true );
		$pay_status   = (string) get_post_meta( $post_id, '_picky_order_payment_status', true );
		$pay_provider = (string) get_post_meta( $post_id, '_picky_order_payment_provider', true );
		?>
		<div class="panel-wrap picky-booking-wrap">
			<table class="form-table">
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Customer Name', 'pickybooking' ); ?></label></th>
					<td><input type="text" name="_picky_order_customer_name" value="<?php echo esc_attr( $name ); ?>"></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Email', 'pickybooking' ); ?></label></th>
					<td><input type="email" name="_picky_order_email" value="<?php echo esc_attr( $email ); ?>"></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Phone', 'pickybooking' ); ?></label></th>
					<td><input type="text" name="_picky_order_phone" value="<?php echo esc_attr( $phone ); ?>"></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Date', 'pickybooking' ); ?></label></th>
					<td><?php echo esc_html( $date ); ?></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Time', 'pickybooking' ); ?></label></th>
					<td><?php echo esc_html( $time ); ?></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Items', 'pickybooking' ); ?></label></th>
					<td><?php echo esc_html( (string) $count ); ?></td>
				</tr>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Order Total', 'pickybooking' ); ?></label></th>
					<td><strong><?php echo esc_html( number_format( $total, 2 ) . ' ' . $currency ); ?></strong></td>
				</tr>
				<?php if ( $pay_status !== '' && $pay_status !== 'not_required' ) : ?>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Payment', 'pickybooking' ); ?></label></th>
					<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $pay_status ) ) . ' via ' . $pay_provider ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $layout_id > 0 ) : ?>
				<tr class="form-field">
					<th><label><?php esc_html_e( 'Layout', 'pickybooking' ); ?></label></th>
					<td><?php echo esc_html( (string) get_the_title( $layout_id ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php
			$debug_json = (string) get_post_meta( $post_id, '_picky_order_debug', true );
			if ( $debug_json !== '' && current_user_can( 'manage_options' ) ) :
				$pretty = $debug_json;
				$decoded = json_decode( $debug_json, true );
				if ( is_array( $decoded ) ) {
					$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				}
			?>
			<details style="margin-top:14px;border:1px solid #ddd;padding:6px 10px;background:#fafafa;">
				<summary style="cursor:pointer;font-weight:600;color:#a00;"><?php esc_html_e( 'Debug snapshot (price/duration was 0)', 'pickybooking' ); ?></summary>
				<p style="font-size:12px;color:#555;margin:4px 0 6px;"><?php esc_html_e( 'Generated automatically when order total or any item resolved to 0. Helps diagnose service-library / layout mismatches.', 'pickybooking' ); ?></p>
				<pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;background:#fff;padding:8px;max-height:280px;overflow:auto;border:1px solid #eee;"><?php echo esc_html( $pretty ); ?></pre>
			</details>
			<?php endif; ?>
		</div>
		<?php
	}

	function render_order_items( $post ) {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Public availability queries require meta_query
		$order_id = $post->ID;
		$children = get_posts( array(
			'post_type'   => 'picky_booking',
			'post_status' => array( 'picky_reserved', 'picky_requests', 'picky_canceled', 'reserved', 'requests', 'canceled', 'any' ),
			'numberposts' => -1,
			'meta_query'  => array(
				array( 'key' => '_picky_booking_order_id', 'value' => $order_id, 'compare' => '=' ),
			),
		) );

		// Self-healing reconciliation: when payment was confirmed against the
		// anchor child only (legacy single-item Stripe handler bug), the parent
		// order + siblings can stay in 'requests'. If any child shows _picky_payment_status=paid
		// we cascade to all siblings and the parent order so the admin sees
		// consistent state on the very next page render. Idempotent.
		if ( ! empty( $children ) ) {
			$has_paid_child = false;
			foreach ( $children as $c ) {
				if ( (string) get_post_meta( $c->ID, '_picky_payment_status', true ) === 'paid' ) {
					$has_paid_child = true;
					break;
				}
			}
			$order_pay = (string) get_post_meta( $order_id, '_picky_order_payment_status', true );
			$order_st  = (string) get_post_status( $order_id );
			if ( $has_paid_child ) {
				$reconciled = false;
				foreach ( $children as $c ) {
					$cur = (string) get_post_meta( $c->ID, '_picky_payment_status', true );
					if ( $cur !== 'paid' ) {
						update_post_meta( $c->ID, '_picky_payment_status', 'paid' );
						$reconciled = true;
					}
					if ( (string) get_post_status( $c->ID ) !== 'reserved' ) {
						wp_update_post( array( 'ID' => $c->ID, 'post_status' => 'picky_reserved' ) );
						$reconciled = true;
					}
				}
				if ( $order_pay !== 'paid' ) {
					update_post_meta( $order_id, '_picky_order_payment_status', 'paid' );
					$reconciled = true;
				}
				if ( $order_st !== 'reserved' ) {
					wp_update_post( array( 'ID' => $order_id, 'post_status' => 'picky_reserved' ) );
					$reconciled = true;
				}
				if ( $reconciled ) {
					// Re-fetch children so the table below shows the just-fixed statuses.
					$children = get_posts( array(
						'post_type'   => 'picky_booking',
						'post_status' => array( 'picky_reserved', 'picky_requests', 'picky_canceled', 'reserved', 'requests', 'canceled', 'any' ),
						'numberposts' => -1,
						'meta_query'  => array(
							array( 'key' => '_picky_booking_order_id', 'value' => $order_id, 'compare' => '=' ),
						),
					) );
					echo '<div class="notice notice-success inline" style="margin:0 0 8px;"><p>'
						. esc_html__( 'Order status reconciled: payment was already confirmed; all items have been marked Reserved.', 'pickybooking' )
						. '</p></div>';
				}
			}
		}

		// Resolve currency: order meta first, fall back to plugin setting.
		$order_currency = (string) get_post_meta( $order_id, '_picky_order_currency', true );
		if ( $order_currency === '' ) {
			$order_options  = Info::get_settings();
			$order_currency = isset( $order_options['deposit_currency'] ) ? strtoupper( (string) $order_options['deposit_currency'] ) : 'USD';
		}

		// Format a duration in minutes as "Nh Mmin" or "Mmin"
		$format_duration = static function ( $minutes ) {
			$minutes = max( 0, (int) $minutes );
			if ( $minutes === 0 ) {
				return '—';
			}
			if ( $minutes < 60 ) {
				/* translators: %d: duration in minutes */
				return sprintf( esc_html__( '%d min', 'pickybooking' ), $minutes );
			}
			$h = intdiv( $minutes, 60 );
			$m = $minutes % 60;
			if ( $m === 0 ) {
				/* translators: %d: hours */
				return sprintf( _n( '%d h', '%d h', $h, 'pickybooking' ), $h );
			}
			/* translators: 1: hours, 2: remaining minutes */
			return sprintf( esc_html__( '%1$d h %2$d min', 'pickybooking' ), $h, $m );
		};

		$format_money = static function ( $amount, $currency ) {
			return number_format_i18n( max( 0.0, (float) $amount ), 2 ) . ' ' . $currency;
		};

		// Pre-load order layout's services library to enrich snapshots that may
		// be stored with only sid (older test data, hand-edited rows, etc.).
		$layout_id_for_lookup = (int) get_post_meta( $order_id, '_picky_order_layout_id', true );
		$library_lookup       = array();
		if ( $layout_id_for_lookup > 0 ) {
			$lib_raw = get_post_meta( $layout_id_for_lookup, '_picky_layout_services_library', true );
			if ( is_string( $lib_raw ) && $lib_raw !== '' ) {
				$lib_decoded = json_decode( $lib_raw, true );
				if ( is_array( $lib_decoded ) ) {
					foreach ( $lib_decoded as $lsvc ) {
						if ( is_array( $lsvc ) && ! empty( $lsvc['sid'] ) ) {
							$library_lookup[ (string) $lsvc['sid'] ] = $lsvc;
						}
					}
				}
			}
		}

		$grand_subtotal = 0.0;
		$grand_duration = 0;
		?>
		<table class="widefat striped picky-order-items-table" style="margin-top:6px;">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Item', 'pickybooking' ); ?></th>
					<th><?php esc_html_e( 'Services', 'pickybooking' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Time', 'pickybooking' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Duration', 'pickybooking' ); ?></th>
					<th style="width:110px;text-align:right;"><?php esc_html_e( 'Price', 'pickybooking' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Status', 'pickybooking' ); ?></th>
					<th style="width:60px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $children ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No items.', 'pickybooking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $children as $child ) :
					$item_label    = (string) get_post_meta( $child->ID, '_picky_booking_id', true );
					$services_json = get_post_meta( $child->ID, '_picky_booking_services', true );
					$services      = array();
					if ( is_string( $services_json ) && $services_json !== '' ) {
						$decoded = json_decode( $services_json, true );
						if ( is_array( $decoded ) ) {
							$services = $decoded;
						}
					}
					// Legacy fallback: synthesize a single-service array from the
					// flat per-booking fields used before _picky_booking_services
					// existed as JSON.
					if ( empty( $services ) ) {
						$legacy_name = (string) get_post_meta( $child->ID, '_picky_booking_service_name', true );
						if ( $legacy_name !== '' ) {
							$services = array(
								array(
									'sid'      => (string) get_post_meta( $child->ID, '_picky_booking_service_id', true ),
									'name'     => $legacy_name,
									'price'    => (float) get_post_meta( $child->ID, '_picky_booking_service_price', true ),
									'duration' => (int) get_post_meta( $child->ID, '_picky_booking_service_duration', true ),
								),
							);
						}
					}

					// Enrich snapshots from the library when name/price/duration look empty
					// but a sid is present (covers manually-edited or partial JSON).
					// Also copy `parent_sids` from the library when the snapshot was
					// recorded before hierarchy support — required for hierarchical render.
					foreach ( $services as &$svc_ref ) {
						if ( ! is_array( $svc_ref ) ) { continue; }
						$svc_sid = isset( $svc_ref['sid'] ) ? (string) $svc_ref['sid'] : '';
						if ( $svc_sid !== '' && isset( $library_lookup[ $svc_sid ] ) ) {
							$lib_match = $library_lookup[ $svc_sid ];
							if ( empty( $svc_ref['name'] )     && ! empty( $lib_match['name'] ) )     { $svc_ref['name']     = $lib_match['name']; }
							if ( empty( $svc_ref['price'] )    && isset( $lib_match['price'] ) )      { $svc_ref['price']    = $lib_match['price']; }
							if ( empty( $svc_ref['duration'] ) && isset( $lib_match['duration'] ) )   { $svc_ref['duration'] = $lib_match['duration']; }
							if ( empty( $svc_ref['description'] ) && ! empty( $lib_match['description'] ) ) { $svc_ref['description'] = $lib_match['description']; }
							if ( ( ! isset( $svc_ref['parent_sids'] ) || ! is_array( $svc_ref['parent_sids'] ) ) && isset( $lib_match['parent_sids'] ) && is_array( $lib_match['parent_sids'] ) ) {
								$svc_ref['parent_sids'] = $lib_match['parent_sids'];
							}
						}
					}
					unset( $svc_ref );

					$svc_subtotal = 0.0;
					$svc_duration = 0;
					foreach ( $services as $svc ) {
						if ( ! is_array( $svc ) ) { continue; }
						$svc_subtotal += isset( $svc['price'] )    ? (float) $svc['price']    : 0.0;
						$svc_duration += isset( $svc['duration'] ) ? (int)   $svc['duration'] : 0;
					}
					$grand_subtotal += $svc_subtotal;
					$grand_duration += $svc_duration;

					$b_time    = (string) get_post_meta( $child->ID, '_picky_booking_time', true );
					$b_time_to = (string) get_post_meta( $child->ID, '_picky_booking_time_to', true );
					$edit_url  = get_edit_post_link( $child->ID );
					$status    = (string) get_post_status( $child->ID );
					$status_cls = 'picky-status-' . sanitize_html_class( $status );
				?>
				<tr>
					<td><strong><?php echo esc_html( '#' . $item_label ); ?></strong></td>
					<td>
					<?php if ( empty( $services ) ) : ?>
						<span style="color:#888;">—</span>
					<?php else :
						$parents_list = array();
						$addons_list  = array();
						$parent_sid_set = array();
						foreach ( $services as $svc ) {
							if ( ! is_array( $svc ) ) { continue; }
							$psids = isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ? $svc['parent_sids'] : array();
							if ( empty( $psids ) ) {
								$parents_list[] = $svc;
								if ( ! empty( $svc['sid'] ) ) { $parent_sid_set[ (string) $svc['sid'] ] = true; }
							} else {
								$addons_list[] = $svc;
							}
						}
						$render_svc_line = static function ( $svc, $is_addon ) use ( $format_duration, $format_money, $order_currency ) {
							if ( ! is_array( $svc ) ) { return; }
							$svc_name = isset( $svc['name'] )        ? (string) $svc['name']        : '';
							$svc_price= isset( $svc['price'] )       ? (float)  $svc['price']       : 0.0;
							$svc_dur  = isset( $svc['duration'] )    ? (int)    $svc['duration']    : 0;
							$svc_desc = isset( $svc['description'] ) ? (string) $svc['description'] : '';
							if ( $svc_name === '' && $svc_price <= 0 && $svc_dur <= 0 ) { return; }
							$style = 'padding:2px 0;line-height:1.4;' . ( $svc_desc !== '' ? 'cursor:help;' : '' ) . ( $is_addon ? 'padding-left:18px;' : '' );
							echo '<li class="' . ( $is_addon ? 'picky-svc-row-addon' : 'picky-svc-row-parent' ) . '" style="' . esc_attr( $style ) . '"' . ( $svc_desc !== '' ? ' title="' . esc_attr( $svc_desc ) . '"' : '' ) . '>';
							if ( $is_addon ) {
								echo '<span class="picky-svc-row-addon-prefix" style="color:#888;margin-right:4px;">&#8627;</span>';
								echo '<span class="picky-svc-row-addon-badge" style="display:inline-block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:1px 6px;border:1px solid #c3c4c7;border-radius:10px;color:#555;margin-right:6px;">' . esc_html__( 'Add-on', 'pickybooking' ) . '</span>';
							}
							echo '<span style="font-weight:500;">' . esc_html( $svc_name !== '' ? $svc_name : '—' ) . '</span>';
							echo '<span style="color:#666;font-size:12px;">';
							echo esc_html( ' · ' . call_user_func( $format_duration, $svc_dur ) );
							echo esc_html( ' · ' . call_user_func( $format_money, $svc_price, $order_currency ) );
							echo '</span></li>';
						};
					?>
						<ul class="picky-order-services-list" style="margin:0;padding:0;list-style:none;">
						<?php
						$rendered_addon_sids = array();
						foreach ( $parents_list as $parent_svc ) {
							call_user_func( $render_svc_line, $parent_svc, false );
							$parent_sid = isset( $parent_svc['sid'] ) ? (string) $parent_svc['sid'] : '';
							if ( $parent_sid === '' ) { continue; }
							foreach ( $addons_list as $addon_svc ) {
								$psids = isset( $addon_svc['parent_sids'] ) && is_array( $addon_svc['parent_sids'] ) ? $addon_svc['parent_sids'] : array();
								if ( in_array( $parent_sid, $psids, true ) ) {
									call_user_func( $render_svc_line, $addon_svc, true );
									$asid = isset( $addon_svc['sid'] ) ? (string) $addon_svc['sid'] : '';
									if ( $asid !== '' ) { $rendered_addon_sids[ $asid ] = true; }
								}
							}
						}
						// Stranded add-ons whose parents never made it into this booking — defensive fallback.
						$stranded = array();
						foreach ( $addons_list as $addon_svc ) {
							$asid = isset( $addon_svc['sid'] ) ? (string) $addon_svc['sid'] : '';
							if ( $asid !== '' && isset( $rendered_addon_sids[ $asid ] ) ) { continue; }
							$psids = isset( $addon_svc['parent_sids'] ) && is_array( $addon_svc['parent_sids'] ) ? $addon_svc['parent_sids'] : array();
							$has_parent_in_booking = false;
							foreach ( $psids as $p ) { if ( isset( $parent_sid_set[ (string) $p ] ) ) { $has_parent_in_booking = true; break; } }
							if ( ! $has_parent_in_booking ) { $stranded[] = $addon_svc; }
						}
						if ( ! empty( $stranded ) ) {
							echo '<li class="picky-svc-row-standalone-note" style="padding:4px 0 2px;margin-top:4px;border-top:1px dashed #dcdcde;color:#a00;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">' . esc_html__( 'Standalone add-ons', 'pickybooking' ) . '</li>';
							foreach ( $stranded as $addon_svc ) {
								call_user_func( $render_svc_line, $addon_svc, true );
							}
						}
						?>
						</ul>
					<?php endif; ?>
					</td>
					<td><?php echo esc_html( $b_time . ( $b_time_to !== '' ? ' – ' . $b_time_to : '' ) ); ?></td>
					<td><?php echo esc_html( call_user_func( $format_duration, $svc_duration ) ); ?></td>
					<td style="text-align:right;"><strong><?php echo esc_html( call_user_func( $format_money, $svc_subtotal, $order_currency ) ); ?></strong></td>
					<td><span class="<?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
					<td><?php if ( $edit_url ) : ?><a href="<?php echo esc_url( (string) $edit_url ); ?>"><?php esc_html_e( 'Edit', 'pickybooking' ); ?></a><?php endif; ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
			<?php if ( ! empty( $children ) ) : ?>
			<tfoot>
				<tr>
					<th colspan="3" style="text-align:right;"><?php esc_html_e( 'Order total', 'pickybooking' ); ?></th>
					<th><?php echo esc_html( call_user_func( $format_duration, $grand_duration ) ); ?></th>
					<th style="text-align:right;"><strong><?php echo esc_html( call_user_func( $format_money, $grand_subtotal, $order_currency ) ); ?></strong></th>
					<th colspan="2"></th>
				</tr>
			</tfoot>
			<?php endif; ?>
		</table>
		<?php
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	function save_metadata($post_id){
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- POST values sanitized per-field in loop
		$post_id = absint( $post_id );
		if ( ! $post_id || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Either the embedded editor nonce or the classic meta-box nonce must verify. An empty value fails wp_verify_nonce.
		$booking_ok = isset( $_POST['picky_booking_nonce'] ) && is_scalar( $_POST['picky_booking_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_booking_nonce'] ) ), 'picky_save_booking_' . $post_id );
		$meta_ok    = isset( $_POST['_picky_booking_meta_nonce'] ) && is_scalar( $_POST['_picky_booking_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_picky_booking_meta_nonce'] ) ), 'picky_booking_meta_' . $post_id );
		if ( ! $booking_ok && ! $meta_ok ) {
			return;
		}

		$fields = array(
			'_picky_booking_id',
			'_picky_booking_unique',
			'_picky_booking_date',
			'_picky_booking_time',
            '_picky_booking_time_to',
			'_picky_booking_seats',
            '_picky_booking_people',
			'_picky_booking_name',
			'_picky_booking_phone',
            '_picky_booking_email',
		);

		foreach ( $fields as $key => $value ) {
			if ( isset( $_POST[ $value ] ) && is_scalar( $_POST[ $value ] ) ) {
				$meta = get_post_meta( $post_id, $value, true );
				$raw  = sanitize_text_field( wp_unslash( (string) $_POST[ $value ] ) );
				if ( '_picky_booking_email' === $value ) {
					$new_value = sanitize_email( (string)$raw );
				} elseif ( in_array( $value, array( '_picky_booking_id', '_picky_booking_seats', '_picky_booking_people' ), true ) ) {
					$trim = trim( $raw );
					// Match prior sanitize_text_field behavior for empty fields; absint only when a value is present.
					$new_value = ( '' === $trim ) ? '' : (string) absint( $trim );
				} else {
					$new_value = sanitize_text_field( (string)$raw );
				}
				if ( '' === (string) $meta && '' === (string) $new_value ) {
					update_post_meta( $post_id, $value, '' );
				} elseif ( '' !== (string) $meta && '' !== (string) $new_value ) {
					update_post_meta( $post_id, $value, $new_value );
				} elseif ( '' === (string) $meta && '' !== (string) $new_value ) {
					update_post_meta( $post_id, $value, $new_value );
				} elseif ( '' !== (string) $meta && '' === (string) $new_value ) {
					update_post_meta( $post_id, $value, '' );
				}
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	function save_order_metadata( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['picky_order_nonce'] ) || ! is_scalar( $_POST['picky_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_order_nonce'] ) ), 'picky_save_order_' . $post_id ) ) {
			return;
		}

		$text_fields = array( '_picky_order_customer_name', '_picky_order_phone' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) );
			}
		}
		if ( isset( $_POST['_picky_order_email'] ) && is_scalar( $_POST['_picky_order_email'] ) ) {
			update_post_meta( $post_id, '_picky_order_email', sanitize_email( wp_unslash( (string) $_POST['_picky_order_email'] ) ) );
		}
	}

    function picky_custom_post_status(){
        register_post_status( 'picky_requests', array(
          'label'                     => __( 'Requested', 'pickybooking' ),
          'public'                    => true,
          'show_in_admin_all_list'    => true,
          'show_in_admin_status_list' => true,
          /* translators: Placeholders are dynamic values. */
          'label_count'               => _n_noop( 'Requested <span class="count">(%s)</span>', 'Requested <span class="count">(%s)</span>', 'pickybooking' )
        ) );

        register_post_status( 'picky_reserved', array(
          'label'                     => __( 'Reserved', 'pickybooking' ),
          'public'                    => true,
          'show_in_admin_all_list'    => true,
          'show_in_admin_status_list' => true,
          /* translators: Placeholders are dynamic values. */
          'label_count'               => _n_noop( 'Reserved <span class="count">(%s)</span>', 'Reserved <span class="count">(%s)</span>', 'pickybooking' )
        ) );

        register_post_status( 'picky_canceled', array(
          'label'                     => __( 'Canceled', 'pickybooking' ),
          'public'                    => true,
          'show_in_admin_all_list'    => true,
          'show_in_admin_status_list' => true,
          /* translators: Placeholders are dynamic values. */
          'label_count'               => _n_noop( 'Canceled <span class="count">(%s)</span>', 'Canceled <span class="count">(%s)</span>', 'pickybooking' )
        ) );
        if ( function_exists( 'picky_migrate_booking_post_statuses' ) ) {
            picky_migrate_booking_post_statuses();
        }
    }

    function true_append_post_status_list(){
        global $post;
        if ( ! is_object( $post ) || 'picky_booking' !== $post->post_type ) {
            return;
        }

        $optionselected_requests = '';
        $optionselected_reserved = '';
        $optionselected_canceled = '';

        if ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'requests' ) ) {
            $optionselected_requests = ' selected="selected"';
        } elseif ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'reserved' ) ) {
            $optionselected_reserved = ' selected="selected"';
        } elseif ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'canceled' ) ) {
            $optionselected_canceled = ' selected="selected"';
        }

        $options  = '<option value="picky_requests"' . $optionselected_requests . '>' . esc_html__( 'Requested', 'pickybooking' ) . '</option>';
        $options .= '<option value="picky_reserved"' . $optionselected_reserved . '>' . esc_html__( 'Reserved', 'pickybooking' ) . '</option>';
        $options .= '<option value="picky_canceled"' . $optionselected_canceled . '>' . esc_html__( 'Canceled', 'pickybooking' ) . '</option>';

        $status_line = '';
        if ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'requests' ) ) {
            $status_line = sprintf( "$( '#post-status-display' ).text( %s );", wp_json_encode( __( 'Requested', 'pickybooking' ) ) );
        } elseif ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'reserved' ) ) {
            $status_line = sprintf( "$( '#post-status-display' ).text( %s );", wp_json_encode( __( 'Reserved', 'pickybooking' ) ) );
        } elseif ( function_exists( 'picky_status_is' ) && picky_status_is( $post->post_status, 'canceled' ) ) {
            $status_line = sprintf( "$( '#post-status-display' ).text( %s );", wp_json_encode( __( 'Canceled', 'pickybooking' ) ) );
        }

        $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $inline     = "jQuery( function( $ ) {\n\t$( 'select#post_status' ).prepend( " . wp_json_encode( $options, $json_flags ) . " );\n";
        if ( '' !== $status_line ) {
            $inline .= "\t" . $status_line . "\n";
        }
        $inline .= "} );";

        wp_print_inline_script_tag( $inline );
    }
 
    function true_append_status() {
        global $post;
        if ( is_object( $post ) && 'picky_booking' === $post->post_type ) {
            $options  = '<option value="picky_requests">' . esc_html__( 'Requested', 'pickybooking' ) . '</option>';
            $options .= '<option value="picky_reserved">' . esc_html__( 'Reserved', 'pickybooking' ) . '</option>';
            $options .= '<option value="picky_canceled">' . esc_html__( 'Canceled', 'pickybooking' ) . '</option>';

            $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
            $inline     = "jQuery( document ).ready( function( $ ) {\n\t$( 'select[name=\"_status\"]' ).prepend( " . wp_json_encode( $options, $json_flags ) . " );\n} );";
            wp_print_inline_script_tag( $inline );
        }
    }

    function hide_publishing_actions(){
        if ( ! is_admin() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
            return;
        }
        global $post;
        if ( ! $post || $post->post_type !== 'picky_booking' ) {
            return;
        }
        if ( $post->post_status !== 'auto-draft' ) {
            return;
        }
        $css = '#misc-publishing-actions,#minor-publishing-actions{display:none;}';
        wp_register_style( 'picky-hide-publishing', false, array(), $this->version );
        wp_enqueue_style( 'picky-hide-publishing' );
        wp_add_inline_style( 'picky-hide-publishing', $css );
    }

    function example_insert_using_custom_status( $data = array(), $postarr = array() ) {
        $type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';
        if ( ! in_array( $type, array( 'picky_booking', 'picky_order' ), true ) ) {
            return $data;
        }
        if ( ! empty( $postarr['_wp_statuses_status'] ) && function_exists( 'picky_booking_status_canonical' ) ) {
            $data['post_status'] = picky_booking_status_canonical( $postarr['_wp_statuses_status'] );
        } elseif ( ! empty( $data['post_status'] ) && function_exists( 'picky_booking_status_canonical' ) ) {
            $data['post_status'] = picky_booking_status_canonical( $data['post_status'] );
        }
        return $data;
    }

    function picky_display_archive_state( $states ) {
        global $post;
        if ( ! is_object( $post ) || empty( $post->post_type ) ) {
            return $states;
        }
        if($post->post_type === 'picky_booking'){
            if(get_query_var( 'post_status' ) !== 'requests' 
            && get_query_var( 'post_status' ) !== 'reserved' 
            && get_query_var( 'post_status' ) !== 'canceled'){
                if($post->post_status === "requests"){
                    return array('Requested');
                } else if($post->post_status === "reserved"){
                    return array('Reserved');
                } else if($post->post_status === "canceled"){
                    return array('Canceled');
                }
            }
        }
        return $states;
    }
    

    
	
}