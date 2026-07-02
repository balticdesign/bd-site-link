<?php
/**
 * BD Site Link - Core Endpoints
 */

if (!defined('ABSPATH')) exit;

class BDSL_Endpoint_Core {
    
    private $namespace = 'bdstatus/v1';
    
    /**
     * Register routes
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/plugins', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugins'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'get_health'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/errors', [
            'methods' => 'GET',
            'callback' => [$this, 'get_errors'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/errors/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_errors'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        register_rest_route($this->namespace, '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/cors-check', [
            'methods' => 'GET',
            'callback' => [$this, 'cors_check'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * GET /status - Site information
     */
    public function get_status() {
        global $wpdb;
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'site_url' => get_site_url(),
                'home_url' => get_home_url(),
                'admin_email' => get_bloginfo('admin_email'),
                'site_name' => get_option('bdsl_site_name', get_bloginfo('name')),
                'timezone' => get_option('timezone_string') ?: date_default_timezone_get(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'mysql_version' => $wpdb->db_version(),
                'theme' => wp_get_theme()->get('Name'),
                'theme_version' => wp_get_theme()->get('Version'),
                'child_theme' => is_child_theme(),
                'parent_theme' => is_child_theme() ? wp_get_theme()->parent()->get('Name') : null,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
                'is_multisite' => is_multisite(),
                'memory_limit' => WP_MEMORY_LIMIT,
                'max_execution_time' => ini_get('max_execution_time'),
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /plugins - Plugin list
     */
    public function get_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $active = get_option('active_plugins', []);
        $network_active = is_multisite() ? get_site_option('active_sitewide_plugins', []) : [];
        
        $list = [];
        foreach ($all_plugins as $path => $data) {
            $list[] = [
                'name' => $data['Name'],
                'version' => $data['Version'],
                'author' => strip_tags($data['Author'] ?? ''),
                'active' => in_array($path, $active),
                'network_active' => isset($network_active[$path]),
                'slug' => dirname($path),
                'path' => $path,
                'requires_wp' => $data['RequiresWP'] ?? '',
                'requires_php' => $data['RequiresPHP'] ?? '',
            ];
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'total_plugins' => count($list),
                'active_plugins' => count(array_filter($list, fn($p) => $p['active'])),
                'plugins' => $list,
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /health - Site health diagnostics
     */
    public function get_health() {
        $upload_dir = wp_upload_dir();
        
        $error_log_path = ini_get('error_log');
        $recent_errors = [];
        if ($error_log_path && file_exists($error_log_path) && is_readable($error_log_path)) {
            $lines = @file($error_log_path);
            if ($lines) {
                $recent_errors = array_slice(array_filter($lines), -10);
            }
        }
        
        $response = [
            'success' => true,
            'data' => [
                'checks' => [
                    'uploads_writable' => wp_is_writable($upload_dir['basedir']),
                    'uploads_path' => $upload_dir['basedir'],
                    'https' => is_ssl(),
                    'php_version_adequate' => version_compare(PHP_VERSION, '7.4', '>='),
                    'wp_debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
                    'debug_log_enabled' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                ],
                'memory' => [
                    'limit' => WP_MEMORY_LIMIT,
                    'current_usage' => size_format(memory_get_usage(true)),
                    'peak_usage' => size_format(memory_get_peak_usage(true)),
                ],
                'cron' => [
                    'enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
                    'next_scheduled' => wp_next_scheduled('wp_version_check'),
                ],
                'database' => [
                    'size' => bdsl_get_database_size(),
                ],
                'recent_error_count' => count($recent_errors),
            ],
            'timestamp' => current_time('mysql'),
        ];
        
        // Send webhook if issues detected
        if (!$response['data']['checks']['uploads_writable'] || 
            !$response['data']['checks']['https'] ||
            count($recent_errors) > 5) {
            bdsl_send_webhook('health_issues');
        }
        
        return rest_ensure_response($response);
    }
    
    /**
     * GET /errors - Error logs
     */
    public function get_errors() {
        $errors = [];
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        
        if (file_exists($debug_log) && is_readable($debug_log)) {
            $log_lines = explode("\n", file_get_contents($debug_log));
            
            foreach (array_slice($log_lines, -100) as $line) {
                if (empty(trim($line))) continue;
                
                $error = [
                    'raw' => $line,
                    'type' => 'unknown',
                    'severity' => 'info',
                ];
                
                if (stripos($line, 'Fatal error') !== false) {
                    $error['type'] = 'fatal';
                    $error['severity'] = 'critical';
                } elseif (stripos($line, 'Warning') !== false) {
                    $error['type'] = 'warning';
                    $error['severity'] = 'warning';
                } elseif (stripos($line, 'Notice') !== false) {
                    $error['type'] = 'notice';
                    $error['severity'] = 'info';
                } elseif (stripos($line, 'Error') !== false) {
                    $error['type'] = 'error';
                    $error['severity'] = 'error';
                }
                
                if (preg_match('/\[([\d-]+ [\d:]+)\]/', $line, $matches)) {
                    $error['timestamp'] = $matches[1];
                }
                
                if (preg_match('/plugins\/([\w-]+)\//', $line, $matches)) {
                    $error['plugin'] = $matches[1];
                }
                
                $errors[] = $error;
            }
        }
        
        global $wpdb;
        if (!empty($wpdb->last_error)) {
            $errors[] = [
                'raw' => $wpdb->last_error,
                'type' => 'database',
                'severity' => 'error',
                'timestamp' => current_time('mysql'),
            ];
        }
        
        return rest_ensure_response([
            'success' => true,
            'errors' => $errors,
            'total' => count($errors),
            'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'debug_log_exists' => file_exists($debug_log),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /errors/clear - Clear error logs
     */
    public function clear_errors() {
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        
        if (file_exists($debug_log) && is_writable($debug_log)) {
            file_put_contents($debug_log, '');
            return rest_ensure_response([
                'success' => true,
                'message' => 'Error log cleared successfully',
                'timestamp' => current_time('mysql'),
            ]);
        }
        
        return rest_ensure_response([
            'success' => false,
            'message' => 'Could not clear error log - file not found or not writable',
        ]);
    }
    
    /**
     * GET /settings - Plugin settings
     */
    public function get_settings() {
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'site_name' => get_option('bdsl_site_name', get_bloginfo('name')),
                'contact_email' => get_option('bdsl_contact_email', get_bloginfo('admin_email')),
                'webhook_url' => get_option('bdsl_webhook_url', ''),
                'rate_limit' => get_option('bdsl_rate_limit', 100),
                'ip_restriction_enabled' => get_option('bdsl_enable_ip_restriction', false),
                'logging_enabled' => get_option('bdsl_enable_logging', false),
                'notification_events' => get_option('bdsl_notification_events', []),
                'integrations' => [
                    'gravity_forms' => class_exists('GFAPI'),
                    'rankmath' => class_exists('RankMath'),
                    'yoast' => defined('WPSEO_VERSION'),
                ],
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /cors-check - CORS configuration check
     */
    public function cors_check() {
        $allowed_origins_raw = get_option('bdsl_allowed_crawler_origins', '');
        $dev_mode = get_option('bdsl_enable_dev_mode', false);
        
        $default_origins = ['https://assist.baltic.digital', 'https://baltic-wp-assist.vercel.app', 'http://localhost:3000'];
        $allowed_origins = !empty($allowed_origins_raw) 
            ? array_map(fn($o) => rtrim(trim($o), '/'), array_filter(explode("\n", $allowed_origins_raw))) 
            : $default_origins;
        
        $request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $is_google_preview = strpos($request_origin, '.usercontent.goog') !== false;
        
        return rest_ensure_response([
            'success' => true,
            'cors_enabled' => true,
            'your_origin' => $request_origin,
            'is_whitelisted' => in_array($request_origin, $allowed_origins) || ($dev_mode && $is_google_preview),
            'is_google_preview' => $is_google_preview,
            'dev_mode_enabled' => $dev_mode,
            'allowed_origins' => $allowed_origins,
            'plugin_version' => BDSL_VERSION,
            'timestamp' => current_time('mysql'),
        ]);
    }
}
