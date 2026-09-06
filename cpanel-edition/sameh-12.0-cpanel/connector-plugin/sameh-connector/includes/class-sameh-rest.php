<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sameh_Connector_REST
{
    const NS = 'sameh-connector/v1';

    public static function register_routes()
    {
        register_rest_route(self::NS, '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'health'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/discover', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'discover'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/ping', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'ping'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
    }

    public static function permission(WP_REST_Request $request)
    {
        $opts = Sameh_Connector_Settings::options();
        $secret = isset($opts['shared_secret']) ? (string) $opts['shared_secret'] : '';
        $check = Sameh_Connector_HMAC::verify_request($request, $secret);
        if (is_wp_error($check)) {
            return $check;
        }

        $token = (string) $request->get_header('x-sameh-site-token');
        $expected_token = isset($opts['site_token']) ? (string) $opts['site_token'] : '';
        if ($expected_token !== '' && !hash_equals($expected_token, $token)) {
            return new WP_Error('sameh_bad_token', 'Invalid site token', ['status' => 401]);
        }
        return true;
    }

    public static function health(WP_REST_Request $request)
    {
        return [
            'ok' => true,
            'plugin' => 'sameh-connector',
            'version' => SAMEH_CONNECTOR_VERSION,
            'time' => gmdate('c'),
            'site_url' => home_url('/'),
        ];
    }

    public static function discover(WP_REST_Request $request)
    {
        global $wp_version;
        $theme = wp_get_theme();
        $active = (array) get_option('active_plugins', []);
        $slugs = [];
        foreach ($active as $plugin_file) {
            $slugs[] = dirname($plugin_file) === '.' ? $plugin_file : dirname($plugin_file);
        }

        $counts = [
            'posts' => (int) wp_count_posts('post')->publish,
            'pages' => (int) wp_count_posts('page')->publish,
        ];

        return [
            'ok' => true,
            'wp_version' => $wp_version,
            'theme' => $theme ? $theme->get('Name') : '',
            'theme_stylesheet' => $theme ? $theme->get_stylesheet() : '',
            'active_plugins' => array_values($slugs),
            'counts' => $counts,
            'php_version' => PHP_VERSION,
            'site_url' => home_url('/'),
        ];
    }

    public static function ping(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        return [
            'ok' => true,
            'pong' => true,
            'received' => is_array($data) ? $data : null,
            'time' => gmdate('c'),
        ];
    }
}
