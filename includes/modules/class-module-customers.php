<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Module_Customers {

    public static function init() {
        // Hook 1: Kích hoạt khi có người dùng mới tạo tài khoản
        add_action( 'user_register', [ __CLASS__, 'sync_new_customer' ] );
        
        // Hook 2: Kích hoạt khi người dùng thay đổi thông tin cá nhân cơ bản
        add_action( 'profile_update', [ __CLASS__, 'update_existing_customer' ], 10, 2 );
        
        // Hook 3: Kích hoạt khi người dùng thay đổi địa chỉ thanh toán (SĐT/Tên) trong WooCommerce
        add_action( 'woocommerce_customer_save_address', [ __CLASS__, 'update_woo_customer_address' ], 10, 2 );
    }

    /**
     * ========================================================================
     * 1. KIỂM TRA & LẤY DANH SÁCH KHÁCH HÀNG (GET /shops/{SHOP_ID}/customers)
     * ========================================================================
     * Dùng để tìm kiếm kiểm tra xem khách đã tồn tại trên POS chưa.
     */
    public static function get_customers( $args = [] ) {
        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/customers';

        if ( ! empty( $args ) ) {
            $endpoint .= '?' . http_build_query( $args );
        }

        return $api->request( $endpoint, 'GET' );
    }

    /**
     * ========================================================================
     * 2. TẠO KHÁCH HÀNG MỚI (POST /shops/{SHOP_ID}/customers)
     * ========================================================================
     * Chạy tự động khi user đăng ký tài khoản mới trên Website.
     */
    public static function sync_new_customer( $user_id ) {
        $user_info = get_userdata( $user_id );
        if ( ! $user_info ) return;

        // Lấy số điện thoại (nếu dùng WooCommerce thì thường lưu ở trường billing_phone)
        $phone = get_user_meta( $user_id, 'billing_phone', true );

        $api = new Pancake_API_Client();

        // BƯỚC 1: Kiểm tra xem khách hàng đã tồn tại trên Pancake POS chưa 
        // Tránh tạo ra 2 khách hàng trùng lặp trên POS nếu họ đã từng nhắn tin mua qua Fanpage
        $existing_customers = self::get_customers( ['phone_number' => $phone] ); 
        
        if ( isset( $existing_customers['success'] ) && $existing_customers['success'] === true && !empty( $existing_customers['data'] ) ) {
            // Khách đã tồn tại -> Chỉ cần lấy ID từ POS và lưu vào Web, KHÔNG GỌI TẠO MỚI NỮA
            $pancake_customer_id = $existing_customers['data'][0]['id'];
            update_user_meta( $user_id, '_pancake_customer_id', $pancake_customer_id );
            return;
        }

        // BƯỚC 2: Nếu chưa tồn tại, chuẩn bị dữ liệu để tạo mới
        $customer_data = [
            'name'         => $user_info->display_name ?: $user_info->user_login,
            'email'        => $user_info->user_email,
            'phone_number' => $phone,
        ];

        // Gửi API tạo khách hàng lên POS
        $response = $api->request( '/shops/{SHOP_ID}/customers', 'POST', $customer_data );

        // BƯỚC 3: Xử lý và lưu lại ID Pancake POS trả về
        if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
            if ( isset( $response['data']['id'] ) ) {
                // Lưu ID khách hàng POS cấp vào Database của WordPress
                update_user_meta( $user_id, '_pancake_customer_id', $response['data']['id'] );
            }
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Đã TẠO MỚI khách hàng trên POS: ' . $customer_data['email'] );
            }
        } else {
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Lỗi tạo khách hàng POS: ' . wp_json_encode( $response ) );
            }
        }
    }

    /**
     * ========================================================================
     * 3. CẬP NHẬT THÔNG TIN KHÁCH (PUT /shops/{SHOP_ID}/customers/{CUSTOMER_ID})
     * ========================================================================
     * Chạy khi khách hàng cập nhật hồ sơ cá nhân trên Web.
     */
    public static function update_existing_customer( $user_id, $old_user_data = null ) {
        // Lấy Pancake Customer ID đã được lưu từ trước
        $pancake_customer_id = get_user_meta( $user_id, '_pancake_customer_id', true );

        // Nếu người dùng này chưa có ID trên Pancake (có thể do lỗi cũ), thì gọi hàm Tạo mới
        if ( empty( $pancake_customer_id ) ) {
            self::sync_new_customer( $user_id );
            return;
        }

        $user_info = get_userdata( $user_id );
        $phone = get_user_meta( $user_id, 'billing_phone', true );

        // Chuẩn bị dữ liệu cập nhật
        $customer_data = [
            'name'         => $user_info->display_name,
            'email'        => $user_info->user_email,
            'phone_number' => $phone,
        ];

        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/customers/' . urlencode( $pancake_customer_id );
        
        // Gửi API cập nhật
        $response = $api->request( $endpoint, 'PUT', $customer_data );

        if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Đã CẬP NHẬT khách hàng trên POS: ' . $customer_data['email'] );
            }
        } else {
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Lỗi cập nhật khách hàng POS: ' . wp_json_encode( $response ) );
            }
        }
    }

    /**
     * Hàm trung gian: Kích hoạt khi khách hàng cập nhật Address trong WooCommerce
     */
    public static function update_woo_customer_address( $user_id, $load_address ) {
        self::update_existing_customer( $user_id );
    }
}