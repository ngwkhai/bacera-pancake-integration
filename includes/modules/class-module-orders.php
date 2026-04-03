<?php
class Bacera_Module_Orders {
    public static function init() {
        add_action( 'woocommerce_thankyou', [ __CLASS__, 'push_order_to_pancake' ] );
        // Hook thêm cho form booking Workshop (nếu không dùng chung Woo)
        add_action( 'bacera_workshop_booked', [ __CLASS__, 'push_workshop_booking' ] );
    }

    public static function push_order_to_pancake( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $api = new Pancake_API_Client();
        
        $order_data = [
            'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone' => $order->get_billing_phone(),
            'total_amount'   => $order->get_total(),
            // ... Mapping dữ liệu Line items
        ];

        $api->request( '/shops/{SHOP_ID}/orders', 'POST', $order_data );
    }

    public static function push_workshop_booking( $booking_data ) {
         // Logic đẩy booking lớp học lên như một "Order" có tag là Workshop
    }
}