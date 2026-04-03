<?php
class Bacera_Admin_Settings {
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_admin_menu() {
        add_menu_page(
            'Bacera Pancake POS',
            'Pancake POS',
            'manage_options',
            'bacera-pancake-settings',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-store',
            56
        );
    }

    public static function register_settings() {
        register_setting( 'bacera_pancake_options_group', 'bacera_pancake_api_key' );
        register_setting( 'bacera_pancake_options_group', 'bacera_pancake_shop_id' );
    }

    public static function render_settings_page() {
        require_once BACERA_PANCAKE_DIR . 'includes/admin/views/settings-page.php';
    }
}