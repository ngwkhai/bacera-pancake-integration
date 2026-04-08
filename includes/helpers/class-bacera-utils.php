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
}