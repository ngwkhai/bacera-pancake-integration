<?php
/**
 * Bacera Webhooks Module
 * Xử lý các tín hiệu từ Pancake POS để đồng bộ dữ liệu sản phẩm và đơn hàng.
 */

class Bacera_Module_Webhooks {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_routes' ] );
    }

    /**
     * Đăng ký endpoint để nhận Webhook từ Pancake POS
     * Vị trí cài đặt trên Pancake: Setting -> Advance -> Third-party connection -> Webhook [cite: 359]
     */
    public static function register_webhook_routes() {
        register_rest_route( 'bacera/v1', '/pancake-webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true' // Lưu ý: Nên bổ sung logic kiểm tra Signature/Token để bảo mật
        ]);
    }

    /**
     * Điều hướng xử lý dựa trên loại dữ liệu nhận được
     */
    public static function handle_webhook( WP_REST_Request $request ) {
        $data = $request->get_json_params();

        // Ghi log để kiểm tra payload thực tế từ Pancake
        Bacera_Utils::log_error( 'Nhận Webhook từ Pancake: ' . wp_json_encode( $data ) );

        if ( empty( $data ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Empty payload' ], 400 );
        }

        /**
         * GIAI ĐOẠN 5: TỰ ĐỘNG DỌN RÁC
         * Pancake POS gửi thông báo khi sản phẩm bị xóa hoặc cập nhật trạng thái [cite: 446, 510]
         */
        if ( isset( $data['type'] ) && $data['type'] === 'product.deleted' ) {
            self::handle_pancake_delete_webhook( $data );
        }

        return new WP_REST_Response( [ 'status' => 'success' ], 200 );
    }

    /**
     * Logic xóa sản phẩm tương ứng trong WordPress
     */
    public static function handle_pancake_delete_webhook( $data ) {
        // ID sản phẩm từ Pancake POS [cite: 446, 451]
        $pancake_id = isset( $data['id'] ) ? $data['id'] : ( isset( $data['product_id'] ) ? $data['product_id'] : null );

        if ( ! $pancake_id ) {
            return;
        }

        // Tìm Post ID tương ứng trong database qua meta_key _pancake_id
        $posts = get_posts([
            'post_type'      => 'pancake_product', // Định dạng post type đã thống nhất ở các giai đoạn trước
            'meta_key'       => '_pancake_id',
            'meta_value'     => $pancake_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any'
        ]);

        if ( ! empty( $posts ) ) {
            foreach ( $posts as $id ) {
                /**
                 * wp_delete_post($id, true) sẽ xóa vĩnh viễn (bypass trash).
                 * Các metadata liên quan sẽ tự động được xóa bởi WordPress Core.
                 * Vì Bacera chỉ lưu URL ảnh meta, không cần xử lý xóa file vật lý[cite: 63, 254].
                 */
                wp_delete_post( $id, true );
                Bacera_Utils::log_error( "Đã dọn dẹp thành công sản phẩm ID: $id (Pancake ID tương ứng: $pancake_id)" );
            }
        }
    }
}

// Khởi tạo module
Bacera_Module_Webhooks::init();