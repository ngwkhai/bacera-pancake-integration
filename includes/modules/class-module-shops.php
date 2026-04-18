<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Đồng bộ thương hiệu cửa hàng từ Pancake GET /shops (avatar_url, name).
 *
 * Logo hiển thị qua proxy /bacera-img/{Bacera_Utils::PANCAKE_SHOP_LOGO_SLUG} (xem Bacera_Utils::handle_image_streaming).
 */
class Bacera_Module_Shops {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_refresh' ], 35 );
		add_action( 'update_option_bacera_pancake_api_key', [ __CLASS__, 'on_shop_settings_changed' ], 10, 0 );
		add_action( 'update_option_bacera_pancake_shop_id', [ __CLASS__, 'on_shop_settings_changed' ], 10, 0 );
	}

	public static function on_shop_settings_changed() {
		delete_transient( 'bacera_pancake_shop_branding_cache' );
		self::refresh_from_api();
		set_transient( 'bacera_pancake_shop_branding_cache', 1, 12 * HOUR_IN_SECONDS );
	}

	public static function maybe_refresh() {
		if ( ! get_option( 'bacera_pancake_api_key' ) ) {
			return;
		}
		if ( get_transient( 'bacera_pancake_shop_branding_cache' ) ) {
			return;
		}
		$ok = self::refresh_from_api();
		set_transient( 'bacera_pancake_shop_branding_cache', 1, $ok ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return bool True nếu lưu được avatar (hoặc đã có từ trước và API vẫn hợp lệ).
	 */
	public static function refresh_from_api() {
		if ( ! class_exists( 'Pancake_API_Client' ) ) {
			return false;
		}
		$api = new Pancake_API_Client();
		$res = $api->request_shops();
		if ( ! is_array( $res ) || empty( $res['success'] ) || empty( $res['shops'] ) || ! is_array( $res['shops'] ) ) {
			if ( class_exists( 'Bacera_Utils' ) ) {
				Bacera_Utils::log_error( 'GET /shops: phản hồi không hợp lệ hoặc rỗng.' );
			}
			return false;
		}

		$shop_id = (string) get_option( 'bacera_pancake_shop_id', '' );
		$picked  = null;
		foreach ( $res['shops'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $shop_id !== '' && isset( $row['id'] ) && (string) $row['id'] === $shop_id ) {
				$picked = $row;
				break;
			}
		}
		if ( $picked === null ) {
			$picked = $res['shops'][0];
		}
		if ( ! is_array( $picked ) ) {
			return false;
		}

		$ok = false;
		if ( ! empty( $picked['avatar_url'] ) && is_string( $picked['avatar_url'] ) ) {
			update_option( 'bacera_pancake_shop_avatar_source_url', esc_url_raw( $picked['avatar_url'] ), false );
			$ok = true;
		}
		if ( ! empty( $picked['name'] ) && is_string( $picked['name'] ) ) {
			update_option( 'bacera_pancake_shop_name_cached', sanitize_text_field( $picked['name'] ), false );
		}

		return $ok;
	}
}
