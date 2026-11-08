<?php

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend
{
    private $plugin_slug;
    private $version;
    private $option_name;
    private $settings;

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
    }

    /**
     * Singular label for the reservable item (Table / Room / Court / …).
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
     * Plural label for the reservable item. If empty, derives from the singular.
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
        return sanitize_text_field( $this->picky_item_label() . 's' );
    }

    /**
     * Try to atomically acquire a per-slot mutex stored in the wp_options table.
     *
     * Uses an "INSERT IGNORE" against wp_options.option_name to make acquisition
     * race-safe between concurrent PHP processes (option_name is UNIQUE). If the
     * lock row already exists, we treat it as held — unless its stored expiry
     * timestamp is in the past, in which case we conditionally take it over via
     * an UPDATE WHERE option_value = <stale value> (optimistic compare-and-swap).
     *
     * @param string $key         Free-form slot identifier (e.g. date + unique id).
     * @param int    $ttl_seconds Max lock lifetime; protects against crashed callers.
     * @return string|false       The actual option_name to release, or false if not acquired.
     */
    private function picky_acquire_slot_mutex( $key, $ttl_seconds = 30 ) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic slot mutex via options table; prepare() used
        global $wpdb;
        if ( ! $wpdb || ! is_string( $key ) || $key === '' ) {
            return false;
        }
        $lock_name = 'picky_slot_lock_' . md5( $key );
        $expire_at = time() + max( 5, intval( $ttl_seconds ) );

        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $lock_name,
            (string) $expire_at
        ) );
        if ( $inserted === 1 ) {
            return $lock_name;
        }

        // Row already exists. Check if it's stale and try to steal it without races.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $lock_name
        ) );
        if ( $existing !== null && intval( $existing ) < time() ) {
            $taken = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $expire_at,
                $lock_name,
                (string) $existing
            ) );
            if ( $taken ) {
                return $lock_name;
            }
        }
        return false;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Release a mutex previously returned by {@see picky_acquire_slot_mutex()}.
     *
     * @param string|false $lock_name Value returned by acquire; safe to pass false.
     * @return void
     */
    private function picky_release_slot_mutex( $lock_name ) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic slot mutex release via options table
        if ( ! is_string( $lock_name ) || $lock_name === '' ) {
            return;
        }
        delete_option( $lock_name );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Vonage alphanumeric sender: Latin letters and digits only, max 11 characters.
     * Pipes, spaces, and long strings break or truncate on many carriers (GSM-7 rules).
     *
     * Default sender id is "PB" (PickyBooking). Override with the `picky_sms_sender_id` filter.
     *
     * @return string
     */
    private function picky_sms_sender_for_api() {
        $raw = apply_filters( 'picky_sms_sender_id', 'PB', is_array( $this->settings ) ? $this->settings : array() );
        $raw = is_string( $raw ) ? $raw : 'PB';
        $id  = preg_replace( '/[^A-Za-z0-9]/', '', $raw );
        if ( $id === '' ) {
            return 'PB';
        }
        return substr( $id, 0, 11 );
    }

    /**
     * Whether the SMS body needs Vonage type=unicode (UCS-2) instead of default GSM-7.
     * Cyrillic, smart quotes, many accented letters, etc. otherwise arrive as garbled symbols.
     *
     * @param string $text Message body.
     * @return bool
     */
    private function picky_sms_text_needs_unicode( $text ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return false;
        }
        return (bool) preg_match( '/[^\x09\x0A\x0D\x20-\x7E]/u', $text );
    }

    /**
     * Read a POST scalar with wp_unslash + sanitize_text_field (never use raw $_POST in logic).
     *
     * @param string $key     $_POST key.
     * @param string $default Value when key missing.
     * @return string
     */
    private function sanitize_post_text_field( $key, $default = '' ) {
        if ( isset( $_POST['picky_nonce'] ) ) {
            if ( ! is_scalar( $_POST['picky_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_nonce'] ) ), 'picky_send_book_action' ) ) {
                return $default;
            }
        } elseif ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            return $default;
        }
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return $default;
        }
        return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * Normalize a layout element unique id for storage and lookups.
     *
     * Multi-item booking already runs uniques through sanitize_key() (lowercase).
     * Availability checks previously keyed bookings with the raw meta value and
     * looked them up with the mixed-case layout JSON unique — PHP array keys are
     * case-sensitive, so occupied tables appeared free on the map while send_book
     * still rejected them (MySQL meta compare is usually case-insensitive).
     *
     * @param string $unique Raw unique from layout JSON, POST, or post meta.
     * @return string Lowercase sanitized key, or '' when empty.
     */
    private function picky_normalize_unique( $unique ) {
        $unique = sanitize_key( (string) $unique );
        return $unique;
    }

    /**
     * Whether a picky_booking should occupy calendar / overlap checks.
     * Payment init failures leave child meta as "pending" while the parent order
     * is marked init_failed — those orphans must not block new bookings.
     *
     * @param int $booking_id picky_booking post ID.
     * @return bool True when this booking should block the slot.
     */
    private function picky_booking_blocks_slot( $booking_id ) {
        $booking_id = absint( $booking_id );
        if ( $booking_id <= 0 ) {
            return false;
        }
        if ( function_exists( 'picky_status_is' ) && picky_status_is( (string) get_post_status( $booking_id ), 'canceled' ) ) {
            return false;
        }
        $pay = sanitize_key( (string) get_post_meta( $booking_id, '_picky_payment_status', true ) );
        if ( $pay === 'init_failed' ) {
            return false;
        }
        $order_id = absint( get_post_meta( $booking_id, '_picky_booking_order_id', true ) );
        if ( $order_id > 0 ) {
            $order_pay = sanitize_key( (string) get_post_meta( $order_id, '_picky_order_payment_status', true ) );
            if ( $order_pay === 'init_failed' ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private function sanitize_post_email_field( $key, $default = '' ) {
        if ( isset( $_POST['picky_nonce'] ) ) {
            if ( ! is_scalar( $_POST['picky_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_nonce'] ) ), 'picky_send_book_action' ) ) {
                return $default;
            }
        } elseif ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            return $default;
        }
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return $default;
        }
        $email = sanitize_email( wp_unslash( (string) $_POST[ $key ] ) );
        return $email !== '' ? $email : $default;
    }

    /**
     * @param string $key
     * @param int    $default
     * @return int
     */
    private function sanitize_post_absint( $key, $default = 0 ) {
        if ( isset( $_POST['picky_nonce'] ) ) {
            if ( ! is_scalar( $_POST['picky_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_nonce'] ) ), 'picky_send_book_action' ) ) {
                return absint( $default );
            }
        } elseif ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            return absint( $default );
        }
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return absint( $default );
        }
        return absint( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * Verify AJAX POST nonce; rejects non-scalar values (e.g. forged array parameters).
     *
     * @param string $post_key $_POST key holding the nonce.
     * @param string $action   Nonce action name.
     * @return bool
     */
    private function verify_post_nonce_scalar( $post_key, $action ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- This helper IS the nonce verification
        return isset( $_POST[ $post_key ] )
            && is_scalar( $_POST[ $post_key ] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) ), $action );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * Read the configured payment amount source.
     *
     * @param array $options Plugin settings.
     * @return string One of: fixed, services, services_or_fixed
     */
    private function picky_payment_amount_source( $options ) {
        $allowed = array( 'fixed', 'services', 'services_or_fixed' );
        $src = is_array( $options ) && isset( $options['payment_amount_source'] )
            ? sanitize_key( (string) $options['payment_amount_source'] )
            : 'fixed';
        return in_array( $src, $allowed, true ) ? $src : 'fixed';
    }

    /**
     * Resolve the effective payment amount based on the configured source.
     *
     * @param array      $options        Plugin settings.
     * @param float|null $services_total Sum of selected services' prices, when known.
     * @return float
     */
    private function picky_resolve_payment_amount( $options, $services_total = null ) {
        $fixed = is_array( $options ) && isset( $options['deposit_amount'] )
            ? max( 0.0, round( (float) $options['deposit_amount'], 2 ) )
            : 0.0;
        $svc = $services_total !== null ? max( 0.0, round( (float) $services_total, 2 ) ) : null;

        switch ( $this->picky_payment_amount_source( $options ) ) {
            case 'services':
                return $svc !== null ? $svc : 0.0;
            case 'services_or_fixed':
                if ( $svc !== null && $svc > 0 ) {
                    return $svc;
                }
                return $fixed;
            case 'fixed':
            default:
                return $fixed;
        }
    }

    /**
     * Whether current booking flow requires immediate payment.
     *
     * @param array      $options        Plugin settings.
     * @param float|null $services_total Sum of selected services' prices, when known.
     * @return bool
     */
    private function picky_is_payment_required( $options, $services_total = null ) {
        // Payments are Pro-only — omitted from Lite (WP.org: no premium runtime in free zip).
        unset( $options, $services_total );
        return false;
    }

    /**
     * @param array $options
     * @return string
     */
    private function picky_payment_provider( $options ) {
        $provider = is_array( $options ) && isset( $options['payment_provider'] )
            ? sanitize_key( (string) $options['payment_provider'] )
            : 'none';
        if ( ! in_array( $provider, array( 'none', 'stripe', 'woocommerce' ), true ) ) {
            return 'none';
        }
        return $provider;
    }

    /**
     * Format a money amount for guest-facing payment text (plain, no HTML).
     *
     * @param float  $amount   Amount.
     * @param string $currency Currency code.
     * @return string
     */
    private function picky_format_payment_money( $amount, $currency = '' ) {
        $amount   = round( (float) $amount, 2 );
        $currency = strtoupper( sanitize_text_field( (string) $currency ) );
        if ( $currency === '' && function_exists( 'get_woocommerce_currency' ) ) {
            $currency = (string) get_woocommerce_currency();
        }
        if ( function_exists( 'html_entity_decode' ) && function_exists( 'wp_strip_all_tags' ) && function_exists( 'wc_price' ) ) {
            $html = wc_price( $amount, array( 'currency' => $currency !== '' ? $currency : null ) );
            return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
        }
        $symbol = '$';
        if ( $currency === 'EUR' ) {
            $symbol = '€';
        } elseif ( $currency === 'GBP' ) {
            $symbol = '£';
        } elseif ( $currency !== '' && $currency !== 'USD' ) {
            $symbol = $currency . ' ';
        }
        return $symbol . number_format( $amount, 2, '.', '' );
    }

    /**
     * Build priced service rows for payment UIs from resolved booking items.
     *
     * @param array  $resolved_items Multi-item rows with services_snapshot.
     * @param array  $data           Booking payload (may include services_snapshot).
     * @param string $currency       Currency code for labels.
     * @return array{lines:array<int,array{label:string,price:float,item_id:string,name:string}>,label:string,total:float}
     */
    private function picky_build_priced_service_lines( $resolved_items, $data = array(), $currency = '' ) {
        $lines = array();
        $chunks = array();

        $push_svc = function ( $did, $svc, $multi ) use ( &$lines, &$chunks, $currency ) {
            if ( ! is_array( $svc ) ) {
                return;
            }
            $name = isset( $svc['name'] ) ? trim( (string) $svc['name'] ) : '';
            if ( $name === '' ) {
                return;
            }
            $price = round( (float) ( $svc['price'] ?? 0 ), 2 );
            $label = ( $multi && $did !== '' ) ? ( '#' . $did . ' · ' . $name ) : $name;
            $lines[] = array(
                'label'   => $label,
                'price'   => $price,
                'item_id' => $did,
                'name'    => $name,
            );
            if ( $price > 0 ) {
                $chunks[] = $label . ' (' . $this->picky_format_payment_money( $price, $currency ) . ')';
            } else {
                $chunks[] = $label;
            }
        };

        $resolved_items = is_array( $resolved_items ) ? $resolved_items : array();
        $data           = is_array( $data ) ? $data : array();
        $multi          = count( $resolved_items ) > 1;

        if ( ! empty( $resolved_items ) ) {
            foreach ( $resolved_items as $ri ) {
                if ( ! is_array( $ri ) ) {
                    continue;
                }
                $did = isset( $ri['display_id'] ) && (string) $ri['display_id'] !== ''
                    ? (string) $ri['display_id']
                    : ( isset( $ri['id'] ) ? (string) $ri['id'] : '' );
                $snap = isset( $ri['services_snapshot'] ) && is_array( $ri['services_snapshot'] )
                    ? $ri['services_snapshot']
                    : array();
                foreach ( $snap as $svc ) {
                    $push_svc( $did, $svc, $multi );
                }
            }
        } elseif ( ! empty( $data['services_snapshot'] ) && is_array( $data['services_snapshot'] ) ) {
            $did = isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '';
            foreach ( $data['services_snapshot'] as $svc ) {
                $push_svc( $did, $svc, false );
            }
        } elseif ( ! empty( $data['service_name'] ) ) {
            $name  = sanitize_text_field( (string) $data['service_name'] );
            $price = isset( $data['service_price'] ) ? round( (float) $data['service_price'], 2 ) : 0.0;
            $lines[] = array(
                'label'   => $name,
                'price'   => $price,
                'item_id' => '',
                'name'    => $name,
            );
            $chunks[] = $price > 0
                ? ( $name . ' (' . $this->picky_format_payment_money( $price, $currency ) . ')' )
                : $name;
        }

        $total = 0.0;
        foreach ( $lines as $row ) {
            $total += (float) $row['price'];
        }

        return array(
            'lines' => $lines,
            'label' => implode( ' · ', $chunks ),
            'total' => round( $total, 2 ),
        );
    }

    /**
     * Guest-facing booking details for WooCommerce / Stripe payment UIs.
     *
     * @param array $data           Booking payload (date, time, titles, …).
     * @param array $resolved_items Optional multi-item rows with services_snapshot.
     * @return array{title:string,description:string,date:string,time:string,services:string,note:string,service_lines:array}
     */
    private function picky_build_payment_display_details( $data, $resolved_items = array() ) {
        $data           = is_array( $data ) ? $data : array();
        $resolved_items = is_array( $resolved_items ) ? $resolved_items : array();

        $date    = isset( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : '';
        $time    = isset( $data['time'] ) ? sanitize_text_field( (string) $data['time'] ) : '';
        $time_to = isset( $data['time_to'] ) ? sanitize_text_field( (string) $data['time_to'] ) : '';
        if ( $time_to === '' && ! empty( $resolved_items[0]['time_to'] ) ) {
            $time_to = sanitize_text_field( (string) $resolved_items[0]['time_to'] );
        }

        $time_label = $time;
        if ( $time !== '' && $time_to !== '' && $time_to !== '--' ) {
            /* translators: 1: start time, 2: end time */
            $time_label = sprintf( __( '%1$s – %2$s', 'pickybooking' ), $time, $time_to );
        }

        $item_label = $this->picky_item_label();
        $item_id    = isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '';
        if ( ! empty( $data['order_item_title'] ) ) {
            $title = sanitize_text_field( (string) $data['order_item_title'] );
        } elseif ( ! empty( $data['item_ids_label'] ) ) {
            $title = trim(
                sprintf(
                    /* translators: %s: plural bookable items label, e.g. "Tables" */
                    __( 'Book %s', 'pickybooking' ),
                    $this->picky_items_label()
                ) . ' ' . sanitize_text_field( (string) $data['item_ids_label'] )
            );
        } else {
            $title = sprintf(
                /* translators: 1: reservable item label (Table/Room), 2: item id/number */
                __( '%1$s Booking #%2$s', 'pickybooking' ),
                $item_label,
                $item_id !== '' ? $item_id : '#'
            );
        }

        $currency = isset( $data['currency'] ) ? (string) $data['currency'] : '';
        $priced   = $this->picky_build_priced_service_lines( $resolved_items, $data, $currency );
        $services_label = $priced['label'];

        $desc_parts = array();
        if ( $date !== '' ) {
            $desc_parts[] = sprintf(
                /* translators: %s: booking date */
                __( 'Date: %s', 'pickybooking' ),
                $date
            );
        }
        if ( $time_label !== '' ) {
            $desc_parts[] = sprintf(
                /* translators: %s: booking time range */
                __( 'Time: %s', 'pickybooking' ),
                $time_label
            );
        }
        if ( $services_label !== '' ) {
            $desc_parts[] = sprintf(
                /* translators: %s: comma-separated service names with prices */
                __( 'Services: %s', 'pickybooking' ),
                $services_label
            );
        }
        $description = implode( ' · ', $desc_parts );
        if ( function_exists( 'mb_substr' ) ) {
            $description = mb_substr( $description, 0, 800 );
        } else {
            $description = substr( $description, 0, 800 );
        }

        $note_lines = array();
        if ( $date !== '' || $time_label !== '' ) {
            $note_lines[] = sprintf(
                /* translators: 1: reservation date, 2: reservation time */
                __( 'Booking: %1$s, %2$s', 'pickybooking' ),
                $date !== '' ? $date : '—',
                $time_label !== '' ? $time_label : '—'
            );
        }
        if ( ! empty( $priced['lines'] ) ) {
            $note_lines[] = __( 'Services & add-ons:', 'pickybooking' );
            foreach ( $priced['lines'] as $row ) {
                $note_lines[] = '- ' . $row['label'] . (
                    $row['price'] > 0
                        ? ( ' — ' . $this->picky_format_payment_money( $row['price'], $currency ) )
                        : ''
                );
            }
        } elseif ( $services_label !== '' ) {
            $note_lines[] = sprintf(
                /* translators: %s: selected services */
                __( 'Extra services: %s', 'pickybooking' ),
                $services_label
            );
        }
        if ( $title !== '' ) {
            array_unshift( $note_lines, $title );
        }

        return array(
            'title'         => $title,
            'description'   => $description,
            'date'          => $date,
            'time'          => $time_label,
            'services'      => $services_label,
            'service_lines' => $priced['lines'],
            'services_sum'  => $priced['total'],
            'note'          => implode( "\n", $note_lines ),
        );
    }

    /**
     * Create WooCommerce order for a reservation and return checkout URL.
     *
     * @param int   $reservation_id Reservation post ID.
     * @param array $data           Sanitized booking payload.
     * @param array $options        Plugin settings.
     * @return array{success:bool,error:string,redirect_url:string,order_id:int}
     */
    private function picky_create_woocommerce_order_for_booking( $reservation_id, $data, $options ) {
        unset( $reservation_id, $data, $options );
        return array(
            'success'      => false,
            'error'        => esc_html__( 'Payments are available in PickyBooking Pro.', 'pickybooking' ),
            'redirect_url' => '',
            'order_id'     => 0,
        );
    }

    /**
     * Create direct Stripe Checkout Session.
     *
     * @param int   $reservation_id Reservation post ID.
     * @param array $data           Sanitized booking payload.
     * @param array $options        Plugin settings.
     * @return array{success:bool,error:string,redirect_url:string,order_id:int}
     */
    private function picky_create_stripe_checkout_session_for_booking( $reservation_id, $data, $options ) {
        unset( $reservation_id, $data, $options );
        return array(
            'success'      => false,
            'error'        => esc_html__( 'Payments are available in PickyBooking Pro.', 'pickybooking' ),
            'redirect_url' => '',
            'session_id'   => '',
        );
    }

    public function assets() {
        if ( is_admin() ) {
            return;
        }
        if ( ! $this->picky_should_enqueue_front_assets() ) {
            return;
        }

        $front_css = plugin_dir_path(__FILE__).'css/picky-frontend.css';
        $front_js  = plugin_dir_path(__FILE__).'js/picky-frontend.js';
        $front_css_ver = $this->version;
        $front_js_ver  = $this->version;

        $tokens_css = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/picky-tokens.css';
        $tokens_ver = $this->version;
        wp_enqueue_style(
            Info::ASSET_HANDLE_TOKENS,
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/picky-tokens.css',
            array(),
            $tokens_ver
        );

        wp_enqueue_style( Info::ASSET_HANDLE_SELECT2_STYLE, plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/select2.css', array(), $this->version );
        wp_enqueue_style(
            Info::ASSET_HANDLE_APP,
            plugin_dir_url( __FILE__ ) . 'css/picky-frontend.css',
            array( Info::ASSET_HANDLE_TOKENS, Info::ASSET_HANDLE_SELECT2_STYLE ),
            $front_css_ver
        );

        // Ensure icon font loads with absolute URLs (avoid relative URL issues when WP concatenates CSS)
        $plugin_root = plugin_dir_path( dirname( __FILE__ ) ) . Info::MAIN_FILE;
        $root_url = plugins_url( '/', $plugin_root );
        $font_woff = esc_url( $root_url . 'assets/font/picky-tools-font.woff?6tbcxd' );
        $font_ttf  = esc_url( $root_url . 'assets/font/picky-tools-font.ttf?6tbcxd' );
        $font_svg  = esc_url( $root_url . 'assets/font/picky-tools-font.svg?6tbcxd#picky-tools-font' );
        $font_css = "@font-face{font-family:'picky-tools-font';src:url('{$font_woff}') format('woff'),url('{$font_ttf}') format('truetype'),url('{$font_svg}') format('svg');font-weight:normal;font-style:normal;font-display:swap;}
        .picky-tables-wrap [class^='icon-'],.picky-tables-wrap [class*=' icon-'],.picky-tables-wrap .picky-element-seats::before{font-family:'picky-tools-font' !important;speak:none;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}";
        wp_add_inline_style( Info::ASSET_HANDLE_APP, $font_css );
        // Use WordPress core jQuery UI Datepicker (do not bundle jquery-ui).
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( Info::ASSET_HANDLE_SELECT2_SCRIPT, plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/select2.js', array( 'jquery' ), $this->version, true );

        wp_enqueue_script( Info::ASSET_HANDLE_APP, plugin_dir_url( __FILE__ ) . 'js/picky-frontend.js', array( 'jquery', 'jquery-ui-datepicker', Info::ASSET_HANDLE_SELECT2_SCRIPT ), $front_js_ver, true );
        $picky_settings_front = Info::get_settings();
        wp_localize_script(
            Info::ASSET_HANDLE_APP,
            'pickyAjax',
            array(
                'url' => admin_url('admin-ajax.php'),
                'bookingIcons' => Booking_Icons::js_payload(),
                'animateBookableItems' => ! isset( $picky_settings_front['animate_bookable_items'] ) || (string) $picky_settings_front['animate_bookable_items'] === '1',
                'i18n' => array(
                    /* translators: %s: configurable item name singular (e.g. Table, Room, Court) */
                    'table_prefix'      => esc_html( sprintf( __( '%s #', 'pickybooking' ), $this->picky_item_label() ) ),
                    'generic_error'     => esc_html__( 'Error', 'pickybooking' ),
                    'fill_required'     => esc_html__( 'Please fill in all required fields.', 'pickybooking' ),
                    'pick_date_time'    => esc_html__( 'Please select a date and time, then try again.', 'pickybooking' ),
                    'check_confirm'     => esc_html__( 'Please confirm that we may use your personal data.', 'pickybooking' ),
                    'select_item'       => esc_html__( 'Please select an item first.', 'pickybooking' ),
                    'unlimited_seats'   => esc_html__( 'Unlimited', 'pickybooking' ),
                    /* translators: placeholder when date/time or max seats is unknown (summary line). */
                    'em_dash'           => esc_html__( "\u{2014}", 'pickybooking' ),
                    /* translators: %s: the time (HH:MM) the next reservation starts */
                    'time_limit_notice' => esc_html__( 'Note: the next reservation starts at %s. Your visit will be limited to that time.', 'pickybooking' ),
                ),
            )
        );
        add_action( 'wp_footer', array( Booking_Icons::class, 'print_sprite_once' ), 5 );
    }


    /**
     * Enqueue public assets only on pages that render the booking shortcode.
     *
     * @return bool
     */
    private function picky_should_enqueue_front_assets() {
        global $post;
        if ( $post instanceof \WP_Post ) {
            $content = (string) $post->post_content;
            if ( has_shortcode( $content, 'pickybooking' )
                || false !== stripos( $content, '[pickybooking' )
            ) {
                return true;
            }
        }
        return (bool) apply_filters( 'picky_enqueue_frontend', false );
    }

    /**
     * Parse HH:MM to minutes since midnight.
     *
     * @param mixed $t
     * @return int|null
     */
    private function picky_parse_time_to_minutes( $t ) {
        if ( ! is_string( $t ) || $t === '' ) {
            return null;
        }
        $parts = explode( ':', $t );
        if ( count( $parts ) < 2 ) {
            return null;
        }
        $h = intval( $parts[0] );
        $m = intval( $parts[1] );
        if ( $h < 0 || $h > 23 || $m < 0 || $m > 59 ) {
            return null;
        }
        return ( $h * 60 ) + $m;
    }

    /**
     * Parse HH:MM strictly as an END-of-window time.
     *
     * Info::$TIME_ALL deliberately includes "00:00" as the "midnight / end of
     * day" sentinel, but picky_parse_time_to_minutes() — being a generic parser —
     * returns 0 for it. That made:
     *   - the slot 23:30 → 00:00 fail with "End time must be after start time",
     *   - interval hints never offer 00:00 as a valid end,
     *   - existing reservations ending at midnight not collide with new ones.
     *
     * Map "00:00" and "24:00" to 1440 (one full day in minutes) only on the END
     * side. Start-time parsing is unchanged on purpose.
     *
     * @param mixed $t
     * @return int|null
     */
    private function picky_parse_end_time_to_minutes( $t ) {
        if ( ! is_string( $t ) || $t === '' ) {
            return null;
        }
        $trimmed = trim( $t );
        if ( $trimmed === '00:00' || $trimmed === '24:00' ) {
            return 1440;
        }
        return $this->picky_parse_time_to_minutes( $trimmed );
    }

    /**
     * Return lowercase weekday slug (monday..sunday) for a booking date.
     *
     * @param string $date
     * @return string
     */
    private function picky_day_slug_from_date( $date ) {
        $raw = trim( (string) $date );
        if ( $raw === '' ) {
            return '';
        }
        $tz = wp_timezone();
        $dt = \DateTimeImmutable::createFromFormat( 'm/d/Y', $raw, $tz );
        if ( ! ( $dt instanceof \DateTimeImmutable ) ) {
            $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $raw, $tz );
        }
        if ( ! ( $dt instanceof \DateTimeImmutable ) ) {
            return '';
        }
        $day = strtolower( $dt->format( 'l' ) );
        $allowed = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        return in_array( $day, $allowed, true ) ? $day : '';
    }

    /**
     * True when $start_mins is already in the past for $date (WordPress local timezone).
     *
     * @param string $date       Booking date (m/d/Y or Y-m-d).
     * @param int    $start_mins Minutes since midnight.
     * @return bool
     */
    private function picky_slot_start_is_past_for_date( $date, $start_mins ) {
        $raw = trim( (string) $date );
        if ( $raw === '' || $start_mins === null ) {
            return false;
        }
        $tz = wp_timezone();
        $dt = \DateTimeImmutable::createFromFormat( 'm/d/Y', $raw, $tz );
        if ( ! ( $dt instanceof \DateTimeImmutable ) ) {
            $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $raw, $tz );
        }
        if ( ! ( $dt instanceof \DateTimeImmutable ) ) {
            return false;
        }
        $today = new \DateTimeImmutable( 'now', $tz );
        if ( $dt->format( 'Y-m-d' ) !== $today->format( 'Y-m-d' ) ) {
            return false;
        }
        $now_mins = ( intval( $today->format( 'G' ), 10 ) * 60 ) + intval( $today->format( 'i' ), 10 );
        return intval( $start_mins ) <= $now_mins;
    }

    /**
     * Slice INFO::$TIME_ALL from start to end (inclusive), with midnight wrap support.
     *
     * @param array<int,string> $all_slots
     * @param string            $start
     * @param string            $end
     * @return array<int,string>
     */
    private function picky_slots_between_inclusive( $all_slots, $start, $end ) {
        $start = trim( (string) $start );
        $end   = trim( (string) $end );
        if ( $start === '' || $end === '' || ! is_array( $all_slots ) || empty( $all_slots ) ) {
            return array();
        }
        $i_start = array_search( $start, $all_slots, true );
        $i_end   = array_search( $end, $all_slots, true );
        if ( $i_start === false || $i_end === false ) {
            return array();
        }
        if ( $i_start <= $i_end ) {
            return array_slice( $all_slots, (int) $i_start, ( (int) $i_end - (int) $i_start ) + 1 );
        }
        // Wrap across midnight, e.g. 22:00 -> 00:00.
        return array_merge(
            array_slice( $all_slots, (int) $i_start ),
            array_slice( $all_slots, 0, (int) $i_end + 1 )
        );
    }

    /**
     * Build allowed slot candidates for a concrete date from Work Days settings.
     *
     * @param string $date
     * @param array  $options
     * @return array<int,string>
     */
    private function picky_allowed_slots_for_date( $date, $options ) {
        $all_slots = Info::$TIME_ALL;
        if ( ! is_array( $all_slots ) || empty( $all_slots ) ) {
            return array();
        }
        $day = $this->picky_day_slug_from_date( $date );
        if ( $day === '' ) {
            return $all_slots;
        }

        $enabled_key = 'work_days_' . $day;
        if ( isset( $options[ $enabled_key ] ) && intval( $options[ $enabled_key ] ) !== 1 ) {
            return array();
        }

        $slots = array();
        $s1 = isset( $options[ 'work_days_' . $day . '_time_start' ] ) ? (string) $options[ 'work_days_' . $day . '_time_start' ] : '';
        $e1 = isset( $options[ 'work_days_' . $day . '_time_end' ] ) ? (string) $options[ 'work_days_' . $day . '_time_end' ] : '';
        $slots = array_merge( $slots, $this->picky_slots_between_inclusive( $all_slots, $s1, $e1 ) );

        $more_key = 'work_days_' . $day . '_more';
        if ( isset( $options[ $more_key ] ) && intval( $options[ $more_key ] ) === 1 ) {
            $s2 = isset( $options[ 'work_days_' . $day . '_time_start_more' ] ) ? (string) $options[ 'work_days_' . $day . '_time_start_more' ] : '';
            $e2 = isset( $options[ 'work_days_' . $day . '_time_end_more' ] ) ? (string) $options[ 'work_days_' . $day . '_time_end_more' ] : '';
            $slots = array_merge( $slots, $this->picky_slots_between_inclusive( $all_slots, $s2, $e2 ) );
        }

        if ( empty( $slots ) ) {
            return array();
        }

        // De-duplicate while preserving canonical INFO::$TIME_ALL order.
        $allowed_map = array_fill_keys( $slots, true );
        $ordered     = array();
        foreach ( $all_slots as $slot ) {
            if ( isset( $allowed_map[ $slot ] ) ) {
                $ordered[] = $slot;
            }
        }
        return $ordered;
    }

    /**
     * Minutes of one booking slot step (derived from configured slot list).
     * Fallback: 30 minutes.
     *
     * @param string $date
     * @param array  $options
     * @return int
     */
    private function picky_slot_step_minutes( $date, $options ) {
        $slots = $this->picky_allowed_slots_for_date( $date, $options );
        if ( ! is_array( $slots ) || count( $slots ) < 2 ) {
            $slots = Info::$TIME_ALL;
        }
        if ( ! is_array( $slots ) || count( $slots ) < 2 ) {
            return 30;
        }
        $best = null;
        $prev = null;
        foreach ( $slots as $slot ) {
            $cur = $this->picky_parse_time_to_minutes( (string) $slot );
            if ( $cur === null ) {
                continue;
            }
            if ( $prev !== null ) {
                $d = $cur - $prev;
                if ( $d > 0 && ( $best === null || $d < $best ) ) {
                    $best = $d;
                }
            }
            $prev = $cur;
        }
        return ( $best !== null && $best > 0 ) ? (int) $best : 30;
    }

    /**
     * Effective default duration in minutes when end time is not selected.
     * If setting is 0, we still reserve one slot step.
     *
     * @param string $date
     * @param array  $options
     * @param int    $dur_h
     * @return int
     */
    private function picky_default_duration_minutes( $date, $options, $dur_h ) {
        $mins = max( 0, intval( $dur_h ) ) * 60;
        if ( $mins > 0 ) {
            return $mins;
        }
        return $this->picky_slot_step_minutes( $date, $options );
    }

    /**
     * Work windows (in minutes) for a date from Work Days settings.
     *
     * @param string $date
     * @param array  $options
     * @return array<int,array{start:int,end:int}>
     */
    private function picky_work_windows_for_date( $date, $options ) {
        $day = $this->picky_day_slug_from_date( $date );
        if ( $day === '' ) {
            return array();
        }
        $enabled_key = 'work_days_' . $day;
        if ( isset( $options[ $enabled_key ] ) && intval( $options[ $enabled_key ] ) !== 1 ) {
            return array();
        }
        $windows = array();

        $s1 = isset( $options[ 'work_days_' . $day . '_time_start' ] ) ? $this->picky_parse_time_to_minutes( (string) $options[ 'work_days_' . $day . '_time_start' ] ) : null;
        $e1 = isset( $options[ 'work_days_' . $day . '_time_end' ] ) ? $this->picky_parse_end_time_to_minutes( (string) $options[ 'work_days_' . $day . '_time_end' ] ) : null;
        if ( $s1 !== null && $e1 !== null && $e1 > $s1 ) {
            $windows[] = array( 'start' => $s1, 'end' => $e1 );
        }

        $more_key = 'work_days_' . $day . '_more';
        if ( isset( $options[ $more_key ] ) && intval( $options[ $more_key ] ) === 1 ) {
            $s2 = isset( $options[ 'work_days_' . $day . '_time_start_more' ] ) ? $this->picky_parse_time_to_minutes( (string) $options[ 'work_days_' . $day . '_time_start_more' ] ) : null;
            $e2 = isset( $options[ 'work_days_' . $day . '_time_end_more' ] ) ? $this->picky_parse_end_time_to_minutes( (string) $options[ 'work_days_' . $day . '_time_end_more' ] ) : null;
            if ( $s2 !== null && $e2 !== null && $e2 > $s2 ) {
                $windows[] = array( 'start' => $s2, 'end' => $e2 );
            }
        }

        return $windows;
    }

    /**
     * Whether [start,end] lies fully inside at least one work window for date.
     *
     * @param string   $date
     * @param array    $options
     * @param int|null $start
     * @param int|null $end
     * @return bool
     */
    private function picky_interval_within_work_windows( $date, $options, $start, $end ) {
        if ( $start === null || $end === null || $end <= $start ) {
            return false;
        }
        $windows = $this->picky_work_windows_for_date( $date, $options );
        foreach ( $windows as $w ) {
            if ( $start >= $w['start'] && $end <= $w['end'] ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $date Reservation date meta value.
     * @param array  $options Plugin options.
     * @param int    $dur_h Default span in hours when booking has no explicit end.
     * @return array<string, list<array{start:int,end:int,people:int}>>
     */
    private function picky_booked_intervals_by_unique_for_date( $date, $options, $dur_h, $layout_id = 0 ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        $meta_query = array(
            array(
                'key'     => '_picky_booking_date',
                'value'   => $date,
                'compare' => '=',
            ),
        );
        $layout_id = absint( $layout_id );
        if ( $layout_id > 0 ) {
            $meta_query[] = array(
                'key'     => '_picky_reservation_layout_id',
                'value'   => (string) $layout_id,
                'compare' => '=',
            );
        }
        $args = array(
            'post_type'              => 'picky_booking',
            'post_status'            => function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ),
            'numberposts'            => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => $meta_query,
        );
        $reserved          = get_posts( $args );
        $booked_by_unique  = array();
        $default_span_mins = $this->picky_default_duration_minutes( $date, $options, $dur_h );
        foreach ( $reserved as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $this->picky_booking_blocks_slot( $post_id ) ) {
                continue;
            }
            $unique = $this->picky_normalize_unique( (string) get_post_meta( $post_id, '_picky_booking_unique', true ) );
            if ( $unique === '' ) {
                continue;
            }
            $start_str = get_post_meta( $post_id, '_picky_booking_time', true );
            $end_str   = get_post_meta( $post_id, '_picky_booking_time_to', true );
            $ex_start  = $this->picky_parse_time_to_minutes( (string) $start_str );
            $ex_end    = $end_str ? $this->picky_parse_end_time_to_minutes( (string) $end_str ) : null;
            if ( $ex_start === null ) {
                continue;
            }
            if ( $ex_end === null ) {
                $ex_end = $ex_start + $default_span_mins;
            }
            if ( ! isset( $booked_by_unique[ $unique ] ) ) {
                $booked_by_unique[ $unique ] = array();
            }
            $people = intval( get_post_meta( $post_id, '_picky_booking_people', true ) );
            if ( $people <= 0 ) {
                $people = 1;
            }
            $booked_by_unique[ $unique ][] = array(
                'start'  => $ex_start,
                'end'    => $ex_end,
                'people' => $people,
            );
        }
        return $booked_by_unique;
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    /**
     * Guest payloads for booked seats on one date+time (avatars). Small on purpose.
     *
     * @param int    $layout_id Layout ID.
     * @param string $date      Booking date.
     * @param string $time      Booking start time.
     * @return list<array<string,mixed>>
     */
    private function picky_occupants_for_slot( $layout_id, $date, $time ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        $date = sanitize_text_field( (string) $date );
        $time = sanitize_text_field( (string) $time );
        $layout_id = absint( $layout_id );
        if ( $date === '' || $time === '' ) {
            return array();
        }
        if ( $layout_id && class_exists( __NAMESPACE__ . '\\Booking_Avatar' ) && ! Booking_Avatar::layout_shows_avatars( $layout_id ) ) {
            return array();
        }
        $meta_query = array(
            array( 'key' => '_picky_booking_date', 'value' => $date, 'compare' => '=' ),
            array( 'key' => '_picky_booking_time', 'value' => $time, 'compare' => '=' ),
        );
        if ( $layout_id > 0 ) {
            $meta_query[] = array(
                'key'     => '_picky_reservation_layout_id',
                'value'   => (string) $layout_id,
                'compare' => '=',
            );
        }
        $ids = get_posts(
            array(
                'post_type'              => 'picky_booking',
                'post_status'            => function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ),
                'numberposts'            => 400,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'meta_query'             => $meta_query,
            )
        );
        $out = array();
        foreach ( (array) $ids as $bid ) {
            $bid = absint( $bid );
            if ( ! $bid || ! $this->picky_booking_blocks_slot( $bid ) ) {
                continue;
            }
            $guest = Booking_Avatar::guest_payload_for_booking( $bid );
            if ( ! is_array( $guest ) ) {
                continue;
            }
            $out[] = array(
                'unique' => (string) get_post_meta( $bid, '_picky_booking_unique', true ),
                'id'     => (string) get_post_meta( $bid, '_picky_booking_id', true ),
                'date'   => $date,
                'time'   => $time,
                'guest'  => $guest,
            );
        }
        return $out;
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    /**
     * Once-event map: one guest per seat unique on this layout (any booking date).
     * Prefers a booking that matches the fixed event date+time when present.
     *
     * @param int    $layout_id     Layout ID.
     * @param string $prefer_date   Once-event date (optional preference).
     * @param string $prefer_time   Once-event time (optional preference).
     * @return list<array<string,mixed>>
     */
    private function picky_occupants_for_once_layout( $layout_id, $prefer_date = '', $prefer_time = '' ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        $layout_id    = absint( $layout_id );
        $prefer_date  = sanitize_text_field( (string) $prefer_date );
        $prefer_time  = sanitize_text_field( (string) $prefer_time );
        if ( $layout_id <= 0 ) {
            return array();
        }
        if ( class_exists( __NAMESPACE__ . '\\Booking_Avatar' ) && ! Booking_Avatar::layout_shows_avatars( $layout_id ) ) {
            return array();
        }
        $ids = get_posts(
            array(
                'post_type'              => 'picky_booking',
                'post_status'            => function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ),
                'numberposts'            => 800,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'meta_query'             => array(
                    array(
                        'key'     => '_picky_reservation_layout_id',
                        'value'   => (string) $layout_id,
                        'compare' => '=',
                    ),
                ),
            )
        );
        /** @var array<string,array<string,mixed>> $by_unique */
        $by_unique = array();
        foreach ( (array) $ids as $bid ) {
            $bid = absint( $bid );
            if ( ! $bid || ! $this->picky_booking_blocks_slot( $bid ) ) {
                continue;
            }
            $unique = $this->picky_normalize_unique( (string) get_post_meta( $bid, '_picky_booking_unique', true ) );
            if ( $unique === '' ) {
                continue;
            }
            $guest = Booking_Avatar::guest_payload_for_booking( $bid );
            if ( ! is_array( $guest ) ) {
                continue;
            }
            $b_date = sanitize_text_field( (string) get_post_meta( $bid, '_picky_booking_date', true ) );
            $b_time = sanitize_text_field( (string) get_post_meta( $bid, '_picky_booking_time', true ) );
            $row    = array(
                'unique' => $unique,
                'id'     => (string) get_post_meta( $bid, '_picky_booking_id', true ),
                'date'   => $b_date,
                'time'   => $b_time,
                'guest'  => $guest,
            );
            $prefer = ( $prefer_date !== '' && $prefer_time !== ''
                && $b_date === $prefer_date && $b_time === $prefer_time );
            if ( ! isset( $by_unique[ $unique ] ) ) {
                $by_unique[ $unique ] = $row;
                continue;
            }
            if ( $prefer ) {
                $by_unique[ $unique ] = $row;
            }
        }
        return array_values( $by_unique );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    /**
     * Occupants for the booking map for the selected (or once-event) date+time.
     *
     * @param int    $layout_id Layout ID.
     * @param string $date      Date.
     * @param string $time      Time.
     * @return list<array<string,mixed>>
     */
    private function picky_occupants_for_map( $layout_id, $date, $time ) {
        return $this->picky_occupants_for_slot( $layout_id, $date, $time );
    }

    /**
     * Look up the server-side capacity (max seats) of a layout element by its
     * unique id. Capacity must NEVER be trusted from the form payload — clients
     * can edit max_persons in the request and book a 2-seat table for 100 people.
     *
     * @param int    $layout_id ID of a picky_layout post; if 0 or wrong type, the
     *                          plugin-wide settings['position'] is used.
     * @param string $unique    The element's unique id as stored in the layout
     *                          JSON ($element['unique']).
     * @return int|null         Positive integer capacity, or null when no
     *                          capacity is defined for the element (caller
     *                          should fall back to "exclusive" booking mode).
     */
    private function picky_get_element_capacity( $layout_id, $unique ) {
        $unique = $this->picky_normalize_unique( $unique );
        if ( $unique === '' ) {
            return null;
        }
        $json = $this->picky_layout_elements_array( $layout_id );
        if ( ! is_array( $json ) ) {
            return null;
        }
        foreach ( $json as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $element_unique = $this->picky_normalize_unique( isset( $element['unique'] ) ? (string) $element['unique'] : '' );
            if ( $element_unique !== $unique ) {
                continue;
            }
            if ( ! isset( $element['max_seats'] ) ) {
                return null;
            }
            $capacity = intval( $element['max_seats'] );
            return ( $capacity > 0 ) ? $capacity : null;
        }
        return null;
    }

    /**
     * Public-facing item ID for titles / WooCommerce lines.
     * Prefers admin custom_id (e.g. "testid"), else layout auto id.
     *
     * @param int    $layout_id Layout post ID.
     * @param string $unique    Element unique key.
     * @param string $fallback  Posted system id fallback.
     * @return string
     */
    private function picky_get_element_display_id( $layout_id, $unique, $fallback = '' ) {
        $unique = $this->picky_normalize_unique( $unique );
        $fallback = sanitize_text_field( (string) $fallback );
        if ( $unique === '' ) {
            return $fallback;
        }
        $json = $this->picky_layout_elements_array( $layout_id );
        if ( ! is_array( $json ) ) {
            return $fallback;
        }
        foreach ( $json as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $element_unique = $this->picky_normalize_unique( isset( $element['unique'] ) ? (string) $element['unique'] : '' );
            if ( $element_unique !== $unique ) {
                continue;
            }
            $custom = isset( $element['custom_id'] ) ? trim( sanitize_text_field( (string) $element['custom_id'] ) ) : '';
            if ( $custom !== '' ) {
                return $custom;
            }
            $auto = isset( $element['id'] ) ? trim( sanitize_text_field( (string) $element['id'] ) ) : '';
            return $auto !== '' ? $auto : $fallback;
        }
        return $fallback;
    }

    /**
     * @param int $layout_id
     * @return array<int,array>|null
     */
    private function picky_layout_elements_array( $layout_id ) {
        $layout_id = absint( $layout_id );
        $settings  = Info::get_settings();
        $position  = '';
        if ( $layout_id > 0 && get_post_type( $layout_id ) === 'picky_layout' ) {
            $position = get_post_meta( $layout_id, '_picky_layout_position', true );
        }
        if ( empty( $position ) ) {
            $position = isset( $settings['position'] ) ? $settings['position'] : '';
        }
        $position = urldecode( (string) $position );
        $json     = $position ? json_decode( $position, true ) : null;
        return is_array( $json ) ? $json : null;
    }

    /**
     * @param array  $json layout elements
     * @param array  $booked_by_unique from picky_booked_intervals_by_unique_for_date
     * @param int    $req_start request window start (minutes)
     * @param int    $req_end request window end (minutes)
     * @param array  $options
     * @param string $booking_mode
     * @param int    $before_h
     * @param int    $after_h
     * @param int    $req_people
     * @param array|null $capacity_by_unique Optional output map for capacity status by unique id.
     * @return list<array{id:string,unique:string}>
     */
    private function picky_tables_available_for_window( $json, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people, &$capacity_by_unique = null ) {
        $tables = array();
        if ( ! is_array( $capacity_by_unique ) ) {
            $capacity_by_unique = array();
        }
        foreach ( $json as $val ) {
            if ( ! is_array( $val ) ) {
                continue;
            }
            $id          = isset( $val['id'] ) ? strval( $val['id'] ) : '';
            $unique_raw  = isset( $val['unique'] ) ? strval( $val['unique'] ) : '';
            $unique_key  = $this->picky_normalize_unique( $unique_raw );
            // Keep layout casing in the AJAX payload so the frontend can match data-unique.
            $unique      = $unique_raw;
            if ( $id === '' || $unique_key === '' ) {
                continue;
            }
            $element_class = isset( $val['class'] ) ? strval( $val['class'] ) : '';
            if ( $element_class === 'type-wall' ) {
                continue;
            }
            $is_available       = true;
            if ( $req_people <= 0 ) {
                $req_people = 1;
            }
            if ( $booking_mode === 'capacity' && isset( $val['max_seats'] ) && intval( $val['max_seats'] ) > 0 ) {
                $capacity = intval( $val['max_seats'] );
                $used     = 0;
                if ( isset( $booked_by_unique[ $unique_key ] ) ) {
                    foreach ( $booked_by_unique[ $unique_key ] as $interval ) {
                        $ex_block_start = $interval['start'] - ( $before_h * 60 );
                        $ex_block_end   = $interval['end'] + ( $after_h * 60 );
                        if ( $req_start < $ex_block_end && $ex_block_start < $req_end ) {
                            $used += intval( $interval['people'] );
                        }
                    }
                }
                if ( ( $used + $req_people ) > $capacity ) {
                    $is_available = false;
                }
                $capacity_by_unique[ $unique ] = array(
                    'capacity'   => $capacity,
                    'used'       => $used,
                    'free_spots' => max( 0, $capacity - $used ),
                );
            } elseif ( isset( $booked_by_unique[ $unique_key ] ) ) {
                foreach ( $booked_by_unique[ $unique_key ] as $interval ) {
                    $ex_block_start = $interval['start'] - ( $before_h * 60 );
                    $ex_block_end   = $interval['end'] + ( $after_h * 60 );
                    if ( $req_start < $ex_block_end && $ex_block_start < $req_end ) {
                        // Any overlap with another booking (incl. before/after buffers)
                        // means this start cannot take the full requested duration.
                        // Do not offer "truncated" visits shorter than the configured duration —
                        // that produced confusing "next reservation at HH:MM" notices.
                        $is_available = false;
                        break;
                    }
                }
            }
            if ( $is_available ) {
                $entry = array( 'id' => $id, 'unique' => $unique );
                $tables[] = $entry;
            }
        }
        return $tables;
    }

    /**
     * @param array $json
     * @param array $booked_by_unique
     */
    private function picky_any_table_available_for_window( $json, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people ) : bool {
        $t = $this->picky_tables_available_for_window( $json, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people );
        return ! empty( $t );
    }

    /**
     * When hints_unique is set, only those layout items are considered.
     * Supports a single unique or a comma-separated list (multi-select).
     *
     * @param array<int,array> $json
     * @param string           $hints_unique
     * @return array<int,array>
     */
    private function picky_layout_elements_for_slot_hints( $json, $hints_unique ) {
        if ( ! is_array( $json ) ) {
            return array();
        }
        $raw = is_string( $hints_unique ) ? trim( $hints_unique ) : '';
        if ( $raw === '' ) {
            return $json;
        }
        $wanted = array();
        foreach ( preg_split( '/\s*,\s*/', $raw ) as $piece ) {
            $n = $this->picky_normalize_unique( $piece );
            if ( $n !== '' ) {
                $wanted[ $n ] = true;
            }
        }
        if ( empty( $wanted ) ) {
            return $json;
        }
        $out = array();
        foreach ( $json as $val ) {
            if ( ! is_array( $val ) ) {
                continue;
            }
            $u = $this->picky_normalize_unique( strval( $val['unique'] ?? '' ) );
            if ( $u !== '' && isset( $wanted[ $u ] ) ) {
                $out[] = $val;
            }
        }
        return $out;
    }

    /**
     * True when every element in $json_hints is free for [req_start, req_end].
     * Used for multi-select: a slot is bookable only if ALL chosen tables are free.
     *
     * @param array $json_hints
     * @param array $booked_by_unique
     * @param int   $req_start
     * @param int   $req_end
     * @param array $options
     * @param string $booking_mode
     * @param int   $before_h
     * @param int   $after_h
     * @param int   $req_people
     * @return bool
     */
    private function picky_all_hinted_tables_available_for_window( $json_hints, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people ) : bool {
        if ( ! is_array( $json_hints ) || empty( $json_hints ) ) {
            return false;
        }
        $available = $this->picky_tables_available_for_window( $json_hints, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people );
        $avail_map = array();
        foreach ( $available as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $u = $this->picky_normalize_unique( strval( $row['unique'] ?? '' ) );
            if ( $u !== '' ) {
                $avail_map[ $u ] = true;
            }
        }
        foreach ( $json_hints as $el ) {
            if ( ! is_array( $el ) ) {
                return false;
            }
            $u = $this->picky_normalize_unique( strval( $el['unique'] ?? '' ) );
            if ( $u === '' || ! isset( $avail_map[ $u ] ) ) {
                return false;
            }
        }
        return true;
    }

    public function get_available_tables() {
        // Basic nonce check (same nonce field name used in forms)
        if ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid nonce', 'pickybooking' ) ), 403 );
        }

        $options      = Info::get_settings();
        $booking_mode = isset( $options['booking_mode'] ) ? $options['booking_mode'] : 'exclusive';
        $date         = $this->sanitize_post_text_field( 'date' );
        $time         = $this->sanitize_post_text_field( 'time' );
        $time_to      = $this->sanitize_post_text_field( 'time_to' );
        $layout_id    = $this->sanitize_post_absint( 'picky_layout_id', 0 );
        if ( $layout_id && function_exists( 'picky_layout_event_slot' ) ) {
            $event_slot = picky_layout_event_slot( $layout_id );
            if ( ! empty( $event_slot['active'] ) ) {
                $date = (string) $event_slot['date'];
                $time = (string) $event_slot['time'];
            }
        }

        $req_start = $this->picky_parse_time_to_minutes( $time );
        $req_end   = null;
        if ( $time_to !== '' ) {
            $req_end = $this->picky_parse_end_time_to_minutes( $time_to );
        }
        $dur_h = isset( $options['table_booked_time'] ) ? intval( $options['table_booked_time'] ) : 2;
        if ( $dur_h < 0 ) {
            $dur_h = 0;
        }
        if ( $req_start !== null && $req_end === null ) {
            $req_end = $req_start + $this->picky_default_duration_minutes( $date, $options, $dur_h );
        }
        if ( $req_start !== null && $req_end !== null && $req_end <= $req_start ) {
            $occupants = $this->picky_occupants_for_map( $layout_id, $date, $time );
            wp_send_json_success( array( 'tables' => array(), 'capacity' => array(), 'occupants' => $occupants ) );
        }
        if ( ! $this->picky_interval_within_work_windows( $date, $options, $req_start, $req_end ) ) {
            $occupants = $this->picky_occupants_for_map( $layout_id, $date, $time );
            wp_send_json_success( array( 'tables' => array(), 'capacity' => array(), 'occupants' => $occupants ) );
        }
        // Buffers apply to how far an *existing* booking blocks the table (matches time_exclude in class-public).
        // Do not shrink-shift the *new* request backward; that incorrectly rejects e.g. 11:30 after a 09:30–11:30 slot.
        $before_h = isset( $options['table_before_booked_time'] ) ? intval( $options['table_before_booked_time'] ) : 0;
        $after_h  = isset( $options['table_after_booked_time'] ) ? intval( $options['table_after_booked_time'] ) : 0;
        if ( $before_h < 0 ) {
            $before_h = 0;
        }
        if ( $after_h < 0 ) {
            $after_h = 0;
        }

        if ( $date === '' || $req_start === null || $req_end === null ) {
            wp_send_json_success( array( 'tables' => array() ) );
        }

        $booked_by_unique = $this->picky_booked_intervals_by_unique_for_date( $date, $options, $dur_h, $layout_id );

        $layout_id = $this->sanitize_post_absint( 'picky_layout_id', 0 );
        $json      = $this->picky_layout_elements_array( $layout_id );
        $req_people = $this->sanitize_post_absint( 'people', 0 );
        if ( $req_people <= 0 ) {
            $req_people = 1;
        }

        $capacity_by_unique = array();
        $tables = is_array( $json )
            ? $this->picky_tables_available_for_window( $json, $booked_by_unique, $req_start, $req_end, $options, $booking_mode, $before_h, $after_h, $req_people, $capacity_by_unique )
            : array();

        $occupants = $this->picky_occupants_for_map( $layout_id, $date, $time );

        wp_send_json_success( array( 'tables' => $tables, 'capacity' => $capacity_by_unique, 'occupants' => $occupants ) );
    }

    /**
     * AJAX: valid start times (some table free for default duration) and valid end times given a start (interval booking UX).
     */
    public function interval_slot_hints() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid nonce', 'pickybooking' ) ), 403 );
        }

        $options      = Info::get_settings();
        $booking_mode = isset( $options['booking_mode'] ) ? $options['booking_mode'] : 'exclusive';
        $date          = $this->sanitize_post_text_field( 'date' );
        $time_start    = $this->sanitize_post_text_field( 'time_start' );
        $hints_unique  = $this->sanitize_post_text_field( 'hints_unique' );
        $layout_id     = $this->sanitize_post_absint( 'picky_layout_id', 0 );
        $req_people    = $this->sanitize_post_absint( 'people', 0 );
        if ( $req_people <= 0 ) {
            $req_people = 1;
        }

        $dur_h = isset( $options['table_booked_time'] ) ? intval( $options['table_booked_time'] ) : 2;
        if ( $dur_h < 0 ) {
            $dur_h = 0;
        }

        $before_h = isset( $options['table_before_booked_time'] ) ? intval( $options['table_before_booked_time'] ) : 0;
        $after_h  = isset( $options['table_after_booked_time'] ) ? intval( $options['table_after_booked_time'] ) : 0;
        if ( $before_h < 0 ) {
            $before_h = 0;
        }
        if ( $after_h < 0 ) {
            $after_h = 0;
        }

        $json = $this->picky_layout_elements_array( $layout_id );
        if ( $date === '' || ! is_array( $json ) ) {
            wp_send_json_success( array( 'valid_starts' => array(), 'valid_ends' => array() ) );
        }

        $json_hints = $this->picky_layout_elements_for_slot_hints( $json, $hints_unique );
        if ( $hints_unique !== '' && empty( $json_hints ) ) {
            wp_send_json_success( array( 'valid_starts' => array(), 'valid_ends' => array() ) );
        }

        $booked_by_unique = $this->picky_booked_intervals_by_unique_for_date( $date, $options, $dur_h, $layout_id );
        $time_all         = $this->picky_allowed_slots_for_date( $date, $options );
        if ( empty( $time_all ) ) {
            wp_send_json_success( array( 'valid_starts' => array(), 'valid_ends' => array() ) );
        }

        $show_time_to = isset( $options['show_time_to'] ) && (string) $options['show_time_to'] === '1';
        $default_span = $this->picky_default_duration_minutes( $date, $options, $dur_h );
        // Optional override from selected services (minutes). Longer services must still fit work hours.
        $duration_min_req = $this->sanitize_post_absint( 'duration_min', 0 );
        if ( $duration_min_req > 0 ) {
            $default_span = max( $default_span, $duration_min_req );
        }
        // Multi-select: every chosen table must be free for the full window.
        // Single / "any table": keep legacy any-free behavior when no unique filter.
        $require_all = ( $hints_unique !== '' && count( $json_hints ) > 1 );
        $slot_ok     = function( $s, $e ) use ( $require_all, $json_hints, $booked_by_unique, $options, $booking_mode, $before_h, $after_h, $req_people, $date ) {
            if ( ! $this->picky_interval_within_work_windows( $date, $options, $s, $e ) ) {
                return false;
            }
            if ( $require_all ) {
                return $this->picky_all_hinted_tables_available_for_window( $json_hints, $booked_by_unique, $s, $e, $options, $booking_mode, $before_h, $after_h, $req_people );
            }
            return $this->picky_any_table_available_for_window( $json_hints, $booked_by_unique, $s, $e, $options, $booking_mode, $before_h, $after_h, $req_people );
        };

        // A start is valid only if the booking window fits work hours.
        // Non-interval mode (no end picker): window = start + default duration (same as send_book).
        // Interval mode: some end slot e > s must fit (and have a free table).
        $valid_starts = array();
        foreach ( $time_all as $slot_start ) {
            $s = $this->picky_parse_time_to_minutes( (string) $slot_start );
            if ( $s === null ) {
                continue;
            }

            if ( ! $show_time_to ) {
                $e = $s + $default_span;
                if ( $this->picky_slot_start_is_past_for_date( $date, $s ) ) {
                    continue;
                }
                if ( $slot_ok( $s, $e ) ) {
                    $valid_starts[] = (string) $slot_start;
                }
                continue;
            }

            if ( $this->picky_slot_start_is_past_for_date( $date, $s ) ) {
                continue;
            }

            $ok = false;
            foreach ( $time_all as $slot_end ) {
                // End-time parser maps "00:00" / "24:00" to 1440 so 23:30 → 00:00 stays bookable.
                $e = $this->picky_parse_end_time_to_minutes( (string) $slot_end );
                if ( $e === null || $e <= $s ) {
                    continue;
                }
                if ( $slot_ok( $s, $e ) ) {
                    $ok = true;
                    break;
                }
            }
            if ( $ok ) {
                $valid_starts[] = (string) $slot_start;
            }
        }

        $valid_ends = array();
        if ( $time_start !== '' ) {
            $s0 = $this->picky_parse_time_to_minutes( $time_start );
            if ( $s0 !== null ) {
                foreach ( $time_all as $slot ) {
                    // Treat end slots with 00:00/24:00 as midnight = 1440 (end of day).
                    $e = $this->picky_parse_end_time_to_minutes( (string) $slot );
                    if ( $e === null || $e <= $s0 ) {
                        continue;
                    }
                    if ( $slot_ok( $s0, $e ) ) {
                        $valid_ends[] = (string) $slot;
                    }
                }
            }
        }

        wp_send_json_success(
            array(
                'valid_starts' => $valid_starts,
                'valid_ends'   => $valid_ends,
            )
        );
    }
    
    /**
     * Read the layout-level services library.
     *
     * @param int $layout_id
     * @return array<int, array<string,mixed>>
     */
    private function picky_get_layout_library( $layout_id ) {
        $raw = get_post_meta( $layout_id, '_picky_layout_services_library', true );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Resolve a service from the layout-level library by SID.
     * Falls back to legacy per-element services[] (pre-library installs) so that
     * payments and snapshots still work when the layout was built before the
     * services library was introduced.
     *
     * @param int    $layout_id
     * @param string $sid
     * @return array<string,mixed>|null
     */
    private function picky_resolve_layout_service( $layout_id, $sid ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        $sid = (string) $sid;
        if ( $sid === '' ) {
            return null;
        }
        $library = $this->picky_get_layout_library( $layout_id );
        foreach ( $library as $svc ) {
            if ( is_array( $svc ) && ( isset( $svc['sid'] ) ? (string) $svc['sid'] : '' ) === $sid ) {
                return $this->picky_normalize_service_record( $svc );
            }
        }
        // Legacy fallback: scan element-level services[] across the layout JSON.
        $elements = $this->picky_layout_elements_array( $layout_id );
        if ( is_array( $elements ) ) {
            foreach ( $elements as $el ) {
                if ( ! is_array( $el ) || empty( $el['services'] ) || ! is_array( $el['services'] ) ) {
                    continue;
                }
                foreach ( $el['services'] as $svc ) {
                    if ( is_array( $svc ) && ( isset( $svc['sid'] ) ? (string) $svc['sid'] : '' ) === $sid ) {
                        return $this->picky_normalize_service_record( $svc );
                    }
                }
            }
        }
        // Last-resort fallback: if the configured layout id doesn't have the
        // service, search every other picky_layout post. This protects against
        // shortcode/layout id mismatches where the frontend is rendering one
        // layout but server-side reads a different one (e.g. settings default
        // overriding shortcode id). Logs a warning so admins can investigate.
        $other_layouts = get_posts( array(
            'post_type'      => 'picky_layout',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'exclude'        => array( (int) $layout_id ),
            'no_found_rows'  => true,
            'cache_results'  => true,
        ) );
        foreach ( $other_layouts as $other_id ) {
            $other_lib = $this->picky_get_layout_library( (int) $other_id );
            foreach ( $other_lib as $svc ) {
                if ( is_array( $svc ) && ( isset( $svc['sid'] ) ? (string) $svc['sid'] : '' ) === $sid ) {
                    return $this->picky_normalize_service_record( $svc );
                }
            }
        }
        return null;
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    /**
     * Coerce a raw service array from library/legacy storage into the canonical
     * shape used by the booking flow.
     *
     * @param array<string,mixed> $svc
     * @return array<string,mixed>
     */
    private function picky_normalize_service_record( $svc ) {
        $parent_sids = array();
        if ( isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ) {
            foreach ( $svc['parent_sids'] as $psid ) {
                $psid = (string) $psid;
                if ( $psid !== '' && ! in_array( $psid, $parent_sids, true ) ) {
                    $parent_sids[] = $psid;
                }
            }
        }
        return array(
            'sid'         => isset( $svc['sid'] ) ? (string) $svc['sid'] : '',
            'name'        => isset( $svc['name'] ) ? (string) $svc['name'] : '',
            'price'       => isset( $svc['price'] ) ? round( (float) $svc['price'], 2 ) : 0.0,
            'duration'    => isset( $svc['duration'] ) ? max( 0, (int) $svc['duration'] ) : 0,
            'description' => isset( $svc['description'] ) ? (string) $svc['description'] : '',
            'parent_sids' => $parent_sids,
        );
    }

    /**
     * Get allowed service IDs for an element from the layout.
     *
     * @param int    $layout_id
     * @param string $unique
     * @return array<int, string>
     */
    private function picky_get_element_allowed_sids( $layout_id, $unique ) {
        $unique = $this->picky_normalize_unique( $unique );
        if ( $unique === '' ) {
            return array();
        }
        $elements = $this->picky_layout_elements_array( $layout_id );
        if ( ! is_array( $elements ) ) {
            return array();
        }
        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( $this->picky_normalize_unique( isset( $el['unique'] ) ? (string) $el['unique'] : '' ) !== $unique ) {
                continue;
            }
            $sids = array();
            if ( isset( $el['service_ids'] ) && is_array( $el['service_ids'] ) ) {
                $sids = array_values( array_filter( array_map( 'sanitize_key', $el['service_ids'] ) ) );
            } else {
                // Legacy fallback: aggregate SIDs from per-element services
                $legacy = isset( $el['services'] ) && is_array( $el['services'] ) ? $el['services'] : array();
                foreach ( $legacy as $svc ) {
                    if ( is_array( $svc ) && ! empty( $svc['sid'] ) ) {
                        $sids[] = sanitize_key( (string) $svc['sid'] );
                    }
                }
                $sids = array_values( array_unique( $sids ) );
            }
            // Empty assignment → inherit full layout library (so creating services
            // in Services Library is enough to offer them on every bookable item).
            if ( empty( $sids ) ) {
                $sids = $this->picky_layout_library_sids( $layout_id );
            }
            return $sids;
        }
        return array();
    }

    /**
     * All service SIDs from a layout's services library.
     *
     * @param int $layout_id
     * @return array<int, string>
     */
    private function picky_layout_library_sids( $layout_id ) {
        $sids = array();
        foreach ( $this->picky_get_layout_library( $layout_id ) as $svc ) {
            if ( ! is_array( $svc ) || empty( $svc['sid'] ) ) {
                continue;
            }
            $sids[] = sanitize_key( (string) $svc['sid'] );
        }
        return array_values( array_unique( $sids ) );
    }

    /**
     * Resolve a service from a layout element by element unique + service ID.
     * Legacy helper kept for backward compatibility.
     *
     * @param int    $layout_id
     * @param string $unique     element unique key
     * @param string $service_id service sid (empty = default)
     * @return array<string,mixed>|null
     */
    private function picky_resolve_element_service( $layout_id, $unique, $service_id ) {
        $allowed = $this->picky_get_element_allowed_sids( $layout_id, $unique );
        if ( empty( $allowed ) ) {
            return array(
                'sid'         => '',
                'name'        => '',
                'price'       => 0.0,
                'duration'    => 0,
                'description' => '',
            );
        }
        $service_id = (string) $service_id;
        if ( $service_id !== '' && in_array( $service_id, $allowed, true ) ) {
            $svc = $this->picky_resolve_layout_service( $layout_id, $service_id );
            if ( $svc !== null ) {
                return $svc;
            }
        }
        // Fallback to first allowed
        $svc = $this->picky_resolve_layout_service( $layout_id, $allowed[0] );
        return $svc !== null ? $svc : null;
    }

    /**
     * Parse and sanitize picky_items[] from POST.
     * Returns array with keys: unique, id, service_ids (array), service_id (legacy string).
     *
     * @return array<int, array<string,mixed>>
     */
    private function picky_parse_posted_items() {
        if ( isset( $_POST['picky_nonce'] ) ) {
            if ( ! is_scalar( $_POST['picky_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_nonce'] ) ), 'picky_send_book_action' ) ) {
                return array();
            }
        } elseif ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'send_book' ) ) {
            return array();
        }
        if ( ! isset( $_POST['picky_items'] ) || ! is_array( $_POST['picky_items'] ) ) {
            return array();
        }
        $posted_items = map_deep( wp_unslash( $_POST['picky_items'] ), 'sanitize_text_field' );
        $items = array();
        foreach ( $posted_items as $raw_item ) {
            if ( ! is_array( $raw_item ) ) {
                continue;
            }
            $unique = isset( $raw_item['unique'] ) && is_scalar( $raw_item['unique'] )
                ? $this->picky_normalize_unique( sanitize_text_field( (string) $raw_item['unique'] ) )
                : '';
            if ( $unique === '' ) {
                continue;
            }
            $service_ids = array();
            if ( isset( $raw_item['service_ids'] ) && is_array( $raw_item['service_ids'] ) ) {
                foreach ( $raw_item['service_ids'] as $rsid ) {
                    $sid = is_scalar( $rsid ) ? sanitize_key( sanitize_text_field( (string) $rsid ) ) : '';
                    if ( $sid !== '' ) { $service_ids[] = $sid; }
                }
            }
            // Legacy fallback
            $legacy_sid = isset( $raw_item['service_id'] ) && is_scalar( $raw_item['service_id'] )
                ? sanitize_key( sanitize_text_field( (string) $raw_item['service_id'] ) )
                : '';
            if ( empty( $service_ids ) && $legacy_sid !== '' ) {
                $service_ids[] = $legacy_sid;
            }
            $items[] = array(
                'unique'      => $unique,
                'id'          => isset( $raw_item['id'] ) && is_scalar( $raw_item['id'] ) ? sanitize_text_field( (string) $raw_item['id'] ) : '',
                'display_id'  => isset( $raw_item['display_id'] ) && is_scalar( $raw_item['display_id'] ) ? sanitize_text_field( (string) $raw_item['display_id'] ) : '',
                'service_ids' => array_values( array_unique( $service_ids ) ),
                'service_id'  => $legacy_sid,
            );
        }
        return $items;
    }

    /**
     * Multi-item booking flow. Called from send_book() when picky_items[] is present.
     * Creates one parent picky_order + N child picky_booking posts.
     * Never returns — calls wp_send_json() and exits.
     *
     * @param array<string,mixed> $data     Common sanitized booking data (name, date, time, email, etc.)
     * @param array<string,mixed> $options  Plugin settings
     * @param array<int, array>   $items    Sanitized items from picky_parse_posted_items()
     */
    private function picky_send_book_multi( $data, $options, $items ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called only after send_book nonce verification
        $layout_id = $this->sanitize_post_absint( 'picky_layout_id', 0 );
        $item_label = $this->picky_item_label();

        $timeToMinutes = function( $t ) {
            if ( empty( $t ) ) { return null; }
            $parts = explode( ':', $t );
            if ( count( $parts ) < 2 ) { return null; }
            $h = intval( $parts[0] ); $m = intval( $parts[1] );
            if ( $h < 0 || $h > 23 || $m < 0 || $m > 59 ) { return null; }
            return ( $h * 60 ) + $m;
        };

        $req_start = $timeToMinutes( $data['time'] );
        if ( $req_start === null ) {
            wp_send_json( array( 'echo' => 0, 'error' => esc_html__( 'Invalid start time.', 'pickybooking' ) ) );
        }

        $before_h = isset( $options['table_before_booked_time'] ) ? absint( $options['table_before_booked_time'] ) : 0;
        $after_h  = isset( $options['table_after_booked_time'] ) ? absint( $options['table_after_booked_time'] ) : 0;

        // Resolve service + per-item end time
        $resolved_items = array();
        $order_total    = 0.0;
        $currency       = isset( $options['deposit_currency'] ) ? strtoupper( sanitize_text_field( (string) $options['deposit_currency'] ) ) : 'USD';

        // Diagnostic snapshot — written to _picky_order_debug meta to help troubleshoot
        // service-resolution issues without enabling WP_DEBUG. Truncated/sanitized.
        $debug_snapshot = array(
            'layout_id'     => $layout_id,
            'library_count' => count( $this->picky_get_layout_library( $layout_id ) ),
            'posted_items'  => array(),
            'resolved'      => array(),
            'options_src'   => $this->picky_payment_amount_source( $options ),
        );

        foreach ( $items as $raw ) {
            $unique      = $raw['unique'];
            $service_ids = isset( $raw['service_ids'] ) && is_array( $raw['service_ids'] ) ? $raw['service_ids'] : array();
            $legacy_sid  = isset( $raw['service_id'] ) ? (string) $raw['service_id'] : '';
            $item_id     = $raw['id'];
            $posted_did  = isset( $raw['display_id'] ) ? sanitize_text_field( (string) $raw['display_id'] ) : '';
            $display_id  = $this->picky_get_element_display_id(
                $layout_id,
                $unique,
                $posted_did !== '' ? $posted_did : $item_id
            );

            $allowed_sids = $this->picky_get_element_allowed_sids( $layout_id, $unique );

            $debug_snapshot['posted_items'][] = array(
                'unique'       => $unique,
                'id'           => $item_id,
                'display_id'   => $display_id,
                'service_ids'  => $service_ids,
                'legacy_sid'   => $legacy_sid,
                'allowed_sids' => $allowed_sids,
            );

            // Validate requested SIDs are allowed
            $resolved_sids = array();
            foreach ( $service_ids as $sid ) {
                if ( in_array( (string) $sid, $allowed_sids, true ) ) {
                    $resolved_sids[] = (string) $sid;
                }
            }
            // Tolerant fallback: if posted SIDs aren't in element's whitelist (e.g. layout not yet
            // re-saved after element reset), still resolve any SID that exists in the layout
            // services library. This avoids dropping prices when admin renamed/removed elements.
            if ( empty( $resolved_sids ) && ! empty( $service_ids ) ) {
                foreach ( $service_ids as $sid ) {
                    $svc_check = $this->picky_resolve_layout_service( $layout_id, (string) $sid );
                    if ( $svc_check !== null ) {
                        $resolved_sids[] = (string) $sid;
                    }
                }
            }
            // Legacy fallback
            if ( empty( $resolved_sids ) && $legacy_sid !== '' && in_array( $legacy_sid, $allowed_sids, true ) ) {
                $resolved_sids[] = $legacy_sid;
            }
            // If still empty but allowed SIDs exist, default to first allowed
            if ( empty( $resolved_sids ) && ! empty( $allowed_sids ) ) {
                $resolved_sids[] = $allowed_sids[0];
            }

            // Resolve services from library
            $services_snapshot = array();
            $total_price       = 0.0;
            $total_duration    = 0;
            foreach ( $resolved_sids as $sid ) {
                $svc = $this->picky_resolve_layout_service( $layout_id, $sid );
                if ( $svc !== null ) {
                    $services_snapshot[] = $svc;
                }
            }

            // Strict 2-level hierarchy enforcement on the server: drop add-ons
            // whose required parent isn't also in this item's selected services.
            // Without this guard, a tampered POST or stale frontend could submit
            // an add-on standalone and we'd charge for it without context.
            $selected_sid_set = array();
            foreach ( $services_snapshot as $svc ) {
                if ( ! empty( $svc['sid'] ) ) { $selected_sid_set[ (string) $svc['sid'] ] = true; }
            }
            $orphan_addons = array();
            $filtered_snapshot = array();
            foreach ( $services_snapshot as $svc ) {
                $parent_sids = isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ? $svc['parent_sids'] : array();
                if ( empty( $parent_sids ) ) {
                    $filtered_snapshot[] = $svc;
                    continue;
                }
                $has_parent = false;
                foreach ( $parent_sids as $psid ) {
                    if ( isset( $selected_sid_set[ (string) $psid ] ) ) { $has_parent = true; break; }
                }
                if ( $has_parent ) {
                    $filtered_snapshot[] = $svc;
                } else {
                    $orphan_addons[] = array(
                        'sid'         => isset( $svc['sid'] ) ? (string) $svc['sid'] : '',
                        'name'        => isset( $svc['name'] ) ? (string) $svc['name'] : '',
                        'parent_sids' => $parent_sids,
                    );
                }
            }
            $services_snapshot = $filtered_snapshot;

            // Recompute totals over the filtered (parent-validated) snapshot.
            // Duration: longest parent + sum of add-on durations (do NOT sum multiple
            // parents — e.g. Private Office 480 + Day Pass 480 must not become 960).
            $parent_durations = array();
            $addon_duration   = 0;
            foreach ( $services_snapshot as $svc ) {
                $total_price += round( (float) ( $svc['price'] ?? 0.0 ), 2 );
                $dur         = max( 0, (int) ( $svc['duration'] ?? 0 ) );
                $psids       = isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ? $svc['parent_sids'] : array();
                if ( empty( $psids ) ) {
                    $parent_durations[] = $dur;
                } else {
                    $addon_duration += $dur;
                }
            }
            $total_duration = ( ! empty( $parent_durations ) ? max( $parent_durations ) : 0 ) + $addon_duration;

            // Surface in debug for traceability when something looks off.
            if ( ! empty( $orphan_addons ) ) {
                $debug_snapshot['orphan_addons_dropped'][] = array(
                    'unique'  => $unique,
                    'dropped' => $orphan_addons,
                );
            }

            // If no services resolved but allowed_sids is empty, synthetic $0 service
            if ( empty( $services_snapshot ) && empty( $allowed_sids ) ) {
                $services_snapshot[] = array(
                    'sid'         => '',
                    'name'        => '',
                    'price'       => 0.0,
                    'duration'    => 0,
                    'description' => '',
                );
            }

            $debug_snapshot['resolved'][] = array(
                'unique'         => $unique,
                'resolved_sids'  => $resolved_sids,
                'snapshot_count' => count( $services_snapshot ),
                'total_price'    => $total_price,
                'total_duration' => $total_duration,
            );

            $duration_min = $total_duration > 0
                ? $total_duration
                : ( isset( $options['table_booked_time'] ) ? max( 1, intval( $options['table_booked_time'] ) ) * 60 : 120 );

            // Cap at end-of-day (1440 = 24:00): a service that would run past midnight
            // ends at midnight. Without this cap, item_end > 1440 produces a time_to
            // like "25:00". The availability-check parser (timeToMinutes) rejects h>23
            // and returns null → coerces to 0, making the booking invisible to future
            // overlap checks → silent double-booking window.
            $item_end = min( 1440, $req_start + $duration_min );
            $order_total += $total_price;

            // Single-service name/price for legacy compat
            $first_svc = $services_snapshot[0] ?? array( 'sid' => '', 'name' => '', 'price' => 0.0, 'duration' => 0 );

            // Validate work windows for this item's computed window
            if ( ! $this->picky_interval_within_work_windows( $data['date'], $options, $req_start, $item_end ) ) {
                wp_send_json( array(
                    'echo'  => 0,
                    'error' => sprintf(
                        /* translators: 1: item id, 2: service name */
                        esc_html__( 'Error. Item #%1$s (%2$s) is outside working hours.', 'pickybooking' ),
                        esc_html( $item_id ),
                        esc_html( (string) ( $first_svc['name'] ?? '' ) )
                    ),
                ) );
            }

            $resolved_items[] = array(
                'unique'            => $unique,
                'id'                => $item_id,
                'display_id'        => $display_id,
                'service_ids'       => $resolved_sids,
                'service_id'        => $first_svc['sid'],
                'service_name'      => (string) ( $first_svc['name'] ?? '' ),
                'service_price'     => round( (float) ( $first_svc['price'] ?? 0.0 ), 2 ),
                'item_total_price'  => round( $total_price, 2 ),
                'service_duration'  => $duration_min,
                'service_desc'      => (string) ( $first_svc['description'] ?? '' ),
                'services_snapshot' => $services_snapshot,
                'req_start'         => $req_start,
                'req_end'           => $item_end,
                'time_to'           => sprintf( '%02d:%02d', intdiv( $item_end, 60 ), $item_end % 60 ),
            );
        }

        if ( empty( $resolved_items ) ) {
            wp_send_json( array( 'echo' => 0, 'error' => esc_html__( 'No valid items to book.', 'pickybooking' ) ) );
        }

        // Per-item availability check + slot mutex acquisition
        $slot_locks = array();
        foreach ( $resolved_items as &$ri ) {
            $slot_key  = $data['date'] . '|' . $ri['unique'];
            $slot_lock = $this->picky_acquire_slot_mutex( $slot_key, 30 );
            if ( $slot_lock === false ) {
                // Release locks already acquired
                foreach ( $slot_locks as $l ) { $this->picky_release_slot_mutex( $l ); }
                wp_send_json( array(
                    'echo'  => 0,
                    'error' => esc_html__( 'Another booking for this slot is being processed. Please try again in a moment.', 'pickybooking' ),
                ) );
            }
            $slot_locks[] = $slot_lock;

            // Availability query for this item
            $existing = get_posts( array(
                'post_type'   => 'picky_booking',
                'post_status' => function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ),
                'numberposts' => -1,
                'meta_query'  => array(
                    'relation' => 'AND',
                    array( 'key' => '_picky_booking_date',   'value' => $data['date'],   'compare' => '=' ),
                    array( 'key' => '_picky_booking_unique', 'value' => $ri['unique'],   'compare' => '=' ),
                ),
            ) );

            $overlap = false;
            foreach ( $existing as $ep ) {
                if ( ! $this->picky_booking_blocks_slot( (int) $ep->ID ) ) {
                    continue;
                }
                $ex_start   = (int) $timeToMinutes( get_post_meta( $ep->ID, '_picky_booking_time', true ) );
                $ex_end_raw = get_post_meta( $ep->ID, '_picky_booking_time_to', true );
                $ex_end_parsed = $ex_end_raw !== '' && $ex_end_raw !== false
                    ? $timeToMinutes( $ex_end_raw )
                    : null;
                if ( $ex_end_raw === '' || $ex_end_raw === false ) {
                    $ex_end = $ex_start + 120;
                } elseif ( trim( (string) $ex_end_raw ) === '00:00' || trim( (string) $ex_end_raw ) === '24:00' ) {
                    $ex_end = 1440;
                } elseif ( $ex_end_parsed !== null ) {
                    $ex_end = (int) $ex_end_parsed;
                } else {
                    // Unparseable time_to (e.g. "25:00" from a pre-fix over-midnight booking).
                    // Treat conservatively as end-of-day so the slot remains blocked.
                    $ex_end = 1440;
                }
                $ex_block_start = $ex_start - ( $before_h * 60 );
                $ex_block_end   = $ex_end + ( $after_h * 60 );
                if ( $ri['req_start'] < $ex_block_end && $ex_block_start < $ri['req_end'] ) {
                    $overlap = true;
                    break;
                }
            }
            if ( $overlap ) {
                foreach ( $slot_locks as $l ) { $this->picky_release_slot_mutex( $l ); }
                wp_send_json( array(
                    'echo'  => 0,
                    'error' => sprintf(
                        /* translators: %s: item ID */
                        esc_html__( 'Error. Item #%s is already booked for the selected time.', 'pickybooking' ),
                        esc_html( $ri['id'] )
                    ),
                ) );
            }
        }
        unset( $ri );

        // Determine order status and payment (amount comes from selected services)
        $payment_required = $this->picky_is_payment_required( $options, $order_total );
        $order_status     = function_exists( 'picky_booking_status_canonical' ) ? picky_booking_status_canonical( $payment_required ? 'requests' : 'reserved' ) : ( $payment_required ? 'picky_requests' : 'picky_reserved' );

        // Create parent picky_order
        $order_title = sprintf(
            /* translators: 1: customer name, 2: date, 3: item count */
            __( 'Order by %1$s on %2$s (%3$d items)', 'pickybooking' ),
            $data['name'],
            $data['date'],
            count( $resolved_items )
        );
        $order_id = wp_insert_post( array(
            'post_title'  => sanitize_text_field( (string) $order_title ),
            'post_type'   => 'picky_order',
            'post_status' => $order_status,
        ) );
        if ( ! $order_id || is_wp_error( $order_id ) ) {
            foreach ( $slot_locks as $l ) { $this->picky_release_slot_mutex( $l ); }
            wp_send_json( array( 'echo' => 0, 'error' => esc_html__( 'Failed to create order.', 'pickybooking' ) ) );
        }

        // Save order meta
        update_post_meta( $order_id, '_picky_order_customer_name', sanitize_text_field( (string) $data['name'] ) );
        update_post_meta( $order_id, '_picky_order_email',         sanitize_email( (string) $data['email'] ) );
        update_post_meta( $order_id, '_picky_order_phone',         sanitize_text_field( (string) $data['phone'] ) );
        update_post_meta( $order_id, '_picky_order_date',          sanitize_text_field( (string) $data['date'] ) );
        update_post_meta( $order_id, '_picky_order_time',          sanitize_text_field( (string) $data['time'] ) );
        update_post_meta( $order_id, '_picky_order_layout_id',     $layout_id );
        update_post_meta( $order_id, '_picky_order_item_count',    count( $resolved_items ) );
        update_post_meta( $order_id, '_picky_order_total',         (string) round( $order_total, 2 ) );
        update_post_meta( $order_id, '_picky_order_currency',      $currency );
        $return_url_raw = $this->sanitize_post_text_field( 'picky_return_url', '' );
        $return_url     = esc_url_raw( $return_url_raw );
        if ( $return_url !== '' ) {
            update_post_meta( $order_id, '_picky_order_return_url', $return_url );
        }

        // Payment setup (against order total). Honors payment_amount_source.
        // Fixed / fallback amount is charged per booked item (table/room).
        $provider         = $payment_required ? $this->picky_payment_provider( $options ) : 'none';
        $payment_amount   = $this->picky_resolve_payment_amount( $options, $order_total );
        $item_count       = max( 1, count( $resolved_items ) );
        $pay_src          = $this->picky_payment_amount_source( $options );
        if ( $pay_src === 'fixed' || ( $pay_src === 'services_or_fixed' && $order_total <= 0 ) ) {
            $payment_amount = round( $payment_amount * $item_count, 2 );
            $charge_per_item = true;
        } else {
            $charge_per_item = false;
        }
        $payment_status   = $payment_required ? 'pending' : 'not_required';
        update_post_meta( $order_id, '_picky_order_payment_required', $payment_required ? '1' : '0' );
        update_post_meta( $order_id, '_picky_order_payment_status',   $payment_status );
        update_post_meta( $order_id, '_picky_order_payment_provider', $provider );
        update_post_meta( $order_id, '_picky_order_payment_amount',   (string) $payment_amount );
        update_post_meta( $order_id, '_picky_admin_seen',             '0' );

        // Debug snapshot (only if anything looks "off", to avoid bloating DB on healthy orders).
        $any_unresolved = false;
        foreach ( $debug_snapshot['resolved'] as $r ) {
            if ( $r['snapshot_count'] === 0 || $r['total_price'] <= 0 ) {
                $any_unresolved = true;
                break;
            }
        }
        if ( $any_unresolved || $order_total <= 0 ) {
            update_post_meta( $order_id, '_picky_order_debug', wp_json_encode( $debug_snapshot ) );
        }

        // Create child picky_booking for each item
        $first_booking_id = 0;
        foreach ( $resolved_items as $ri ) {
            $booking_title = sprintf(
                /* translators: 1: item label, 2: item id, 3: date, 4: time, 5: customer name */
                __( '%1$s #%2$s - %3$s | %4$s. By - %5$s', 'pickybooking' ),
                $item_label,
                $ri['id'],
                $data['date'],
                $data['time'],
                $data['name']
            );
            $booking_id = wp_insert_post( array(
                'post_title'  => sanitize_text_field( (string) $booking_title ),
                'post_type'   => 'picky_booking',
                'post_status' => $order_status,
                'post_author' => is_user_logged_in() ? (int) get_current_user_id() : 0,
            ) );
            if ( ! $booking_id || is_wp_error( $booking_id ) ) {
                continue;
            }
            if ( ! $first_booking_id ) { $first_booking_id = $booking_id; }

            // Core booking meta
            update_post_meta( $booking_id, '_picky_booking_id',         sanitize_text_field( $ri['id'] ) );
            update_post_meta( $booking_id, '_picky_booking_unique',      sanitize_text_field( $ri['unique'] ) );
            update_post_meta( $booking_id, '_picky_booking_date',        sanitize_text_field( (string) $data['date'] ) );
            update_post_meta( $booking_id, '_picky_booking_time',        sanitize_text_field( (string) $data['time'] ) );
            update_post_meta( $booking_id, '_picky_booking_time_to',     $ri['time_to'] );
            update_post_meta( $booking_id, '_picky_booking_name',        sanitize_text_field( (string) $data['name'] ) );
            update_post_meta( $booking_id, '_picky_booking_phone',       sanitize_text_field( (string) $data['phone'] ) );
            update_post_meta( $booking_id, '_picky_booking_people',      absint( $data['people'] ) );
            if ( ! empty( $data['email'] ) ) {
                update_post_meta( $booking_id, '_picky_booking_email', sanitize_email( (string) $data['email'] ) );
            }
            Booking_Avatar::maybe_store_user_id( $booking_id, isset( $data['email'] ) ? (string) $data['email'] : '' );
            // Order linkage
            update_post_meta( $booking_id, '_picky_booking_order_id', $order_id );
            // Service snapshot meta (new multi-service array)
            if ( ! empty( $ri['services_snapshot'] ) && is_array( $ri['services_snapshot'] ) ) {
                update_post_meta( $booking_id, '_picky_booking_services', wp_json_encode( $ri['services_snapshot'] ) );
            }
            // Legacy single-service meta (kept for backward compat)
            update_post_meta( $booking_id, '_picky_booking_service_id',       sanitize_text_field( $ri['service_id'] ) );
            update_post_meta( $booking_id, '_picky_booking_service_name',     sanitize_text_field( $ri['service_name'] ) );
            update_post_meta( $booking_id, '_picky_booking_service_price',    (string) $ri['service_price'] );
            update_post_meta( $booking_id, '_picky_booking_service_duration', (string) $ri['service_duration'] );
            update_post_meta( $booking_id, '_picky_booking_service_currency', $currency );
            // Payment: use the total of ALL services selected for this item so the
            // per-child amount matches the customer's actual item cost, not just
            // the first service price. The order total was already correct.
            $item_pay_amount = isset( $ri['item_total_price'] ) ? (float) $ri['item_total_price'] : (float) $ri['service_price'];
            update_post_meta( $booking_id, '_picky_payment_required', $payment_required ? '1' : '0' );
            update_post_meta( $booking_id, '_picky_payment_status',   $payment_status );
            update_post_meta( $booking_id, '_picky_payment_provider', $provider );
            update_post_meta( $booking_id, '_picky_payment_amount',   (string) round( $item_pay_amount, 2 ) );
            update_post_meta( $booking_id, '_picky_payment_currency', $currency );
            // Layout
            if ( $layout_id > 0 ) {
                update_post_meta( $booking_id, '_picky_reservation_layout_id', $layout_id );
            }
            if ( isset( $return_url ) && $return_url !== '' ) {
                update_post_meta( $booking_id, '_picky_return_url', $return_url );
            }
        }

        // Payment gateway (off order total)
        $payment_result = array( 'success' => true, 'error' => '', 'redirect_url' => '', 'order_id' => 0 );
        if ( $payment_required && $first_booking_id ) {
            $provider_name = $this->picky_payment_provider( $options );
            // Build a synthetic $data array for the existing payment helpers
            $pay_data = $data;
            $pay_data['total'] = $payment_amount;
            $pay_data['item_count'] = $item_count;
            $pay_data['charge_per_item'] = $charge_per_item ? 1 : 0;
            $ids_for_label = array();
            foreach ( $resolved_items as $ri_lab ) {
                $did = isset( $ri_lab['display_id'] ) && (string) $ri_lab['display_id'] !== ''
                    ? (string) $ri_lab['display_id']
                    : ( isset( $ri_lab['id'] ) ? (string) $ri_lab['id'] : '' );
                if ( $did !== '' ) {
                    $ids_for_label[] = $did;
                }
            }
            // Match popup title: "Book Tables #1, #testid"
            $pay_data['item_ids_label'] = ! empty( $ids_for_label )
                ? ( '#' . implode( ', #', $ids_for_label ) )
                : '';
            $pay_data['order_item_title'] = trim(
                sprintf(
                    /* translators: %s: plural bookable items label, e.g. "Tables" */
                    __( 'Book %s', 'pickybooking' ),
                    $this->picky_items_label()
                ) . ( $pay_data['item_ids_label'] !== '' ? ' ' . $pay_data['item_ids_label'] : '' )
            );
            // Slim copy for payment UIs (names + ids + services only).
            $pay_data['resolved_items'] = array();
            foreach ( $resolved_items as $ri_pay ) {
                $pay_data['resolved_items'][] = array(
                    'id'                => isset( $ri_pay['id'] ) ? $ri_pay['id'] : '',
                    'display_id'        => isset( $ri_pay['display_id'] ) ? $ri_pay['display_id'] : '',
                    'time_to'           => isset( $ri_pay['time_to'] ) ? $ri_pay['time_to'] : '',
                    'services_snapshot' => isset( $ri_pay['services_snapshot'] ) && is_array( $ri_pay['services_snapshot'] )
                        ? $ri_pay['services_snapshot']
                        : array(),
                );
            }
            // Prefer computed end time from first item when form time_to is empty.
            if ( ( ! isset( $pay_data['time_to'] ) || $pay_data['time_to'] === '' ) && ! empty( $resolved_items[0]['time_to'] ) ) {
                $pay_data['time_to'] = (string) $resolved_items[0]['time_to'];
            }

            if ( $provider_name === 'woocommerce' ) {
                $payment_result = $this->picky_create_woocommerce_order_for_booking( $first_booking_id, $pay_data, $options );
            } else {
                $payment_result = $this->picky_create_stripe_checkout_session_for_booking( $first_booking_id, $pay_data, $options );
            }
            if ( ! $payment_result['success'] ) {
                update_post_meta( $order_id, '_picky_order_payment_status', 'init_failed' );
                // Match single-item flow: roll back so a failed payment init cannot
                // leave 'requests' bookings that block the calendar while the customer
                // only saw an error (and admin may not notice orphaned Requested rows).
                $failed_children = get_posts( array(
                    'post_type'      => 'picky_booking',
                    'post_status'    => array_merge( function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ), array( 'draft' ) ),
                    'numberposts'    => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => '_picky_booking_order_id',
                            'value'   => $order_id,
                            'compare' => '=',
                        ),
                    ),
                ) );
                foreach ( $failed_children as $child_id ) {
                    $child_id = (int) $child_id;
                    if ( $child_id <= 0 ) {
                        continue;
                    }
                    update_post_meta( $child_id, '_picky_payment_status', 'init_failed' );
                    wp_trash_post( $child_id );
                }
                wp_trash_post( $order_id );
                foreach ( $slot_locks as $l ) { $this->picky_release_slot_mutex( $l ); }
                wp_send_json( array( 'echo' => 0, 'error' => $payment_result['error'] ) );
            }
            // Update order payment link
            update_post_meta( $order_id, '_picky_order_payment_status', 'pending' );
        }

        // Emails
        $site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $to         = ( ! empty( $options['form_email'] ) && is_email( $options['form_email'] ) ) ? $options['form_email'] : get_option( 'admin_email' );
        $server_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $from_email  = $server_host ? 'noreply@' . preg_replace( '/^www\./i', '', $server_host ) : sanitize_email( get_option( 'admin_email' ) );

        // Build items summary for email
        $items_html = '<ul>';
        foreach ( $resolved_items as $ri ) {
            $items_html .= '<li>' . esc_html( $item_label ) . ' #' . esc_html( $ri['id'] );
            $svc_names = array();
            $svc_total = 0.0;
            if ( ! empty( $ri['services_snapshot'] ) && is_array( $ri['services_snapshot'] ) ) {
                foreach ( $ri['services_snapshot'] as $ss ) {
                    if ( ! empty( $ss['name'] ) ) { $svc_names[] = (string) $ss['name']; }
                    $svc_total += round( (float) ( $ss['price'] ?? 0.0 ), 2 );
                }
            }
            if ( ! empty( $svc_names ) ) {
                $items_html .= ' — ' . esc_html( implode( ' + ', $svc_names ) );
                if ( $svc_total > 0 ) {
                    $items_html .= ' (' . esc_html( number_format( $svc_total, 2 ) . ' ' . $currency ) . ')';
                }
            } elseif ( $ri['service_name'] !== '' ) {
                $items_html .= ' — ' . esc_html( $ri['service_name'] );
                if ( $ri['service_price'] > 0 ) {
                    $items_html .= ' (' . esc_html( number_format( $ri['service_price'], 2 ) . ' ' . $currency ) . ')';
                }
            }
            $items_html .= ' ' . esc_html( $data['time'] ) . '–' . esc_html( $ri['time_to'] );
            $items_html .= '</li>';
        }
        $items_html .= '</ul>';

        $admin_content = '<h2>' . esc_html__( 'New Order', 'pickybooking' ) . '</h2>'
            /* translators: %s: value. */
            . '<p>' . sprintf( esc_html__( 'Customer: %s', 'pickybooking' ), esc_html( (string) $data['name'] ) ) . '</p>'
            /* translators: %s: value. */
            . '<p>' . sprintf( esc_html__( 'Date: %s', 'pickybooking' ), esc_html( (string) $data['date'] ) ) . '</p>'
            /* translators: %s: value. */
            . '<p>' . sprintf( esc_html__( 'Time: %s', 'pickybooking' ), esc_html( (string) $data['time'] ) ) . '</p>'
            . $items_html
            . '<p><strong>' . esc_html__( 'Total:', 'pickybooking' ) . ' ' . esc_html( number_format( $order_total, 2 ) . ' ' . $currency ) . '</strong></p>';

        $admin_headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $from_email . '>',
        );
        if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
            $admin_headers[] = 'Reply-To: ' . sanitize_text_field( (string) $data['name'] ) . ' <' . sanitize_email( (string) $data['email'] ) . '>';
        }
        $admin_subject = sprintf(
            /* translators: 1: customer name */
            __( 'New Booking Order by %s', 'pickybooking' ),
            $data['name']
        );
        wp_mail( sanitize_email( (string) $to ), $admin_subject, $admin_content, $admin_headers );

        if ( ! empty( $data['email'] ) && is_email( $data['email'] )
             && isset( $options['send_customer_email'] ) && $options['send_customer_email'] === '1' ) {
            $customer_content = '<h2>' . esc_html__( 'Your Booking Confirmation', 'pickybooking' ) . '</h2>'
                /* translators: %s: value. */
                . '<p>' . sprintf( esc_html__( 'Dear %s, thank you for your booking.', 'pickybooking' ), esc_html( (string) $data['name'] ) ) . '</p>'
                /* translators: %s: value. */
                . '<p>' . sprintf( esc_html__( 'Date: %s', 'pickybooking' ), esc_html( (string) $data['date'] ) ) . '</p>'
                . $items_html
                . '<p><strong>' . esc_html__( 'Total:', 'pickybooking' ) . ' ' . esc_html( number_format( $order_total, 2 ) . ' ' . $currency ) . '</strong></p>'
                . '<p>' . esc_html( $site_name ) . '</p>';
            $customer_headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $site_name . ' <' . $from_email . '>',
            );
            wp_mail(
                sanitize_email( (string) $data['email'] ),
                /* translators: %s: value. */
                sprintf( __( 'Booking Confirmation - %s', 'pickybooking' ), $site_name ),
                $customer_content,
                $customer_headers
            );
        }

        // Integration webhooks (fire once per order)
        $order_data_for_hooks = array_merge( $data, array(
            'order_id'    => $order_id,
            'order_total' => $order_total,
            'currency'    => $currency,
            'items'       => $resolved_items,
        ) );
        if ( class_exists( __NAMESPACE__ . '\\Hubspot_Integration' ) ) {
            Hubspot_Integration::maybe_sync_booking( $order_id, $order_data_for_hooks, $options, $item_label );
        }
        if ( class_exists( __NAMESPACE__ . '\\Mailchimp_Integration' ) ) {
            Mailchimp_Integration::maybe_sync_booking( $order_id, $order_data_for_hooks, $options, $item_label );
        }
        if ( class_exists( __NAMESPACE__ . '\\N8n_Integration' ) ) {
            N8n_Integration::maybe_send_booking_webhook( $order_id, $order_data_for_hooks, $options, $item_label );
        }
        if ( class_exists( __NAMESPACE__ . '\\Zapier_Integration' ) ) {
            Zapier_Integration::maybe_send_booking_webhook( $order_id, $order_data_for_hooks, $options, $item_label );
        }
        if ( class_exists( __NAMESPACE__ . '\\Make_Integration' ) ) {
            Make_Integration::maybe_send_booking_webhook( $order_id, $order_data_for_hooks, $options, $item_label );
        }
        if ( class_exists( __NAMESPACE__ . '\\Google_Calendar_Integration' ) ) {
            Google_Calendar_Integration::maybe_sync_booking( $order_id, $order_data_for_hooks, $options, $item_label );
        }

        foreach ( $slot_locks as $l ) { $this->picky_release_slot_mutex( $l ); }

        wp_send_json( array(
            'echo'              => $order_id,
            'order_id'          => $order_id,
            'payment_required'  => $payment_required ? 1 : 0,
            'payment_provider'  => $payment_required ? $provider : 'none',
            'redirect_url'      => $payment_required ? $payment_result['redirect_url'] : '',
        ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    public function send_book() {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Booking availability queries require meta lookups / exclude
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified at start of send_book; remaining POST reads sanitized
        // 1. Nonce Verification (CSRF Protection) - CRITICAL for CodeCanyon approval
        if ( ! isset( $_POST['picky_nonce'] ) || ! is_scalar( $_POST['picky_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_nonce'] ) ), 'picky_send_book_action' ) ) {
            wp_send_json(
                array(
                    'echo'  => 0,
                    'error' => esc_html__( 'Security check failed.', 'pickybooking' ),
                )
            );
        }
    
        // 2. Explicit sanitization per field (no raw $_POST assignment — reviewer / security requirement).
        $data = array();
        
        $data['id']      = $this->sanitize_post_text_field( 'picky_booking_id' );
        $data['unique']  = $this->picky_normalize_unique( $this->sanitize_post_text_field( 'picky_booking_unique' ) );
        $data['date']    = $this->sanitize_post_text_field( 'picky_booking_date' );
        $data['time']    = $this->sanitize_post_text_field( 'picky_booking_time' );
        $data['time_to'] = $this->sanitize_post_text_field( 'picky_booking_time_to' );
        $layout_id_event = $this->sanitize_post_absint( 'picky_layout_id', 0 );
        if ( $layout_id_event && function_exists( 'picky_layout_event_slot' ) ) {
            $event_slot = picky_layout_event_slot( $layout_id_event );
            if ( ! empty( $event_slot['active'] ) ) {
                $data['date'] = (string) $event_slot['date'];
                $data['time'] = (string) $event_slot['time'];
            }
        }
        $data['name']    = $this->sanitize_post_text_field( 'picky_booking_name' );
        $data['phone']   = $this->sanitize_post_text_field( 'picky_booking_phone' );
        $data['email']   = $this->sanitize_post_email_field( 'picky_booking_email' );
        $data['mob']     = ( '1' === $this->sanitize_post_text_field( 'picky_booking_mob', '0' ) ) ? '1' : '0';
        
        $data['seats']   = $this->sanitize_post_absint( 'picky_booking_seats', 0 );
        $data['people']  = $this->sanitize_post_absint( 'picky_booking_people', 1 );
        $data['max_p']   = $this->sanitize_post_absint( 'picky_booking_max_persons', 0 );
        $data['confirm'] = ( '1' === $this->sanitize_post_text_field( 'picky_booking_form_confirm', '' ) ) ? 1 : 0;
    
        $error_empty = 0;
        $error_exist = 0;
        $sms_message = "";
        $echo = array();
        // Defensive option read: every documented key is guaranteed present,
        // protecting later array dereferences from "Undefined index" / fatal
        // arithmetic on PHP 8+ when the option row is missing or partial.
        $options = Info::get_settings();

        $data = Booking_Avatar::apply_logged_in_identity( $data );
    
        $timeToMinutes = function($t) {
            if(empty($t)) return null;
            $parts = explode(':', $t);
            if(count($parts) < 2) return null;
            $h = intval($parts[0]); $m = intval($parts[1]);
            if($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
            return ($h * 60) + $m;
        };
        // End-of-window parser: "00:00" / "24:00" mean midnight = end of day.
        // Without this, slot 23:30 → 00:00 is rejected as "End time must be
        // after start time" and existing midnight-ending bookings are missed.
        $endTimeToMinutes = function($t) use ($timeToMinutes) {
            if ( ! is_string( $t ) || $t === '' ) return null;
            $trimmed = trim( $t );
            if ( $trimmed === '00:00' || $trimmed === '24:00' ) return 1440;
            return $timeToMinutes( $trimmed );
        };
    
        // 3. Validation Logic (Merged Mobile/Desktop flows to avoid duplication)
        $show_phone = isset( $options['show_phone'] ) && $options['show_phone'] === '1';
        if ( empty( $data['name'] ) || empty( $data['date'] ) || empty( $data['time'] )
            || ( $show_phone && empty( $data['phone'] ) ) ) {
            $error_empty = 1;
            $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. One or more fields is empty', 'pickybooking' ) );
        } elseif ( ! empty( $data['phone'] ) && ! preg_match( '/^[0-9\-\+]{7,15}$/', $data['phone'] ) ) {
            $error_empty = 1;
            $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. Phone number is wrong', 'pickybooking' ) );
        } elseif (!empty($data['email']) && !is_email($data['email'])) {
            $error_empty = 1;
            $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. E-mail is wrong', 'pickybooking' ) );
        } elseif (isset($options['form_confirm']) && !empty($options['form_confirm']) && $data['confirm'] === 0) {
            $error_empty = 1;
            $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. Please, confirm that you give rights to use your personal data', 'pickybooking' ) );
        }
    
        if ($error_empty === 0 && $data['time_to'] !== '') {
            $rs = $timeToMinutes($data['time']);
            $re = $endTimeToMinutes($data['time_to']);
            if ($rs !== null && $re !== null && $re <= $rs) {
                $error_empty = 1;
                $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. End time must be after start time', 'pickybooking' ) );
            }
        }

        // Server-side guard: booking interval must fit configured Work Days windows.
        if ( $error_empty === 0 ) {
            $rs = $timeToMinutes( $data['time'] );
            $re = ! empty( $data['time_to'] ) ? $endTimeToMinutes( $data['time_to'] ) : null;
            if ( $rs !== null && $re === null ) {
                $dur_h = isset($options['table_booked_time']) ? max(0, intval($options['table_booked_time'])) : 2;
                $re = $rs + $this->picky_default_duration_minutes( $data['date'], $options, $dur_h );
            }
            if ( ! $this->picky_interval_within_work_windows( $data['date'], $options, $rs, $re ) ) {
                $error_empty = 1;
                $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. Selected time is outside working hours', 'pickybooking' ) );
            }
        }

        // Multi-item booking path: when picky_items[] is posted, delegate to the
        // multi-item handler which creates a picky_order + N picky_booking children.
        // This never returns — wp_send_json() exits inside picky_send_book_multi().
        if ( $error_empty === 0 ) {
            $posted_items = $this->picky_parse_posted_items();
            if ( ! empty( $posted_items ) ) {
                $this->picky_send_book_multi( $data, $options, $posted_items );
                // picky_send_book_multi always calls wp_send_json → exit. Dead code:
                exit;
            }
        }

        // Per-slot mutex: serializes concurrent attempts to book the same item on
        // the same date. Without it, two requests can both pass the availability
        // check (TOCTOU) and both call wp_insert_post(), creating a double booking.
        // Slot key is intentionally coarse (date + unique id) so all overlapping
        // windows for that item collide; the availability check inside the lock
        // does the precise per-window decision.
        $slot_lock = false;
        $has_unique = ! empty( $data['unique'] );

        if ( $error_empty === 0 ) {
            if ( ( $data['mob'] === '0' || ( $data['mob'] === '1' && $has_unique ) ) && $has_unique && ! empty( $data['date'] ) ) {
                $slot_key  = (string) $data['date'] . '|' . (string) $data['unique'];
                $slot_lock = $this->picky_acquire_slot_mutex( $slot_key, 30 );
                if ( $slot_lock === false ) {
                    wp_send_json( array(
                        'echo'  => 0,
                        'error' => esc_html__( 'Another booking for this slot is being processed. Please try again in a moment.', 'pickybooking' ),
                    ) );
                }
            }
        }

        if ($error_empty === 0) {
            // 4. Availability Check
            if ($data['mob'] === '0' || ($data['mob'] === '1' && $has_unique)) {
                $req_start = $timeToMinutes($data['time']);
                $req_end = !empty($data['time_to']) ? $endTimeToMinutes($data['time_to']) : null;
    
                if ($req_start !== null && $req_end === null) {
                    $dur_h = isset($options['table_booked_time']) ? max(0, intval($options['table_booked_time'])) : 2;
                    $req_end = $req_start + $this->picky_default_duration_minutes( $data['date'], $options, $dur_h );
                }
    
                $before_h = isset($options['table_before_booked_time']) ? absint($options['table_before_booked_time']) : 0;
                $after_h  = isset($options['table_after_booked_time']) ? absint($options['table_after_booked_time']) : 0;
    
                $query = get_posts(array(
                    'post_type'   => 'picky_booking',
                    'post_status' => function_exists( 'picky_booking_status_occupying' ) ? picky_booking_status_occupying() : array( 'picky_reserved', 'picky_requests' ),
                    'numberposts' => -1,
                    'meta_query'  => array(
                        'relation' => 'AND',
                        array('key' => '_picky_booking_date', 'value' => $data['date'], 'compare' => '='),
                        array('key' => '_picky_booking_unique', 'value' => $data['unique'], 'compare' => '=')
                    )
                ));

                $booking_mode = $options['booking_mode'] ?? 'exclusive';

                // SECURITY: never trust max_persons from the form. Derive the
                // element's capacity from the server-side layout JSON before any
                // existing-booking branch so the first booking is capped too.
                $capacity = 0;
                if ( $booking_mode === 'capacity' ) {
                    $layout_id_for_capacity = $this->sanitize_post_absint( 'picky_layout_id', 0 );
                    $server_capacity = $this->picky_get_element_capacity( $layout_id_for_capacity, $data['unique'] );
                    if ( $server_capacity === null || $server_capacity <= 0 ) {
                        $booking_mode = 'exclusive';
                    } else {
                        $capacity = $server_capacity;
                        if ( $data['people'] > $capacity ) {
                            $error_exist = 1;
                            $echo = array(
                                'echo'  => 0,
                                'error' => sprintf(
                                    /* translators: %d: number of free capacity spots left */
                                    esc_html__( 'Error. Not enough capacity for selected time. Free spots left: %d', 'pickybooking' ),
                                    max( 0, $capacity )
                                ),
                            );
                        }
                    }
                }

                if ($error_exist === 0 && !empty($query)) {
                    $used                   = 0;
                    $time_limited_until_min = null;

                    foreach ($query as $post) {
                        if ( ! $this->picky_booking_blocks_slot( (int) $post->ID ) ) {
                            continue;
                        }
                        $ex_start = $timeToMinutes(get_post_meta($post->ID, '_picky_booking_time', true));
                        $ex_end_raw = get_post_meta($post->ID, '_picky_booking_time_to', true);
                        $fallback_dur_h = isset($options['table_booked_time']) ? max(0, intval($options['table_booked_time'])) : 2;
                        $ex_end = $ex_end_raw ? $endTimeToMinutes($ex_end_raw) : ($ex_start + $this->picky_default_duration_minutes( $data['date'], $options, $fallback_dur_h ));

                        $ex_block_start = $ex_start - ( $before_h * 60 );
                        $ex_block_end   = $ex_end + ( $after_h * 60 );
                        if ( $req_start < $ex_block_end && $ex_block_start < $req_end ) {
                            if ($booking_mode === 'capacity' && $capacity > 0) {
                                $used += absint(get_post_meta($post->ID, '_picky_booking_people', true) ?: 1);
                            } elseif ( $ex_block_start > $req_start ) {
                                // Overlap is caused by req_end extending past a FUTURE booking.
                                // Auto-trim: allow booking up to the start of the next reservation.
                                if ( $time_limited_until_min === null || $ex_block_start < $time_limited_until_min ) {
                                    $time_limited_until_min = $ex_block_start;
                                }
                            } else {
                                // An existing booking already occupies this start time — truly blocked.
                                $error_exist = 1;
                                $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. On this date and time table already booked', 'pickybooking' ) );
                                break;
                            }
                        }
                    }

                    // Apply the auto-trim: shorten this booking to end before the next reservation.
                    if ( $error_exist === 0 && $time_limited_until_min !== null ) {
                        if ( $time_limited_until_min <= $req_start ) {
                            $error_exist = 1;
                            $echo = array( 'echo' => 0, 'error' => esc_html__( 'Error. On this date and time table already booked', 'pickybooking' ) );
                        } else {
                            $req_end           = $time_limited_until_min;
                            $data['time_to']   = sprintf( '%02d:%02d', intdiv( $time_limited_until_min, 60 ), $time_limited_until_min % 60 );
                            $booking_time_limit_until = $data['time_to'];
                        }
                    }

                    if ($error_exist === 0 && $booking_mode === 'capacity' && $capacity > 0) {
                        $free_spots = max( 0, $capacity - $used );
                        if ($data['people'] > $free_spots) {
                            $error_exist = 1;
                            $echo = array(
                                'echo'  => 0,
                                'error' => sprintf(
                                    /* translators: %d: number of free capacity spots left */
                                    esc_html__( 'Error. Not enough capacity for selected time. Free spots left: %d', 'pickybooking' ),
                                    $free_spots
                                ),
                            );
                        }
                    }
                }
            }
        }
    
        if ($error_exist === 0 && $error_empty === 0) {
            $item_label = $this->picky_item_label();
            // 5. Prepare Content (Using esc_html for safety)
            $sms_content = sprintf(
                /* translators: 1: reservable item label, 2: item ID, 3: reservation date, 4: reservation time, 5: customer name, 6: people count */
                __( '%1$s: %2$s. Date: %3$s. Time: %4$s. Name: %5$s. People: %6$s.', 'pickybooking' ),
                $item_label,
                $data['id'],
                $data['date'],
                $data['time'],
                $data['name'],
                $data['people']
            );
            
            $content = "";
            if(isset($data['id'])){
                /* translators: Placeholders are dynamic values. */
                $content .= '<p>' . sprintf( esc_html__( '%1$s Number: %2$s', 'pickybooking' ), esc_html( $item_label ), esc_html( (string)$data['id'] ) ) . '</p>';
            }
            /* translators: %s: value. */
            $content .= '<p>' . sprintf( esc_html__( 'Date: %s', 'pickybooking' ), esc_html( (string)$data['date'] ) ) . '</p>';
            /* translators: %s: value. */
            $content .= '<p>' . sprintf( esc_html__( 'Time: %s', 'pickybooking' ), esc_html( (string)$data['time'] ) ) . '</p>';
            if(isset($data['time_to'])){
                /* translators: %s: value. */
                $content .= '<p>' . sprintf( esc_html__( 'Reserved Until: %s', 'pickybooking' ), esc_html( (string)$data['time_to'] ) ) . '</p>';
            }
            if ( ! empty( $booking_time_limit_until ) ) {
                $content .= '<p style="color:#b45000;font-weight:bold;">' . sprintf(
                    /* translators: 1: item label, 2: time the next booking begins */
                    esc_html__( 'Please note: the next reservation for this %1$s starts at %2$s. Your visit must end by that time.', 'pickybooking' ),
                    esc_html( $item_label ),
                    esc_html( $booking_time_limit_until )
                ) . '</p>';
            }
            /* translators: %s: value. */
            $content .= '<p>' . sprintf( esc_html__( 'Name: %s', 'pickybooking' ), esc_html( (string)$data['name'] ) ) . '</p>';
            if ( ! empty( $data['phone'] ) ) {
                /* translators: %s: value. */
                $content .= '<p>' . sprintf( esc_html__( 'Phone Number: %s', 'pickybooking' ), esc_html( (string)$data['phone'] ) ) . '</p>';
            }
            if(isset($data['email'])){
                /* translators: %s: value. */
                $content .= '<p>' . sprintf( esc_html__( 'E-mail: %s', 'pickybooking' ), esc_html( (string)$data['email'] ) ) . '</p>';
            }
            /* translators: %s: value. */
            $content .= '<p>' . sprintf( esc_html__( 'Max Seats: %s', 'pickybooking' ), absint( $data['seats'] ) ) . '</p>';
            /* translators: %s: value. */
            $content .= '<p>' . sprintf( esc_html__( 'People Amount: %s', 'pickybooking' ), absint( $data['people'] ) ) . '</p>';
    
            // Determine Post Title
            if($data['mob'] === '1' && !$has_unique) {
                $title = sprintf(
                    /* translators: 1: reservation date, 2: reservation time, 3: customer name */
                    __( 'Request on - %1$s | %2$s. By - %3$s', 'pickybooking' ),
                    $data['date'],
                    $data['time'],
                    $data['name']
                );
            } else {
                $title = sprintf(
                    /* translators: 1: reservable item label, 2: item ID, 3: reservation date, 4: reservation time, 5: customer name */
                    __( '%1$s #%2$s - %3$s | %4$s. By - %5$s', 'pickybooking' ),
                    $item_label,
                    $data['id'],
                    $data['date'],
                    $data['time'],
                    $data['name']
                );
            }
    
            // 6. Insert Post
            $payment_required = $this->picky_is_payment_required( $options );

            $post_id = wp_insert_post(array(
                'post_title'    => sanitize_text_field((string)$title),
                'post_type'     => 'picky_booking',
                'post_status'   => function_exists( 'picky_booking_status_canonical' ) ? picky_booking_status_canonical( $payment_required ? 'requests' : ( $has_unique ? 'reserved' : 'requests' ) ) : ( $payment_required ? 'picky_requests' : ( $has_unique ? 'picky_reserved' : 'picky_requests' ) ),
                'post_author'   => is_user_logged_in() ? (int) get_current_user_id() : 0,
            ));
    
            if($post_id) {
                // 7. Save Meta Data (Late Sanitization)
                update_post_meta($post_id, '_picky_booking_id', sanitize_text_field((string)$data['id']));
                update_post_meta($post_id, '_picky_booking_unique', sanitize_text_field((string)$data['unique']));
                update_post_meta($post_id, '_picky_booking_date', sanitize_text_field((string)$data['date']));
                update_post_meta($post_id, '_picky_booking_time', sanitize_text_field((string)$data['time']));
                if(!empty($data['time_to'])) { update_post_meta($post_id, '_picky_booking_time_to', sanitize_text_field((string)$data['time_to'])); }
                update_post_meta($post_id, '_picky_booking_seats', absint($data['seats']));
                update_post_meta($post_id, '_picky_booking_people', absint($data['people']));
                update_post_meta($post_id, '_picky_booking_name', sanitize_text_field((string)$data['name']));
                update_post_meta($post_id, '_picky_booking_phone', sanitize_text_field((string)$data['phone']));
                if(!empty($data['email'])) { update_post_meta($post_id, '_picky_booking_email', sanitize_email($data['email'])); }
                Booking_Avatar::maybe_store_user_id( $post_id, ! empty( $data['email'] ) ? (string) $data['email'] : '' );
                update_post_meta($post_id, '_picky_payment_required', $payment_required ? '1' : '0');
                update_post_meta($post_id, '_picky_payment_status', $payment_required ? 'pending' : 'not_required');
                update_post_meta($post_id, '_picky_payment_provider', $payment_required ? $this->picky_payment_provider( $options ) : 'none');
                // Provisional amount before services are resolved below; we re-compute
                // with the resolver after svc_total is known.
                $payment_amount = $this->picky_resolve_payment_amount( $options, null );
                $payment_currency = isset( $options['deposit_currency'] ) ? strtoupper( sanitize_text_field( (string) $options['deposit_currency'] ) ) : '';
                if ( $payment_currency === '' ) {
                    $payment_currency = 'USD';
                }
                update_post_meta( $post_id, '_picky_payment_amount', $payment_amount > 0 ? (string) $payment_amount : '0' );
                update_post_meta( $post_id, '_picky_payment_currency', $payment_currency );
                update_post_meta( $post_id, '_picky_admin_seen', '0' );
                $return_url_raw = $this->sanitize_post_text_field( 'picky_return_url', '' );
                $return_url     = esc_url_raw( $return_url_raw );
                if ( $return_url !== '' ) {
                    update_post_meta( $post_id, '_picky_return_url', $return_url );
                }
                // Save which layout this booking belongs to
                $layout_id_raw = $this->sanitize_post_absint( 'picky_layout_id', 0 );
                if ( $layout_id_raw > 0 && get_post_type( $layout_id_raw ) === 'picky_layout' ) {
                    update_post_meta( $post_id, '_picky_reservation_layout_id', $layout_id_raw );
                }

                // Resolve selected services for single-item booking (from bottom panel)
                $services_snapshot = array();
                $svc_total         = 0.0;
                $svc_duration      = 0;
                $first_svc         = array( 'sid' => '', 'name' => '', 'price' => 0.0, 'duration' => 0 );
                if ( $layout_id_raw > 0 && ! empty( $data['unique'] ) ) {
                    $allowed_sids = $this->picky_get_element_allowed_sids( $layout_id_raw, $data['unique'] );
                    $posted_sids = array();
                    if ( isset( $_POST['service_ids'] ) && is_array( $_POST['service_ids'] ) ) {
                        $posted_service_ids = map_deep( wp_unslash( $_POST['service_ids'] ), 'sanitize_text_field' );
                        foreach ( $posted_service_ids as $rsid ) {
                            $sid = is_scalar( $rsid ) ? sanitize_key( (string) $rsid ) : '';
                            if ( $sid !== '' && in_array( $sid, $allowed_sids, true ) ) {
                                $posted_sids[] = $sid;
                            }
                        }
                    }
                    // Legacy fallback
                    $legacy_sid = isset( $_POST['service_id'] ) && is_scalar( $_POST['service_id'] )
                        ? sanitize_key( sanitize_text_field( wp_unslash( (string) $_POST['service_id'] ) ) )
                        : '';
                    if ( empty( $posted_sids ) && $legacy_sid !== '' && in_array( $legacy_sid, $allowed_sids, true ) ) {
                        $posted_sids[] = $legacy_sid;
                    }
                    if ( empty( $posted_sids ) && ! empty( $allowed_sids ) ) {
                        $posted_sids[] = $allowed_sids[0];
                    }
                    $parent_durs = array();
                    $addon_durs  = 0;
                    foreach ( $posted_sids as $sid ) {
                        $svc = $this->picky_resolve_layout_service( $layout_id_raw, $sid );
                        if ( $svc !== null ) {
                            $services_snapshot[] = $svc;
                            $svc_total += round( (float) ( $svc['price'] ?? 0.0 ), 2 );
                            $dur   = max( 0, (int) ( $svc['duration'] ?? 0 ) );
                            $psids = isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ? $svc['parent_sids'] : array();
                            if ( empty( $psids ) ) {
                                $parent_durs[] = $dur;
                            } else {
                                $addon_durs += $dur;
                            }
                        }
                    }
                    $svc_duration = ( ! empty( $parent_durs ) ? max( $parent_durs ) : 0 ) + $addon_durs;
                    if ( ! empty( $services_snapshot ) ) {
                        update_post_meta( $post_id, '_picky_booking_services', wp_json_encode( $services_snapshot ) );
                    }
                    $first_svc = $services_snapshot[0] ?? array( 'sid' => '', 'name' => '', 'price' => 0.0, 'duration' => 0 );
                    update_post_meta( $post_id, '_picky_booking_service_id', sanitize_text_field( $first_svc['sid'] ) );
                    update_post_meta( $post_id, '_picky_booking_service_name', sanitize_text_field( $first_svc['name'] ) );
                    update_post_meta( $post_id, '_picky_booking_service_price', (string) round( (float) ( $first_svc['price'] ?? 0.0 ), 2 ) );
                    update_post_meta( $post_id, '_picky_booking_service_duration', (string) ( $svc_duration > 0 ? $svc_duration : max( 0, (int) ( $first_svc['duration'] ?? 0 ) ) ) );
                    update_post_meta( $post_id, '_picky_booking_service_currency', $payment_currency );
                    // Authoritative amount honors payment_amount_source.
                    $resolved_amount = $this->picky_resolve_payment_amount( $options, $svc_total );
                    update_post_meta( $post_id, '_picky_payment_amount', (string) $resolved_amount );
                }

                // Re-evaluate payment requirement using the actual service total
                $payment_required = $this->picky_is_payment_required( $options, $svc_total );
                if ( $payment_required ) {
                    wp_update_post( array( 'ID' => $post_id, 'post_status' => 'picky_requests' ) );
                    update_post_meta( $post_id, '_picky_payment_required', '1' );
                    update_post_meta( $post_id, '_picky_payment_status', 'pending' );
                    update_post_meta( $post_id, '_picky_payment_provider', $this->picky_payment_provider( $options ) );
                    $data['total'] = $this->picky_resolve_payment_amount( $options, $svc_total );
                    update_post_meta( $post_id, '_picky_payment_amount', (string) $data['total'] );
                }

                $payment_result = array(
                    'success'      => false,
                    'error'        => '',
                    'redirect_url' => '',
                    'order_id'     => 0,
                );
                if ( $payment_required ) {
                    $provider = $this->picky_payment_provider( $options );
                    if ( ! empty( $services_snapshot ) ) {
                        $data['services_snapshot'] = $services_snapshot;
                        $data['service_name']      = isset( $first_svc['name'] ) ? (string) $first_svc['name'] : '';
                    }
                    if ( ( ! isset( $data['time_to'] ) || $data['time_to'] === '' ) && $svc_duration > 0 && ! empty( $data['time'] ) ) {
                        $start_parts = array_map( 'intval', explode( ':', (string) $data['time'] ) );
                        $start_min   = ( isset( $start_parts[0] ) ? $start_parts[0] : 0 ) * 60 + ( isset( $start_parts[1] ) ? $start_parts[1] : 0 );
                        $end_min     = min( 1440, $start_min + $svc_duration );
                        $data['time_to'] = sprintf( '%02d:%02d', intdiv( $end_min, 60 ), $end_min % 60 );
                    }
                    if ( empty( $data['order_item_title'] ) && ! empty( $data['id'] ) ) {
                        $data['order_item_title'] = trim(
                            sprintf(
                                /* translators: %s: plural bookable items label, e.g. "Tables" */
                                __( 'Book %s', 'pickybooking' ),
                                $this->picky_items_label()
                            ) . ' #' . sanitize_text_field( (string) $data['id'] )
                        );
                    }
                    if ( $provider === 'woocommerce' ) {
                        $payment_result = $this->picky_create_woocommerce_order_for_booking( $post_id, $data, $options );
                    } else {
                        $payment_result = $this->picky_create_stripe_checkout_session_for_booking( $post_id, $data, $options );
                    }
                    if ( ! $payment_result['success'] ) {
                        update_post_meta( $post_id, '_picky_payment_status', 'init_failed' );
                        wp_trash_post( $post_id );
                        $echo = array(
                            'echo'  => 0,
                            'error' => $payment_result['error'],
                        );
                        $this->picky_release_slot_mutex( $slot_lock );
                        wp_send_json( $echo );
                    }
                }

                // 8. SMS (Vonage) is Pro-only — omitted from Lite (no third-party SMS runtime).
                $sms_message = '';

                // 9. Send Email to Admin/Owner
                $to = (!empty($options['form_email']) && is_email($options['form_email'])) ? $options['form_email'] : get_option('admin_email');
                $admin_subject = sprintf(
                    /* translators: 1: customer name, 2: table ID */
                    __( 'New Booking: "%1$s" reserved table no. %2$s', 'pickybooking' ),
                    $data['name'],
                    $data['id']
                );
                $site_name = wp_specialchars_decode( get_bloginfo('name'), ENT_QUOTES );
                $admin_email = sanitize_email( get_option('admin_email') );
                // Use a domain-based sender to avoid DMARC rejections (e.g. Gmail's p=reject policy).
                $server_host = wp_parse_url( home_url(), PHP_URL_HOST );
                if ( $server_host ) {
                    $from_email = 'noreply@' . preg_replace( '/^www\./i', '', $server_host );
                } else {
                    $from_email = $admin_email;
                }
                $admin_headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . $site_name . ' <' . $from_email . '>'
                );
                if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
                    $admin_headers[] = 'Reply-To: ' . sanitize_text_field( (string)$data['name'] ) . ' <' . sanitize_email( (string)$data['email'] ) . '>';
                }

                $admin_content = '<h2>' . esc_html__( 'New Table Reservation', 'pickybooking' ) . '</h2>' . $content;
                $admin_mail_sent = wp_mail( sanitize_email( (string)$to ), $admin_subject, $admin_content, $admin_headers );

                // 9b. Send Confirmation Email to Customer
                $customer_mail_sent = true;
                if ( ! empty( $data['email'] ) && is_email( $data['email'] )
                     && isset( $options['send_customer_email'] ) && $options['send_customer_email'] === '1' ) {
                    if ( $has_unique ) {
                        $customer_subject = sprintf(
                            /* translators: 1: site name */
                            __( 'Your Reservation Confirmation - %1$s', 'pickybooking' ),
                            $site_name
                        );
                        $customer_content = '<h2>' . esc_html__( 'Reservation Confirmed!', 'pickybooking' ) . '</h2>';
                    } else {
                        $customer_subject = sprintf(
                            /* translators: 1: site name */
                            __( 'Your Reservation Request - %1$s', 'pickybooking' ),
                            $site_name
                        );
                        $customer_content = '<h2>' . esc_html__( 'Reservation Request Received', 'pickybooking' ) . '</h2>';
                    }
                    /* translators: %s: value. */
                    $customer_content .= '<p>' . sprintf( esc_html__( 'Dear %s,', 'pickybooking' ), esc_html( (string)$data['name'] ) ) . '</p>';
                    $customer_content .= '<p>' . esc_html__( 'Thank you for your reservation. Here are your booking details:', 'pickybooking' ) . '</p>';
                    $customer_content .= $content;
                    $customer_content .= '<p>' . esc_html__( 'We look forward to seeing you!', 'pickybooking' ) . '</p>';
                    $customer_content .= '<p>' . esc_html( (string)$site_name ) . '</p>';
                    $customer_headers = array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: ' . $site_name . ' <' . $from_email . '>',
                        'Reply-To: ' . $site_name . ' <' . $from_email . '>'
                    );
                    $customer_mail_sent = wp_mail( sanitize_email( (string)$data['email'] ), $customer_subject, $customer_content, $customer_headers );
                }

                if ( class_exists( __NAMESPACE__ . '\\Hubspot_Integration' ) ) {
                    Hubspot_Integration::maybe_sync_booking( (int) $post_id, $data, $options, (string) $item_label );
                }
                if ( class_exists( __NAMESPACE__ . '\\Mailchimp_Integration' ) ) {
                    Mailchimp_Integration::maybe_sync_booking( (int) $post_id, $data, $options, (string) $item_label );
                }
                if ( class_exists( __NAMESPACE__ . '\\N8n_Integration' ) ) {
                    N8n_Integration::maybe_send_booking_webhook( (int) $post_id, $data, $options, (string) $item_label );
                }
                if ( class_exists( __NAMESPACE__ . '\\Zapier_Integration' ) ) {
                    Zapier_Integration::maybe_send_booking_webhook( (int) $post_id, $data, $options, (string) $item_label );
                }
                if ( class_exists( __NAMESPACE__ . '\\Make_Integration' ) ) {
                    Make_Integration::maybe_send_booking_webhook( (int) $post_id, $data, $options, (string) $item_label );
                }
                if ( class_exists( __NAMESPACE__ . '\\Google_Calendar_Integration' ) ) {
                    Google_Calendar_Integration::maybe_sync_booking( (int) $post_id, $data, $options, (string) $item_label );
                }

                $echo = array(
                    'echo'         => $post_id,
                    'test'         => $sms_content,
                    'nexmo'        => $sms_message,
                    'payment_required' => $payment_required ? 1 : 0,
                    'payment_provider' => $payment_required ? $this->picky_payment_provider( $options ) : 'none',
                    'redirect_url' => $payment_required ? $payment_result['redirect_url'] : '',
                    'order_id'     => $payment_required ? absint( $payment_result['order_id'] ) : 0,
                    'mail_warning' => ( ! $admin_mail_sent || ! $customer_mail_sent )
                        ? esc_html__( 'Reservation was saved, but email delivery failed. Please check WordPress mail/SMTP settings.', 'pickybooking' )
                        : '',
                    'time_limit_until' => ! empty( $booking_time_limit_until ) ? esc_html( $booking_time_limit_until ) : '',
                );
            } else {
                $echo = array( 'echo' => 0, 'error' => esc_html__( 'Not added', 'pickybooking' ) );
            }
        }

        // Always release the per-slot mutex (if acquired), regardless of whether
        // the booking succeeded, was rejected by availability/capacity, or failed
        // at wp_insert_post(). wp_send_json terminates the request after this.
        $this->picky_release_slot_mutex( $slot_lock );

        wp_send_json( $echo );
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    }

    /**
     * Propagate a payment outcome from one (typically the first) child booking to
     * its parent picky_order and ALL sibling child bookings so a multi-item order
     * doesn't end up half-reserved/half-requests after a single Stripe checkout.
     *
     * @param int    $child_booking_id Any child picky_booking that participated in the order.
     * @param string $outcome          One of: 'paid' | 'cancelled' | 'not_paid'.
     * @return void
     */
    private function picky_finalize_order_after_payment( $child_booking_id, $outcome ) {
        // Pro-only payment finalization — no-op in Lite.
        unset( $child_booking_id, $outcome );
    }

    /**
     * Handle direct Stripe success/cancel redirects.
     *
     * @return void
     */
    public function picky_handle_stripe_return() {
        // Pro-only Stripe return handler — omitted in Lite.
    }

    /**
     * WooCommerce callback: reservation is paid.
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public function picky_on_woocommerce_order_paid( $order_id ) {
        unset( $order_id );
    }

    /**
     * WooCommerce callback: payment failed/cancelled -> release slot.
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public function picky_on_woocommerce_order_unpaid( $order_id ) {
        unset( $order_id );
    }

    /**
     * Render the view using MVC pattern.
     */
    public function render() {
        // Model
        $settings = $this->settings;

    }
}
