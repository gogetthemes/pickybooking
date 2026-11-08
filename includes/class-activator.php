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
        update_option( $option_name, $new_options );
    }
}
