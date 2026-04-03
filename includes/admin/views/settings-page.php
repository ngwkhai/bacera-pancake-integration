<div class="wrap">
    <h1>Cấu hình Tích hợp Pancake POS cho Bacera</h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'bacera_pancake_options_group' ); ?>
        <?php do_settings_sections( 'bacera_pancake_options_group' ); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Shop ID</th>
                <td><input type="text" name="bacera_pancake_shop_id" value="<?php echo esc_attr( get_option('bacera_pancake_shop_id') ); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">API Key</th>
                <td><input type="password" name="bacera_pancake_api_key" value="<?php echo esc_attr( get_option('bacera_pancake_api_key') ); ?>" class="regular-text" />
                <p class="description">Lấy API Key từ cài đặt Webhook/API trên Pancake POS.</p></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>