<?php

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin
{
    private $plugin_slug;
    private $version;
    private $option_name;
    private $settings;
    private $settings_group;

    public function __construct($plugin_slug, $version, $option_name) {
        $this->plugin_slug = $plugin_slug;
        $this->version = $version;
        $this->option_name = $option_name;
        if ( is_admin() ) {
            Color_Defaults_Migration::apply();
        }
        $raw = get_option($this->option_name);
        // Runtime fallback key.
        // when the new key is missing (sites that didn't re-activate after rebrand).
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
     * Inline help control (native details/summary for keyboard + mobile).
     *
     * @param string $uid_suffix Unique fragment for stable id.
     * @param string $text     Plain text; newlines become br.
     */
    private function picky_settings_help_details( $uid_suffix, $text ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }
        $uid  = 'picky-help-' . sanitize_html_class( (string) $uid_suffix );
        $body = wp_kses(
            nl2br( esc_html( $text ) ),
            array(
                'br' => array(),
            )
        );
        return '<details class="picky-settings-help">' .
            '<summary class="picky-settings-help__summary" aria-label="' . esc_attr__( 'Explain this option', 'pickybooking' ) . '">' .
            '<span class="picky-settings-help__mark" aria-hidden="true">?</span></summary>' .
            '<div class="picky-settings-help__content">' . $body . '</div>' .
            '</details>';
    }

    /**
     * Short URL summary for status panels (host + path, truncated). Never includes credentials.
     *
     * @param string $url Full URL.
     * @return string
     */
    private function picky_integration_url_summary( $url ) {
        $url = trim( (string) $url );
        if ( $url === '' ) {
            return '';
        }
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            $excerpt = $url;
        } else {
            $scheme = isset( $p['scheme'] ) ? (string) $p['scheme'] : '';
            $host   = (string) $p['host'];
            $path   = isset( $p['path'] ) ? (string) $p['path'] : '';
            $excerpt = ( $scheme !== '' ? $scheme . '://' : '' ) . $host . $path;
        }
        $max = 72;
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $excerpt, 'UTF-8' ) > $max ) {
            return mb_substr( $excerpt, 0, $max, 'UTF-8' ) . '…';
        }
        if ( strlen( $excerpt ) > $max ) {
            return substr( $excerpt, 0, $max ) . '…';
        }
        return $excerpt;
    }

    /**
     * Short connection + webhook summary under HubSpot / n8n / Zapier / Make section headings (saved options only).
     *
     * @param string               $sec_slug Section slug (picky_sec_*).
     * @param array<string, mixed> $settings Plugin options.
     * @return string Safe HTML or empty.
     */
    private function picky_settings_section_integration_strip( $sec_slug, $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $sec_slug = (string) $sec_slug;

        if ( 'picky_sec_hubspot' === $sec_slug ) {
            $hs_on  = isset( $settings['hubspot_enabled'] ) && (string) $settings['hubspot_enabled'] === '1';
            $hs_tok = isset( $settings['hubspot_access_token'] ) ? trim( (string) $settings['hubspot_access_token'] ) : '';
            if ( ! $hs_on ) {
                $state = 'disabled';
                $conn  = __( 'Disabled', 'pickybooking' );
            } elseif ( $hs_tok === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add access token below', 'pickybooking' );
            } else {
                $state = 'ready';
                $conn  = __( 'Ready — token saved', 'pickybooking' );
            }
            $hook_val = '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( 'Not used (outbound REST to HubSpot only).', 'pickybooking' ) . '</span>';
        } elseif ( 'picky_sec_mailchimp' === $sec_slug ) {
            $mc_on  = isset( $settings['mailchimp_enabled'] ) && (string) $settings['mailchimp_enabled'] === '1';
            $mc_key = isset( $settings['mailchimp_api_key'] ) ? trim( (string) $settings['mailchimp_api_key'] ) : '';
            $mc_list = isset( $settings['mailchimp_list_id'] ) ? trim( (string) $settings['mailchimp_list_id'] ) : '';
            if ( ! $mc_on ) {
                $state = 'disabled';
                $conn  = __( 'Disabled', 'pickybooking' );
            } elseif ( $mc_key === '' || $mc_list === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add API key and Audience ID below', 'pickybooking' );
            } else {
                $state = 'ready';
                /* translators: Shown when API key and Audience ID are stored; does not mean Mailchimp already accepted a request. */
                $conn  = __( 'Credentials saved — use Test Connection to verify with Mailchimp', 'pickybooking' );
            }
            $hook_val = '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( 'Not used (outbound REST to Mailchimp only).', 'pickybooking' ) . '</span>';
        } elseif ( 'picky_sec_n8n' === $sec_slug ) {
            $on  = isset( $settings['n8n_webhook_enabled'] ) && (string) $settings['n8n_webhook_enabled'] === '1';
            $url = isset( $settings['n8n_webhook_url'] ) ? trim( (string) $settings['n8n_webhook_url'] ) : '';
            if ( ! $on ) {
                $state = 'disabled';
                $conn  = __( 'Webhook disabled', 'pickybooking' );
            } elseif ( $url === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add webhook URL below', 'pickybooking' );
            } else {
                $state = 'ready';
                $conn  = __( 'Ready to POST after each booking', 'pickybooking' );
            }
            $hook_val = $url !== ''
                ? '<code class="picky-settings-section__integration-strip-code" title="' . esc_attr( $url ) . '">' . esc_html( $this->picky_integration_url_summary( $url ) ) . '</code>'
                : '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( '—', 'pickybooking' ) . '</span>';
        } elseif ( 'picky_sec_zapier' === $sec_slug ) {
            $on  = isset( $settings['zapier_webhook_enabled'] ) && (string) $settings['zapier_webhook_enabled'] === '1';
            $url = isset( $settings['zapier_webhook_url'] ) ? trim( (string) $settings['zapier_webhook_url'] ) : '';
            if ( ! $on ) {
                $state = 'disabled';
                $conn  = __( 'Webhook disabled', 'pickybooking' );
            } elseif ( $url === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add Catch Hook URL below', 'pickybooking' );
            } else {
                $state = 'ready';
                $conn  = __( 'Ready to POST after each booking', 'pickybooking' );
            }
            $hook_val = $url !== ''
                ? '<code class="picky-settings-section__integration-strip-code" title="' . esc_attr( $url ) . '">' . esc_html( $this->picky_integration_url_summary( $url ) ) . '</code>'
                : '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( '—', 'pickybooking' ) . '</span>';
        } elseif ( 'picky_sec_make' === $sec_slug ) {
            $on  = isset( $settings['make_webhook_enabled'] ) && (string) $settings['make_webhook_enabled'] === '1';
            $url = isset( $settings['make_webhook_url'] ) ? trim( (string) $settings['make_webhook_url'] ) : '';
            if ( ! $on ) {
                $state = 'disabled';
                $conn  = __( 'Webhook disabled', 'pickybooking' );
            } elseif ( $url === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add Custom webhook URL below', 'pickybooking' );
            } else {
                $state = 'ready';
                $conn  = __( 'Ready to POST after each booking', 'pickybooking' );
            }
            $hook_val = $url !== ''
                ? '<code class="picky-settings-section__integration-strip-code" title="' . esc_attr( $url ) . '">' . esc_html( $this->picky_integration_url_summary( $url ) ) . '</code>'
                : '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( '—', 'pickybooking' ) . '</span>';
        } elseif ( 'picky_sec_gcal' === $sec_slug ) {
            $on   = isset( $settings['gcal_enabled'] ) && (string) $settings['gcal_enabled'] === '1';
            $json = isset( $settings['gcal_service_account_json'] ) ? trim( (string) $settings['gcal_service_account_json'] ) : '';
            $cal  = isset( $settings['gcal_calendar_id'] ) ? trim( (string) $settings['gcal_calendar_id'] ) : '';
            if ( ! $on ) {
                $state = 'disabled';
                $conn  = __( 'Sync disabled', 'pickybooking' );
            } elseif ( $json === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add Service Account JSON key', 'pickybooking' );
            } elseif ( $cal === '' ) {
                $state = 'incomplete';
                $conn  = __( 'Incomplete — add Calendar ID', 'pickybooking' );
            } else {
                $state = 'ready';
                $conn  = __( 'Ready to sync to Google Calendar', 'pickybooking' );
            }
            $k_conn = esc_html__( 'Connection', 'pickybooking' );
            $k_cal  = esc_html__( 'Calendar', 'pickybooking' );
            $cal_span = $cal !== ''
                ? '<code class="picky-settings-section__integration-strip-code" title="' . esc_attr( $cal ) . '">' . esc_html( $cal === 'primary' ? __( 'Primary calendar', 'pickybooking' ) : $cal ) . '</code>'
                : '<span class="picky-settings-section__integration-strip-v picky-settings-section__integration-strip-v--muted">' . esc_html__( '—', 'pickybooking' ) . '</span>';
            return '<p class="picky-settings-section__integration-strip picky-settings-section__integration-strip--' . esc_attr( $state ) . '" role="status">'
                . '<span class="picky-settings-section__integration-strip-part"><span class="picky-settings-section__integration-strip-k">' . $k_conn . '</span> '
                . '<span class="picky-settings-section__integration-strip-v">' . esc_html( $conn ) . '</span></span>'
                . '<span class="picky-settings-section__integration-strip-sep" aria-hidden="true"> · </span>'
                . '<span class="picky-settings-section__integration-strip-part"><span class="picky-settings-section__integration-strip-k">' . $k_cal . '</span> '
                . $cal_span . '</span></p>';
        } else {
            return '';
        }

        $k_conn = esc_html__( 'Connection', 'pickybooking' );
        $k_hook = esc_html__( 'Webhook URL', 'pickybooking' );

        return '<p class="picky-settings-section__integration-strip picky-settings-section__integration-strip--' . esc_attr( $state ) . '" role="status">'
            . '<span class="picky-settings-section__integration-strip-part"><span class="picky-settings-section__integration-strip-k">' . $k_conn . '</span> '
            . '<span class="picky-settings-section__integration-strip-v">' . esc_html( $conn ) . '</span></span>'
            . '<span class="picky-settings-section__integration-strip-sep" aria-hidden="true"> · </span>'
            . '<span class="picky-settings-section__integration-strip-part"><span class="picky-settings-section__integration-strip-k">' . $k_hook . '</span> '
            . $hook_val             . '</span></p>';
    }

    /**
     * Optional brand icon beside integration section titles (`assets/img/brands/{slug}.svg`).
     *
     * @param string $brand Alphanumeric key (hubspot, mailchimp, …).
     * @param int    $size  Image width/height in pixels.
     * @return string Safe HTML or empty.
     */
    private function picky_settings_section_brand_img_tag( $brand, $size = 28 ) {
        $brand = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $brand ) );
        $size  = max( 16, (int) $size );
        if ( $brand === '' ) {
            return '';
        }
        $basename = 'assets/img/brands/' . $brand . '.svg';
        $abs        = dirname( __DIR__ ) . '/' . $basename;
        if ( ! is_readable( $abs ) ) {
            return '';
        }
        $url = plugins_url( $basename, dirname( __DIR__ ) . '/' . Info::MAIN_FILE );

        return '<span class="picky-settings-section__brand">' .
            '<img src="' . esc_url( $url ) . '" width="' . $size . '" height="' . $size . '" alt="" decoding="async" loading="lazy" />' .
            '</span>';
    }

    /**
     * Connection badge for an Integrations card (Off / Setup / Ready).
     *
     * @param string               $sec_slug Section slug.
     * @param array<string, mixed> $settings Plugin options.
     * @return array{0:string,1:string} State key and label.
     */
    private function picky_integration_status( $sec_slug, $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $on = static function ( $key ) use ( $settings ) {
            return isset( $settings[ $key ] ) && (string) $settings[ $key ] === '1';
        };
        $val = static function ( $key ) use ( $settings ) {
            return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
        };

        if ( 'picky_sec_ai' === $sec_slug ) {
            if ( ! $on( 'ai_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'ai_groq_key' ) === '' && $val( 'ai_openai_key' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_hubspot' === $sec_slug ) {
            if ( ! $on( 'hubspot_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'hubspot_access_token' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_mailchimp' === $sec_slug ) {
            if ( ! $on( 'mailchimp_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'mailchimp_api_key' ) === '' || $val( 'mailchimp_list_id' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_n8n' === $sec_slug ) {
            if ( ! $on( 'n8n_webhook_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'n8n_webhook_url' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_zapier' === $sec_slug ) {
            if ( ! $on( 'zapier_webhook_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'zapier_webhook_url' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_make' === $sec_slug ) {
            if ( ! $on( 'make_webhook_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'make_webhook_url' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_gcal' === $sec_slug ) {
            if ( ! $on( 'gcal_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            if ( $val( 'gcal_service_account_json' ) === '' || $val( 'gcal_calendar_id' ) === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_pay_collect' === $sec_slug ) {
            if ( ! $on( 'deposit_enabled' ) ) {
                return array( 'disabled', __( 'Off', 'pickybooking' ) );
            }
            return array( 'ready', __( 'On', 'pickybooking' ) );
        }
        if ( 'picky_sec_pay_method' === $sec_slug ) {
            $p = $val( 'payment_provider' );
            if ( $p === '' || $p === 'none' ) {
                return array( 'disabled', __( 'Free', 'pickybooking' ) );
            }
            if ( $p === 'stripe' ) {
                return array( 'ready', __( 'Stripe', 'pickybooking' ) );
            }
            if ( $p === 'woocommerce' ) {
                return array( 'ready', __( 'Woo', 'pickybooking' ) );
            }
            return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
        }
        if ( 'picky_sec_pay_stripe' === $sec_slug ) {
            $pk = $val( 'stripe_publishable_key' );
            $sk = $val( 'stripe_secret_key' );
            if ( $val( 'payment_provider' ) !== 'stripe' ) {
                return array( 'disabled', __( 'Idle', 'pickybooking' ) );
            }
            if ( $pk === '' || $sk === '' ) {
                return array( 'incomplete', __( 'Setup', 'pickybooking' ) );
            }
            return array( 'ready', __( 'Ready', 'pickybooking' ) );
        }
        if ( 'picky_sec_pay_paypal' === $sec_slug ) {
            return array( 'disabled', __( 'Soon', 'pickybooking' ) );
        }
        return array( 'disabled', __( 'Off', 'pickybooking' ) );
    }

    /**
     * Open an Integrations gallery card (Layouts-style preview + configure drawer).
     *
     * @param array<string, mixed>  $field        Section field.
     * @param array<string, mixed>  $settings     Plugin options.
     * @param array<string, string> $section_help Short blurbs.
     * @return string
     */
    private function picky_integration_card_open_html( $field, $settings, $section_help ) {
        $sec_slug = isset( $field['slug'] ) ? (string) $field['slug'] : '';
        $brand    = isset( $field['brand'] ) ? (string) $field['brand'] : '';
        $title    = isset( $field['title'] ) ? (string) $field['title'] : '';
        $desc     = '';
        if ( isset( $section_help[ $sec_slug ] ) && $section_help[ $sec_slug ] !== '' ) {
            $desc = (string) $section_help[ $sec_slug ];
        } elseif ( ! empty( $field['description'] ) ) {
            $desc = wp_strip_all_tags( (string) $field['description'] );
            if ( function_exists( 'mb_substr' ) && mb_strlen( $desc ) > 140 ) {
                $desc = rtrim( mb_substr( $desc, 0, 137 ) ) . '…';
            } elseif ( strlen( $desc ) > 140 ) {
                $desc = rtrim( substr( $desc, 0, 137 ) ) . '…';
            }
        }
        list( $state, $badge ) = $this->picky_integration_status( $sec_slug, $settings );
        $brand_key = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $brand ) );
        $brand_cls = $brand_key !== '' ? ' picky-int-card--' . $brand_key : '';
        $logo      = $brand !== '' ? $this->picky_settings_section_brand_img_tag( $brand, 56 ) : '';
        if ( $logo === '' ) {
            $fallback = '';
            if ( $sec_slug === 'picky_sec_ai' ) {
                $fallback = 'AI';
            } elseif ( $sec_slug === 'picky_sec_pay_collect' ) {
                $fallback = '$';
            } elseif ( $sec_slug === 'picky_sec_pay_method' ) {
                $fallback = 'Pay';
            }
            if ( $fallback !== '' ) {
                $logo = '<span class="picky-int-card__logo-fallback" aria-hidden="true">' . esc_html( $fallback ) . '</span>';
            }
        }

        $html  = '<article class="picky-int-card picky-int-card--' . esc_attr( $state ) . $brand_cls . '" id="' . esc_attr( 'picky-section-' . $sec_slug ) . '">';
        $html .= '<div class="picky-int-card__preview">';
        $html .= $logo;
        $html .= '<span class="picky-int-card__badge">' . esc_html( $badge ) . '</span>';
        $html .= '</div>';
        $html .= '<div class="picky-int-card__body">';
        if ( $title !== '' ) {
            $html .= '<h2 class="picky-int-card__title">' . esc_html( $title ) . '</h2>';
        }
        if ( $desc !== '' ) {
            $html .= '<p class="picky-int-card__desc">' . esc_html( $desc ) . '</p>';
        }
        $html .= $this->picky_settings_section_integration_strip( $sec_slug, $settings );
        $html .= '<details class="picky-int-card__config">';
        $html .= '<summary class="picky-int-card__summary">' . esc_html__( 'Configure', 'pickybooking' ) . '</summary>';
        $html .= '<div class="picky-int-card__fields">';
        return $html;
    }

    /**
     * Long tooltips and short visible hints keyed by field slug / section slug / radio index / checkbox item slug.
     *
     * @param string $item_label  Singular reservable label.
     * @param string $items_label Plural reservable label.
     * @return array{section:array<string,string>,slug:array<string,string>,radio:array<string,array<int,string>>,checkbox_item:array<string,string>,hints:array<string,string>}
     */
    private function picky_get_settings_help_pack( $item_label, $items_label ) {
        return array(
            'section'       => array(
                'picky_sec_main_labels'   => __( 'Set how your bookable thing is named everywhere in the plugin, and how far into the future the calendar opens.', 'pickybooking' ),
                'picky_sec_main_capacity' => __( 'Control headcount and which parts of the form and map visitors see. Capacity mode needs party size when items share one area.', 'pickybooking' ),
                'picky_sec_main_ux'       => __( 'Choose the step order on the frontend, how phones behave, and how the widget sits in the page.', 'pickybooking' ),
                'picky_sec_payments'      => __( 'Turn on paid bookings and pick Stripe Checkout or WooCommerce. Keys here are only for direct Stripe; Woo uses its own store gateways.', 'pickybooking' ),
                'picky_sec_pay_collect'   => __( 'When this is on, the slot is held only after the guest pays. Choose a fixed amount, services total, or both.', 'pickybooking' ),
                'picky_sec_pay_method'    => __( 'None skips checkout. Stripe uses the keys in the Stripe card. WooCommerce sends guests to your shop checkout.', 'pickybooking' ),
                'picky_sec_pay_stripe'    => __( 'Used only when the provider is Stripe Checkout (direct). WooCommerce Stripe plugins do not use these keys.', 'pickybooking' ),
                'picky_sec_pay_paypal'    => __( 'Reserved for a future PayPal flow. These fields are unused until that integration ships.', 'pickybooking' ),
                'picky_sec_schedule'      => __( 'Bookings are only accepted inside the green windows you enable. Use the second window for split shifts.', 'pickybooking' ),
                'picky_sec_form_copy'     => __( 'Copy shown on the public form and where admin / customer emails go.', 'pickybooking' ),
                'picky_sec_sms'           => __( 'Optional SMS to your staff phone via Vonage. Test credentials in dashboard first; failed SMS shows a settings notice.', 'pickybooking' ),
                'picky_sec_layout_colors' => __( 'Public shortcode appearance only. Does not change colors inside the admin layout builder.', 'pickybooking' ),
                'picky_sec_integrations'  => __( 'Optional connections to HubSpot, Mailchimp, n8n, Zapier, and Make. Each section includes a short status line and a test where available.', 'pickybooking' ),
                'picky_sec_hubspot'       => __( 'Send all successful bookings to HubSpot CRM. Adds them as contacts and optionally creates a deal.', 'pickybooking' ),
                'picky_sec_mailchimp'     => __( 'Send all successful bookings to a Mailchimp Audience. Adds or updates them as subscribers.', 'pickybooking' ),
                'picky_sec_n8n'           => __( 'Ping an n8n webhook URL with booking data when a reservation is placed.', 'pickybooking' ),
                'picky_sec_zapier'        => __( 'Ping a Zapier Catch Hook URL with booking data when a reservation is placed.', 'pickybooking' ),
                'picky_sec_make'          => __( 'Ping a Make.com Custom Webhook URL with booking data when a reservation is placed.', 'pickybooking' ),
                'picky_sec_gcal'          => __( 'Create events in Google Calendar for each booking using a Service Account.', 'pickybooking' ),
            ),
            'slug'          => array(
                'reservable_item_name'       => __( 'Shown in admin lists, emails, and payment line items. Use Title Case in your language (e.g. “Room”, “Court”).', 'pickybooking' ),
                'reservable_items_name'      => __( 'Plural form for menus and headings. Leave empty to auto-append “s” to the singular name.', 'pickybooking' ),
                'days_ahead'                 => __( 'Rolling horizon: today + this many days is the last selectable date. Lower for tight inventory; higher for long-term planning.', 'pickybooking' ),
                'table_booked_time'          => __( 'When the guest does not pick an end time, this is how long the slot lasts. It is also the default span used when checking conflicts.', 'pickybooking' ),
                'table_before_booked_time'   => __( 'Adds empty time before an existing booking starts so staff can reset the room. Zero means no extra padding.', 'pickybooking' ),
                'shop_people_amount'         => sprintf(
                    /* translators: %s: singular item */
                    __( 'Shows a “people” field on the form. Required for capacity mode so the plugin knows how many guests share one %s.', 'pickybooking' ),
                    $item_label
                ),
                'picky_group_display_options'  => __( 'Controls which fields appear on the public booking form and which numbers appear on the floor plan. Email must be on if you send confirmations.', 'pickybooking' ),
                'mobile_table_select'        => sprintf(
                    /* translators: 1: singular item, 2: plural items */
                    __( 'Small screens (under 768px): choose whether guests pick a specific %1$s on the map, use a list, or skip map selection. Test on a real phone. Applies to %2$s choice, not other fields.', 'pickybooking' ),
                    $item_label,
                    $items_label
                ),
                'booking_mode'               => sprintf(
                    /* translators: %s: plural items */
                    __( 'Exclusive = one party blocks the whole %s for the time range. Capacity = many parties until “max persons” is reached.', 'pickybooking' ),
                    $items_label
                ),
                'shortcode_layout'           => sprintf(
                    /* translators: %s: plural items */
                    __( 'Site-wide default for the order of booking steps. Each layout can override this under “Wizard flow on the public site” in the layout editor. Classic suits “pick %s first”; Smart suits “pick date/time first”; Simple hides map picking.', 'pickybooking' ),
                    $items_label
                ),
                'container_align'            => __( 'CSS alignment of the shortcode wrapper: useful inside full-width themes where you want the widget centered.', 'pickybooking' ),
                'deposit_enabled'            => __( 'When on, the booking is not final until payment succeeds. Failed payment frees the slot again. The amount comes from "Payment amount source" below.', 'pickybooking' ),
                'deposit_amount'             => __( 'Used when "Payment amount source" is Fixed amount (or as a fallback). Use the same currency as your Stripe account or WooCommerce store.', 'pickybooking' ),
                'deposit_currency'           => __( 'ISO 4217 code. Mismatched currency vs Stripe/Woo will cause declines or wrong totals.', 'pickybooking' ),
                'payment_amount_source'      => __( 'Where to read the charge amount from: a fixed value, the sum of selected services, or both with services taking priority.', 'pickybooking' ),
                'payment_provider'           => __( 'None = free booking only. Direct Stripe uses the keys below. WooCommerce creates an order and uses whatever gateways you enabled in Woo.', 'pickybooking' ),
                'stripe_publishable_key'     => __( 'Public key safe for browsers. Only needed for Direct Stripe mode (not for Woo’s own Stripe gateway).', 'pickybooking' ),
                'stripe_secret_key'          => __( 'Secret key — never share. Store stays in wp_options; restrict admin users. Rotate if leaked.', 'pickybooking' ),
                'paypal_client_id'           => __( 'Reserved for a future PayPal flow; currently unused by the booking payment logic.', 'pickybooking' ),
                'paypal_client_secret'       => __( 'Reserved for future PayPal integration.', 'pickybooking' ),
                'hubspot_enabled'            => __( 'Requires email or phone on the booking form so HubSpot can match or create a contact.', 'pickybooking' ),
                'hubspot_access_token'       => __( 'HubSpot private apps live under Development → Legacy apps (or a project on the new developer platform). The access token works like an API key—rotate it in HubSpot if compromised.', 'pickybooking' ),
                'hubspot_sync_deals'         => __( 'When on, each booking also creates a HubSpot deal linked to the contact, with reservation details in the deal description.', 'pickybooking' ),
                'hubspot_deal_pipeline_id'   => __( 'Optional. Paste your pipeline internal ID from HubSpot (Settings → Objects → Deals → Pipelines). Leave blank with stage blank to auto-pick the first pipeline and first stage.', 'pickybooking' ),
                'hubspot_deal_stage_id'      => __( 'Optional. Stage internal ID in that pipeline. Must be set together with pipeline ID, or leave both blank for auto-detection.', 'pickybooking' ),
                'n8n_webhook_enabled'        => __( 'Sends a JSON POST to your n8n Webhook node URL after each successful booking. Failures are logged on the booking and do not block the guest. Use Zapier or Make below for those platforms.', 'pickybooking' ),
                'n8n_webhook_url'            => __( 'Production webhook URL from n8n (HTTPS recommended). Payload: event pickybooking.booking_created plus guest, reservation, payment, HubSpot IDs if synced.', 'pickybooking' ),
                'n8n_webhook_secret'         => __( 'Optional shared secret: HMAC-SHA256 of the raw JSON body is sent as header X-Picky-N8N-Signature (prefix sha256=). Verify in n8n to reject forged requests.', 'pickybooking' ),
                'zapier_webhook_enabled'     => __( 'Sends the same JSON payload as n8n to your Zapier “Webhooks by Zapier” Catch Hook URL after each successful booking. Runs after the n8n webhook when both are on.', 'pickybooking' ),
                'zapier_webhook_url'         => __( 'Paste the HTTPS URL from Zapier’s Catch Hook trigger. Payload matches the n8n webhook (event pickybooking.booking_created).', 'pickybooking' ),
                'zapier_webhook_secret'      => __( 'Optional: HMAC-SHA256 of the raw JSON body is sent as X-Picky-ZAPIER-Signature: sha256=… for verification in a Zap Code step.', 'pickybooking' ),
                'make_webhook_enabled'       => __( 'Sends the same JSON payload as n8n to your Make.com Custom webhook URL after each successful booking. Runs after the Zapier webhook when both are on.', 'pickybooking' ),
                'make_webhook_url'           => __( 'From Make: add a Webhooks module → Custom webhook, run once to get the URL, then paste it here (HTTPS). Payload: event pickybooking.booking_created.', 'pickybooking' ),
                'make_webhook_secret'        => __( 'Optional: HMAC-SHA256 of the raw JSON body is sent as X-Picky-MAKE-Signature: sha256=… Verify in Make (e.g. HTTP tools or a filter) if you need request authentication.', 'pickybooking' ),
                'gcal_enabled'               => __( 'Creates a Google Calendar event for each booking. Requires a Google Cloud Service Account with Calendar API access.', 'pickybooking' ),
                'gcal_service_account_json'  => __( 'Service Account JSON key from Google Cloud Console. Grants server-to-server access without OAuth user consent.', 'pickybooking' ),
                'gcal_calendar_id'           => __( 'Target calendar ID. Use "primary" for the main calendar, or the specific calendar ID found in calendar settings.', 'pickybooking' ),
                'gcal_add_attendees'         => __( 'Adds the customer email as an attendee so they receive a calendar invitation.', 'pickybooking' ),
                'work_days'                  => __( 'Turn a weekday on, set open/close, optionally add a second interval. Disabled days never show slots.', 'pickybooking' ),
                'form_message'               => __( 'Short reassurance under the main button (e.g. “We reply within 15 minutes”). Keep it one line if possible.', 'pickybooking' ),
                'picky_group_form_email_flags' => __( 'Consent stores legal text you provide. Customer email requires the email field to be visible and filled.', 'pickybooking' ),
                'form_confirm_text'          => __( 'HTML snippet next to the checkbox (links allowed). Shown only when consent is enabled.', 'pickybooking' ),
                'form_thx'                   => __( 'Shown after a successful unpaid booking or after payment redirect success. Leave empty for the built-in message.', 'pickybooking' ),
                'form_email'                 => __( 'Where admin copies of bookings go. Must be a valid address; falls back to the site admin email if empty.', 'pickybooking' ),
                'send_sms'                   => __( 'Sends one SMS per booking to the phone below using Vonage REST. Costs money per message—disable when not needed.', 'pickybooking' ),
                'sms_key'                    => __( 'Vonage “API key” from the dashboard. Together with the secret it authorizes balance checks on save.', 'pickybooking' ),
                'sms_secret'                 => __( 'Vonage “API secret”. Treat like a password.', 'pickybooking' ),
                'sms_phone'                  => __( 'Destination in E.164 (+countrycode…). This is usually your staff phone, not the guest’s.', 'pickybooking' ),
                'color_main'                 => sprintf(
                    /* translators: %s: plural bookable items (e.g. Tables, Seats) */
                    __( 'Public shortcode only: outer booking wrapper, calendar column tint, %s shapes on the map, seat chips, capacity badge, and ID labels. Does not change the map background image (set per layout in the builder).', 'pickybooking' ),
                    $items_label
                ),
                'color_datepicker'           => __( 'Public shortcode only: jQuery UI datepicker cells, text, and month arrows for the booking calendar.', 'pickybooking' ),
                'color_form'                 => __( 'Public shortcode only: submit button, form typography, inputs inside the widget, notices, and secondary step panels (see each label for exact scope).', 'pickybooking' ),
            ),
            'radio'         => array(
                'mobile_table_select' => array(
                    0 => sprintf(
                        /* translators: %s: singular item */
                        __( 'Fastest on small screens if you assign %s manually or run first-come-first-served without map picking.', 'pickybooking' ),
                        $item_label
                    ),
                    1 => sprintf(
                        /* translators: %s: singular item */
                        __( 'Best when the map is simple; guests tap the exact %s. Can feel cramped on very small phones.', 'pickybooking' ),
                        $item_label
                    ),
                    2 => sprintf(
                        /* translators: %s: plural items */
                        __( 'Good for many %s: scrollable list, still respects availability when the plugin can compute it.', 'pickybooking' ),
                        $items_label
                    ),
                ),
                'booking_mode'        => array(
                    0 => __( 'Use for rooms, courts, equipment—anything that cannot be shared by strangers at the same time.', 'pickybooking' ),
                    1 => sprintf(
                        /* translators: %s: plural items */
                        __( 'Use for coworking-style %s: turn on party size and set “max persons” on each element in the layout editor.', 'pickybooking' ),
                        $items_label
                    ),
                ),
                'shortcode_layout'    => array(
                    0 => sprintf(
                        /* translators: %s: singular item */
                        __( 'Guest chooses %s first, then date/time in the modal—closest to classic restaurant booking.', 'pickybooking' ),
                        $item_label
                    ),
                    1 => sprintf(
                        /* translators: %s: plural items */
                        __( 'Guest filters by date/time first, then sees only free %s—reduces hunting on busy days.', 'pickybooking' ),
                        $items_label
                    ),
                    2 => sprintf(
                        /* translators: %s: plural items */
                        __( 'Map is visible immediately; guest picks date/time in a compact bottom bar and free %s update live on the layout.', 'pickybooking' ),
                        $items_label
                    ),
                    3 => sprintf(
                        /* translators: %s: singular item */
                        __( 'Guest never picks a %s on the map; plugin assigns the first free slot. Good for events or uniform seats.', 'pickybooking' ),
                        $item_label
                    ),
                ),
                'payment_provider'    => array(
                    0 => __( 'Bookings are stored without taking card data through PickyBooking.', 'pickybooking' ),
                    1 => __( 'Redirects to Stripe-hosted Checkout; you maintain Stripe keys in these two fields only.', 'pickybooking' ),
                    2 => __( 'Creates a WooCommerce pending order with a fee line; customer pays with any gateway you configured in Woo (Stripe, PayPal, etc.).', 'pickybooking' ),
                ),
            ),
            'checkbox_item' => array(
                'show_email'           => __( 'Turn off if you only take phone bookings or want fewer GDPR fields. Required for “email confirmation to guest”.', 'pickybooking' ),
                'show_phone'           => __( 'Turn off if you do not collect a guest phone on the public form (e.g. email-only or walk-in follow-up). Admin can still add a phone when editing a reservation.', 'pickybooking' ),
                'show_people'          => sprintf(
                    /* translators: %s: singular item */
                    __( 'Shows the capacity number painted on each %s in the map. Helps guests pick the right size.', 'pickybooking' ),
                    $item_label
                ),
                'show_seats'           => __( 'Shows the center capacity / seat count badge on each bookable item on the map (independent of “max persons”). Turn off for a cleaner floor plan. Each layout can override this under “Center seat/capacity badge on the public site” in the layout editor.', 'pickybooking' ),
                'show_id_badge'        => __( 'Shows the ID badge (for example, table number) on map items. Turn off for a cleaner map. Each layout can override this under “ID badge on the public site” in the layout editor.', 'pickybooking' ),
                'show_element_label'   => __( 'Shows the custom label text on map items. Turn off to hide labels globally. Each layout can override this under “Element label on the public site” in the layout editor.', 'pickybooking' ),
                'show_time_to'         => __( 'Shows an end-time field so guests can shorten or lengthen the default slot where your layout supports intervals.', 'pickybooking' ),
                'animate_bookable_items' => __( 'Plays a short staggered “pop in” when the floor plan loads and a gentle scale on hover/selection for bookable items. Turn off for a static map (also respects the guest’s “reduce motion” preference).', 'pickybooking' ),
                'form_confirm'         => __( 'Adds a mandatory checkbox—pair it with the consent text area below. Common for EU privacy compliance.', 'pickybooking' ),
                'send_customer_email'  => __( 'Uses WordPress mail (wp_mail). For deliverability add SMTP plugin; requires email field on and filled.', 'pickybooking' ),
            ),
            'hints'         => array(
                'deposit_currency' => __( 'Example: EUR for a European Stripe account.', 'pickybooking' ),
                'days_ahead'       => __( 'Tip: include your busiest booking horizon only—very large values slow down calendar generation.', 'pickybooking' ),
                'deposit_amount'   => __( 'Use the same major-unit amount your gateway expects (e.g. 25.00, not cents, unless your store is configured otherwise).', 'pickybooking' ),
                'deposit_enabled'  => __( 'Guests who abandon payment do not keep a confirmed booking; the time slot is released.', 'pickybooking' ),
                'payment_amount_source' => __( 'Choose Selected services total when each bookable item exposes priced services and the customer’s total should equal the sum of what they picked.', 'pickybooking' ),
                'shop_people_amount'   => __( 'In capacity mode this field is required so the plugin can subtract the right headcount from each item.', 'pickybooking' ),
                'animate_bookable_items' => __( 'Entrance and hover motion only — availability colors and selection still work when this is off.', 'pickybooking' ),
                'picky_group_display_options' => __( 'Toggle email and phone on the public form, map visibility elements (“max persons”, center badge, ID badge, label), and the optional end-time field. If you hide email, turn off “email confirmation to guest” below.', 'pickybooking' ),
                'picky_group_form_email_flags' => __( 'Consent text supports basic HTML; test the form once after changing these toggles.', 'pickybooking' ),
            ),
        );
    }

    private function custom_settings_fields( $field_args, $settings ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for UI
        $output = '';
        $output_return = '';
        $output_tab = '';
        $output_tab_1 = '';
        $output_tab_2 = '';
        $output_tab_3 = '';
        $output_tab_4 = '';
        $output_tab_5 = '';
        $output_tab_6 = '';
        $output_tab_1_fake = '';
        $output_tab_2_fake = '';
        $output_tab_3_fake = '';
        $output_tab_4_fake = '';
        $output_tab_5_fake = '';
        $output_tab_6_fake = '';
        $output_tab_12_fake = '';
        $tab = "";
        $output_all = "";
        $option_name = INFO::OPTION_NAME;
        $default_options = INFO::$DEFAULT_OPTIONS;

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $item_label    = $this->picky_item_label();
        $items_label   = $this->picky_items_label();
        $help_pack     = $this->picky_get_settings_help_pack( $item_label, $items_label );
        $slug_help     = isset( $help_pack['slug'] ) && is_array( $help_pack['slug'] ) ? $help_pack['slug'] : array();
        $radio_help    = isset( $help_pack['radio'] ) && is_array( $help_pack['radio'] ) ? $help_pack['radio'] : array();
        $cb_item_help  = isset( $help_pack['checkbox_item'] ) && is_array( $help_pack['checkbox_item'] ) ? $help_pack['checkbox_item'] : array();
        $field_hints   = isset( $help_pack['hints'] ) && is_array( $help_pack['hints'] ) ? $help_pack['hints'] : array();
        $section_help  = isset( $help_pack['section'] ) && is_array( $help_pack['section'] ) ? $help_pack['section'] : array();
        $picky_int_card_open = false;
        $picky_int_grid_open = false;
        $picky_pay_card_open = false;
        $picky_pay_grid_open = false;

        foreach ($field_args as $key_field => $field) {
            // clear output
            $output = '';

            $ftype = isset( $field['type'] ) ? (string) $field['type'] : '';

            if ( 'settings_section' === $ftype ) {
                $tab = '';
                if ( isset( $field['tab'] ) && $field['tab'] !== '' ) {
                    $tab = (string) $field['tab'];
                }
                $sec_slug = isset( $field['slug'] ) ? (string) $field['slug'] : 'section-' . (string) $key_field;
                $section_classes = 'picky-settings-section';
                if ( 'picky_sec_layout_colors' === $sec_slug ) {
                    $section_classes .= ' picky-settings-section--layout-colors-intro';
                }
                if ( $tab === '12' ) {
                    if ( $sec_slug === 'picky_sec_integrations' ) {
                        $section_classes .= ' picky-settings-section--integrations-header picky-int-intro';
                    }
                }
                if ( $tab === '5' && $sec_slug === 'picky_sec_payments' ) {
                    $section_classes .= ' picky-int-intro picky-pay-intro';
                }
                if ( $tab === '12' && $sec_slug !== 'picky_sec_integrations' ) {
                    if ( $picky_int_card_open ) {
                        $output_tab_12_fake .= '</div></details></div></article>';
                        $picky_int_card_open = false;
                    }
                    if ( ! $picky_int_grid_open ) {
                        $output_tab_12_fake .= '<div class="picky-int-grid">';
                        $picky_int_grid_open = true;
                    }
                    $output .= $this->picky_integration_card_open_html( $field, $settings, $section_help );
                    $picky_int_card_open = true;
                } elseif ( $tab === '5' && $sec_slug !== 'picky_sec_payments' ) {
                    if ( $picky_pay_card_open ) {
                        $output_tab_5_fake .= '</div></details></div></article>';
                        $picky_pay_card_open = false;
                    }
                    if ( ! $picky_pay_grid_open ) {
                        $output_tab_5_fake .= '<div class="picky-int-grid picky-pay-grid">';
                        $picky_pay_grid_open = true;
                    }
                    $output .= $this->picky_integration_card_open_html( $field, $settings, $section_help );
                    $picky_pay_card_open = true;
                } else {
                $output .= '<div class="' . esc_attr( $section_classes ) . '" id="' . esc_attr( 'picky-section-' . $sec_slug ) . '">';
                $output .= '<div class="picky-settings-section__head">';
                if ( isset( $field['brand'] ) && (string) $field['brand'] !== '' ) {
                    $output .= $this->picky_settings_section_brand_img_tag( (string) $field['brand'] );
                }
                if ( ! empty( $field['title'] ) ) {
                    $output .= '<h2 class="picky-settings-section__title">' . esc_html( (string) $field['title'] ) . '</h2>';
                }
                $output .= '</div>';
                $sec_desc = '';
                if ( ! empty( $field['description'] ) ) {
                    $sec_desc = (string) $field['description'];
                } elseif ( isset( $section_help[ $sec_slug ] ) && $section_help[ $sec_slug ] !== '' ) {
                    $sec_desc = (string) $section_help[ $sec_slug ];
                }
                if ( $sec_desc !== '' ) {
                    $output .= '<p class="picky-settings-section__desc">' . wp_kses_post( $sec_desc ) . '</p>';
                }
                $output .= $this->picky_settings_section_integration_strip( $sec_slug, $settings );
                $output .= '</div>';
                if ( $tab === '12' && $sec_slug === 'picky_sec_integrations' ) {
                    $output .= '<div class="picky-int-grid">';
                    $picky_int_grid_open = true;
                }
                if ( $tab === '5' && $sec_slug === 'picky_sec_payments' ) {
                    $output .= '<div class="picky-int-grid picky-pay-grid">';
                    $picky_pay_grid_open = true;
                }
                }
                if ( $tab === '1' ) {
                    $output_tab_1_fake .= $output;
                } elseif ( $tab === '2' ) {
                    $output_tab_2_fake .= $output;
                } elseif ( $tab === '3' ) {
                    $output_tab_3_fake .= $output;
                } elseif ( $tab === '4' ) {
                    $output_tab_4_fake .= $output;
                } elseif ( $tab === '5' ) {
                    $output_tab_5_fake .= $output;
                } elseif ( $tab === '6' ) {
                    $output_tab_6_fake .= $output;
                } elseif ( $tab === '12' ) {
                    $output_tab_12_fake .= $output;
                } elseif ( $tab === '' ) {
                    $output_all .= $output;
                }
                continue;
            }

            if ( 'integration_test_button' === $ftype ) {
                $tab = '';
                if ( isset( $field['tab'] ) && $field['tab'] !== '' ) {
                    $tab = (string) $field['tab'];
                }
                $integration_name = isset( $field['slug'] ) ? (string) $field['slug'] : '';
                $btn_label        = ( isset( $field['button_label'] ) && (string) $field['button_label'] !== '' )
                    ? esc_html( (string) $field['button_label'] )
                    : esc_html__( 'Test Connection', 'pickybooking' );
                $test_row_class = 'picky-integration-test-row';
                if ( $tab !== '12' ) {
                    $test_row_class = 'picky-settings-card ' . $test_row_class;
                }
                $output .= '<div class="' . esc_attr( $test_row_class ) . '">';
                if ( ! empty( $field['description'] ) ) {
                    $output .= '<p class="description" style="margin:0 0 10px;">' . wp_kses_post( (string) $field['description'] ) . '</p>';
                }
                $output .= '<button type="button" class="button button-secondary picky-test-integration-btn picky-test-integration-btn--' . esc_attr( $integration_name ) . '">' . $btn_label . '</button>';
                $output .= ' <span class="picky-test-integration-result" style="margin-left:8px;font-weight:600;font-size:13px;"></span>';
                $output .= '</div>';
                if ( $tab === '1' ) {
                    $output_tab_1_fake .= $output;
                } elseif ( $tab === '2' ) {
                    $output_tab_2_fake .= $output;
                } elseif ( $tab === '3' ) {
                    $output_tab_3_fake .= $output;
                } elseif ( $tab === '4' ) {
                    $output_tab_4_fake .= $output;
                } elseif ( $tab === '5' ) {
                    $output_tab_5_fake .= $output;
                } elseif ( $tab === '6' ) {
                    $output_tab_6_fake .= $output;
                } elseif ( $tab === '12' ) {
                    $output_tab_12_fake .= $output;
                } elseif ( $tab === '' ) {
                    $output_all .= $output;
                }
                continue;
            }

            if ( 'ai_form_copy_button' === $ftype ) {
                // Pro-only UI — omitted entirely in Lite (no locked/disabled AI controls).
                if ( ! \Picky\Edition::allows( 'ai' ) ) {
                    continue;
                }
                $tab = isset( $field['tab'] ) ? (string) $field['tab'] : '6';
                $output .= '<div class="picky-settings-card picky-ai-form-copy-row">';
                $output .= '<p class="picky-ai-form-copy-title"><strong>' . esc_html__( 'Generate form copy with AI', 'pickybooking' ) . '</strong></p>';
                if ( ! empty( $field['description'] ) ) {
                    $output .= '<p class="description" style="margin:0 0 10px;">' . wp_kses_post( (string) $field['description'] ) . '</p>';
                }
                $output .= '<p><input type="text" class="regular-text picky-ai-form-copy-prompt" placeholder="' . esc_attr__( 'e.g. Boutique salon in Berlin, calm tone', 'pickybooking' ) . '" /></p>';
                $output .= '<p><button type="button" class="button button-secondary picky-ai-form-copy-btn">' . esc_html__( 'Generate with AI', 'pickybooking' ) . '</button>';
                $output .= ' <span class="picky-ai-form-copy-result" style="margin-left:8px;font-weight:600;font-size:13px;"></span></p>';
                $output .= '</div>';
                if ( $tab === '6' ) {
                    $output_tab_6_fake .= $output;
                } elseif ( $tab === '12' ) {
                    $output_tab_12_fake .= $output;
                } else {
                    $output_all .= $output;
                }
                continue;
            }

            if ( 'checkbox_group' === $ftype ) {
                $tab = '';
                if ( isset( $field['tab'] ) && $field['tab'] !== '' ) {
                    $tab = (string) $field['tab'];
                }
                $slug = isset( $field['slug'] ) ? (string) $field['slug'] : 'checkbox-group-' . (string) $key_field;
                $output .= '<div id="picky-settings-field--' . esc_attr( $slug ) . '" class="picky-settings-field--' . esc_attr( $slug ) . ' picky-settings-card picky-settings-card--checkbox-group">';
                $grp_label = isset( $field['label'] ) ? (string) $field['label'] : '';
                $output .= '<div class="picky-settings-card__head">';
                $output .= '<h3 class="picky-settings-card__title">' . esc_html( $grp_label ) . '</h3>';
                $output .= '</div>';
                if ( ! empty( $field['description'] ) ) {
                    $output .= '<div class="picky-settings-card__desc picky-settings-field-desc">' . wp_kses_post( (string) $field['description'] ) . '</div>';
                }
                if ( isset( $field_hints[ $slug ] ) && $field_hints[ $slug ] !== '' ) {
                    $output .= '<p class="picky-settings-field-hint">' . esc_html( (string) $field_hints[ $slug ] ) . '</p>';
                }
                $output .= '<ul class="picky-settings-checkbox-stack">';
                foreach ( (array) ( $field['items'] ?? array() ) as $item ) {
                    $is_slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
                    if ( $is_slug === '' ) {
                        continue;
                    }
                    $checkbox_name = $this->option_name . '[' . $is_slug . ']';
                    $checkbox_value = '';
                    $has_explicit_checkbox = array_key_exists( $is_slug, $settings )
                        && $settings[ $is_slug ] !== ''
                        && $settings[ $is_slug ] !== null;
                    if ( $has_explicit_checkbox ) {
                        $checkbox_value = (string) $settings[ $is_slug ];
                    } elseif ( isset( $default_options[ $is_slug ] ) ) {
                        $checkbox_value = (string) $default_options[ $is_slug ];
                    }
                    $checked = ( $checkbox_value === '1' ) ? 'checked' : '';
                    $item_label = isset( $item['label'] ) ? (string) $item['label'] : $is_slug;
                    $ih         = isset( $cb_item_help[ $is_slug ] ) ? (string) $cb_item_help[ $is_slug ] : '';
                    $output .= '<li class="picky-settings-checkbox-stack__item">';
                    $output .= '<div class="picky-settings-toggle-row">';
                    $output .= '<label class="picky-settings-toggle-row__main" for="' . esc_attr( $checkbox_name ) . '">';
                    $output .= '<input type="checkbox" id="' . esc_attr( $checkbox_name ) . '" name="' . esc_attr( $checkbox_name ) . '" ' . $checked . ' value="1"/>';
                    $output .= '<span class="picky-settings-toggle-row__text">' . esc_html( $item_label ) . '</span>';
                    $output .= '</label>';
                    if ( $ih !== '' ) {
                        $output .= $this->picky_settings_help_details( $slug . '-item-' . $is_slug . '-' . (string) $key_field, $ih );
                    }
                    $output .= '</div>';
                    $output .= '</li>';
                }
                $output .= '</ul></div>';
                if ( $tab === '1' ) {
                    $output_tab_1_fake .= $output;
                } elseif ( $tab === '2' ) {
                    $output_tab_2_fake .= $output;
                } elseif ( $tab === '3' ) {
                    $output_tab_3_fake .= $output;
                } elseif ( $tab === '4' ) {
                    $output_tab_4_fake .= $output;
                } elseif ( $tab === '5' ) {
                    $output_tab_5_fake .= $output;
                } elseif ( $tab === '6' ) {
                    $output_tab_6_fake .= $output;
                } elseif ( $tab === '12' ) {
                    $output_tab_12_fake .= $output;
                } elseif ( $tab === '' ) {
                    $output_all .= $output;
                }
                continue;
            }

            $slug = $field['slug'];
            $tab = '';
            if ( isset( $field['tab'] ) && $field['tab'] !== '' ) {
                $tab = (string) $field['tab'];
            }
            $setting = $this->option_name . '[' . $slug . ']';
            // Labels/after are translated where defined (__( ... , 'pickybooking' )); escape once at output.
            $label = isset( $field['label'] ) ? $field['label'] : '';
            $after = '';
            if ( isset( $field['after'] ) && $field['after'] !== '' ) {
                $after = $field['after'];
            }
            $class = '';
            if ( $field['type'] === 'colors' ) {
                $class = 'picky-settings-group--colors';
            } elseif ( $field['type'] === 'radio' ) {
                $class = 'picky-settings-group--radios';
            }
            if ( isset( $field['class'] ) && $field['class'] !== '' ) {
                $class = trim( $class . ' ' . $field['class'] );
            }
            $field_classes = 'picky-settings-field--' . esc_attr( (string) $slug ) . ' ' . esc_attr( (string) $class ) . ' picky-settings-group';
            if ( $tab !== '12' ) {
                $field_classes .= ' picky-settings-card';
            }
            $output .= '<div id="picky-settings-field--' . esc_attr( (string) $slug ) . '" class="' . trim( $field_classes ) . '">';
            $output .= '<div class="picky-settings-card__head">';
            $output .= '<h3 class="picky-settings-card__title"><label for="' . esc_attr( (string) $setting ) . '">' . esc_html( (string) $label ) . '</label></h3>';
            $output .= '</div>';
            // Checkbox descriptions are rendered beside the control (see checkbox branch).
            if ( $field['type'] !== 'checkbox' && isset( $field['description'] ) && ! empty( $field['description'] ) ) {
                $output .= '<div class="picky-settings-card__desc picky-settings-field-desc">' . wp_kses_post( $field['description'] ) . '</div>';
            }
            if ( isset( $field_hints[ $slug ] ) && $field_hints[ $slug ] !== '' ) {
                $output .= '<p class="picky-settings-field-hint">' . esc_html( (string) $field_hints[ $slug ] ) . '</p>';
            }
            
            if(!isset($settings[$slug])){
                if(isset($default_options[$slug])){
                    $settings[$slug] = $default_options[$slug];
                }
            }

            

            if ($field['type'] === 'text') { // text
                $output .= '<p>';
                $output .= '<input type="text" id="'.esc_attr((string)$setting).'" name="'.esc_attr((string)$setting).'" value="'.esc_attr((string)$settings[$slug]).'">';
                if($after !== ''){
                    $output .= '<span class="picky-settings-input-suffix">'.esc_html((string)$after).'</span>';
                }
                $output .= '</p>';
            } elseif ( $field['type'] === 'password' ) {
                $output .= '<p>';
                $stored = isset( $settings[ $slug ] ) ? (string) $settings[ $slug ] : '';
                $default_ph = __( 'Paste secret token/key', 'pickybooking' );
                if ( $slug === 'hubspot_access_token' ) {
                    $default_ph = __( 'Paste HubSpot access token', 'pickybooking' );
                } elseif ( $slug === 'mailchimp_api_key' ) {
                    $default_ph = __( 'Marketing API key (Profile → Extras → API keys; not Mobile SDK)', 'pickybooking' );
                } elseif ( $slug === 'ai_openai_key' ) {
                    $default_ph = __( 'Paste OpenAI API key (sk-…)', 'pickybooking' );
                } elseif ( $slug === 'ai_groq_key' ) {
                    $default_ph = __( 'Paste Groq API key (gsk_…)', 'pickybooking' );
                } elseif ( strpos( $slug, 'stripe_secret' ) !== false ) {
                    $default_ph = __( 'Paste Stripe Secret key', 'pickybooking' );
                }

                $ph       = $stored !== ''
                    ? __( 'Enter a new key/token to replace the stored one', 'pickybooking' )
                    : $default_ph;
                $output .= '<input type="password" id="' . esc_attr( (string) $setting ) . '" name="' . esc_attr( (string) $setting ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '" class="regular-text" />';
                if ( $stored !== '' ) {
                    $output .= '<span class="picky-settings-input-suffix description">' . esc_html__( 'A token is already saved (hidden). Leave blank to keep it.', 'pickybooking' ) . '</span>';
                }
                if ( $after !== '' ) {
                    $output .= '<span class="picky-settings-input-suffix">' . esc_html( (string) $after ) . '</span>';
                }
                $output .= '</p>';
            } elseif ($field['type'] === 'textarea') { // textarea
                $output .= '<p>';
                $output .= '<textarea id="'.esc_attr((string)$setting).'" name="'.esc_attr((string)$setting).'" rows="10">'.esc_textarea((string)$settings[$slug]).'</textarea>';
                if($after !== ''){
                    $output .= '<span class="picky-settings-input-suffix">'.esc_html((string)$after).'</span>';
                }
                $output .= '</p>';
            } elseif ($field['type'] === 'number') { // number
                $output .= '<p>';
                $output .= '<input id="'.esc_attr((string)$setting).'" name="'.esc_attr((string)$setting).'" type="number" min="0" value="'.esc_attr((string)$settings[$slug]).'">';
                if($after !== ''){
                    $output .= '<span class="picky-settings-input-suffix">'.esc_html((string)$after).'</span>';
                }
                $output .= '</p>';
            } elseif ($field['type'] === 'select') { // select
                $output .= '<p>';
                    $output .= '<select id="'.esc_attr((string)$setting).'" name="'.esc_attr((string)$setting).'">'; // value в select
                        foreach ($field['options'] as $key => $value) {
                            // Associative: key = value, value = label. List: value = value = label.
                            $opt_value = is_string( $key ) ? (string) $key : (string) $value;
                            $opt_label = (string) $value;
                            $selected  = ( (string) $settings[ $slug ] === $opt_value ) ? 'selected' : '';
                            $output   .= '<option ' . $selected . ' value="' . esc_attr( $opt_value ) . '">' . esc_html( $opt_label ) . '</option>';
                        }
                    $output .= '</select>';
                    if($after !== ''){
                        $output .= '<span class="picky-settings-input-suffix">' . esc_html((string)$after) . '</span>';
                    }
                $output .= '</p>';
            } else if($field['type'] === 'radio'){ // radio
                $output .= '<div class="picky-settings-radio-tiles">';
                foreach ($field['inputs'] as $key => $input) {
                    $checked = '';
                    if($settings[$slug] === $input['value']){
                        $checked = 'checked';
                    }
                    $rid = esc_attr( (string)$setting . '_' . (string)$key );
                    $output .= '<div class="picky-settings-radio-option">';
                    $output .= '<label class="picky-settings-radio picky-settings-radio--with-desc" for="' . $rid . '">';
                    $output .= '<input type="radio" id="' . $rid . '" name="' . esc_attr( (string) $setting ) . '" value="' . esc_attr( (string) $input['value'] ) . '" ' . $checked . '>';
                    $output .= '<span class="picky-settings-radio-tile__title">' . esc_html( (string) $input['title'] ) . '</span>';
                    if ( ! empty( $input['description'] ) ) {
                        $output .= '<span class="picky-settings-field-desc">' . wp_kses_post( $input['description'] ) . '</span>';
                    }
                    $output .= '</label>';
                    $rh = '';
                    if ( isset( $radio_help[ $slug ] ) && is_array( $radio_help[ $slug ] ) && isset( $radio_help[ $slug ][ (int) $key ] ) ) {
                        $rh = (string) $radio_help[ $slug ][ (int) $key ];
                    }
                    if ( $rh !== '' ) {
                        $output .= $this->picky_settings_help_details( (string) $slug . '-opt-' . (string) $key . '-' . (string) $key_field, $rh );
                    }
                    $output .= '</div>';
                }
                $output .= '</div>';
            } else if($field['type'] === 'image'){ // image

                $output .= $this->custom_image_uploader_field( $setting, $settings[$slug] );
                
            } else if($field['type'] === 'checkboxes'){ // checkboxes
                $output .= '<fieldset class="picky-settings-workdays-fieldset">';
                    foreach ($field['checkboxes'] as $key => $value) {
                        $checkbox_name = $this->option_name.'['.$slug.'_'.$value['name'].']';
                        $opt_key = $slug . '_' . $value['name'];
                        $checkbox_value = '';
                        $has_explicit = array_key_exists( $opt_key, $settings )
                            && $settings[ $opt_key ] !== ''
                            && $settings[ $opt_key ] !== null;
                        if ( $has_explicit ) {
                            $checkbox_value = (string) $settings[ $opt_key ];
                        } elseif ( isset( $default_options[ $opt_key ] ) ) {
                            $checkbox_value = (string) $default_options[ $opt_key ];
                        }
                        $checked = '';
                        if ( $checkbox_value === '1' ) {
                            $checked = 'checked';
                        }
                        $output .= '<div class="picky-checkboxes-fields-wrap">';
                            $output .= '<div class="rb-label-wrap">';
                            $output .= '<label for="'.esc_attr((string)$checkbox_name).'">'.esc_html((string)$value['title']).'</label>';
                            $output .= '</div>';
                            if(isset($value['selects'])){
                                foreach ($value['selects'] as $key => $sel) {
                                    $checkbox_select_name = $this->option_name.'['.$slug.'_'.$value['name'].'_'.$sel['name'].']';
                                    $checkbox_select_value = '';
                                    if(isset($settings[$slug.'_'.$value['name'].'_'.$sel['name']]) ) {
                                        $checkbox_select_value = $settings[$slug.'_'.$value['name'].'_'.$sel['name']];
                                    } else {
                                        $checkbox_select_value = $default_options[$slug.'_'.$value['name'].'_'.$sel['name']];
                                    }

                                    $output .= '<select id="'.esc_attr((string)$checkbox_select_name).'" name="'.esc_attr((string)$checkbox_select_name).'">';
                                        foreach ($sel['options'] as $key1 => $value1) {
                                            $selected = '';
                                            if(isset($settings[$slug.'_'.$value['name'].'_'.$sel['name']])){
                                                if($checkbox_select_value === $value1){
                                                    $selected = 'selected';
                                                }
                                            }
                                            $output .= '<option ' . $selected . ' value="' . esc_attr( (string)$value1 ) . '">' . esc_html( (string)$value1 ) . '</option>';
                                        }
                                    $output .= '</select>';

                                }
                            }
                            if(isset($value['selects'])){
                                $output .= '<div class="picky-more-time-wrap-all picky-inline">';

                                    $checkbox_name_more = $this->option_name.'['.$slug.'_'.$value['name'].'_more]';
                                    $opt_key_more = $slug . '_' . $value['name'] . '_more';
                                    $checkbox_value_more = '';
                                    $has_explicit_more = array_key_exists( $opt_key_more, $settings )
                                        && $settings[ $opt_key_more ] !== ''
                                        && $settings[ $opt_key_more ] !== null;
                                    if ( $has_explicit_more ) {
                                        $checkbox_value_more = (string) $settings[ $opt_key_more ];
                                    } elseif ( isset( $default_options[ $opt_key_more ] ) ) {
                                        $checkbox_value_more = (string) $default_options[ $opt_key_more ];
                                    }
                                    $checked_more = '';
                                    if ( $checkbox_value_more === '1' ) {
                                        $checked_more = 'checked';
                                    }

                                    $output .= '<div class="picky-more-time-wrap picky-inline">';
                                        $output .= '<span class="more-time-interval-label">' . esc_html__( 'Second time window', 'pickybooking' ) . '</span>';
                                        $output .= '<input type="hidden" name="'.esc_attr((string)$checkbox_name_more).'" value="0" />';
                                        $output .= '<input type="checkbox" id="'.esc_attr((string)$checkbox_name_more).'" name="'.esc_attr((string)$checkbox_name_more).'" '.$checked_more.' value="1"/>';
                                    $output .= '</div>';

                                    $output .= '<div class="picky-more-time-selects-wrap picky-inline" style="';
                                        if($checked_more === ""){
                                            $output .= 'display: none;';
                                        }
                                    $output .= '">';
                                        foreach ($value['selects'] as $key => $sel) { // _more
                                            $checkbox_select_name = $this->option_name.'['.$slug.'_'.$value['name'].'_'.$sel['name'].'_more]';
                                            $checkbox_select_value = '';
                                            if(isset($settings[$slug.'_'.$value['name'].'_'.$sel['name'].'_more']) ) {
                                                $checkbox_select_value = $settings[$slug.'_'.$value['name'].'_'.$sel['name'].'_more'];
                                            } else {
                                                $checkbox_select_value = $default_options[$slug.'_'.$value['name'].'_'.$sel['name'].'_more'];
                                            }

                                            $output .= '<select id="' . esc_attr( (string)$checkbox_select_name ) . '" name="' . esc_attr( (string)$checkbox_select_name ) . '">';
                                                foreach ($sel['options'] as $key1 => $value1) {
                                                    $selected = '';
                                                    if(isset($settings[$slug.'_'.$value['name'].'_'.$sel['name'].'_more'])){
                                                        if($checkbox_select_value === $value1){
                                                            $selected = 'selected';
                                                        }
                                                    }
                                                    $output .= '<option ' . $selected . ' value="' . esc_attr((string)$value1) . '">' . esc_html((string)$value1) . ' </option>';
                                                }
                                            $output .= '</select>';
                                        }
                                    $output .= '</div>';
                                $output .= '</div>';
                            }

                            $output .= '<div class="picky-inline">';
                                $output .= '<span class="picky-workday-open-label">' . esc_html__( 'Open', 'pickybooking' ) . '</span>';
                                $output .= '<input type="hidden" name="'.esc_attr((string)$checkbox_name).'" value="0" />';
                                $output .= '<input type="checkbox" id="'.esc_attr((string)$checkbox_name).'" name="'.esc_attr((string)$checkbox_name).'" '.$checked.' value="1"/>';
                            $output .= '</div>';
                        $output .= '</div>';
                    }  
                $output .= '</fieldset>';
            } else if($field['type'] === 'checkbox'){ // checkbox
                $checkbox_name = $this->option_name.'['.$slug.']';
                $checkbox_value = '';
                $has_explicit_checkbox = array_key_exists( $slug, $settings )
                    && $settings[ $slug ] !== ''
                    && $settings[ $slug ] !== null;
                if ( $has_explicit_checkbox ) {
                    $checkbox_value = (string) $settings[ $slug ];
                } elseif ( isset( $default_options[ $slug ] ) ) {
                    $checkbox_value = (string) $default_options[ $slug ];
                }
                $checked = '';
                if($checkbox_value === "1"){
                    $checked = 'checked';
                }
                $has_desc = ! empty( $field['description'] );
                if ( $has_desc ) {
                    $output .= '<div class="picky-settings-checkbox-with-desc">';
                    $output .= '<div class="picky-settings-card__desc picky-settings-field-desc">' . wp_kses_post( $field['description'] ) . '</div>';
                }
                $output .= '<p class="picky-settings-checkbox-wrap">';
                $output .= '<input type="checkbox" id="' . esc_attr( (string)$checkbox_name ) . '" name="' . esc_attr( (string)$checkbox_name ) . '" ' . $checked . ' value="1"/>';
                $output .= '</p>';
                if ( $has_desc ) {
                    $output .= '</div>';
                }
            } else if($field['type'] === 'colors'){ // colors
                $picky_booking_shell_bg_subtle = false;
                if ( $slug === 'color_main' ) {
                    $shortcode_layout_val = isset( $settings['shortcode_layout'] ) ? (string) $settings['shortcode_layout'] : '';
                    if ( $shortcode_layout_val === '' && isset( $default_options['shortcode_layout'] ) ) {
                        $shortcode_layout_val = (string) $default_options['shortcode_layout'];
                    }
                    $picky_booking_shell_bg_subtle = ( $shortcode_layout_val !== '2' && $shortcode_layout_val !== '3' && $shortcode_layout_val !== '4' );
                }
                $output .= '<div class="picky-settings-colors" role="group">';
                    foreach ($field['inputs'] as $key => $value) {
                        $color_name = $this->option_name.'['.$slug.'_'.$value['name'].']';
                        $color_title = $value['title'];
                        $color_value = '';
                        if(isset($settings[$slug.'_'.$value['name']])){
                            $color_value = $settings[$slug.'_'.$value['name']];
                        } else {
                            if(isset($default_options[$slug.'_'.$value['name']])){
                                $color_value = $default_options[$slug.'_'.$value['name']];
                            }
                        }
                        $output .= '<div class="picky-settings-color-row">';
                            $output .= '<div class="picky-settings-color-row__label">';
                                $output .= '<label for="'.esc_attr((string)$color_name).'">'.esc_html((string)$color_title).'</label>';
                                if ( $slug === 'color_main' && isset( $value['name'] ) && $value['name'] === 'main' && $picky_booking_shell_bg_subtle ) {
                                    $output .= $this->picky_settings_help_details(
                                        'color-main-shell-' . (string) $key_field,
                                        __( 'With the default wizard (table first or date/time first on the map page), this background is usually covered by the floor plan and booking modal, so changing it has little effect. It matters most for Smart layout and Third layout, where it tints the booking shell and calendar column.', 'pickybooking' )
                                    );
                                }
                            $output .= '</div>';
                            $output .= '<div class="picky-settings-color-row__control">';
                            $output .= '<input type="text" class="picky-settings-color" id="'.esc_attr((string)$color_name).'" name="'.esc_attr((string)$color_name).'" value="'.esc_attr((string)$color_value).'"/>';
                            $output .= '</div>';
                        $output .= '</div>';
                    }
                $output .= '</div>';
            } // end of choises

            $output .= '</div>';


            if($tab === "1"){
                $output_tab_1_fake .= $output;
            } else if($tab === "2"){
                $output_tab_2_fake .= $output;
            } else if($tab === "3"){
                $output_tab_3_fake .= $output;
            } else if($tab === "4"){
                $output_tab_4_fake .= $output;
            } else if($tab === "5"){
                $output_tab_5_fake .= $output;
            } else if($tab === "6"){
                $output_tab_6_fake .= $output;
            } else if($tab === "12"){
                $output_tab_12_fake .= $output;
            } else if($tab === ""){
                $output_all .= $output;
            }

        } // foreach

        if ( $picky_int_card_open ) {
            $output_tab_12_fake .= '</div></details></div></article>';
            $picky_int_card_open = false;
        }
        if ( $picky_int_grid_open ) {
            $output_tab_12_fake .= '</div>';
            $picky_int_grid_open = false;
        }
        if ( $picky_pay_card_open ) {
            $output_tab_5_fake .= '</div></details></div></article>';
            $picky_pay_card_open = false;
        }
        if ( $picky_pay_grid_open ) {
            $output_tab_5_fake .= '</div>';
            $picky_pay_grid_open = false;
        }
            
        if($output_all === ""){
            $plugin_title = Info::get_plugin_title();
            $output_tab .= '<div class="picky-settings-tabs-wrap picky-settings-tabs-wrap--surface">';
            $output_tab .= '<div class="picky-settings-tabs-header">';
            $output_tab .= '<div class="picky-settings-rail__top">';
            $output_tab .= '<div class="picky-settings-tabs-header-brand">';
            $logo_src = plugins_url( 'assets/img/picky-logo.png', dirname( __DIR__ ) . '/' . Info::MAIN_FILE );
            $output_tab .= '<div class="picky-logo-wrap">';
            $output_tab .= '<a class="picky-settings-rail__logo-link" href="' . esc_url( admin_url( 'admin.php?page=picky_settings' ) ) . '" title="' . esc_attr( (string) $plugin_title ) . '">';
            $output_tab .= '<img src="' . esc_url( $logo_src ) . '" width="36" height="36" alt="' . esc_attr__( 'PickyBooking Logo', 'pickybooking' ) . '" decoding="async" loading="eager">';
            $output_tab .= '</a>';
            /* Collapsed + hover: logo swaps to this expand control (ChatGPT-style). */
            $output_tab .= '<button type="button" class="picky-settings-nav-toggle picky-settings-nav-toggle--expand-hit" aria-label="' . esc_attr__( 'Expand sidebar', 'pickybooking' ) . '" title="' . esc_attr__( 'Expand sidebar', 'pickybooking' ) . '">';
            $output_tab .= '<span class="picky-settings-nav-toggle__icon" aria-hidden="true"><svg class="picky-sidebar-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3.75" y="4.75" width="16.5" height="14.5" rx="2.25" stroke="currentColor" stroke-width="1.6"/><path d="M9.25 5v14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>';
            $output_tab .= '<span class="picky-settings-nav-toggle__label screen-reader-text">' . esc_html__( 'Expand sidebar', 'pickybooking' ) . '</span></button>';
            $output_tab .= '<span class="picky-settings-rail__product-name">' . esc_html( (string) $plugin_title ) . '</span>';
            $output_tab .= '</div>';
            $output_tab .= '</div>';
            /* Expanded: collapse control on the right of the header (not an edge tab). */
            $output_tab .= '<button type="button" class="picky-settings-nav-toggle picky-settings-nav-toggle--collapse picky-settings-nav-toggle--header" aria-expanded="true" aria-label="' . esc_attr__( 'Collapse sidebar', 'pickybooking' ) . '" title="' . esc_attr__( 'Collapse sidebar', 'pickybooking' ) . '" data-title-expanded="' . esc_attr__( 'Collapse sidebar', 'pickybooking' ) . '" data-title-collapsed="' . esc_attr__( 'Expand sidebar', 'pickybooking' ) . '">';
            $output_tab .= '<span class="picky-settings-nav-toggle__icon" aria-hidden="true"><svg class="picky-sidebar-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="3.75" y="4.75" width="16.5" height="14.5" rx="2.25" stroke="currentColor" stroke-width="1.6"/><path d="M9.25 5v14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>';
            $output_tab .= '<span class="picky-settings-nav-toggle__label screen-reader-text">' . esc_html__( 'Collapse sidebar', 'pickybooking' ) . '</span></button>';
            $output_tab .= '</div>';

            $active_tab = '13';
            $candidate  = $this->picky_request_picky_tab_value();
            if ( $candidate !== '' ) {
                // Legacy redirect: Reservations tab (7) was removed in favor of the unified Bookings (orders) tab.
                if ( $candidate === '7' ) {
                    $candidate = '13';
                }
                $active_tab = $candidate;
            }
            $active_tab_state_class = $this->picky_settings_active_tab_class( $active_tab );
            $layout_id_req          = absint( picky_request_query_value( 'layout_id' ) );
            if ( '9' === (string) $active_tab && $layout_id_req > 0 ) {
                $active_tab_state_class .= ' builder-opened';
            }

            for ( $ti = 1; $ti <= 13; $ti++ ) {
                $an = 'active_' . $ti;
                $tn = 'tab_' . $ti;
                $$an = '';
                $$tn = 'display:none;';
            }
            $an = 'active_' . $active_tab;
            $tn = 'tab_' . $active_tab;
            $$an = 'active';
            $$tn = '';

            /* Scrollable nav (logo + rail-edge stay outside so the collapse tab is not clipped). */
            $output_tab .= '<nav class="picky-settings-tabs-header__nav" aria-label="' . esc_attr__( 'Plugin settings', 'pickybooking' ) . '">';
            $output_tab .= '<a href="#picky-settings-tab-13" data-tab="13" class="picky-settings-tabs-header-btn ' . esc_attr( (string) $active_13 ) . '" title="' . esc_attr__( 'Bookings', 'pickybooking' ) . '"><i class="icon-list"></i><span>' . esc_html__( 'Bookings', 'pickybooking' ) . '</span></a>';
            $output_tab .= '<a href="#picky-settings-tab-8" data-tab="8" class="picky-settings-tabs-header-btn ' . esc_attr( (string) $active_8 ) . '" title="' . esc_attr__( 'Calendar', 'pickybooking' ) . '"><i class="icon-maximize"></i><span>' . esc_html__( 'Calendar', 'pickybooking' ) . '</span></a>';
            $output_tab .= $this->picky_render_settings_header_layouts_nav( $active_9, $active_tab );

            $output_tab .= '<div class="picky-settings-tabs-nav-divider" role="presentation"><span class="picky-settings-tabs-nav-divider__label">' . esc_html__( 'Settings', 'pickybooking' ) . '</span></div>';

            if ( $output_tab_1_fake !== '' ) {
                $output_tab .= '<a href="#picky-settings-tab-1" data-tab="1" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_1 ) . '" title="' . esc_attr__( 'Main', 'pickybooking' ) . '"><i class="icon-settings"></i><span>' . esc_html__( 'Main', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_2_fake !== '' ) {
                $output_tab .= '<a href="#picky-settings-tab-2" data-tab="2" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_2 ) . '" title="' . esc_attr__( 'Work Days', 'pickybooking' ) . '"><i class="icon-calendar"></i><span>' . esc_html__( 'Work Days', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_3_fake !== '' && \Picky\Edition::allows( 'sms' ) ) {
                $output_tab .= '<a href="#picky-settings-tab-3" data-tab="3" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_3 ) . '" title="' . esc_attr__( 'SMS', 'pickybooking' ) . '"><i class="icon-smartphone"></i><span>' . esc_html__( 'SMS', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_4_fake !== '' ) {
                $output_tab .= '<a href="#picky-settings-tab-4" data-tab="4" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_4 ) . '" title="' . esc_attr__( 'Layout Colors', 'pickybooking' ) . '"><i class="icon-droplet"></i><span>' . esc_html__( 'Layout Colors', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_5_fake !== '' && \Picky\Edition::allows( 'payments' ) ) {
                $output_tab .= '<a href="#picky-settings-tab-5" data-tab="5" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_5 ) . '" title="' . esc_attr__( 'Payments', 'pickybooking' ) . '"><i class="icon-credit-card"></i><span>' . esc_html__( 'Payments', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_12_fake !== '' && \Picky\Edition::allows( 'integrations' ) ) {
                $output_tab .= '<a href="#picky-settings-tab-12" data-tab="12" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_12 ) . '" title="' . esc_attr__( 'Integrations', 'pickybooking' ) . '"><i class="icon-code" aria-hidden="true"></i><span>' . esc_html__( 'Integrations', 'pickybooking' ) . '</span></a>';
            }
            if ( $output_tab_6_fake !== '' ) {
                $output_tab .= '<a href="#picky-settings-tab-6" data-tab="6" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_6 ) . '" title="' . esc_attr__( 'Form & Emails', 'pickybooking' ) . '"><i class="icon-toggle-right"></i><span>' . esc_html__( 'Form & Emails', 'pickybooking' ) . '</span></a>';
            }
            if ( \Picky\Edition::allows( 'import_export' ) ) {
                $output_tab .= '<a href="#picky-settings-tab-10" data-tab="10" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_10 ) . '" title="' . esc_attr__( 'Import / Export', 'pickybooking' ) . '"><i class="icon-link"></i><span>' . esc_html__( 'Import / Export', 'pickybooking' ) . '</span></a>';
            }
            $output_tab .= '<a href="#picky-settings-tab-11" data-tab="11" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option ' . esc_attr( (string) $active_11 ) . '" title="' . esc_attr__( 'Documentation', 'pickybooking' ) . '"><i class="icon-life-buoy"></i><span>' . esc_html__( 'Documentation', 'pickybooking' ) . '</span></a>';
            if ( \Picky\Edition::is_lite() ) {
                $output_tab .= '<a href="' . esc_url( \Picky\Edition::PRO_URL ) . '" class="picky-settings-tabs-header-btn picky-settings-tabs-header-btn--option picky-settings-tabs-header-btn--pro" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'PickyBooking Pro', 'pickybooking' ) . '"><i class="icon-star"></i><span>' . esc_html__( 'Go Pro', 'pickybooking' ) . '</span></a>';
            }
            $output_tab .= '</nav>';
            $output_tab .= '</div>';

                $output_tab .= '<div class="picky-settings-tabs-wrap-inner picky-settings-app-panels ' . esc_attr( $active_tab_state_class ) . '">';
                $output_tab .= $this->picky_render_settings_app_tab_panels();
                $output_tab .= '</div>';

                $output_tab .= '<!--picky_settings_form_start-->';
                $output_tab .= '<div class="picky-settings-tabs-wrap-inner picky-settings-option-panels ' . esc_attr( $active_tab_state_class ) . '">';
                    if($output_tab_1_fake !== ""){
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_1 ) . '" data-tab="1" id="picky-settings-tab-1" class="picky-settings-tab picky-settings-tab-1 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_1_fake;
                        $output_tab .= '</div>';
                    }
                    if($output_tab_2_fake !== ""){
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_2 ) . '" data-tab="2" id="picky-settings-tab-2" class="picky-settings-tab picky-settings-tab-2 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_2_fake;
                        $output_tab .= '</div>';
                    }
                    if ( $output_tab_3_fake !== '' && \Picky\Edition::allows( 'sms' ) ) {
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_3 ) . '" data-tab="3" id="picky-settings-tab-3" class="picky-settings-tab picky-settings-tab-3 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_3_fake;
                        $output_tab .= '</div>';
                    }
                    if($output_tab_4_fake !== ""){
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_4 ) . '" data-tab="4" id="picky-settings-tab-4" class="picky-settings-tab picky-settings-tab-4 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_4_fake;
                        $output_tab .= '</div>';
                    }
                    if ( $output_tab_5_fake !== '' && \Picky\Edition::allows( 'payments' ) ) {
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_5 ) . '" data-tab="5" id="picky-settings-tab-5" class="picky-settings-tab picky-settings-tab-5 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_5_fake;
                        $output_tab .= '</div>';
                    }
                    if ( $output_tab_12_fake !== '' && \Picky\Edition::allows( 'integrations' ) ) {
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_12 ) . '" data-tab="12" id="picky-settings-tab-12" class="picky-settings-tab picky-settings-tab-12 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_12_fake;
                        $output_tab .= '</div>';
                    }
                    if($output_tab_6_fake !== ""){
                        $output_tab .= '<div style="' . esc_attr( (string)$tab_6 ) . '" data-tab="6" id="picky-settings-tab-6" class="picky-settings-tab picky-settings-tab-6 picky-settings-tab-panel">';
                            $output_tab .= $output_tab_6_fake;
                        $output_tab .= '</div>';
                    }
                $output_tab .= '</div>';
                $output_tab .= '<!--picky_settings_form_end-->';
            $output_tab .= '</div>';
            
            return $output_tab;
        } else {
            return $output_all;
        }

        
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * URL for the Layouts dashboard tab (optional embedded builder when layout_id > 0).
     *
     * @param int   $layout_id Layout post ID, or 0 for the layouts list only.
     * @param array $extra     Extra query args (e.g. saved, imported).
     * @return string
     */
    private function picky_layout_dashboard_url( $layout_id = 0, $extra = array() ) {
        $args = array(
            'page'    => 'picky_settings',
            'picky-tab' => '9',
        );
        if ( (int) $layout_id > 0 ) {
            $args['layout_id'] = absint( $layout_id );
        }
        if ( is_array( $extra ) ) {
            foreach ( $extra as $k => $v ) {
                if ( $v === null || $v === '' ) {
                    continue;
                }
                $args[ (string) $k ] = $v;
            }
        }
        return admin_url( add_query_arg( $args, 'admin.php' ) );
    }

    /**
     * URL for the Bookings dashboard tab (optional embedded order editor when order_id > 0).
     *
     * Orders are auto-created from frontend bookings and were previously edited on the native
     * `post.php?post=N&action=edit` screen, which broke the dashboard SPA flow. Routing every
     * "Edit order" link through this helper keeps the user inside the dashboard shell.
     *
     * @param int   $order_id Order post ID, or 0 for the bookings list only.
     * @param array $extra    Extra query args (e.g. saved=1).
     * @return string
     */
    public function picky_order_dashboard_url( $order_id = 0, $extra = array() ) {
        $args = array(
            'page'    => 'picky_settings',
            'picky-tab' => '13',
        );
        if ( (int) $order_id > 0 ) {
            $args['order_id'] = absint( $order_id );
        }
        if ( is_array( $extra ) ) {
            foreach ( $extra as $k => $v ) {
                if ( $v === null || $v === '' ) {
                    continue;
                }
                $args[ (string) $k ] = $v;
            }
        }
        return admin_url( add_query_arg( $args, 'admin.php' ) );
    }

    /**
     * Filter `get_edit_post_link` for picky_order: WP's list table (and any other code that asks
     * for an order's edit URL) gets the dashboard URL so clicks stay inside Settings tab 13
     * instead of jumping out to wp-admin/post.php.
     *
     * @param string $url     URL WP built for the post.
     * @param int    $post_id Post ID being linked.
     * @param string $context Context ('display'|'raw') — irrelevant here, returned as-is for non-orders.
     * @return string
     */
    public function picky_order_filter_edit_post_link( $url, $post_id, $context = 'display' ) {
        if ( ! $post_id ) {
            return $url;
        }
        if ( get_post_type( (int) $post_id ) !== 'picky_order' ) {
            return $url;
        }
        return $this->picky_order_dashboard_url( (int) $post_id );
    }

    /**
     * Override row actions for picky_order so the "Edit" action and any related admin links
     * route through the dashboard tab. Kept symmetrical with `picky_layout_row_actions`.
     */
    public function picky_order_row_actions( $actions, $post ) {
        if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== 'picky_order' ) {
            return $actions;
        }
        $url = $this->picky_order_dashboard_url( (int) $post->ID );
        if ( isset( $actions['edit'] ) ) {
            $actions['edit'] = '<a href="' . esc_url( (string) $url ) . '">' . esc_html__( 'Edit Order', 'pickybooking' ) . '</a>';
        }
        // Drop the inline editor — the embedded order edit screen is the canonical flow now.
        unset( $actions['inline hide-if-no-js'] );
        return $actions;
    }

    /**
     * Compact Bookings list title: "Order #123" (date / items / customer already have columns).
     *
     * @param string $title   Post title.
     * @param int    $post_id Post ID.
     * @return string
     */
    public function picky_order_list_title( $title, $post_id = 0 ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'picky_order' ) {
            return $title;
        }
        if ( ! is_admin() ) {
            return $title;
        }
        // List / embedded Bookings table only — keep full titles elsewhere.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! isset( $screen->id ) || (string) $screen->id !== 'edit-picky_order' ) {
            return $title;
        }
        /* translators: %d: order post ID */
        return sprintf( __( 'Order #%d', 'pickybooking' ), $post_id );
    }

    /**
     * Intercept native WP "Edit" requests for picky_order: redirect to the embedded dashboard editor.
     * Mirrors `redirect_edit_layout` so old bookmarks / external integrations still land in the SPA.
     */
    public function redirect_edit_order() {
        $action  = sanitize_key( picky_request_query_value( 'action' ) );
        $post_id = absint( picky_request_query_value( 'post' ) );
        if ( $action !== 'edit' || get_post_type( $post_id ) !== 'picky_order' ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! is_scalar( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'picky_edit_picky_order_' . $post_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }
        wp_safe_redirect( $this->picky_order_dashboard_url( (int) $post_id ) );
        exit;
    }

    /**
     * Render the embedded order-edit screen inside Bookings tab.
     *
     * Reuses the existing meta-box renderers from the Public class (`render_order_info` and
     * `render_order_items`) so the displayed data stays identical to the legacy native edit
     * screen — only the surrounding shell, navigation, and save flow change.
     *
     * @param int $order_id picky_order post id (already validated by caller).
     * @return void
     */
    private function picky_render_order_edit_embedded( $order_id ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for UI
        $order_id = absint( $order_id );
        if ( $order_id <= 0 || get_post_type( $order_id ) !== 'picky_order' ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $order_id ) ) {
            echo '<p class="description">' . esc_html__( 'Insufficient permissions.', 'pickybooking' ) . '</p>';
            return;
        }

        $order_post = get_post( $order_id );
        if ( ! ( $order_post instanceof \WP_Post ) ) {
            echo '<p class="description">' . esc_html__( 'Order not found.', 'pickybooking' ) . '</p>';
            return;
        }

        // Build a Public_Plugin instance on demand so we can reuse its meta-box renderers
        // without taking a hard dependency on the global plugin loader's instance graph.
        if ( ! class_exists( __NAMESPACE__ . '\\Public_Plugin' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-public.php';
        }
        $renderer = new Public_Plugin( $this->plugin_slug, $this->version, $this->option_name );

        $back_url    = $this->picky_order_dashboard_url( 0 );
        $saved_flag  = picky_request_query_value( 'saved' ) === '1';
        $status_now  = (string) get_post_status( $order_id );
        $status_map  = array(
            'requests' => __( 'Requested', 'pickybooking' ),
            'reserved' => __( 'Reserved', 'pickybooking' ),
            'canceled' => __( 'Canceled', 'pickybooking' ),
            'draft'    => __( 'Draft', 'pickybooking' ),
        );

        $title       = get_the_title( $order_id );
        if ( $title === '' ) {
            /* translators: %d: order id */
            $title = sprintf( __( 'Order #%d', 'pickybooking' ), $order_id );
        }
        $order_date  = (string) get_post_meta( $order_id, '_picky_order_date', true );
        $order_time  = (string) get_post_meta( $order_id, '_picky_order_time', true );
        $order_count = (int) get_post_meta( $order_id, '_picky_order_item_count', true );
        ?>
        <div class="picky-embedded-posts-list picky-embedded-posts-list--picky-order picky-reservations-custom picky-root picky-reservations-wrap picky-reservations-wrap--embedded picky-order-edit-embedded">

            <?php if ( $saved_flag ) : ?>
                <div class="picky-oe-toast picky-oe-toast--success" role="status">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M6 10.5l2.5 2.5 5.5-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e( 'Order saved successfully.', 'pickybooking' ); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="picky-order-edit-form picky-oe-status-form"
                data-picky-status-entity="order"
                data-picky-entity-id="<?php echo esc_attr( (string) $order_id ); ?>"
                data-picky-saved-status="<?php echo esc_attr( $status_now ); ?>"
                data-picky-status-nonce="<?php echo esc_attr( wp_create_nonce( 'picky_ajax_order_status_' . $order_id ) ); ?>">
                <input type="hidden" name="action" value="picky_save_order_embedded" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
                <?php wp_nonce_field( 'picky_save_order_' . $order_id, 'picky_order_nonce' ); ?>

                <div class="picky-order-editor">

                    <!-- ── Top bar: breadcrumb + status badge + save ── -->
                    <div class="picky-oe-topbar">
                        <div class="picky-oe-topbar__left">
                            <a class="picky-oe-back-link" href="<?php echo esc_url( $back_url ); ?>">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e( 'Bookings', 'pickybooking' ); ?>
                            </a>
                            <span class="picky-oe-sep" aria-hidden="true">/</span>
                            <span class="picky-oe-breadcrumb-current"><?php echo esc_html( $title ); ?></span>
                            <span class="picky-oe-status-badge picky-oe-status-badge--<?php echo esc_attr( $status_now ); ?>">
                                <span class="picky-oe-status-badge__dot" aria-hidden="true"></span>
                                <?php echo esc_html( isset( $status_map[ $status_now ] ) ? $status_map[ $status_now ] : ucfirst( $status_now ) ); ?>
                            </span>
                        </div>
                        <div class="picky-oe-topbar__right">
                            <button type="submit" class="picky-oe-save-btn">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13 4.5L6.5 11 3 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e( 'Save changes', 'pickybooking' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- ── Summary strip ── -->
                    <div class="picky-oe-summary">
                        <?php if ( $order_date !== '' ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <?php echo esc_html( $order_date . ( $order_time !== '' ? ' · ' . $order_time : '' ) ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $order_count > 0 ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="9" y="3" width="6" height="4" rx="1" stroke="currentColor" stroke-width="1.6"/></svg>
                                <?php
                                /* translators: %d: number of items in the order */
                                echo esc_html( sprintf( _n( '%d item', '%d items', $order_count, 'pickybooking' ), $order_count ) );
								?>
                            </div>
                        <?php endif; ?>
                        <div class="picky-oe-summary-item picky-oe-summary-item--id">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php
                            /* translators: %d: order ID */
                            echo esc_html( sprintf( __( 'ID #%d', 'pickybooking' ), $order_id ) );
							?>
                        </div>
                    </div>

                    <!-- ── Two-column card grid ── -->
                    <div class="picky-oe-grid">

                        <!-- Card: Customer Details -->
                        <div class="picky-oe-card picky-oe-card--details">
                            <div class="picky-oe-card__header">
                                <span class="picky-oe-card__icon" aria-hidden="true">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M4 20c0-4 3.582-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </span>
                                <h3 class="picky-oe-card__title"><?php esc_html_e( 'Customer Details', 'pickybooking' ); ?></h3>
                            </div>
                            <div class="picky-oe-card__body">
                                <?php $renderer->render_order_info( $order_post ); ?>
                            </div>
                        </div>

                        <!-- Card: Order Status -->
                        <div class="picky-oe-card picky-oe-card--status">
                            <div class="picky-oe-card__header">
                                <span class="picky-oe-card__icon" aria-hidden="true">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </span>
                                <h3 class="picky-oe-card__title"><?php esc_html_e( 'Order Status', 'pickybooking' ); ?></h3>
                            </div>
                            <div class="picky-oe-card__body">
                                <div class="picky-oe-status-field">
                                    <label class="picky-oe-label" for="picky-order-post-status"><?php esc_html_e( 'Change status', 'pickybooking' ); ?></label>
                                    <select name="post_status" id="picky-order-post-status" class="picky-oe-status-select">
                                        <?php foreach ( $status_map as $st_key => $st_label ) : ?>
                                            <option value="<?php echo esc_attr( $st_key ); ?>" <?php selected( $status_now, $st_key ); ?>><?php echo esc_html( $st_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="picky-oe-hint"><?php esc_html_e( 'Status saves automatically and applies to all items in this order.', 'pickybooking' ); ?></p>
                                </div>

                                <div class="picky-oe-status-feedback" role="status" aria-live="polite"></div>

                                <div class="picky-oe-status-pills" role="group" aria-label="<?php esc_attr_e( 'Quick status', 'pickybooking' ); ?>">
                                    <?php foreach ( $status_map as $st_key => $st_label ) : ?>
                                        <button type="button"
                                            class="picky-oe-pill picky-oe-pill--<?php echo esc_attr( $st_key ); ?><?php echo $status_now === $st_key ? ' is-active' : ''; ?>"
                                            data-picky-status-pill="<?php echo esc_attr( $st_key ); ?>"
                                            data-picky-target="picky-order-post-status">
                                            <?php echo esc_html( $st_label ); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php
                            $delete_url = get_delete_post_link( $order_id, '', false );
                            if ( $delete_url && current_user_can( 'delete_post', $order_id ) ) :
                            ?>
                            <div class="picky-oe-card__footer">
                                <a class="picky-oe-delete-link"
                                   href="<?php echo esc_url( (string) $delete_url ); ?>"
                                   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this order? Child bookings will also be removed.', 'pickybooking' ) ) ); ?>);">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M9 6V4h6v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php esc_html_e( 'Delete order', 'pickybooking' ); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div><!-- /.picky-oe-grid -->

                    <!-- ── Booking Items full-width card ── -->
                    <div class="picky-oe-card picky-oe-card--items">
                        <div class="picky-oe-card__header">
                            <span class="picky-oe-card__icon" aria-hidden="true">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.6"/><path d="M8 5V3M16 5V3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            </span>
                            <h3 class="picky-oe-card__title"><?php esc_html_e( 'Booking Items', 'pickybooking' ); ?></h3>
                            <?php if ( $order_count > 0 ) : ?>
                                <span class="picky-oe-item-count"><?php echo esc_html( (string) $order_count ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="picky-oe-card__body picky-oe-card__body--items">
                            <?php $renderer->render_order_items( $order_post ); ?>
                        </div>
                    </div>

                    <!-- ── Bottom action bar ── -->
                    <div class="picky-oe-actions">
                        <button type="submit" class="picky-oe-save-btn">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13 4.5L6.5 11 3 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Save changes', 'pickybooking' ); ?>
                        </button>
                        <a class="picky-oe-cancel-link" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Discard', 'pickybooking' ); ?></a>
                    </div>

                </div><!-- /.picky-order-editor -->
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * admin-post.php handler for the embedded order editor save button.
     *
     * Validates the nonce, persists order meta via the existing Public hook (`save_post_picky_order`
     * → `save_order_metadata`), updates the parent post status and cascades it to all child
     * bookings so list/state stays consistent across the order.
     *
     * @return void
     */
    public function save_order_embedded() {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        $order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? absint( wp_unslash( (string) $_POST['order_id'] ) ) : 0;
        check_admin_referer( 'picky_save_order_' . $order_id, 'picky_order_nonce' );
        if ( $order_id <= 0 || get_post_type( $order_id ) !== 'picky_order' ) {
            wp_die( esc_html__( 'Invalid order.', 'pickybooking' ), '', array( 'response' => 400 ) );
        }
        if ( ! current_user_can( 'edit_post', $order_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ), '', array( 'response' => 403 ) );
        }

        // Re-use existing public-class save handler so any meta sanitization stays in one place.
        if ( ! class_exists( __NAMESPACE__ . '\\Public_Plugin' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-public.php';
        }
        $renderer = new Public_Plugin( $this->plugin_slug, $this->version, $this->option_name );
        $renderer->save_order_metadata( $order_id );

        // Status cascade: parent + every child picky_booking gets the same post_status so the
        // dashboard never shows "1 reserved / 2 requests" mismatches inside one order.
        $valid_statuses = array( 'draft', 'picky_requests', 'picky_reserved', 'picky_canceled', 'requests', 'reserved', 'canceled' );
        $new_status     = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_status'] ) ) : '';
        if ( in_array( $new_status, $valid_statuses, true ) && $new_status !== get_post_status( $order_id ) ) {
            wp_update_post( array(
                'ID'          => $order_id,
                'post_status' => $new_status,
            ) );
            $children = get_posts( array(
                'post_type'      => 'picky_booking',
                'post_status'    => 'any',
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
            foreach ( $children as $cid ) {
                wp_update_post( array(
                    'ID'          => (int) $cid,
                    'post_status' => $new_status,
                ) );
            }
        }

        wp_safe_redirect( $this->picky_order_dashboard_url( $order_id, array( 'saved' => '1' ) ) );
        exit;
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    /**
     * Dashboard URL for the calendar tab (tab 8), optionally opening a single booking editor.
     *
     * @param int   $booking_id Booking post ID, or 0 for the calendar only.
     * @param array $extra      Extra query args (e.g. saved=1).
     * @return string
     */
    public function picky_booking_dashboard_url( $booking_id = 0, $extra = array() ) {
        $args = array(
            'page'    => 'picky_settings',
            'picky-tab' => '8',
        );
        $booking_id = absint( $booking_id );
        if ( $booking_id > 0 ) {
            $args['booking_id'] = $booking_id;
        }
        if ( is_array( $extra ) ) {
            foreach ( $extra as $k => $v ) {
                if ( $v === null || $v === '' ) {
                    continue;
                }
                $args[ (string) $k ] = $v;
            }
        }
        $url = admin_url( add_query_arg( $args, 'admin.php' ) );
        if ( $booking_id > 0 ) {
            $url = wp_nonce_url( $url, 'picky_open_booking_' . $booking_id );
        }
        return $url;
    }

    /**
     * Filter `get_edit_post_link` for picky_booking so list/calendar links stay inside the dashboard.
     *
     * @param string $url     URL WP built for the post.
     * @param int    $post_id Post ID being linked.
     * @param string $context Context ('display'|'raw').
     * @return string
     */
    public function picky_booking_filter_edit_post_link( $url, $post_id, $context = 'display' ) {
        unset( $context );
        if ( ! $post_id ) {
            return $url;
        }
        if ( get_post_type( (int) $post_id ) !== 'picky_booking' ) {
            return $url;
        }
        return $this->picky_booking_dashboard_url( (int) $post_id );
    }

    /**
     * Intercept native WP "Edit" requests for picky_booking: redirect to the embedded calendar editor.
     */
    public function redirect_edit_booking() {
        $action  = sanitize_key( picky_request_query_value( 'action' ) );
        $post_id = absint( picky_request_query_value( 'post' ) );
        if ( $action !== 'edit' || get_post_type( $post_id ) !== 'picky_booking' ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! is_scalar( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'picky_edit_picky_booking_' . $post_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }
        wp_safe_redirect( $this->picky_booking_dashboard_url( (int) $post_id ) );
        exit;
    }

    /**
     * Render the embedded booking-edit screen inside the Calendar tab.
     *
     * @param int $booking_id picky_booking post ID.
     * @return void
     */
    private function picky_render_booking_edit_embedded( $booking_id ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for UI
        $booking_id = absint( $booking_id );
        if ( $booking_id <= 0 || get_post_type( $booking_id ) !== 'picky_booking' ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $booking_id ) ) {
            echo '<p class="description">' . esc_html__( 'Insufficient permissions.', 'pickybooking' ) . '</p>';
            return;
        }

        $booking_post = get_post( $booking_id );
        if ( ! ( $booking_post instanceof \WP_Post ) ) {
            echo '<p class="description">' . esc_html__( 'Reservation not found.', 'pickybooking' ) . '</p>';
            return;
        }

        if ( current_user_can( 'manage_options' ) && isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'picky_open_booking_' . $booking_id ) ) {
            update_post_meta( $booking_id, '_picky_admin_seen', '1' );
        }

        if ( ! class_exists( __NAMESPACE__ . '\\Public_Plugin' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-public.php';
        }
        $renderer = new Public_Plugin( $this->plugin_slug, $this->version, $this->option_name );

        $back_url   = $this->picky_booking_dashboard_url( 0 );
        $saved_flag = picky_request_query_value( 'saved' ) === '1';
        $status_now = (string) get_post_status( $booking_id );
        $status_map = array(
            'requests' => __( 'Requested', 'pickybooking' ),
            'reserved' => __( 'Reserved', 'pickybooking' ),
            'canceled' => __( 'Canceled', 'pickybooking' ),
            'draft'    => __( 'Draft', 'pickybooking' ),
        );

        $title = get_the_title( $booking_id );
        if ( $title === '' ) {
            /* translators: %d: booking id */
            $title = sprintf( __( 'Reservation #%d', 'pickybooking' ), $booking_id );
        }

        $table_id  = (string) get_post_meta( $booking_id, '_picky_booking_id', true );
        $date      = (string) get_post_meta( $booking_id, '_picky_booking_date', true );
        $time      = (string) get_post_meta( $booking_id, '_picky_booking_time', true );
        $people    = (string) get_post_meta( $booking_id, '_picky_booking_people', true );
        $layout_id = (int) get_post_meta( $booking_id, '_picky_reservation_layout_id', true );
        $order_id  = (int) get_post_meta( $booking_id, '_picky_booking_order_id', true );
        /* translators: %s: configurable item name singular (e.g. Table, Room, Court) */
        $details_title = sprintf( __( '%s Reservation Details', 'pickybooking' ), $renderer->picky_item_label() );
        ?>
        <div class="picky-embedded-posts-list picky-reservations-custom picky-root picky-reservations-wrap picky-reservations-wrap--embedded picky-booking-edit-embedded">

            <?php if ( $saved_flag ) : ?>
                <div class="picky-oe-toast picky-oe-toast--success" role="status">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M6 10.5l2.5 2.5 5.5-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e( 'Reservation saved successfully.', 'pickybooking' ); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="picky-booking-edit-form picky-oe-status-form"
                data-picky-status-entity="booking"
                data-picky-entity-id="<?php echo esc_attr( (string) $booking_id ); ?>"
                data-picky-saved-status="<?php echo esc_attr( $status_now ); ?>"
                data-picky-status-nonce="<?php echo esc_attr( wp_create_nonce( 'picky_ajax_booking_status_' . $booking_id ) ); ?>">
                <input type="hidden" name="action" value="picky_save_booking_embedded" />
                <input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>" />
                <?php wp_nonce_field( 'picky_save_booking_' . $booking_id, 'picky_booking_nonce' ); ?>

                <div class="picky-order-editor">

                    <div class="picky-oe-topbar">
                        <div class="picky-oe-topbar__left">
                            <a class="picky-oe-back-link" href="<?php echo esc_url( $back_url ); ?>">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e( 'Calendar', 'pickybooking' ); ?>
                            </a>
                            <span class="picky-oe-sep" aria-hidden="true">/</span>
                            <span class="picky-oe-breadcrumb-current"><?php echo esc_html( $title ); ?></span>
                            <span class="picky-oe-status-badge picky-oe-status-badge--<?php echo esc_attr( $status_now ); ?>">
                                <span class="picky-oe-status-badge__dot" aria-hidden="true"></span>
                                <?php echo esc_html( isset( $status_map[ $status_now ] ) ? $status_map[ $status_now ] : ucfirst( $status_now ) ); ?>
                            </span>
                        </div>
                        <div class="picky-oe-topbar__right">
                            <button type="submit" class="picky-oe-save-btn">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13 4.5L6.5 11 3 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e( 'Save changes', 'pickybooking' ); ?>
                            </button>
                        </div>
                    </div>

                    <div class="picky-oe-summary">
                        <?php if ( $date !== '' ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <?php echo esc_html( $date . ( $time !== '' ? ' · ' . $time : '' ) ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $table_id !== '' ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.6"/></svg>
                                <?php echo esc_html( $renderer->picky_item_label() . ' ' . $table_id ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $layout_id > 0 ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5h16v14H4z" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h16M9 9v10" stroke="currentColor" stroke-width="1.6"/></svg>
                                <?php echo esc_html( (string) get_the_title( $layout_id ) ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $people !== '' && $people !== '0' ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M3 19c0-3.5 2.686-6 6-6s6 2.5 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M17 11v6M14 14h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <?php
                                /* translators: %s: number of guests */
                                echo esc_html( sprintf( _n( '%s guest', '%s guests', (int) $people, 'pickybooking' ), $people ) );
								?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $order_id > 0 && get_post_type( $order_id ) === 'picky_order' ) : ?>
                            <div class="picky-oe-summary-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="9" y="3" width="6" height="4" rx="1" stroke="currentColor" stroke-width="1.6"/></svg>
                                <a href="<?php echo esc_url( $this->picky_order_dashboard_url( $order_id ) ); ?>"><?php
									/* translators: %d: order ID */
									echo esc_html( sprintf( __( 'Order #%d', 'pickybooking' ), $order_id ) );
								?></a>
                            </div>
                        <?php endif; ?>
                        <div class="picky-oe-summary-item picky-oe-summary-item--id">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php
                            /* translators: %d: booking ID */
                            echo esc_html( sprintf( __( 'ID #%d', 'pickybooking' ), $booking_id ) );
							?>
                        </div>
                    </div>

                    <div class="picky-oe-grid">

                        <div class="picky-oe-card picky-oe-card--details">
                            <div class="picky-oe-card__header">
                                <span class="picky-oe-card__icon" aria-hidden="true">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.6"/></svg>
                                </span>
                                <h3 class="picky-oe-card__title"><?php echo esc_html( $details_title ); ?></h3>
                            </div>
                            <div class="picky-oe-card__body">
                                <?php $renderer->render_location_info( $booking_post ); ?>
                            </div>
                        </div>

                        <div class="picky-oe-card picky-oe-card--status">
                            <div class="picky-oe-card__header">
                                <span class="picky-oe-card__icon" aria-hidden="true">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </span>
                                <h3 class="picky-oe-card__title"><?php esc_html_e( 'Reservation Status', 'pickybooking' ); ?></h3>
                            </div>
                            <div class="picky-oe-card__body">
                                <div class="picky-oe-status-field">
                                    <label class="picky-oe-label" for="picky-booking-post-status"><?php esc_html_e( 'Change status', 'pickybooking' ); ?></label>
                                    <select name="post_status" id="picky-booking-post-status" class="picky-oe-status-select">
                                        <?php foreach ( $status_map as $st_key => $st_label ) : ?>
                                            <option value="<?php echo esc_attr( $st_key ); ?>" <?php selected( $status_now, $st_key ); ?>><?php echo esc_html( $st_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="picky-oe-hint"><?php esc_html_e( 'Status saves automatically when you change it.', 'pickybooking' ); ?></p>
                                </div>

                                <div class="picky-oe-status-feedback" role="status" aria-live="polite"></div>

                                <div class="picky-oe-status-pills" role="group" aria-label="<?php esc_attr_e( 'Quick status', 'pickybooking' ); ?>">
                                    <?php foreach ( $status_map as $st_key => $st_label ) : ?>
                                        <button type="button"
                                            class="picky-oe-pill picky-oe-pill--<?php echo esc_attr( $st_key ); ?><?php echo $status_now === $st_key ? ' is-active' : ''; ?>"
                                            data-picky-status-pill="<?php echo esc_attr( $st_key ); ?>"
                                            data-picky-target="picky-booking-post-status">
                                            <?php echo esc_html( $st_label ); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <?php
                                $payment_now  = sanitize_key( (string) get_post_meta( $booking_id, '_picky_payment_status', true ) );
                                $payment_map  = $this->picky_payment_status_map();
                                if ( $payment_now === '' || $payment_now === 'none' ) {
                                    $payment_now = 'not_required';
                                } elseif ( in_array( $payment_now, array( 'cancelled', 'init_failed' ), true ) ) {
                                    $payment_now = 'not_paid';
                                }
                                if ( ! isset( $payment_map[ $payment_now ] ) ) {
                                    $payment_now = 'not_required';
                                }
                                ?>
                                <div class="picky-oe-payment-field"
                                    data-picky-payment-booking-id="<?php echo esc_attr( (string) $booking_id ); ?>"
                                    data-picky-saved-payment="<?php echo esc_attr( $payment_now ); ?>"
                                    data-picky-payment-nonce="<?php echo esc_attr( wp_create_nonce( 'picky_ajax_booking_payment_' . $booking_id ) ); ?>">
                                    <label class="picky-oe-label" for="picky-booking-payment-status"><?php esc_html_e( 'Payment status', 'pickybooking' ); ?></label>
                                    <select name="picky_payment_status" id="picky-booking-payment-status" class="picky-oe-payment-select">
                                        <?php foreach ( $payment_map as $pay_key => $pay_label ) : ?>
                                            <option value="<?php echo esc_attr( $pay_key ); ?>" <?php selected( $payment_now, $pay_key ); ?>><?php echo esc_html( $pay_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="picky-oe-hint"><?php esc_html_e( 'Mark as paid if the guest paid offline (cash, transfer, etc.). Saves automatically.', 'pickybooking' ); ?></p>
                                    <div class="picky-oe-payment-feedback" role="status" aria-live="polite"></div>
                                    <div class="picky-oe-payment-pills" role="group" aria-label="<?php esc_attr_e( 'Quick payment status', 'pickybooking' ); ?>">
                                        <?php foreach ( $payment_map as $pay_key => $pay_label ) : ?>
                                            <button type="button"
                                                class="picky-oe-pill picky-oe-pill--pay-<?php echo esc_attr( $pay_key ); ?><?php echo $payment_now === $pay_key ? ' is-active' : ''; ?>"
                                                data-picky-payment-pill="<?php echo esc_attr( $pay_key ); ?>">
                                                <?php echo esc_html( $pay_label ); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $delete_url = get_delete_post_link( $booking_id, '', false );
                            if ( $delete_url && current_user_can( 'delete_post', $booking_id ) ) :
                            ?>
                            <div class="picky-oe-card__footer">
                                <a class="picky-oe-delete-link"
                                   href="<?php echo esc_url( (string) $delete_url ); ?>"
                                   onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this reservation?', 'pickybooking' ) ) ); ?>);">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M9 6V4h6v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php esc_html_e( 'Delete reservation', 'pickybooking' ); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div><!-- /.picky-oe-grid -->

                    <div class="picky-oe-actions">
                        <button type="submit" class="picky-oe-save-btn">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13 4.5L6.5 11 3 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Save changes', 'pickybooking' ); ?>
                        </button>
                        <a class="picky-oe-cancel-link" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Discard', 'pickybooking' ); ?></a>
                    </div>

                </div><!-- /.picky-order-editor -->
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * admin-post.php handler for the embedded booking editor save button.
     *
     * @return void
     */
    public function save_booking_embedded() {
        $booking_id = isset( $_POST['booking_id'] ) && is_scalar( $_POST['booking_id'] ) ? absint( wp_unslash( (string) $_POST['booking_id'] ) ) : 0;
        check_admin_referer( 'picky_save_booking_' . $booking_id, 'picky_booking_nonce' );
        if ( $booking_id <= 0 || get_post_type( $booking_id ) !== 'picky_booking' ) {
            wp_die( esc_html__( 'Invalid reservation.', 'pickybooking' ), '', array( 'response' => 400 ) );
        }
        if ( ! current_user_can( 'edit_post', $booking_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ), '', array( 'response' => 403 ) );
        }

        if ( ! class_exists( __NAMESPACE__ . '\\Public_Plugin' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-public.php';
        }
        $renderer = new Public_Plugin( $this->plugin_slug, $this->version, $this->option_name );
        $renderer->save_metadata( $booking_id );

        $valid_statuses = array( 'draft', 'picky_requests', 'picky_reserved', 'picky_canceled', 'requests', 'reserved', 'canceled' );
        $new_status     = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_status'] ) ) : '';
        if ( in_array( $new_status, $valid_statuses, true ) && $new_status !== get_post_status( $booking_id ) ) {
            wp_update_post( array(
                'ID'          => $booking_id,
                'post_status' => $new_status,
            ) );
        }

        if ( isset( $_POST['picky_payment_status'] ) && is_scalar( $_POST['picky_payment_status'] ) ) {
            $this->picky_update_booking_payment_status(
                $booking_id,
                sanitize_key( wp_unslash( (string) $_POST['picky_payment_status'] ) )
            );
        }

        wp_safe_redirect( $this->picky_booking_dashboard_url( $booking_id, array( 'saved' => '1' ) ) );
        exit;
    }

    /**
     * Valid custom post statuses for bookings and orders.
     *
     * @return string[]
     */
    private function picky_reservation_status_keys() {
        return array( 'draft', 'picky_requests', 'picky_reserved', 'picky_canceled', 'requests', 'reserved', 'canceled' );
    }

    /**
     * Human-readable labels for reservation statuses.
     *
     * @return array<string, string>
     */
    private function picky_reservation_status_map() {
        return array(
            'requests' => __( 'Requested', 'pickybooking' ),
            'reserved' => __( 'Reserved', 'pickybooking' ),
            'canceled' => __( 'Canceled', 'pickybooking' ),
            'draft'    => __( 'Draft', 'pickybooking' ),
        );
    }

    /**
     * AJAX: save booking status immediately from the embedded editor.
     */
    public function ajax_save_booking_status() {
        $booking_id = isset( $_POST['booking_id'] ) && is_scalar( $_POST['booking_id'] ) ? absint( wp_unslash( (string) $_POST['booking_id'] ) ) : 0;
        check_ajax_referer( 'picky_ajax_booking_status_' . $booking_id, 'nonce' );
        if ( $booking_id <= 0 || get_post_type( $booking_id ) !== 'picky_booking' ) {
            wp_send_json_error( array( 'message' => __( 'Invalid reservation.', 'pickybooking' ) ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $booking_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pickybooking' ) ), 403 );
        }

        $new_status = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_status'] ) ) : '';
        if ( ! in_array( $new_status, $this->picky_reservation_status_keys(), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid status.', 'pickybooking' ) ), 400 );
        }

        $current = (string) get_post_status( $booking_id );
        if ( $new_status !== $current ) {
            wp_update_post( array(
                'ID'          => $booking_id,
                'post_status' => $new_status,
            ) );
        }

        $labels = $this->picky_reservation_status_map();
        wp_send_json_success( array(
            'status' => $new_status,
            'label'  => isset( $labels[ $new_status ] ) ? $labels[ $new_status ] : ucfirst( $new_status ),
        ) );
    }

    /**
     * Allowed payment status keys for admin booking editor.
     *
     * @return string[]
     */
    private function picky_payment_status_keys() {
        return array( 'paid', 'pending', 'not_paid', 'not_required' );
    }

    /**
     * Labels for payment statuses in the booking editor.
     *
     * @return array<string, string>
     */
    private function picky_payment_status_map() {
        return array(
            'paid'         => __( 'Paid', 'pickybooking' ),
            'pending'      => __( 'Pending', 'pickybooking' ),
            'not_paid'     => __( 'Unpaid', 'pickybooking' ),
            'not_required' => __( 'No payment required', 'pickybooking' ),
        );
    }

    /**
     * Persist booking payment status meta (and mark provider as manual when admin sets paid).
     *
     * @param int    $booking_id Booking post ID.
     * @param string $status     Payment status key.
     * @return string|false Normalized status on success, false on invalid.
     */
    private function picky_update_booking_payment_status( $booking_id, $status ) {
        $booking_id = absint( $booking_id );
        $status     = sanitize_key( (string) $status );
        if ( $booking_id <= 0 || ! in_array( $status, $this->picky_payment_status_keys(), true ) ) {
            return false;
        }

        update_post_meta( $booking_id, '_picky_payment_status', $status );

        if ( $status === 'paid' ) {
            $provider = sanitize_key( (string) get_post_meta( $booking_id, '_picky_payment_provider', true ) );
            if ( $provider === '' || $provider === 'none' ) {
                update_post_meta( $booking_id, '_picky_payment_provider', 'manual' );
            }
        } elseif ( $status === 'not_required' ) {
            $provider = sanitize_key( (string) get_post_meta( $booking_id, '_picky_payment_provider', true ) );
            if ( $provider === '' || $provider === 'manual' ) {
                update_post_meta( $booking_id, '_picky_payment_provider', 'none' );
            }
        }

        return $status;
    }

    /**
     * AJAX: save booking payment status (Pro).
     */
    public function ajax_save_booking_payment() {
        wp_send_json_error( array( 'message' => __( 'Not available.', 'pickybooking' ) ), 404 );
    }


    /**
     * AJAX: save order status immediately and cascade to child bookings.
     */
    public function ajax_save_order_status() {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        $order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? absint( wp_unslash( (string) $_POST['order_id'] ) ) : 0;
        check_ajax_referer( 'picky_ajax_order_status_' . $order_id, 'nonce' );
        if ( $order_id <= 0 || get_post_type( $order_id ) !== 'picky_order' ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order.', 'pickybooking' ) ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $order_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'pickybooking' ) ), 403 );
        }

        $new_status = isset( $_POST['post_status'] ) && is_scalar( $_POST['post_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_status'] ) ) : '';
        if ( ! in_array( $new_status, $this->picky_reservation_status_keys(), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid status.', 'pickybooking' ) ), 400 );
        }

        if ( $new_status !== get_post_status( $order_id ) ) {
            wp_update_post( array(
                'ID'          => $order_id,
                'post_status' => $new_status,
            ) );
            $children = get_posts( array(
                'post_type'      => 'picky_booking',
                'post_status'    => 'any',
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
            foreach ( $children as $cid ) {
                wp_update_post( array(
                    'ID'          => (int) $cid,
                    'post_status' => $new_status,
                ) );
            }
        }

        $labels = $this->picky_reservation_status_map();
        wp_send_json_success( array(
            'status' => $new_status,
            'label'  => isset( $labels[ $new_status ] ) ? $labels[ $new_status ] : ucfirst( $new_status ),
        ) );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    /**
     * Header nav: Layouts tab + submenu (All layouts as parent, named layouts nested under it).
     *
     * @param string $active_class Active class string for the primary Layouts control (e.g. 'active').
     * @param string $active_tab   Current tab index ('7', '9', …).
     * @return string
     */
    private function picky_render_settings_header_layouts_nav( $active_class, $active_tab ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for UI
        $current_layout = absint( picky_request_query_value( 'layout_id' ) );
        $layouts        = \Picky\Layout_Library::get_user_layout_posts( 'title', 'ASC' );
        $pto              = get_post_type_object( 'picky_layout' );
        $can_add_layout   = $pto && ! empty( $pto->cap->create_posts ) && current_user_can( $pto->cap->create_posts );
        $layouts_add_url  = $this->picky_new_layout_url();

        $out  = '<div class="picky-settings-tabs-header-item picky-settings-tabs-header-item--layouts">';
        $out .= '<a href="#picky-settings-tab-9" data-tab="9" class="picky-settings-tabs-header-btn ' . esc_attr( (string) $active_class ) . '" title="' . esc_attr__( 'Layouts', 'pickybooking' ) . '" aria-haspopup="true" aria-expanded="' . ( $active_tab === '9' ? 'true' : 'false' ) . '"><i class="icon-layers" aria-hidden="true"></i><span>' . esc_html__( 'Layouts', 'pickybooking' ) . '</span></a>';
        $out .= '<ul class="picky-settings-tabs-submenu picky-settings-tabs-submenu--flat" role="list" aria-label="' . esc_attr__( 'Open or switch layout', 'pickybooking' ) . '">';
        foreach ( $layouts as $p ) {
            $lid    = (int) $p->ID;
            $title  = get_the_title( $p );
            if ( $title === '' ) {
                /* translators: %d: layout post ID */
                $title = sprintf( __( 'Layout #%d', 'pickybooking' ), $lid );
            }
            $sub_active = ( $active_tab === '9' && $current_layout === $lid );
            $out       .= '<li class="picky-settings-tabs-submenu__item">';
            $out       .= '<a class="picky-settings-tabs-submenu__link' . ( $sub_active ? ' is-active' : '' ) . '" href="' . esc_url( $this->picky_layout_dashboard_url( $lid ) ) . '" title="' . esc_attr( (string) $title ) . '">' . esc_html( (string) $title ) . '</a>';
            $out       .= '</li>';
        }
        if ( $can_add_layout ) {
            $out .= '<li class="picky-settings-tabs-submenu__item"><a class="picky-settings-tabs-submenu__link addnew-icon" href="' . esc_url( $layouts_add_url ) . '">' . esc_html__( 'Add new', 'pickybooking' ) . '</a></li>';
        }
        $out .= '</ul></div>';

        return $out;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Output layout builder markup (standalone or embedded in the Layouts tab).
     *
     * @param int  $layout_id Post ID of picky_layout.
     * @param bool $embedded  When true, omits duplicate page chrome (inside Settings).
     * @return void
     */
    private function picky_render_layout_builder( $layout_id, $embedded = false ) {
        $layout_id = absint( $layout_id );
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            return;
        }

        $this->picky_migrate_layout_to_library( $layout_id );

        $picky_layout_embedded = (bool) $embedded;

        $layout_post     = get_post( $layout_id );
        $layout_title    = $layout_post ? $layout_post->post_title : __( 'Layout', 'pickybooking' );
        $layout_position = get_post_meta( $layout_id, '_picky_layout_position', true );
        if ( ! $layout_position ) {
            $layout_position = '';
        }

        $options = Info::get_settings();
        $per_layout_w = (int) get_post_meta( $layout_id, '_picky_layout_canvas_width', true );
        $per_layout_h = (int) get_post_meta( $layout_id, '_picky_layout_canvas_height', true );
        if ( $per_layout_w > 0 ) {
            $options['canvas_width'] = $per_layout_w;
        }
        if ( $per_layout_h > 0 ) {
            $options['canvas_height'] = $per_layout_h;
        }

        $settings = $options;
        $option_name    = INFO::OPTION_NAME;
        $heading        = Info::get_plugin_title();
        $submit_text    = __( 'Save Layout', 'pickybooking' );
        $tables         = \Picky\Edition::tables_palette();
        $builder_font_family_options = $this->picky_builder_font_family_options();

        $background_canvas = $this->custom_image_uploader_field( 'picky_layout_bg_canvas', get_post_meta( $layout_id, '_picky_layout_bg_canvas', true ) );
        $background_table  = $this->custom_image_uploader_field( 'picky_layout_bg_table', get_post_meta( $layout_id, '_picky_layout_bg_table', true ) );

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/view_layout.php';
    }

    /**
     * Collapse duplicate query keys (e.g. picky-tab twice) into one via wp_parse_str + build_query.
     *
     * @param string $url Full URL.
     * @return string
     */
    private function picky_normalize_admin_url_query( $url ) {
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['query'] ) || ! is_string( $p['query'] ) ) {
            return $url;
        }
        wp_parse_str( $p['query'], $args );
        if ( ! is_array( $args ) ) {
            return $url;
        }
        $query = build_query( $args );
        $out   = '';
        if ( isset( $p['scheme'] ) && is_string( $p['scheme'] ) ) {
            $out .= $p['scheme'] . '://';
        }
        if ( isset( $p['host'] ) && is_string( $p['host'] ) ) {
            $out .= $p['host'];
        }
        if ( isset( $p['port'] ) ) {
            $out .= ':' . (int) $p['port'];
        }
        if ( isset( $p['path'] ) && is_string( $p['path'] ) ) {
            $out .= $p['path'];
        }
        $out .= ( $query !== '' ) ? '?' . $query : '';
        if ( isset( $p['fragment'] ) && is_string( $p['fragment'] ) && $p['fragment'] !== '' ) {
            $out .= '#' . $p['fragment'];
        }
        return $out;
    }

    /**
     * Single picky-tab value for the current request.
     * Duplicate keys resolve to the last value. The value is sanitized before it is used.
     *
     * @return string Allowed tab id or '' if absent/invalid.
     */
    private function picky_request_picky_tab_value() {
        $allowed = array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13' );
        $bag     = picky_request_query_bag();
        $raw = isset( $bag['picky-tab'] ) ? $bag['picky-tab'] : '';
        if ( is_array( $raw ) ) {
            $last = end( $raw );
            $raw  = is_scalar( $last ) ? sanitize_text_field( (string) $last ) : '';
        }
        $candidate = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
        if ( $candidate === '' || ! in_array( $candidate, $allowed, true ) ) {
            return '';
        }
        return $candidate;
    }

    /**
     * Active-tab state class for settings wrappers.
     *
     * @param string $tab
     * @return string
     */
    private function picky_settings_active_tab_class( $tab ) {
        $map = array(
            '1'  => 'is-tab-main',
            '2'  => 'is-tab-work-days',
            '3'  => 'is-tab-sms',
            '4'  => 'is-tab-layout-colors',
            '5'  => 'is-tab-payments',
            '6'  => 'is-tab-form-emails',
            '7'  => 'is-tab-reservations',
            '8'  => 'is-tab-calendar',
            '9'  => 'is-tab-layouts',
            '10' => 'is-tab-import-export',
            '11' => 'is-tab-documentation',
            '12' => 'is-tab-integrations',
            '13' => 'is-tab-orders',
        );
        return isset( $map[ $tab ] ) ? $map[ $tab ] : 'is-tab-unknown';
    }

    /**
     * Rewrite list-table links so embedded lists stay on admin.php?page=picky_settings.
     * WP_Posts_List_Table::get_views() uses edit.php — clicks otherwise leave Settings (wrong tab/context).
     *
     * @param string               $html             Fragment HTML (views + table).
     * @param array<string, mixed> $canonical_query  Embed sandbox query (page, post_type, picky-tab, filters).
     * @return string
     */
    private function picky_rewrite_embedded_posts_list_html( $html, array $canonical_query ) {
        $html = (string) $html;
        if ( $html === '' ) {
            return $html;
        }
        $that = $this;
        return (string) preg_replace_callback(
            '/\bhref\s*=\s*(["\'])([^"\']*)\1/i',
            static function ( array $m ) use ( $that, $canonical_query ) : string {
                $quo = $m[1];
                $href = $m[2];
                $new = $that->picky_canonicalize_embedded_list_href( $href, $canonical_query );
                if ( $new === null ) {
                    return $m[0];
                }
                return 'href=' . $quo . esc_url( $new ) . $quo;
            },
            $html
        );
    }

    /**
     * Map edit.php / admin.php list URLs to the embedded settings URL for this tab.
     *
     * @param string               $href            Raw href from markup.
     * @param array<string, mixed> $canonical_query Embed sandbox query.
     * @return string|null New absolute admin URL, or null to leave href unchanged.
     */
    private function picky_canonicalize_embedded_list_href( $href, array $canonical_query ) {
        $expected_pt = isset( $canonical_query['post_type'] ) ? (string) $canonical_query['post_type'] : '';
        $href          = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $href          = trim( $href );
        if ( $href === '' ) {
            return null;
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            $full = $href;
        } elseif ( strpos( $href, '/' ) === 0 ) {
            $full = site_url( $href );
        } else {
            $full = admin_url( $href );
        }
        $path = wp_parse_url( $full, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $file = $path !== '' ? basename( $path ) : '';
        $qs   = wp_parse_url( $full, PHP_URL_QUERY );
        $qs   = is_string( $qs ) ? $qs : '';
        wp_parse_str( $qs, $args );
        if ( ! is_array( $args ) ) {
            $args = array();
        }
        if ( $file === 'edit.php' ) {
            if ( ! isset( $args['post_type'] ) || (string) $args['post_type'] !== $expected_pt ) {
                return null;
            }
            /*
             * Canonical query fixes tab/post_type/page context; link-specific args must win (paged, orderby,
             * order, post_status, etc.). array_merge( $args, $canonical_query ) overwrote href paged with the
             * current page — pagination Next/Prev stayed on the same page after the first click.
             */
            $merged = array_merge( $canonical_query, $args );
            $out    = add_query_arg( $merged, admin_url( 'admin.php' ) );
            return $this->picky_normalize_admin_url_query( $out );
        }
        if ( $file === 'admin.php' ) {
            if ( isset( $args['page'] ) && (string) $args['page'] !== 'picky_settings' ) {
                return null;
            }
            $merged = array_merge( $canonical_query, $args );
            $out    = add_query_arg( $merged, admin_url( 'admin.php' ) );
            return $this->picky_normalize_admin_url_query( $out );
        }
        return null;
    }

    /**
     * Canonicalize list URLs for embedded Settings markup built outside href= attributes (e.g. select options).
     *
     * @param string               $href
     * @param array<string, mixed> $canonical_query
     * @return string|null
     */
    public function picky_canonicalize_embedded_list_href_for_markup( $href, array $canonical_query ) {
        return $this->picky_canonicalize_embedded_list_href( (string) $href, $canonical_query );
    }

    /**
     * Native WP posts list (no iframe) inside Settings — same table as edit.php.
     *
     * @param string $post_type Post type slug.
     * @param string $picky_tab   Tab index for return URL (7 = reservations, 9 = layouts).
     * @return void
     */
    private function picky_render_embedded_posts_list( $post_type, $picky_tab ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only list UI from request
        $pto = get_post_type_object( $post_type );
        if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
            echo '<p class="description">' . esc_html__( 'Insufficient permissions.', 'pickybooking' ) . '</p>';
            return;
        }

        $backup_get     = $this->picky_sanitize_input_array( picky_request_query_bag() );
        $backup_request = $backup_get;
        $backup_uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

        /*
         * Sandbox GET/REQUEST + REQUEST_URI for this embedded list before prepare_items().
         * WP_Posts_List_Table::prepare_items() calls wp_edit_posts_query(), which reads $_GET.
         * If the loaded URL was another tab (e.g. Calendar picky-tab=8), leaving $_GET untouched
         * breaks paging/totals vs pagination links (built later from REQUEST_URI).
         */
        $embed_query       = $backup_get;
        $embed_query['page']      = 'picky_settings';
        $embed_query['post_type'] = $post_type;
        $embed_query['picky-tab']   = (string) $picky_tab;
        $strip_embed_noise        = array( 'view', 'mode', 'saved_view', 'date', 'layout_id', 'order_id', 'saved', 'table_id', 'sort_by', 'density', 'status' );
        foreach ( $strip_embed_noise as $noise_key ) {
            unset( $embed_query[ $noise_key ] );
        }

        /*
         * Inactive tab panels are pre-rendered server-side for instant SPA tab switching.
         * Their list query MUST NOT inherit the active tab's paged/s/orderby — `WP_List_Table::set_pagination_args()`
         * does `wp_redirect( add_query_arg('paged', $total_pages) )` whenever requested `paged` exceeds this tab's
         * total pages, hijacking the user away from (e.g.) Reservations page 2 to Layouts page 1.
         * Active tab keeps the URL state; inactive tabs reset to "page 1, no filters".
         */
        $active_tab_for_embed = $this->picky_request_picky_tab_value();
        if ( $active_tab_for_embed === '' ) {
            $active_tab_for_embed = '13';
        }
        // Tab 7 (Reservations) folded into tab 13 (Bookings/Orders).
        if ( $active_tab_for_embed === '7' ) {
            $active_tab_for_embed = '13';
        }
        if ( $active_tab_for_embed !== (string) $picky_tab ) {
            $reset_for_inactive = array( 'paged', 's', 'orderby', 'order', 'm', 'cat', 'post_status' );
            foreach ( $reset_for_inactive as $reset_key ) {
                unset( $embed_query[ $reset_key ] );
            }
        }
        $GLOBALS['_GET'] = $embed_query;
        foreach ( $strip_embed_noise as $noise_key ) {
            unset( $GLOBALS['_REQUEST'][ $noise_key ] );
        }
        if ( $active_tab_for_embed !== (string) $picky_tab ) {
            // Same reset list as $reset_for_inactive above — get_pagenum() reads the request paged value.
            foreach ( array( 'paged', 's', 'orderby', 'order', 'm', 'cat', 'post_status' ) as $reset_key ) {
                unset( $GLOBALS['_REQUEST'][ $reset_key ] );
            }
        }
        foreach ( $embed_query as $gk => $gv ) {
            $GLOBALS['_REQUEST'][ $gk ] = $gv;
        }

        $built_embed = add_query_arg( $embed_query, admin_url( 'admin.php' ) );
        $built_embed = $this->picky_normalize_admin_url_query( $built_embed );
        $parsed_b    = wp_parse_url( $built_embed );
        $embed_path  = ( is_array( $parsed_b ) && isset( $parsed_b['path'] ) && is_string( $parsed_b['path'] ) && $parsed_b['path'] !== '' )
            ? $parsed_b['path']
            : ( wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH ) ?: '/wp-admin/admin.php' );
        $embed_q     = ( is_array( $parsed_b ) && isset( $parsed_b['query'] ) && is_string( $parsed_b['query'] ) ) ? $parsed_b['query'] : '';
        $_SERVER['REQUEST_URI'] = $embed_path . ( $embed_q !== '' ? '?' . $embed_q : '' );

        $prev_screen = get_current_screen();
        global $typenow;
        $prev_typenow = isset( $typenow ) ? $typenow : '';
        $typenow      = $post_type;

        set_current_screen( 'edit-' . $post_type );

        $picky_embed_list_root_class = 'picky-embedded-posts-list';
        if ( $post_type === 'picky_layout' ) {
            $picky_embed_list_root_class .= ' picky-embedded-posts-list--picky-layout picky-reservations-custom picky-root picky-reservations-wrap picky-reservations-wrap--embedded';
        } elseif ( $post_type === 'picky_booking' ) {
            $picky_embed_list_root_class .= ' picky-reservations-custom picky-root picky-reservations-wrap picky-reservations-wrap--embedded';
        } elseif ( $post_type === 'picky_order' ) {
            $picky_embed_list_root_class .= ' picky-embedded-posts-list--picky-order picky-reservations-custom picky-root picky-reservations-wrap picky-reservations-wrap--embedded';
        }
        echo '<div class="' . esc_attr( $picky_embed_list_root_class ) . '">';
        $picky_embed_list_title = '';
        if ( isset( $pto->labels->menu_name ) && is_string( $pto->labels->menu_name ) && $pto->labels->menu_name !== '' ) {
            $picky_embed_list_title = $pto->labels->menu_name;
        } elseif ( isset( $pto->labels->name ) && is_string( $pto->labels->name ) && $pto->labels->name !== '' ) {
            $picky_embed_list_title = $pto->labels->name;
        }

        // Bookings (orders) tab: render the same `picky-cal-head` band used by the calendar tab,
        // populated with at-a-glance order stats so this tab feels consistent with the rest of the dashboard.
        if ( $post_type === 'picky_order' ) {
            $orders_total    = 0;
            $orders_reserved = 0;
            $orders_requests = 0;
            $orders_revenue  = 0.0;
            $orders_currency = '';
            // Count by post_status; revenue summed only for paid orders so refunds/pending don't inflate totals.
            $stats_q = new \WP_Query( array(
                'post_type'      => 'picky_order',
                'post_status'    => array( 'picky_reserved', 'picky_requests', 'reserved', 'requests', 'draft' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            if ( ! empty( $stats_q->posts ) ) {
                foreach ( $stats_q->posts as $oid ) {
                    $oid = (int) $oid;
                    $orders_total++;
                    $st = (string) get_post_status( $oid );
                    if ( $st === 'reserved' ) { $orders_reserved++; }
                    elseif ( $st === 'requests' ) { $orders_requests++; }
                    if ( (string) get_post_meta( $oid, '_picky_order_payment_status', true ) === 'paid' ) {
                        $orders_revenue += (float) get_post_meta( $oid, '_picky_order_total', true );
                        if ( $orders_currency === '' ) {
                            $orders_currency = (string) get_post_meta( $oid, '_picky_order_currency', true );
                        }
                    }
                }
            }
            if ( $orders_currency === '' ) {
                $stats_options   = Info::get_settings();
                $orders_currency = isset( $stats_options['deposit_currency'] ) ? strtoupper( (string) $stats_options['deposit_currency'] ) : 'USD';
            }
            ?>
            <div class="picky-cal-head">
                <div class="picky-cal-head__main">
                    <div class="picky-cal-head__copy">
                        <div class="picky-cal-head__title-row">
                            <h1><?php echo esc_html__( 'Bookings', 'pickybooking' ); ?></h1>
                        </div>
                        <p class="picky-cal-subtitle"><?php echo esc_html__( 'All multi-item bookings. Each order groups the items a customer reserved together; click an order to see its line items, services, durations and total.', 'pickybooking' ); ?></p>
                    </div>
                    <div class="picky-cal-stats" role="group" aria-label="<?php echo esc_attr__( 'Booking stats', 'pickybooking' ); ?>">
                        <div class="picky-cal-stat">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-list"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $orders_total ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Total', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <div class="picky-cal-stat picky-cal-stat-reserved">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-calendar"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $orders_reserved ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Reserved', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <div class="picky-cal-stat picky-cal-stat-requests">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-users"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $orders_requests ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Requests', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <?php if ( $orders_revenue > 0 ) : ?>
                        <div class="picky-cal-stat picky-cal-stat-revenue">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-credit-card"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( number_format_i18n( $orders_revenue, 2 ) . ' ' . $orders_currency ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Paid revenue', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }

        /*
         * Pagination &laquo; / page links use $_SERVER['REQUEST_URI']. Re-apply in case a hook
         * during prepare_items() altered REQUEST_URI before views()/display() output.
         */
        $_SERVER['REQUEST_URI'] = $embed_path . ( $embed_q !== '' ? '?' . $embed_q : '' );

        if ( $post_type === 'picky_booking' ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
            require_once __DIR__ . '/class-picky-embedded-posts-list-table.php';
            require_once __DIR__ . '/class-picky-reservations-list-facade.php';
            $picky_facade = new Picky_Reservations_List_Facade( array( 'screen' => get_current_screen() ) );
            $picky_facade->set_picky_embed_base_url( $built_embed );
            $picky_facade->set_picky_embed_canonical_query( $embed_query );
            $picky_facade->set_picky_embed_admin( $this );
            $picky_facade->prepare_items();
            global $wp_query;
            $picky_posts = ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) ? $wp_query->posts : array();
            /** @var array<string, string> $picky_columns */
            $picky_columns = apply_filters( 'manage_picky_booking_posts_columns', array() );
            /*
             * Custom path skips WP_Posts_List_Table::display(), which normally locks REQUEST_URI for
             * the whole render. Re-apply after prepare_items() so views/tablenav/sort links match this tab.
             */
            $_SERVER['REQUEST_URI'] = $embed_path . ( $embed_q !== '' ? '?' . $embed_q : '' );
            ob_start();
            $picky_embed_query = $embed_query;
            $picky_found_posts = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
            include __DIR__ . '/partials/reservations-list-custom.php';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rewritten admin list HTML from WP_List_Table; URLs rebuilt via esc_url.
            echo $this->picky_rewrite_embedded_posts_list_html( ob_get_clean(), $embed_query );
            echo '</div>';

            $_SERVER['REQUEST_URI'] = $backup_uri;
            $GLOBALS['_GET']     = $backup_get;
            $GLOBALS['_REQUEST'] = $backup_request;
            $typenow                = $prev_typenow;

            if ( $prev_screen instanceof \WP_Screen ) {
                set_current_screen( $prev_screen );
            }
            return;
        }

        if ( $post_type === 'picky_layout' ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
            require_once __DIR__ . '/class-picky-embedded-posts-list-table.php';
            require_once __DIR__ . '/class-picky-reservations-list-facade.php';
            $picky_facade = new Picky_Reservations_List_Facade( array( 'screen' => get_current_screen() ) );
            $picky_facade->set_picky_embed_base_url( $built_embed );
            $picky_facade->set_picky_embed_canonical_query( $embed_query );
            $picky_facade->set_picky_embed_admin( $this );
            $picky_facade->prepare_items();
            global $wp_query;
            $picky_posts = ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) ? $wp_query->posts : array();
            /** @var array<string, string> $picky_columns */
            $picky_columns = apply_filters( 'manage_picky_layout_posts_columns', array() );
            if ( ! is_array( $picky_columns ) ) {
                $picky_columns = array();
            }
            $_SERVER['REQUEST_URI'] = $embed_path . ( $embed_q !== '' ? '?' . $embed_q : '' );
            ob_start();
            $picky_embed_query = $embed_query;
            $picky_found_posts = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
            include __DIR__ . '/partials/layouts-list-custom.php';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rewritten admin list HTML from WP_List_Table; URLs rebuilt via esc_url.
            echo $this->picky_rewrite_embedded_posts_list_html( ob_get_clean(), $embed_query );
            echo '</div>';

            $_SERVER['REQUEST_URI'] = $backup_uri;
            $GLOBALS['_GET']     = $backup_get;
            $GLOBALS['_REQUEST'] = $backup_request;
            $typenow                = $prev_typenow;

            if ( $prev_screen instanceof \WP_Screen ) {
                set_current_screen( $prev_screen );
            }
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
        require_once __DIR__ . '/class-picky-embedded-posts-list-table.php';
        $wp_list_table = new Picky_Embedded_Posts_List_Table( array( 'screen' => get_current_screen() ) );
        $wp_list_table->set_picky_embed_base_url( $built_embed );
        $wp_list_table->prepare_items();

        echo '<form id="posts-filter" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="picky_settings" />';
        echo '<input type="hidden" name="picky-tab" value="' . esc_attr( (string) $picky_tab ) . '" />';
        echo '<input type="hidden" name="post_type" value="' . esc_attr( $post_type ) . '" />';

        ob_start();
        $wp_list_table->views();
        echo '<hr class="wp-header-end" />';
        $wp_list_table->search_box( $pto->labels->search_items, 'post' );
        $wp_list_table->display();
        $list_markup = ob_get_clean();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rewritten admin list HTML from WP_List_Table; URLs rebuilt via esc_url.
        echo $this->picky_rewrite_embedded_posts_list_html( $list_markup, $embed_query );
        echo '</form></div>';

        $_SERVER['REQUEST_URI'] = $backup_uri;
        $GLOBALS['_GET']     = $backup_get;
        $GLOBALS['_REQUEST'] = $backup_request;
        $typenow                = $prev_typenow;

        if ( $prev_screen instanceof \WP_Screen ) {
            set_current_screen( $prev_screen );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    /**
     * Panels for operational tabs (reservations list, calendar, layouts list, import, docs) inside Settings.
     *
     * @return string
     */
    private function picky_render_settings_app_tab_panels() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for UI
        $active_tab = '13';
        $candidate  = $this->picky_request_picky_tab_value();
        if ( $candidate !== '' ) {
            // Legacy redirect: tab 7 (Reservations) is folded into tab 13 (Bookings/Orders).
            if ( $candidate === '7' ) {
                $candidate = '13';
            }
            $active_tab = $candidate;
        }

        $out = '';
        $app_tabs = array( 8, 9, 10, 11, 13 );
        foreach ( $app_tabs as $i ) {
            $st = ( $active_tab === (string) $i ) ? '' : 'display:none;';
            $out .= '<div style="' . esc_attr( (string) $st ) . '" data-tab="' . esc_attr( (string) $i ) . '" id="picky-settings-tab-' . esc_attr( (string) $i ) . '" class="picky-settings-tab picky-settings-tab-' . esc_attr( (string) $i ) . ' picky-settings-tab-panel picky-settings-app-tab">';
            if ( $i === 8 ) {
                ob_start();
                $booking_id = absint( picky_request_query_value( 'booking_id' ) );
                if ( $booking_id > 0 && get_post_type( $booking_id ) === 'picky_booking' ) {
                    $this->picky_render_booking_edit_embedded( $booking_id );
                } else {
                    $this->render_calendar( true );
                }
                $out .= ob_get_clean();
            } elseif ( $i === 9 ) {
                ob_start();
                $layout_id = absint( picky_request_query_value( 'layout_id' ) );
                if ( $layout_id > 0 && get_post_type( $layout_id ) === 'picky_layout' ) {
                    $this->picky_render_layout_builder( $layout_id, true );
                } else {
                    $this->render_layout_library( true );
                }
                $out .= ob_get_clean();
            } elseif ( $i === 10 ) {
                ob_start();
                $this->render_import_layout( true );
                $out .= ob_get_clean();
            } elseif ( $i === 11 ) {
                ob_start();
                $this->render_documentation( true );
                $out .= ob_get_clean();
            } elseif ( $i === 13 ) {
                ob_start();
                $order_id = absint( picky_request_query_value( 'order_id' ) );
                if ( $order_id > 0 && get_post_type( $order_id ) === 'picky_order' ) {
                    $this->picky_render_order_edit_embedded( $order_id );
                } else {
                    $this->picky_render_embedded_posts_list( 'picky_order', '13' );
                }
                $out .= ob_get_clean();
            }
            $out .= '</div>';
        }

        return $out;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    function custom_image_uploader_field( $name, $value = '') {
        $image_button_text = esc_html__( 'Upload image', 'pickybooking' );
        $image_remove_button_text = esc_html__( 'Remove image', 'pickybooking' );
        $image = '';
        $image_size = 'full'; // it would be better to use thumbnail size here (150x150 or so)
        $display = 'none'; // display state ot the "Remove image" button
     
        if( $image_attributes = wp_get_attachment_image_src( $value, $image_size ) ) {
            // wp_get_attachment_image_src returns url, width, height; we use the URL (index 0).
            $image = $image_attributes[0];
            $display = 'inline-block';
     
        }  else if(isset($value) && !empty($value)) {
            $image = $value;
            $display = 'inline-block';
        }
        $holder_state_class = ! empty( $image ) ? 'picky-has-image' : 'picky-no-image';
        
        $output = '';
        $output .='
        <div class="picky-background-field-wrap-inner">
            <div class="picky-background-buttons-wrap">
                <a href="#" class="picky-custom_upload_image_button button"><span>' . $image_button_text . '</span></a>
                <a href="#" class="picky-custom_remove_image_button button" style="display:' . esc_attr( (string)$display ) . '"><span>' . $image_remove_button_text . '</span></a>
            </div>
            <div class="picky-background-image-holder-wrap ' . esc_attr( (string) $holder_state_class ) . '" style="background-image: url(' . esc_url( (string)$image ) . ');"></div>
            <input type="hidden" name="' . esc_attr((string)$name) . '" id="' . esc_attr((string)$name) . '" value="' . esc_attr((string)$value) . '" />
        </div>';

        return $output;
    }

    /**
     * Whether heavy admin assets (media/editor stack) should load.
     *
     * Skips Widgets / Customizer widgets screens — WordPress 5.8+ forbids
     * enqueueing classic `wp-editor` alongside `wp-edit-widgets`.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return bool
     */
    private function picky_should_enqueue_admin_assets( $hook_suffix = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin screen detection
        $hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';

        if ( in_array( $hook_suffix, array( 'widgets.php', 'customize.php' ), true ) ) {
            return false;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && isset( $screen->id ) ) {
            $sid = (string) $screen->id;
            if ( $sid === 'widgets' || $sid === 'customize' || strpos( $sid, 'widgets' ) !== false ) {
                return false;
            }
        }

        $page = sanitize_key( picky_request_query_value( 'page' ) );
        if ( $page !== '' && ( $page === 'picky_settings' || strpos( $page, 'picky_' ) === 0 || $page === 'layout' || $page === 'picky_layout' ) ) {
            return true;
        }

        if ( $screen && ! empty( $screen->post_type ) && strpos( (string) $screen->post_type, 'picky_' ) === 0 ) {
            return true;
        }

        if ( $hook_suffix !== '' && ( strpos( $hook_suffix, 'picky_' ) !== false || strpos( $hook_suffix, 'toplevel_page_picky' ) !== false ) ) {
            return true;
        }

        return false;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public function assets( $hook_suffix = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin screen detection
        if ( ! $this->picky_should_enqueue_admin_assets( $hook_suffix ) ) {
            return;
        }

        if ( ! did_action( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        wp_enqueue_style( 'dashicons' );

        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'wp-color-picker' );

        // Use WordPress bundled jQuery UI components for builder interactions
        wp_enqueue_script( 'jquery-ui-core' );
        wp_enqueue_script( 'jquery-ui-draggable' );
        wp_enqueue_script( 'jquery-ui-droppable' );
        wp_enqueue_script( 'jquery-ui-resizable' );

        $admin_css = plugin_dir_path(__FILE__).'css/picky-admin.css';
        $admin_js  = plugin_dir_path(__FILE__).'js/picky-admin.js';
        $admin_css_ver = $this->version;
        $admin_js_ver  = $this->version;

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
            plugin_dir_url( __FILE__ ) . 'css/picky-admin.css',
            array( Info::ASSET_HANDLE_TOKENS, Info::ASSET_HANDLE_SELECT2_STYLE ),
            $admin_css_ver
        );

        $admin_settings_css = plugin_dir_path( __FILE__ ) . 'css/picky-admin-settings.css';
        $admin_settings_ver = $this->version;
        wp_enqueue_style(
            Info::ASSET_HANDLE_ADMIN_SETTINGS,
            plugin_dir_url( __FILE__ ) . 'css/picky-admin-settings.css',
            array( Info::ASSET_HANDLE_APP ),
            $admin_settings_ver
        );

        // Ensure icon fonts load with absolute URLs (avoid relative URL issues when WP concatenates CSS)
        $plugin_root = plugin_dir_path( dirname( __FILE__ ) ) . Info::MAIN_FILE;
        $root_url = plugins_url( '/', $plugin_root );
        $font_css = "@font-face{font-family:'picky-admin-font';src:url('{$root_url}admin/font/picky-admin-font.woff?9ghvw3') format('woff'),url('{$root_url}admin/font/picky-admin-font.ttf?9ghvw3') format('truetype'),url('{$root_url}admin/font/picky-admin-font.svg?9ghvw3#picky-admin-font') format('svg');font-weight:normal;font-style:normal;font-display:block;}
        @font-face{font-family:'picky-tools-font';src:url('{$root_url}assets/font/picky-tools-font.woff?6tbcxd') format('woff'),url('{$root_url}assets/font/picky-tools-font.ttf?6tbcxd') format('truetype'),url('{$root_url}assets/font/picky-tools-font.svg?6tbcxd#picky-tools-font') format('svg');font-weight:normal;font-style:normal;font-display:swap;}
        .picky-wrap [class^='icon-'],.picky-wrap [class*=' icon-'],.picky-wrap .picky-element-edit-id-icon::before,.picky-wrap .picky-element-edit-rotate::before,.picky-wrap .picky-element-duplicate::before,.picky-wrap .picky-element-delete::before,.picky-wrap .picky-element-color::before,.picky-wrap .picky-element-edit::before,.picky-wrap .picky-element-custom-upload::before{font-family:'picky-tools-font' !important;speak:none;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}";
        wp_add_inline_style( Info::ASSET_HANDLE_APP, $font_css );
        wp_enqueue_script( Info::ASSET_HANDLE_SELECT2_SCRIPT, plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/select2.js', array( 'jquery' ), $this->version, true );
        $custom_image_loader_js = plugin_dir_path(__FILE__).'js/custom_image_loader.js';
        $custom_image_loader_ver = $this->version;
        wp_enqueue_script( Info::ASSET_HANDLE_CUSTOM_IMAGE, plugin_dir_url(__FILE__).'js/custom_image_loader.js', array( 'jquery' ), $custom_image_loader_ver, true );
        // Use WordPress core clipboard.js (do not bundle a duplicate library).
        wp_enqueue_script( 'clipboard' );
        wp_enqueue_script(
            Info::ASSET_HANDLE_APP,
            plugin_dir_url(__FILE__).'js/picky-admin.js',
            array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-resizable', 'clipboard' ),
            $admin_js_ver,
            true
        );
        $admin_i18n = array(
            'select_image_svg' => esc_html__( 'Select image / SVG', 'pickybooking' ),
            'use_this_file'    => esc_html__( 'Use this file', 'pickybooking' ),
            'insert_image'     => esc_html__( 'Insert image', 'pickybooking' ),
            'use_this_image'   => esc_html__( 'Use this image', 'pickybooking' ),
            'status_saving'    => esc_html__( 'Saving status…', 'pickybooking' ),
            'status_saved'     => esc_html__( 'Status saved', 'pickybooking' ),
            'status_error'     => esc_html__( 'Could not save status. Try again.', 'pickybooking' ),
        );
        wp_localize_script( Info::ASSET_HANDLE_CUSTOM_IMAGE, 'pickyAdminI18n', $admin_i18n );
        wp_localize_script( Info::ASSET_HANDLE_APP, 'pickyAdminI18n', $admin_i18n );

        /* Cache-bust marker + ensure overlay node exists even if main file is stale/cached. */
        wp_add_inline_script(
            Info::ASSET_HANDLE_APP,
            'window.__PICKY_ALIGN_V="align14";console.warn("[Picky] align inline",window.__PICKY_ALIGN_V);'
            . 'jQuery(function($){var $ov=$("#picky-align-guides-overlay");'
            . 'if(!$ov.length){$ov=$("<div id=\\"picky-align-guides-overlay\\" class=\\"picky-align-guides-overlay\\" data-picky-align=\\"align14\\" aria-hidden=\\"true\\"><svg class=\\"picky-align-guides-svg\\" xmlns=\\"http://www.w3.org/2000/svg\\" width=\\"100%\\" height=\\"100%\\"></svg></div>");$("body").append($ov);}'
            . 'else{$ov.attr("data-picky-align","align14");if(!$ov.find("svg.picky-align-guides-svg").length){$ov.html("<svg class=\\"picky-align-guides-svg\\" xmlns=\\"http://www.w3.org/2000/svg\\" width=\\"100%\\" height=\\"100%\\"></svg>");}}});',
            'after'
        );

        if ( sanitize_key( picky_request_query_value( 'page' ) ) === 'picky_settings' ) {
            wp_enqueue_script( 'posts' );
            wp_enqueue_script( 'inline-edit-post' );
        }

        // Pass current layout ID to JS (for builder page)
        $layout_id    = absint( picky_request_query_value( 'layout_id' ) );
        $picky_settings = Info::get_settings();
        $layout_localize = array(
            'layout_id'              => $layout_id,
            'ajax_url'               => admin_url( 'admin-ajax.php' ),
            'nonce_layout_preview'   => $layout_id ? wp_create_nonce( 'picky_save_layout_preview_' . $layout_id ) : '',
            /* translators: %s: element kind label (e.g. Shape, Table). */
            'editingElementTemplate' => __( 'Editing: %s', 'pickybooking' ),
            'elementTypeLabels'      => array(
                'type-wall'  => __( 'Wall', 'pickybooking' ),
                'type-shape' => __( 'Shape', 'pickybooking' ),
                'type-icon'  => __( 'Icon', 'pickybooking' ),
                'type-text'  => __( 'Text', 'pickybooking' ),
                'type-table' => $this->picky_item_label(),
                'type-1'     => __( 'Square', 'pickybooking' ),
                'type-4'     => __( 'Circle', 'pickybooking' ),
                /* translators: %s: configurable item name singular (e.g. table, room) */
                'type-6'     => sprintf( __( 'Universal %s', 'pickybooking' ), $this->picky_item_label() ),
            ),
            'square_radius_field_title' => __( 'Corner radius (px)', 'pickybooking' ),
            'bookingIcons'              => Booking_Icons::js_payload(),
        );
        $layout_localize['ai_enabled'] = 0;
        $layout_localize['i18n_ai']    = array();
        wp_localize_script( Info::ASSET_HANDLE_APP, 'pickyLayoutData', $layout_localize );

        add_action( 'admin_footer', array( Booking_Icons::class, 'print_sprite_once' ) );
        
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    
    


    public function register_settings() {
        register_setting($this->settings_group, $this->option_name, array($this, 'settings_save_validate'));
        

    }

    /**
     * Singular label for the reservable item ("Table" / "Room" / "Court" / …).
     * Falls back to the i18n default "Table" so existing sites read identically.
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
     * Plural label for the reservable item ("Tables" / "Rooms" / "Courts" / …).
     * If the option is empty, derive it from the singular by appending an "s"
     * so existing setups (singular only) keep behaving naturally.
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

    function set_custom_columns($columns) {
        $item_name = $this->picky_item_label();

        $columns = array();
        $columns["cb"] = '<input type="checkbox" />';
        $columns["title"] = __( "Title", 'pickybooking' );
        $columns["picky_layout_name"] = __( "Layout", 'pickybooking' );
        /* translators: %s: configurable item name */
        $columns["picky_booking_id"] = sprintf( __( '%s Number', 'pickybooking' ), $item_name );
        $columns["picky_booking_date"] = __( "Date", 'pickybooking' );
        $columns["picky_booking_time"] = __( "Time", 'pickybooking' );
        $columns["picky_booking_name"] = __( "Name", 'pickybooking' );
        $columns["picky_booking_phone"] = __( "Phone Number", 'pickybooking' );
        $columns["picky_booking_service"] = __( "Service", 'pickybooking' );
        $columns["picky_payment"] = __( "Payment", 'pickybooking' );
        $columns["picky_booking_order"] = __( "Order", 'pickybooking' );

        return $columns;
    }

    private function picky_payment_confirmation_link_html( $post_id ) {
        $order_id = (int) get_post_meta( $post_id, '_picky_wc_order_id', true );
        if ( $order_id > 0 ) {
            $url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
            return '<a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Woo order', 'pickybooking' ) . '</a>';
        }

        $session_id = (string) get_post_meta( $post_id, '_picky_stripe_session_id', true );
        if ( $session_id !== '' ) {
            // Stripe dashboard deep-links are Pro-only; Lite never collects card payments.
            return esc_html__( 'Online payment reference on file', 'pickybooking' );
        }

        return '—';
    }

    private function picky_payment_summary_html( $post_id ) {
        $status = sanitize_key( (string) get_post_meta( $post_id, '_picky_payment_status', true ) );
        $provider = sanitize_key( (string) get_post_meta( $post_id, '_picky_payment_provider', true ) );
        $amount = (float) get_post_meta( $post_id, '_picky_payment_amount', true );
        $currency = sanitize_text_field( (string) get_post_meta( $post_id, '_picky_payment_currency', true ) );
        if ( $currency === '' ) {
            $currency = 'USD';
        }
        $amount_text = number_format_i18n( $amount, 2 ) . ' ' . $currency;

        if ( $status === '' || $status === 'not_required' || $provider === 'none' ) {
            return esc_html__( 'No payment required', 'pickybooking' );
        }

        if ( $status === 'paid' ) {
            $badge = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#16a34a;color:#fff;font-weight:600;">&#10003; ' . esc_html__( 'Payment Succeeded', 'pickybooking' ) . '</span>';
            return $badge . '<br><small>' . esc_html( $amount_text ) . '</small><br>' . $this->picky_payment_confirmation_link_html( $post_id );
        }

        if ( $status === 'pending' ) {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#f59e0b;color:#111;font-weight:600;">' . esc_html__( 'Pending payment', 'pickybooking' ) . '</span><br><small>' . esc_html( $amount_text ) . '</small>';
        }

        if ( $status === 'cancelled' || $status === 'not_paid' || $status === 'init_failed' ) {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#dc2626;color:#fff;font-weight:600;">' . esc_html__( 'Payment failed', 'pickybooking' ) . '</span><br><small>' . esc_html( $amount_text ) . '</small>';
        }

        return esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '<br><small>' . esc_html( $amount_text ) . '</small>';
    }

    public function post_type_columns() {
        
        add_filter( 'manage_picky_booking_posts_columns', array($this, 'set_custom_columns') );
        add_filter( 'manage_edit-picky_booking_sortable_columns', array($this, 'sortable_columns') );
        add_action( 'pre_get_posts',  array($this, 'sortable_columns_orderby') );
        add_action( 'manage_picky_booking_posts_custom_column', array($this, 'render_custom_columns'), 10, 2 );

        // Orders CPT columns
        add_filter( 'manage_picky_order_posts_columns', array( $this, 'set_order_columns' ) );
        add_action( 'manage_picky_order_posts_custom_column', array( $this, 'render_order_columns' ), 10, 2 );
    }

    public function set_order_columns( $columns ) {
        return array(
            'cb'                    => '<input type="checkbox" />',
            'title'                 => __( 'Order', 'pickybooking' ),
            'picky_order_layout'      => __( 'Layout', 'pickybooking' ),
            'picky_order_date'        => __( 'Date', 'pickybooking' ),
            'picky_order_time'        => __( 'Time', 'pickybooking' ),
            'picky_order_customer'    => __( 'Customer', 'pickybooking' ),
            'picky_order_items_count' => __( 'Items', 'pickybooking' ),
            'picky_order_total'       => __( 'Total', 'pickybooking' ),
            'picky_order_payment'     => __( 'Payment', 'pickybooking' ),
        );
    }

    public function render_order_columns( $column, $post_id ) {
        if ( $column === 'picky_order_layout' ) {
            $layout_id = (int) get_post_meta( $post_id, '_picky_order_layout_id', true );
            echo $layout_id > 0 ? esc_html( (string) get_the_title( $layout_id ) ) : '—';

        } elseif ( $column === 'picky_order_date' ) {
            $date = (string) get_post_meta( $post_id, '_picky_order_date', true );
            echo esc_html( $date );

        } elseif ( $column === 'picky_order_time' ) {
            echo esc_html( (string) get_post_meta( $post_id, '_picky_order_time', true ) );

        } elseif ( $column === 'picky_order_customer' ) {
            $n = (string) get_post_meta( $post_id, '_picky_order_customer_name', true );
            $e = (string) get_post_meta( $post_id, '_picky_order_email', true );
            echo esc_html( $n );
            if ( $e !== '' ) {
                echo '<br><small>' . esc_html( $e ) . '</small>';
            }

        } elseif ( $column === 'picky_order_items_count' ) {
            echo esc_html( (string) get_post_meta( $post_id, '_picky_order_item_count', true ) );

        } elseif ( $column === 'picky_order_total' ) {
            $total    = (float)  get_post_meta( $post_id, '_picky_order_total', true );
            $currency = (string) get_post_meta( $post_id, '_picky_order_currency', true );
            echo esc_html( number_format( $total, 2 ) . ' ' . $currency );

        } elseif ( $column === 'picky_order_payment' ) {
            $status   = (string) get_post_meta( $post_id, '_picky_order_payment_status', true );
            $provider = (string) get_post_meta( $post_id, '_picky_order_payment_provider', true );
            $amount   = (float)  get_post_meta( $post_id, '_picky_order_payment_amount', true );
            $currency = (string) get_post_meta( $post_id, '_picky_order_currency', true );
            if ( $status === '' || $status === 'not_required' || $provider === 'none' ) {
                echo '<span class="picky-order-pay-badge picky-order-pay-badge--muted">' . esc_html__( 'No payment', 'pickybooking' ) . '</span>';
            } elseif ( $status === 'paid' ) {
                echo '<span class="picky-order-pay-badge picky-order-pay-badge--paid">' . esc_html__( 'Paid', 'pickybooking' ) . '</span>';
                if ( $amount > 0 ) {
                    echo '<br><small>' . esc_html( number_format( $amount, 2 ) . ' ' . $currency ) . '</small>';
                }
            } elseif ( $status === 'pending' ) {
                echo '<span class="picky-order-pay-badge picky-order-pay-badge--pending">' . esc_html__( 'Pending', 'pickybooking' ) . '</span>';
            } else {
                echo '<span class="picky-order-pay-badge picky-order-pay-badge--muted">' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</span>';
            }
        }
    }

    public function render_custom_columns($column, $post_id) {

        if ( $column === 'picky_layout_name' ) {
            $layout_id = (int) get_post_meta( $post_id, '_picky_reservation_layout_id', true );
            if ( $layout_id && get_post_type( $layout_id ) === 'picky_layout' ) {
                echo esc_html( (string)get_the_title( $layout_id ) );
            } else {
                echo '—';
            }
        } elseif ( $column === 'picky_payment' ) {
            echo wp_kses(
                $this->picky_payment_summary_html( $post_id ),
                array(
                    'span'  => array(
                        'style' => true,
                        'class' => true,
                    ),
                    'br'    => array(),
                    'small' => array(),
                    'a'     => array(
                        'href'   => true,
                        'class'  => true,
                        'target' => true,
                        'rel'    => true,
                    ),
                )
            );
        } elseif ( $column === "picky_booking_id" ) {
            $id = get_post_meta( $post_id, '_picky_booking_id', true );
            $value = $id;
            echo esc_html( (string)$value );
        } elseif ( $column === 'picky_booking_service' ) {
            $services_json = get_post_meta( $post_id, '_picky_booking_services', true );
            $services = array();
            if ( is_string( $services_json ) && $services_json !== '' ) {
                $decoded = json_decode( $services_json, true );
                if ( is_array( $decoded ) ) {
                    $services = $decoded;
                }
            }
            // Legacy fallback
            if ( empty( $services ) ) {
                $legacy_name = (string) get_post_meta( $post_id, '_picky_booking_service_name', true );
                if ( $legacy_name !== '' ) {
                    $services = array(
                        array(
                            'name'  => $legacy_name,
                            'price' => (float) get_post_meta( $post_id, '_picky_booking_service_price', true ),
                        ),
                    );
                }
            }
            if ( ! empty( $services ) ) {
                $names  = array_filter( array_map( function( $s ) { return isset( $s['name'] ) ? (string) $s['name'] : ''; }, $services ) );
                $total  = array_sum( array_map( function( $s ) { return isset( $s['price'] ) ? (float) $s['price'] : 0.0; }, $services ) );
                $currency = (string) get_post_meta( $post_id, '_picky_booking_service_currency', true );
                echo esc_html( implode( ' + ', $names ) );
                if ( $total > 0 ) {
                    echo '<br><small>' . esc_html( number_format( $total, 2 ) . ' ' . $currency ) . '</small>';
                }
            } else {
                echo '—';
            }
        } elseif ( $column === 'picky_booking_order' ) {
            $order_id = (int) get_post_meta( $post_id, '_picky_booking_order_id', true );
            if ( $order_id > 0 ) {
                $url = get_edit_post_link( $order_id );
                echo '<a href="' . esc_url( (string) $url ) . '">#' . esc_html( (string) $order_id ) . '</a>';
            } else {
                echo '—';
            }
        } elseif ( in_array( $column, array( 'picky_booking_date', 'picky_booking_time', 'picky_booking_name', 'picky_booking_phone' ), true ) ) {
            $value = get_post_meta( $post_id, '_' . $column, true );
            if ( $column === 'picky_booking_date' ) {
                $dt = $this->picky_parse_reservation_date_local( (string) $value );
                if ( $dt instanceof \DateTimeImmutable ) {
                    echo esc_html( $dt->format( get_option( 'date_format' ) ) );
                } else {
                    echo esc_html( (string) $value );
                }
            } else {
                echo esc_html( (string) $value );
            }
        } else {
            $value = get_post_meta( $post_id, '_' . $column, true );
            echo esc_html( (string)$value );
        }
    }

    function sortable_columns( $columns ) {
        $columns['picky_booking_id'] = 'id';
        $columns['picky_booking_date'] = 'date';
        $columns['picky_booking_time'] = 'time';

        return $columns;
    }

    function sortable_columns_orderby( $query ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list table orderby from GET
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        $post_type = $query->get( 'post_type' );
        if ( 'picky_booking' !== $post_type && ! ( is_array( $post_type ) && in_array( 'picky_booking', $post_type, true ) ) ) {
            return;
        }
        $orderby = $query->get( 'orderby' );
        if ( 'date' === $orderby ) {
            $query->set( 'meta_key', '_picky_booking_date' );
            $query->set( 'orderby', 'meta_value date' );
        } elseif ( 'time' === $orderby ) {
            $query->set( 'meta_key', '_picky_booking_time' );
            $query->set( 'orderby', 'meta_value time' );
        } elseif ( 'id' === $orderby ) {
            $query->set( 'meta_key', '_picky_booking_id' );
            $query->set( 'orderby', 'meta_value_num' );
        }

        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }

        $payment_status = sanitize_key( picky_request_query_value( 'picky_payment_status' ) );
        $allowed_statuses = array( 'paid', 'pending', 'failed', 'cancelled', 'not_required' );
        if ( in_array( $payment_status, $allowed_statuses, true ) ) {
            if ( $payment_status === 'failed' ) {
                $meta_query[] = array(
                    'key'     => '_picky_payment_status',
                    'value'   => array( 'cancelled', 'not_paid', 'init_failed' ),
                    'compare' => 'IN',
                );
            } elseif ( $payment_status === 'not_required' ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'   => '_picky_payment_status',
                        'value' => 'not_required',
                    ),
                    array(
                        'key'     => '_picky_payment_status',
                        'compare' => 'NOT EXISTS',
                    ),
                );
            } else {
                $meta_query[] = array(
                    'key'   => '_picky_payment_status',
                    'value' => $payment_status,
                );
            }
        }

        $payment_provider = sanitize_key( picky_request_query_value( 'picky_payment_provider' ) );
        $allowed_providers = array( 'stripe', 'woocommerce', 'none' );
        if ( in_array( $payment_provider, $allowed_providers, true ) ) {
            if ( $payment_provider === 'none' ) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'   => '_picky_payment_provider',
                        'value' => 'none',
                    ),
                    array(
                        'key'     => '_picky_payment_provider',
                        'compare' => 'NOT EXISTS',
                    ),
                );
            } else {
                $meta_query[] = array(
                    'key'   => '_picky_payment_provider',
                    'value' => $payment_provider,
                );
            }
        }

        if ( ! empty( $meta_query ) ) {
            if ( count( $meta_query ) > 1 && ! isset( $meta_query['relation'] ) ) {
                $meta_query['relation'] = 'AND';
            }
            $query->set( 'meta_query', $meta_query );
        }

        $layout_tab = absint( picky_request_query_value( 'picky_layout_tab' ) );
        if ( $layout_tab > 0 ) {
            $meta_query_layout = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query_layout ) ) {
                $meta_query_layout = array();
            }
            $meta_query_layout[] = array(
                'key'   => '_picky_reservation_layout_id',
                'value' => $layout_tab,
            );
            if ( count( $meta_query_layout ) > 1 && ! isset( $meta_query_layout['relation'] ) ) {
                $meta_query_layout['relation'] = 'AND';
            }
            $query->set( 'meta_query', $meta_query_layout );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    private function picky_unread_bookings_count() {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        $q = new \WP_Query(
            array(
                'post_type'      => 'picky_booking',
                'post_status'    => array( 'picky_reserved', 'picky_requests', 'reserved', 'requests' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'   => '_picky_admin_seen',
                        'value' => '0',
                    ),
                    array(
                        'key'     => '_picky_admin_seen',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );
        return isset( $q->found_posts ) ? absint( $q->found_posts ) : 0;
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    public function render_new_booking_notice() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for notice
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $post_type = sanitize_key( picky_request_query_value( 'post_type' ) );
        if ( $post_type === 'picky_booking' ) {
            return;
        }
        $unread = $this->picky_unread_bookings_count();
        if ( $unread <= 0 ) {
            return;
        }
        $url = admin_url( 'admin.php?page=picky_settings&picky-tab=13&picky_new=1' );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of new bookings */
                        __( 'You have %d new bookings.', 'pickybooking' ),
                        $unread
                    )
                );
                ?>
                <a href="<?php echo esc_url( (string) $url ); ?>"><?php esc_html_e( 'Open bookings', 'pickybooking' ); ?></a>
            </p>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public function maybe_mark_picky_bookings_seen() {
        // Deprecated auto-mark: keep new rows/overlays until an admin opens a reservation.
        return;
    }

    /**
     * Add row class for unseen bookings in the reservations list table.
     *
     * @param array    $classes Existing CSS classes for the post row.
     * @param string[] $class   Additional classes passed to get_post_class().
     * @param int      $post_id Current post id.
     * @return array
     */
    public function picky_post_row_new_class( $classes, $class, $post_id ) {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return $classes;
        }
        if ( get_post_type( $post_id ) !== 'picky_booking' ) {
            return $classes;
        }
        $seen = (string) get_post_meta( $post_id, '_picky_admin_seen', true );
        if ( $seen !== '1' ) {
            $classes[] = 'picky-new-booking-row';
        }
        return $classes;
    }

    /**
     * WP sidebar: size PickyBooking menu icon + mint current-item state.
     *
     * @return void
     */
    public function print_admin_menu_css() {
        if ( ! is_admin() ) {
            return;
        }
        $css = '
            #adminmenu #toplevel_page_picky_settings .wp-menu-image img {
                width: 20px !important;
                height: 20px !important;
                max-width: 20px !important;
                padding: 7px 0 0 !important;
                opacity: 1 !important;
                object-fit: contain;
            }
            #adminmenu #toplevel_page_picky_settings:hover .wp-menu-image img,
            #adminmenu #toplevel_page_picky_settings.wp-has-current-submenu .wp-menu-image img,
            #adminmenu #toplevel_page_picky_settings.current .wp-menu-image img {
                opacity: 1 !important;
            }
            #adminmenu li#toplevel_page_picky_settings.wp-has-current-submenu a.wp-has-current-submenu,
            #adminmenu li#toplevel_page_picky_settings.current a.menu-top {
                background: #79fed1 !important;
                color: #111 !important;
            }
            #adminmenu li#toplevel_page_picky_settings.wp-has-current-submenu a.wp-has-current-submenu:after,
            #adminmenu li#toplevel_page_picky_settings.current a.menu-top:after {
                border-right-color: #f0f0f1 !important;
            }
            #adminmenu li#toplevel_page_picky_settings.wp-has-current-submenu .wp-menu-name,
            #adminmenu li#toplevel_page_picky_settings.current .wp-menu-name {
                color: #111 !important;
                font-weight: 600;
            }
            #adminmenu li#toplevel_page_picky_settings:hover:not(.wp-has-current-submenu):not(.current) > a {
                color: #fff;
            }

            body.picky-edition-lite .picky-settings-tabs-header-btn--pro span {
                font-weight: 600;
            }
        ';
        wp_register_style( 'picky-admin-menu-css', false, array(), $this->version );
        wp_enqueue_style( 'picky-admin-menu-css' );
        wp_add_inline_style( 'picky-admin-menu-css', $css );
    }

    /**
     * Highlight unread bookings in the list table.
     *
     * @return void
     */
    public function print_picky_new_row_css() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ( $screen->id !== 'edit-picky_booking' && $screen->id !== 'toplevel_page_picky_settings' ) ) {
            return;
        }
        $css = '
            .wp-list-table.posts tr.picky-new-booking-row > td,
            .picky-reservations-custom__table tr.picky-new-booking-row > td {
                background-image: linear-gradient(0deg, rgba(39,174,96,.12), rgba(39,174,96,.12));
            }
            .wp-list-table.posts tr.picky-new-booking-row > td:first-child,
            .picky-reservations-custom__table tr.picky-new-booking-row > td:first-child {
                box-shadow: inset 4px 0 0 #0e8a39;
            }
        ';
        wp_register_style( 'picky-admin-new-row-css', false, array(), $this->version );
        wp_enqueue_style( 'picky-admin-new-row-css' );
        wp_add_inline_style( 'picky-admin-new-row-css', $css );
    }

    public function picky_layout_views( $views ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET for views
        $post_type = sanitize_key( picky_request_query_value( 'post_type' ) );
        if ( $post_type !== 'picky_booking' ) {
            return $views;
        }

        $current_layout_id = absint( picky_request_query_value( 'picky_layout_tab' ) );

        $base_args = array( 'post_type' => 'picky_booking' );
        $payment_status_arg = sanitize_key( picky_request_query_value( 'picky_payment_status' ) );
        if ( $payment_status_arg !== '' ) {
            $base_args['picky_payment_status'] = $payment_status_arg;
        }
        $payment_provider_arg = sanitize_key( picky_request_query_value( 'picky_payment_provider' ) );
        if ( $payment_provider_arg !== '' ) {
            $base_args['picky_payment_provider'] = $payment_provider_arg;
        }

        $in_settings_shell = sanitize_key( picky_request_query_value( 'page' ) ) === 'picky_settings'
            && $this->picky_request_picky_tab_value() === '7';
        if ( $in_settings_shell ) {
            $base_args['page']    = 'picky_settings';
            $base_args['picky-tab'] = '7';
        }

        $all_count_q = new \WP_Query(
            array(
                'post_type'      => 'picky_booking',
                'post_status'    => array( 'picky_reserved', 'picky_requests', 'reserved', 'requests', 'draft' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
            )
        );
        $all_count = isset( $all_count_q->found_posts ) ? absint( $all_count_q->found_posts ) : 0;
        $list_base = $in_settings_shell ? admin_url( 'admin.php' ) : admin_url( 'edit.php' );
        $all_url     = add_query_arg( $base_args, $list_base );
        $views['picky_layout_all'] = '<a href="' . esc_url( (string) $all_url ) . '"' . ( $current_layout_id === 0 ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All Layouts', 'pickybooking' ) . ' <span class="count">(' . absint( $all_count ) . ')</span></a>';

        $layouts = get_posts(
            array(
                'post_type'   => 'picky_layout',
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'title',
                'order'       => 'ASC',
            )
        );
        foreach ( $layouts as $layout ) {
            $lid = absint( $layout->ID );
            $cnt_q = new \WP_Query(
                array(
                    'post_type'      => 'picky_booking',
                    'post_status'    => array( 'picky_reserved', 'picky_requests', 'reserved', 'requests', 'draft' ),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'   => '_picky_reservation_layout_id',
                            'value' => $lid,
                        ),
                    ),
                )
            );
            $cnt = isset( $cnt_q->found_posts ) ? absint( $cnt_q->found_posts ) : 0;
            $args = $base_args;
            $args['picky_layout_tab'] = $lid;
            $url = add_query_arg( $args, $list_base );
            $key = 'picky_layout_' . $lid;
            $title = get_the_title( $lid );
            $views[ $key ] = '<a href="' . esc_url( (string) $url ) . '"' . ( $current_layout_id === $lid ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( (string) $title ) . ' <span class="count">(' . $cnt . ')</span></a>';
        }

        return $views;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    /**
     * Render admin filters on reservations list.
     *
     * @param string $post_type
     * @return void
     */
    public function render_picky_admin_filters( $post_type ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin GET filters
        if ( $post_type !== 'picky_booking' ) {
            return;
        }

        $selected_status = sanitize_key( picky_request_query_value( 'picky_payment_status' ) );
        $selected_provider = sanitize_key( picky_request_query_value( 'picky_payment_provider' ) );
        ?>
        <label>
            <?php esc_html_e( 'Payment status', 'pickybooking' ); ?>
            <select name="picky_payment_status" id="picky_payment_status">
                <option value=""><?php esc_html_e( 'All payment statuses', 'pickybooking' ); ?></option>
                <option value="paid" <?php selected( $selected_status, 'paid' ); ?>><?php esc_html_e( 'Paid', 'pickybooking' ); ?></option>
                <option value="pending" <?php selected( $selected_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'pickybooking' ); ?></option>
                <option value="failed" <?php selected( $selected_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'pickybooking' ); ?></option>
                <option value="cancelled" <?php selected( $selected_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'pickybooking' ); ?></option>
                <option value="not_required" <?php selected( $selected_status, 'not_required' ); ?>><?php esc_html_e( 'No payment required', 'pickybooking' ); ?></option>
            </select>
        </label>
        <label>
            <?php esc_html_e( 'Payment provider', 'pickybooking' ); ?>
            <select name="picky_payment_provider" id="picky_payment_provider">
                <option value=""><?php esc_html_e( 'All providers', 'pickybooking' ); ?></option>
                <option value="stripe" <?php selected( $selected_provider, 'stripe' ); ?>><?php esc_html_e( 'Direct Stripe', 'pickybooking' ); ?></option>
                <option value="woocommerce" <?php selected( $selected_provider, 'woocommerce' ); ?>><?php esc_html_e( 'WooCommerce', 'pickybooking' ); ?></option>
                <option value="none" <?php selected( $selected_provider, 'none' ); ?>><?php esc_html_e( 'None', 'pickybooking' ); ?></option>
            </select>
        </label>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }


    public function settings_save_validate($input){
        // Settings API calls this sanitize callback. When the request is the options form, verify its nonce before any other input is used. Programmatic update_option() has no option_page field and must keep working.
        $option_page = isset( $_POST['option_page'] ) && is_scalar( $_POST['option_page'] )
            ? sanitize_key( wp_unslash( (string) $_POST['option_page'] ) )
            : '';
        $layout_field_posted = false;
        if ( $option_page === $this->settings_group ) {
            check_admin_referer( $this->settings_group . '-options' );
            $layout_field_posted = ( isset( $_POST['picky_background_canvas'] ) && is_scalar( $_POST['picky_background_canvas'] ) )
                || ( isset( $_POST['picky_background_table'] ) && is_scalar( $_POST['picky_background_table'] ) );
        }
        $options_array = get_option($this->option_name);

        if(isset($options_array) && !empty($options_array)){
            
        } else {
            $options_array = array();
        }

        

        if(isset($input)){

            // Re-assemble chunked layout payload (used when host truncates long single input values).
            if ( isset( $input['position_chunks'] ) && is_array( $input['position_chunks'] ) ) {
                $joined_position = implode( '', $input['position_chunks'] );
                if ( $joined_position !== '' ) {
                    $input['position'] = $joined_position;
                }
                unset( $input['position_chunks'] );
            }

        	$sms_error = 0;
        	$sms_message = '';
            foreach ($input as $key => $value) {
                if( isset( $input[$key])){

                    if($key === "canvas_width" || $key === "canvas_height"){ // check canvas width and height
                        if($value === ""){
                            $value = '500';
                        } else if(intval($value) > 2500){
                            $value = '2500';
                        } else if(intval($value) < 500){
                            $value = '500';
                        }
                    }
                    if($key === "form_email"){ // check email
                        if(is_email($value)){

                        } else {
                            $value = get_option('admin_email');
                        }
                    }
                    if($key === "background-canvas-mode"){
                        $allowed_modes = array('repeat','cover','contain','stretch','repeat-x','repeat-y','center');
                        if(!in_array($value, $allowed_modes, true)){
                            $value = INFO::$DEFAULT_OPTIONS['background-canvas-mode'];
                        }
                    }
                    if($key === "container_align"){
                        $allowed_align = array('left','center','right');
                        if(!in_array($value, $allowed_align, true)){
                            $value = 'center';
                        }
                    }
                    if ( $key === 'table_booked_time' || $key === 'table_before_booked_time' ) {
                        $trim = trim( (string) $value );
                        if ( $trim === '' || ! is_numeric( $trim ) ) {
                            $value = '0';
                        } else {
                            $value = (string) max( 0, (int) $trim );
                        }
                    }
                    if ( isset( $input['send_sms'] ) && $input['send_sms'] === '1' ) {
	                    if ( $key === 'sms_key' && $value === '' ) {
	                    	$sms_error = 1;
	                    }
	                    if ( $key === 'sms_secret' && $value === '' ) {
	                    	$sms_error = 1;
	                    }
	                    if ( $key === 'sms_phone' && $value === '' ) {
	                    	$sms_error = 1;
	                    }
	                } 
	               
	                

                    // Sanitize every option key (WP.org InputSanitization).
                    if ( $key === 'position' ) {
                        $options_array[ $key ] = $this->picky_sanitize_layout_position_json( (string) $value );
                    } elseif ( $key === 'canvases' ) {
                        $options_array[ $key ] = $this->picky_sanitize_layout_position_json( (string) $value );
                    } elseif ( $key === 'form_confirm_text' || $key === 'form_thx' ) {
                        $options_array[ $key ] = wp_kses_post( wp_unslash( (string) $value ) );
                    } elseif ( $key === 'sms_key' || $key === 'sms_secret' || $key === 'sms_phone' ) {
                        $options_array[ $key ] = sanitize_text_field( (string) $value );
                    } else if($key === "background-canvas" || $key === "background-table" || $key === "background-seat"){
                        // Keep media value valid (legacy could store attachment ID; current builder stores URL).
                        if ( is_numeric( $value ) ) {
                            $options_array[$key] = sanitize_text_field((string)$value);
                        } else {
                            $options_array[$key] = esc_url_raw($value);
                        }
                    } elseif ( strpos( (string) $key, 'color_' ) === 0 ) {
                        $hex = picky_sanitize_hex_color( (string) $value );
                        $options_array[ $key ] = ( is_string( $hex ) && $hex !== '' )
                            ? $hex
                            : ( isset( Info::$DEFAULT_OPTIONS[ $key ] ) ? (string) Info::$DEFAULT_OPTIONS[ $key ] : '#000000' );
                    } else { // sanitaze ALL the others
                        $options_array[$key] = sanitize_text_field((string)$value);
                    }
                }
            }

            // SMS provider API checks omitted in Lite (Pro-only).
            if ( isset( $input['send_sms'] ) ) {
                $input['send_sms'] = '0';
            }


            $work_days = array(
                "work_days_monday",
                "work_days_tuesday",
                "work_days_wednesday",
                "work_days_thursday",
                "work_days_friday",
                "work_days_saturday",
                "work_days_sunday", 

                "work_days_monday_more",
                "work_days_tuesday_more",
                "work_days_wednesday_more",
                "work_days_thursday_more",
                "work_days_friday_more",
                "work_days_saturday_more",
                "work_days_sunday_more",

                "shop_people_amount",
                "form_confirm",
                "send_customer_email",
                "send_sms",
                "show_email",
                "show_phone",
                "show_people",
                "show_seats",
                "show_id_badge",
                "show_element_label",
                'show_time_to',
                'animate_bookable_items',
                'deposit_enabled',
                'hubspot_enabled',
                'hubspot_sync_deals',
                'mailchimp_enabled',
                'n8n_webhook_enabled',
                'zapier_webhook_enabled',
                'make_webhook_enabled',
                'gcal_enabled',
                'gcal_add_attendees',
                'ai_enabled',
            );

            foreach ($work_days as $key => $value) {
                if ( array_key_exists( $value, $input ) ) {
                    $v = $input[ $value ];
                    $v = is_scalar( $v ) ? (string) $v : '0';
                    $options_array[ $value ] = ( $v === '1' ) ? '1' : '0';
                } else {
                    $is_layout_save = array_key_exists( 'position', $input )
                        || array_key_exists( 'position_chunks', $input )
                        || array_key_exists( 'canvases', $input )
                        || $layout_field_posted;
                    if ( ! $is_layout_save ) {
                        $options_array[ $value ] = '0';
                    }
                }
            }
        }

        return $options_array;
        
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }



    // Element Mapping
    public function vc_infobox_mapping() {
         
        // Stop all if VC is not enabled
        if ( !defined( 'WPB_VC_VERSION' ) ) {
            return;
        }
         
        // Map the block with vc_map()
        vc_map( 
            array(
                'name' => esc_html__( 'PickyBooking', 'pickybooking' ),
                'base' => 'pickybooking',
                'description' => esc_html__( 'PickyBooking', 'pickybooking' ),
                'category' => esc_html__( 'Content', 'pickybooking' ),
                'icon' => plugins_url( 'assets/img/composer-icon.png', dirname( __DIR__ ) . '/' . Info::MAIN_FILE ),            
                'params' => array(   
                         
                    array(
                        'type' => 'textfield',
                        'class' => 'title-class',
                        'heading' => esc_html__( 'Element ID', 'pickybooking' ),
                        'param_name' => 'picky_shortcode_id',
                        'value' => '',
                        'description' => esc_html__( 'Enter element ID', 'pickybooking' ),
                        'admin_label' => true,
                        'weight' => 0,
                    ),  
                    array(
                        'type' => 'textfield',
                        'class' => 'title-class',
                        'heading' => esc_html__( 'Extra class name', 'pickybooking' ),
                        'param_name' => 'picky_shortcode_class',
                        'value' => '',
                        'description' => esc_html__( 'Style particular content element differently - add a class name and refer to it in custom CSS.', 'pickybooking' ),
                        'admin_label' => true,
                        'weight' => 0,
                    ),  
                ),
            )
        );                                
        
    }
  

    
     
    


    public function add_menus() {
        $plugin_name = Info::get_plugin_title();

        /* Top-level opens the unified dashboard (reservations, calendar, layouts live in-app). */
        /* Above WooCommerce (typically ~55–56) so the plugin stays visible in the main admin stack. */
        add_menu_page(
            (string) $plugin_name,
            (string) $plugin_name,
            'manage_options',
            'picky_settings',
            array( $this, 'render_settings' ),
            plugins_url( 'assets/img/menu-icon.png', dirname( __DIR__ ) . '/' . Info::MAIN_FILE ),
            55
        );
        // WP auto-adds a first submenu with the same title as the parent. Rename it so the
        // flyout is “PickyBooking → Dashboard”, not a duplicate plugin title.
        add_submenu_page(
            'picky_settings',
            (string) $plugin_name,
            __( 'Dashboard', 'pickybooking' ),
            'manage_options',
            'picky_settings',
            array( $this, 'render_settings' )
        );
        // Hidden builder route — redirects to Layouts tab + builder (see picky_redirect_standalone_layout_builder_to_settings).
        add_submenu_page(
            'picky_hidden_menu', // fake parent slug to hide it from nav but keep it valid for WP core routing
            __( 'Edit Layout', 'pickybooking' ),
            __( 'Edit Layout', 'pickybooking' ),
            'manage_options',
            'picky_layout',
            array( $this, 'render_layout' )
        );
        // Keep the first submenu labeled Dashboard (not the plugin name).
        add_action( 'admin_menu', array( $this, 'picky_fix_duplicate_menu_label' ), 99999 );
    }

    /**
     * Ensure the first sidebar submenu under picky_settings is "Dashboard", not a duplicate plugin title.
     *
     * @return void
     */
    public function picky_fix_duplicate_menu_label() {
        global $submenu;
        if ( empty( $submenu['picky_settings'] ) || ! is_array( $submenu['picky_settings'] ) ) {
            return;
        }
        foreach ( $submenu['picky_settings'] as $i => $item ) {
            if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
                continue;
            }
            if ( (string) $item[2] === 'picky_settings' ) {
                $submenu['picky_settings'][ $i ][0] = __( 'Dashboard', 'pickybooking' );
            }
        }
    }

    /**
     * Legacy landing route — unified UI lives on admin.php?page=picky_settings.
     *
     * @return void
     */
    public function render_picky_home() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=picky_settings' ) );
        exit;
    }

    /**
     * Old CPT-submenu URLs (edit.php?post_type=picky_booking&page=…) redirected to admin.php?page=…
     * after the main menu moved to a dedicated top-level slug.
     *
     * @return void
     */
    public function picky_maybe_redirect_legacy_admin_urls() {
        if ( ! is_admin() ) {
            return;
        }
        $pt = sanitize_key( picky_request_query_value( 'post_type' ) );
        if ( $pt !== 'picky_booking' ) {
            return;
        }
        $page = sanitize_key( picky_request_query_value( 'page' ) );
        $map  = array(
            'settings' => 'picky_settings',
        );
        if ( isset( $map[ $page ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . rawurlencode( (string) $map[ $page ] ) ) );
            exit;
        }
    }

    /**
     * Redirect old standalone admin routes into the unified Settings screen tabs.
     *
     * @return void
     */
    public function picky_redirect_standalone_pages_to_settings() {
        if ( ! is_admin() ) {
            return;
        }
        $page = sanitize_key( picky_request_query_value( 'page' ) );
        $map  = array(
            'picky_booking'       => '7',
            'picky_calendar'      => '8',
            'picky_import_layout' => '10',
            'picky_documentation' => '11',
        );
        if ( ! isset( $map[ $page ] ) ) {
            return;
        }
        $args = array(
            'page'    => 'picky_settings',
            'picky-tab' => (string) $map[ $page ],
        );
        if ( $page === 'picky_import_layout' ) {
            $import_error = sanitize_text_field( picky_request_query_value( 'picky_import_error' ) );
            if ( $import_error !== '' ) {
                $args['picky_import_error'] = $import_error;
            }
        }
        wp_safe_redirect( admin_url( add_query_arg( $args, 'admin.php' ) ) );
        exit;
    }

    /**
     * Collapse duplicate query keys on admin.php?page=picky_settings (e.g. picky-tab repeated).
     * wp_parse_str keeps last value; GET then matches the canonical URL.
     *
     * @return void
     */
    public function picky_deduplicate_settings_screen_query() {
        if ( ! is_admin() ) {
            return;
        }
        if ( sanitize_key( picky_request_query_value( 'page' ) ) !== 'picky_settings' ) {
            return;
        }
        $qs = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : '';
        if ( $qs === '' ) {
            return;
        }
        if ( substr_count( $qs, 'picky-tab=' ) < 2 ) {
            return;
        }
        $args = array();
        wp_parse_str( $qs, $args );
        $target = admin_url( 'admin.php?' . build_query( $args ) );
        $here   = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
        if ( $target === $here ) {
            return;
        }
        wp_safe_redirect( $target );
        exit;
    }

    /**
     * Ensure layout_id on the settings screen always opens the Layouts tab (9).
     *
     * Skips when picky-tab is 8: the calendar filter form also sends layout_id (filter by floor plan),
     * which must not be treated as “open this layout in the builder”.
     * Skips when picky-tab is 7: Reservations list pagination/views must not inherit layout_id and get
     * redirected away from the bookings list (same layout_id key as the Layouts builder).
     *
     * @return void
     */
    public function picky_normalize_settings_layout_url() {
        if ( ! is_admin() ) {
            return;
        }
        if ( sanitize_key( picky_request_query_value( 'page' ) ) !== 'picky_settings' ) {
            return;
        }
        $lid = absint( picky_request_query_value( 'layout_id' ) );
        if ( $lid <= 0 ) {
            return;
        }
        $effective_tab = $this->picky_request_picky_tab_value();
        if ( $effective_tab === '7' || $effective_tab === '8' || $effective_tab === '9' || $effective_tab === '13' ) {
            return;
        }
        $args = array(
            'page'      => 'picky_settings',
            'picky-tab'   => '9',
            'layout_id' => $lid,
        );
        wp_safe_redirect( admin_url( add_query_arg( $args, 'admin.php' ) ) );
        exit;
    }

    /**
     * Legacy standalone builder route (?page=layout or ?page=picky_layout) → unified Layouts tab + builder.
     *
     * @return void
     */
    public function picky_redirect_standalone_layout_builder_to_settings() {
        if ( ! is_admin() ) {
            return;
        }
        $picky_layout_page = sanitize_key( picky_request_query_value( 'page' ) );
        if ( $picky_layout_page !== 'layout' && $picky_layout_page !== 'picky_layout' ) {
            return;
        }
        $layout_id = absint( picky_request_query_value( 'layout_id' ) );
        $args      = array(
            'page'    => 'picky_settings',
            'picky-tab' => '9',
        );
        if ( $layout_id > 0 ) {
            $args['layout_id'] = $layout_id;
        }
        wp_safe_redirect( admin_url( add_query_arg( $args, 'admin.php' ) ) );
        exit;
    }

    private function picky_doc_option_title( $slug ) {
        $labels = array(
            'canvas_width'                => __( 'Canvas width', 'pickybooking' ),
            'canvas_height'               => __( 'Canvas height', 'pickybooking' ),
            'canvas_elements_size'        => __( 'Canvas element scale', 'pickybooking' ),
            'background-canvas'           => __( 'Canvas background image', 'pickybooking' ),
            'background-canvas-mode'      => __( 'Canvas background fit mode', 'pickybooking' ),
            'background-table'            => __( 'Legacy table background', 'pickybooking' ),
            'background-seat'             => __( 'Legacy seat background', 'pickybooking' ),
            'position'                    => __( 'Legacy position data', 'pickybooking' ),
            'reservable_item_name'        => __( 'Reservable item singular name', 'pickybooking' ),
            'reservable_items_name'       => __( 'Reservable item plural name', 'pickybooking' ),
            'form_message'                => __( 'Form helper message', 'pickybooking' ),
            'form_confirm'                => __( 'Require consent checkbox', 'pickybooking' ),
            'form_confirm_text'           => __( 'Consent text HTML', 'pickybooking' ),
            'form_email'                  => __( 'Admin notification email', 'pickybooking' ),
            'form_thx'                    => __( 'Thank-you message', 'pickybooking' ),
            'send_customer_email'         => __( 'Send confirmation to customer', 'pickybooking' ),
            'shortcode_layout'            => __( 'Wizard flow mode', 'pickybooking' ),
            'container_align'             => __( 'Widget alignment in page', 'pickybooking' ),
            'shop_people_amount'          => __( 'Ask for party size', 'pickybooking' ),
            'show_email'                  => __( 'Show email field', 'pickybooking' ),
            'show_phone'                  => __( 'Show phone field on booking forms', 'pickybooking' ),
            'show_people'                 => __( 'Show max persons on map items', 'pickybooking' ),
            'show_seats'                  => __( 'Show center seat/capacity badge', 'pickybooking' ),
            'show_id_badge'               => __( 'Show ID badge on map items', 'pickybooking' ),
            'show_element_label'          => __( 'Show element label on map items', 'pickybooking' ),
            'show_time_to'                => __( 'Allow end time field', 'pickybooking' ),
            'animate_bookable_items'      => __( 'Animate bookable items on the map', 'pickybooking' ),
            'mobile_table_select'         => __( 'Mobile item selection mode', 'pickybooking' ),
            'booking_mode'                => __( 'Booking conflict mode', 'pickybooking' ),
            'send_sms'                    => __( 'Send SMS notifications', 'pickybooking' ),
            'sms_key'                     => __( 'Vonage API key', 'pickybooking' ),
            'sms_secret'                  => __( 'Vonage API secret', 'pickybooking' ),
            'sms_phone'                   => __( 'SMS destination phone', 'pickybooking' ),
            'deposit_enabled'             => __( 'Require payment before confirmation', 'pickybooking' ),
            'deposit_amount'              => __( 'Fixed payment amount (or fallback)', 'pickybooking' ),
            'deposit_currency'            => __( 'Payment currency', 'pickybooking' ),
            'payment_amount_source'       => __( 'Payment amount source', 'pickybooking' ),
            'payment_provider'            => __( 'Payment provider', 'pickybooking' ),
            'stripe_publishable_key'      => __( 'Stripe publishable key', 'pickybooking' ),
            'stripe_secret_key'           => __( 'Stripe secret key', 'pickybooking' ),
            'paypal_client_id'            => __( 'PayPal client ID (reserved)', 'pickybooking' ),
            'paypal_client_secret'        => __( 'PayPal client secret (reserved)', 'pickybooking' ),
            'hubspot_enabled'             => __( 'Send new bookings to HubSpot CRM', 'pickybooking' ),
            'hubspot_access_token'        => __( 'HubSpot access token (legacy private app or developer project)', 'pickybooking' ),
            'hubspot_sync_deals'          => __( 'Create a HubSpot deal for each booking', 'pickybooking' ),
            'hubspot_deal_pipeline_id'    => __( 'Deal pipeline ID (optional)', 'pickybooking' ),
            'hubspot_deal_stage_id'       => __( 'Deal stage ID (optional)', 'pickybooking' ),
            'mailchimp_enabled'           => __( 'Send new bookings to Mailchimp', 'pickybooking' ),
            'mailchimp_api_key'           => __( 'Mailchimp Marketing API key', 'pickybooking' ),
            'mailchimp_list_id'           => __( 'Mailchimp Audience (List) ID', 'pickybooking' ),
            'n8n_webhook_enabled'         => __( 'Send booking webhook (n8n)', 'pickybooking' ),
            'n8n_webhook_url'             => __( 'n8n webhook URL', 'pickybooking' ),
            'n8n_webhook_secret'          => __( 'n8n webhook signing secret (optional)', 'pickybooking' ),
            'zapier_webhook_enabled'      => __( 'Send booking webhook (Zapier)', 'pickybooking' ),
            'zapier_webhook_url'          => __( 'Zapier Catch Hook URL', 'pickybooking' ),
            'zapier_webhook_secret'       => __( 'Zapier webhook signing secret (optional)', 'pickybooking' ),
            'make_webhook_enabled'        => __( 'Send booking webhook (Make.com)', 'pickybooking' ),
            'make_webhook_url'            => __( 'Make.com Custom webhook URL', 'pickybooking' ),
            'make_webhook_secret'         => __( 'Make.com webhook signing secret (optional)', 'pickybooking' ),
            'gcal_enabled'                => __( 'Sync bookings to Google Calendar', 'pickybooking' ),
            'gcal_service_account_json'   => __( 'Service Account JSON key', 'pickybooking' ),
            'gcal_calendar_id'            => __( 'Google Calendar ID', 'pickybooking' ),
            'gcal_add_attendees'          => __( 'Add customer as attendee', 'pickybooking' ),
            'days_ahead'                  => __( 'How many days ahead to allow bookings', 'pickybooking' ),
            'table_booked_time'           => __( 'Default booking duration (hours)', 'pickybooking' ),
            'table_before_booked_time'    => __( 'Buffer before booking (hours)', 'pickybooking' ),
        );

        if ( isset( $labels[ $slug ] ) ) {
            return (string) $labels[ $slug ];
        }
        return ucwords( str_replace( array( '_', '-' ), ' ', (string) $slug ) );
    }

    private function picky_doc_is_hidden_from_reference( $slug ) {
        $s = (string) $slug;
        if ( strpos( $s, 'color_main_' ) === 0 || strpos( $s, 'color_datepicker_' ) === 0 || strpos( $s, 'color_form_' ) === 0 ) {
            return true;
        }
        if ( strpos( $s, 'work_days_' ) === 0 ) {
            return true;
        }
        return false;
    }

    private function picky_doc_is_crucial( $slug ) {
        $crucial = array(
            'reservable_item_name',
            'reservable_items_name',
            'shortcode_layout',
            'mobile_table_select',
            'shop_people_amount',
            'booking_mode',
            'days_ahead',
            'table_booked_time',
            'table_before_booked_time',
            'deposit_enabled',
            'payment_provider',
            'send_sms',
        );
        return in_array( (string) $slug, $crucial, true );
    }

    private function picky_doc_crucial_note( $slug, $item_label, $items_label ) {
        $slug = (string) $slug;
        switch ( $slug ) {
            case 'reservable_item_name':
                return __( 'What you call one bookable object everywhere (Table, Court, Room, …).', 'pickybooking' );
            case 'reservable_items_name':
                return __( 'Plural label for headings/lists. If empty, the plugin pluralizes the singular name.', 'pickybooking' );
            case 'shortcode_layout':
                return __( 'Controls the booking wizard order on the frontend (map-first, date/time-first, layout-first Smart Map, or auto-assign).', 'pickybooking' );
            case 'mobile_table_select':
                return sprintf(
                    /* translators: 1: singular item, 2: plural items */
                    __( 'Controls how mobile users select a %1$s: map, list, or no map. Does not change desktop behavior. Applies to %2$s selection only.', 'pickybooking' ),
                    $item_label,
                    $items_label
                );
            case 'shop_people_amount':
                return __( 'Turn this on for capacity sharing and any flow where headcount matters.', 'pickybooking' );
            case 'booking_mode':
                return sprintf(
                    /* translators: %s: plural reservable items */
                    __( 'Exclusive: one party blocks a whole item for the time range. Capacity: many parties can share the same item until it is full (requires party size). Applies to: %s.', 'pickybooking' ),
                    $items_label
                );
            case 'days_ahead':
                return __( 'Rolling booking window: last selectable day is “today + N days”.', 'pickybooking' );
            case 'table_booked_time':
                return __( 'Default length of a reservation if the guest does not pick an end time. Also used for overlap checks in many flows.', 'pickybooking' );
            case 'table_before_booked_time':
                return __( 'Extra blocked time *before* an existing booking starts (turnover / setup).', 'pickybooking' );
            case 'deposit_enabled':
                return __( 'If on, a booking is not final until online payment completes (per your payment settings).', 'pickybooking' );
            case 'payment_provider':
                return __( 'Where online payments are processed: off, Stripe (direct), or WooCommerce (uses your store gateways).', 'pickybooking' );
            case 'send_sms':
                return __( 'Optional: sends an SMS to the staff number below when a booking is created (Vonage).', 'pickybooking' );
        }
        return '';
    }

    private function picky_doc_format_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return __( '[complex value]', 'pickybooking' );
        }
        $value = (string) $value;
        if ( $value === '' ) {
            return __( 'empty', 'pickybooking' );
        }
        if ( $value === '1' ) {
            return __( 'enabled (1)', 'pickybooking' );
        }
        if ( $value === '0' ) {
            return __( 'disabled (0)', 'pickybooking' );
        }
        return $value;
    }

    private function picky_doc_option_group( $slug ) {
        if ( in_array( $slug, array( 'days_ahead', 'table_booked_time', 'table_before_booked_time' ), true ) ) {
            return 'time_engine';
        }
        if ( in_array( $slug, array( 'form_email', 'form_thx', 'send_customer_email' ), true ) ) {
            return 'communications';
        }
        if ( in_array( $slug, array( 'deposit_enabled', 'deposit_amount', 'deposit_currency', 'payment_amount_source', 'payment_provider', 'stripe_publishable_key', 'stripe_secret_key', 'paypal_client_id', 'paypal_client_secret', 'hubspot_enabled', 'hubspot_access_token', 'hubspot_sync_deals', 'hubspot_deal_pipeline_id', 'hubspot_deal_stage_id', 'mailchimp_enabled', 'mailchimp_api_key', 'mailchimp_list_id', 'n8n_webhook_enabled', 'n8n_webhook_url', 'n8n_webhook_secret', 'zapier_webhook_enabled', 'zapier_webhook_url', 'zapier_webhook_secret', 'make_webhook_enabled', 'make_webhook_url', 'make_webhook_secret' ), true ) ) {
            return 'payments';
        }
        if ( in_array( $slug, array( 'send_sms', 'sms_key', 'sms_secret', 'sms_phone' ), true ) ) {
            return 'sms';
        }
        if ( strpos( $slug, 'canvas_' ) === 0 || strpos( $slug, 'background-' ) === 0 || $slug === 'position' ) {
            return 'layout';
        }
        return 'booking';
    }

    private function picky_doc_sort_option_rows( $rows ) {
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return $rows;
        }
        usort(
            $rows,
            function ( $a, $b ) {
                return strcmp( (string) $a['slug'], (string) $b['slug'] );
            }
        );
        return $rows;
    }

    private function picky_doc_weekly_hours_table( $settings, $defaults ) {
        $days = array(
            'monday'    => __( 'Monday', 'pickybooking' ),
            'tuesday'   => __( 'Tuesday', 'pickybooking' ),
            'wednesday' => __( 'Wednesday', 'pickybooking' ),
            'thursday'  => __( 'Thursday', 'pickybooking' ),
            'friday'    => __( 'Friday', 'pickybooking' ),
            'saturday'  => __( 'Saturday', 'pickybooking' ),
            'sunday'    => __( 'Sunday', 'pickybooking' ),
        );

        $get = function ( $key ) use ( $settings, $defaults ) {
            if ( array_key_exists( $key, $settings ) && $settings[ $key ] !== null && (string) $settings[ $key ] !== '' ) {
                return (string) $settings[ $key ];
            }
            return array_key_exists( $key, $defaults ) ? (string) $defaults[ $key ] : '';
        };

        $on_label  = __( 'Open', 'pickybooking' );
        $off_label = __( 'Closed', 'pickybooking' );

        echo '<p class="description">';
        echo esc_html__( 'This is your weekly availability model (per weekday + optional split shift). It is not the per-reservation length — that is covered by “Default booking duration / buffer” above.', 'pickybooking' );
        echo '</p>';

        echo '<div class="picky-docs-table-wrap"><table class="widefat striped picky-docs-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Day', 'pickybooking' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'pickybooking' ) . '</th>';
        echo '<th>' . esc_html__( 'Window 1', 'pickybooking' ) . '</th>';
        echo '<th>' . esc_html__( 'Split', 'pickybooking' ) . '</th>';
        echo '<th>' . esc_html__( 'Window 2', 'pickybooking' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $days as $key => $label ) {
            $enabled   = $get( 'work_days_' . $key ) === '1' ? $on_label : $off_label;
            $t1s       = $get( 'work_days_' . $key . '_time_start' );
            $t1e       = $get( 'work_days_' . $key . '_time_end' );
            $more      = $get( 'work_days_' . $key . '_more' ) === '1' ? __( 'On', 'pickybooking' ) : __( 'Off', 'pickybooking' );
            $t2s       = $get( 'work_days_' . $key . '_time_start_more' );
            $t2e       = $get( 'work_days_' . $key . '_time_end_more' );
            $w1        = ( $t1s !== '' && $t1e !== '' ) ? ( $t1s . '–' . $t1e ) : '—';
            $w2        = ( $t2s !== '' && $t2e !== '' ) ? ( $t2s . '–' . $t2e ) : '—';
            if ( $get( 'work_days_' . $key . '_more' ) !== '1' ) {
                $w2 = '—';
            }
            echo '<tr>';
            echo '<td>' . esc_html( (string) $label ) . '</td>';
            echo '<td>' . esc_html( (string) $enabled ) . '</td>';
            echo '<td><code>' . esc_html( (string) $w1 ) . '</code></td>';
            echo '<td>' . esc_html( (string) $more ) . '</td>';
            echo '<td><code>' . esc_html( (string) $w2 ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Parse reservation date string into WP-local DateTimeImmutable.
     *
     * @param string $raw
     * @return \DateTimeImmutable|null
     */
    private function picky_parse_reservation_date_local( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return null;
        }
        $tz = wp_timezone();
        $formats = array( 'm/d/Y', 'Y-m-d', 'd.m.Y' );
        foreach ( $formats as $format ) {
            $dt = \DateTimeImmutable::createFromFormat( $format, $raw, $tz );
            if ( $dt instanceof \DateTimeImmutable ) {
                return $dt->setTime( 0, 0, 0 );
            }
        }
        $ts = strtotime( $raw );
        if ( $ts === false ) {
            return null;
        }
        return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
    }

    /**
     * Parse HH:MM into minutes since midnight.
     *
     * @param string $time
     * @return int|null
     */
    private function picky_parse_time_to_minutes( $time ) {
        $time = trim( (string) $time );
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
            return null;
        }
        $h = intval( $m[1] );
        $i = intval( $m[2] );
        if ( $h < 0 || $h > 24 || $i < 0 || $i > 59 ) {
            return null;
        }
        if ( $h === 24 && $i !== 0 ) {
            return null;
        }
        return ( $h * 60 ) + $i;
    }

    /**
     * Union of configured work-day hours (Work Days settings) for the calendar grid.
     *
     * @return array{start_m: int, end_m: int}
     */
    private function picky_calendar_work_hours_bounds() {
        $opts     = Info::get_settings();
        $day_keys = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $min_start = null;
        $max_end   = null;

        foreach ( $day_keys as $day ) {
            if ( ! isset( $opts[ 'work_days_' . $day ] ) || (string) $opts[ 'work_days_' . $day ] !== '1' ) {
                continue;
            }
            $windows = array(
                array(
                    (string) ( $opts[ 'work_days_' . $day . '_time_start' ] ?? '' ),
                    (string) ( $opts[ 'work_days_' . $day . '_time_end' ] ?? '' ),
                ),
            );
            if ( isset( $opts[ 'work_days_' . $day . '_more' ] ) && (string) $opts[ 'work_days_' . $day . '_more' ] === '1' ) {
                $windows[] = array(
                    (string) ( $opts[ 'work_days_' . $day . '_time_start_more' ] ?? '' ),
                    (string) ( $opts[ 'work_days_' . $day . '_time_end_more' ] ?? '' ),
                );
            }
            foreach ( $windows as $window ) {
                $start_m = $this->picky_parse_time_to_minutes( $window[0] );
                $end_m   = $this->picky_parse_time_to_minutes( $window[1] );
                if ( $start_m === null || $end_m === null || $end_m <= $start_m ) {
                    continue;
                }
                if ( $min_start === null || $start_m < $min_start ) {
                    $min_start = $start_m;
                }
                if ( $max_end === null || $end_m > $max_end ) {
                    $max_end = $end_m;
                }
            }
        }

        if ( $min_start === null || $max_end === null ) {
            $min_start = 8 * 60;
            $max_end   = 22 * 60;
        }

        return array(
            'start_m' => (int) $min_start,
            'end_m'   => (int) $max_end,
        );
    }

    /**
     * Render one compact booking card in the reservations calendar.
     *
     * @param array<string,mixed> $item         Calendar item payload.
     * @param string              $layout_label Human layout name.
     * @return void
     */
    private function picky_render_calendar_item_card( array $item, $layout_label ) {
        $status       = (string) ( $item['status'] ?? '' );
        if ( $status === 'reserved' ) {
            $state_class  = 'picky-reserved';
            $status_badge = __( 'Reserved', 'pickybooking' );
        } elseif ( $status === 'requests' ) {
            $state_class  = 'picky-request';
            $status_badge = __( 'Request', 'pickybooking' );
        } elseif ( $status === 'canceled' ) {
            $state_class  = 'picky-canceled';
            $status_badge = __( 'Canceled', 'pickybooking' );
        } elseif ( $status === 'draft' ) {
            $state_class  = 'picky-draft';
            $status_badge = __( 'Draft', 'pickybooking' );
        } else {
            $state_class  = 'picky-request';
            $status_badge = __( 'Request', 'pickybooking' );
        }
        if ( ! empty( $item['is_new'] ) ) {
            $state_class .= ' picky-new-booking';
        }
        $time_label = sprintf(
            '%02d:%02d–%02d:%02d',
            (int) floor( (int) $item['start_m'] / 60 ),
            (int) $item['start_m'] % 60,
            (int) floor( (int) $item['end_m'] / 60 ),
            (int) $item['end_m'] % 60
        );
        $people = max( 1, (int) ( $item['people'] ?? 1 ) );
        $title  = sprintf(
            '#%s · %s',
            (string) ( $item['id'] ?? '' ),
            (string) ( $item['name'] ?? '' )
        );
        $sub = sprintf(
            '%s · %s',
            (string) $layout_label,
            sprintf(
                /* translators: %d: number of guests */
                _n( '%d guest', '%d guests', $people, 'pickybooking' ),
                $people
            )
        );
        $is_new = ! empty( $item['is_new'] );
        $payment   = sanitize_key( (string) ( $item['payment'] ?? '' ) );
        $pay_badge = '';
        $pay_mod   = '';
        if ( $payment === 'paid' ) {
            $pay_badge = __( 'Paid', 'pickybooking' );
            $pay_mod   = 'picky-cal-item-badge--paid';
        } elseif ( in_array( $payment, array( 'pending', 'cancelled', 'not_paid', 'init_failed' ), true ) ) {
            $pay_badge = __( 'Unpaid', 'pickybooking' );
            $pay_mod   = 'picky-cal-item-badge--unpaid';
        }
        $icon_key = $this->picky_calendar_card_icon_key( $status, $payment );
        ?>
        <a
            class="picky-cal-item picky-cal-open-drawer <?php echo esc_attr( $state_class ); ?>"
            href="<?php echo esc_url( (string) $item['edit_link'] ); ?>"
            data-link="<?php echo esc_attr( (string) $item['edit_link'] ); ?>"
            data-title="<?php echo esc_attr( '#' . (string) $item['id'] . ' - ' . (string) $item['name'] ); ?>"
            data-time="<?php echo esc_attr( $time_label ); ?>"
            data-layout="<?php echo esc_attr( (string) $layout_label ); ?>"
            data-status="<?php echo esc_attr( $status ); ?>"
            data-people="<?php echo esc_attr( (string) $people ); ?>"
            data-payment="<?php echo esc_attr( $payment ); ?>"
            data-icon="<?php echo esc_attr( $icon_key ); ?>"
        >
            <span class="picky-cal-item-preview picky-cal-item-preview--<?php echo esc_attr( $icon_key ); ?>">
                <span class="picky-cal-item-icon" aria-hidden="true">
                    <?php echo wp_kses( $this->picky_calendar_card_icon_svg( $icon_key ), \Picky\Booking_Icons::kses_allowed() ); ?>
                </span>
                <span class="picky-cal-item-badges">
                    <?php if ( $is_new ) : ?>
                        <span class="picky-cal-item-badge picky-cal-item-badge--new"><?php esc_html_e( 'New', 'pickybooking' ); ?></span>
                    <?php endif; ?>
                    <span class="picky-cal-item-badge picky-cal-item-badge--status"><?php echo esc_html( $status_badge ); ?></span>
                    <?php if ( $pay_badge !== '' ) : ?>
                        <span class="picky-cal-item-badge <?php echo esc_attr( $pay_mod ); ?>"><?php echo esc_html( $pay_badge ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="picky-cal-item-time"><?php echo esc_html( $time_label ); ?></span>
            </span>
            <span class="picky-cal-item-body">
                <span class="picky-cal-item-title"><?php echo esc_html( $title ); ?></span>
                <span class="picky-cal-item-sub"><?php echo esc_html( $sub ); ?></span>
            </span>
        </a>
        <?php
    }

    /**
     * Soft status icon key for calendar cards (paid / canceled / unpaid / …).
     *
     * @param string $status  Booking post status.
     * @param string $payment Payment meta status.
     * @return string
     */
    private function picky_calendar_card_icon_key( $status, $payment ) {
        $status  = sanitize_key( (string) $status );
        $payment = sanitize_key( (string) $payment );

        if ( $status === 'canceled' ) {
            return 'canceled';
        }
        if ( $payment === 'paid' ) {
            return 'paid';
        }
        if ( in_array( $payment, array( 'pending', 'cancelled', 'not_paid', 'init_failed' ), true ) ) {
            return ( $payment === 'pending' ) ? 'pending' : 'unpaid';
        }
        if ( $status === 'requests' ) {
            return 'request';
        }
        if ( $status === 'draft' ) {
            return 'draft';
        }
        return 'reserved';
    }

    /**
     * Inline SVG for calendar card status icon.
     *
     * @param string $key Icon key from picky_calendar_card_icon_key().
     * @return string
     */
    private function picky_calendar_card_icon_svg( $key ) {
        $key = sanitize_key( (string) $key );
        $common = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';

        switch ( $key ) {
            case 'paid':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><circle cx="12" cy="12" r="8.25"/><path d="M8.2 12.2l2.4 2.4 5.2-5.3"/></svg>';
            case 'canceled':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><circle cx="12" cy="12" r="8.25"/><path d="M9 9l6 6M15 9l-6 6"/></svg>';
            case 'unpaid':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><circle cx="12" cy="12" r="8.25"/><path d="M12 7.5v5.2"/><circle cx="12" cy="16.2" r="0.85" fill="currentColor" stroke="none"/></svg>';
            case 'pending':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><circle cx="12" cy="12" r="8.25"/><path d="M12 8v4.4l2.8 1.7"/></svg>';
            case 'request':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><circle cx="12" cy="12" r="8.25"/><path d="M9.75 9.35a2.35 2.35 0 114.05 1.65c-.85.7-1.4 1.15-1.4 2.35"/><circle cx="12" cy="16.35" r="0.95" fill="currentColor" stroke="none"/></svg>';
            case 'draft':
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><path d="M8 4.8h5.2L16.8 8.4V19.2a1 1 0 01-1 1H8a1 1 0 01-1-1V5.8a1 1 0 011-1z"/><path d="M13 4.8V8.4h3.6"/><path d="M9.2 12.2h5.6M9.2 15.2h4.2"/></svg>';
            case 'reserved':
            default:
                return '<svg viewBox="0 0 24 24" ' . $common . ' aria-hidden="true"><rect x="4.5" y="6" width="15" height="13.5" rx="2"/><path d="M8 4.5v3M16 4.5v3M4.5 10h15"/><path d="M9.2 14.2l1.7 1.7 3.9-3.9"/></svg>';
        }
    }

    /**
     * Layout preview bg + thumbnail URL for calendar cards (cached per request).
     *
     * @param int $layout_id Layout post ID.
     * @return array{bg:string,url:string}
     */
    private function picky_calendar_layout_preview( $layout_id ) {
        static $cache = array();
        $layout_id    = absint( $layout_id );
        if ( array_key_exists( $layout_id, $cache ) ) {
            return $cache[ $layout_id ];
        }
        $bg  = '#efeaf8';
        $url = '';
        if ( $layout_id > 0 && get_post_type( $layout_id ) === 'picky_layout' ) {
            if ( class_exists( '\\Picky\\Layout_Library' ) ) {
                $colors = \Picky\Layout_Library::preview_colors_for_layout( $layout_id );
                if ( ! empty( $colors['bg'] ) ) {
                    $bg = (string) $colors['bg'];
                }
            } elseif ( function_exists( 'picky_layout_canvas_bg_color' ) ) {
                $bg = (string) picky_layout_canvas_bg_color( $layout_id );
            }
            if ( class_exists( '\\Picky\\Layout_Preview' ) ) {
                // Prefer the same gallery attachment; fall back to a cropped SVG for small cards.
                $aid = (int) get_post_meta( $layout_id, \Picky\Layout_Preview::META_ATTACHMENT, true );
                $url = '';
                if ( $aid > 0 ) {
                    $maybe = wp_get_attachment_image_url( $aid, 'medium_large' );
                    if ( ! $maybe ) {
                        $maybe = wp_get_attachment_url( $aid );
                    }
                    if ( is_string( $maybe ) && $maybe !== '' ) {
                        $mime = (string) get_post_mime_type( $aid );
                        // Raster screenshots match the Layouts gallery; skip tiny SVG attachments.
                        if ( $mime === '' || strpos( $mime, 'image/svg' ) === false ) {
                            $ver = (string) get_post_modified_time( 'U', true, $aid );
                            $url = add_query_arg( 'ver', $ver !== '' ? $ver : (string) time(), $maybe );
                        }
                    }
                }
                if ( $url === '' ) {
                    $url = (string) \Picky\Layout_Preview::svg_data_uri_for_layout( $layout_id, true, true );
                }
            }
        }
        $cache[ $layout_id ] = array(
            'bg'  => $bg,
            'url' => $url,
        );
        return $cache[ $layout_id ];
    }

    /**
     * Human-readable layout name for calendar cards (published layout title).
     *
     * @param int $layout_id Stored _picky_reservation_layout_id.
     * @return string
     */
    private function picky_calendar_layout_display_name( $layout_id ) {
        static $cache = array();
        $layout_id = absint( $layout_id );
        if ( $layout_id === 0 ) {
            return __( 'No layout', 'pickybooking' );
        }
        if ( array_key_exists( $layout_id, $cache ) ) {
            return $cache[ $layout_id ];
        }
        if ( get_post_type( $layout_id ) !== 'picky_layout' ) {
            /* translators: %d: layout post ID when the layout no longer exists */
            $cache[ $layout_id ] = sprintf( __( 'Layout #%d (unavailable)', 'pickybooking' ), $layout_id );
            return $cache[ $layout_id ];
        }
        $title = (string) get_the_title( $layout_id );
        if ( $title === '' ) {
            /* translators: %d: layout post ID when title is empty */
            $cache[ $layout_id ] = sprintf( __( 'Layout #%d', 'pickybooking' ), $layout_id );
            return $cache[ $layout_id ];
        }
        $cache[ $layout_id ] = $title;
        return $cache[ $layout_id ];
    }

    /**
     * Build reservation dataset for calendar grid.
     *
     * @param \DateTimeImmutable         $start_date Inclusive.
     * @param int                        $days       Number of days.
     * @param array<string,string|int>   $filters    Optional filters.
     * @return array{by_day: array<string,array<int,array<string,mixed>>>, rows: int, row_start: int, row_minutes: int}
     */
    private function picky_calendar_dataset( \DateTimeImmutable $start_date, $days, $filters = array() ) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list/filter queries require meta_query
        $days = max( 1, intval( $days ) );
        $end_date = $start_date->modify( '+' . $days . ' days' );
        $settings = Info::get_settings();
        $default_duration_h = isset( $settings['table_booked_time'] ) ? max( 1, intval( $settings['table_booked_time'] ) ) : 2;
        $default_duration_m = $default_duration_h * 60;
        $status_filter = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : 'all';
        if ( ! in_array( $status_filter, array( 'all', 'picky_reserved', 'picky_requests', 'picky_canceled', 'reserved', 'requests', 'canceled' ), true ) ) {
            $status_filter = 'all';
        }
        $layout_filter = isset( $filters['layout_id'] ) ? absint( $filters['layout_id'] ) : 0;
        $table_filter = isset( $filters['table_id'] ) ? trim( (string) $filters['table_id'] ) : '';
        $candidate_date_values = array();
        for ( $d = 0; $d < $days; $d++ ) {
            $scan_day = $start_date->modify( '+' . $d . ' days' );
            $candidate_date_values[] = $scan_day->format( 'm/d/Y' );
            $candidate_date_values[] = $scan_day->format( 'Y-m-d' );
            $candidate_date_values[] = $scan_day->format( 'd.m.Y' );
        }
        $candidate_date_values = array_values( array_unique( $candidate_date_values ) );

        if ( $status_filter === 'all' ) {
            $query_statuses = array( 'reserved', 'requests', 'canceled' );
        } elseif ( $status_filter === 'canceled' ) {
            $query_statuses = array( 'canceled' );
        } elseif ( $status_filter === 'reserved' ) {
            $query_statuses = array( 'reserved' );
        } else {
            $query_statuses = array( 'requests' );
        }

        $all = get_posts(
            array(
                'post_type'      => 'picky_booking',
                'post_status'    => $query_statuses,
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => '_picky_booking_date',
                        'value'   => $candidate_date_values,
                        'compare' => 'IN',
                    ),
                ),
            )
        );
        if ( ! empty( $all ) ) {
            update_meta_cache( 'post', $all );
        }

        $row_minutes = 30;
        $hours       = $this->picky_calendar_work_hours_bounds();
        $row_start   = (int) floor( (int) $hours['start_m'] / $row_minutes );
        $row_end     = (int) max( $row_start, (int) ceil( (int) $hours['end_m'] / $row_minutes ) - 1 );
        $rows        = $row_end - $row_start + 1;
        $by_day = array();
        for ( $d = 0; $d < $days; $d++ ) {
            $key = $start_date->modify( '+' . $d . ' days' )->format( 'Y-m-d' );
            $by_day[ $key ] = array();
        }

        foreach ( $all as $post_id ) {
            $status = (string) get_post_status( $post_id );
            if ( $status_filter !== 'all' && $status !== $status_filter ) {
                continue;
            }
            $date_raw = get_post_meta( $post_id, '_picky_booking_date', true );
            $date_dt = $this->picky_parse_reservation_date_local( (string) $date_raw );
            if ( ! $date_dt ) {
                continue;
            }
            if ( $date_dt < $start_date || $date_dt >= $end_date ) {
                continue;
            }

            $time_raw = (string) get_post_meta( $post_id, '_picky_booking_time', true );
            $time_to_raw = (string) get_post_meta( $post_id, '_picky_booking_time_to', true );
            $start_m = $this->picky_parse_time_to_minutes( $time_raw );
            if ( $start_m === null || $start_m >= ( 24 * 60 ) ) {
                continue;
            }
            $table_id = (string) get_post_meta( $post_id, '_picky_booking_id', true );
            if ( $table_filter !== '' && stripos( $table_id, $table_filter ) === false ) {
                continue;
            }
            $layout_id = absint( get_post_meta( $post_id, '_picky_reservation_layout_id', true ) );
            if ( $layout_filter > 0 && $layout_filter !== $layout_id ) {
                continue;
            }
            $end_m = $this->picky_parse_time_to_minutes( $time_to_raw );
            if ( $end_m === null || $end_m <= $start_m ) {
                $end_m = min( 24 * 60, $start_m + $default_duration_m );
            }
            $row = max( 0, min( (int) $rows - 1, (int) floor( $start_m / $row_minutes ) ) );
            $day_key = $date_dt->format( 'Y-m-d' );

            $by_day[ $day_key ][] = array(
                'row'       => $row,
                'start_m'   => $start_m,
                'end_m'     => $end_m,
                'id'        => $table_id,
                'name'      => (string) get_post_meta( $post_id, '_picky_booking_name', true ),
                'people'    => (int) get_post_meta( $post_id, '_picky_booking_people', true ),
                'status'    => $status,
                'layout_id' => $layout_id,
                'is_new'    => (string) get_post_meta( $post_id, '_picky_admin_seen', true ) !== '1',
                'payment'   => sanitize_key( (string) get_post_meta( $post_id, '_picky_payment_status', true ) ),
                'edit_link' => get_edit_post_link( $post_id ),
            );
        }

        foreach ( $by_day as $key => $items ) {
            usort(
                $items,
                static function ( $a, $b ) {
                    return ( (int) $a['start_m'] ) <=> ( (int) $b['start_m'] );
                }
            );
            $by_day[ $key ] = $items;
        }

        return array(
            'by_day'      => $by_day,
            'rows'        => $rows,
            'row_start'   => $row_start,
            'row_minutes' => $row_minutes,
        );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    /**
     * Render day/week reservation calendar in admin.
     *
     * @param bool $embed True when rendered inside the Settings screen (tab).
     * @return void
     */
    public function render_calendar( $embed = false ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only calendar UI from GET
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $view = sanitize_key( picky_request_query_value( 'view' ) );
        if ( $view === '' ) {
            $view = 'week';
        }
        if ( ! in_array( $view, array( 'day', 'week' ), true ) ) {
            $view = 'week';
        }
        $date_raw = sanitize_text_field( picky_request_query_value( 'date' ) );
        $tz = wp_timezone();
        $today = new \DateTimeImmutable( 'now', $tz );
        $cursor = $today->setTime( 0, 0, 0 );
        if ( $date_raw !== '' ) {
            $tmp = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_raw, $tz );
            if ( $tmp instanceof \DateTimeImmutable ) {
                $cursor = $tmp->setTime( 0, 0, 0 );
            }
        }

        $status_filter = sanitize_key( picky_request_query_value( 'status' ) );
        if ( $status_filter === '' ) {
            $status_filter = 'all';
        }
        if ( ! in_array( $status_filter, array( 'all', 'picky_reserved', 'picky_requests', 'picky_canceled', 'reserved', 'requests', 'canceled' ), true ) ) {
            $status_filter = 'all';
        }
        $layout_filter = absint( picky_request_query_value( 'layout_id' ) );
        $table_filter = sanitize_text_field( picky_request_query_value( 'table_id' ) );
        $calendar_mode = sanitize_key( picky_request_query_value( 'mode' ) );
        if ( $calendar_mode === '' ) {
            $calendar_mode = 'board';
        }
        if ( ! in_array( $calendar_mode, array( 'board', 'lanes' ), true ) ) {
            $calendar_mode = 'board';
        }
        $saved_view = sanitize_key( picky_request_query_value( 'saved_view' ) );
        if ( $saved_view === '' ) {
            $saved_view = 'custom';
        }
        if ( ! in_array( $saved_view, array( 'custom', 'dinner_rush', 'new_requests', 'today_only' ), true ) ) {
            $saved_view = 'custom';
        }
        $sort_by = sanitize_key( picky_request_query_value( 'sort_by' ) );
        if ( $sort_by === '' ) {
            $sort_by = 'time';
        }
        if ( ! in_array( $sort_by, array( 'time', 'table', 'name', 'party', 'status', 'new' ), true ) ) {
            $sort_by = 'time';
        }
        $density = sanitize_key( picky_request_query_value( 'density' ) );
        if ( $density === '' ) {
            $density = 'cozy';
        }
        if ( ! in_array( $density, array( 'cozy', 'compact' ), true ) ) {
            $density = 'cozy';
        }
        $time_from_m = null;
        $time_to_m = null;
        if ( $saved_view === 'new_requests' ) {
            $status_filter = 'requests';
        } elseif ( $saved_view === 'dinner_rush' ) {
            $time_from_m = 17 * 60;
            $time_to_m = 22 * 60;
            $density = 'compact';
        } elseif ( $saved_view === 'today_only' ) {
            $view = 'day';
            $date_raw = $today->format( 'Y-m-d' );
            $density = 'cozy';
        }
        if ( $calendar_mode === 'lanes' ) {
            $view = 'day';
        }

        $start_date = ( $view === 'week' ) ? $cursor->modify( 'monday this week' ) : $cursor;
        $days = ( $view === 'week' ) ? 7 : 1;
        $dataset = $this->picky_calendar_dataset(
            $start_date,
            $days,
            array(
                'status'    => $status_filter,
                'layout_id' => $layout_filter,
                'table_id'  => $table_filter,
            )
        );
        $by_day = $dataset['by_day'];
        $rows = (int) $dataset['rows'];
        $row_start = max( 0, (int) ( $dataset['row_start'] ?? 0 ) );
        $row_minutes = max( 15, (int) $dataset['row_minutes'] );
        $hours_bounds = $this->picky_calendar_work_hours_bounds();
        if ( $time_from_m !== null && $time_to_m !== null ) {
            foreach ( $by_day as $by_day_key => $by_day_items ) {
                $by_day[ $by_day_key ] = array_values(
                    array_filter(
                        $by_day_items,
                        static function ( $it ) use ( $time_from_m, $time_to_m ) {
                            $start_val = isset( $it['start_m'] ) ? (int) $it['start_m'] : -1;
                            return $start_val >= $time_from_m && $start_val < $time_to_m;
                        }
                    )
                );
            }
        }

        $step = ( $view === 'week' ) ? 7 : 1;
        $prev_date = $start_date->modify( '-' . $step . ' days' )->format( 'Y-m-d' );
        $next_date = $start_date->modify( '+' . $step . ' days' )->format( 'Y-m-d' );
        $base_url = admin_url(
            add_query_arg(
                array(
                    'page'    => 'picky_settings',
                    'picky-tab' => '8',
                ),
                'admin.php'
            )
        );
        $cal_wrapper_class = $embed ? 'picky-root picky-cal-wrap picky-cal-wrap--embedded' : 'wrap picky-root picky-cal-wrap';
        $label_end = $start_date->modify( '+' . ( $days - 1 ) . ' days' );
        $layouts = get_posts(
            array(
                'post_type'      => 'picky_layout',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
            )
        );

        $now = new \DateTimeImmutable( 'now', $tz );
        $now_day_key = $now->format( 'Y-m-d' );
        $now_minutes = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );
        $now_row = (int) floor( $now_minutes / $row_minutes );
        $base_args = array(
            'view'      => $view,
            'mode'      => $calendar_mode,
            'saved_view'=> $saved_view,
            'status'    => $status_filter,
            'layout_id' => $layout_filter > 0 ? $layout_filter : null,
            'table_id'  => $table_filter !== '' ? $table_filter : null,
            'sort_by'   => $sort_by,
            'density'   => $density,
        );
        $count_total = 0;
        $count_reserved = 0;
        $count_requests = 0;
        foreach ( $by_day as $items_for_day ) {
            foreach ( $items_for_day as $it ) {
                $count_total++;
                if ( isset( $it['status'] ) && $it['status'] === 'reserved' ) {
                    $count_reserved++;
                } elseif ( isset( $it['status'] ) && $it['status'] === 'requests' ) {
                    $count_requests++;
                }
            }
        }
        ?>
        <div class="<?php echo esc_attr( (string) $cal_wrapper_class ); ?>" data-picky-context="<?php echo $embed ? 'calendar-embedded' : 'calendar'; ?>">
            <div class="picky-cal-head">
                <div class="picky-cal-head__main">
                    <div class="picky-cal-head__copy">
                        <div class="picky-cal-head__title-row">
                            <h1><?php echo esc_html__( 'Reservations Calendar', 'pickybooking' ); ?></h1>
                        </div>
                        <p class="picky-cal-subtitle">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: work-day open time, 2: work-day close time */
                                    __( 'Operational day/week board for all bookings. Showing work hours %1$s–%2$s from Work Days settings.', 'pickybooking' ),
                                    sprintf( '%02d:%02d', (int) floor( (int) $hours_bounds['start_m'] / 60 ), (int) $hours_bounds['start_m'] % 60 ),
                                    sprintf( '%02d:%02d', (int) floor( (int) $hours_bounds['end_m'] / 60 ), (int) $hours_bounds['end_m'] % 60 )
                                )
                            );
                            ?>
                        </p>
                    </div>
                    <div class="picky-cal-stats" role="group" aria-label="<?php echo esc_attr__( 'Calendar stats', 'pickybooking' ); ?>">
                        <div class="picky-cal-stat">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-list"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $count_total ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Total', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <div class="picky-cal-stat picky-cal-stat-reserved">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-calendar"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $count_reserved ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Reserved', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                        <div class="picky-cal-stat picky-cal-stat-requests">
                            <span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-users"></i></span>
                            <span class="picky-cal-stat__body">
                                <strong class="picky-cal-stat__value"><?php echo esc_html( (string) $count_requests ); ?></strong>
                                <span class="picky-cal-stat__label"><?php echo esc_html__( 'Requests', 'pickybooking' ); ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <form method="get" class="picky-cal-filters picky-filters-panel" id="picky-cal-filters">
                <input type="hidden" name="page" value="picky_settings" />
                <input type="hidden" name="picky-tab" value="8" />
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
                <input type="hidden" name="date" value="<?php echo esc_attr( $start_date->format( 'Y-m-d' ) ); ?>" />
                <label>
                    <?php echo esc_html__( 'Saved view', 'pickybooking' ); ?>
                    <select name="saved_view">
                        <option value="custom" <?php selected( $saved_view, 'custom' ); ?>><?php echo esc_html__( 'Custom', 'pickybooking' ); ?></option>
                        <option value="dinner_rush" <?php selected( $saved_view, 'dinner_rush' ); ?>><?php echo esc_html__( 'Dinner rush', 'pickybooking' ); ?></option>
                        <option value="new_requests" <?php selected( $saved_view, 'new_requests' ); ?>><?php echo esc_html__( 'New requests', 'pickybooking' ); ?></option>
                        <option value="today_only" <?php selected( $saved_view, 'today_only' ); ?>><?php echo esc_html__( 'Today only', 'pickybooking' ); ?></option>
                    </select>
                </label>
                <label>
                    <?php echo esc_html__( 'Mode', 'pickybooking' ); ?>
                    <select name="mode">
                        <option value="board" <?php selected( $calendar_mode, 'board' ); ?>><?php echo esc_html__( 'Board', 'pickybooking' ); ?></option>
                        <option value="lanes" <?php selected( $calendar_mode, 'lanes' ); ?>><?php echo esc_html__( 'Table lanes', 'pickybooking' ); ?></option>
                    </select>
                </label>
                <label>
                    <?php echo esc_html__( 'Status', 'pickybooking' ); ?>
                    <select name="status">
                        <option value="all" <?php selected( $status_filter, 'all' ); ?>><?php echo esc_html__( 'All', 'pickybooking' ); ?></option>
                        <option value="reserved" <?php selected( $status_filter, 'reserved' ); ?>><?php echo esc_html__( 'Reserved', 'pickybooking' ); ?></option>
                        <option value="requests" <?php selected( $status_filter, 'requests' ); ?>><?php echo esc_html__( 'Requests', 'pickybooking' ); ?></option>
                        <option value="canceled" <?php selected( $status_filter, 'canceled' ); ?>><?php echo esc_html__( 'Canceled', 'pickybooking' ); ?></option>
                    </select>
                </label>
                <label>
                    <?php echo esc_html__( 'Layout', 'pickybooking' ); ?>
                    <select name="layout_id">
                        <option value="0"><?php echo esc_html__( 'All layouts', 'pickybooking' ); ?></option>
                        <?php foreach ( $layouts as $layout_id ) : ?>
                            <option value="<?php echo esc_attr( (string) $layout_id ); ?>" <?php selected( $layout_filter, (int) $layout_id ); ?>>
                                <?php echo esc_html( get_the_title( $layout_id ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php echo esc_html__( 'Table ID contains', 'pickybooking' ); ?>
                    <input type="text" name="table_id" value="<?php echo esc_attr( $table_filter ); ?>" placeholder="<?php echo esc_attr__( 'e.g. 12', 'pickybooking' ); ?>" />
                </label>
                <label>
                    <?php echo esc_html__( 'Sort cards by', 'pickybooking' ); ?>
                    <select name="sort_by">
                        <option value="time" <?php selected( $sort_by, 'time' ); ?>><?php echo esc_html__( 'Time', 'pickybooking' ); ?></option>
                        <option value="table" <?php selected( $sort_by, 'table' ); ?>><?php echo esc_html__( 'Table ID', 'pickybooking' ); ?></option>
                        <option value="name" <?php selected( $sort_by, 'name' ); ?>><?php echo esc_html__( 'Customer name', 'pickybooking' ); ?></option>
                        <option value="party" <?php selected( $sort_by, 'party' ); ?>><?php echo esc_html__( 'Party size', 'pickybooking' ); ?></option>
                        <option value="status" <?php selected( $sort_by, 'status' ); ?>><?php echo esc_html__( 'Status', 'pickybooking' ); ?></option>
                        <option value="new" <?php selected( $sort_by, 'new' ); ?>><?php echo esc_html__( 'New first', 'pickybooking' ); ?></option>
                    </select>
                </label>
                <label>
                    <?php echo esc_html__( 'Density', 'pickybooking' ); ?>
                    <select name="density">
                        <option value="cozy" <?php selected( $density, 'cozy' ); ?>><?php echo esc_html__( 'Cozy', 'pickybooking' ); ?></option>
                        <option value="compact" <?php selected( $density, 'compact' ); ?>><?php echo esc_html__( 'Compact', 'pickybooking' ); ?></option>
                    </select>
                </label>
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Apply', 'pickybooking' ); ?></button>
            </form>

            <div class="picky-cal-toolbar">
                <a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $prev_date ) ), $base_url ) ); ?>">&larr; <?php echo esc_html__( 'Previous', 'pickybooking' ); ?></a>
                <strong class="picky-cal-range">
                    <?php echo esc_html( $start_date->format( 'M j, Y' ) ); ?>
                    <?php if ( $days > 1 ) : ?>
                        <?php echo esc_html( ' - ' . $label_end->format( 'M j, Y' ) ); ?>
                    <?php endif; ?>
                </strong>
                <a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $next_date ) ), $base_url ) ); ?>"><?php echo esc_html__( 'Next', 'pickybooking' ); ?> &rarr;</a>
                <span class="picky-cal-spacer"></span>
                <nav class="picky-cal-view-links" aria-label="<?php echo esc_attr__( 'Calendar view', 'pickybooking' ); ?>">
                    <a class="picky-cal-view-link<?php echo $view === 'day' ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'view' => 'day', 'date' => $start_date->format( 'Y-m-d' ) ) ), $base_url ) ); ?>"><?php echo esc_html__( 'Day', 'pickybooking' ); ?></a>
                    <a class="picky-cal-view-link<?php echo $view === 'week' ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'view' => 'week', 'date' => $start_date->format( 'Y-m-d' ) ) ), $base_url ) ); ?>"><?php echo esc_html__( 'Week', 'pickybooking' ); ?></a>
                    <a class="picky-cal-view-link" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'date' => $today->format( 'Y-m-d' ) ) ), $base_url ) ); ?>"><?php echo esc_html__( 'Today', 'pickybooking' ); ?></a>
                </nav>
                <form method="get" class="picky-cal-jump">
                    <input type="hidden" name="page" value="picky_settings" />
                    <input type="hidden" name="picky-tab" value="8" />
                    <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
                    <input type="hidden" name="mode" value="<?php echo esc_attr( $calendar_mode ); ?>" />
                    <input type="hidden" name="saved_view" value="<?php echo esc_attr( $saved_view ); ?>" />
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>" />
                    <input type="hidden" name="layout_id" value="<?php echo esc_attr( (string) $layout_filter ); ?>" />
                    <input type="hidden" name="table_id" value="<?php echo esc_attr( $table_filter ); ?>" />
                    <input type="hidden" name="sort_by" value="<?php echo esc_attr( $sort_by ); ?>" />
                    <input type="hidden" name="density" value="<?php echo esc_attr( $density ); ?>" />
                    <label for="picky-cal-jump-date"><?php echo esc_html__( 'Jump to', 'pickybooking' ); ?></label>
                    <input id="picky-cal-jump-date" type="date" name="date" value="<?php echo esc_attr( $start_date->format( 'Y-m-d' ) ); ?>" />
                    <button type="submit" class="button"><?php echo esc_html__( 'Go', 'pickybooking' ); ?></button>
                </form>
            </div>

            <?php if ( $calendar_mode === 'lanes' ) : ?>
            <?php
            $lane_day_key = $start_date->format( 'Y-m-d' );
            $lane_items = isset( $by_day[ $lane_day_key ] ) ? $by_day[ $lane_day_key ] : array();
            $lane_tables = array();
            $lane_map = array();
            foreach ( $lane_items as $lane_item ) {
                $tkey = (string) ( $lane_item['id'] ?? '' );
                if ( $tkey === '' ) {
                    $tkey = '—';
                }
                $lane_tables[ $tkey ] = true;
                $ridx = isset( $lane_item['row'] ) ? (int) $lane_item['row'] : -1;
                if ( ! isset( $lane_map[ $tkey ] ) ) {
                    $lane_map[ $tkey ] = array();
                }
                if ( ! isset( $lane_map[ $tkey ][ $ridx ] ) ) {
                    $lane_map[ $tkey ][ $ridx ] = array();
                }
                $lane_map[ $tkey ][ $ridx ][] = $lane_item;
            }
            $lane_table_ids = array_keys( $lane_tables );
            usort(
                $lane_table_ids,
                static function ( $a, $b ) {
                    if ( is_numeric( $a ) && is_numeric( $b ) ) {
                        return intval( $a ) <=> intval( $b );
                    }
                    return strcasecmp( (string) $a, (string) $b );
                }
            );
            if ( empty( $lane_table_ids ) && $table_filter !== '' ) {
                $lane_table_ids[] = $table_filter;
            }
            ?>
            <div class="picky-cal-lanes-note"><?php echo esc_html__( 'Lane mode optimizes operational scanning: rows are tables, columns are 30-minute slots.', 'pickybooking' ); ?></div>
            <div class="picky-cal-grid-wrap picky-cal-lanes-wrap">
            <table class="picky-cal-grid picky-cal-lanes-grid" cellspacing="0">
                <thead>
                    <tr>
                        <th class="picky-cal-time-col"><?php echo esc_html__( 'Table', 'pickybooking' ); ?></th>
                        <?php for ( $ri = 0; $ri < $rows; $ri++ ) : ?>
                            <?php
                            $r = $row_start + $ri;
                            $slot_m = $r * $row_minutes;
                            ?>
                            <th><?php echo esc_html( sprintf( '%02d:%02d', floor( $slot_m / 60 ), $slot_m % 60 ) ); ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $lane_table_ids as $lane_table_id ) : ?>
                        <tr>
                            <td class="picky-cal-time-col"><?php echo esc_html( '#' . $lane_table_id ); ?></td>
                            <?php for ( $ri = 0; $ri < $rows; $ri++ ) : ?>
                                <?php
                                $r = $row_start + $ri;
                                $lane_slot_items = isset( $lane_map[ $lane_table_id ][ $r ] ) ? $lane_map[ $lane_table_id ][ $r ] : array();
                                $lane_count = count( $lane_slot_items );
                                $heat = 'picky-heat-0';
                                if ( $lane_count >= 5 ) {
                                    $heat = 'picky-heat-3';
                                } elseif ( $lane_count >= 3 ) {
                                    $heat = 'picky-heat-2';
                                } elseif ( $lane_count >= 1 ) {
                                    $heat = 'picky-heat-1';
                                }
                                ?>
                                <td class="picky-cal-lane-cell <?php echo esc_attr( $heat ); ?>">
                                    <?php if ( $lane_count > 0 ) : ?>
                                        <?php $lead = $lane_slot_items[0]; ?>
                                        <?php
                                        $lane_payment = sanitize_key( (string) ( $lead['payment'] ?? '' ) );
                                        $lane_icon    = $this->picky_calendar_card_icon_key( (string) ( $lead['status'] ?? '' ), $lane_payment );
                                        ?>
                                        <a
                                            class="picky-cal-lane-pill picky-cal-open-drawer"
                                            href="<?php echo esc_url( (string) $lead['edit_link'] ); ?>"
                                            data-link="<?php echo esc_attr( (string) $lead['edit_link'] ); ?>"
                                            data-title="<?php echo esc_attr( '#' . (string) $lead['id'] . ' - ' . (string) $lead['name'] ); ?>"
                                            data-time="<?php echo esc_attr( sprintf( '%02d:%02d-%02d:%02d', floor( (int) $lead['start_m'] / 60 ), (int) $lead['start_m'] % 60, floor( (int) $lead['end_m'] / 60 ), (int) $lead['end_m'] % 60 ) ); ?>"
                                            data-layout="<?php echo esc_attr( $this->picky_calendar_layout_display_name( (int) $lead['layout_id'] ) ); ?>"
                                            data-status="<?php echo esc_attr( (string) $lead['status'] ); ?>"
                                            data-people="<?php echo esc_attr( (string) max( 1, (int) $lead['people'] ) ); ?>"
                                            data-payment="<?php echo esc_attr( $lane_payment ); ?>"
                                            data-icon="<?php echo esc_attr( $lane_icon ); ?>"
                                        >
                                            <span><?php echo esc_html( (string) $lead['name'] ); ?></span>
                                            <?php if ( $lane_count > 1 ) : ?>
                                                <strong><?php echo esc_html( '+' . (string) ( $lane_count - 1 ) ); ?></strong>
                                            <?php endif; ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else : ?>
            <?php
            $board_sort = static function ( $a, $b ) use ( $sort_by ) {
                if ( $sort_by === 'table' ) {
                    return intval( $a['id'] ) <=> intval( $b['id'] );
                }
                if ( $sort_by === 'name' ) {
                    return strcasecmp( (string) $a['name'], (string) $b['name'] );
                }
                if ( $sort_by === 'party' ) {
                    return intval( $b['people'] ) <=> intval( $a['people'] );
                }
                if ( $sort_by === 'status' ) {
                    return strcmp( (string) $a['status'], (string) $b['status'] );
                }
                if ( $sort_by === 'new' ) {
                    if ( ! empty( $a['is_new'] ) && empty( $b['is_new'] ) ) {
                        return -1;
                    }
                    if ( empty( $a['is_new'] ) && ! empty( $b['is_new'] ) ) {
                        return 1;
                    }
                }
                return intval( $a['start_m'] ) <=> intval( $b['start_m'] );
            };
            ?>
            <div class="picky-cal-board-wrap">
                <div class="picky-cal-board picky-cal-board--<?php echo esc_attr( $view ); ?> picky-cal-board--<?php echo esc_attr( $density ); ?>">
                    <?php for ( $d = 0; $d < $days; $d++ ) : ?>
                        <?php
                        $day_obj = $start_date->modify( '+' . $d . ' days' );
                        $day_key = $day_obj->format( 'Y-m-d' );
                        $day_n = (int) $day_obj->format( 'N' );
                        $day_items = isset( $by_day[ $day_key ] ) ? $by_day[ $day_key ] : array();
                        if ( ! empty( $day_items ) ) {
                            usort( $day_items, $board_sort );
                        }
                        $day_total = count( $day_items );
                        $day_classes = array( 'picky-cal-board-day' );
                        if ( $day_key === $now_day_key ) {
                            $day_classes[] = 'picky-cal-board-day--today';
                        }
                        if ( $day_n >= 6 ) {
                            $day_classes[] = 'picky-cal-board-day--weekend';
                        }
                        if ( $day_total === 0 ) {
                            $day_classes[] = 'picky-cal-board-day--empty';
                        }
                        $max_visible = ( $density === 'compact' ) ? 8 : 12;
                        $visible_items = ( $day_total > $max_visible ) ? array_slice( $day_items, 0, $max_visible ) : $day_items;
                        $hidden_items = ( $day_total > $max_visible ) ? array_slice( $day_items, $max_visible ) : array();
                        ?>
                        <section class="<?php echo esc_attr( implode( ' ', $day_classes ) ); ?>">
                            <header class="picky-cal-board-day__head">
                                <div class="picky-cal-board-day__when">
                                    <div class="picky-cal-board-day__date">
                                        <span class="picky-cal-board-day__num"><?php echo esc_html( $day_obj->format( 'j' ) ); ?></span>
                                        <span class="picky-cal-board-day__month"><?php echo esc_html( $day_obj->format( 'M' ) ); ?></span>
                                    </div>
                                    <span class="picky-cal-board-day__weekday"><?php echo esc_html( $day_obj->format( 'D' ) ); ?></span>
                                </div>
                                <?php if ( $day_total > 0 ) : ?>
                                    <span class="picky-cal-board-day__count">
                                        <strong class="picky-cal-board-day__count-num"><?php echo esc_html( (string) $day_total ); ?></strong>
                                        <span class="picky-cal-board-day__count-label"><?php echo esc_html( _n( 'booking', 'bookings', $day_total, 'pickybooking' ) ); ?></span>
                                    </span>
                                <?php endif; ?>
                            </header>
                            <div class="picky-cal-board-day__list">
                                <?php if ( $day_total === 0 ) : ?>
                                    <p class="picky-cal-board-day__empty"><?php echo esc_html__( 'No bookings', 'pickybooking' ); ?></p>
                                <?php else : ?>
                                    <?php foreach ( $visible_items as $item ) : ?>
                                        <?php
                                        $layout_label = $this->picky_calendar_layout_display_name( (int) $item['layout_id'] );
                                        $this->picky_render_calendar_item_card( $item, $layout_label );
                                        ?>
                                    <?php endforeach; ?>
                                    <?php if ( ! empty( $hidden_items ) ) : ?>
                                        <details class="picky-cal-more">
                                            <summary><?php
											/* translators: %d: number of hidden bookings */
											echo esc_html( sprintf( __( '+%d more', 'pickybooking' ), count( $hidden_items ) ) );
											?></summary>
                                            <div class="picky-cal-more-list">
                                                <?php foreach ( $hidden_items as $item ) : ?>
                                                    <?php
                                                    $layout_label = $this->picky_calendar_layout_display_name( (int) $item['layout_id'] );
                                                    $this->picky_render_calendar_item_card( $item, $layout_label );
                                                    ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ( $count_total === 0 ) : ?>
                <div class="picky-cal-empty-state">
                    <strong><?php echo esc_html__( 'No bookings found for this range.', 'pickybooking' ); ?></strong>
                    <span><?php echo esc_html__( 'Try widening the date range or resetting filters.', 'pickybooking' ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <aside class="picky-cal-drawer" id="picky-cal-drawer" aria-hidden="true">
            <div class="picky-cal-drawer-backdrop picky-cal-drawer-close"></div>
            <div class="picky-cal-drawer-panel" role="dialog" aria-modal="false" aria-label="<?php echo esc_attr__( 'Booking details', 'pickybooking' ); ?>">
                <button type="button" class="picky-cal-drawer-x picky-cal-drawer-close" aria-label="<?php echo esc_attr__( 'Close details', 'pickybooking' ); ?>">&times;</button>
                <div class="picky-cal-drawer-hero picky-cal-item-preview--reserved" id="picky-cal-drawer-hero">
                    <span class="picky-cal-drawer-icon" id="picky-cal-drawer-icon" aria-hidden="true"></span>
                    <h3 id="picky-cal-drawer-title"><?php echo esc_html__( 'Booking', 'pickybooking' ); ?></h3>
                </div>
                <div class="picky-cal-drawer-body">
                    <div class="picky-cal-drawer-fields">
                        <div><strong><?php echo esc_html__( 'Time', 'pickybooking' ); ?></strong><span id="picky-cal-drawer-time">-</span></div>
                        <div><strong><?php echo esc_html__( 'Status', 'pickybooking' ); ?></strong><span id="picky-cal-drawer-status">-</span></div>
                        <div><strong><?php echo esc_html__( 'Layout', 'pickybooking' ); ?></strong><span id="picky-cal-drawer-layout">-</span></div>
                        <div><strong><?php echo esc_html__( 'People', 'pickybooking' ); ?></strong><span id="picky-cal-drawer-people">-</span></div>
                    </div>
                    <a href="#" class="button button-primary" id="picky-cal-drawer-open"><?php echo esc_html__( 'Open booking record', 'pickybooking' ); ?></a>
                </div>
            </div>
        </aside>
        <?php
        $drawer_icon_keys = array( 'paid', 'canceled', 'unpaid', 'pending', 'request', 'draft', 'reserved' );
        $drawer_icons     = array();
        foreach ( $drawer_icon_keys as $drawer_icon_key ) {
            $drawer_icons[ $drawer_icon_key ] = $this->picky_calendar_card_icon_svg( $drawer_icon_key );
        }
        $picky_cal_labels = array(
            'reserved' => __( 'Reserved', 'pickybooking' ),
            'requests' => __( 'Request', 'pickybooking' ),
            'canceled' => __( 'Canceled', 'pickybooking' ),
            'draft'    => __( 'Draft', 'pickybooking' ),
        );
        $picky_cal_js  = '(function(){';
        $picky_cal_js .= 'var drawer=document.getElementById("picky-cal-drawer");if(!drawer){return;}';
        $picky_cal_js .= 'var hero=document.getElementById("picky-cal-drawer-hero");';
        $picky_cal_js .= 'var iconEl=document.getElementById("picky-cal-drawer-icon");';
        $picky_cal_js .= 'var title=document.getElementById("picky-cal-drawer-title");';
        $picky_cal_js .= 'var t=document.getElementById("picky-cal-drawer-time");';
        $picky_cal_js .= 'var s=document.getElementById("picky-cal-drawer-status");';
        $picky_cal_js .= 'var l=document.getElementById("picky-cal-drawer-layout");';
        $picky_cal_js .= 'var p=document.getElementById("picky-cal-drawer-people");';
        $picky_cal_js .= 'var openLink=document.getElementById("picky-cal-drawer-open");';
        $picky_cal_js .= 'var icons=' . wp_json_encode( $drawer_icons ) . ';';
        $picky_cal_js .= 'var statusLabels=' . wp_json_encode( $picky_cal_labels ) . ';';
        $picky_cal_js .= 'var fallbackTitle=' . wp_json_encode( __( 'Booking', 'pickybooking' ) ) . ';';
        $picky_cal_js .= 'function applyDrawerIcon(key){var iconKey=(key&&icons[key])?key:"reserved";if(hero){hero.className="picky-cal-drawer-hero picky-cal-item-preview--"+iconKey;}if(iconEl){iconEl.innerHTML=icons[iconKey]||icons.reserved||"";}}';
        $picky_cal_js .= 'applyDrawerIcon("reserved");';
        $picky_cal_js .= 'document.querySelectorAll(".picky-cal-open-drawer").forEach(function(el){el.addEventListener("click",function(ev){ev.preventDefault();title.textContent=this.dataset.title||fallbackTitle;t.textContent=this.dataset.time||"-";var st=this.dataset.status||"-";s.textContent=statusLabels[st]||st;l.textContent=this.dataset.layout||"-";p.textContent=this.dataset.people||"-";openLink.href=this.dataset.link||"#";applyDrawerIcon(this.dataset.icon||"");drawer.classList.add("is-open");drawer.setAttribute("aria-hidden","false");});});';
        $picky_cal_js .= 'drawer.querySelectorAll(".picky-cal-drawer-close").forEach(function(el){el.addEventListener("click",function(){drawer.classList.remove("is-open");drawer.setAttribute("aria-hidden","true");});});';
        $picky_cal_js .= '})();';
        wp_register_script( 'picky-cal-drawer', false, array(), $this->version, true );
        wp_enqueue_script( 'picky-cal-drawer' );
        wp_add_inline_script( 'picky-cal-drawer', $picky_cal_js );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public function render_documentation( $embed = false ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $item_label  = $this->picky_item_label();
        $items_label = $this->picky_items_label();
        $settings    = Info::get_settings();
        $defaults    = Info::$DEFAULT_OPTIONS;

        $group_titles = array(
            'booking'         => __( 'Booking flow, labels, and form fields', 'pickybooking' ),
            'layout'          => __( 'Layout and canvas defaults', 'pickybooking' ),
            'time_engine'     => __( 'Date horizon and booking duration rules', 'pickybooking' ),
            'weekly_hours'    => __( 'Weekly hours (summary)', 'pickybooking' ),
            'communications'  => __( 'Emails and thank-you page text', 'pickybooking' ),
            'payments'        => __( 'Payments and deposits', 'pickybooking' ),
            'sms'             => __( 'SMS notifications (staff)', 'pickybooking' ),
        );

        $flow_steps = array(
            __( 'Create at least one layout in “Layouts” and place your bookable elements on the map. Keep non-bookable shapes (walls/text/decor) as visual helpers only.', 'pickybooking' ),
            __( 'Open “Settings” (Main tab) and set labels, booking mode, wizard order, and what the guest sees (email, party size, map badges, mobile behavior).', 'pickybooking' ),
            __( 'Set “Date horizon and booking duration rules”: how far ahead guests can book, default reservation length, and buffer time between bookings (turnover).', 'pickybooking' ),
            __( 'Set “Weekly hours” using the table below: enable each weekday, choose window times, and use split hours when you need a lunch break or two shifts.', 'pickybooking' ),
            __( 'Configure “Emails and thank-you page text” (where admin copies go, optional guest confirmation, success message).', 'pickybooking' ),
            __( 'If needed, enable optional SMS to staff, payments/deposits, and test those integrations.', 'pickybooking' ),
            __( 'Colors and visual styling: adjust them in the plugin “Settings” Appearance tab (not duplicated here on purpose).', 'pickybooking' ),
            __( 'Place shortcode `[pickybooking id="LAYOUT_ID"]` on the target page and test the full journey as a customer (desktop and mobile).', 'pickybooking' ),
        );

        $business_examples = array(
            array(
                'title' => __( 'Restaurant / Cafe (table reservations)', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Table / Tables', 'pickybooking' ),
                    __( 'Booking mode: Exclusive', 'pickybooking' ),
                    __( 'Wizard: Smart (date/time first) for busy operations', 'pickybooking' ),
                    __( 'Show center badge and max persons so guests pick suitable tables', 'pickybooking' ),
                    __( 'Typical duration: 1.5–2.5 hours, with pre-booking buffer for turnover', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Coworking / Hot desk space', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Desk / Desks or Seat / Seats', 'pickybooking' ),
                    __( 'Booking mode: Capacity', 'pickybooking' ),
                    __( 'Enable party size to subtract capacity correctly', 'pickybooking' ),
                    __( 'Use map badges to show capacity at a glance', 'pickybooking' ),
                    __( 'Longer schedule windows and larger days-ahead horizon are common', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Sports venue (court/field booking)', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Court / Courts or Field / Fields', 'pickybooking' ),
                    __( 'Booking mode: Exclusive (one booking blocks the full resource)', 'pickybooking' ),
                    __( 'Show end-time field to support variable session length', 'pickybooking' ),
                    __( 'Keep mobile mode as map or dropdown based on number of courts', 'pickybooking' ),
                    __( 'Deposits are recommended for no-show reduction', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Clinic / Beauty / Service rooms', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Room / Rooms or Chair / Chairs', 'pickybooking' ),
                    __( 'Booking mode: Exclusive', 'pickybooking' ),
                    __( 'Use strict schedule windows with optional split shifts', 'pickybooking' ),
                    __( 'Consent checkbox and clear policy text are usually required', 'pickybooking' ),
                    __( 'Enable customer email confirmations for appointment clarity', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Event venue / Mixed zones', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Zone / Zones, Booth / Booths, etc.', 'pickybooking' ),
                    __( 'Use visual non-bookable shapes/text to explain map areas', 'pickybooking' ),
                    __( 'Pick wizard mode based on sales process: Classic for map-first, Smart for date-first', 'pickybooking' ),
                    __( 'Transparent canvas mode helps blend maps into branded landing pages', 'pickybooking' ),
                    __( 'Use Import/Export to clone layouts across similar event pages', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Day tours (boats, hop-on buses, group departures)', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Tour / Tours, Bus / Buses, or Departure / Departures', 'pickybooking' ),
                    __( 'Booking mode: Capacity — the same vehicle/tour run can be shared until “max persons” is reached', 'pickybooking' ),
                    __( 'Enable party size so each booking reserves the correct number of seats', 'pickybooking' ),
                    __( 'Wizard: Smart (date/time first) so guests only see departures that still have seats', 'pickybooking' ),
                    __( 'Set default duration to the tour leg length; add buffer for boarding/turnaround between departures', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Training / classes (seats in a room)', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Class / Classes, Room / Rooms, or Seat / Seats', 'pickybooking' ),
                    __( 'Booking mode: Capacity when multiple students share one scheduled session', 'pickybooking' ),
                    __( 'Enable party size if one booking can cover several attendees (e.g. a company group)', 'pickybooking' ),
                    __( 'Use weekly hours for session windows; optional split shifts if you run morning and evening classes', 'pickybooking' ),
                    __( 'Use email confirmations and consent text if you process personal or training data', 'pickybooking' ),
                ),
            ),
            array(
                'title' => __( 'Hotel / B&B (overnight room stays)', 'pickybooking' ),
                'items' => array(
                    __( 'Item naming: Room / Rooms or Unit / Units', 'pickybooking' ),
                    __( 'Booking mode: Exclusive — one stay blocks the whole room for that time range', 'pickybooking' ),
                    __( 'Set a longer default booking length (e.g. overnight or multi-day) and use “end time” if guests choose checkout time', 'pickybooking' ),
                    __( 'Larger “days ahead” is typical for travel planning', 'pickybooking' ),
                    __( 'Customer email on + clear confirmation text; optional deposit in Payments if you require prepayment', 'pickybooking' ),
                ),
            ),
        );
        ?>
        <?php
        $picky_html_docs_url = plugins_url( 'docs/index.html', dirname( __DIR__ ) . '/' . Info::MAIN_FILE );
        ?>
        <?php if ( $embed ) : ?>
        <div class="picky-root picky-docs-wrap picky-docs-wrap--embedded" data-picky-context="docs-embedded">
            <div class="picky-cal-head">
                <div class="picky-cal-head__main">
                    <div class="picky-cal-head__title-row">
                        <h1 class="picky-app-tab-title"><?php esc_html_e( 'PickyBooking Documentation', 'pickybooking' ); ?></h1>
                    </div>
                </div>
            </div>
        <?php else : ?>
        <div class="wrap picky-root picky-wrap picky-docs-wrap" data-picky-context="docs">
            <h1><?php esc_html_e( 'PickyBooking Documentation', 'pickybooking' ); ?></h1>
        <?php endif; ?>
            <div class="picky-docs-card picky-docs-card--guide">
                <h2><?php esc_html_e( 'Full HTML documentation (online)', 'pickybooking' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Complete product guide: setup stages, how-tos (layout, services, bookings, emails/SMS, CRM, payments, avatars, import/export, AI), industry recipes, troubleshooting, and a full features catalog.', 'pickybooking' ); ?>
                </p>
                <p class="picky-docs-actions">
                    <a class="button button-primary" href="<?php echo esc_url( $picky_html_docs_url ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Open HTML documentation', 'pickybooking' ); ?>
                    </a>
                    <code><?php echo esc_html( '…/wp-content/plugins/pickybooking/docs/' ); ?></code>
                </p>
                <p class="description picky-docs-card__note">
                    <?php
                    $picky_howto_base = trailingslashit( dirname( $picky_html_docs_url ) );
                    echo esc_html__( 'Guides:', 'pickybooking' ) . ' ';
                    $picky_howto_links = array(
                        'how-to-layout.html'         => __( 'Layout', 'pickybooking' ),
                        'how-to-services.html'       => __( 'Services', 'pickybooking' ),
                        'how-to-bookings.html'       => __( 'Bookings', 'pickybooking' ),
                        'how-to-notifications.html'  => __( 'Emails & SMS', 'pickybooking' ),
                        'how-to-crm.html'            => __( 'CRM', 'pickybooking' ),
                        'how-to-payments.html'       => __( 'Payments', 'pickybooking' ),
                        'how-to-avatars.html'        => __( 'Avatars', 'pickybooking' ),
                        'how-to-import-export.html'  => __( 'Import/Export', 'pickybooking' ),
                        'how-to-ai.html'             => __( 'AI', 'pickybooking' ),
                        'industries.html'            => __( 'Industries', 'pickybooking' ),
                        'troubleshooting.html'       => __( 'Troubleshooting', 'pickybooking' ),
                    );
                    $picky_howto_parts = array();
                    foreach ( $picky_howto_links as $picky_howto_file => $picky_howto_label ) {
                        $picky_howto_parts[] = '<a href="' . esc_url( $picky_howto_base . $picky_howto_file ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $picky_howto_label ) . '</a>';
                    }
                    echo wp_kses_post( implode( ' · ', $picky_howto_parts ) );
                    ?>
                </p>
            </div>
            <p class="description picky-settings-section__desc"><?php esc_html_e( 'This page is an in-plugin guide for administrators. It explains the full setup flow, all settings, and ready-to-use configuration patterns.', 'pickybooking' ); ?></p>

            <div class="picky-docs-card">
                <h2><?php esc_html_e( 'Implementation flow (end-to-end)', 'pickybooking' ); ?></h2>
                <ol class="picky-docs-list">
                    <?php foreach ( $flow_steps as $step ) : ?>
                        <li><?php echo esc_html( $step ); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div class="picky-docs-card">
                <h2><?php esc_html_e( 'Business setup examples (8 scenarios)', 'pickybooking' ); ?></h2>
                <div class="picky-docs-grid">
                    <?php foreach ( $business_examples as $example ) : ?>
                        <section class="picky-docs-grid-card">
                            <h3><?php echo esc_html( (string) $example['title'] ); ?></h3>
                            <ul class="picky-docs-list">
                                <?php foreach ( (array) $example['items'] as $point ) : ?>
                                    <li><?php echo esc_html( (string) $point ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="picky-docs-card">
                <h2><?php esc_html_e( 'All settings reference', 'pickybooking' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Most options are listed as values only. A small set of “critical” options includes a short note. Color pickers are not duplicated here—use the Settings screen.', 'pickybooking' ); ?></p>
                <?php
                $options_by_group = array(
                    'booking'        => array(),
                    'layout'         => array(),
                    'time_engine'    => array(),
                    'communications' => array(),
                    'payments'       => array(),
                    'sms'            => array(),
                );
                foreach ( $defaults as $slug => $default_value ) {
                    if ( $this->picky_doc_is_hidden_from_reference( (string) $slug ) ) {
                        continue;
                    }
                    $group = $this->picky_doc_option_group( (string) $slug );
                    if ( ! isset( $options_by_group[ $group ] ) ) {
                        $options_by_group[ $group ] = array();
                    }
                    $options_by_group[ $group ][] = array(
                        'slug'    => (string) $slug,
                        'default' => $default_value,
                    );
                }
                foreach ( $options_by_group as $g => $list_rows ) {
                    $options_by_group[ $g ] = $this->picky_doc_sort_option_rows( $list_rows );
                }
                $section_order = array( 'booking', 'layout', 'time_engine', 'weekly_hours', 'communications', 'payments', 'sms' );
                ?>
                <?php foreach ( $section_order as $group ) : ?>
                    <?php if ( $group === 'weekly_hours' ) : ?>
                        <h3 class="picky-docs-subtitle"><?php echo esc_html( $group_titles['weekly_hours'] ); ?></h3>
                        <?php $this->picky_doc_weekly_hours_table( $settings, $defaults ); ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    if ( ! isset( $options_by_group[ $group ] ) || empty( $options_by_group[ $group ] ) ) {
                        continue;
                    }
                    $rows = $options_by_group[ $group ];
                    ?>
                    <h3 class="picky-docs-subtitle"><?php echo esc_html( isset( $group_titles[ $group ] ) ? $group_titles[ $group ] : ucfirst( (string) $group ) ); ?></h3>
                    <div class="picky-docs-options">
                        <?php foreach ( $rows as $row ) : ?>
                            <?php
                            $slug          = (string) $row['slug'];
                            $default_value = array_key_exists( $slug, $defaults ) ? $defaults[ $slug ] : '';
                            $current_value = array_key_exists( $slug, $settings ) ? $settings[ $slug ] : $default_value;
                            $title         = $this->picky_doc_option_title( $slug );
                            $note          = $this->picky_doc_is_crucial( $slug )
                                ? $this->picky_doc_crucial_note( $slug, $item_label, $items_label )
                                : '';
                            ?>
                            <article class="picky-docs-option">
                                <h4><?php echo esc_html( $title ); ?></h4>
                                <code><?php echo esc_html( $slug ); ?></code>
                                <?php if ( $note !== '' ) : ?>
                                    <p class="picky-docs-note"><?php echo esc_html( $note ); ?></p>
                                <?php endif; ?>
                                <p class="picky-docs-meta">
                                    <strong><?php esc_html_e( 'Current', 'pickybooking' ); ?>:</strong>
                                    <?php echo esc_html( $this->picky_doc_format_value( $current_value ) ); ?>
                                    &nbsp;|&nbsp;
                                    <strong><?php esc_html_e( 'Default', 'pickybooking' ); ?>:</strong>
                                    <?php echo esc_html( $this->picky_doc_format_value( $default_value ) ); ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Layouts gallery (user layouts first, then bundled presets).
     *
     * @param bool $embed When true, omit outer wrap chrome.
     * @return void
     */
    public function render_layout_library( $embed = false ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $picky_user_layouts   = \Picky\Layout_Library::get_user_items();
        $picky_preset_layouts = \Picky\Layout_Library::get_preset_items();
        require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/layout-library.php';
    }

    /**
     * admin-post: import a library item into a new picky_layout and open the builder.
     *
     * @return void
     */
    public function library_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }
        if ( ! isset( $_POST['picky_library_import_nonce'] ) || ! is_scalar( $_POST['picky_library_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['picky_library_import_nonce'] ) ), 'picky_library_import' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }

        $item_id = isset( $_POST['picky_library_item'] ) && is_scalar( $_POST['picky_library_item'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['picky_library_item'] ) )
            : '';
        $result  = \Picky\Layout_Library::import_item( $item_id );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        $this->picky_set_layout_notice( 'imported' );
        wp_safe_redirect( $this->picky_layout_dashboard_url( absint( $result ) ) );
        exit;
    }

    /**
     * Permanently delete a picky_layout and return to the Layouts gallery.
     * Hooked: admin_post_picky_delete_layout
     *
     * @return void
     */
    public function delete_layout() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $layout_id = absint( picky_request_query_value( 'layout_id' ) );
        $delete_nonce = picky_request_query_value( '_wpnonce' );
        if ( ! wp_verify_nonce( $delete_nonce, 'picky_delete_layout_' . $layout_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            wp_die( esc_html__( 'Invalid layout.', 'pickybooking' ) );
        }

        if ( ! current_user_can( 'delete_post', $layout_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $sample_key = (string) get_post_meta( $layout_id, '_picky_sample_key', true );
        if ( $sample_key === 'cinema' && (int) get_option( 'picky_sample_cinema_layout_id', 0 ) === $layout_id ) {
            delete_option( 'picky_sample_cinema_layout_id' );
            delete_option( 'picky_sample_cinema_layout_version' );
        }

        wp_delete_post( $layout_id, true );

        $this->picky_set_layout_notice( 'deleted' );
        wp_safe_redirect( $this->picky_layout_dashboard_url( 0 ) );
        exit;
    }

    /**
     * Duplicate a picky_layout (deep copy of layout meta) and open the new copy in the builder.
     * Hooked: admin_post_picky_duplicate_layout
     *
     * @return void
     */
    public function duplicate_layout() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $layout_id = absint( picky_request_query_value( 'layout_id' ) );
        $duplicate_nonce = picky_request_query_value( '_wpnonce' );
        if ( ! wp_verify_nonce( $duplicate_nonce, 'picky_duplicate_layout_' . $layout_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            wp_die( esc_html__( 'Invalid layout.', 'pickybooking' ) );
        }

        if ( ! current_user_can( 'edit_post', $layout_id ) || ! current_user_can( 'publish_posts' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $new_id = \Picky\Layout_Library::clone_layout( $layout_id );
        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html( $new_id->get_error_message() ) );
        }

        $new_id = absint( $new_id );
        // User-initiated duplicate — not a library preset import.
        delete_post_meta( $new_id, '_picky_from_library' );

        $this->picky_set_layout_notice( 'duplicated' );
        wp_safe_redirect( $this->picky_layout_dashboard_url( $new_id ) );
        exit;
    }

    /**
     * Nonced admin-post URL to permanently delete a layout.
     *
     * @param int $layout_id Layout post ID.
     * @return string
     */
    private function picky_layout_delete_url( $layout_id ) {
        $layout_id = absint( $layout_id );
        return (string) wp_nonce_url(
            add_query_arg(
                array(
                    'action'    => 'picky_delete_layout',
                    'layout_id' => $layout_id,
                ),
                admin_url( 'admin-post.php' )
            ),
            'picky_delete_layout_' . $layout_id
        );
    }

    /**
     * Nonced admin-post URL to duplicate a layout.
     *
     * @param int $layout_id Layout post ID.
     * @return string
     */
    private function picky_layout_duplicate_url( $layout_id ) {
        $layout_id = absint( $layout_id );
        return (string) wp_nonce_url(
            add_query_arg(
                array(
                    'action'    => 'picky_duplicate_layout',
                    'layout_id' => $layout_id,
                ),
                admin_url( 'admin-post.php' )
            ),
            'picky_duplicate_layout_' . $layout_id
        );
    }

    public function render_import_layout( $embed = false ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only import form GET
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        $error = sanitize_text_field( picky_request_query_value( 'picky_import_error' ) );
        ?>
        <?php if ( $embed ) : ?>
        <div class="picky-root picky-import-export picky-import-export--embedded" data-picky-context="import-export-embedded">
            <div class="picky-cal-head">
                <div class="picky-cal-head__main">
                    <div class="picky-cal-head__title-row">
                        <h1 class="picky-app-tab-title"><?php esc_html_e( 'Import / Export', 'pickybooking' ); ?></h1>
                    </div>
                    <p class="picky-cal-subtitle picky-app-tab-subtitle"><?php esc_html_e( 'Copy layouts between sites or from backups using JSON export and import.', 'pickybooking' ); ?></p>
                </div>
            </div>
        <?php else : ?>
        <div class="wrap picky-root picky-wrap" data-picky-context="import-export">
            <h1><?php esc_html_e( 'Import / Export', 'pickybooking' ); ?></h1>
            <p class="description picky-app-tab-subtitle"><?php esc_html_e( 'Copy layouts between sites or from backups using JSON export and import.', 'pickybooking' ); ?></p>
        <?php endif; ?>

            <?php if ( $error !== '' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <p class="picky-settings-section__desc">
                <strong><?php esc_html_e( 'Export', 'pickybooking' ); ?>:</strong>
                <?php esc_html_e( 'Open a layout in the visual editor and click “Export” in the top bar, or use “Export” in the Layouts list.', 'pickybooking' ); ?>
            </p>
            <p class="picky-settings-section__desc">
                <strong><?php esc_html_e( 'Import', 'pickybooking' ); ?>:</strong>
                <?php esc_html_e( 'Paste a PickyBooking layout JSON or upload a .json file. Includes elements, canvas size, background images (embedded when possible), fit mode, visibility flags, and the services library when present.', 'pickybooking' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="picky_import_layout">
                <?php wp_nonce_field( 'picky_import_layout', 'picky_import_layout_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="picky_import_title"><?php esc_html_e( 'Layout title', 'pickybooking' ); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="picky_import_title" name="picky_import_title" placeholder="<?php echo esc_attr__( 'Imported Layout', 'pickybooking' ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave empty to use the title from JSON, when present.', 'pickybooking' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picky_import_file"><?php esc_html_e( 'JSON file', 'pickybooking' ); ?></label></th>
                        <td><input type="file" id="picky_import_file" name="picky_import_file" accept="application/json,.json"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="picky_import_json"><?php esc_html_e( 'JSON content', 'pickybooking' ); ?></label></th>
                        <td>
                            <textarea id="picky_import_json" name="picky_import_json" rows="18" class="large-text code" placeholder='{"title":"Cinema","canvas_width":1200,"canvas_height":900,"elements":[...]}'></textarea>
                            <p class="description"><?php esc_html_e( 'If both file and textarea are provided, the uploaded file is used.', 'pickybooking' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Import Layout', 'pickybooking' ) ); ?>
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Nonced URL that creates a layout. Creation itself is checked in redirect_new_layout().
     *
     * @return string
     */
    public function picky_new_layout_url() {
        return wp_nonce_url(
            admin_url( 'post-new.php?post_type=picky_layout' ),
            'picky_new_layout'
        );
    }

    /**
     * Copy scalar request values into a sanitized array. Used before WP_List_Table reads the query.
     *
     * @param mixed $source $_GET or $_REQUEST.
     * @return array<string, mixed>
     */
    private function picky_sanitize_input_array( $source ) {
        $clean = array();
        if ( ! is_array( $source ) ) {
            return $clean;
        }
        foreach ( $source as $key => $value ) {
            $safe_key = sanitize_key( (string) $key );
            if ( $safe_key === '' ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $clean[ $safe_key ] = map_deep( wp_unslash( $value ), 'sanitize_text_field' );
            } elseif ( is_scalar( $value ) ) {
                $clean[ $safe_key ] = sanitize_text_field( wp_unslash( (string) $value ) );
            }
        }
        return $clean;
    }

    /**
     * Remember a one-time layout admin notice for the current user.
     *
     * @param string $flag saved|imported|duplicated|deleted.
     * @return void
     */
    public function picky_set_layout_notice( $flag ) {
        $flag = sanitize_key( (string) $flag );
        if ( ! in_array( $flag, array( 'saved', 'imported', 'duplicated', 'deleted' ), true ) ) {
            return;
        }
        set_transient( 'picky_layout_notice_' . get_current_user_id(), $flag, MINUTE_IN_SECONDS );
    }

    /**
     * One-time layout notice for this request. Safe to call from more than one template.
     *
     * @return string
     */
    public function picky_layout_notice() {
        static $loaded = false;
        static $notice = '';
        if ( $loaded ) {
            return $notice;
        }
        $loaded = true;
        $key    = 'picky_layout_notice_' . get_current_user_id();
        $flag   = get_transient( $key );
        if ( is_string( $flag ) && $flag !== '' ) {
            delete_transient( $key );
            $notice = sanitize_key( $flag );
        }
        return $notice;
    }

    /**
     * Keep picky_layout edit links inside the Layouts tab.
     *
     * @param string $url     URL WP built for the post.
     * @param int    $post_id Post ID being linked.
     * @param string $context Context ('display'|'raw').
     * @return string
     */
    public function picky_layout_filter_edit_post_link( $url, $post_id, $context = 'display' ) {
        unset( $context );
        if ( ! $post_id || get_post_type( (int) $post_id ) !== 'picky_layout' ) {
            return $url;
        }
        return $this->picky_layout_dashboard_url( (int) $post_id );
    }

    /** Intercept native WP "Add New" for picky_layout — create post, redirect to builder. */
    public function redirect_new_layout() {
        global $typenow;
        if ( ! is_string( $typenow ) || $typenow !== 'picky_layout' ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! is_scalar( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'picky_new_layout' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }
        $post_id = wp_insert_post( array(
            'post_title'  => __( 'New Layout', 'pickybooking' ),
            'post_type'   => 'picky_layout',
            'post_status' => 'publish',
        ) );
        if ( $post_id && ! is_wp_error( $post_id ) ) {
            wp_safe_redirect( $this->picky_layout_dashboard_url( (int) $post_id ) );
            exit;
        }
    }

    /** Intercept native WP "Edit" for picky_layout — redirect to builder. */
    public function redirect_edit_layout() {
        $action  = sanitize_key( picky_request_query_value( 'action' ) );
        $post_id = absint( picky_request_query_value( 'post' ) );
        if ( $action !== 'edit' || get_post_type( $post_id ) !== 'picky_layout' ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! is_scalar( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'picky_edit_picky_layout_' . $post_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }
        wp_safe_redirect( $this->picky_layout_dashboard_url( (int) $post_id ) );
        exit;
    }

    /** Custom columns for picky_layout list. */
    public function picky_layout_columns( $columns ) {
        return array(
            'cb'        => '<input type="checkbox" />',
            'title'     => __( 'Layout Name', 'pickybooking' ),
            'shortcode' => __( 'Shortcode', 'pickybooking' ),
            'export'    => __( 'Export', 'pickybooking' ),
            'edit_link' => __( 'Edit Layout', 'pickybooking' ),
        );
    }

    public function picky_layout_column_content( $column, $post_id ) {
        if ( $column === 'shortcode' ) {
            echo '<code>[pickybooking id="' . esc_html( (string)$post_id ) . '"]</code>';
        }
        if ( $column === 'edit_link' ) {
            $url = $this->picky_layout_dashboard_url( (int) $post_id );
            echo '<a href="' . esc_url( (string) $url ) . '" class="button button-primary">' . esc_html__( 'Edit Layout', 'pickybooking' ) . '</a>';
        }
        if ( $column === 'export' ) {
            $export_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'    => 'picky_export_layout',
                        'layout_id' => (int) $post_id,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'picky_export_layout_' . (int) $post_id
            );
            echo '<a href="' . esc_url( (string) $export_url ) . '">' . esc_html__( 'Download JSON', 'pickybooking' ) . '</a>';
        }
    }

    /** Override row actions for picky_layout to point Edit to builder. */
    public function picky_layout_row_actions( $actions, $post ) {
        if ( $post->post_type !== 'picky_layout' ) {
            return $actions;
        }
        $url = $this->picky_layout_dashboard_url( (int) $post->ID );
        $actions['edit'] = '<a href="' . esc_url( (string) $url ) . '">' . esc_html__( 'Edit Layout', 'pickybooking' ) . '</a>';
        if ( current_user_can( 'edit_post', (int) $post->ID ) && current_user_can( 'publish_posts' ) ) {
            $actions['picky_duplicate'] = '<a href="' . esc_url( $this->picky_layout_duplicate_url( (int) $post->ID ) ) . '">' . esc_html__( 'Duplicate', 'pickybooking' ) . '</a>';
        }
        $export_url      = wp_nonce_url(
            add_query_arg(
                array(
                    'action'    => 'picky_export_layout',
                    'layout_id' => (int) $post->ID,
                ),
                admin_url( 'admin-post.php' )
            ),
            'picky_export_layout_' . (int) $post->ID
        );
        $actions['picky_export'] = '<a href="' . esc_url( (string) $export_url ) . '">' . esc_html__( 'Export JSON', 'pickybooking' ) . '</a>';
        unset( $actions['trash'], $actions['untrash'], $actions['delete'] );
        if ( current_user_can( 'delete_post', (int) $post->ID ) ) {
            $confirm = esc_js( __( 'Delete this layout permanently? This cannot be undone.', 'pickybooking' ) );
            $actions['delete'] = '<a href="' . esc_url( $this->picky_layout_delete_url( (int) $post->ID ) ) . '" class="submitdelete" onclick="return confirm(\'' . $confirm . '\');">' . esc_html__( 'Delete', 'pickybooking' ) . '</a>';
        }
        return $actions;
    }

    private function picky_import_layout_error( $message ) {
        wp_safe_redirect(
            admin_url(
                add_query_arg(
                    array(
                        'page'             => 'picky_settings',
                        'picky-tab'          => '10',
                        'picky_import_error' => (string) $message,
                    ),
                    'admin.php'
                )
            )
        );
        exit;
    }

    /**
     * Resolve layout image meta (attachment ID or URL) to a public URL.
     *
     * @param mixed $raw
     * @return string
     */
    private function picky_layout_image_meta_to_url( $raw ) {
        if ( is_numeric( $raw ) && intval( $raw ) > 0 ) {
            $url = wp_get_attachment_url( intval( $raw ) );
            return is_string( $url ) ? $url : '';
        }
        if ( is_scalar( $raw ) ) {
            return trim( (string) $raw );
        }
        return '';
    }

    /**
     * Embed a local attachment/URL as a data URI for portable JSON export (max ~3MB).
     *
     * @param mixed $raw Attachment ID or URL.
     * @return string data:image/...;base64,... or empty.
     */
    private function picky_layout_image_to_data_uri( $raw ) {
        $path = '';
        if ( is_numeric( $raw ) && intval( $raw ) > 0 ) {
            $attached = get_attached_file( intval( $raw ) );
            $path     = is_string( $attached ) ? $attached : '';
        } elseif ( is_string( $raw ) && $raw !== '' ) {
            $uploads = wp_get_upload_dir();
            if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) && strpos( $raw, (string) $uploads['baseurl'] ) === 0 ) {
                $path = (string) $uploads['basedir'] . substr( $raw, strlen( (string) $uploads['baseurl'] ) );
            }
        }
        if ( $path === '' || ! is_readable( $path ) ) {
            return '';
        }
        $size = filesize( $path );
        if ( false === $size || $size <= 0 || $size > 3 * 1024 * 1024 ) {
            return '';
        }
        $ft   = wp_check_filetype( $path );
        $type = ( ! empty( $ft['type'] ) && is_string( $ft['type'] ) ) ? $ft['type'] : 'image/png';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $bin = file_get_contents( $path );
        if ( ! is_string( $bin ) || $bin === '' ) {
            return '';
        }
        return 'data:' . $type . ';base64,' . base64_encode( $bin );
    }

    /**
     * Import an image from a URL or data URI into the Media Library.
     *
     * @param string $url_or_data
     * @param string $title
     * @return int Attachment ID or 0.
     */
    private function picky_import_image_to_attachment( $url_or_data, $title = '' ) {
        $url_or_data = is_string( $url_or_data ) ? trim( $url_or_data ) : '';
        if ( $url_or_data === '' ) {
            return 0;
        }

        if ( preg_match( '#^data:image/([a-z0-9+.-]+);base64,(.+)$#is', $url_or_data, $m ) ) {
            $ext = strtolower( (string) $m[1] );
            if ( $ext === 'jpeg' ) {
                $ext = 'jpg';
            }
            $allowed = array( 'jpg', 'png', 'gif', 'webp' );
            if ( ! in_array( $ext, $allowed, true ) ) {
                return 0;
            }
            $bin = base64_decode( (string) $m[2], true );
            if ( false === $bin || $bin === '' ) {
                return 0;
            }
            $filename = 'picky-import-' . wp_generate_password( 8, false ) . '.' . $ext;
            $upload   = wp_upload_bits( $filename, null, $bin );
            if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
                return 0;
            }
            $filetype   = wp_check_filetype( (string) $upload['file'], null );
            $attachment = array(
                'post_mime_type' => isset( $filetype['type'] ) ? (string) $filetype['type'] : 'image/' . $ext,
                'post_title'     => $title !== '' ? $title : sanitize_file_name( $filename ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            );
            $attach_id = wp_insert_attachment( $attachment, (string) $upload['file'] );
            if ( ! $attach_id || is_wp_error( $attach_id ) ) {
                return 0;
            }
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata( (int) $attach_id, (string) $upload['file'] );
            if ( is_array( $meta ) ) {
                wp_update_attachment_metadata( (int) $attach_id, $meta );
            }
            return (int) $attach_id;
        }

        if ( ! preg_match( '#^https?://#i', $url_or_data ) ) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $id = media_sideload_image( $url_or_data, 0, ( $title !== '' ? $title : null ), 'id' );
        if ( is_wp_error( $id ) || ! $id ) {
            return 0;
        }
        return (int) $id;
    }

    /**
     * Allowed canvas background fit modes (import/export).
     *
     * @return array<int, string>
     */
    private function picky_layout_bg_mode_allowlist() {
        return array( 'cover', 'contain', 'stretch', 'repeat-x', 'repeat-y', 'center', 'repeat' );
    }

    private function picky_read_import_layout_payload() {
        return '';
    }

    private function picky_normalize_import_element( $element, $index ) {
        if ( ! is_array( $element ) ) {
            return null;
        }

        $class = isset( $element['class'] ) ? sanitize_text_field( (string) $element['class'] ) : 'type-1';
        if ( $class === '' ) {
            $class = 'type-1';
        }

        $id = isset( $element['id'] ) ? sanitize_text_field( (string) $element['id'] ) : (string) ( $index + 1 );
        if ( $id === '' ) {
            $id = (string) ( $index + 1 );
        }

        $unique = isset( $element['unique'] ) ? sanitize_key( (string) $element['unique'] ) : '';
        if ( $unique === '' && $class !== 'type-wall' ) {
            $unique = 'imported_' . sanitize_key( $id ) . '_' . ( $index + 1 );
        }

        $x  = isset( $element['x'] ) ? floatval( $element['x'] ) : 0;
        $y  = isset( $element['y'] ) ? floatval( $element['y'] ) : 0;
        $x1 = isset( $element['x1'] ) ? floatval( $element['x1'] ) : $x + 100;
        $y1 = isset( $element['y1'] ) ? floatval( $element['y1'] ) : $y + 100;
        if ( $x1 <= $x ) {
            $x1 = $x + 100;
        }
        if ( $y1 <= $y ) {
            $y1 = $y + 100;
        }

        $seats = ( isset( $element['seats'] ) && (string) $element['seats'] !== '' )
            ? absint( $element['seats'] )
            : 1;
        $max_seats = ( isset( $element['max_seats'] ) && (string) $element['max_seats'] !== '' )
            ? absint( $element['max_seats'] )
            : $seats;
        if ( $class === 'type-wall' ) {
            $seats = 0;
            $max_seats = 0;
        }

        $import_label_font_size = ( isset( $element['label_font_size'] ) && (string) $element['label_font_size'] !== '' )
            ? max( 8, min( 200, intval( $element['label_font_size'] ) ) )
            : 12;
        $import_label_color     = isset( $element['label_color'] ) ? picky_sanitize_hex_color( (string) $element['label_color'] ) : '';
        if ( ! $import_label_color ) {
            $import_label_color = '#333333';
        }
        $import_label_weight = isset( $element['label_font_weight'] ) ? sanitize_text_field( (string) $element['label_font_weight'] ) : 'normal';
        $import_weight_allow   = array( 'normal', 'bold', '300', '400', '500', '600', '700', '800', '900' );
        if ( ! in_array( $import_label_weight, $import_weight_allow, true ) ) {
            $import_label_weight = 'normal';
        }

        $import_opacity = ( isset( $element['opacity'] ) && (string) $element['opacity'] !== '' )
            ? sanitize_text_field( (string) $element['opacity'] )
            : '1';
        $import_font_size = ( isset( $element['font_size'] ) && (string) $element['font_size'] !== '' )
            ? (string) absint( $element['font_size'] )
            : '16';
        $import_padding = ( isset( $element['padding'] ) && (string) $element['padding'] !== '' )
            ? (string) absint( $element['padding'] )
            : '10';
        $import_shape_border_width = ( isset( $element['shape_border_width'] ) && (string) $element['shape_border_width'] !== '' )
            ? (string) absint( $element['shape_border_width'] )
            : '0';

        return array(
            'x'                  => $x,
            'y'                  => $y,
            'x1'                 => $x1,
            'y1'                 => $y1,
            'id'                 => $id,
            'unique'             => $unique,
            'class'              => $class,
            'rotate'             => isset( $element['rotate'] ) ? sanitize_text_field( (string) $element['rotate'] ) : '1',
            'rotate_z'           => isset( $element['rotate_z'] ) ? sanitize_text_field( (string) $element['rotate_z'] ) : '0',
            'rotate_x'           => isset( $element['rotate_x'] ) ? sanitize_text_field( (string) $element['rotate_x'] ) : '0',
            'rotate_y'           => isset( $element['rotate_y'] ) ? sanitize_text_field( (string) $element['rotate_y'] ) : '0',
            'big_side'           => isset( $element['big_side'] ) ? sanitize_text_field( (string) $element['big_side'] ) : 'w',
            'seats'              => (string) $seats,
            'max_seats'          => (string) $max_seats,
            'label'              => isset( $element['label'] ) ? sanitize_text_field( (string) $element['label'] ) : '',
            'label_position'     => isset( $element['label_position'] ) ? sanitize_text_field( (string) $element['label_position'] ) : 'bottom',
            'label_font_size'    => (string) $import_label_font_size,
            'label_color'        => $import_label_color,
            'label_font_weight'  => $import_label_weight,
            'show_id'            => ( isset( $element['show_id'] ) && (string) $element['show_id'] === '0' ) ? '0' : '1',
            'custom_id'          => isset( $element['custom_id'] ) ? sanitize_text_field( (string) $element['custom_id'] ) : '',
            'custom_url'         => isset( $element['custom_url'] ) ? esc_url_raw( (string) $element['custom_url'] ) : '',
            'color'              => isset( $element['color'] ) ? picky_sanitize_hex_color( (string) $element['color'] ) : '',
            'opacity'            => $import_opacity,
            'text'               => isset( $element['text'] ) ? sanitize_textarea_field( (string) $element['text'] ) : '',
            'text_color'         => isset( $element['text_color'] ) ? picky_sanitize_hex_color( (string) $element['text_color'] ) : '#333333',
            'text_color_mode'    => ( isset( $element['text_color_mode'] ) && (string) $element['text_color_mode'] === 'theme' ) ? 'theme' : 'custom',
            'font_size'          => $import_font_size,
            'line_height'        => ( isset( $element['line_height'] ) && (string) $element['line_height'] !== '' )
                ? (string) max( 8, min( 400, absint( $element['line_height'] ) ) )
                : '',
            'font_family'        => isset( $element['font_family'] ) ? sanitize_text_field( (string) $element['font_family'] ) : 'inherit',
            'font_weight'        => isset( $element['font_weight'] ) ? sanitize_text_field( (string) $element['font_weight'] ) : 'normal',
            'shape_type'         => isset( $element['shape_type'] ) ? sanitize_text_field( (string) $element['shape_type'] ) : 'rectangle',
            // Empty string / 0 must stay empty: absint('') === 0 and legacy "0"
            // would force sharp corners on import (overriding CSS default 8px).
            'shape_radius'       => ( isset( $element['shape_radius'] ) && (string) $element['shape_radius'] !== '' )
                ? (string) absint( $element['shape_radius'] )
                : '',
            'shape_border_width' => $import_shape_border_width,
            'shape_border_color' => isset( $element['shape_border_color'] ) ? picky_sanitize_hex_color( (string) $element['shape_border_color'] ) : '',
            'icon_key'           => isset( $element['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $element['icon_key'] ) : '',
            'square_radius'      => ( isset( $element['square_radius'] ) && (string) $element['square_radius'] !== '' && absint( $element['square_radius'] ) > 0 )
                ? (string) absint( $element['square_radius'] )
                : '',
            'wall_radius'        => ( isset( $element['wall_radius'] ) && (string) $element['wall_radius'] !== '' )
                ? (string) max( 0, min( 120, absint( $element['wall_radius'] ) ) )
                : '',
            'padding'            => $import_padding,
            'service_ids'        => isset( $element['service_ids'] ) && is_array( $element['service_ids'] )
                ? array_values( array_filter( array_map( 'sanitize_key', $element['service_ids'] ) ) )
                : array(),
        );
    }

    /**
     * Sanitize a raw services array coming from imported JSON or the layout builder save.
     * Each service: { sid, name, price, duration, description?, parent_sids? }
     *
     * Note: `parent_sids` are read here as-is (with self-removal). Cross-record
     * validation (existence of parents, anti-cycle, strict 2-level hierarchy) is
     * applied later in {@see picky_sanitize_services_library()} after the full list
     * is materialised.
     *
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function picky_sanitize_element_services( $raw ) {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $svc ) {
            if ( ! is_array( $svc ) ) {
                continue;
            }
            $sid  = isset( $svc['sid'] ) ? sanitize_key( (string) $svc['sid'] ) : '';
            $name = isset( $svc['name'] ) ? sanitize_text_field( (string) $svc['name'] ) : '';
            if ( $name === '' ) {
                continue; // skip unnamed services
            }
            if ( $sid === '' ) {
                $sid = 'svc_' . substr( md5( $name . count( $out ) ), 0, 8 );
            }
            $price    = isset( $svc['price'] ) ? max( 0.0, round( (float) $svc['price'], 2 ) ) : 0.0;
            $duration = isset( $svc['duration'] ) ? max( 1, (int) $svc['duration'] ) : 30;
            $description = isset( $svc['description'] ) ? substr( wp_strip_all_tags( (string) $svc['description'] ), 0, 200 ) : '';

            $parent_sids = array();
            if ( isset( $svc['parent_sids'] ) && is_array( $svc['parent_sids'] ) ) {
                foreach ( $svc['parent_sids'] as $psid ) {
                    $psid = sanitize_key( (string) $psid );
                    if ( $psid === '' || $psid === $sid ) {
                        continue; // drop self-parent + empties
                    }
                    if ( ! in_array( $psid, $parent_sids, true ) ) {
                        $parent_sids[] = $psid;
                    }
                }
            }

            $out[] = array(
                'sid'         => $sid,
                'name'        => $name,
                'price'       => $price,
                'duration'    => $duration,
                'description' => $description,
                'parent_sids' => $parent_sids,
                'icon'        => isset( $svc['icon'] ) ? Booking_Icons::sanitize( $svc['icon'] ) : '',
            );
        }
        return $out;
    }

    /**
     * Sanitize the layout-level services library.
     * Enforces a strict 2-level hierarchy (parent -> add-on):
     *  - parents listed in `parent_sids` must exist in the library;
     *  - a parent cannot itself be an add-on (an add-on can't parent another add-on);
     *  - cycles are prevented (degenerate to top-level on violation).
     *
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function picky_sanitize_services_library( $raw ) {
        $list = $this->picky_sanitize_element_services( $raw );
        if ( empty( $list ) ) {
            return $list;
        }

        // First pass: collect existing SIDs and which sids declare any parent.
        $sid_set     = array();
        $declares_parent = array();
        foreach ( $list as $svc ) {
            $sid_set[ (string) $svc['sid'] ] = true;
            if ( ! empty( $svc['parent_sids'] ) ) {
                $declares_parent[ (string) $svc['sid'] ] = true;
            }
        }

        // Second pass: drop parent refs that don't exist, prevent 2-level
        // violations (parent must NOT be an add-on itself), and drop cycles.
        foreach ( $list as &$svc ) {
            if ( empty( $svc['parent_sids'] ) ) {
                $svc['parent_sids'] = array();
                continue;
            }
            $clean = array();
            foreach ( $svc['parent_sids'] as $psid ) {
                $psid = (string) $psid;
                if ( ! isset( $sid_set[ $psid ] ) ) {
                    continue; // unknown sid
                }
                if ( isset( $declares_parent[ $psid ] ) ) {
                    continue; // parent is itself an add-on => violates 2-level rule
                }
                $clean[] = $psid;
            }
            $svc['parent_sids'] = array_values( array_unique( $clean ) );
        }
        unset( $svc );

        return $list;
    }

    /**
     * Migrate a layout from per-element services[] to a layout-level library.
     * Idempotent: skips if _picky_layout_services_library already exists.
     *
     * @param int $layout_id
     * @return void
     */
    /**
     * One-time bulk migration: scan every picky_layout post and migrate any that
     * still have legacy per-element services[] into the layout-level library.
     * Stamped via the `picky_layout_library_migrated` site option so we never
     * re-scan unnecessarily.
     */
    public function picky_maybe_bulk_migrate_layouts() {
        if ( get_option( 'picky_layout_library_migrated' ) === '1' ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $layouts = get_posts( array(
            'post_type'     => 'picky_layout',
            'post_status'   => 'any',
            'numberposts'   => -1,
            'fields'        => 'ids',
            'no_found_rows' => true,
        ) );
        foreach ( $layouts as $lid ) {
            $this->picky_migrate_layout_to_library( (int) $lid );
        }
        update_option( 'picky_layout_library_migrated', '1', false );
    }

    private function picky_migrate_layout_to_library( $layout_id ) {
        $layout_id = absint( $layout_id );
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            return;
        }
        $existing = get_post_meta( $layout_id, '_picky_layout_services_library', true );
        if ( $existing !== '' && $existing !== false && $existing !== null ) {
            return; // already migrated
        }

        $position = get_post_meta( $layout_id, '_picky_layout_position', true );
        if ( ! $position ) {
            update_post_meta( $layout_id, '_picky_layout_services_library', '[]' );
            return;
        }
        $elements = json_decode( wp_unslash( $position ), true );
        if ( ! is_array( $elements ) ) {
            update_post_meta( $layout_id, '_picky_layout_services_library', '[]' );
            return;
        }

        $library = array();
        $library_map = array(); // key: name|price|duration => sid

        foreach ( $elements as &$el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            $legacy_services = isset( $el['services'] ) && is_array( $el['services'] ) ? $el['services'] : array();
            if ( empty( $legacy_services ) ) {
                continue;
            }
            $el_service_ids = array();
            foreach ( $legacy_services as $svc ) {
                if ( ! is_array( $svc ) || empty( $svc['name'] ) ) {
                    continue;
                }
                $key = (string) $svc['name'] . '|' . ( isset( $svc['price'] ) ? (float) $svc['price'] : 0 ) . '|' . ( isset( $svc['duration'] ) ? (int) $svc['duration'] : 30 );
                if ( isset( $library_map[ $key ] ) ) {
                    $el_service_ids[] = $library_map[ $key ];
                    continue;
                }
                $sid = ! empty( $svc['sid'] ) ? sanitize_key( (string) $svc['sid'] ) : 'svc_' . substr( md5( $key ), 0, 8 );
                // ensure unique sid in library
                $existing_sids = array_column( $library, 'sid' );
                $attempt = 0;
                $final_sid = $sid;
                while ( in_array( $final_sid, $existing_sids, true ) ) {
                    $final_sid = $sid . '_' . $attempt;
                    $attempt++;
                }
                $library[] = array(
                    'sid'         => $final_sid,
                    'name'        => sanitize_text_field( (string) $svc['name'] ),
                    'price'       => isset( $svc['price'] ) ? max( 0.0, round( (float) $svc['price'], 2 ) ) : 0.0,
                    'duration'    => isset( $svc['duration'] ) ? max( 1, (int) $svc['duration'] ) : 30,
                    'description' => isset( $svc['description'] ) ? substr( wp_strip_all_tags( (string) $svc['description'] ), 0, 200 ) : '',
                );
                $library_map[ $key ] = $final_sid;
                $el_service_ids[] = $final_sid;
            }
            $el['service_ids'] = array_values( array_unique( $el_service_ids ) );
            unset( $el['services'], $el['default_service'] );
        }
        unset( $el );

        update_post_meta( $layout_id, '_picky_layout_services_library', wp_json_encode( $library ) );
        update_post_meta( $layout_id, '_picky_layout_position', wp_slash( wp_json_encode( $elements ) ) );
    }

    private function picky_parse_import_layout_payload( $payload ) {
        $payload = trim( (string) $payload );
        if ( $payload === '' ) {
            $this->picky_import_layout_error( __( 'Paste JSON content or upload a JSON file.', 'pickybooking' ) );
        }

        $decoded = json_decode( $payload, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = json_decode( rawurldecode( $payload ), true );
        }
        if ( ! is_array( $decoded ) ) {
            $this->picky_import_layout_error( __( 'Layout JSON is invalid.', 'pickybooking' ) );
        }

        $title = '';
        $canvas_width  = (int) INFO::$DEFAULT_OPTIONS['canvas_width'];
        $canvas_height = (int) INFO::$DEFAULT_OPTIONS['canvas_height'];
        $elements = $decoded;
        $layout_meta = array(
            'background_canvas'      => '',
            'background_canvas_data' => '',
            'background_table'       => '',
            'background_table_data'  => '',
            'background_canvas_mode' => '',
            'transparent_canvas'     => '',
            'show_seats'             => '',
            'show_ids'               => '',
            'show_labels'            => '',
            'show_item_fill'         => '',
            'show_booked_avatars'    => '',
            'booked_avatars_tone'    => '',
            'shortcode_layout'       => '',
            'multi_select'           => '',
            'event_mode'             => '',
            'event_date'             => '',
            'event_time'             => '',
            'event_show_label'       => '',
            'booking_item_icon'      => '',
            'services_library'       => null,
        );

        if ( isset( $decoded['elements'] ) && is_array( $decoded['elements'] ) ) {
            $elements = $decoded['elements'];
            $title = isset( $decoded['title'] ) ? sanitize_text_field( (string) $decoded['title'] ) : '';
            $canvas_width = isset( $decoded['canvas_width'] ) ? intval( $decoded['canvas_width'] ) : $canvas_width;
            $canvas_height = isset( $decoded['canvas_height'] ) ? intval( $decoded['canvas_height'] ) : $canvas_height;
            foreach ( array_keys( $layout_meta ) as $meta_key ) {
                if ( ! array_key_exists( $meta_key, $decoded ) ) {
                    continue;
                }
                if ( $meta_key === 'services_library' ) {
                    $layout_meta[ $meta_key ] = is_array( $decoded[ $meta_key ] ) ? $decoded[ $meta_key ] : null;
                    continue;
                }
                $layout_meta[ $meta_key ] = is_scalar( $decoded[ $meta_key ] ) ? (string) $decoded[ $meta_key ] : '';
            }
        } elseif ( isset( $decoded['position'] ) || isset( $decoded['layout_position'] ) ) {
            $position = isset( $decoded['position'] ) ? (string) $decoded['position'] : (string) $decoded['layout_position'];
            $elements_from_position = json_decode( rawurldecode( $position ), true );
            if ( ! is_array( $elements_from_position ) ) {
                $this->picky_import_layout_error( __( 'Imported layout position is invalid.', 'pickybooking' ) );
            }
            $elements = $elements_from_position;
            $title = isset( $decoded['title'] ) ? sanitize_text_field( (string) $decoded['title'] ) : '';
            $canvas_width = isset( $decoded['canvas_width'] ) ? intval( $decoded['canvas_width'] ) : $canvas_width;
            $canvas_height = isset( $decoded['canvas_height'] ) ? intval( $decoded['canvas_height'] ) : $canvas_height;
        }

        if ( empty( $elements ) || ! is_array( $elements ) ) {
            $this->picky_import_layout_error( __( 'No layout elements found in the imported JSON.', 'pickybooking' ) );
        }

        $normalized = array();
        foreach ( $elements as $index => $element ) {
            $normalized_element = $this->picky_normalize_import_element( $element, (int) $index );
            if ( $normalized_element !== null ) {
                $normalized[] = $normalized_element;
            }
        }

        if ( empty( $normalized ) ) {
            $this->picky_import_layout_error( __( 'No valid layout elements found in the imported JSON.', 'pickybooking' ) );
        }

        $bg_mode = sanitize_key( (string) $layout_meta['background_canvas_mode'] );
        if ( $bg_mode !== '' && ! in_array( $bg_mode, $this->picky_layout_bg_mode_allowlist(), true ) ) {
            $bg_mode = '';
        }

        return array(
            'title'                  => $title,
            'canvas_width'           => max( 500, min( 2500, $canvas_width ) ),
            'canvas_height'          => max( 500, min( 2500, $canvas_height ) ),
            'elements'               => $normalized,
            'background_canvas'      => $layout_meta['background_canvas'],
            'background_canvas_data' => $layout_meta['background_canvas_data'],
            'background_table'       => $layout_meta['background_table'],
            'background_table_data'  => $layout_meta['background_table_data'],
            'background_canvas_mode' => $bg_mode,
            'transparent_canvas'     => ( $layout_meta['transparent_canvas'] === '1' ) ? '1' : ( ( $layout_meta['transparent_canvas'] === '0' ) ? '0' : '' ),
            'show_seats'             => ( $layout_meta['show_seats'] === '0' || $layout_meta['show_seats'] === '1' ) ? $layout_meta['show_seats'] : '',
            'show_ids'               => ( $layout_meta['show_ids'] === '0' || $layout_meta['show_ids'] === '1' ) ? $layout_meta['show_ids'] : '',
            'show_labels'            => ( $layout_meta['show_labels'] === '0' || $layout_meta['show_labels'] === '1' ) ? $layout_meta['show_labels'] : '',
            'show_item_fill'         => ( $layout_meta['show_item_fill'] === '0' || $layout_meta['show_item_fill'] === '1' ) ? $layout_meta['show_item_fill'] : '',
            'shortcode_layout'       => in_array( $layout_meta['shortcode_layout'], array( '1', '2', '3', '4' ), true ) ? $layout_meta['shortcode_layout'] : '',
            'multi_select'           => ( $layout_meta['multi_select'] === '0' || $layout_meta['multi_select'] === '1' ) ? $layout_meta['multi_select'] : '',
            'event_mode'             => ( $layout_meta['event_mode'] === 'once' ) ? 'once' : '',
            'event_date'             => function_exists( 'picky_normalize_booking_date' ) ? picky_normalize_booking_date( $layout_meta['event_date'] ) : '',
            'event_time'             => function_exists( 'picky_normalize_booking_time' ) ? picky_normalize_booking_time( $layout_meta['event_time'] ) : '',
            'event_show_label'       => ( $layout_meta['event_show_label'] === '0' || $layout_meta['event_show_label'] === '1' ) ? $layout_meta['event_show_label'] : '',
            'booking_item_icon'      => Booking_Icons::sanitize( $layout_meta['booking_item_icon'] ),
            'services_library'       => $layout_meta['services_library'],
        );
    }

    public function import_layout() {
        wp_die( esc_html__( 'JSON import is available in PickyBooking Pro.', 'pickybooking' ) );
    }

    public function export_layout() {
        wp_die( esc_html__( 'JSON export is available in PickyBooking Pro.', 'pickybooking' ) );
    }


    public function render_settings() {
        $time_all    = INFO::$TIME_ALL;
        $path        = INFO::get_plugin_path();
        $item_label  = $this->picky_item_label();
        $items_label = $this->picky_items_label();

        $field_args = [
            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_main_labels',
                'title'       => __( 'Names & how far ahead guests can book', 'pickybooking' ),
                'description' => __( 'Labels guests see for your bookable items, plus the rolling booking window and default slot length used when building availability.', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label' => __( 'Singular name for what is being booked', 'pickybooking' ),
                'slug'  => 'reservable_item_name',
                'type'  => 'text',
                'after' => __( 'e.g. Table, Room, Court, Desk', 'pickybooking' ),
                'tab'   => '1',
            ],
            [
                'label' => __( 'Plural name', 'pickybooking' ),
                'slug'  => 'reservable_items_name',
                'type'  => 'text',
                'after' => __( 'e.g. Tables, Rooms. Leave empty to add “s” to the singular name.', 'pickybooking' ),
                'tab'   => '1',
            ],
            [
                'label'       => __( 'How many days ahead bookings are allowed', 'pickybooking' ),
                'slug'        => 'days_ahead',
                'type'        => 'number',
                'after'       => __( 'day(s)', 'pickybooking' ),
                'description' => __( 'Last bookable day is today plus this many days (rolling window).', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label'       => __( 'Default booking length (hours)', 'pickybooking' ),
                'slug'        => 'table_booked_time',
                'type'        => 'number',
                'after'       => __( 'hour(s)', 'pickybooking' ),
                'description' => __( 'If the guest does not choose an end time, the reserved slot is this long. Also used when building availability.', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label'       => __( 'Extra blocked time before each booking (hours)', 'pickybooking' ),
                'slug'        => 'table_before_booked_time',
                'type'        => 'number',
                'after'       => __( 'hour(s)', 'pickybooking' ),
                'description' => __( 'Padding before an existing booking: new requests cannot start inside this window before another booking begins (turnaround / setup).', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_main_capacity',
                'title'       => __( 'Party size & what visitors see', 'pickybooking' ),
                'description' => __( 'Turn party size on when you use capacity mode. Control which fields and map hints appear on the public booking form.', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label'       => __( 'Ask for party size (number of people)', 'pickybooking' ),
                'slug'        => 'shop_people_amount',
                'type'        => 'checkbox',
                'description' => __( 'Needed for capacity mode and any setup where availability depends on guest count.', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label' => __( 'Visibility on the booking form & layout', 'pickybooking' ),
                'slug'  => 'picky_group_display_options',
                'type'  => 'checkbox_group',
                'tab'   => '1',
                'items' => array(
                    array(
                        'slug'  => 'show_email',
                        'label' => __( 'Show email field', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'show_phone',
                        'label' => __( 'Show phone field', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'show_people',
                        'label' => sprintf(
                            /* translators: %s: singular item label */
                            __( 'Show “max persons” on each %s', 'pickybooking' ),
                            $item_label
                        ),
                    ),
                    array(
                        'slug'  => 'show_seats',
                        'label' => __( 'Show center seat / capacity badge on the map', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'show_id_badge',
                        'label' => __( 'Show ID badge on the map', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'show_element_label',
                        'label' => __( 'Show element label on the map', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'show_time_to',
                        'label' => __( 'Show end time (“until”) for the reservation', 'pickybooking' ),
                    ),
                ),
            ],
            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_main_ux',
                'title'       => __( 'Wizard flow, mobile & placement', 'pickybooking' ),
                'description' => __( 'Choose how guests move through the booking steps on phones and desktop, exclusive vs shared capacity, and how the shortcode block aligns on the page.', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label' => sprintf(
                    /* translators: %s: singular item label (table, room, …) */
                    __( 'On phones: how guests pick a %s', 'pickybooking' ),
                    $item_label
                ),
                'slug'  => 'mobile_table_select',
                'type'  => 'radio',
                'inputs' => array(
                    0 => array(
                        'title' => __( 'Hide layout (no map)', 'pickybooking' ),
                        'value' => '0',
                        'description' => sprintf(
                            /* translators: %s: singular item label */
                            __( 'On narrow screens the map is hidden; guests book without choosing a specific %s (if your flow allows).', 'pickybooking' ),
                            $item_label
                        ),
                    ),
                    1 => array(
                        'title' => __( 'Show scaled map (tap to select)', 'pickybooking' ),
                        'value' => '1',
                        'description' => sprintf(
                            /* translators: %s: singular item label */
                            __( 'The full layout is shown, scaled to fit; guests tap a %s.', 'pickybooking' ),
                            $item_label
                        ),
                    ),
                    2 => array(
                        'title' => __( 'Show a dropdown list', 'pickybooking' ),
                        'value' => '2',
                        'description' => sprintf(
                            /* translators: %s: plural items label */
                            __( 'A list of %s; unavailable ones are disabled when possible.', 'pickybooking' ),
                            $items_label
                        ),
                    ),
                ),
                'tab'   => '1',
            ],
            [
                'label' => __( 'Booking mode', 'pickybooking' ),
                'slug'  => 'booking_mode',
                'type'  => 'radio',
                'inputs' => array(
                    0 => array(
                        'title' => __( 'Exclusive — one booking per item per time slot', 'pickybooking' ),
                        'value' => 'exclusive',
                        'description' => sprintf(
                            /* translators: %s: singular item label */
                            __( 'If any part of a time range overlaps an existing booking, that %s is unavailable.', 'pickybooking' ),
                            $item_label
                        ),
                    ),
                    1 => array(
                        'title' => __( 'Capacity — share by headcount', 'pickybooking' ),
                        'value' => 'capacity',
                        'description' => sprintf(
                            /* translators: 1: singular item label, 2: plural items label */
                            __( 'Each %1$s has a “max persons”; several bookings can share the same %2$s until capacity is full. Turn on “party size” above.', 'pickybooking' ),
                            $item_label,
                            $items_label
                        ),
                    ),
                ),
                'tab'   => '1',
            ],
            [
                'label' => __( 'Wizard order (frontend steps)', 'pickybooking' ),
                'slug'  => 'shortcode_layout',
                'type'  => 'radio',
                'class' => 'picky-setting-radio-buttons',
                'inputs' => array(
                    0 => array(
                        'title' => __( 'Classic — pick item first', 'pickybooking' ),
                        'value' => '1',
                        'description' => sprintf(
                            /* translators: %s: singular item label */
                            __( 'Guest chooses a %s, then date, time, and details in the popup.', 'pickybooking' ),
                            $item_label
                        ),
                    ),
                    1 => array(
                        'title' => __( 'Smart — date & time first', 'pickybooking' ),
                        'value' => '2',
                        'description' => sprintf(
                            /* translators: %s: plural items label */
                            __( 'Guest picks date and time, then one of the available %s, then details.', 'pickybooking' ),
                            $items_label
                        ),
                    ),
                    2 => array(
                        'title' => __( 'Smart Map — layout first', 'pickybooking' ),
                        'value' => '4',
                        'description' => sprintf(
                            /* translators: %s: plural items label */
                            __( 'Map is shown immediately. Guest picks date and time in a compact bar at the bottom; available %s update on the layout.', 'pickybooking' ),
                            $items_label
                        ),
                    ),
                    3 => array(
                        'title' => __( 'Simple — no map, time only', 'pickybooking' ),
                        'value' => '3',
                        'description' => sprintf(
                            /* translators: 1: singular item label, 2: singular item label (same) */
                            __( 'No picking a specific %1$s on the map: guest chooses date, time, and details; the first available %2$s is assigned.', 'pickybooking' ),
                            $item_label,
                            $item_label
                        ),
                    ),
                ),
                'tab'   => '1',
            ],
            [
                'label'       => __( 'Shortcode block alignment', 'pickybooking' ),
                'slug'        => 'container_align',
                'type'        => 'select',
                'options'     => array( 'left', 'center', 'right' ),
                'description' => __( 'Align the booking widget within the page content (left, center, or right).', 'pickybooking' ),
                'tab'         => '1',
            ],
            [
                'label'       => __( 'Animate bookable items on the map', 'pickybooking' ),
                'slug'        => 'animate_bookable_items',
                'type'        => 'checkbox',
                'description' => __( 'Staggered entrance when the layout loads, plus a light hover/selection scale. Turn off for a fully static floor plan.', 'pickybooking' ),
                'tab'         => '1',
            ],

            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_schedule',
                'title'       => __( 'Weekly schedule', 'pickybooking' ),
                'description' => __( 'Turn days on or off and set the hours when bookings are accepted. You can add a second time window per day.', 'pickybooking' ),
                'tab'         => '2',
            ],
            [
                'label'       => __( 'Opening hours by day', 'pickybooking' ),
                'slug'        => 'work_days',
                'type'        => 'checkboxes',
                'tab'         => '2',
                'description' => __( 'Guests can only book inside the enabled intervals.', 'pickybooking' ),
                'checkboxes' => array(
                    0 => array(
                        'title' => __('Monday', 'pickybooking'),
                        'name' => 'monday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    1 => array(
                        'title' => __('Tuesday', 'pickybooking'),
                        'name' => 'tuesday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    2 => array(
                        'title' => __('Wednesday', 'pickybooking'),
                        'name' => 'wednesday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    3 => array(
                        'title' => __('Thursday', 'pickybooking'),
                        'name' => 'thursday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    4 => array(
                        'title' => __('Friday', 'pickybooking'),
                        'name' => 'friday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    5 => array(
                        'title' => __('Saturday', 'pickybooking'),
                        'name' => 'saturday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                    6 => array(
                        'title' => __('Sunday', 'pickybooking'),
                        'name' => 'sunday',
                        'selects' => array(
                            array(
                                'options' => $time_all,
                                'name' => 'time_start'
                            ),
                            array(
                                'options' => $time_all,
                                'name' => 'time_end'
                            )
                        )
                    ),
                )
            ],
            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_form_copy',
                'title'       => __( 'Form text & emails', 'pickybooking' ),
                'description' => __( 'Customize helper text on the public form, consent wording, thank-you message, and where admin notifications are sent.', 'pickybooking' ),
                'tab'         => '6',
            ],
            [
                'type'        => 'ai_form_copy_button',
                'slug'        => 'ai_form_copy',
                'tab'         => '6',
                'description' => __( 'Generate helper text, consent wording, and thank-you copy from a short business description. Review the fields below, then Save settings.', 'pickybooking' ),
            ],
            [
                'label'       => __( 'Hint under the “Book” button', 'pickybooking' ),
                'slug'        => 'form_message',
                'type'        => 'text',
                'description' => __( 'Short line shown under the main call-to-action (e.g. “We will confirm shortly”).', 'pickybooking' ),
                'tab'         => '6',
            ],
            [
                'label'       => __( 'Consent & customer email', 'pickybooking' ),
                'slug'        => 'picky_group_form_email_flags',
                'type'        => 'checkbox_group',
                'tab'         => '6',
                'description' => __( 'To email the guest, enable “Show email field” on the Main tab and ask them to fill it in.', 'pickybooking' ),
                'items'       => array(
                    array(
                'slug'  => 'form_confirm',
                        'label' => __( 'Require GDPR / privacy consent checkbox', 'pickybooking' ),
                    ),
                    array(
                        'slug'  => 'send_customer_email',
                        'label' => __( 'Send booking confirmation email to the guest', 'pickybooking' ),
                    ),
                ),
            ],
            [
                'label'       => __( 'Consent checkbox label & text', 'pickybooking' ),
                'slug'        => 'form_confirm_text',
                'type'        => 'textarea',
                'description' => __( 'HTML allowed. Shown next to the consent checkbox when it is enabled.', 'pickybooking' ),
                'tab'         => '6',
            ],
            [
                'label'       => __( 'Thank-you message after booking', 'pickybooking' ),
                'slug'        => 'form_thx',
                'type'        => 'textarea',
                'description' => __( 'Leave empty to use the built-in default message.', 'pickybooking' ),
                'tab'         => '6',
            ],
            [
                'label'       => __( 'Where to send admin booking alerts', 'pickybooking' ),
                'description' => __( 'If empty, WordPress site admin email is used.', 'pickybooking' ),
                'slug'        => 'form_email',
                'type'        => 'text',
                'tab'         => '6',
            ],

            [
                'type'        => 'settings_section',
                'slug'        => 'picky_sec_layout_colors',
                'title'       => __( 'Layout Colors', 'pickybooking' ),
                'description' => __( 'Public shortcode appearance only. Does not change colors inside the admin layout builder.', 'pickybooking' ),
                'tab'         => '4',
            ],
            [
                'label'       => __( 'Public map & chrome colors', 'pickybooking' ),
                'slug'        => 'color_main',
                'type'        => 'colors',
                'tab'         => '4',
                'description' => __( 'Shortcode only, not the admin builder. The first color tints the whole booking wrapper and the left calendar column — it is not the draggable map image (that is set on each layout). Other colors target map items (fills, seats, badges, IDs).', 'pickybooking' ),
                'inputs'      => array(
                    0 => array(
                        'title' => __( 'Booking wrapper & calendar column background', 'pickybooking' ),
                        'name'  => 'main',
                    ),
                    1 => array(
                        'title' => sprintf(
                            /* translators: %s: plural bookable items, e.g. "Tables" */
                            __( '%s shape fill (map items without their own color / image)', 'pickybooking' ),
                            $items_label
                        ),
                        'name' => 'tables',
                    ),
                    15 => array(
                        'title' => __( 'Seat chip fill (small circles on map items)', 'pickybooking' ),
                        'name'  => 'seats',
                    ),
                    16 => array(
                        'title' => __( 'Seats count pill on map items (.picky-element-seats) — background', 'pickybooking' ),
                        'name'  => 'max_seats',
                    ),
                    17 => array(
                        'title' => __( 'Max persons badge — text (on hover)', 'pickybooking' ),
                        'name'  => 'max_seats_text',
                    ),
                    18 => array(
                        'title' => sprintf(
                            /* translators: %s: singular item, e.g. "Table" */
                            __( '%s ID badge background (unused when ID is unboxed text)', 'pickybooking' ),
                            $item_label
                        ),
                        'name' => 'table_id',
                    ),
                    19 => array(
                        'title' => sprintf(
                            /* translators: %s: singular item */
                            __( '%s ID text on map (D1, MR1, …)', 'pickybooking' ),
                            $item_label
                        ),
                        'name' => 'table_id_text',
                    ),
                ),
            ],
            [
                'label'       => __( 'Date picker colors', 'pickybooking' ),
                'slug'        => 'color_datepicker',
                'type'        => 'colors',
                'tab'         => '4',
                'description' => __( 'Shortcode only. Targets the booking calendar (jQuery UI). Header row uses global .ui-datepicker selectors — if another plugin adds a datepicker on the same page, its title row may pick up that color too.', 'pickybooking' ),
                'inputs' => array(
                    1 => array(
                        'title' => __( 'Month/year & weekday row text (.ui-datepicker title + th)', 'pickybooking' ),
                        'name'  => 'datepicker_text_all',
                    ),
                    2 => array(
                        'title' => __( 'Day cell background (enabled, not selected)', 'pickybooking' ),
                        'name'  => 'datepicker',
                    ),
                    3 => array(
                        'title' => __( 'Day cell background (disabled / unavailable)', 'pickybooking' ),
                        'name'  => 'datepicker_disabled',
                    ),
                    4 => array(
                        'title' => __( 'Day cell background (selected day)', 'pickybooking' ),
                        'name'  => 'datepicker_active',
                    ),
                    5 => array(
                        'title' => __( 'Day number text (default)', 'pickybooking' ),
                        'name'  => 'datepicker_text',
                    ),
                    6 => array(
                        'title' => __( 'Day number text (disabled)', 'pickybooking' ),
                        'name'  => 'datepicker_text_disabled',
                    ),
                    7 => array(
                        'title' => __( 'Day number text (selected)', 'pickybooking' ),
                        'name'  => 'datepicker_text_active',
                    ),
                    8 => array(
                        'title' => __( 'Prev/next arrow icon color', 'pickybooking' ),
                        'name'  => 'datepicker_arrow',
                    ),
                    9 => array(
                        'title' => __( 'Prev/next arrow hover fill', 'pickybooking' ),
                        'name'  => 'datepicker_arrow_hover',
                    ),
                    10 => array(
                        'title' => __( 'Prev/next arrow button background', 'pickybooking' ),
                        'name'  => 'datepicker_arrow_background',
                    ),
                    11 => array(
                        'title' => __( 'Prev/next arrow icon (disabled)', 'pickybooking' ),
                        'name'  => 'datepicker_arrow_disabled',
                    ),
                    12 => array(
                        'title' => __( 'Prev/next arrow button background (disabled)', 'pickybooking' ),
                        'name'  => 'datepicker_arrow_background_disabled',
                    ),
                )
            ],
            [
                'label'       => __( 'Booking form colors', 'pickybooking' ),
                'slug'        => 'color_form',
                'type'        => 'colors',
                'tab'         => '4',
                'description' => __( 'Shortcode only. Submit button is the public booking CTA color (independent of the plugin’s mint admin brand). Notices apply everywhere; “form panel” only colors extra step containers in smart / multi-step flows — not the first popup shell.', 'pickybooking' ),
                'inputs'      => array(
                    2 => array(
                        'title' => __( 'Submit / Pay & Book button background', 'pickybooking' ),
                        'name'  => 'book_now',
                    ),
                    3 => array(
                        'title' => __( 'Submit / Pay & Book button text', 'pickybooking' ),
                        'name'  => 'book_now_text',
                    ),
                    4 => array(
                        'title' => __( 'Booking form body background', 'pickybooking' ),
                        'name'  => 'form',
                    ),
                    50 => array(
                        'title' => __( 'Booking title in form (.picky-form-booking-title)', 'pickybooking' ),
                        'name'  => 'booking_title',
                    ),
                    5 => array(
                        'title' => __( 'Large section headings (.picky-input-label-big)', 'pickybooking' ),
                        'name'  => 'big_titles',
                    ),
                    6 => array(
                        'title' => __( 'Field labels (.picky-input-label)', 'pickybooking' ),
                        'name'  => 'small_titles',
                    ),
                    20 => array(
                        'title' => __( 'Hints & consent HTML default color (.picky-small-text, consent label)', 'pickybooking' ),
                        'name'  => 'small_descriptions',
                    ),
                    9 => array(
                        'title' => __( 'Inputs, native selects & Select2 (.picky-tables-wrap …)', 'pickybooking' ),
                        'name'  => 'inputs',
                    ),
                    10 => array(
                        'title' => __( 'Input / select text (same scope as background)', 'pickybooking' ),
                        'name'  => 'inputs_text',
                    ),
                    11 => array(
                        'title' => __( 'Error notice background (.picky-error)', 'pickybooking' ),
                        'name'  => 'error',
                    ),
                    12 => array(
                        'title' => __( 'Error notice text', 'pickybooking' ),
                        'name'  => 'error_text',
                    ),
                    13 => array(
                        'title' => __( 'Info notice background (.picky-message)', 'pickybooking' ),
                        'name'  => 'message',
                    ),
                    14 => array(
                        'title' => __( 'Info notice text', 'pickybooking' ),
                        'name'  => 'message_text',
                    ),
                ),
            ],
        ];

        // Lite: omit Pro settings tabs (payments / SMS / integrations+AI) from the form entirely.
        if ( class_exists( __NAMESPACE__ . '\Edition' ) && Edition::is_lite() ) {
            $field_args = array_values(
                array_filter(
                    $field_args,
                    static function ( $field ) {
                        $tab = isset( $field['tab'] ) ? (string) $field['tab'] : '';
                        return ! in_array( $tab, array( '3', '5', '12' ), true );
                    }
                )
            );
        }

        // Model
        $settings = $this->settings;

        // Controller
        $fields = $this->custom_settings_fields($field_args, $settings);
        $settings_group = $this->settings_group;
        $option_name = INFO::OPTION_NAME;
        $heading = Info::get_plugin_title();
        $plugin_name = Info::get_plugin_title();
        $submit_text = __( 'Save settings', 'pickybooking' );
        $picky_settings_tab_q = $this->picky_request_picky_tab_value();

        // View
        require_once plugin_dir_path(dirname(__FILE__)).'admin/partials/view_settings.php';
    }

    /**
     * Legacy admin menu callback for the hidden picky_layout page — always land in the unified Layouts tab.
     */
    public function render_layout() {
        $layout_id = absint( picky_request_query_value( 'layout_id' ) );
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            wp_safe_redirect( $this->picky_layout_dashboard_url( 0 ) );
            exit;
        }
        wp_safe_redirect( $this->picky_layout_dashboard_url( $layout_id ) );
        exit;
    }

    /**
     * Font family options for the layout builder text element.
     * Includes Site Editor typography presets when available.
     *
     * @return array<string,string>
     */
    private function picky_builder_font_family_options() {
        $options = array(
            'inherit'           => __( 'Theme default (inherit)', 'pickybooking' ),
            'sans-serif'        => __( 'Sans Serif', 'pickybooking' ),
            'serif'             => __( 'Serif', 'pickybooking' ),
            'monospace'         => __( 'Monospace', 'pickybooking' ),
            'cursive'           => __( 'Cursive', 'pickybooking' ),
            'Arial'             => 'Arial',
            'Verdana'           => 'Verdana',
            'Georgia'           => 'Georgia',
            "'Times New Roman'" => 'Times New Roman',
            "'Courier New'"     => 'Courier New',
        );

        if ( function_exists( 'wp_get_global_settings' ) ) {
            $font_families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
            if ( is_array( $font_families ) ) {
                foreach ( $font_families as $group_fonts ) {
                    if ( ! is_array( $group_fonts ) ) {
                        continue;
                    }
                    foreach ( $group_fonts as $font_item ) {
                        if ( ! is_array( $font_item ) ) {
                            continue;
                        }
                        $name = ( isset( $font_item['name'] ) && is_scalar( $font_item['name'] ) )
                            ? trim( (string) $font_item['name'] )
                            : '';
                        $slug = ( isset( $font_item['slug'] ) && is_scalar( $font_item['slug'] ) )
                            ? sanitize_title( (string) $font_item['slug'] )
                            : '';
                        $family = ( isset( $font_item['fontFamily'] ) && is_scalar( $font_item['fontFamily'] ) )
                            ? trim( (string) $font_item['fontFamily'] )
                            : '';
                        if ( $name === '' ) {
                            $name = ( $slug !== '' ) ? $slug : $family;
                        }
                        if ( $name === '' ) {
                            continue;
                        }

                        $value = '';
                        if ( $slug !== '' ) {
                            if ( $family !== '' ) {
                                // Include fallback so admin builder can preview even if preset CSS vars are not loaded there.
                                $value = 'var(--wp--preset--font-family--' . $slug . ', ' . $family . ')';
                            } else {
                                $value = 'var(--wp--preset--font-family--' . $slug . ')';
                            }
                        } elseif ( $family !== '' ) {
                            $value = $family;
                        }

                        if ( $value === '' || isset( $options[ $value ] ) ) {
                            continue;
                        }
                        $options[ $value ] = $name;
                    }
                }
            }
        }

        return $options;
    }

    /**
     * Decode layout JSON and normalize each element (InputSanitization for saves).
     *
     * @param mixed $raw Raw JSON string (or already-decoded array).
     * @return string JSON-encoded sanitized elements (empty string if invalid/empty).
     */
    private function picky_sanitize_layout_position_json( $raw ) {
        if ( is_array( $raw ) ) {
            $data = $raw;
        } else {
            $s = (string) $raw;
            if ( $s === '' ) {
                return '';
            }
            $data = null;
            foreach ( array( $s, rawurldecode( $s ), urldecode( $s ) ) as $candidate ) {
                $decoded = json_decode( (string) $candidate, true );
                if ( is_array( $decoded ) ) {
                    $data = $decoded;
                    break;
                }
            }
            if ( ! is_array( $data ) ) {
                return '';
            }
        }
        $out = array();
        foreach ( $data as $i => $el ) {
            $normalized = $this->picky_normalize_import_element( $el, (int) $i );
            if ( is_array( $normalized ) ) {
                $out[] = $normalized;
            }
        }
        $json = wp_json_encode( $out );
        return is_string( $json ) ? $json : '[]';
    }

    /**
     * Handle form POST from layout builder — save to post meta.
     * Hooked: admin_post_picky_save_layout
     */
    public function save_layout() {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified at start; values sanitized via helpers/allowlists
        $layout_id = isset( $_POST['layout_id'] ) && is_scalar( $_POST['layout_id'] )
            ? absint( wp_unslash( (string) $_POST['layout_id'] ) )
            : 0;
        $layout_nonce = isset( $_POST['picky_layout_nonce'] ) && is_scalar( $_POST['picky_layout_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $layout_nonce, 'picky_save_layout_' . $layout_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'pickybooking' ) );
        }
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            wp_die( esc_html__( 'Invalid layout.', 'pickybooking' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pickybooking' ) );
        }

        // Layout title
        if ( isset( $_POST['picky_layout_title'] ) && is_scalar( $_POST['picky_layout_title'] ) ) {
            $new_title = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_title'] ) );
            if ( $new_title !== '' ) {
                wp_update_post( array( 'ID' => $layout_id, 'post_title' => $new_title ) );
            }
        }

        // Layout position JSON (may be split into chunks by JS when large)
        $position = '';
        if ( isset( $_POST['picky_layout_position'] ) && is_scalar( $_POST['picky_layout_position'] ) ) {
            // JSON (often URL-encoded, sometimes split into chunks). Invalid UTF-8 is dropped here;
            // structure and element fields are validated by picky_sanitize_layout_position_json().
            $position = wp_check_invalid_utf8( wp_unslash( (string) $_POST['picky_layout_position'] ) );
            if ( $position === '__chunked__' && isset( $_POST['picky_layout_chunks'] ) && is_array( $_POST['picky_layout_chunks'] ) ) {
                $chunks = array();
                foreach ( map_deep( wp_unslash( $_POST['picky_layout_chunks'] ), 'wp_check_invalid_utf8' ) as $chunk ) {
                    if ( is_scalar( $chunk ) ) {
                        $chunks[] = wp_check_invalid_utf8( str_replace( "\0", '', (string) $chunk ) );
                    }
                }
                $position = implode( '', $chunks );
            }
        }
        $position = $this->picky_sanitize_layout_position_json( $position );

        // Per-layout canvas dimensions: save whatever the user typed in the builder.
        // Positions are stored as absolute pixels — elements never move when the canvas
        // grows; the canvas simply reveals more empty space around them.
        $posted_settings = isset( $_POST['picky_layout_settings'] ) && is_array( $_POST['picky_layout_settings'] )
            ? map_deep( wp_unslash( $_POST['picky_layout_settings'] ), 'sanitize_text_field' )
            : array();
        $new_canvas_w = isset( $posted_settings['canvas_width'] )  ? absint( $posted_settings['canvas_width'] )  : 0;
        $new_canvas_h = isset( $posted_settings['canvas_height'] ) ? absint( $posted_settings['canvas_height'] ) : 0;
        if ( $new_canvas_w > 0 ) { $new_canvas_w = max( 500, min( 2500, $new_canvas_w ) ); }
        if ( $new_canvas_h > 0 ) { $new_canvas_h = max( 500, min( 2500, $new_canvas_h ) ); }

        // Persist the canvas dimensions per-layout so future opens use the same size.
        if ( $new_canvas_w > 0 ) { update_post_meta( $layout_id, '_picky_layout_canvas_width',  $new_canvas_w ); }
        if ( $new_canvas_h > 0 ) { update_post_meta( $layout_id, '_picky_layout_canvas_height', $new_canvas_h ); }

        update_post_meta( $layout_id, '_picky_layout_position', $position );

        // Background images (stored per-layout in post meta)
        // Accept both current field names and legacy aliases so saving still works
        // even when browser cache serves older admin JS.
        if ( isset( $_POST['picky_background_canvas'] ) && is_scalar( $_POST['picky_background_canvas'] ) ) {
            $raw_canvas = sanitize_text_field( wp_unslash( (string) $_POST['picky_background_canvas'] ) );
            update_post_meta( $layout_id, '_picky_layout_bg_canvas', is_numeric( $raw_canvas ) ? absint( $raw_canvas ) : esc_url_raw( $raw_canvas ) );
        }

        if ( isset( $_POST['picky_background_table'] ) && is_scalar( $_POST['picky_background_table'] ) ) {
            $raw_table = sanitize_text_field( wp_unslash( (string) $_POST['picky_background_table'] ) );
            update_post_meta( $layout_id, '_picky_layout_bg_table', is_numeric( $raw_table ) ? absint( $raw_table ) : esc_url_raw( $raw_table ) );
        }

        $transparent_canvas = ( isset( $_POST['picky_layout_transparent_canvas'] ) && is_scalar( $_POST['picky_layout_transparent_canvas'] ) && sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_transparent_canvas'] ) ) === '1' ) ? '1' : '0';
        update_post_meta( $layout_id, '_picky_layout_transparent_canvas', $transparent_canvas );

        if ( isset( $_POST['picky_layout_bg_canvas_mode'] ) && is_scalar( $_POST['picky_layout_bg_canvas_mode'] ) ) {
            $raw_canvas_mode = sanitize_key( wp_unslash( (string) $_POST['picky_layout_bg_canvas_mode'] ) );
            $allowed_modes   = array( 'cover', 'contain', 'stretch', 'repeat', 'repeat-x', 'repeat-y', 'center' );
            if ( in_array( $raw_canvas_mode, $allowed_modes, true ) ) {
                update_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', $raw_canvas_mode );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_bg_canvas_mode' );
            }
        }

        if ( isset( $_POST['picky_layout_bg_canvas_color'] ) && is_scalar( $_POST['picky_layout_bg_canvas_color'] ) ) {
            $raw_canvas_color = picky_sanitize_hex_color( sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_bg_canvas_color'] ) ) );
            if ( is_string( $raw_canvas_color ) && $raw_canvas_color !== '' ) {
                update_post_meta( $layout_id, '_picky_layout_bg_canvas_color', $raw_canvas_color );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_bg_canvas_color' );
            }
        }

        if ( isset( $_POST['picky_layout_bg_table_color'] ) && is_scalar( $_POST['picky_layout_bg_table_color'] ) ) {
            $raw_table_color = picky_sanitize_hex_color( sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_bg_table_color'] ) ) );
            if ( is_string( $raw_table_color ) && $raw_table_color !== '' ) {
                update_post_meta( $layout_id, '_picky_layout_bg_table_color', $raw_table_color );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_bg_table_color' );
            }
        }

        if ( isset( $_POST['picky_layout_booking_item_icon'] ) && is_scalar( $_POST['picky_layout_booking_item_icon'] ) ) {
            $raw_item_icon = Booking_Icons::sanitize( sanitize_key( wp_unslash( (string) $_POST['picky_layout_booking_item_icon'] ) ) );
            if ( $raw_item_icon !== '' ) {
                update_post_meta( $layout_id, Booking_Icons::META_KEY, $raw_item_icon );
            } else {
                delete_post_meta( $layout_id, Booking_Icons::META_KEY );
            }
        }

        delete_post_meta( $layout_id, '_picky_layout_show_people' );
        if ( isset( $_POST['picky_layout_show_seats'] ) && is_scalar( $_POST['picky_layout_show_seats'] ) ) {
            $raw_badge = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_show_seats'] ) );
            if ( $raw_badge === '0' || $raw_badge === '1' ) {
                update_post_meta( $layout_id, '_picky_layout_show_seats', $raw_badge );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_show_seats' );
            }
        }
        if ( isset( $_POST['picky_layout_show_ids'] ) && is_scalar( $_POST['picky_layout_show_ids'] ) ) {
            $raw_ids = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_show_ids'] ) );
            if ( $raw_ids === '0' || $raw_ids === '1' ) {
                update_post_meta( $layout_id, '_picky_layout_show_ids', $raw_ids );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_show_ids' );
            }
        }
        if ( isset( $_POST['picky_layout_show_labels'] ) && is_scalar( $_POST['picky_layout_show_labels'] ) ) {
            $raw_labels = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_show_labels'] ) );
            if ( $raw_labels === '0' || $raw_labels === '1' ) {
                update_post_meta( $layout_id, '_picky_layout_show_labels', $raw_labels );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_show_labels' );
            }
        }
        if ( isset( $_POST['picky_layout_show_item_fill'] ) && is_scalar( $_POST['picky_layout_show_item_fill'] ) ) {
            $raw_item_fill = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_show_item_fill'] ) );
            if ( $raw_item_fill === '0' ) {
                update_post_meta( $layout_id, '_picky_layout_show_item_fill', '0' );
            } else {
                // Default is show — no need to store an explicit "1".
                delete_post_meta( $layout_id, '_picky_layout_show_item_fill' );
            }
        }
        if ( isset( $_POST['picky_layout_show_booked_avatars'] ) && is_scalar( $_POST['picky_layout_show_booked_avatars'] ) ) {
            $raw_avatars = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_show_booked_avatars'] ) );
            if ( $raw_avatars === '1' ) {
                update_post_meta( $layout_id, '_picky_layout_show_booked_avatars', '1' );
            } else {
                update_post_meta( $layout_id, '_picky_layout_show_booked_avatars', '0' );
            }
        }
        if ( isset( $_POST['picky_layout_booked_avatars_tone'] ) && is_scalar( $_POST['picky_layout_booked_avatars_tone'] ) ) {
            $raw_tone = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_booked_avatars_tone'] ) );
            if ( $raw_tone === 'bw' ) {
                update_post_meta( $layout_id, '_picky_layout_booked_avatars_tone', 'bw' );
            } else {
                update_post_meta( $layout_id, '_picky_layout_booked_avatars_tone', 'color' );
            }
        }
        if ( isset( $_POST['picky_layout_shortcode_layout'] ) && is_scalar( $_POST['picky_layout_shortcode_layout'] ) ) {
            $raw_wizard = sanitize_text_field( wp_unslash( (string) $_POST['picky_layout_shortcode_layout'] ) );
            if ( in_array( $raw_wizard, array( '1', '2', '3', '4' ), true ) ) {
                update_post_meta( $layout_id, '_picky_layout_shortcode_layout', $raw_wizard );
            } else {
                delete_post_meta( $layout_id, '_picky_layout_shortcode_layout' );
            }
        }

        // Services library (posted as JSON string from builder JS)
        if ( isset( $_POST['picky_layout_services_library'] ) && is_scalar( $_POST['picky_layout_services_library'] ) ) {
            $raw_library = json_decode( sanitize_textarea_field( wp_unslash( (string) $_POST['picky_layout_services_library'] ) ), true );
            if ( ! is_array( $raw_library ) ) {
                $raw_library = array();
            }
            $library = $this->picky_sanitize_services_library( is_array( $raw_library ) ? $raw_library : array() );
            update_post_meta( $layout_id, '_picky_layout_services_library', wp_json_encode( $library ) );
        }

        // Multi-select toggle (optional hidden 0 + checkbox 1 both post; last value wins).
        $posted_ms = isset( $_POST['picky_layout_multi_select'] ) ? map_deep( wp_unslash( $_POST['picky_layout_multi_select'] ), 'sanitize_text_field' ) : '0';
        if ( is_array( $posted_ms ) ) {
            $last_ms   = end( $posted_ms );
            $posted_ms = is_scalar( $last_ms ) ? sanitize_text_field( (string) $last_ms ) : '0';
        }
        $multi_select = ( is_scalar( $posted_ms ) && (string) $posted_ms === '1' ) ? '1' : '0';
        update_post_meta( $layout_id, '_picky_layout_multi_select', $multi_select );

        $posted_event_mode = isset( $_POST['picky_layout_event_mode'] ) ? map_deep( wp_unslash( $_POST['picky_layout_event_mode'] ), 'sanitize_text_field' ) : 'calendar';
        if ( is_array( $posted_event_mode ) ) {
            $last_mode         = end( $posted_event_mode );
            $posted_event_mode = is_scalar( $last_mode ) ? sanitize_text_field( (string) $last_mode ) : '';
        }
        $event_mode = ( is_scalar( $posted_event_mode ) && sanitize_key( (string) $posted_event_mode ) === 'once' ) ? 'once' : 'calendar';
        $posted_event_date = isset( $_POST['picky_layout_event_date'] ) ? map_deep( wp_unslash( $_POST['picky_layout_event_date'] ), 'sanitize_text_field' ) : '';
        if ( is_array( $posted_event_date ) ) {
            $last_date         = end( $posted_event_date );
            $posted_event_date = is_scalar( $last_date ) ? sanitize_text_field( (string) $last_date ) : '';
        }
        $event_date = is_scalar( $posted_event_date ) ? picky_normalize_booking_date( sanitize_text_field( (string) $posted_event_date ) ) : '';
        $posted_event_time = isset( $_POST['picky_layout_event_time'] ) ? map_deep( wp_unslash( $_POST['picky_layout_event_time'] ), 'sanitize_text_field' ) : '';
        if ( is_array( $posted_event_time ) ) {
            $last_time         = end( $posted_event_time );
            $posted_event_time = is_scalar( $last_time ) ? sanitize_text_field( (string) $last_time ) : '';
        }
        $event_time = is_scalar( $posted_event_time ) ? picky_normalize_booking_time( sanitize_text_field( (string) $posted_event_time ) ) : '';
        if ( $event_mode === 'once' && ( $event_date === '' || $event_time === '' ) ) {
            $event_mode = 'calendar';
        }
        update_post_meta( $layout_id, '_picky_layout_event_mode', $event_mode );
        if ( $event_date !== '' ) {
            update_post_meta( $layout_id, '_picky_layout_event_date', $event_date );
        } else {
            delete_post_meta( $layout_id, '_picky_layout_event_date' );
        }
        if ( $event_time !== '' ) {
            update_post_meta( $layout_id, '_picky_layout_event_time', $event_time );
        } else {
            delete_post_meta( $layout_id, '_picky_layout_event_time' );
        }
        if ( isset( $_POST['picky_layout_event_show_label'] ) ) {
            $raw_show_label = map_deep( wp_unslash( $_POST['picky_layout_event_show_label'] ), 'sanitize_text_field' );
            if ( is_array( $raw_show_label ) ) {
                $last_label     = end( $raw_show_label );
                $raw_show_label = is_scalar( $last_label ) ? sanitize_text_field( (string) $last_label ) : '';
            }
            $show_label = ( is_scalar( $raw_show_label ) && (string) $raw_show_label === '1' ) ? '1' : '0';
        } else {
            $existing   = (string) get_post_meta( $layout_id, '_picky_layout_event_show_label', true );
            $show_label = ( $existing === '0' ) ? '0' : '1';
        }
        update_post_meta( $layout_id, '_picky_layout_event_show_label', $show_label );

        // Fresh list thumbnail: keep a just-uploaded raster; otherwise drop stale file
        // so the gallery falls back to the freshly regenerated SVG snapshot.
        if ( isset( $_POST['picky_layout_preview'] ) && is_string( $_POST['picky_layout_preview'] ) ) {
            \Picky\Layout_Preview::save_screenshot_data_uri( $layout_id, wp_check_invalid_utf8( wp_unslash( $_POST['picky_layout_preview'] ) ) );
        }
        \Picky\Layout_Preview::refresh_svg_meta( $layout_id );
        if ( ! \Picky\Layout_Preview::consume_raster_fresh( $layout_id ) ) {
            \Picky\Layout_Preview::clear_attachment( $layout_id );
            // Prefer a real media URL when the host allows SVG uploads; otherwise data-URI fallback.
            \Picky\Layout_Preview::publish_svg_attachment( $layout_id );
        }

        $this->picky_set_layout_notice( 'saved' );
        wp_safe_redirect( $this->picky_layout_dashboard_url( (int) $layout_id ) );
        exit;
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    }

    /**
     * AJAX: store a layout list thumbnail (JPEG/PNG upload) before the main save POST.
     *
     * @return void
     */
    public function ajax_save_layout_preview() {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified; preview sanitized before save
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'pickybooking' ) ), 403 );
        }
        $layout_id = isset( $_POST['layout_id'] ) && is_scalar( $_POST['layout_id'] )
            ? absint( wp_unslash( (string) $_POST['layout_id'] ) )
            : 0;
        $nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, 'picky_save_layout_preview_' . $layout_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'pickybooking' ) ), 403 );
        }
        if ( ! $layout_id || get_post_type( $layout_id ) !== 'picky_layout' ) {
            wp_send_json_error( array( 'message' => __( 'Invalid layout.', 'pickybooking' ) ), 400 );
        }

        $aid = 0;
        if ( ! empty( $_FILES['preview'] ) && is_array( $_FILES['preview'] ) ) {
            $upload     = $_FILES['preview'];
            $clean_file = array(
                'name'     => isset( $upload['name'] ) && is_scalar( $upload['name'] ) ? sanitize_file_name( wp_unslash( (string) $upload['name'] ) ) : '',
                'type'     => isset( $upload['type'] ) && is_scalar( $upload['type'] ) ? sanitize_mime_type( (string) $upload['type'] ) : '',
                'tmp_name' => isset( $upload['tmp_name'] ) && is_scalar( $upload['tmp_name'] ) ? (string) $upload['tmp_name'] : '',
                'error'    => isset( $upload['error'] ) ? absint( $upload['error'] ) : UPLOAD_ERR_NO_FILE,
                'size'     => isset( $upload['size'] ) ? absint( $upload['size'] ) : 0,
            );
            $aid = \Picky\Layout_Preview::save_screenshot_upload( $layout_id, $clean_file );
        } elseif ( isset( $_POST['preview_data'] ) && is_string( $_POST['preview_data'] ) ) {
            $aid = \Picky\Layout_Preview::save_screenshot_data_uri( $layout_id, wp_check_invalid_utf8( wp_unslash( $_POST['preview_data'] ) ) );
        }

        if ( $aid <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Could not save preview.', 'pickybooking' ) ) );
        }
        wp_send_json_success(
            array(
                'attachment_id' => $aid,
                'url'           => \Picky\Layout_Preview::url_for_layout( $layout_id ),
            )
        );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    public function ajax_test_integration() {
        wp_send_json_error( array( 'message' => __( 'Not available.', 'pickybooking' ) ), 404 );
    }
}
