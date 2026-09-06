<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sameh_Connector_REST {
    public static function register_routes(): void {
        register_rest_route('sameh-connector/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('sameh-connector/v1', '/discover', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'discover'],
            'permission_callback' => [self::class, 'permission_signed'],
        ]);
    }

    public static function permission_signed(WP_REST_Request $request) {
        $ok = Sameh_Connector_HMAC::verify_request($request);
        return $ok === true ? true : $ok;
    }

    public static function health(): WP_REST_Response {
        return new WP_REST_Response([
            'status'  => 'ok',
            'service' => 'sameh-connector',
            'version' => SAMEH_CONNECTOR_VERSION,
        ], 200);
    }

    public static function discover(): WP_REST_Response {
        global $wp_version;

        $theme = wp_get_theme();
        $active = (array) get_option('active_plugins', []);
        $plugin_names = [];
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        foreach ($active as $file) {
            if (isset($all[$file]['Name'])) {
                $plugin_names[] = $all[$file]['Name'];
            } else {
                $plugin_names[] = $file;
            }
        }

        $post_count = (int) wp_count_posts('post')->publish;
        $page_count = (int) wp_count_posts('page')->publish;

        return new WP_REST_Response([
            'wp_version'     => $wp_version,
            'theme'          => [
                'name'    => $theme->get('Name'),
                'version' => $theme->get('Version'),
            ],
            'active_plugins' => $plugin_names,
            'counts'         => [
                'posts' => $post_count,
                'pages' => $page_count,
            ],
            'siteurl'        => get_option('siteurl'),
            'home'           => get_option('home'),
            'connector'      => SAMEH_CONNECTOR_VERSION,
        ], 200);
    }
}
