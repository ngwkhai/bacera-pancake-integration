<div class="wrap">
    <h1>Cấu hình Tích hợp Pancake POS cho Bacera</h1>
    <p class="description">Quản lý đồng bộ dữ liệu Cửa hàng</p>
    
    <form method="post" action="options.php">
        <?php settings_fields( 'bacera_pancake_options_group' ); ?>
        <?php do_settings_sections( 'bacera_pancake_options_group' ); ?>
        
        <table class="form-table" style="max-w: 800px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            
            <tr valign="top">
                <th scope="row"><label for="bacera_pancake_shop_id">Shop ID</label></th>
                <td>
                    <input type="text" id="bacera_pancake_shop_id" name="bacera_pancake_shop_id" value="<?php echo esc_attr( get_option('bacera_pancake_shop_id') ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><label for="bacera_pancake_api_key">API Key</label></th>
                <td>
                    <input type="password" id="bacera_pancake_api_key" name="bacera_pancake_api_key" value="<?php echo esc_attr( get_option('bacera_pancake_api_key') ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><label for="bacera_pancake_default_warehouse">Warehouse ID</label></th>
                <td>
                    <input type="text" id="bacera_pancake_default_warehouse" name="bacera_pancake_default_warehouse" value="<?php echo esc_attr( get_option('bacera_pancake_default_warehouse') ); ?>" class="regular-text" />
                    
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Trạng thái Đồng bộ</th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="bacera_pancake_sync_products" value="1" <?php checked( 1, get_option( 'bacera_pancake_sync_products', 1 ) ); ?> />
                            <strong>Đồng bộ Sản phẩm</strong>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="bacera_pancake_sync_orders" value="1" <?php checked( 1, get_option( 'bacera_pancake_sync_orders', 1 ) ); ?> />
                            <strong>Đồng bộ Đơn hàng</strong>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Webhook URL</th>
                <td>
                    <code style="padding: 6px 10px; background: #f0f0f1; border-left: 4px solid #2271b1; user-select: all; display: inline-block;">
                        <?php echo esc_url( rest_url( 'bacera/v1/pancake-webhook' ) ); ?>
                    </code>
                    
                </td>
            </tr>

        </table>
        
        <?php submit_button('Lưu cấu hình'); ?>
    </form>
</div>