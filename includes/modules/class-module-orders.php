<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Đơn hàng Pancake POS — POST /shops/{SHOP_ID}/orders
 *
 * - WooCommerce: hook thankyou → build payload từ WC_Order → create_order_via_api().
 * - Giỏ headless (theme): REST POST /bacera-pancake/v1/orders (kèm nonce).
 */
class Bacera_Module_Orders {

	public static function init() {
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'push_order_to_pancake' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Đăng ký REST API tạo đơn (dùng từ checkout theme / SPA).
	 *
	 * Gửi header: X-Bacera-Nonce = giá trị wp_create_nonce( 'bacera_pancake_create_order' ) (in ra ở template PHP).
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'bacera-pancake/v1',
			'/orders',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_create_order' ],
				'permission_callback' => [ __CLASS__, 'rest_permission_create_order' ],
				'args'                => [],
			]
		);
	}

	/**
	 * Đọc nonce từ header: JS gửi `X-Bacera-Nonce`; WP_REST_Request chuẩn hóa khác nhau giữa gạch ngang / gạch dưới.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function get_create_order_nonce_from_request( $request ) {
		$candidates = [
			$request->get_header( 'X_Bacera_Nonce' ),
			$request->get_header( 'X-Bacera-Nonce' ),
			$request->get_header( 'x-bacera-nonce' ),
		];
		foreach ( $candidates as $n ) {
			if ( is_string( $n ) && $n !== '' ) {
				return $n;
			}
		}
		// PHP: header `X-Bacera-Nonce` → $_SERVER['HTTP_X_BACERA_NONCE'].
		if ( ! empty( $_SERVER['HTTP_X_BACERA_NONCE'] ) ) {
			return wp_unslash( $_SERVER['HTTP_X_BACERA_NONCE'] );
		}
		// Một số proxy/nginx không chuyển header tùy chỉnh — client gửi kèm trong JSON.
		$json = $request->get_json_params();
		if ( is_array( $json ) && ! empty( $json['bacera_nonce'] ) && is_string( $json['bacera_nonce'] ) ) {
			return $json['bacera_nonce'];
		}
		$p = $request->get_param( 'bacera_nonce' );
		if ( is_string( $p ) && $p !== '' ) {
			return $p;
		}
		// permission_callback đôi khi chạy trước khi JSON được gộp — đọc lại từ body.
		if ( $request instanceof WP_REST_Request && method_exists( $request, 'get_body' ) ) {
			$raw = $request->get_body();
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded['bacera_nonce'] ) && is_string( $decoded['bacera_nonce'] ) ) {
					return $decoded['bacera_nonce'];
				}
			}
		}
		return '';
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function rest_permission_create_order( $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$nonce = self::get_create_order_nonce_from_request( $request );
		if ( is_string( $nonce ) && $nonce !== '' && wp_verify_nonce( wp_unslash( $nonce ), 'bacera_pancake_create_order' ) ) {
			return true;
		}
		return new WP_Error(
			'bacera_pancake_forbidden',
			__( 'Thiếu hoặc sai nonce. Trang checkout cần gửi header X-Bacera-Nonce.', 'bacera-pancake' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * REST: nhận JSON body giống payload Pancake (xem create_order_via_api).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create_order( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = [];
		}
		unset( $params['bacera_nonce'] );

		$items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : [];
		if ( $items === [] ) {
			return new WP_Error( 'bacera_pancake_bad_request', __( 'Thiếu danh sách items.', 'bacera-pancake' ), [ 'status' => 400 ] );
		}

		$sanitized_items = [];
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$vid = isset( $row['variation_id'] ) ? sanitize_text_field( (string) $row['variation_id'] ) : '';
			$qty = isset( $row['quantity'] ) ? (float) $row['quantity'] : 0;
			if ( $vid === '' || $qty <= 0 ) {
				continue;
			}
			$sanitized_items[] = [
				'variation_id' => $vid,
				'quantity'     => $qty,
				'price'        => isset( $row['price'] ) ? (float) $row['price'] : 0,
				'name'         => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
			];
		}

		if ( $sanitized_items === [] ) {
			return new WP_Error( 'bacera_pancake_bad_request', __( 'Không có dòng hàng hợp lệ (variation_id + quantity).', 'bacera-pancake' ), [ 'status' => 400 ] );
		}

		$name   = isset( $params['bill_full_name'] ) ? sanitize_text_field( (string) $params['bill_full_name'] ) : '';
		$phone  = isset( $params['bill_phone_number'] ) ? sanitize_text_field( (string) $params['bill_phone_number'] ) : '';
		$note   = isset( $params['note'] ) ? sanitize_textarea_field( (string) $params['note'] ) : '';
		$total  = isset( $params['total_amount'] ) ? (float) $params['total_amount'] : 0;
		$disc   = isset( $params['discount'] ) ? (float) $params['discount'] : 0;

		$shipping = isset( $params['shipping_address'] ) && is_array( $params['shipping_address'] ) ? $params['shipping_address'] : [];
		$full_ad  = isset( $shipping['full_address'] ) ? sanitize_textarea_field( (string) $shipping['full_address'] ) : '';

		$order_data = [
			'bill_full_name'    => $name,
			'bill_phone_number' => $phone,
			'shipping_address'  => [
				'full_address' => $full_ad,
			],
			'note'              => $note,
			'items'             => $sanitized_items,
			'total_amount'      => $total,
			'discount'          => $disc,
			'warehouse_id'      => get_option( 'bacera_pancake_default_warehouse', '' ),
		];

		if ( ! empty( $params['bill_email'] ) ) {
			$order_data['bill_email'] = sanitize_email( (string) $params['bill_email'] );
		}

		$result = self::create_order_via_api( $order_data, [ 'source' => 'rest' ] );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'bacera_pancake_api',
				$result['message'] ?? __( 'Tạo đơn thất bại.', 'bacera-pancake' ),
				[ 'status' => 502, 'data' => $result['raw'] ]
			);
		}

		return rest_ensure_response(
			[
				'success'          => true,
				'pancake_order_id' => $result['pancake_order_id'],
				'raw'              => $result['raw'],
			]
		);
	}

	/**
	 * Gửi POST /shops/{SHOP_ID}/orders lên Pancake.
	 *
	 * @param array<string,mixed> $order_data Payload theo API Pancake (bill_*, shipping_address, items, total_amount, discount, warehouse_id, …).
	 * @param array<string,mixed> $args       check_sync (bool, default true), source (string) cho filter/log.
	 * @return array{success:bool,pancake_order_id:?string,message:string,raw:mixed}
	 */
	public static function create_order_via_api( array $order_data, array $args = [] ) {
		$check_sync = isset( $args['check_sync'] ) ? (bool) $args['check_sync'] : true;
		$source     = isset( $args['source'] ) ? (string) $args['source'] : 'direct';

		if ( $check_sync && ! (int) get_option( 'bacera_pancake_sync_orders', 1 ) ) {
			return [
				'success'          => false,
				'pancake_order_id' => null,
				'message'          => __( 'Đồng bộ đơn hàng Pancake đang tắt trong cài đặt.', 'bacera-pancake' ),
				'raw'              => null,
			];
		}

		$order_data = apply_filters( 'bacera_pancake_order_payload', $order_data, $source, $args );

		$api      = new Pancake_API_Client();
		$response = $api->request( '/shops/{SHOP_ID}/orders', 'POST', $order_data );

		if ( $response && isset( $response['success'] ) && true === $response['success'] ) {
			$pancake_id = null;
			if ( isset( $response['data']['id'] ) ) {
				$pancake_id = is_scalar( $response['data']['id'] ) ? (string) $response['data']['id'] : null;
			}
			do_action( 'bacera_pancake_order_created', $pancake_id, $order_data, $source, $response );
			if ( class_exists( 'Bacera_Utils' ) ) {
				Bacera_Utils::log_error( 'Pancake: tạo đơn thành công (' . $source . ') ID=' . (string) $pancake_id );
			}
			return [
				'success'          => true,
				'pancake_order_id' => $pancake_id,
				'message'          => '',
				'raw'              => $response,
			];
		}

		$msg = __( 'API Pancake trả lỗi hoặc không hợp lệ.', 'bacera-pancake' );
		if ( class_exists( 'Bacera_Utils' ) ) {
			Bacera_Utils::log_error( 'Pancake create_order: ' . wp_json_encode( $response ) );
		}

		return [
			'success'          => false,
			'pancake_order_id' => null,
			'message'          => $msg,
			'raw'              => $response,
		];
	}

	/**
	 * Build payload từ WC_Order (WooCommerce).
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function build_payload_from_wc_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}

		$items_payload = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$pancake_var_id = $product->get_meta( '_pancake_variation_id' );
			$items_payload[] = [
				'variation_id' => $pancake_var_id ? (string) $pancake_var_id : '',
				'quantity'     => (float) $item->get_quantity(),
				'price'        => (float) $order->get_item_total( $item, false, false ),
				'name'         => $item->get_name(),
			];
		}

		$warehouse_id = get_option( 'bacera_pancake_default_warehouse', '' );

		$ship1 = $order->get_shipping_address_1();
		$bill1 = $order->get_billing_address_1();
		$city  = $order->get_shipping_city() ?: $order->get_billing_city();
		$state = $order->get_shipping_state() ?: $order->get_billing_state();

		$addr_parts = array_filter(
			[
				$ship1 ?: $bill1,
				$city,
				$state,
			]
		);
		$full_address = implode( ', ', $addr_parts );

		return [
			'bill_full_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'bill_phone_number' => $order->get_billing_phone(),
			'bill_email'        => $order->get_billing_email(),
			'shipping_address'  => [
				'full_address' => $full_address,
			],
			'note'              => 'Đơn từ Website: #' . $order->get_order_number() . ' | ' . $order->get_customer_note(),
			'items'             => $items_payload,
			'total_amount'      => (float) $order->get_total(),
			'discount'          => (float) $order->get_discount_total(),
			'warehouse_id'      => $warehouse_id,
		];
	}

	/**
	 * Hook: sau thanh toán WC — đẩy đơn lên Pancake (một lần / đơn).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function push_order_to_pancake( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		if ( ! get_option( 'bacera_pancake_sync_orders', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_pancake_order_id' ) ) {
			return;
		}

		$order_data = self::build_payload_from_wc_order( $order );
		if ( empty( $order_data['items'] ) ) {
			if ( class_exists( 'Bacera_Utils' ) ) {
				Bacera_Utils::log_error( 'Pancake: bỏ qua đơn WC #' . (int) $order_id . ' — không có line item hợp lệ.' );
			}
			return;
		}

		$result = self::create_order_via_api( $order_data, [ 'check_sync' => false, 'source' => 'woocommerce', 'wc_order_id' => $order_id ] );

		if ( $result['success'] && ! empty( $result['pancake_order_id'] ) ) {
			$order->update_meta_data( '_pancake_order_id', $result['pancake_order_id'] );
			$order->save();
			if ( class_exists( 'Bacera_Utils' ) ) {
				Bacera_Utils::log_error( 'Thành công: Đã tạo đơn hàng #' . $order_id . ' lên Pancake POS.' );
			}
		} elseif ( class_exists( 'Bacera_Utils' ) ) {
			Bacera_Utils::log_error( 'Lỗi tạo đơn hàng #' . $order_id . ': ' . wp_json_encode( $result['raw'] ) );
		}
	}

	/**
	 * Lấy danh sách đơn (GET /shops/{SHOP_ID}/orders).
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array|false
	 */
	public static function get_orders( $args = [] ) {
		$api      = new Pancake_API_Client();
		$endpoint = '/shops/{SHOP_ID}/orders';

		if ( ! empty( $args ) ) {
			$endpoint .= '?' . http_build_query( $args );
		}

		return $api->request( $endpoint, 'GET' );
	}

	/**
	 * Chi tiết một đơn (GET /shops/{SHOP_ID}/orders/{ORDER_ID}).
	 *
	 * @param string $pancake_order_id ID Pancake.
	 * @return array|false
	 */
	public static function get_order_detail( $pancake_order_id ) {
		if ( empty( $pancake_order_id ) ) {
			return false;
		}

		$api      = new Pancake_API_Client();
		$endpoint = '/shops/{SHOP_ID}/orders/' . rawurlencode( (string) $pancake_order_id );

		return $api->request( $endpoint, 'GET' );
	}
}
