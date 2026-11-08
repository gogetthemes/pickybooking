<?php

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The main plugin class (Lite build).
 */
class Plugin
{

    private $loader;
    private $plugin_slug;
    private $version;
    private $option_name;

    public function __construct() {
        $this->plugin_slug = Info::SLUG;
        $this->version     = Info::VERSION;
        $this->option_name = Info::OPTION_NAME;
        $this->load_dependencies();
        $this->define_upgrade_hooks();
        $this->define_public_hooks();
        $this->define_admin_hooks();
        $this->define_frontend_hooks();
    }

    /**
     * Runs after dependencies load: one-time data migrations (options).
     */
    private function define_upgrade_hooks() {
        $this->loader->add_action( 'plugins_loaded', $this, 'maybe_migrate_color_defaults', 5 );
        // Elementor / Pro integrations intentionally omitted in Lite (WP.org freemium: no locked code).
    }

    /**
     * Applies revised default colors when the stored palette still matches pre-revision factory values.
     */
    public function maybe_migrate_color_defaults() {
        Color_Defaults_Migration::apply();
    }

    private function load_dependencies() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-loader.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-booking-avatar.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-color-defaults-migration.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-layout-preview.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-layout-library.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-booking-icons.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-public.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'frontend/class-frontend.php';

        $this->loader = new Loader();
    }

    private function define_public_hooks() {
        $plugin_public = new Public_Plugin( $this->plugin_slug, $this->version, $this->option_name );

        $this->loader->add_action('init', $plugin_public, 'picky_custom_post_status');

        $this->loader->add_action('admin_footer-post-new.php', $plugin_public, 'true_append_post_status_list');
        $this->loader->add_action('admin_footer-post.php', $plugin_public, 'true_append_post_status_list');
        $this->loader->add_action('admin_footer-edit.php', $plugin_public, 'true_append_status');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_public, 'hide_publishing_actions');

        $this->loader->add_filter('wp_insert_post_data', $plugin_public, 'example_insert_using_custom_status', 10, 2 );
        $this->loader->add_filter('display_post_states', $plugin_public, 'picky_display_archive_state', 10, 2 );

        $this->loader->add_action( 'init', $plugin_public, 'register_post_types' );
        $this->loader->add_action( 'init', $plugin_public, 'register_shortcodes' );

        $this->loader->add_action( 'save_post_picky_booking', $plugin_public, 'save_metadata' );
        $this->loader->add_action( 'save_post_picky_order', $plugin_public, 'save_order_metadata' );
        $this->loader->add_action( 'load-post.php', $plugin_public, 'register_post_meta' );
        $this->loader->add_action( 'load-post-new.php', $plugin_public, 'register_post_meta' );
    }

    private function define_admin_hooks() {
        if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        $plugin_admin = new Admin($this->plugin_slug, $this->version, $this->option_name);

        $this->loader->add_action('vc_before_init', $plugin_admin, 'vc_infobox_mapping');

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'assets');
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_maybe_redirect_legacy_admin_urls', 1 );
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_redirect_standalone_pages_to_settings', 2 );
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_redirect_standalone_layout_builder_to_settings', 3 );
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_deduplicate_settings_screen_query', 4 );
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_normalize_settings_layout_url', 5 );
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');
        $this->loader->add_action('admin_init', $plugin_admin, 'post_type_columns');
        $this->loader->add_action('admin_init', $plugin_admin, 'picky_maybe_bulk_migrate_layouts', 9);
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_menus');
        $this->loader->add_action('restrict_manage_posts', $plugin_admin, 'render_picky_admin_filters');
        $this->loader->add_filter('views_edit-picky_booking', $plugin_admin, 'picky_layout_views');
        $this->loader->add_filter('post_class', $plugin_admin, 'picky_post_row_new_class', 10, 3);
        $this->loader->add_action('admin_notices', $plugin_admin, 'render_new_booking_notice');
        $this->loader->add_action('load-edit.php', $plugin_admin, 'maybe_mark_picky_bookings_seen');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'print_admin_menu_css');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'print_picky_new_row_css');

        $this->loader->add_action('load-post-new.php', $plugin_admin, 'redirect_new_layout');
        $this->loader->add_action('load-post.php',     $plugin_admin, 'redirect_edit_layout');
        $this->loader->add_action('load-post.php',     $plugin_admin, 'redirect_edit_order');
        $this->loader->add_action('load-post.php',     $plugin_admin, 'redirect_edit_booking');

        $this->loader->add_filter('manage_picky_layout_posts_columns',        $plugin_admin, 'picky_layout_columns');
        $this->loader->add_action('manage_picky_layout_posts_custom_column',   $plugin_admin, 'picky_layout_column_content', 10, 2);
        $this->loader->add_filter('post_row_actions',                        $plugin_admin, 'picky_layout_row_actions', 10, 2);
        $this->loader->add_filter('post_row_actions',                        $plugin_admin, 'picky_order_row_actions', 10, 2);

        $this->loader->add_filter('the_title', $plugin_admin, 'picky_order_list_title', 10, 2);
        $this->loader->add_filter('get_edit_post_link', $plugin_admin, 'picky_order_filter_edit_post_link', 10, 3);
        $this->loader->add_filter('get_edit_post_link', $plugin_admin, 'picky_booking_filter_edit_post_link', 10, 3);
        $this->loader->add_filter('get_edit_post_link', $plugin_admin, 'picky_layout_filter_edit_post_link', 10, 3);

        $this->loader->add_action('admin_post_picky_save_layout', $plugin_admin, 'save_layout');
        $this->loader->add_action('wp_ajax_picky_save_layout_preview', $plugin_admin, 'ajax_save_layout_preview');
        // Import/export JSON is Pro-only — library preset import stays available for getting started.
        $this->loader->add_action('admin_post_picky_library_import', $plugin_admin, 'library_import');
        $this->loader->add_action('admin_post_picky_delete_layout', $plugin_admin, 'delete_layout');
        $this->loader->add_action('admin_post_picky_duplicate_layout', $plugin_admin, 'duplicate_layout');

        $this->loader->add_action('admin_post_picky_save_order_embedded', $plugin_admin, 'save_order_embedded');
        $this->loader->add_action('admin_post_picky_save_booking_embedded', $plugin_admin, 'save_booking_embedded');

        $this->loader->add_action('wp_ajax_picky_save_booking_status', $plugin_admin, 'ajax_save_booking_status');
        $this->loader->add_action('wp_ajax_picky_save_order_status', $plugin_admin, 'ajax_save_order_status');
    }

    private function define_frontend_hooks() {
        $plugin_frontend = new Frontend($this->plugin_slug, $this->version, $this->option_name);
        $this->loader->add_action('wp_ajax_picky_send_book', $plugin_frontend, 'send_book');
        $this->loader->add_action('wp_ajax_nopriv_picky_send_book', $plugin_frontend, 'send_book');
        $this->loader->add_action('wp_ajax_picky_get_available_tables', $plugin_frontend, 'get_available_tables');
        $this->loader->add_action('wp_ajax_nopriv_picky_get_available_tables', $plugin_frontend, 'get_available_tables');
        $this->loader->add_action('wp_ajax_picky_interval_slot_hints', $plugin_frontend, 'interval_slot_hints');
        $this->loader->add_action('wp_ajax_nopriv_picky_interval_slot_hints', $plugin_frontend, 'interval_slot_hints');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_frontend, 'assets');
        $this->loader->add_action('wp_footer', $plugin_frontend, 'render');
        // Stripe / WooCommerce payment hooks omitted in Lite.
    }

    public function run() {
        $this->loader->run();
    }
}
