<?php
class Bacera_Utils {
    public static function log_error( $message ) {
        if ( true === WP_DEBUG ) {
            error_log( '[Bacera Pancake POS] ' . $message );
        }
    }

    // Hàm chuyển đổi tiền tệ quốc tế về chuẩn lưu trữ (VND)
    public static function format_currency_for_pos( $amount, $currency_code ) {
        // Logic convert tiền tệ dựa trên tỉ giá hiện tại
        return $amount;
    }
}