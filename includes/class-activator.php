<?php
namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class defines all code necessary to run during the plugin's activation.
 */
class Activator
{
    /**
     * Sets the default options in the options table on activation.
     *
     * Initializes and normalizes settings on activation.
     */
    public static function activate() {
        $option_name        = Info::OPTION_NAME;
        $legacy_option_name = Info::LEGACY_OPTION_NAME;
        $default_options    = Info::$DEFAULT_OPTIONS;

        $current = get_option( $option_name );

        // If the new key is missing but the legacy key exists, hydrate from it.
        if ( ( $current === false || $current === '' ) && $legacy_option_name && $legacy_option_name !== $option_name ) {
            $legacy = get_option( $legacy_option_name );
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                $current = $legacy;
            }
        }

        if ( empty( $current ) ) {
            update_option( $option_name, $default_options );
            return;
        }

        if ( ! is_array( $current ) ) {
            $current = array();
        }

        // Merge stored values with defaults so newly introduced keys gain sane values.
        $new_options = array();
        foreach ( $default_options as $key => $value ) {
            $new_options[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : $value;
        }
        if ( isset( $new_options['shortcode_layout'] ) && class_exists( __NAMESPACE__ . '\\Edition' ) ) {
            $new_options['shortcode_layout'] = Edition::clamp_wizard_layout( (string) $new_options['shortcode_layout'] );
        }
        update_option( $option_name, $new_options );

        self::migrate_lite_wizard_layouts();
    }

    /**
     * Lite: collapse Smart / Smart Map settings and layout overrides to Classic.
     *
     * @return void
     */
    public static function migrate_lite_wizard_layouts() {
        if ( ! class_exists( __NAMESPACE__ . '\\Edition' ) || ! Edition::is_lite() ) {
            return;
        }
        if ( get_option( 'picky_lite_wizard_migrated', '' ) === '1' ) {
            return;
        }

        $opts = get_option( Info::OPTION_NAME );
        if ( is_array( $opts ) && isset( $opts['shortcode_layout'] ) ) {
            $clamped = Edition::clamp_wizard_layout( (string) $opts['shortcode_layout'] );
            if ( (string) $opts['shortcode_layout'] !== $clamped ) {
                $opts['shortcode_layout'] = $clamped;
                update_option( Info::OPTION_NAME, $opts );
            }
        }

        $layouts = get_posts(
            array(
                'post_type'              => 'picky_layout',
                'post_status'            => 'any',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );
        foreach ( $layouts as $lid ) {
            $raw = (string) get_post_meta( (int) $lid, '_picky_layout_shortcode_layout', true );
            if ( $raw === '2' || $raw === '4' ) {
                update_post_meta( (int) $lid, '_picky_layout_shortcode_layout', Edition::clamp_wizard_layout( $raw ) );
            }
        }

        update_option( 'picky_lite_wizard_migrated', '1', false );
    }
}
