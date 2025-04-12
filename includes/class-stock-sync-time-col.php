<?php
class WC_SSPAA_Stock_Sync_Time_Col
{
    public function __construct()
    {
        add_filter('manage_edit-product_columns', array($this, 'add_custom_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'display_sync_info_in_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'register_sortable_columns'));
        add_action('pre_get_posts', array($this, 'sort_custom_column'));
        
        // Add AJAX handlers
        add_action('wp_ajax_wc_sspaa_sync_single_product', array($this, 'sync_single_product'));
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_custom_columns($columns)
    {
        $reordered_columns = array();
        foreach ($columns as $key => $value) {
            if ($key == 'price') {
                $reordered_columns['avenue_stock_sync'] = __('Avenue Stock Sync', 'woocommerce');
            }
            $reordered_columns[$key] = $value;
        }
        return $reordered_columns;
    }

    public function display_sync_info_in_column($column, $post_id)
    {
        if ('avenue_stock_sync' === $column) {
            $last_sync = get_post_meta($post_id, '_wc_sspaa_last_sync', true);
            $sku = get_post_meta($post_id, '_sku', true);
            
            // Add sync button with the product ID as data attribute
            echo '<div class="wc-sspaa-sync-container">';
            if ($last_sync) {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; white-space: nowrap; display: block; margin-bottom: 5px;">' . esc_html($last_sync) . '</span>';
            } else {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; display: block; margin-bottom: 5px;">N/A</span>';
            }
            
            if ($sku) {
                echo '<button type="button" class="button wc-sspaa-sync-stock" data-product-id="' . esc_attr($post_id) . '" data-sku="' . esc_attr($sku) . '">Sync Stock</button>';
                echo '<span class="spinner" style="float: none; margin-top: 0;"></span>';
            } else {
                echo '<span style="color: #999;">No SKU</span>';
            }
            echo '</div>';
        }
    }

    public function register_sortable_columns($columns)
    {
        $columns['avenue_stock_sync'] = 'avenue_stock_sync';
        return $columns;
    }

    public function sort_custom_column($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ('avenue_stock_sync' === $query->get('orderby')) {
            $query->set('meta_key', '_wc_sspaa_last_sync');
            $query->set('orderby', 'meta_value');
        }
    }

    public function enqueue_scripts($hook)
    {
        if ('edit.php' !== $hook || !isset($_GET['post_type']) || 'product' !== $_GET['post_type']) {
            return;
        }
        
        wc_sspaa_log('Enqueuing admin scripts on hook: ' . $hook);
        
        $js_path = '../assets/js/admin.js';
        $js_url = plugins_url($js_path, __FILE__);
        $js_file = plugin_dir_path(__FILE__) . $js_path;
        
        if (!file_exists($js_file)) {
            wc_sspaa_log('Error: Admin JS file not found at: ' . $js_file);
            return;
        }
        
        wc_sspaa_log('Loading admin JS from: ' . $js_url);
        
        wp_enqueue_script(
            'wc-sspaa-admin',
            $js_url,
            array('jquery'),
            filemtime($js_file),
            true
        );
        
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_sync_nonce')
        );
        
        wp_localize_script('wc-sspaa-admin', 'wcSspaaAdmin', $script_data);
        wc_sspaa_log('Localized script data: ' . json_encode($script_data));
        
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-sspaa-sync-container { position: relative; }
            .wc-sspaa-sync-container .spinner { visibility: hidden; margin-left: 4px; }
            .wc-sspaa-sync-container .spinner.is-active { visibility: visible; }
            .wc-sspaa-sync-container .notice { margin: 5px 0; padding: 5px; }
            .wc-sspaa-sync-container .updated { background-color: #edfaef; border-left: 4px solid #46b450; }
            .wc-sspaa-sync-container .error { background-color: #fbeaea; border-left: 4px solid #dc3232; }
        ');
    }
    
    public function sync_single_product()
    {
        wc_sspaa_log('Received sync request');
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_sync_nonce', 'nonce', false)) {
            wc_sspaa_log('Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('edit_products')) {
            wc_sspaa_log('Permission denied for user');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        wc_sspaa_log('Processing sync request - Product ID: ' . $product_id . ', SKU: ' . $sku);
        
        if (!$product_id || !$sku) {
            wc_sspaa_log('Error: Invalid product ID or SKU for single product sync');
            wp_send_json_error(array('message' => 'Invalid product ID or SKU'));
            return;
        }
        
        try {
            $api_handler = new WC_SSPAA_API_Handler();
            wc_sspaa_log('Fetching product data from API for SKU: ' . $sku);
            
            $response = $api_handler->get_product_data($sku);
            wc_sspaa_log('API Response: ' . json_encode($response));
            
            if (!$response || !isset($response['products']) || empty($response['products'])) {
                wc_sspaa_log('Error: No product data found for SKU: ' . $sku);
                wp_send_json_error(array('message' => 'No product data found'));
                return;
            }
            
            $product_data = $response['products'][0];
            $quantity = 0;
            
            foreach ($product_data['inventory_quantities'] as $inventory) {
                if ($inventory['warehouse'] === '1') {
                    $quantity = floatval($inventory['quantity']);
                    break;
                }
            }
            
            if ($quantity < 0) {
                $quantity = 0;
            }
            
            wc_sspaa_log('Updating stock for product ID: ' . $product_id . ' to quantity: ' . $quantity);
            
            update_post_meta($product_id, '_stock', $quantity);
            wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
            
            $current_time = current_time('mysql');
            update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
            
            wc_sspaa_log('Successfully updated stock for product ID: ' . $product_id);
            
            wp_send_json_success(array(
                'message' => 'Stock updated successfully',
                'last_sync' => $current_time
            ));
            
        } catch (Exception $e) {
            wc_sspaa_log('Error syncing product ID: ' . $product_id . ' - ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing product: ' . $e->getMessage()));
        }
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>