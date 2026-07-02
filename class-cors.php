<?php
/**
 * Plugin Name: BD Site Link
 * Plugin URI: https://baltic.design/plugins/bd-site-link
 * Description: REST API for AI-based WordPress site management. Enables remote monitoring, updates, and content management.
 * Version: 1.8.0
 * Author: Baltic Design
 * Author URI: https://baltic.design
 * License: GPL v2 or later
 * Text Domain: bd-site-link
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

// Plugin constants
define('BDSL_VERSION', '1.8.0');
define('BDSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BDSL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BDSL_PLUGIN_FILE', __FILE__);

// Define token from options if not in wp-config.php
if (!defined('BD_SITE_LINK_TOKEN')) {
    $stored_token = get_option('bdsl_api_token');
    define('BD_SITE_LINK_TOKEN', $stored_token ?: '');
}

// Autoloader for includes
spl_autoload_register(function ($class) {
    $prefix = 'BDSL_';
    if (strpos($class, $prefix) !== 0) return;
    
    $relative_class = substr($class, strlen($prefix));
    $file = BDSL_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    
    if (file_exists($file)) require_once $file;
});

// Load core files
require_once BDSL_PLUGIN_DIR . 'includes/functions-helpers.php';
require_once BDSL_PLUGIN_DIR . 'includes/class-authentication.php';
require_once BDSL_PLUGIN_DIR . 'includes/class-cors.php';

// Load REST API endpoints
require_once BDSL_PLUGIN_DIR . 'includes/endpoints/class-endpoint-core.php';
require_once BDSL_PLUGIN_DIR . 'includes/endpoints/class-endpoint-updates.php';
require_once BDSL_PLUGIN_DIR . 'includes/endpoints/class-endpoint-content.php';
require_once BDSL_PLUGIN_DIR . 'includes/endpoints/class-endpoint-media.php';
require_once BDSL_PLUGIN_DIR . 'includes/endpoints/class-endpoint-integrations.php';

// Load admin if in admin context
if (is_admin()) {
    require_once BDSL_PLUGIN_DIR . 'admin/class-admin-settings.php';
}

/**
 * Initialize the plugin
 */
class BD_Site_Link {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Track API requests
        add_action('rest_api_init', [$this, 'track_requests']);
        
        // Initialize CORS
        BDSL_CORS::init();
        
        // Initialize admin
        if (is_admin()) {
            new BDSL_Admin_Settings();
        }
        
        // Umami analytics injection
        add_action('wp_head', [$this, 'inject_analytics']);
        
        // Gravity Forms webhook
        add_action('gform_after_submission', [$this, 'notify_form_submission'], 10, 2);
    }
    
    public function register_routes() {
        $endpoints = [
            new BDSL_Endpoint_Core(),
            new BDSL_Endpoint_Updates(),
            new BDSL_Endpoint_Content(),
            new BDSL_Endpoint_Media(),
            new BDSL_Endpoint_Integrations(),
        ];
        
        foreach ($endpoints as $endpoint) {
            $endpoint->register_routes();
        }
    }
    
    public function track_requests() {
        add_filter('rest_pre_dispatch', function($result, $server, $request) {
            if (strpos($request->get_route(), '/bdstatus/v1/') === 0) {
                $count = get_transient('bdsl_total_requests_24h') ?: 0;
                set_transient('bdsl_total_requests_24h', $count + 1, DAY_IN_SECONDS);
                update_option('bdsl_last_request_time', current_time('mysql'));
            }
            return $result;
        }, 10, 3);
    }
    
    public function inject_analytics() {
        if (is_admin() || !get_option('bdsl_umami_enabled', false)) return;
        
        $website_id = get_option('bdsl_umami_website_id');
        $umami_url = rtrim(get_option('bdsl_umami_url'), '/');
        
        if (empty($website_id) || empty($umami_url)) return;
        
        printf(
            '<script async defer src="%s/script.js" data-website-id="%s"></script>',
            esc_url($umami_url),
            esc_attr($website_id)
        );
    }
    
    public function notify_form_submission($entry, $form) {
        bdsl_send_webhook('form_submissions');
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    BD_Site_Link::instance();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    // Set default options
    if (!get_option('bdsl_rate_limit')) {
        update_option('bdsl_rate_limit', 100);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
