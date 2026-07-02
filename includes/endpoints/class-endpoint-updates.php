<?php
/**
 * BD Site Link - Update Endpoints
 */

if (!defined('ABSPATH')) exit;

class BDSL_Endpoint_Updates {
    
    private $namespace = 'bdstatus/v1';
    
    /**
     * Register routes
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/updates', [
            'methods' => 'GET',
            'callback' => [$this, 'get_updates'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/refresh-updates', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_updates'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/plugins/(?P<slug>[^/]+)/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_plugin'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => [
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
        
        register_rest_route($this->namespace, '/themes/(?P<slug>[^/]+)/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_theme'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => [
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
        
        register_rest_route($this->namespace, '/core/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_core'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
    }
    
    /**
     * GET /updates - Available updates
     */
    public function get_updates() {
        $core = get_site_transient('update_core');
        $plugins = get_site_transient('update_plugins');
        $themes = get_site_transient('update_themes');
        
        $response = [
            'success' => true,
            'data' => [
                'last_checked' => [
                    'core' => $core->last_checked ?? null,
                    'plugins' => $plugins->last_checked ?? null,
                    'themes' => $themes->last_checked ?? null,
                ],
                'core_updates' => $core->updates ?? [],
                'plugin_updates' => $plugins->response ?? [],
                'theme_updates' => $themes->response ?? [],
                'counts' => [
                    'plugins' => count($plugins->response ?? []),
                    'themes' => count($themes->response ?? []),
                    'core' => !empty($core->updates) ? 1 : 0,
                ],
            ],
            'timestamp' => current_time('mysql'),
        ];
        
        // Notify if updates available
        if ($response['data']['counts']['plugins'] > 0 || 
            $response['data']['counts']['themes'] > 0 || 
            $response['data']['counts']['core'] > 0) {
            bdsl_send_webhook('plugin_updates');
        }
        
        return rest_ensure_response($response);
    }
    
    /**
     * POST /refresh-updates - Force update check
     */
    public function refresh_updates() {
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        
        wp_clean_plugins_cache();
        wp_clean_themes_cache();
        
        wp_update_plugins();
        wp_update_themes();
        wp_version_check();
        
        sleep(2);
        
        $plugin_updates = get_site_transient('update_plugins');
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Update check completed',
            'plugins_checked' => isset($plugin_updates->checked) ? count($plugin_updates->checked) : 0,
            'plugins_with_updates' => isset($plugin_updates->response) ? count($plugin_updates->response) : 0,
            'last_checked' => time(),
        ]);
    }
    
    /**
     * POST /plugins/{slug}/update - Update a plugin
     */
    public function update_plugin($request) {
        $slug = $request['slug'];
        
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        
        $all_plugins = get_plugins();
        $plugin_file = null;
        
        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug || $file === "$slug.php") {
                $plugin_file = $file;
                break;
            }
        }
        
        if (!$plugin_file) {
            return new WP_Error('plugin_not_found', "Plugin with slug '$slug' not found", ['status' => 404]);
        }
        
        $was_active = is_plugin_active($plugin_file);
        
        $update_plugins = get_site_transient('update_plugins');
        if (!isset($update_plugins->response[$plugin_file])) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No update available for this plugin',
                'plugin' => $plugin_file,
                'current_version' => $all_plugins[$plugin_file]['Version'] ?? 'unknown',
            ]);
        }
        
        $old_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';
        $new_version = $update_plugins->response[$plugin_file]->new_version ?? 'unknown';
        
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->upgrade($plugin_file);
        
        if (is_wp_error($result)) {
            return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
        }
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Update failed for unknown reason', ['status' => 500]);
        }
        
        // Reactivate if was active
        if ($was_active) {
            $reactivate = activate_plugin($plugin_file);
            if (is_wp_error($reactivate)) {
                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Plugin updated but failed to reactivate',
                    'warning' => 'Manual reactivation may be required',
                    'plugin' => $plugin_file,
                    'old_version' => $old_version,
                    'new_version' => $new_version,
                    'reactivation_error' => $reactivate->get_error_message(),
                    'timestamp' => current_time('mysql'),
                ]);
            }
        }
        
        wp_clean_plugins_cache();
        $updated_plugin = get_plugins()[$plugin_file] ?? null;
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Plugin updated successfully' . ($was_active ? ' and reactivated' : ''),
            'plugin' => $plugin_file,
            'old_version' => $old_version,
            'new_version' => $updated_plugin ? $updated_plugin['Version'] : $new_version,
            'was_reactivated' => $was_active,
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /themes/{slug}/update - Update a theme
     */
    public function update_theme($request) {
        $slug = $request['slug'];
        
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        
        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_Error('theme_not_found', "Theme with slug '$slug' not found", ['status' => 404]);
        }
        
        $update_themes = get_site_transient('update_themes');
        if (!isset($update_themes->response[$slug])) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No update available for this theme',
                'theme' => $slug,
            ]);
        }
        
        $upgrader = new Theme_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->upgrade($slug);
        
        if (is_wp_error($result)) {
            return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
        }
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Update failed for unknown reason', ['status' => 500]);
        }
        
        wp_clean_themes_cache();
        $updated_theme = wp_get_theme($slug);
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Theme updated successfully',
            'theme' => $slug,
            'new_version' => $updated_theme->get('Version'),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /core/update - Update WordPress core
     */
    public function update_core() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        
        wp_version_check();
        $updates = get_core_updates();
        
        if (empty($updates) || !isset($updates[0]) || $updates[0]->response !== 'upgrade') {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No WordPress core update available',
                'current_version' => get_bloginfo('version'),
            ]);
        }
        
        $update = $updates[0];
        $upgrader = new Core_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result = $upgrader->upgrade($update);
        
        if (is_wp_error($result)) {
            return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
        }
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Update failed for unknown reason', ['status' => 500]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'WordPress core updated successfully',
            'new_version' => get_bloginfo('version'),
            'timestamp' => current_time('mysql'),
        ]);
    }
}
