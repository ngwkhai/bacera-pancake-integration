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
     * GET một khách theo ID (đầy đủ hơn danh sách — dùng khi list thiếu SĐT/email).
     *
     * @param string|int $customer_id ID khách trên Pancake.
     * @return array|false
     */
    public static function get_customer_by_id( $customer_id ) {
        $api      = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/customers/' . rawurlencode( (string) $customer_id );

        return $api->request( $endpoint, 'GET' );
    }

    /**
     * ID dùng trong URL API: ưu tiên customer_id (theo tài liệu Pancake).
     *
     * @param array $row Một phần tử từ data[] hoặc data sau POST.
     * @return string
     */
    private static function pancake_customer_api_id_from_row( array $row ) {
        if ( ! empty( $row['customer_id'] ) ) {
            return (string) $row['customer_id'];
        }
        if ( ! empty( $row['id'] ) ) {
            return (string) $row['id'];
        }

        return '';
    }

    /**
     * Body POST tạo khách (Open API: phoneNumber, createType).
     *
     * @param string $name  Tên.
     * @param string $email Email (tuỳ chọn, thêm vào emails[]).
     * @param string $phone SĐT (bắt buộc API — nếu rỗng dùng placeholder).
     * @return array
     */
    private static function build_pancake_post_create_body( $name, $email, $phone ) {
        $body = [
            'name'        => $name !== '' ? $name : 'Khách',
            'phoneNumber' => ( $phone !== '' && $phone !== null ) ? (string) $phone : '0000000000',
            'createType'  => 'force',
        ];
        $email = trim( (string) $email );
        if ( $email !== '' ) {
            $body['emails'] = [ $email ];
        }

        return $body;
    }

    /**
     * Body PUT cập nhật khách — wrapper "customer" + emails / phone_numbers (mảng).
     *
     * @param string $name  Tên.
     * @param string $email Email.
     * @param string $phone SĐT.
     * @return array
     */
    private static function build_pancake_put_customer_body( $name, $email, $phone ) {
        $customer = [
            'name' => $name !== '' ? $name : 'Khách',
        ];
        $email = trim( (string) $email );
        $phone = trim( (string) $phone );
        $customer['emails']        = $email !== '' ? [ $email ] : [];
        $customer['phone_numbers'] = $phone !== '' ? [ $phone ] : [];

        return [ 'customer' => $customer ];
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

        // BƯỚC 1: Kiểm tra xem khách hàng đã tồn tại trên Pancake POS chưa (query: search).
        if ( $phone !== '' && $phone !== null ) {
            $existing_customers = self::get_customers( [ 'search' => $phone ] );
            if ( isset( $existing_customers['success'] ) && true === $existing_customers['success'] && ! empty( $existing_customers['data'] ) ) {
                $row                 = $existing_customers['data'][0];
                $pancake_customer_id = self::pancake_customer_api_id_from_row( $row );
                if ( $pancake_customer_id !== '' ) {
                    update_user_meta( $user_id, '_pancake_customer_id', $pancake_customer_id );
                    return;
                }
            }
        }

        // BƯỚC 2: POST tạo mới — body theo tài liệu: name, phoneNumber (camelCase), createType.
        $customer_data = self::build_pancake_post_create_body(
            $user_info->display_name ?: $user_info->user_login,
            $user_info->user_email,
            (string) $phone
        );

        $response = $api->request( '/shops/{SHOP_ID}/customers', 'POST', $customer_data );

        // BƯỚC 3: Xử lý và lưu lại ID Pancake POS trả về
        if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
            if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
                $pid = self::pancake_customer_api_id_from_row( $response['data'] );
                if ( $pid !== '' ) {
                    update_user_meta( $user_id, '_pancake_customer_id', $pid );
                }
            }
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Đã TẠO MỚI khách hàng trên POS: ' . $user_info->user_email );
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
        $phone     = get_user_meta( $user_id, 'billing_phone', true );

        $api      = new Pancake_API_Client();
        $endpoint = '/shops/{SHOP_ID}/customers/' . rawurlencode( (string) $pancake_customer_id );

        $body = self::build_pancake_put_customer_body(
            (string) $user_info->display_name,
            (string) $user_info->user_email,
            (string) $phone
        );

        $response = $api->request( $endpoint, 'PUT', $body );

        if ( $response && isset( $response['success'] ) && $response['success'] === true ) {
            if ( class_exists( 'Bacera_Utils' ) ) {
                Bacera_Utils::log_error( 'Đã CẬP NHẬT khách hàng trên POS: ' . $user_info->user_email );
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

    // —— Đồng bộ bảng bacera_customers (theme) với Pancake ——————————————

    /**
     * Chuẩn hóa SĐT để so khớp (chỉ chữ số, 84… → 0…).
     *
     * @param string $phone Raw phone.
     * @return string
     */
    public static function normalize_phone_key( $phone ) {
        $d = preg_replace( '/\D+/', '', (string) $phone );
        if ( $d === '' ) {
            return '';
        }
        if ( strlen( $d ) >= 11 && substr( $d, 0, 2 ) === '84' ) {
            $d = '0' . substr( $d, 2 );
        }
        return $d;
    }

    /**
     * Bóc mảng khách từ response GET /customers (các dạng data khác nhau).
     *
     * @param array|false $response API response.
     * @return array
     */
    private static function extract_customers_list_from_response( $response ) {
        if ( ! is_array( $response ) || empty( $response['success'] ) ) {
            return [];
        }
        $data = isset( $response['data'] ) ? $response['data'] : null;
        if ( ! is_array( $data ) ) {
            return [];
        }
        if ( isset( $data['customers'] ) && is_array( $data['customers'] ) ) {
            return $data['customers'];
        }
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            return $data['items'];
        }
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return $data;
        }
        if ( isset( $data['id'] ) || isset( $data['customer_id'] ) ) {
            return [ $data ];
        }
        return [];
    }

    /**
     * Gộp các lớp lồng nhau (customer, data…) về một mảng phẳng để đọc trường.
     *
     * @param array $item Raw item.
     * @return array
     */
    private static function flatten_pancake_customer_item( $item ) {
        if ( ! is_array( $item ) ) {
            return [];
        }
        $out = $item;

        // Không dùng array_merge(customer, item): nếu customer có phone_numbers: [] mà item cha không có key,
        // kết quả giữ mảng rỗng → mất SĐT. Chỉ merge từng key; không ghi đè bằng mảng rỗng.
        $merge_nested = function ( array $into, array $from ) {
            foreach ( $from as $k => $v ) {
                if ( ! array_key_exists( $k, $into ) ) {
                    $into[ $k ] = $v;
                    continue;
                }
                if ( is_array( $v ) && array() === $v ) {
                    continue;
                }
                // Ghi đè phone_numbers / emails rỗng bằng bản từ from có dữ liệu.
                if ( is_array( $into[ $k ] ) && array() === $into[ $k ] && is_array( $v ) && count( $v ) > 0 ) {
                    $into[ $k ] = $v;
                    continue;
                }
                if ( $into[ $k ] === '' || $into[ $k ] === null ) {
                    $into[ $k ] = $v;
                }
            }

            return $into;
        };

        if ( ! empty( $item['customer'] ) && is_array( $item['customer'] ) ) {
            $out = $merge_nested( $out, $item['customer'] );
        }
        if ( ! empty( $item['data'] ) && is_array( $item['data'] )
            && ( isset( $item['data']['id'] ) || isset( $item['data']['customer_id'] ) ) ) {
            $out = $merge_nested( $out, $item['data'] );
        }

        return $out;
    }

    /**
     * Trích name / email / phone từ nhiều kiểu key và object lồng nhau (Pancake API không cố định một schema).
     *
     * @param array $item Raw (đã flatten hoặc chưa).
     * @param int   $depth Độ sâu đệ quy.
     * @return array{name: string, email: string, phone: string}
     */
    private static function extract_contact_fields_from_pancake_array( $item, $depth = 0 ) {
        $empty = [ 'name' => '', 'email' => '', 'phone' => '' ];
        if ( ! is_array( $item ) || $depth > 6 ) {
            return $empty;
        }

        $name  = '';
        $email = '';
        $phone = '';

        $candidates_name = [ 'name', 'full_name', 'fullName', 'customer_name', 'display_name', 'title' ];
        foreach ( $candidates_name as $k ) {
            if ( isset( $item[ $k ] ) && $item[ $k ] !== null && $item[ $k ] !== '' ) {
                $name = trim( (string) $item[ $k ] );
                if ( $name !== '' ) {
                    break;
                }
            }
        }

        $candidates_email = [ 'email', 'mail', 'contact_email', 'user_email', 'email_address', 'contactEmail' ];
        foreach ( $candidates_email as $k ) {
            if ( isset( $item[ $k ] ) && $item[ $k ] !== null && $item[ $k ] !== '' ) {
                $email = trim( (string) $item[ $k ] );
                if ( $email !== '' ) {
                    break;
                }
            }
        }

        $candidates_phone = [ 'phone_number', 'phone', 'mobile', 'tel', 'phoneNumber', 'msisdn', 'contact_phone', 'primary_phone', 'telephone' ];
        foreach ( $candidates_phone as $k ) {
            if ( isset( $item[ $k ] ) && $item[ $k ] !== null && $item[ $k ] !== '' ) {
                $phone = trim( (string) $item[ $k ] );
                if ( $phone !== '' ) {
                    break;
                }
            }
        }

        // Pancake POS API (docs): "phone_numbers": [ "0999999999" ]
        if ( $phone === '' && ! empty( $item['phone_numbers'] ) && is_array( $item['phone_numbers'] ) ) {
            foreach ( $item['phone_numbers'] as $p ) {
                $p = trim( (string) $p );
                if ( $p !== '' ) {
                    $phone = $p;
                    break;
                }
            }
        }

        // Một số phiên bản / proxy trả camelCase.
        if ( $phone === '' && ! empty( $item['phoneNumbers'] ) && is_array( $item['phoneNumbers'] ) ) {
            foreach ( $item['phoneNumbers'] as $p ) {
                $p = trim( (string) $p );
                if ( $p !== '' ) {
                    $phone = $p;
                    break;
                }
            }
        }

        if ( $phone === '' && ! empty( $item['phones'] ) && is_array( $item['phones'] ) ) {
            foreach ( $item['phones'] as $p ) {
                if ( is_array( $p ) ) {
                    $phone = trim( (string) ( $p['phone_number'] ?? $p['phone'] ?? $p['number'] ?? $p['value'] ?? '' ) );
                } else {
                    $phone = trim( (string) $p );
                }
                if ( $phone !== '' ) {
                    break;
                }
            }
        }

        if ( $email === '' && ! empty( $item['emails'] ) && is_array( $item['emails'] ) ) {
            foreach ( $item['emails'] as $e ) {
                if ( is_array( $e ) ) {
                    $email = trim( (string) ( $e['email'] ?? $e['address'] ?? $e['value'] ?? '' ) );
                } else {
                    $email = trim( (string) $e );
                }
                if ( $email !== '' ) {
                    break;
                }
            }
        }

        // Địa chỉ giao hàng: phone_number trong shop_customer_address[]
        if ( $phone === '' && ! empty( $item['shop_customer_address'] ) && is_array( $item['shop_customer_address'] ) ) {
            foreach ( $item['shop_customer_address'] as $addr ) {
                if ( ! is_array( $addr ) ) {
                    continue;
                }
                $p = trim( (string) ( $addr['phone_number'] ?? $addr['phoneNumber'] ?? '' ) );
                if ( $p !== '' ) {
                    $phone = $p;
                    break;
                }
            }
        }

        $nested_keys = [ 'contact', 'profile', 'info', 'billing', 'shipping', 'address', 'customer', 'user', 'meta' ];
        foreach ( $nested_keys as $nk ) {
            if ( empty( $item[ $nk ] ) || ! is_array( $item[ $nk ] ) ) {
                continue;
            }
            $sub = self::extract_contact_fields_from_pancake_array( $item[ $nk ], $depth + 1 );
            if ( $name === '' && $sub['name'] !== '' ) {
                $name = $sub['name'];
            }
            if ( $email === '' && $sub['email'] !== '' ) {
                $email = $sub['email'];
            }
            if ( $phone === '' && $sub['phone'] !== '' ) {
                $phone = $sub['phone'];
            }
        }

        return [
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * @param array|false $response Response từ get_customer_by_id.
     * @return array|null
     */
    private static function extract_single_customer_data_from_response( $response ) {
        if ( ! is_array( $response ) || empty( $response['success'] ) ) {
            return null;
        }
        $data = $response['data'] ?? null;
        if ( ! is_array( $data ) ) {
            return null;
        }
        if ( isset( $data['id'] ) || isset( $data['customer_id'] ) ) {
            return $data;
        }
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return $data[0];
        }
        if ( isset( $data['phone_number'] ) || isset( $data['phone'] ) || isset( $data['phone_numbers'] )
            || isset( $data['phoneNumbers'] )
            || isset( $data['email'] ) || isset( $data['name'] ) ) {
            return $data;
        }

        return null;
    }

    /**
     * Nếu list API thiếu SĐT/email, gọi GET chi tiết theo ID.
     *
     * @param array|null $parsed Kết quả parse_pancake_customer_item.
     * @return array|null
     */
    private static function maybe_enrich_customer_from_detail_endpoint( $parsed ) {
        if ( ! is_array( $parsed ) || empty( $parsed['id'] ) ) {
            return $parsed;
        }
        // Chỉ bỏ qua GET chi tiết khi đã có SĐT. Tên/email đồng bộ từ list nhưng SĐT thường thiếu → cần gọi thêm.
        if ( $parsed['phone'] !== '' ) {
            return $parsed;
        }

        $detail = self::get_customer_by_id( $parsed['id'] );
        $item   = self::extract_single_customer_data_from_response( $detail );
        if ( ! $item ) {
            return $parsed;
        }

        $flat   = self::flatten_pancake_customer_item( $item );
        $fields = self::extract_contact_fields_from_pancake_array( $flat );

        if ( $parsed['phone'] === '' && $fields['phone'] !== '' ) {
            $parsed['phone'] = $fields['phone'];
        }
        if ( $parsed['email'] === '' && $fields['email'] !== '' ) {
            $parsed['email'] = $fields['email'];
        }
        if ( $parsed['name'] === '' && $fields['name'] !== '' ) {
            $parsed['name'] = $fields['name'];
        }

        return $parsed;
    }

    /**
     * Parse một phần tử khách từ API thành mảng chuẩn.
     *
     * @param array $item Raw item.
     * @return array|null id, name, email, phone
     */
    private static function parse_pancake_customer_item( $item ) {
        if ( ! is_array( $item ) ) {
            return null;
        }
        $flat = self::flatten_pancake_customer_item( $item );
        // Tài liệu API: PUT/GET /customers/{CUSTOMER_ID} dùng trường customer_id (khác id nội bộ).
        $api_id = isset( $flat['customer_id'] ) && $flat['customer_id'] !== '' && $flat['customer_id'] !== null
            ? $flat['customer_id']
            : ( $flat['id'] ?? $flat['_id'] ?? null );
        if ( $api_id === null || $api_id === '' ) {
            return null;
        }

        $fields = self::extract_contact_fields_from_pancake_array( $flat );
        // Nếu flatten làm mất phone_numbers, đọc thêm trực tiếp từ bản ghi gốc (list API).
        if ( $fields['phone'] === '' ) {
            $direct = self::extract_contact_fields_from_pancake_array( $item );
            if ( $direct['phone'] !== '' ) {
                $fields['phone'] = $direct['phone'];
            }
        }

        return [
            'id'    => (string) $api_id,
            'name'  => $fields['name'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
        ];
    }

    /**
     * Tìm id bản ghi bacera_customers theo pancake_customer_id hoặc SĐT (đã chuẩn hóa).
     *
     * @param \wpdb $wpdb WPDB.
     * @param string $table Table name.
     * @param string $pancake_id Pancake customer id.
     * @param string $phone Phone from Pancake.
     * @return int 0 nếu không thấy.
     */
    private static function find_bacera_row_by_pancake_or_phone( $wpdb, $table, $pancake_id, $phone ) {
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE pancake_customer_id = %s LIMIT 1",
                $pancake_id
            )
        );
        if ( $found ) {
            return (int) $found;
        }

        $norm = self::normalize_phone_key( $phone );
        if ( $norm === '' ) {
            return 0;
        }

        $rows = $wpdb->get_results( "SELECT id, phone FROM `{$table}`", ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return 0;
        }
        foreach ( $rows as $r ) {
            if ( self::normalize_phone_key( $r['phone'] ?? '' ) === $norm ) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    /**
     * Đồng bộ toàn bộ: GET từ Pancake (nguồn chính) → bacera_customers, sau đó link/POST bản ghi chưa có ID.
     *
     * @return array{ pulled: int, inserted: int, updated: int, linked: int, pushed: int, errors: string[] }
     */
    public static function sync_bacera_customers_full() {
        global $wpdb;

        $stats = [
            'pulled'   => 0,
            'inserted' => 0,
            'updated'  => 0,
            'linked'   => 0,
            'pushed'   => 0,
            'errors'   => [],
        ];

        $table = $wpdb->prefix . 'bacera_customers';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            $stats['errors'][] = 'Bảng bacera_customers chưa tồn tại.';
            return $stats;
        }

        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'pancake_customer_id'" );
        if ( empty( $col ) ) {
            $stats['errors'][] = 'Thiếu cột pancake_customer_id. Hãy tải lại trang admin để cập nhật DB.';
            return $stats;
        }

        if ( ! class_exists( 'Pancake_API_Client' ) ) {
            $stats['errors'][] = 'Pancake API client không khả dụng.';
            return $stats;
        }

        $api_key = get_option( 'bacera_pancake_api_key', '' );
        $shop_id = get_option( 'bacera_pancake_shop_id', '' );
        if ( empty( $api_key ) || empty( $shop_id ) ) {
            $stats['errors'][] = 'Thiếu API Key hoặc Shop ID trong cài đặt Pancake.';
            return $stats;
        }

        $page  = 1;
        $limit = 80;
        $more  = true;

        $list_query_base = [
            'page_size'               => $limit,
            'start_time_inserted_at' => 0,
            'end_time_inserted_at'    => 0,
            'start_time_updated_at'   => 0,
            'end_time_updated_at'     => 0,
        ];

        while ( $more ) {
            $resp = self::get_customers(
                array_merge(
                    $list_query_base,
                    [
                        'page_number' => $page,
                    ]
                )
            );

            if ( $resp === false ) {
                $stats['errors'][] = 'Lỗi mạng hoặc API (trang ' . $page . ').';
                break;
            }

            $rows      = self::extract_customers_list_from_response( $resp );
            $used_flat = false;

            if ( empty( $rows ) && 1 === $page ) {
                $resp_flat = self::get_customers(
                    array_merge( $list_query_base, [ 'page_number' => 1 ] )
                );
                $rows      = self::extract_customers_list_from_response( $resp_flat );
                $used_flat = ! empty( $rows );
            }

            if ( empty( $rows ) ) {
                $more = false;
                break;
            }

            foreach ( $rows as $item ) {
                $parsed = self::parse_pancake_customer_item( $item );
                if ( ! $parsed ) {
                    continue;
                }

                $parsed = self::maybe_enrich_customer_from_detail_endpoint( $parsed );

                $stats['pulled']++;

                $local_id = self::find_bacera_row_by_pancake_or_phone( $wpdb, $table, $parsed['id'], $parsed['phone'] );

                $row_data = [
                    'pancake_customer_id' => $parsed['id'],
                    'name'                => $parsed['name'] !== '' ? $parsed['name'] : 'Khách',
                    'email'               => $parsed['email'],
                    'phone'               => $parsed['phone'],
                ];

                if ( $local_id > 0 ) {
                    $wpdb->update(
                        $table,
                        $row_data,
                        [ 'id' => $local_id ],
                        [ '%s', '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    $stats['updated']++;
                } else {
                    $wpdb->insert(
                        $table,
                        [
                            'pancake_customer_id' => $row_data['pancake_customer_id'],
                            'name'                => $row_data['name'],
                            'email'               => $row_data['email'],
                            'phone'               => $row_data['phone'],
                            'has_password'        => 0,
                            'created_at'          => current_time( 'mysql' ),
                            'password_updated_at' => current_time( 'mysql' ),
                        ],
                        [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
                    );
                    if ( $wpdb->insert_id ) {
                        $stats['inserted']++;
                    }
                }
            }

            if ( $used_flat || count( $rows ) < $limit ) {
                $more = false;
            } else {
                $page++;
            }

            if ( $page > 400 ) {
                $stats['errors'][] = 'Đã dừng an toàn sau 400 trang — kiểm tra phân trang API.';
                break;
            }
        }

        update_option( 'bacera_pancake_customers_last_sync', time() );

        $orphans = $wpdb->get_results(
            "SELECT * FROM `{$table}` WHERE pancake_customer_id IS NULL OR pancake_customer_id = ''",
            ARRAY_A
        );

        if ( is_array( $orphans ) ) {
            foreach ( $orphans as $row ) {
                self::link_or_push_unlinked_bacera_row( $wpdb, $table, $row, $stats );
            }
        }

        return $stats;
    }

    /**
     * Với bản ghi bacera chưa có pancake_customer_id: GET theo SĐT/email → gán ID; nếu không có trên POS thì POST tạo.
     *
     * @param \wpdb $wpdb WPDB.
     * @param string $table Table.
     * @param array $row Row.
     * @param array $stats Stats by ref.
     */
    private static function link_or_push_unlinked_bacera_row( $wpdb, $table, $row, array &$stats ) {
        $phone = trim( (string) ( $row['phone'] ?? '' ) );
        $email = trim( (string) ( $row['email'] ?? '' ) );
        $name  = trim( (string) ( $row['name'] ?? '' ) );

        if ( $phone === '' && $email === '' ) {
            return;
        }

        if ( $phone !== '' ) {
            $existing = self::get_customers( [ 'search' => $phone ] );
            if ( isset( $existing['success'], $existing['data'][0] ) && $existing['success'] && ! empty( $existing['data'] ) ) {
                $pid = self::pancake_customer_api_id_from_row( $existing['data'][0] );
                if ( $pid !== '' ) {
                    $wpdb->update(
                        $table,
                        [ 'pancake_customer_id' => $pid ],
                        [ 'id' => $row['id'] ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                    $stats['linked']++;
                    return;
                }
            }
        }

        $payload = self::build_pancake_post_create_body(
            $name !== '' ? $name : ( $email !== '' ? $email : 'Khách' ),
            $email,
            $phone
        );

        $api      = new Pancake_API_Client();
        $response = $api->request( '/shops/{SHOP_ID}/customers', 'POST', $payload );

        if ( $response && ! empty( $response['success'] ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $new_pid = self::pancake_customer_api_id_from_row( $response['data'] );
            if ( $new_pid !== '' ) {
                $wpdb->update(
                    $table,
                    [ 'pancake_customer_id' => $new_pid ],
                    [ 'id' => $row['id'] ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $stats['pushed']++;
            }
        } else {
            $stats['errors'][] = 'Không POST được khách #' . (int) $row['id'] . ': ' . wp_json_encode( $response );
        }
    }

    /**
     * Đẩy một khách bacera_customers lên Pancake: có ID → PUT; không → GET trùng SĐT hoặc POST tạo mới.
     *
     * @param int $bacera_customer_id PK bacera_customers.id.
     * @return bool
     */
    public static function sync_bacera_customer_row_to_pancake( $bacera_customer_id ) {
        global $wpdb;

        $bacera_customer_id = (int) $bacera_customer_id;
        if ( $bacera_customer_id <= 0 ) {
            return false;
        }

        $table = $wpdb->prefix . 'bacera_customers';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $bacera_customer_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return false;
        }

        if ( empty( get_option( 'bacera_pancake_api_key' ) ) || empty( get_option( 'bacera_pancake_shop_id' ) ) ) {
            return false;
        }

        $name  = trim( (string) ( $row['name'] ?? '' ) );
        $email = trim( (string) ( $row['email'] ?? '' ) );
        $phone = trim( (string) ( $row['phone'] ?? '' ) );
        $pid   = isset( $row['pancake_customer_id'] ) ? trim( (string) $row['pancake_customer_id'] ) : '';

        $api = new Pancake_API_Client();

        if ( $pid !== '' ) {
            $put_body = self::build_pancake_put_customer_body( $name, $email, $phone );
            $response = $api->request( '/shops/{SHOP_ID}/customers/' . rawurlencode( $pid ), 'PUT', $put_body );
            return $response && ! empty( $response['success'] );
        }

        if ( $phone !== '' ) {
            $existing = self::get_customers( [ 'search' => $phone ] );
            if ( isset( $existing['success'], $existing['data'][0] ) && $existing['success'] && ! empty( $existing['data'] ) ) {
                $match_id = self::pancake_customer_api_id_from_row( $existing['data'][0] );
                if ( $match_id !== '' ) {
                    $wpdb->update(
                        $table,
                        [ 'pancake_customer_id' => $match_id ],
                        [ 'id' => $bacera_customer_id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                    return true;
                }
            }
        }

        $post_body = self::build_pancake_post_create_body(
            $name !== '' ? $name : ( $email !== '' ? $email : 'Khách' ),
            $email,
            $phone
        );
        $response = $api->request( '/shops/{SHOP_ID}/customers', 'POST', $post_body );
        if ( $response && ! empty( $response['success'] ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $new_pid = self::pancake_customer_api_id_from_row( $response['data'] );
            if ( $new_pid !== '' ) {
                $wpdb->update(
                    $table,
                    [ 'pancake_customer_id' => $new_pid ],
                    [ 'id' => $bacera_customer_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                return true;
            }
        }

        return false;
    }
}