<?php
/**
 * Plugin Name: Bacera Pancake POS Integration
 * Description: Tích hợp hệ thống đồng bộ API giữa Bacera Web (Sản phẩm, Đơn hàng, Workshop) và Pancake POS.
 * Version: 1.0.0
 * Author: Bacera Team
 * Text Domain: bacera-pancake
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'BACERA_PANCAKE_VERSION', '1.0.0' );
define( 'BACERA_PANCAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BACERA_PANCAKE_URL', plugin_dir_url( __FILE__ ) );

// Autoloader đơn giản để nạp các file (Có thể thay bằng Composer PSR-4 sau này)
$files_to_include = [
    'includes/helpers/class-bacera-utils.php',
    'includes/core/class-pancake-api-client.php',
    'includes/core/class-bacera-setup.php',
    'includes/admin/class-bacera-admin-settings.php',
    'includes/modules/class-module-products.php',
    'includes/modules/class-module-orders.php',
    'includes/modules/class-module-geo.php',
    'includes/modules/class-module-shops.php',
    'includes/modules/class-module-customers.php',
    'includes/modules/class-module-webhooks.php',
];

foreach ( $files_to_include as $file ) {
    if ( file_exists( BACERA_PANCAKE_DIR . $file ) ) {
        require_once BACERA_PANCAKE_DIR . $file;
    }
}

// Khởi tạo Plugin
function bacera_pancake_init() {
    Bacera_Setup::init();
    Bacera_Admin_Settings::init();
    Bacera_Module_Products::init();
    Bacera_Module_Orders::init();
    Bacera_Module_Geo::init();
    Bacera_Module_Shops::init();
    Bacera_Module_Customers::init();
    Bacera_Module_Webhooks::init();
}
add_action( 'plugins_loaded', 'bacera_pancake_init' );

// Đăng ký hook kích hoạt
register_activation_hook( __FILE__, ['Bacera_Setup', 'activate'] );