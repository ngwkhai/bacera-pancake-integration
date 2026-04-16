<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Bacera_Utils {

    /**
     * Ghi log lỗi vào hệ thống debug của WordPress.
     */
    public static function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
            error_log( '[Bacera Pancake POS] ' . $message );
        }
    }

    /* ==========================================================================
       HỖ TRỢ ĐỒNG BỘ DỮ LIỆU (FIX LỖI DỮ LIỆU TRỐNG)
       ========================================================================== */

    /**
     * Lưu hoặc cập nhật sản phẩm từ Pancake vào WordPress.
     * Hàm này được gọi trong quá trình lặp qua danh sách sản phẩm từ API[cite: 309, 312].
     */
    public static function upsert_external_product( $item ) {
        // Pancake thường để tên trong $item['product']['name'] hoặc $item['name']
        $name = $item['product']['name'] ?? $item['name'] ?? 'Sản phẩm gốm';
        $pancake_id = $item['id'] ?? null;
    
        if ( ! $pancake_id ) return;
    
        // Tạo slug thống nhất: ten-san-pham-pancakeid
        $slug = sanitize_title( $name . '-' . $pancake_id );
    
        $post_data = [
            'post_title'   => $name,
            'post_status'  => 'publish',
            'post_type'    => 'pancake_product',
            'post_name'    => $slug, // Đảm bảo slug này khớp với URL ảnh
        ];
    
        // Tìm xem đã tồn tại chưa
        $existing = get_posts([
            'post_type'  => 'pancake_product',
            'meta_key'   => '_pancake_id',
            'meta_value' => $pancake_id,
            'posts_per_page' => 1,
            'fields'     => 'ids',
        ]);
    
        if ( ! empty( $existing ) ) {
            $post_id = $existing[0];
            $post_data['ID'] = $post_id;
            wp_update_post( $post_data );
        } else {
            $post_id = wp_insert_post( $post_data );
        }
    
        // Lưu URL ảnh gốc để trạm trung chuyển sử dụng [cite: 63]
        if ( ! empty( $item['images'][0] ) ) {
            update_post_meta( $post_id, '_pancake_image_url', $item['images'][0] );
        }
        update_post_meta( $post_id, '_pancake_id', $pancake_id );
        if ( ! empty( $item['product_id'] ) ) {
            update_post_meta( $post_id, '_pancake_product_id', sanitize_text_field( (string) $item['product_id'] ) );
        }
    }

    /**
     * Lấy object sản phẩm từ Pancake GET /products/{id} (và tự bổ sung _pancake_product_id nếu thiếu).
     *
     * @param int $post_id ID bài pancake_product.
     * @return array<string,mixed>|null
     */
    public static function fetch_product_detail_for_wp_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! class_exists( 'Bacera_Module_Products' ) || ! class_exists( 'Pancake_API_Client' ) ) {
            return null;
        }

        $product_uuid  = get_post_meta( $post_id, '_pancake_product_id', true );
        $variation_id  = get_post_meta( $post_id, '_pancake_id', true );

        if ( $product_uuid === '' && $variation_id !== '' ) {
            $api = new Pancake_API_Client();
            $q   = http_build_query(
                [
                    'page_size'       => 1,
                    'variation_ids'   => [ $variation_id ],
                ]
            );
            $r = $api->request( '/shops/{SHOP_ID}/products/variations?' . $q, 'GET' );
            if ( is_array( $r ) && ! empty( $r['data'][0]['product_id'] ) ) {
                $product_uuid = (string) $r['data'][0]['product_id'];
                update_post_meta( $post_id, '_pancake_product_id', $product_uuid );
            }
        }

        if ( $product_uuid === '' ) {
            return null;
        }

        $raw = Bacera_Module_Products::get_product_detail( $product_uuid );
        if ( ! is_array( $raw ) ) {
            return null;
        }
        if ( isset( $raw['success'] ) && $raw['success'] === false ) {
            return null;
        }

        $product = isset( $raw['data'] ) && is_array( $raw['data'] ) ? $raw['data'] : $raw;
        if ( empty( $product['variations'] ) && empty( $product['name'] ) ) {
            return null;
        }

        return $product;
    }

    /**
     * URL trang Shop (template template-shop.php) nếu có.
     */
    public static function get_shop_page_url() {
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-shop.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        return home_url( '/' );
    }

    /**
     * Map id danh mục (chuỗi) => tên hiển thị từ cây categories API.
     *
     * @param array<int,array<string,mixed>> $categories_data
     * @return array<string,string>
     */
    public static function flatten_category_names( array $categories_data ) {
        $out = [];
        $walk = static function ( $nodes ) use ( &$out, &$walk ) {
            foreach ( $nodes as $n ) {
                if ( ! is_array( $n ) ) {
                    continue;
                }
                if ( isset( $n['id'] ) ) {
                    $out[ (string) $n['id'] ] = isset( $n['text'] ) ? (string) $n['text'] : ( isset( $n['name'] ) ? (string) $n['name'] : '' );
                }
                if ( ! empty( $n['nodes'] ) && is_array( $n['nodes'] ) ) {
                    $walk( $n['nodes'] );
                }
            }
        };
        $walk( $categories_data );
        return $out;
    }

    /* ==========================================================================
       GIAI ĐOẠN 3: XỬ LÝ TRUNG CHUYỂN - STREAMING PROXY
       ========================================================================== */

    /**
     * "Trạm trung chuyển" lấy ảnh từ Pancake và đẩy về trình duyệt.
     */
    public static function handle_image_streaming() {
        // 1. Lấy slug từ URL
        $slug = get_query_var( 'bacera_img_slug' );
        if ( ! $slug ) return;
    
        // Xóa dấu gạch chéo ở cuối slug nếu có (để khớp với database)
        $slug = rtrim( $slug, '/' );
    
        // 2. Truy vấn tìm Post trong CPT 'pancake_product'
        // Chúng ta cần tìm đúng loại post đã lưu ở bước upsert [cite: 317, 318]
        $product = get_page_by_path( $slug, OBJECT, 'pancake_product' );
    
        if ( ! $product ) {
            self::log_error( "Proxy lỗi: Không tìm thấy sản phẩm với slug [$slug] trong database." );
            // Nếu không tìm thấy, cho phép WP tiếp tục tải trang 404 bình thường
            return; 
        }
    
        // 3. Lấy URL gốc từ Metadata [cite: 310, 311]
        $original_url = get_post_meta( $product->ID, '_pancake_image_url', true );
        if ( ! $original_url ) {
            self::log_error( "Proxy lỗi: Sản phẩm ID {$product->ID} thiếu metadata _pancake_image_url." );
            return;
        }
    
        // 4. Tải ảnh từ server Pancake [cite: 451]
        $response = wp_remote_get( $original_url, [
            'timeout'    => 20,
            'user-agent' => 'Bacera-Image-Proxy/1.0',
            'sslverify'  => false, // Thêm dòng này nếu localhost của bạn chưa cấu hình SSL chuẩn
        ] );
    
        if ( is_wp_error( $response ) ) {
            self::log_error( 'Lỗi kết nối server Pancake: ' . $response->get_error_message() );
            return;
        }
    
        $image_data = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
    
        if ( empty( $image_data ) ) {
            self::log_error( 'Dữ liệu ảnh trả về từ Pancake bị trống.' );
            return;
        }
    
        // GIAI ĐOẠN HOÀN THIỆN: Đẩy dữ liệu về trình duyệt
        // Kiểm tra và dọn dẹp buffer một cách an toàn để tránh lỗi "headers already sent"
        while ( ob_get_level() ) {
            ob_end_clean();
        }
    
        header( "Content-Type: $content_type" );
        header( "Cache-Control: public, max-age=604800" ); // Lưu cache 7 ngày để tối ưu UX [cite: 332]
        header( "X-Source: Bacera-Pancake-Proxy" );
        header( "Content-Length: " . strlen( $image_data ) ); // Thông báo kích thước file cho trình duyệt
        
        echo $image_data;
        exit; // Dừng mọi tiến trình khác để file ảnh không bị lẫn mã HTML/Text thừa
    }

    // Thêm hàm này vào trong class Bacera_Utils
    public static function get_proxy_url( $item ) {
        $name = $item['product']['name'] ?? $item['name'] ?? 'product';
        $pancake_id = $item['id'] ?? $item['product_id'] ?? '0';
        
        // Tạo slug giống hệt lúc upsert để handle_image_streaming tìm thấy
        $slug = sanitize_title( $name . '-' . $pancake_id );
        
        // Trả về URL cục bộ: bacera.vn/pancake-img/ten-san-pham-id
        return home_url( "/bacera-img/{$slug}" );
    }

    /**
     * Permalink trang chi tiết (single-pancake_product.php) — slug trùng upsert_external_product().
     *
     * @param array<string,mixed> $item Một variation/product từ API (cùng shape với upsert).
     * @return string URL đầy đủ hoặc chuỗi rỗng nếu không tạo được.
     */
    public static function get_product_permalink( $item ) {
        $name       = $item['product']['name'] ?? $item['name'] ?? 'product';
        $pancake_id = $item['id'] ?? $item['product_id'] ?? null;
        if ( $pancake_id === null || $pancake_id === '' ) {
            return '';
        }
        $slug = sanitize_title( $name . '-' . $pancake_id );
        $post = get_page_by_path( $slug, OBJECT, 'pancake_product' );
        if ( $post instanceof \WP_Post ) {
            return get_permalink( $post );
        }
        return '';
    }
}