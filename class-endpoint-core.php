<?php
/**
 * BD Site Link - Media Endpoints
 */

if (!defined('ABSPATH')) exit;

class BDSL_Endpoint_Media {
    
    private $namespace = 'bdstatus/v1';
    
    /**
     * Register routes
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/media/upload-from-url', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_from_url'],
            'permission_callback' => [BDSL_Authentication::class, 'check_token_with_admin_rights'],
            'args' => [
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'URL of the image to upload',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title for the attachment',
                ],
                'alt' => [
                    'type' => 'string',
                    'description' => 'Alt text for the image',
                ],
            ],
        ]);
    }
    
    /**
     * POST /media/upload-from-url - Upload image from external URL
     */
    public function upload_from_url($request) {
        $url = $request->get_param('url');
        $title = $request->get_param('title');
        $alt = $request->get_param('alt');
        
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid image URL provided', ['status' => 400]);
        }
        
        // Sideload the image
        $attachment_id = bdsl_sideload_image($url, 0, $title);
        
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        
        // Set alt text if provided
        if ($alt) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }
        
        // Get attachment data
        $attachment_url = wp_get_attachment_url($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => [
                'id' => $attachment_id,
                'url' => $attachment_url,
                'title' => get_the_title($attachment_id),
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'width' => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
                'sizes' => $this->get_image_sizes($attachment_id),
            ],
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * Get available image sizes for an attachment
     */
    private function get_image_sizes($attachment_id) {
        $sizes = [];
        $available_sizes = get_intermediate_image_sizes();
        
        foreach ($available_sizes as $size) {
            $image = wp_get_attachment_image_src($attachment_id, $size);
            if ($image) {
                $sizes[$size] = [
                    'url' => $image[0],
                    'width' => $image[1],
                    'height' => $image[2],
                ];
            }
        }
        
        // Add full size
        $full = wp_get_attachment_image_src($attachment_id, 'full');
        if ($full) {
            $sizes['full'] = [
                'url' => $full[0],
                'width' => $full[1],
                'height' => $full[2],
            ];
        }
        
        return $sizes;
    }
}
