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

        // Slug chỉ từ tên (vd. still-cup), không gắn UUID — trùng tên → wp_unique_post_slug (still-cup-2, …).
        $base_slug = sanitize_title( $name );
        if ( $base_slug === '' ) {
            $base_slug = 'product';
        }

        $post_data = [
            'post_title'  => $name,
            'post_status' => 'publish',
            'post_type'   => 'pancake_product',
        ];

        // Tìm xem đã tồn tại chưa
        $existing = get_posts(
            [
                'post_type'      => 'pancake_product',
                'meta_key'       => '_pancake_id',
                'meta_value'     => $pancake_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]
        );

        if ( ! empty( $existing ) ) {
            $post_id               = (int) $existing[0];
            $post_data['ID']       = $post_id;
            $post_data['post_name'] = wp_unique_post_slug( $base_slug, $post_id, 'publish', 'pancake_product', 0 );
            wp_update_post( $post_data );
        } else {
            $post_data['post_name'] = wp_unique_post_slug( $base_slug, 0, 'publish', 'pancake_product', 0 );
            $post_id                = wp_insert_post( $post_data );
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
     * URL trang Giỏ hàng (template template-cart.php) nếu có; không thì WooCommerce cart hoặc /cart/.
     */
    public static function get_cart_page_url() {
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-cart.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        if ( function_exists( 'wc_get_cart_url' ) ) {
            return wc_get_cart_url();
        }
        return home_url( '/cart/' );
    }

    /**
     * URL trang checkout 3 bước (template template-checkout.php) nếu có page gán template.
     *
     * @return string Rỗng nếu chưa tạo page.
     */
    public static function get_checkout_page_url() {
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-checkout.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        return '';
    }

    /**
     * URL trang cảm ơn sau đặt hàng (template template-order-thankyou.php) nếu có page gán template.
     *
     * @return string Rỗng nếu chưa tạo page.
     */
    public static function get_order_thankyou_page_url() {
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-order-thankyou.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        return '';
    }

    /**
     * URL bước checkout — ưu tiên template-checkout.php (3 bước), không thì template-checkout-shipping.php.
     */
    public static function get_checkout_shipping_page_url() {
        $unified = self::get_checkout_page_url();
        if ( $unified !== '' ) {
            return $unified;
        }
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-checkout-shipping.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        return self::get_cart_page_url();
    }

    /**
     * URL bước phương thức giao hàng & thanh toán — ưu tiên template-checkout.php, không thì template-checkout-delivery.php.
     */
    public static function get_checkout_delivery_page_url() {
        $unified = self::get_checkout_page_url();
        if ( $unified !== '' ) {
            return $unified;
        }
        $pages = get_pages(
            [
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-checkout-delivery.php',
                'number'     => 1,
            ]
        );
        if ( ! empty( $pages[0] ) ) {
            return get_permalink( $pages[0]->ID );
        }
        if ( function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_checkout_url();
        }
        return self::get_checkout_shipping_page_url();
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
     * Slug dành riêng trong /bacera-img/{slug} — logo cửa hàng (Pancake GET /shops → avatar_url).
     */
    const PANCAKE_SHOP_LOGO_SLUG = 'bacera-pancake-shop';

    /**
     * URL proxy logo cửa hàng (trống nếu chưa đồng bộ avatar từ Pancake).
     *
     * @return string
     */
    public static function get_pancake_shop_logo_proxy_url() {
        $src = get_option( 'bacera_pancake_shop_avatar_source_url', '' );
        if ( ! is_string( $src ) || $src === '' ) {
            return '';
        }
        return home_url( '/bacera-img/' . self::PANCAKE_SHOP_LOGO_SLUG );
    }

    /**
     * Tải ảnh từ URL tuyệt đối và trả raw response (dùng cho proxy). Thành công thì exit.
     *
     * @param string $original_url URL ảnh gốc (Pancake CDN…).
     * @return bool False nếu không stream được (caller không được tiếp tục xử lý slug khác).
     */
    private static function stream_remote_image_and_exit( $original_url ) {
        $original_url = is_string( $original_url ) ? trim( $original_url ) : '';
        if ( $original_url === '' ) {
            return false;
        }

        $response = wp_remote_get(
            $original_url,
            [
                'timeout'    => 20,
                'user-agent' => 'Bacera-Image-Proxy/1.0',
                'sslverify'  => false,
            ]
        );

        if ( is_wp_error( $response ) ) {
            self::log_error( 'Lỗi kết nối server ảnh: ' . $response->get_error_message() );
            return false;
        }

        $image_data   = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        if ( empty( $image_data ) ) {
            self::log_error( 'Dữ liệu ảnh trả về từ nguồn bị trống.' );
            return false;
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: ' . $content_type );
        header( 'Cache-Control: public, max-age=604800' );
        header( 'X-Source: Bacera-Pancake-Proxy' );
        header( 'Content-Length: ' . strlen( $image_data ) );

        echo $image_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * "Trạm trung chuyển" lấy ảnh từ Pancake và đẩy về trình duyệt.
     */
    public static function handle_image_streaming() {
        $slug = get_query_var( 'bacera_img_slug' );
        if ( ! $slug ) {
            return;
        }

        $slug = rtrim( $slug, '/' );

        if ( $slug === self::PANCAKE_SHOP_LOGO_SLUG ) {
            $original_url = get_option( 'bacera_pancake_shop_avatar_source_url', '' );
            if ( ! is_string( $original_url ) || $original_url === '' ) {
                self::log_error( 'Proxy logo cửa hàng: chưa có bacera_pancake_shop_avatar_source_url (đồng bộ /shops?).' );
                return;
            }
            if ( ! self::stream_remote_image_and_exit( $original_url ) ) {
                return;
            }
        }

        $product = get_page_by_path( $slug, OBJECT, 'pancake_product' );

        if ( ! $product ) {
            self::log_error( "Proxy lỗi: Không tìm thấy sản phẩm với slug [$slug] trong database." );
            return;
        }

        $original_url = get_post_meta( $product->ID, '_pancake_image_url', true );
        if ( ! $original_url ) {
            self::log_error( "Proxy lỗi: Sản phẩm ID {$product->ID} thiếu metadata _pancake_image_url." );
            return;
        }

        if ( ! self::stream_remote_image_and_exit( $original_url ) ) {
            return;
        }
    }

    // Thêm hàm này vào trong class Bacera_Utils
    public static function get_proxy_url( $item ) {
        $name       = $item['product']['name'] ?? $item['name'] ?? 'product';
        $pancake_id = $item['id'] ?? $item['product_id'] ?? null;

        $slug = '';
        if ( $pancake_id !== null && $pancake_id !== '' ) {
            $found = get_posts(
                [
                    'post_type'      => 'pancake_product',
                    'post_status'    => 'any',
                    'meta_key'       => '_pancake_id',
                    'meta_value'     => $pancake_id,
                    'posts_per_page' => 1,
                ]
            );
            if ( ! empty( $found[0] ) && $found[0] instanceof \WP_Post ) {
                $slug = $found[0]->post_name;
            }
        }
        if ( $slug === '' ) {
            $slug = sanitize_title( $name );
            if ( $slug === '' ) {
                $slug = 'product';
            }
        }

        return home_url( '/bacera-img/' . $slug );
    }

    /**
     * Permalink trang chi tiết (single-pancake_product.php) — slug trùng upsert_external_product().
     *
     * @param array<string,mixed> $item Một variation/product từ API (cùng shape với upsert).
     * @return string URL đầy đủ hoặc chuỗi rỗng nếu không tạo được.
     */
    public static function get_product_permalink( $item ) {
        $pancake_id = $item['id'] ?? $item['product_id'] ?? null;
        if ( $pancake_id === null || $pancake_id === '' ) {
            return '';
        }
        $posts = get_posts(
            [
                'post_type'      => 'pancake_product',
                'post_status'    => 'publish',
                'meta_key'       => '_pancake_id',
                'meta_value'     => $pancake_id,
                'posts_per_page' => 1,
            ]
        );
        if ( ! empty( $posts[0] ) && $posts[0] instanceof \WP_Post ) {
            return get_permalink( $posts[0] );
        }
        return '';
    }
}