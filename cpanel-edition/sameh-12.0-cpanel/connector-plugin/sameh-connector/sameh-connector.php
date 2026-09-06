<?php
/**
 * Plugin Name: SAMEH Connector
 * Description: Thin signed bridge between WordPress and SAMEH 12.0 Core (cPanel Edition). No AI. Settings + REST only.
 * Version: 1.0.0
 * Author: SAMEH
 * Text Domain: sameh-connector
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAMEH_CONNECTOR_VERSION', '1.0.0');
define('SAMEH_CONNECTOR_FILE', __FILE__);
define('SAMEH_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once SAMEH_CONNECTOR_DIR . 'includes/class-sameh-hmac.php';
require_once SAMEH_CONNECTOR_DIR . 'includes/class-sameh-rest.php';
require_once SAMEH_CONNECTOR_DIR . 'includes/class-sameh-settings.php';

add_action('rest_api_init', ['Sameh_Connector_REST', 'register_routes']);
add_action('admin_menu', ['Sameh_Connector_Settings', 'menu']);
add_action('admin_init', ['Sameh_Connector_Settings', 'register']);
