<?php
/**
 * BD Site Link - CORS Handler
 */

if (!defined('ABSPATH')) exit;

class BDSL_CORS {
    
    private static $default_origins = [
        'https://assist.baltic.digital',
        'https://baltic-wp-assist.vercel.app',
        'http://localhost:3000',
    ];
    
    /**
     * Initialize CORS handlers
     */
    public static function init() {
        add_action('send_headers', [self::class, 'handle_headers'], 1);
        add_action('rest_api_init', [self::class, 'handle_rest_api']);
    }
    
    /**
     * Get allowed origins
     * 
     * @return array
     */
    private static function get_allowed_origins() {
        $custom_origins = get_option('bdsl_allowed_crawler_origins', '');
        
        if (empty($custom_origins)) {
            return self::$default_origins;
        }
        
        return array_map(
            fn($origin) => rtrim(trim($origin), '/'),
            array_filter(explode("\n", $custom_origins))
        );
    }
    
    /**
     * Check if origin is allowed
     * 
     * @param string $origin
     * @return bool
     */
    private static function is_origin_allowed($origin) {
        if (empty($origin)) {
            return false;
        }
        
        $allowed_origins = self::get_allowed_origins();
        
        // Check if in whitelist
        if (in_array($origin, $allowed_origins)) {
            return true;
        }
        
        // Check dev mode for Google AI Studio
        $dev_mode = get_option('bdsl_enable_dev_mode', false);
        $is_google_preview = strpos($origin, '.usercontent.goog') !== false;
        
        if ($dev_mode && $is_google_preview) {
            if (get_option('bdsl_enable_logging')) {
                error_log("BD Site Link: Dev mode - allowing Google preview origin: {$origin}");
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Handle CORS headers for all requests
     */
    public static function handle_headers() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (self::is_origin_allowed($origin)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, User-Agent');
            header('Access-Control-Max-Age: 86400');
        }
        
        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }
    
    /**
     * Handle CORS for REST API requests
     */
    public static function handle_rest_api() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (!self::is_origin_allowed($origin)) {
            return;
        }
        
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        
        add_filter('rest_pre_serve_request', function($served) use ($origin) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
            return $served;
        }, 15);
    }
}
