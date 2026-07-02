<?php
/**
 * BD Site Link - Helper Functions
 */

if (!defined('ABSPATH')) exit;

/**
 * Check if an IP is within a range (supports CIDR notation)
 */
function bdsl_ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }
    
    list($subnet, $mask) = explode('/', $range);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long = -1 << (32 - (int)$mask);
    
    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

/**
 * Check rate limiting
 */
function bdsl_check_rate_limit($limit = 100) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $transient_key = 'bdsl_rate_' . md5($ip);
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    if ($attempts >= $limit) {
        return false;
    }
    
    set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    return true;
}

/**
 * Log failed authentication attempt
 */
function bdsl_log_failed_attempt() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $transient_key = 'bdsl_failed_' . md5($ip);
    $failures = get_transient($transient_key) ?: 0;
    set_transient($transient_key, $failures + 1, HOUR_IN_SECONDS);
    
    $count = get_transient('bdsl_failed_attempts_24h') ?: 0;
    set_transient('bdsl_failed_attempts_24h', $count + 1, DAY_IN_SECONDS);
}

/**
 * Send webhook notification
 */
function bdsl_send_webhook($event_type) {
    $webhook_url = get_option('bdsl_webhook_url');
    $notification_events = get_option('bdsl_notification_events', []);
    
    if (empty($webhook_url) || !in_array($event_type, $notification_events)) {
        return;
    }
    
    $payload = [
        'site_url' => get_site_url(),
        'site_name' => get_option('bdsl_site_name', get_bloginfo('name')),
        'event' => $event_type,
        'timestamp' => current_time('mysql'),
        'data' => bdsl_get_webhook_data($event_type),
    ];
    
    wp_remote_post($webhook_url, [
        'body' => json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 5,
    ]);
}

/**
 * Get data for webhook based on event type
 */
function bdsl_get_webhook_data($event_type) {
    switch ($event_type) {
        case 'auth_failures':
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            return [
                'failed_attempts' => get_transient('bdsl_failed_' . md5($ip)) ?: 0,
                'ip' => $ip,
            ];
        case 'form_submissions':
            return ['message' => 'New form submission received'];
        default:
            return [];
    }
}

/**
 * Get database size
 */
function bdsl_get_database_size() {
    global $wpdb;
    $size = $wpdb->get_var(
        "SELECT SUM(data_length + index_length) 
         FROM information_schema.TABLES 
         WHERE table_schema = '{$wpdb->dbname}'"
    );
    return $size ? size_format($size) : 'Unknown';
}

/**
 * Format page data for API response
 */
function bdsl_format_page_data($page, $include_content = false) {
    $data = [
        'id' => $page->ID,
        'title' => $page->post_title,
        'slug' => $page->post_name,
        'status' => $page->post_status,
        'url' => get_permalink($page->ID),
        'parent' => $page->post_parent,
        'template' => get_post_meta($page->ID, '_wp_page_template', true) ?: 'default',
        'modified' => $page->post_modified,
        'featured_image' => get_the_post_thumbnail_url($page->ID, 'full'),
    ];
    
    if ($include_content) {
        $data['content'] = $page->post_content;
        $data['excerpt'] = $page->post_excerpt;
        $data['seo'] = bdsl_get_seo_meta($page->ID);
    }
    
    return $data;
}

/**
 * Format post data for API response
 */
function bdsl_format_post_data($post, $include_content = false) {
    $data = [
        'id' => $post->ID,
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'url' => get_permalink($post->ID),
        'date' => $post->post_date,
        'modified' => $post->post_modified,
        'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
        'tags' => wp_get_post_tags($post->ID, ['fields' => 'names']),
        'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
    ];
    
    if ($include_content) {
        $data['content'] = $post->post_content;
        $data['excerpt'] = $post->post_excerpt;
        $data['seo'] = bdsl_get_seo_meta($post->ID);
    }
    
    return $data;
}

/**
 * Get SEO meta data (RankMath or Yoast)
 */
function bdsl_get_seo_meta($post_id) {
    $seo = [];
    
    if (class_exists('RankMath')) {
        $seo['meta_title'] = get_post_meta($post_id, 'rank_math_title', true);
        $seo['meta_description'] = get_post_meta($post_id, 'rank_math_description', true);
        $seo['focus_keyword'] = get_post_meta($post_id, 'rank_math_focus_keyword', true);
    } elseif (defined('WPSEO_VERSION')) {
        $seo['meta_title'] = get_post_meta($post_id, '_yoast_wpseo_title', true);
        $seo['meta_description'] = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        $seo['focus_keyword'] = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    }
    
    return $seo;
}

/**
 * Set SEO meta data (RankMath or Yoast)
 */
function bdsl_set_seo_meta($post_id, $request) {
    $meta_title = $request->get_param('meta_title');
    $meta_description = $request->get_param('meta_description');
    $focus_keyword = $request->get_param('focus_keyword');
    
    if (class_exists('RankMath')) {
        if ($meta_title) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($meta_title));
        }
        if ($meta_description) {
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_description));
        }
        if ($focus_keyword) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($focus_keyword));
        }
    } elseif (defined('WPSEO_VERSION')) {
        if ($meta_title) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($meta_title));
        }
        if ($meta_description) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
        }
        if ($focus_keyword) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focus_keyword));
        }
    }
}

/**
 * Sideload image from URL to media library
 */
function bdsl_sideload_image($url, $post_id = 0, $title = '') {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    
    $tmp = download_url($url);
    
    if (is_wp_error($tmp)) {
        return $tmp;
    }
    
    $file_array = [
        'name' => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];
    
    // Add extension if missing
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $file_array['name'])) {
        $file_array['name'] .= '.jpg';
    }
    
    $attachment_id = media_handle_sideload($file_array, $post_id, $title);
    
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
    }
    
    return $attachment_id;
}

/**
 * Get connection statistics
 */
function bdsl_get_connection_stats() {
    return [
        'total_requests' => get_transient('bdsl_total_requests_24h') ?: 0,
        'failed_attempts' => get_transient('bdsl_failed_attempts_24h') ?: 0,
        'last_request' => get_option('bdsl_last_request_time'),
    ];
}
