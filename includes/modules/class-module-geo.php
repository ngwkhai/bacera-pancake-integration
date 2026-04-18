<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Địa giới hành chính Pancake POS — proxy GET /geo/provinces|districts|communes.
 *
 * Theme checkout gọi REST nội bộ (kèm X-WP-Nonce), không lộ api_key ra trình duyệt.
 */
class Bacera_Module_Geo {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	public static function register_rest_routes() {
		register_rest_route(
			'bacera-pancake/v1',
			'/geo/provinces',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_provinces' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
				'args'                => [
					'country_code' => [
						'type'              => 'string',
						'default'           => '84',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'is_new'       => [
						'type'    => 'string',
						'default' => 'false',
					],
					'all'          => [
						'type'    => 'string',
						'default' => 'false',
					],
				],
			]
		);

		register_rest_route(
			'bacera-pancake/v1',
			'/geo/districts',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_districts' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
				'args'                => [
					'province_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'bacera-pancake/v1',
			'/geo/communes',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_communes' ],
				'permission_callback' => [ __CLASS__, 'rest_permission' ],
				'args'                => [
					'district_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'province_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$nonce = '';
		foreach ( [ 'X_WP_Nonce', 'X-WP-Nonce' ] as $h ) {
			$v = $request->get_header( $h );
			if ( is_string( $v ) && $v !== '' ) {
				$nonce = $v;
				break;
			}
		}
		if ( $nonce === '' && ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
		}
		if ( is_string( $nonce ) && $nonce !== '' && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'Thiếu hoặc sai nonce REST (wp_rest).', 'bacera-pancake' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_provinces( $request ) {
		$api = new Pancake_API_Client();
		$res = $api->request_geo(
			'/geo/provinces',
			[
				'country_code' => (string) $request->get_param( 'country_code' ),
				'is_new'       => self::bool_query_param( $request->get_param( 'is_new' ), false ) ? 'true' : 'false',
				'all'          => self::bool_query_param( $request->get_param( 'all' ), false ) ? 'true' : 'false',
			]
		);
		return self::ensure_geo_data_response( $res );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_districts( $request ) {
		$api = new Pancake_API_Client();
		$res = $api->request_geo(
			'/geo/districts',
			[
				'province_id' => (string) $request->get_param( 'province_id' ),
			]
		);
		return self::ensure_geo_data_response( $res );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_communes( $request ) {
		$api = new Pancake_API_Client();
		$res = $api->request_geo(
			'/geo/communes',
			[
				'district_id' => (string) $request->get_param( 'district_id' ),
				'province_id' => (string) $request->get_param( 'province_id' ),
			]
		);
		return self::ensure_geo_data_response( $res );
	}

	/**
	 * @param mixed $raw Raw param.
	 * @param bool  $def Default.
	 * @return bool
	 */
	private static function bool_query_param( $raw, $def ) {
		if ( $raw === null || $raw === '' ) {
			return $def;
		}
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		$s = strtolower( (string) $raw );
		if ( in_array( $s, [ '1', 'true', 'yes', 'on' ], true ) ) {
			return true;
		}
		if ( in_array( $s, [ '0', 'false', 'no', 'off' ], true ) ) {
			return false;
		}
		return $def;
	}

	/**
	 * @param array|false $res Upstream decoded JSON.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function ensure_geo_data_response( $res ) {
		if ( ! is_array( $res ) || ! array_key_exists( 'data', $res ) || ! is_array( $res['data'] ) ) {
			return new WP_Error(
				'bacera_pancake_geo',
				__( 'Không lấy được dữ liệu địa chỉ từ Pancake.', 'bacera-pancake' ),
				[ 'status' => 502 ]
			);
		}
		return rest_ensure_response( $res );
	}
}
