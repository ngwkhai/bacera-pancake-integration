<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Module_Orders {

    public static function init() {
        // Hook chạy khi khách hàng thanh toán thành công đơn hàng trên Website (Shop gốm)
        add_action( 'woocommerce_thankyou', [ __CLASS__, 'push_order_to_pancake' ] );
        
        // Hook cho form booking Workshop (Tạm tắt do chỉ dùng Pancake cho Shop gốm)
        // add_action( 'bacera_workshop_booked', [ __CLASS__, 'push_workshop_booking' ] );
    }

    /**
     * ========================================================================
     * 1. TẠO ĐƠN HÀNG MỚI (POST /shops/{SHOP_ID}/orders)
     * ========================================================================
     * Tự động đẩy dữ liệu sang Pancake POS để trừ kho và lên đơn giao hàng
     */
    public static function push_order_to_pancake( $order_id ) {
        // Kiểm tra xem đã bật tính năng đồng bộ đơn hàng trong Admin chưa
        if ( ! get_option( 'bacera_pancake_sync_orders', 1 ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // BẢO MẬT DỮ LIỆU: Kiểm tra xem đơn này đã từng đẩy lên Pancake chưa?
        // Tránh lỗi tạo 2 đơn trên POS khi khách hàng F5 (tải lại) trang Thank you.
        if ( $order->get_meta( '_pancake_order_id' ) ) {
            return;
        }

        $api = new Pancake_API_Client();
        $warehouse_id = get_option( 'bacera_pancake_default_warehouse', '' );
        
        // 1. Ánh xạ danh sách sản phẩm trong giỏ hàng (Line Items)
        $items_payload = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            
            // Lấy ID biến thể trên Pancake (được lưu khi đồng bộ Product Module) để POS biết trừ đúng món nào
            $pancake_var_id = $product->get_meta( '_pancake_variation_id' );
            
            $items_payload[] = [
                'variation_id' => $pancake_var_id ?: '', 
                'quantity'     => $item->get_quantity(),
                'price'        => $order->get_item_total( $item, false, false ), // Giá đã chia theo SL
                'name'         => $item->get_name(),
            ];
        }

        // 2. Xây dựng Payload thông tin khách & Đơn hàng
        $order_data = [
            'bill_full_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'bill_phone_number' => $order->get_billing_phone(),
            'shipping_address'  => [
                'full_address' => $order->get_shipping_address_1() . ', ' . $order->get_shipping_city()
            ],
            'note'              => 'Đơn từ Website: #' . $order->get_order_number() . ' | ' . $order->get_customer_note(),
            'items'             => $items_payload,
            'total_amount'      => $order->get_total(),
            'discount'          => $order->get_discount_total(),
            'warehouse_id'      => $warehouse_id, // Gắn kho xuất hàng
        ];

        // 3. Gửi API Tạo đơn
        $response = $api->request( '/shops/{SHOP_ID}/orders', 'POST', $order_data );

        // 4. Xử lý kết quả trả về
        if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
            // Lưu lại ID của POS trả về vào WooCommerce Order Meta để sau này gọi xem chi tiết
            if ( isset( $response['data']['id'] ) ) {
                $order->update_meta_data( '_pancake_order_id', $response['data']['id'] );
                $order->save();
            }
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Thành công: Đã tạo đơn hàng #' . $order_id . ' lên Pancake POS.' );
            }
        } else {
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Lỗi tạo đơn hàng #' . $order_id . ': ' . wp_json_encode( $response ) );
            }
        }
    }

    /**
     * ========================================================================
     * 2. LẤY DANH SÁCH ĐƠN HÀNG (GET /shops/{SHOP_ID}/orders)
     * ========================================================================
     * Phục vụ cho trang "Lịch sử mua hàng" (My Account).
     * @param array $args Tham số lọc (ví dụ: ['phone_number' => '09...', 'page_size' => 10])
     * @return array Danh sách đơn hàng trả về từ POS
     */
    public static function get_orders( $args = [] ) {
        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/orders';

        // Nếu truyền số điện thoại để lọc ra lịch sử của riêng User đang đăng nhập
        if ( ! empty( $args ) ) {
            $endpoint .= '?' . http_build_query( $args );
        }

        return $api->request( $endpoint, 'GET' );
    }

    /**
     * ========================================================================
     * 3. LẤY CHI TIẾT MỘT ĐƠN HÀNG (GET /shops/{SHOP_ID}/orders/{ORDER_ID})
     * ========================================================================
     * Lấy tiến độ đóng gói, mã vận đơn (Tracking URL) của hãng vận chuyển.
     * @param string $pancake_order_id ID đơn hàng lưu trên hệ thống Pancake
     * @return array Dữ liệu chi tiết 1 đơn hàng
     */
    public static function get_order_detail( $pancake_order_id ) {
        if ( empty( $pancake_order_id ) ) {
            return false;
        }

        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/orders/' . urlencode( $pancake_order_id );
        
        return $api->request( $endpoint, 'GET' );
    }

    /**
     * (Optional) Đẩy Booking Workshop - Hiện đang tắt
     */
    public static function push_workshop_booking( $booking_data ) {
        // Tương lai nếu muốn push Workshop thành 1 đơn dạng ảo trên Pancake POS
        // thì gọi POST tới endpoint orders và truyền tham số tag = "Workshop".
    }
}