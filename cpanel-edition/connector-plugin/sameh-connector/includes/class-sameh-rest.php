<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sameh_Connector_REST
{
    const NS = 'sameh-connector/v1';

    /** Allowed Rank Math meta keys only */
    const RANK_MATH_KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_canonical_url',
    ];

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
        register_rest_route(self::NS, '/discover/v2', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'discover_v2'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/ping', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'ping'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);

        // Phase C — draft-only signed endpoints
        register_rest_route(self::NS, '/create_draft', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_draft'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/update_draft', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'update_draft'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/get_post/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_post'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/update_rank_math', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'update_rank_math'],
            'permission_callback' => [__CLASS__, 'permission'],
        ]);
        register_rest_route(self::NS, '/change_post_status', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'change_post_status'],
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

        // Sample titles for growth heuristics (titles only — no secrets)
        $sample = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => 25,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $titles = [];
        foreach ($sample as $p) {
            $titles[] = ['id' => (int) $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'type' => $p->post_type];
        }

        return [
            'ok' => true,
            'wp_version' => $wp_version,
            'theme' => $theme ? $theme->get('Name') : '',
            'theme_stylesheet' => $theme ? $theme->get_stylesheet() : '',
            'active_plugins' => array_values($slugs),
            'counts' => $counts,
            'php_version' => PHP_VERSION,
            'site_url' => home_url('/'),
            'sample_titles' => $titles,
            'page_titles' => array_map(static function ($t) {
                return $t['title'];
            }, $titles),
        ];
    }


    public static function discover_v2(WP_REST_Request $request)
    {
        $base = self::discover($request);
        if (is_wp_error($base)) {
            return $base;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per = min(50, max(5, (int) ($request->get_param('per_page') ?: 25)));
        $offset = ($page - 1) * $per;

        $sample = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => $per,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $items = [];
        foreach ($sample as $p) {
            $items[] = [
                'id' => (int) $p->ID,
                'title' => $p->post_title,
                'slug' => $p->post_name,
                'status' => $p->post_status,
                'type' => $p->post_type,
                'modified' => $p->post_modified_gmt,
            ];
        }

        // Media heuristics (alt + file basename as weak hash)
        $media = [];
        $atts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 40,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        foreach ($atts as $a) {
            $alt = (string) get_post_meta($a->ID, '_wp_attachment_image_alt', true);
            $file = (string) get_post_meta($a->ID, '_wp_attached_file', true);
            $media[] = [
                'id' => (int) $a->ID,
                'alt' => $alt,
                'file' => $file,
                'hash' => $file !== '' ? md5($file) : ('id-' . $a->ID),
                'url' => wp_get_attachment_url($a->ID) ?: '',
            ];
        }

        $counts = $base['counts'];
        $counts['attachments'] = (int) (wp_count_posts('attachment')->inherit ?? 0);

        $base['api_version'] = 'discover/v2';
        $base['page'] = $page;
        $base['per_page'] = $per;
        $base['items'] = $items;
        $base['sample_titles'] = $items;
        $base['page_titles'] = array_map(static function ($t) {
            return is_array($t) ? ($t['title'] ?? '') : (string) $t;
        }, $items);
        $base['media'] = $media;
        $base['counts'] = $counts;
        $base['rank_math_active'] = in_array('seo-by-rank-math', $base['active_plugins'], true);
        return $base;
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

    public static function create_draft(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('sameh_bad_body', 'JSON body required', ['status' => 400]);
        }
        $title = isset($data['title']) ? sanitize_text_field((string) $data['title']) : '';
        if ($title === '') {
            return new WP_Error('sameh_no_title', 'title required', ['status' => 400]);
        }
        // Force draft — ignore any publish attempt
        $postarr = [
            'post_title' => $title,
            'post_content' => isset($data['content']) ? wp_kses_post((string) $data['content']) : '',
            'post_status' => 'draft',
            'post_type' => 'page',
        ];
        if (!empty($data['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $data['slug']);
        }
        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) {
            return $id;
        }
        return [
            'ok' => true,
            'post_id' => (int) $id,
            'status' => 'draft',
        ];
    }

    public static function update_draft(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('sameh_bad_body', 'JSON body required', ['status' => 400]);
        }
        $post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('sameh_no_post', 'post_id invalid', ['status' => 404]);
        }
        $post = get_post($post_id);
        // Only allow updating drafts (or pending) — refuse published content edits here
        if ($post->post_status === 'publish' && empty($data['allow_published_edit'])) {
            return new WP_Error('sameh_refuse_publish_edit', 'Refusing published post edit without explicit flag', ['status' => 403]);
        }

        $update = ['ID' => $post_id];
        if (isset($data['title'])) {
            $update['post_title'] = sanitize_text_field((string) $data['title']);
        }
        if (isset($data['content'])) {
            $content = wp_kses_post((string) $data['content']);
            if (!empty($data['link_ops']) && is_array($data['link_ops'])) {
                foreach ($data['link_ops'] as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    $anchor = isset($op['anchor']) ? esc_html((string) $op['anchor']) : '';
                    $url = isset($op['url']) ? esc_url((string) $op['url']) : '';
                    if ($anchor !== '' && $url !== '') {
                        $content .= "\n<p><a href=\"" . $url . "\">" . $anchor . "</a></p>";
                    }
                }
            }
            $update['post_content'] = $content;
        } elseif (!empty($data['link_ops']) && is_array($data['link_ops'])) {
            $content = $post->post_content;
            foreach ($data['link_ops'] as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $anchor = isset($op['anchor']) ? esc_html((string) $op['anchor']) : '';
                $url = isset($op['url']) ? esc_url((string) $op['url']) : '';
                if ($anchor !== '' && $url !== '') {
                    $content .= "\n<p><a href=\"" . $url . "\">" . $anchor . "</a></p>";
                }
            }
            $update['post_content'] = $content;
        }
        if (isset($data['excerpt'])) {
            $update['post_excerpt'] = sanitize_textarea_field((string) $data['excerpt']);
        }
        // Never change status to publish via update_draft
        $update['post_status'] = in_array($post->post_status, ['draft', 'pending'], true) ? $post->post_status : 'draft';

        $r = wp_update_post($update, true);
        if (is_wp_error($r)) {
            return $r;
        }

        if (!empty($data['meta']['alt']) && $post->post_type === 'attachment') {
            update_post_meta($post_id, '_wp_attachment_image_alt', sanitize_text_field((string) $data['meta']['alt']));
        }

        return [
            'ok' => true,
            'post_id' => $post_id,
            'status' => get_post_status($post_id),
        ];
    }

    public static function get_post(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('sameh_no_post', 'Not found', ['status' => 404]);
        }
        return [
            'ok' => true,
            'post' => [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'type' => $post->post_type,
                'slug' => $post->post_name,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'modified' => $post->post_modified_gmt,
            ],
        ];
    }

    public static function update_rank_math(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('sameh_bad_body', 'JSON body required', ['status' => 400]);
        }
        $post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('sameh_no_post', 'post_id invalid', ['status' => 404]);
        }
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        $updated = [];
        foreach ($meta as $k => $v) {
            $key = (string) $k;
            if (!in_array($key, self::RANK_MATH_KEYS, true)) {
                continue;
            }
            update_post_meta($post_id, $key, sanitize_text_field((string) $v));
            $updated[] = $key;
        }
        return [
            'ok' => true,
            'post_id' => $post_id,
            'updated_keys' => $updated,
        ];
    }

    public static function change_post_status(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('sameh_bad_body', 'JSON body required', ['status' => 400]);
        }
        $post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
        $status = isset($data['status']) ? sanitize_key((string) $data['status']) : '';
        $confirm = !empty($data['confirm_publish']);
        $high = !empty($data['high_risk_approved']);
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('sameh_no_post', 'post_id invalid', ['status' => 404]);
        }
        $allowed = ['draft', 'pending', 'publish', 'private', 'trash'];
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('sameh_bad_status', 'Invalid status', ['status' => 400]);
        }
        if (in_array($status, ['publish', 'trash'], true) && !($confirm && $high)) {
            return new WP_Error(
                'sameh_refuse_publish',
                'Refuse publish/trash unless confirm_publish=1 AND high_risk_approved from Core',
                ['status' => 403]
            );
        }
        $r = wp_update_post(['ID' => $post_id, 'post_status' => $status], true);
        if (is_wp_error($r)) {
            return $r;
        }
        return [
            'ok' => true,
            'post_id' => $post_id,
            'status' => get_post_status($post_id),
        ];
    }
}
