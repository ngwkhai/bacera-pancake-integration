<?php
class Bacera_Setup {
    public static function init() {
        // Init logic here, like registering custom post types if needed.
    }

    public static function activate() {
        // Chạy khi kích hoạt plugin: Dọn dẹp transient, tạo bảng log, v.v.
        flush_rewrite_rules();
    }
}