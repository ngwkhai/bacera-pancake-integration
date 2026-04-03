<?php
class Bacera_Module_Customers {
    public static function init() {
        add_action( 'user_register', [ __CLASS__, 'sync_new_customer' ] );
    }

    public static function sync_new_customer( $user_id ) {
        $user_info = get_userdata( $user_id );
        $api = new Pancake_API_Client();

        $customer_data = [
            'name'  => $user_info->display_name,
            'email' => $user_info->user_email,
        ];

        $response = $api->request( '/shops/{SHOP_ID}/customers', 'POST', $customer_data );
        if ( $response && isset($response['id']) ) {
            update_user_meta( $user_id, '_pancake_customer_id', $response['id'] );
        }
    }
}