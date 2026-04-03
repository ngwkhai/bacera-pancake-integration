<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Module_Products {

    public static function init() {
        // Hook chạy sau khi một sản phẩm WooCommerce được lưu hoặc cập nhật
        add_action( 'woocommerce_after_product_object_save', [ __CLASS__, 'sync_product_to_pancake' ], 10, 2 );
    }

    /**
     * Đồng bộ dữ liệu sản phẩm lên Pancake POS
     * * @param WC_Product $product Đối tượng sản phẩm WooCommerce
     * @param \WC_Data_Store $data_store Data store
     */
    public static function sync_product_to_pancake( $product, $data_store ) {
        // 1. CHỈ ĐỒNG BỘ NGÔN NGỮ MẶC ĐỊNH (Tiếng Việt)
        // Nếu bạn dùng WPML hoặc Polylang, cần check language code tại đây để tránh tạo sản phẩm trùng lặp.
        if ( function_exists('pll_get_post_language') ) {
            $lang = pll_get_post_language( $product->get_id() );
            if ( $lang !== 'vi' ) {
                return; // Bỏ qua nếu không phải tiếng Việt
            }
        }

        $api = new Pancake_API_Client();
        
        // Lấy ID Pancake đã lưu (nếu có) để quyết định POST hay PUT
        $pancake_product_id = $product->get_meta( '_pancake_product_id' );
        
        // 2. ÁNH XẠ DỮ LIỆU CƠ BẢN (DATA MAPPING)
        $product_data = [
            'name'         => $product->get_name(),
            'note_product' => strip_tags( $product->get_short_description() ), // Ghi chú ngắn
            'weight'       => (float) $product->get_weight() ?: 1, // Mặc định là 1 nếu trống
            'custom_id'    => $product->get_sku() ?: 'BACERA-' . $product->get_id(), // Mã sản phẩm
            'is_published' => $product->get_status() === 'publish' ? true : false,
        ];

        // 3. XỬ LÝ DANH MỤC (CATEGORIES)
        // Lưu ý: Cần có một bảng mapping giữa Category ID của Woo và Category ID của Pancake.
        // Ở đây lấy mô phỏng mảng ID.
        $product_data['category_ids'] = []; 
        
        // 4. XỬ LÝ VARIATIONS (BIẾN THỂ)
        $variations_payload = [];

        if ( $product->is_type( 'variable' ) ) {
            // Trường hợp sản phẩm có biến thể (Ví dụ: Cốc gốm - Màu Xanh/Đỏ)
            $available_variations = $product->get_children();
            
            // Lấy thuộc tính sản phẩm cha
            $attributes = $product->get_attributes();
            $product_data['product_attributes'] = [];
            foreach ( $attributes as $attr ) {
                $product_data['product_attributes'][] = [
                    'name'   => wc_attribute_label( $attr->get_name() ),
                    'values' => $attr->get_options()
                ];
            }

            foreach ( $available_variations as $var_id ) {
                $variation = wc_get_product( $var_id );
                $var_fields = [];
                
                foreach ( $variation->get_variation_attributes() as $tax => $val ) {
                    $var_fields[] = [
                        'name'  => wc_attribute_label( str_replace('attribute_', '', $tax) ),
                        'value' => $val
                    ];
                }

                $variation_item = [
                    'fields'           => $var_fields,
                    'retail_price'     => (int) $variation->get_regular_price(),
                    'price_at_counter' => (int) $variation->get_price(), // Giá bán thực tế
                    'weight'           => (float) $variation->get_weight(),
                    'barcode'          => $variation->get_sku(),
                    'custom_id'        => $variation->get_sku(),
                    'is_hidden'        => $variation->get_status() !== 'publish',
                ];

                // Nếu là cập nhật, cần truyền id của biến thể trên Pancake
                $pancake_var_id = $variation->get_meta( '_pancake_variation_id' );
                if ( ! empty( $pancake_var_id ) ) {
                    $variation_item['id'] = $pancake_var_id;
                }

                $variations_payload[] = $variation_item;
            }
        } else {
            // Trường hợp sản phẩm đơn giản (Simple Product)
            // Pancake vẫn yêu cầu gửi data vào mảng "variations" dù chỉ có 1 item
            $single_variation = [
                'fields'           => [], // Sản phẩm đơn giản không có fields
                'retail_price'     => (int) $product->get_regular_price(),
                'price_at_counter' => (int) $product->get_price(),
                'weight'           => (float) $product->get_weight(),
                'barcode'          => $product->get_sku(),
                'custom_id'        => $product->get_sku() ?: 'BACERA-' . $product->get_id(),
                'is_hidden'        => $product->get_status() !== 'publish',
            ];

            // Truyền ID biến thể nếu đang update
            $pancake_var_id = $product->get_meta( '_pancake_variation_id' );
            if ( ! empty( $pancake_var_id ) ) {
                $single_variation['id'] = $pancake_var_id;
            }

            $variations_payload[] = $single_variation;
        }

        $product_data['variations'] = $variations_payload;

        // 5. GỬI REQUEST LÊN PANCAKE POS
        if ( empty( $pancake_product_id ) ) {
            // SẢN PHẨM MỚI -> Gọi POST
            $endpoint = '/shops/{SHOP_ID}/products';
            $response = $api->request( $endpoint, 'POST', [ 'product' => $product_data ] );
            
            if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
                // Giả định API trả về ID của product vừa tạo (tuỳ thuộc format thực tế Pancake trả về)
                if ( isset( $response['data']['id'] ) ) {
                    $product->update_meta_data( '_pancake_product_id', $response['data']['id'] );
                    
                    // Lưu lại ID của biến thể đầu tiên để dùng cho Update/Inventory sau này
                    if ( isset( $response['data']['variations'][0]['id'] ) ) {
                        $product->update_meta_data( '_pancake_variation_id', $response['data']['variations'][0]['id'] );
                    }
                    $product->save_meta_data();
                }
                Bacera_Utils::log_error( 'Đã TẠO MỚI sản phẩm trên Pancake POS: ' . $product->get_name() );
            } else {
                Bacera_Utils::log_error( 'Lỗi tạo sản phẩm Pancake POS: ' . wp_json_encode( $response ) );
            }

        } else {
            // SẢN PHẨM ĐÃ TỒN TẠI -> Gọi PUT
            $endpoint = '/shops/{SHOP_ID}/products/' . $pancake_product_id;
            $response = $api->request( $endpoint, 'PUT', [ 'product' => $product_data ] );

            if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
                Bacera_Utils::log_error( 'Đã CẬP NHẬT sản phẩm trên Pancake POS: ' . $product->get_name() );
            } else {
                Bacera_Utils::log_error( 'Lỗi cập nhật sản phẩm Pancake POS: ' . wp_json_encode( $response ) );
            }
        }
    }
    
    /**
     * Cập nhật tồn kho hàng loạt (Bulk update inventory)
     * Gọi thủ công hoặc qua cron job khi cần đồng bộ kho từ Web -> Pancake
     */
    public static function sync_inventory_to_pancake( $variation_data ) {
        $api = new Pancake_API_Client();
        
        $payload = [
            'is_actual_remain_quantity' => true,
            'variations_warehouses'     => $variation_data 
            // format: [['variation_id' => '...', 'remain_quantity' => 15, 'warehouse_id' => '...']]
        ];

        $endpoint = '/shops/{SHOP_ID}/variations/update_quantity';
        return $api->request( $endpoint, 'POST', $payload );
    }
}