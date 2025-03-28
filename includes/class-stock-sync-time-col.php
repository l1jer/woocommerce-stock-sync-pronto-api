<?php
class WC_SSPAA_Stock_Sync_Time_Col
{
    public function __construct()
    {
        add_filter('manage_edit-product_columns', array($this, 'add_custom_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'display_sync_info_in_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'register_sortable_columns'));
        add_action('pre_get_posts', array($this, 'sort_custom_column'));
        add_action('restrict_manage_posts', array($this, 'add_sync_status_link'), 10);
        
        // Register AJAX handlers
        add_action('wp_ajax_wc_sspaa_sync_single_product', array($this, 'ajax_sync_single_product'));
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('edit.php' !== $hook || !isset($_GET['post_type']) || 'product' !== $_GET['post_type']) {
            return;
        }
        
        wp_enqueue_script(
            'wc-sspaa-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
            array('jquery'),
            WC_SSPAA_VERSION,
            true
        );
        
        wp_localize_script(
            'wc-sspaa-admin-js',
            'wc_sspaa_params',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_sspaa_sync_nonce'),
                'sync_text' => __('Sync Stock', 'woocommerce'),
                'syncing_text' => __('Syncing...', 'woocommerce'),
                'synced_text' => __('Synced!', 'woocommerce'),
                'error_text' => __('Error!', 'woocommerce'),
            )
        );
        
        // Enqueue the standalone CSS file
        wp_enqueue_style(
            'wc-sspaa-admin-css',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
            array('woocommerce_admin_styles'),
            WC_SSPAA_VERSION
        );
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
            
            echo '<div class="wc-sspaa-sync-data" data-product-id="' . esc_attr($post_id) . '">';
            
            if ($last_sync) {
                echo '<span class="wc-sspaa-sync-time">' . esc_html($last_sync) . '</span>';
                
                // Check for obsolete stock API response
                $api_response = get_post_meta($post_id, '_wc_sspaa_api_response', true);
                if ($api_response === '{"products":[],"count":0,"pages":0}') {
                    echo '<span class="wc-sspaa-obsolete-stock"><strong>Obsolete Stock</strong></span>';
                }
            } else {
                echo '<span class="wc-sspaa-sync-time">N/A</span>';
            }
            
            // Add sync button
            echo '<button type="button" class="button button-small wc-sspaa-sync-btn">' . esc_html__('Sync Stock', 'woocommerce') . '</button>';
            
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
    
    /**
     * Add link to Stock Sync Status page
     */
    public function add_sync_status_link() {
        global $post_type;
        
        if ($post_type === 'product') {
            $url = admin_url('edit.php?post_type=product&page=wc-sspaa-settings');
            echo '<div class="wc-sspaa-toolbar-action">';
            echo '<a href="' . esc_url($url) . '" class="button wc-sspaa-status-link">' . 
                esc_html__('Stock Sync Status', 'woocommerce') . '</a>';
            echo '</div>';
        }
    }
    
    /**
     * AJAX handler to sync a single product
     */
    public function ajax_sync_single_product() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_sspaa_sync_nonce')) {
            wc_sspaa_log('AJAX: Security check failed');
            wp_send_json_error(array('message' => __('Security check failed', 'woocommerce')));
        }
        
        // Check product ID
        if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
            wc_sspaa_log('AJAX: Invalid product ID');
            wp_send_json_error(array('message' => __('Invalid product ID', 'woocommerce')));
        }
        
        $product_id = intval($_POST['product_id']);
        
        // Get product SKU
        $sku = get_post_meta($product_id, '_sku', true);
        
        if (empty($sku)) {
            wc_sspaa_log('AJAX: Product ID ' . $product_id . ' has no SKU');
            wp_send_json_error(array('message' => __('Product has no SKU', 'woocommerce')));
        }
        
        wc_sspaa_log('AJAX: Processing single product sync for product ID: ' . $product_id . ' with SKU: ' . $sku);
        
        // Create a temporary email notification instance for this single product sync
        $email_notification = new WC_SSPAA_Email_Notification(WC_SSPAA_EMAIL_RECIPIENT);
        $email_notification->start_sync(1, 1); // Just one product, one batch
        
        try {
            // Initialize API handler
            $api_handler = new WC_SSPAA_API_Handler();
            
            // Get product data from API
            $response = $api_handler->get_product_data($sku);
            
            if ($response === null) {
                $error_message = 'API returned null response';
                wc_sspaa_log('AJAX: Error: ' . $error_message);
                $email_notification->add_error($error_message, $sku, $product_id);
                wp_send_json_error(array('message' => __('API Error: Could not retrieve data', 'woocommerce')));
                return;
            }
            
            // Store API response for obsolete stock detection
            $response_json = json_encode($response);
            update_post_meta($product_id, '_wc_sspaa_api_response', $response_json);
            
            wc_sspaa_log('AJAX: API response for SKU ' . $sku . ': ' . $response_json);
            
            // Save last sync time
            $current_time = current_time('mysql');
            update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
            
            $is_obsolete = false;
            
            if (isset($response['products']) && !empty($response['products'])) {
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
                
                // Update stock quantity
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
                
                wc_sspaa_log('AJAX: Updated stock for product ID ' . $product_id . ' with quantity: ' . $quantity);
                
                // Update email notification stats
                $email_notification->update_batch_stats([
                    'updated' => 1,
                    'obsolete' => 0,
                    'skipped' => 0,
                    'errors' => []
                ]);
                
                // Return success response
                wp_send_json_success(array(
                    'sync_time' => $current_time,
                    'is_obsolete' => false,
                    'quantity' => $quantity,
                    'message' => sprintf(__('Stock updated to %d', 'woocommerce'), $quantity)
                ));
            } else {
                // Mark as obsolete stock
                $is_obsolete = true;
                
                wc_sspaa_log('AJAX: Product ID ' . $product_id . ' with SKU: ' . $sku . ' marked as obsolete stock');
                
                // Update email notification stats
                $email_notification->update_batch_stats([
                    'updated' => 0,
                    'obsolete' => 1,
                    'skipped' => 0,
                    'errors' => []
                ]);
                
                // Return success response with obsolete flag
                wp_send_json_success(array(
                    'sync_time' => $current_time,
                    'is_obsolete' => true,
                    'message' => __('Obsolete Stock', 'woocommerce')
                ));
            }
        } catch (Exception $e) {
            $error_message = 'Exception during sync: ' . $e->getMessage();
            wc_sspaa_log('AJAX: Error: ' . $error_message);
            $email_notification->add_error($error_message, $sku, $product_id);
            wp_send_json_error(array('message' => __('Sync error: ', 'woocommerce') . $e->getMessage()));
        }
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>