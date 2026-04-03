<?php
class Pancake_API_Client {
    private $api_key;
    private $shop_id;
    private $base_url = 'https://pos.pages.fm/api/v1';

    public function __construct() {
        $this->api_key = get_option('bacera_pancake_api_key', '');
        $this->shop_id = get_option('bacera_pancake_shop_id', '');
    }

    public function request( $endpoint, $method = 'GET', $body = [] ) {
        if ( empty( $this->api_key ) || empty( $this->shop_id ) ) {
            Bacera_Utils::log_error('Thiếu API Key hoặc Shop ID.');
            return false;
        }

        // Thay thế placeholder {SHOP_ID} trong endpoint
        $endpoint = str_replace( '{SHOP_ID}', $this->shop_id, $endpoint );
        
        // PANCAKE POS YÊU CẦU API KEY NẰM TRONG URL (QUERY PARAM)
        // Kiểm tra xem endpoint đã có dấu ? chưa để nối chuỗi cho đúng
        $separator = ( strpos( $endpoint, '?' ) !== false ) ? '&' : '?';
        $endpoint .= $separator . 'api_key=' . $this->api_key;

        $url = $this->base_url . $endpoint;

        $args = [
            'method'  => $method,
            'timeout' => 45,
            'headers' => [
                'Content-Type'  => 'application/json',
                // Bỏ Authorization Bearer đi vì Pancake không dùng chuẩn này
            ],
        ];

        if ( !empty( $body ) && in_array( $method, ['POST', 'PUT'] ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            Bacera_Utils::log_error( 'API Request Error: ' . $response->get_error_message() );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $body, true );
        
        // Ghi log nếu API Pancake trả về lỗi để dễ debug
        if ( isset($decoded_body['success']) && $decoded_body['success'] === false ) {
            Bacera_Utils::log_error( 'Pancake API Error: ' . $body );
        }

        return $decoded_body;
    }
}