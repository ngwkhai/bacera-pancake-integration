<?php
class Bacera_Module_Webhooks {
    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_routes' ] );
    }

    public static function register_webhook_routes() {
        register_rest_route( 'bacera/v1', '/pancake-webhook', [
            'methods'  => 'POST',
            'callback' => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true' // Cần thêm logic check signature để bảo mật
        ]);
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        
        // Xử lý dữ liệu trả về từ Pancake (Cập nhật tồn kho, đổi trạng thái đơn...)
        Bacera_Utils::log_error('Nhận Webhook từ Pancake: ' . wp_json_encode($data));

        return new WP_REST_Response( ['status' => 'success'], 200 );
    }
}