<?php
/**
 * BD Site Link - Authentication Handler
 */

if (!defined('ABSPATH')) exit;

class BDSL_Authentication {
    
    /**
     * Check API token authentication
     * 
     * @return bool|WP_Error
     */
    public static function check_token() {
        // Check IP whitelist if enabled
        if (get_option('bdsl_enable_ip_restriction', false)) {
            $ip_check = self::check_ip_whitelist();
            if (is_wp_error($ip_check)) {
                return $ip_check;
            }
        }
        
        // Rate limiting
        $rate_limit = get_option('bdsl_rate_limit', 100);
        if (!bdsl_check_rate_limit($rate_limit)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please try again later.',
                ['status' => 429]
            );
        }
        
        // Get provided token from headers
        $provided = self::get_token_from_headers();
        $token = BD_SITE_LINK_TOKEN;
        
        $valid = !empty($provided) && !empty($token) && hash_equals($token, $provided);
        
        if (!$valid) {
            bdsl_log_failed_attempt();
            bdsl_send_webhook('auth_failures');
            
            if (get_option('bdsl_enable_logging')) {
                error_log('BD Site Link: Failed auth attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            }
        }
        
        return $valid;
    }
    
    /**
     * Check token with admin capabilities granted
     * 
     * @return bool|WP_Error
     */
    public static function check_token_with_admin_rights() {
        $token_valid = self::check_token();
        
        if (is_wp_error($token_valid)) {
            return $token_valid;
        }
        
        if (!$token_valid) {
            return false;
        }
        
        // Grant admin capabilities for this request
        add_filter('user_has_cap', [self::class, 'grant_capabilities'], 10, 3);
        
        return true;
    }
    
    /**
     * Grant update and content capabilities
     */
    public static function grant_capabilities($allcaps, $caps, $args) {
        $granted = [
            'update_plugins',
            'update_themes', 
            'update_core',
            'install_plugins',
            'install_themes',
            'delete_plugins',
            'delete_themes',
            'manage_options',
            'edit_posts',
            'edit_pages',
            'publish_posts',
            'publish_pages',
            'delete_posts',
            'delete_pages',
            'upload_files',
        ];
        
        foreach ($granted as $cap) {
            $allcaps[$cap] = true;
        }
        
        return $allcaps;
    }
    
    /**
     * Get token from request headers
     * 
     * @return string
     */
    private static function get_token_from_headers() {
        $provided = '';
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $provided = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $provided = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $provided = $headers['Authorization'];
            }
        }
        
        return trim(str_replace('Bearer ', '', $provided));
    }
    
    /**
     * Check IP against whitelist
     * 
     * @return bool|WP_Error
     */
    private static function check_ip_whitelist() {
        $allowed_ips = get_option('bdsl_allowed_ips', '');
        
        if (empty($allowed_ips)) {
            return true;
        }
        
        $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowed_list = array_filter(array_map('trim', explode("\n", $allowed_ips)));
        
        foreach ($allowed_list as $allowed_ip) {
            if (bdsl_ip_in_range($current_ip, $allowed_ip)) {
                return true;
            }
        }
        
        bdsl_log_failed_attempt();
        
        return new WP_Error(
            'ip_not_allowed',
            'Your IP address is not whitelisted',
            ['status' => 403]
        );
    }
}
