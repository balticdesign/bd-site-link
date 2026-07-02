<?php
/**
 * BD Site Link - Content Management Endpoints
 */

if (!defined('ABSPATH')) exit;

class BDSL_Endpoint_Content {
    
    private $namespace = 'bdstatus/v1';
    
    /**
     * Register routes
     */
    public function register_routes() {
        // Pages
        register_rest_route($this->namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_pages'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
            'args' => [
                'status' => ['default' => 'any', 'type' => 'string'],
                'per_page' => ['default' => 20, 'type' => 'integer'],
                'search' => ['type' => 'string'],
            ],
        ]);
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
        
        register_rest_route($this->namespace, '/pages', [
            'methods' => 'POST',
            'callback' => [$this, 'create_page'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => $this->get_page_args(),
        ]);
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_page'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_page'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        // Posts
        register_rest_route($this->namespace, '/posts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_posts'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
            'args' => [
                'status' => ['default' => 'any', 'type' => 'string'],
                'per_page' => ['default' => 20, 'type' => 'integer'],
                'category' => ['type' => 'string'],
                'search' => ['type' => 'string'],
            ],
        ]);
        
        register_rest_route($this->namespace, '/posts', [
            'methods' => 'POST',
            'callback' => [$this, 'create_post'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => $this->get_post_args(),
        ]);
        
        register_rest_route($this->namespace, '/posts/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_post'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        register_rest_route($this->namespace, '/posts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_post'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
        ]);
        
        // Templates
        register_rest_route($this->namespace, '/templates', [
            'methods' => 'GET',
            'callback' => [$this, 'get_templates'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token'],
        ]);
    }
    
    /**
     * Page endpoint arguments
     */
    private function get_page_args() {
        return [
            'title' => ['required' => true, 'type' => 'string'],
            'content' => ['required' => true, 'type' => 'string'],
            'excerpt' => ['type' => 'string', 'description' => 'Page excerpt/summary'],
            'status' => ['default' => 'draft', 'type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'focus_keyword' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'default' => 0],
            'template' => ['type' => 'string'],
            'featured_image_url' => ['type' => 'string'],
        ];
    }
    
    /**
     * Post endpoint arguments
     */
    private function get_post_args() {
        return [
            'title' => ['required' => true, 'type' => 'string'],
            'content' => ['required' => true, 'type' => 'string'],
            'excerpt' => ['type' => 'string', 'description' => 'Post excerpt/summary'],
            'status' => ['default' => 'draft', 'type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
            'categories' => ['type' => 'array'],
            'tags' => ['type' => 'array'],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'focus_keyword' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'featured_image_url' => ['type' => 'string'],
        ];
    }
    
    // ========================================
    // PAGES
    // ========================================
    
    /**
     * GET /pages - List pages
     */
    public function get_pages($request) {
        $args = [
            'post_type' => 'page',
            'post_status' => $request->get_param('status') ?: 'any',
            'posts_per_page' => min($request->get_param('per_page') ?: 20, 100),
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        
        if ($search = $request->get_param('search')) {
            $args['s'] = $search;
        }
        
        $pages = get_posts($args);
        $result = array_map(fn($page) => bdsl_format_page_data($page), $pages);
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'total' => count($result),
                'pages' => $result,
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /pages/{id} - Get single page
     */
    public function get_page($request) {
        $page = get_post($request['id']);
        
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => bdsl_format_page_data($page, true),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /pages - Create page
     */
    public function create_page($request) {
        $post_data = [
            'post_type' => 'page',
            'post_title' => sanitize_text_field($request->get_param('title')),
            'post_content' => wp_kses_post($request->get_param('content')),
            'post_status' => $request->get_param('status') ?: 'draft',
            'post_author' => 1,
        ];
        
        if ($excerpt = $request->get_param('excerpt')) {
            $post_data['post_excerpt'] = sanitize_textarea_field($excerpt);
        }
        
        if ($slug = $request->get_param('slug')) {
            $post_data['post_name'] = sanitize_title($slug);
        }
        
        if ($parent = $request->get_param('parent')) {
            $post_data['post_parent'] = absint($parent);
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Set template
        if ($template = $request->get_param('template')) {
            update_post_meta($post_id, '_wp_page_template', sanitize_text_field($template));
        }
        
        // Set SEO meta
        bdsl_set_seo_meta($post_id, $request);
        
        // Handle featured image
        if ($image_url = $request->get_param('featured_image_url')) {
            $attachment_id = bdsl_sideload_image($image_url, $post_id);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Page created successfully',
            'data' => bdsl_format_page_data(get_post($post_id), true),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * PUT /pages/{id} - Update page
     */
    public function update_page($request) {
        $page_id = $request['id'];
        $page = get_post($page_id);
        
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }
        
        $post_data = ['ID' => $page_id];
        
        if ($title = $request->get_param('title')) {
            $post_data['post_title'] = sanitize_text_field($title);
        }
        if ($content = $request->get_param('content')) {
            $post_data['post_content'] = wp_kses_post($content);
        }
        if (($excerpt = $request->get_param('excerpt')) !== null) {
            $post_data['post_excerpt'] = sanitize_textarea_field($excerpt);
        }
        if ($status = $request->get_param('status')) {
            $post_data['post_status'] = $status;
        }
        if ($slug = $request->get_param('slug')) {
            $post_data['post_name'] = sanitize_title($slug);
        }
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if ($template = $request->get_param('template')) {
            update_post_meta($page_id, '_wp_page_template', sanitize_text_field($template));
        }
        
        bdsl_set_seo_meta($page_id, $request);
        
        if ($image_url = $request->get_param('featured_image_url')) {
            $attachment_id = bdsl_sideload_image($image_url, $page_id);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                set_post_thumbnail($page_id, $attachment_id);
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Page updated successfully',
            'data' => bdsl_format_page_data(get_post($page_id), true),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * DELETE /pages/{id} - Delete page
     */
    public function delete_page($request) {
        $page_id = $request['id'];
        $page = get_post($page_id);
        
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }
        
        $force = $request->get_param('force') === true;
        $result = wp_delete_post($page_id, $force);
        
        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete page', ['status' => 500]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => $force ? 'Page permanently deleted' : 'Page moved to trash',
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    // ========================================
    // POSTS
    // ========================================
    
    /**
     * GET /posts - List posts
     */
    public function get_posts($request) {
        $args = [
            'post_type' => 'post',
            'post_status' => $request->get_param('status') ?: 'any',
            'posts_per_page' => min($request->get_param('per_page') ?: 20, 100),
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        if ($search = $request->get_param('search')) {
            $args['s'] = $search;
        }
        if ($category = $request->get_param('category')) {
            $args['category_name'] = $category;
        }
        
        $posts = get_posts($args);
        $result = array_map(fn($post) => bdsl_format_post_data($post), $posts);
        
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'total' => count($result),
                'posts' => $result,
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * POST /posts - Create post
     */
    public function create_post($request) {
        $post_data = [
            'post_type' => 'post',
            'post_title' => sanitize_text_field($request->get_param('title')),
            'post_content' => wp_kses_post($request->get_param('content')),
            'post_status' => $request->get_param('status') ?: 'draft',
            'post_author' => 1,
        ];
        
        if ($excerpt = $request->get_param('excerpt')) {
            $post_data['post_excerpt'] = sanitize_textarea_field($excerpt);
        }
        
        if ($slug = $request->get_param('slug')) {
            $post_data['post_name'] = sanitize_title($slug);
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Handle categories
        if ($categories = $request->get_param('categories')) {
            $cat_ids = [];
            foreach ((array)$categories as $cat) {
                $term = get_term_by('name', $cat, 'category') ?: get_term_by('slug', $cat, 'category');
                if ($term) {
                    $cat_ids[] = $term->term_id;
                } else {
                    $new_term = wp_insert_term($cat, 'category');
                    if (!is_wp_error($new_term)) {
                        $cat_ids[] = $new_term['term_id'];
                    }
                }
            }
            if (!empty($cat_ids)) {
                wp_set_post_categories($post_id, $cat_ids);
            }
        }
        
        // Handle tags
        if ($tags = $request->get_param('tags')) {
            wp_set_post_tags($post_id, $tags);
        }
        
        bdsl_set_seo_meta($post_id, $request);
        
        if ($image_url = $request->get_param('featured_image_url')) {
            $attachment_id = bdsl_sideload_image($image_url, $post_id);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Post created successfully',
            'data' => bdsl_format_post_data(get_post($post_id), true),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * PUT /posts/{id} - Update post
     */
    public function update_post($request) {
        $post_id = $request['id'];
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }
        
        $post_data = ['ID' => $post_id];
        
        if ($title = $request->get_param('title')) {
            $post_data['post_title'] = sanitize_text_field($title);
        }
        if ($content = $request->get_param('content')) {
            $post_data['post_content'] = wp_kses_post($content);
        }
        if (($excerpt = $request->get_param('excerpt')) !== null) {
            $post_data['post_excerpt'] = sanitize_textarea_field($excerpt);
        }
        if ($status = $request->get_param('status')) {
            $post_data['post_status'] = $status;
        }
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if ($categories = $request->get_param('categories')) {
            $cat_ids = [];
            foreach ((array)$categories as $cat) {
                $term = get_term_by('name', $cat, 'category') ?: get_term_by('slug', $cat, 'category');
                if ($term) {
                    $cat_ids[] = $term->term_id;
                }
            }
            if (!empty($cat_ids)) {
                wp_set_post_categories($post_id, $cat_ids);
            }
        }
        
        if ($tags = $request->get_param('tags')) {
            wp_set_post_tags($post_id, $tags);
        }
        
        bdsl_set_seo_meta($post_id, $request);
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Post updated successfully',
            'data' => bdsl_format_post_data(get_post($post_id), true),
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * DELETE /posts/{id} - Delete post
     */
    public function delete_post($request) {
        $post_id = $request['id'];
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }
        
        $force = $request->get_param('force') === true;
        $result = wp_delete_post($post_id, $force);
        
        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => $force ? 'Post permanently deleted' : 'Post moved to trash',
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * GET /templates - Available page templates
     */
    public function get_templates() {
        $templates = wp_get_theme()->get_page_templates();
        
        $result = ['default' => 'Default Template'];
        foreach ($templates as $file => $name) {
            $result[$file] = $name;
        }
        
        return rest_ensure_response([
            'success' => true,
            'data' => $result,
            'timestamp' => current_time('mysql'),
        ]);
    }
}
