<?php
/**
 * BD Site Link - Admin Settings Page
 */

if (!defined('ABSPATH')) exit;

class BDSL_Admin_Settings {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    public function add_menu() {
        add_options_page('BD Site Link Settings', 'BD Site Link', 'manage_options', 'bd-site-link', [$this, 'render_page']);
    }
    
    public function register_settings() {
        register_setting('bdsl_settings', 'bdsl_api_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('bdsl_settings', 'bdsl_site_name');
        register_setting('bdsl_settings', 'bdsl_contact_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('bdsl_settings', 'bdsl_enable_logging');
        register_setting('bdsl_settings', 'bdsl_rate_limit', ['sanitize_callback' => 'absint', 'default' => 100]);
        register_setting('bdsl_settings', 'bdsl_enable_ip_restriction');
        register_setting('bdsl_settings', 'bdsl_allowed_ips', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('bdsl_settings', 'bdsl_webhook_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('bdsl_settings', 'bdsl_notification_events', ['type' => 'array', 'default' => [], 'sanitize_callback' => [$this, 'sanitize_events']]);
        register_setting('bdsl_settings', 'bdsl_allowed_crawler_origins', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_origins']]);
        register_setting('bdsl_settings', 'bdsl_enable_dev_mode', ['type' => 'boolean', 'default' => false]);
    }
    
    public function sanitize_events($input) {
        if (!is_array($input)) return [];
        $allowed = ['plugin_updates', 'theme_updates', 'core_updates', 'health_issues', 'auth_failures', 'form_submissions'];
        return array_intersect($input, $allowed);
    }
    
    public function sanitize_origins($input) {
        if (empty($input)) return '';
        $origins = array_filter(array_map('trim', explode("\n", $input)));
        return implode("\n", array_filter(array_map('esc_url_raw', $origins)));
    }
    
    public function render_page() {
        if (!current_user_can('manage_options')) return;
        
        $token = get_option('bdsl_api_token', '');
        $site_name = get_option('bdsl_site_name', get_bloginfo('name'));
        $contact_email = get_option('bdsl_contact_email', get_bloginfo('admin_email'));
        $enable_logging = get_option('bdsl_enable_logging', false);
        $rate_limit = get_option('bdsl_rate_limit', 100);
        $enable_ip_restriction = get_option('bdsl_enable_ip_restriction', false);
        $allowed_ips = get_option('bdsl_allowed_ips', '');
        $webhook_url = get_option('bdsl_webhook_url', '');
        $notification_events = get_option('bdsl_notification_events', []);
        $crawler_origins = get_option('bdsl_allowed_crawler_origins', '');
        $dev_mode = get_option('bdsl_enable_dev_mode', false);
        
        if (!is_array($notification_events)) $notification_events = [];
        ?>
        <div class="wrap">
            <h1>🔗 BD Site Link Settings</h1>
            
            <?php if (empty($token)): ?>
            <div class="notice notice-warning"><p><strong>⚠️ Setup Required:</strong> Please enter your API token below.</p></div>
            <?php endif; ?>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1;">
                <h2>📡 API Endpoints</h2>
                <p><strong>Base URL:</strong> <code><?php echo esc_url(get_rest_url(null, 'bdstatus/v1')); ?></code></p>
                <h3>Available Endpoints:</h3>
                <ul style="font-family: monospace; background: #f9f9f9; padding: 15px;">
                    <li>✓ GET /status, /plugins, /updates, /health, /errors</li>
                    <li>✓ POST /plugins/{slug}/update, /themes/{slug}/update, /core/update</li>
                    <li>✓ GET/POST/PUT/DELETE /pages, /posts - Content management</li>
                    <li>✓ POST /media/upload-from-url - Image upload from URL</li>
                    <li>✓ GET /templates - Available page templates</li>
                    <?php if (class_exists('GFAPI')): ?><li>✓ GET /forms, /forms/{id}/entries - Gravity Forms</li><?php endif; ?>
                    <?php if (class_exists('RankMath')): ?><li>✓ GET /analytics - RankMath SEO analytics</li><?php endif; ?>
                </ul>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('bdsl_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>API Token</th>
                        <td>
                            <input type="password" name="bdsl_api_token" value="<?php echo esc_attr($token); ?>" class="regular-text" style="width: 400px;">
                            <p class="description">Paste token from your management app. Used to authenticate API requests.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Site Display Name</th>
                        <td><input type="text" name="bdsl_site_name" value="<?php echo esc_attr($site_name); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Contact Email</th>
                        <td><input type="email" name="bdsl_contact_email" value="<?php echo esc_attr($contact_email); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Rate Limit (per hour)</th>
                        <td><input type="number" name="bdsl_rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="10" max="1000" class="small-text"> requests/hour/IP</td>
                    </tr>
                    <tr>
                        <th>IP Restriction</th>
                        <td>
                            <label><input type="checkbox" name="bdsl_enable_ip_restriction" value="1" <?php checked($enable_ip_restriction); ?>> Enable IP whitelist</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Allowed IPs</th>
                        <td>
                            <textarea name="bdsl_allowed_ips" rows="4" class="large-text code"><?php echo esc_textarea($allowed_ips); ?></textarea>
                            <p class="description">One IP per line. Supports CIDR notation (e.g., 192.168.1.0/24)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook URL</th>
                        <td>
                            <input type="url" name="bdsl_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" class="regular-text" placeholder="https://your-app.com/webhook">
                            <p class="description">Receive notifications when events occur</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Notification Events</th>
                        <td>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="plugin_updates" <?php checked(in_array('plugin_updates', $notification_events)); ?>> Plugin updates</label><br>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="theme_updates" <?php checked(in_array('theme_updates', $notification_events)); ?>> Theme updates</label><br>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="core_updates" <?php checked(in_array('core_updates', $notification_events)); ?>> Core updates</label><br>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="health_issues" <?php checked(in_array('health_issues', $notification_events)); ?>> Health issues</label><br>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="auth_failures" <?php checked(in_array('auth_failures', $notification_events)); ?>> Auth failures</label><br>
                            <?php if (class_exists('GFAPI')): ?>
                            <label><input type="checkbox" name="bdsl_notification_events[]" value="form_submissions" <?php checked(in_array('form_submissions', $notification_events)); ?>> Form submissions</label>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>CORS Origins</th>
                        <td>
                            <textarea name="bdsl_allowed_crawler_origins" rows="3" class="large-text code"><?php echo esc_textarea($crawler_origins); ?></textarea>
                            <p class="description">One URL per line. Default: assist.baltic.digital, localhost:3000</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Development Mode</th>
                        <td>
                            <label><input type="checkbox" name="bdsl_enable_dev_mode" value="1" <?php checked($dev_mode); ?>> Allow Google AI Studio preview</label>
                            <?php if ($dev_mode): ?>
                            <p style="color: #856404; background: #fff3cd; padding: 8px; margin-top: 8px;">⚠️ Dev mode active. Disable before production.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Debug Logging</th>
                        <td><label><input type="checkbox" name="bdsl_enable_logging" value="1" <?php checked($enable_logging); ?>> Log API requests to debug.log</label></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <?php if (!empty($token)): ?>
            <div style="margin-top: 30px; padding: 20px; background: #fff; border-left: 4px solid #00a32a;">
                <h2>📊 Connection Statistics</h2>
                <?php $stats = bdsl_get_connection_stats(); ?>
                <table class="widefat" style="max-width: 500px;">
                    <tr><td>Requests (24h)</td><td><?php echo esc_html($stats['total_requests']); ?></td></tr>
                    <tr><td>Failed Auth (24h)</td><td><?php echo esc_html($stats['failed_attempts']); ?></td></tr>
                    <tr><td>Last Request</td><td><?php echo esc_html($stats['last_request'] ?: 'Never'); ?></td></tr>
                </table>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding: 20px; background: #fff; border-left: 4px solid #00a0d2;">
                <h2>🔌 Plugin Integrations</h2>
                <table class="widefat" style="max-width: 500px;">
                    <tr><td>Gravity Forms</td><td><?php echo class_exists('GFAPI') ? '<span style="color: #00a32a;">✓ Active</span>' : '<span style="color: #999;">○ Not installed</span>'; ?></td></tr>
                    <tr><td>RankMath SEO</td><td><?php echo class_exists('RankMath') ? '<span style="color: #00a32a;">✓ Active</span>' : '<span style="color: #999;">○ Not installed</span>'; ?></td></tr>
                    <tr><td>Yoast SEO</td><td><?php echo defined('WPSEO_VERSION') ? '<span style="color: #00a32a;">✓ Active</span>' : '<span style="color: #999;">○ Not installed</span>'; ?></td></tr>
                </table>
            </div>
            
            <div style="margin-top: 30px; padding: 20px; background: #f0f6fc; border-left: 4px solid #2271b1;">
                <h2>🚀 Quick Test</h2>
                <p>Test your connection:</p>
                <pre style="background: #fff; padding: 15px; overflow-x: auto;">curl -H "Authorization: Bearer YOUR_TOKEN" <?php echo esc_url(get_rest_url(null, 'bdstatus/v1/status')); ?></pre>
            </div>
        </div>
        <?php
    }
}
