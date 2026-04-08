<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Setup {

    /**
     * Khởi tạo các hooks và đăng ký logic cốt lõi cho hệ thống Bacera.
     */
    public static function init() {
        // 1. Đăng ký đường dẫn ảo cho ảnh để đạt nhu cầu chuẩn SEO đường dẫn sạch.
        add_action( 'init', [ __CLASS__, 'register_image_rewrite_rules' ] );

        // 2. Thêm biến query để WordPress có thể "hiểu" và bóc tách slug từ URL.
        add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );

        // 3. Kích hoạt logic trung chuyển ảnh (Streaming Proxy) khi khách hàng truy cập đường dẫn ảo[cite: 218, 356].
        // Logic này sẽ chặn các request vào đường dẫn ảo và bơm dữ liệu ảnh từ Pancake POS trả về[cite: 354].
        add_action( 'template_redirect', [ 'Bacera_Utils', 'handle_image_streaming' ] );
    }

    /**
     * Thiết lập Rewrite Rule cho thư mục ảnh ảo /pancake-img/
     * * Quy tắc này sẽ chuyển hướng ngầm: 
     * bacera.vn/pancake-img/ly-gom-men-tro.jpg -> index.php?pancake_img_slug=ly-gom-men-tro
     * Giúp ẩn hoàn toàn sự hiện diện của Pancake POS, tối ưu uy tín thương hiệu và SEO[cite: 21, 218].
     */
    public static function register_image_rewrite_rules() {
        add_rewrite_rule(
            '^pancake-img/([^/]+)\.(jpg|png|jpeg|webp)$',
            'index.php?pancake_img_slug=$matches[1]',
            'top'
        );
    }

    /**
     * Đăng ký biến 'pancake_img_slug' vào danh sách biến truy vấn hợp lệ của WordPress.
     */
    public static function add_query_vars( $vars ) {
        $vars[] = 'pancake_img_slug';
        return $vars;
    }

    /**
     * Chạy khi kích hoạt plugin (Activation Hook).
     * Quan trọng: Phải flush rewrite rules để WordPress cập nhật lại database cấu trúc đường dẫn sạch.
     */
    public static function activate() {
        // Đăng ký quy tắc ngay lập tức trước khi flush để đảm bảo rule có hiệu lực ngay
        self::register_image_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Chạy khi hủy kích hoạt plugin (Deactivation Hook).
     * Dọn dẹp cấu trúc đường dẫn để trả về trạng thái mặc định cho WordPress.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}