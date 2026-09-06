<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sameh_Connector_Settings
{
    const OPTION = 'sameh_connector_options';

    public static function options()
    {
        $defaults = [
            'core_url' => '',
            'site_id' => '',
            'shared_secret' => '',
            'site_token' => '',
        ];
        $opts = get_option(self::OPTION, []);
        if (!is_array($opts)) {
            $opts = [];
        }
        return array_merge($defaults, $opts);
    }

    public static function menu()
    {
        add_options_page(
            'SAMEH Connector',
            'SAMEH Connector',
            'manage_options',
            'sameh-connector',
            [__CLASS__, 'render']
        );
    }

    public static function register()
    {
        register_setting('sameh_connector_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => [],
        ]);
    }

    public static function sanitize($input)
    {
        $out = self::options();
        if (!is_array($input)) {
            return $out;
        }
        $out['core_url'] = isset($input['core_url']) ? esc_url_raw(trim($input['core_url'])) : '';
        $out['site_id'] = isset($input['site_id']) ? preg_replace('/\D+/', '', (string) $input['site_id']) : '';
        $out['shared_secret'] = isset($input['shared_secret']) ? preg_replace('/[^a-fA-F0-9]/', '', (string) $input['shared_secret']) : '';
        $out['site_token'] = isset($input['site_token']) ? preg_replace('/[^a-fA-F0-9]/', '', (string) $input['site_token']) : '';
        return $out;
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = self::options();
        ?>
        <div class="wrap">
          <h1>SAMEH Connector</h1>
          <p>Thin bridge only — paste credentials from SAMEH Core → Site → Pairing.</p>
          <form method="post" action="options.php">
            <?php settings_fields('sameh_connector_group'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="sameh_core_url">Core URL</label></th>
                <td><input name="<?php echo esc_attr(self::OPTION); ?>[core_url]" id="sameh_core_url" type="url" class="regular-text" value="<?php echo esc_attr($opts['core_url']); ?>" placeholder="https://sameh.example.com"></td>
              </tr>
              <tr>
                <th scope="row"><label for="sameh_site_id">Site ID</label></th>
                <td><input name="<?php echo esc_attr(self::OPTION); ?>[site_id]" id="sameh_site_id" type="text" class="regular-text" value="<?php echo esc_attr($opts['site_id']); ?>"></td>
              </tr>
              <tr>
                <th scope="row"><label for="sameh_secret">Shared HMAC Secret</label></th>
                <td><input name="<?php echo esc_attr(self::OPTION); ?>[shared_secret]" id="sameh_secret" type="password" class="regular-text" value="<?php echo esc_attr($opts['shared_secret']); ?>" autocomplete="off"></td>
              </tr>
              <tr>
                <th scope="row"><label for="sameh_token">Connector Token (optional)</label></th>
                <td><input name="<?php echo esc_attr(self::OPTION); ?>[site_token]" id="sameh_token" type="password" class="regular-text" value="<?php echo esc_attr($opts['site_token']); ?>" autocomplete="off">
                <p class="description">Paste the Connector Token from Core pairing. Core sends it as X-Sameh-Site-Token.</p></td>
              </tr>
            </table>
            <?php submit_button('Save'); ?>
          </form>
          <hr>
          <p><strong>REST:</strong> <code><?php echo esc_html(rest_url('sameh-connector/v1/health')); ?></code></p>
          <p>Endpoints require HMAC headers: X-Sameh-Timestamp, X-Sameh-Nonce, X-Sameh-Signature.</p>
        </div>
        <?php
    }
}
