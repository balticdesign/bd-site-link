<?php
/**
 * BD Site Link - Integration Endpoints
 * 
 * Gravity Forms, RankMath SEO, Umami Analytics
 */

if (!defined('ABSPATH')) exit;

class BDSL_Endpoint_Integrations {
    
    private $namespace = 'bdstatus/v1';
    
    /**
     * Register routes
     */
    public function register_routes() {
        // Gravity Forms
        register_rest_route($this->namespace, '/forms', [
            'methods' => 'GET',
            'callback' => [$this, 'get_forms'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/forms/(?P<id>\d+)/entries', [
            'methods' => 'GET',
            'callback' => [$this, 'get_form_entries'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        // RankMath Analytics
        register_rest_route($this->namespace, '/analytics', [
            'methods' => 'GET',
            'callback' => [$this, 'get_rankmath_analytics'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        // Umami Analytics Configuration
        register_rest_route($this->namespace, '/analytics/configure', [
            'methods' => 'POST',
            'callback' => [$this, 'configure_analytics'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => [
                'website_id' => ['required' => true, 'type' => 'string'],
                'umami_url' => ['required' => true, 'type' => 'string'],
            ],
        ]);
        
        register_rest_route($this->namespace, '/analytics/disable', [
            'methods' => 'POST',
            'callback' => [$this, 'disable_analytics'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        register_rest_route($this->namespace, '/analytics/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_analytics_status'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
    }
    
    // ========================================
    // GRAVITY FORMS
    // ========================================
    
    /**
     * GET /forms - List Gravity Forms
     *
     * Optional query params (added in v1.7.2):
     *   since=YYYY-MM-DD  start of date range for period-filtered counts
     *   until=YYYY-MM-DD  end of date range for period-filtered counts
     *
     * When either is provided, each form gains an `entries_in_period` field
     * with the entry count restricted to that range. The lifetime `entries`
     * field is unchanged for backward compatibility.
     */
    public function get_forms($request = null) {
        if (!class_exists('GFAPI')) {
            return new WP_Error(
                'plugin_not_active',
                'Gravity Forms is not installed or active',
                ['status' => 404]
            );
        }
        
        // v1.7.2: optional date range
        $since = $request ? $request->get_param('since') : null;
        $until = $request ? $request->get_param('until') : null;
        $has_period = !empty($since) || !empty($until);
        $period_criteria = ['status' => 'active'];
        if (!empty($since)) {
            $period_criteria['start_date'] = sanitize_text_field($since);
        }
        if (!empty($until)) {
            $period_criteria['end_date'] = sanitize_text_field($until);
        }
        
        $forms = GFAPI::get_forms();
        $form_stats = [];
        
        foreach ($forms as $form) {
            $entry_count = GFAPI::count_entries($form['id']);
            
            $unread_criteria = [
                'status' => 'active',
                'field_filters' => [['key' => 'is_read', 'value' => '0']],
            ];
            $unread_count = GFAPI::count_entries($form['id'], $unread_criteria);
            
            $recent_criteria = [
                'start_date' => date('Y-m-d', strtotime('-7 days')),
                'end_date' => date('Y-m-d'),
                'status' => 'active',
            ];
            $recent_count = GFAPI::count_entries($form['id'], $recent_criteria);
            
            $form_data = [
                'id' => $form['id'],
                'title' => $form['title'],
                'entries' => $entry_count,
                'unread' => $unread_count,
                'recent_7days' => $recent_count,
                'is_active' => $form['is_active'],
                'is_trash' => $form['is_trash'],
            ];
            
            // v1.7.2: include period-filtered count when params provided
            if ($has_period) {
                $form_data['entries_in_period'] = GFAPI::count_entries($form['id'], $period_criteria);
            }
            
            $form_stats[] = $form_data;
        }
        
        $response_data = [
            'total_forms' => count($forms),
            'forms' => $form_stats,
        ];
        
        if ($has_period) {
            $response_data['period'] = [
                'since' => $since,
                'until' => $until,
            ];
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $response_data,
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /forms/{id}/entries - Get form entries
     */
    public function get_form_entries($request) {
        if (!class_exists('GFAPI')) {
            return new WP_Error(
                'plugin_not_active',
                'Gravity Forms is not installed or active',
                ['status' => 404]
            );
        }

        $form_id = $request['id'];
        $form = GFAPI::get_form($form_id);

        if (!$form) {
            return new WP_Error('form_not_found', 'Form not found', ['status' => 404]);
        }

        $days   = $request->get_param('days') ?: 30;
        $status = $request->get_param('status') ?: 'active';
        $limit  = min($request->get_param('limit') ?: 20, 100);

        $search_criteria = [
            'start_date' => date('Y-m-d', strtotime("-{$days} days")),
            'end_date'   => date('Y-m-d'),
            'status'     => $status,
        ];

        $sorting = ['key' => 'date_created', 'direction' => 'DESC'];
        $paging  = ['page_size' => $limit];

        $entries     = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
        $total_count = GFAPI::count_entries($form_id, $search_criteria);

        $unread_criteria = array_merge($search_criteria, [
            'field_filters' => [['key' => 'is_read', 'value' => '0']],
        ]);
        $unread_count = GFAPI::count_entries($form_id, $unread_criteria);

        // v1.8.0: map Gravity Forms' numeric field-id keys to human labels so
        // downstream consumers (lead classifier, dashboard) don't have to guess.
        $field_map  = [];
        $type_index = []; // type => [GF_Field, ...] for deterministic contact extraction
        if (!empty($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $fid = (string) $field->id;
                $field_map[$fid] = [
                    'label' => $field->label,
                    'type'  => $field->type,
                ];
                $type_index[$field->type][] = $field;
            }
        }

        // Resolve a field's value, gathering composite sub-inputs (e.g. 1.3, 1.6 for name).
        $resolve = function ($entry, $fid) {
            $val = rgar($entry, (string) $fid);
            if ($val === '' || $val === null) {
                $parts = [];
                foreach ($entry as $k => $v) {
                    if ($v !== '' && $v !== null && strpos((string) $k, $fid . '.') === 0) {
                        $parts[] = $v;
                    }
                }
                $val = trim(implode(' ', $parts));
            }
            return ($val === '' ? null : $val);
        };

        // v1.8.0: normalised, label-resolved view of each entry.
        $parsed = [];
        foreach ($entries as $entry) {
            $labeled = [];
            foreach ($field_map as $fid => $meta) {
                $val = $resolve($entry, $fid);
                if ($val !== null) {
                    $labeled[$meta['label']] = $val;
                }
            }

            $pick = function ($type) use ($type_index, $resolve, $entry) {
                if (empty($type_index[$type])) return null;
                foreach ($type_index[$type] as $f) {
                    $val = $resolve($entry, (string) $f->id);
                    if ($val !== null) return $val;
                }
                return null;
            };

            $parsed[] = [
                'entry_id'     => (string) rgar($entry, 'id'),
                'date_created' => rgar($entry, 'date_created'), // UTC 'Y-m-d H:i:s'
                'status'       => rgar($entry, 'status'),        // active | spam | trash
                'is_read'      => rgar($entry, 'is_read'),
                'source_url'   => rgar($entry, 'source_url'),
                'name'         => $pick('name'),
                'email'        => $pick('email'),
                'phone'        => $pick('phone'),
                'message'      => $pick('textarea'),
                'fields'       => $labeled, // every non-empty field, label => value
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'form_id'        => $form_id,
                'form_title'     => $form['title'],
                'total_entries'  => $total_count,
                'unread_entries' => $unread_count,
                'field_map'      => $field_map, // v1.8.0
                'entries'        => $entries,    // raw (backward compatible)
                'parsed'         => $parsed,     // v1.8.0 normalised view
                'filters'        => [
                    'days'   => $days,
                    'status' => $status,
                    'limit'  => $limit,
                ],
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }

    // ========================================
    // RANKMATH SEO
    // ========================================
    
    /**
     * GET /analytics - RankMath SEO analytics
     */
    public function get_rankmath_analytics() {
        if (!class_exists('RankMath')) {
            return new WP_Error(
                'plugin_not_active',
                'RankMath SEO is not installed or active',
                ['status' => 404]
            );
        }
        
        global $wpdb;
        
        $table_exists = $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}rank_math_analytics_gsc'"
        );
        
        if (!$table_exists) {
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'message' => 'RankMath is installed but Google Search Console is not connected',
                    'analytics_enabled' => false,
                ],
                'timestamp' => current_time('mysql'),
            ]);
        }
        
        $top_keywords = $wpdb->get_results("
            SELECT keyword, SUM(impressions) as impressions, SUM(clicks) as clicks, AVG(position) as avg_position
            FROM {$wpdb->prefix}rank_math_analytics_gsc
            WHERE created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY keyword
            ORDER BY clicks DESC
            LIMIT 10
        ");
        
        $top_pages = $wpdb->get_results("
            SELECT page, SUM(clicks) as clicks, SUM(impressions) as impressions, 
                   (SUM(clicks) / SUM(impressions) * 100) as ctr, AVG(position) as avg_position
            FROM {$wpdb->prefix}rank_math_analytics_gsc
            WHERE created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY page
            ORDER BY clicks DESC
            LIMIT 10
        ");
        
        $overall_stats = $wpdb->get_row("
            SELECT 
                SUM(impressions) as total_impressions,
                SUM(clicks) as total_clicks,
                AVG(position) as avg_position,
                (SUM(clicks) / SUM(impressions) * 100) as avg_ctr
            FROM {$wpdb->prefix}rank_math_analytics_gsc
            WHERE created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'analytics_enabled' => true,
                'period' => 'Last 30 days',
                'overall' => [
                    'total_impressions' => (int)($overall_stats->total_impressions ?? 0),
                    'total_clicks' => (int)($overall_stats->total_clicks ?? 0),
                    'average_position' => round($overall_stats->avg_position ?? 0, 1),
                    'average_ctr' => round($overall_stats->avg_ctr ?? 0, 2),
                ],
                'top_keywords' => array_map(fn($row) => [
                    'keyword' => $row->keyword,
                    'impressions' => (int)$row->impressions,
                    'clicks' => (int)$row->clicks,
                    'position' => round($row->avg_position, 1),
                ], $top_keywords),
                'top_pages' => array_map(fn($row) => [
                    'page' => $row->page,
                    'clicks' => (int)$row->clicks,
                    'impressions' => (int)$row->impressions,
                    'ctr' => round($row->ctr, 2),
                    'position' => round($row->avg_position, 1),
                ], $top_pages),
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    // ========================================
    // UMAMI ANALYTICS
    // ========================================
    
    /**
     * POST /analytics/configure - Configure Umami analytics
     */
    public function configure_analytics($request) {
        $website_id = $request->get_param('website_id');
        $umami_url = $request->get_param('umami_url');
        
        if (empty($website_id) || empty($umami_url)) {
            return new WP_Error(
                'invalid_params',
                'website_id and umami_url are required',
                ['status' => 400]
            );
        }
        
        update_option('bdsl_umami_website_id', sanitize_text_field($website_id));
        update_option('bdsl_umami_url', esc_url_raw($umami_url));
        update_option('bdsl_umami_enabled', true);
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Analytics configured successfully',
            'website_id' => $website_id,
            'tracking_enabled' => true,
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /analytics/disable - Disable Umami analytics
     */
    public function disable_analytics() {
        update_option('bdsl_umami_enabled', false);
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Analytics disabled',
            'tracking_enabled' => false,
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /analytics/status - Get analytics configuration status
     */
    public function get_analytics_status() {
        $enabled = get_option('bdsl_umami_enabled', false);
        $website_id = get_option('bdsl_umami_website_id', '');
        $umami_url = get_option('bdsl_umami_url', '');
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'enabled' => (bool)$enabled,
                'website_id' => $website_id,
                'umami_url' => $umami_url,
                'configured' => !empty($website_id) && !empty($umami_url),
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
}
