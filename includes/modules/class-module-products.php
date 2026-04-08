<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Module_Products {

    public static function init() {
        // Hook chạy sau khi một sản phẩm WooCommerce được lưu hoặc cập nhật
        add_action( 'woocommerce_after_product_object_save', [ __CLASS__, 'sync_product_to_pancake' ], 10, 2 );
    }

    /* ==========================================================================
       PHẦN 1: ĐỒNG BỘ TỪ WEB LÊN PANCAKE POS (Dữ liệu đi)
       ========================================================================== */

    /**
     * Đồng bộ dữ liệu sản phẩm lên Pancake POS
     */
    public static function sync_product_to_pancake( $product, $data_store ) {
        // 1. CHỈ ĐỒNG BỘ NGÔN NGỮ MẶC ĐỊNH (Tiếng Việt) [cite: 19, 307]
        if ( function_exists('pll_get_post_language') ) {
            $lang = pll_get_post_language( $product->get_id() );
            if ( $lang !== 'vi' ) {
                return; 
            }
        }

        $api = new Pancake_API_Client();
        $pancake_product_id = $product->get_meta( '_pancake_product_id' );
        
        // 2. ÁNH XẠ DỮ LIỆU CƠ BẢN (DATA MAPPING) [cite: 68, 71, 76]
        $product_data = [
            'name'         => $product->get_name(), // [cite: 68, 84]
            'note_product' => strip_tags( $product->get_short_description() ), 
            'weight'       => (float) $product->get_weight() ?: 1, 
            'custom_id'    => $product->get_sku() ?: 'BACERA-' . $product->get_id(), 
            'is_published' => $product->get_status() === 'publish' ? true : false,
        ];

        // 3. XỬ LÝ BIẾN THỂ (VARIATIONS) [cite: 447, 450]
        $variations_payload = [];
        if ( $product->is_type( 'variable' ) ) {
            $available_variations = $product->get_children();
            foreach ( $available_variations as $var_id ) {
                $variation = wc_get_product( $var_id );
                $variation_item = [
                    'retail_price'     => (int) $variation->get_regular_price(), // [cite: 69, 87]
                    'price_at_counter' => (int) $variation->get_price(), 
                    'custom_id'        => $variation->get_sku(),
                ];
                $pancake_var_id = $variation->get_meta( '_pancake_variation_id' );
                if ( ! empty( $pancake_var_id ) ) {
                    $variation_item['id'] = $pancake_var_id;
                }
                $variations_payload[] = $variation_item;
            }
        } else {
            $single_variation = [
                'retail_price'     => (int) $product->get_regular_price(),
                'price_at_counter' => (int) $product->get_price(),
                'custom_id'        => $product->get_sku() ?: 'BACERA-' . $product->get_id(),
            ];
            $pancake_var_id = $product->get_meta( '_pancake_variation_id' );
            if ( ! empty( $pancake_var_id ) ) {
                $single_variation['id'] = $pancake_var_id;
            }
            $variations_payload[] = $single_variation;
        }

        $product_data['variations'] = $variations_payload;

        // 4. GỬI REQUEST LÊN PANCAKE POS [cite: 361, 445, 446]
        if ( empty( $pancake_product_id ) ) {
            $endpoint = '/shops/{SHOP_ID}/products';
            $response = $api->request( $endpoint, 'POST', [ 'product' => $product_data ] );
            
            if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
                if ( isset( $response['data']['id'] ) ) {
                    $product->update_meta_data( '_pancake_product_id', $response['data']['id'] );
                    $product->save_meta_data();
                }
            }
        } else {
            $endpoint = '/shops/{SHOP_ID}/products/' . $pancake_product_id;
            $api->request( $endpoint, 'PUT', [ 'product' => $product_data ] );
        }
    }

    /* ==========================================================================
       PHẦN 2: THIẾT LẬP BẢN ĐỒ DỮ LIỆU (Dữ liệu về - Webhook/API Sync)
       ========================================================================== */

    /**
     * GIAI ĐOẠN 1: Tạo bản đồ dữ liệu để tối ưu SEO và dung lượng ổ cứng.
     * Phương thức này nhận dữ liệu từ Pancake và ánh xạ vào WordPress Post Meta.
     * * @param array $data Dữ liệu sản phẩm từ Pancake POS API hoặc Webhook
     */
    public static function sync_pancake_product_to_wp( $data ) {
        if ( empty( $data['id'] ) ) return false;

        // 1. Kiểm tra xem sản phẩm đã có trong "Bản đồ" chưa dựa trên ID Pancake [cite: 445]
        $existing_posts = get_posts([
            'post_type'      => 'product', // Hoặc 'pancake_product' tùy theo CPT bạn chọn
            'meta_key'       => '_pancake_id',
            'meta_value'     => $data['id'],
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);

        $product_args = [
            'post_title'   => $data['name'], // Tên gốm thủ công [cite: 68, 89]
            'post_type'    => 'product',
            'post_status'  => 'publish',
            'post_name'    => sanitize_title( $data['name'] ) // Slug sạch chuẩn SEO 
        ];

        if ( ! empty( $existing_posts ) ) {
            $product_id = $existing_posts[0];
            $product_args['ID'] = $product_id;
            wp_update_post( $product_args );
        } else {
            $product_id = wp_insert_post( $product_args );
        }

        // 2. LƯU THÔNG TIN VÀO METADATA (Bản đồ địa chỉ) [cite: 310, 445]
        // Lưu ID gốc của Pancake để đối soát khi xóa [cite: 359]
        update_post_meta( $product_id, '_pancake_id', $data['id'] );

        // Lưu link ảnh gốc vật lý (Không tải file về host) [cite: 310]
        // Link này sẽ được helper Bacera_Utils sử dụng để Streaming Proxy sau này.
        if ( ! empty( $data['image_path'] ) ) {
            update_post_meta( $product_id, '_pancake_image_url', $data['image_path'] );
        }

        // Lưu giá để hiển thị nhanh (Cache metadata) [cite: 69, 87]
        if ( isset( $data['retail_price'] ) ) {
            update_post_meta( $product_id, '_regular_price', $data['retail_price'] );
            update_post_meta( $product_id, '_price', $data['retail_price'] );
        }

        return $product_id;
    }

    /* ==========================================================================
       PHẦN 3: CÁC TIỆN ÍCH LẤY DỮ LIỆU (GET)
       ========================================================================== */

    public static function get_products( $args = [] ) {
        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/products/variations'; // [cite: 450]
        if ( ! empty( $args ) ) {
            $endpoint .= '?' . http_build_query( $args );
        }
        return $api->request( $endpoint, 'GET' );
    }

    public static function get_product_detail( $product_sku ) {
        $api = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/products/' . urlencode( $product_sku ); // [cite: 451]
        return $api->request( $endpoint, 'GET' );
    }

    public static function get_categories() {
        $api = new Pancake_API_Client();
        return $api->request( '/shops/{SHOP_ID}/categories', 'GET' ); // [cite: 454]
    }

    public static function get_warehouses() {
        $api = new Pancake_API_Client();
        return $api->request( '/shops/{SHOP_ID}/warehouses', 'GET' ); // [cite: 385]
    }
}