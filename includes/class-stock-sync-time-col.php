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
        
        // Data for our inline script
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_sync_nonce')
        );
        
        // Add inline script for the single product sync button
        $inline_js = "
            jQuery(document).ready(function($) {
                $(document).on('click', '.wc-sspaa-sync-stock', function(e) {
                    e.preventDefault();
                    var \$button = $(this);
                    var \$container = \$button.closest('.wc-sspaa-sync-container');
                    var \$spinner = \$container.find('.spinner');
                    var \$lastSyncSpan = \$container.find('.wc-sspaa-last-sync');
                    
                    // Remove previous notices
                    \$container.find('.notice').remove();

                    \$button.prop('disabled', true);
                    \$spinner.addClass('is-active');

                    $.ajax({
                        url: wcSspaaColData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wc_sspaa_sync_single_product',
                            nonce: wcSspaaColData.nonce,
                            product_id: \$button.data('product-id'),
                            sku: \$button.data('sku')
                        },
                        success: function(response) {
                            if (response.success) {
                                \$lastSyncSpan.text(response.data.last_sync).css('color', '#46b450'); // Green for success
                                \$('<div class=\\'notice updated\\'><p>' + response.data.message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                            } else {
                                \$lastSyncSpan.css('color', '#dc3232'); // Red for error
                                \$('<div class=\\'notice error\\'><p>' + response.data.message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            \$lastSyncSpan.css('color', '#dc3232'); // Red for error
                             \$('<div class=\\'notice error\\'><p>AJAX Error: ' + textStatus + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                        },
                        complete: function() {
                            \$button.prop('disabled', false);
                            \$spinner.removeClass('is-active');
                        }
                    });
                });
            });
        ";
        
        wp_register_script('wc-sspaa-col-inline-js-handle', ''); // Dummy handle
        wp_enqueue_script('wc-sspaa-col-inline-js-handle');
        wp_add_inline_script('wc-sspaa-col-inline-js-handle', $inline_js);
        wp_localize_script('wc-sspaa-col-inline-js-handle', 'wcSspaaColData', $script_data);
        
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-sspaa-sync-container { position: relative; }
            .wc-sspaa-sync-container .spinner { visibility: hidden; margin-left: 4px; vertical-align: middle; }
            .wc-sspaa-sync-container .spinner.is-active { visibility: visible; }
            .wc-sspaa-sync-container .notice { margin: 5px 0; padding: 5px 10px; border-radius: 3px; }
            .wc-sspaa-sync-container .updated { background-color: #f0f8ff; border-left: 4px solid #46b450; color: #46b450; }
            .wc-sspaa-sync-container .error { background-color: #fff6f6; border-left: 4px solid #dc3232; color: #dc3232; }
            .wc-sspaa-last-sync { transition: color 0.3s ease-in-out; }
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

        $lock_transient_key = 'wc_sspaa_manual_sync_active_lock';
        $lock_timeout = 30; // Lock for 30 seconds (API call + delay + buffer)

        if (get_transient($lock_transient_key)) {
            wc_sspaa_log('Manual sync: Another sync operation is currently locked. Aborting.');
            wp_send_json_error(array('message' => __('Another sync operation is currently in progress. Please try again in a moment.', 'woocommerce')));
            return;
        }

        set_transient($lock_transient_key, true, $lock_timeout);
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        wc_sspaa_log('Processing sync request - Product ID: ' . $product_id . ', SKU: ' . $sku);
        
        if (!$product_id || !$sku) {
            delete_transient($lock_transient_key); // Release lock
            wc_sspaa_log('Error: Invalid product ID or SKU for single product sync');
            wp_send_json_error(array('message' => 'Invalid product ID or SKU'));
            return;
        }
        
        try {
            // Get product information
            $product_post = get_post($product_id);
            $product_type = $product_post->post_type;
            $parent_id = $product_post->post_parent;
            
            wc_sspaa_log("Product info - ID: {$product_id}, Type: {$product_type}, Parent ID: {$parent_id}");
            
            $api_handler = new WC_SSPAA_API_Handler();
            wc_sspaa_log('Fetching product data from API for SKU: ' . $sku);
            
            $response = $api_handler->get_product_data($sku);
            // Add a 3-second delay after the API call for manual sync
            wc_sspaa_log('Manual sync: Preparing to delay for 3 seconds to respect API rate limit.');
            usleep(3000000); // 3 seconds delay

            wc_sspaa_log('API Response: ' . json_encode($response));
            
            if (!$response || !isset($response['products']) || empty($response['products'])) {
                delete_transient($lock_transient_key); // Release lock
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
            
            // Update stock quantity
            update_post_meta($product_id, '_stock', $quantity);
            wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
            
            $current_time = current_time('mysql');
            update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
            
            // If this is a variation, update the parent product stock
            if ($product_type === 'product_variation' && $parent_id > 0) {
                $this->update_parent_product_stock($parent_id);
            }
            
            delete_transient($lock_transient_key); // Release lock
            wc_sspaa_log('Successfully updated stock for product ID: ' . $product_id);
            
            wp_send_json_success(array(
                'message' => 'Stock updated successfully',
                'last_sync' => $current_time
            ));
            
        } catch (Exception $e) {
            delete_transient($lock_transient_key); // Ensure lock is released on error
            wc_sspaa_log('Error syncing product ID: ' . $product_id . ' - ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing product: ' . $e->getMessage()));
        }
    }
    
    /**
     * Update parent variable product stock status based on variations
     *
     * @param int $parent_id Parent product ID
     */
    private function update_parent_product_stock($parent_id)
    {
        global $wpdb;
        
        wc_sspaa_log("Updating stock for parent product ID: {$parent_id}");
        
        // Get all variations
        $variations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_parent = %d AND post_type = 'product_variation'",
                $parent_id
            )
        );
        
        if (empty($variations)) {
            wc_sspaa_log("No variations found for parent product ID: {$parent_id}");
            return;
        }
        
        $total_stock = 0;
        $has_stock = false;
        
        foreach ($variations as $variation) {
            $variation_stock = get_post_meta($variation->ID, '_stock', true);
            
            if ($variation_stock === '') {
                continue;
            }
            
            $variation_stock = floatval($variation_stock);
            $total_stock += $variation_stock;
            
            if ($variation_stock > 0) {
                $has_stock = true;
            }
        }
        
        // Update parent product stock
        update_post_meta($parent_id, '_stock', $total_stock);
        wc_update_product_stock_status($parent_id, $has_stock ? 'instock' : 'outofstock');
        
        // Save last sync time for parent product
        $current_time = current_time('mysql');
        update_post_meta($parent_id, '_wc_sspaa_last_sync', $current_time);
        
        wc_sspaa_log("Updated parent product ID: {$parent_id} with total stock: {$total_stock}, Status: " . ($has_stock ? 'instock' : 'outofstock'));
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>