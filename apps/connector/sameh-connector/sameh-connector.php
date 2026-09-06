<?php
/**
 * Plugin Name: SAMEH Connector
 * Description: Thin WordPress bridge for SAMEH AI SEO Platform 12.0 — discover + signed REST only. No AI, no dashboard.
 * Version: 12.0.0
 * Author: SAMEH
 * Text Domain: sameh-connector
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAMEH_CONNECTOR_VERSION', '12.0.0');
define('SAMEH_CONNECTOR_OPT_SECRET', 'sameh_connector_shared_secret');

require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-hmac.php';

add_action('rest_api_init', ['Sameh_Connector_REST', 'register_routes']);

register_activation_hook(__FILE__, function () {
    if (!get_option(SAMEH_CONNECTOR_OPT_SECRET)) {
        update_option(SAMEH_CONNECTOR_OPT_SECRET, wp_generate_password(48, false, false));
    }
});
