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
            $is_obsolete_exempt = get_post_meta($post_id, '_wc_sspaa_obsolete_exempt', true);
            
            echo '<div class="wc-sspaa-sync-container">';
            if ($last_sync) {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; white-space: nowrap; display: block; margin-bottom: 5px;">' . esc_html($last_sync) . '</span>';
            } else {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; display: block; margin-bottom: 5px;">N/A</span>';
            }

            if ($is_obsolete_exempt) {
                echo '<span style="color: red; display: block; margin-bottom: 5px; font-weight: bold;">Obsolete</span>';
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
        
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_sync_nonce'),
            'strings' => array(
                'obsolete_exempt_message' => __('Product is Obsolete. Manual sync skipped.', 'woocommerce'),
                'marked_obsolete_message' => __('Product marked as Obsolete. Stock set to 0.', 'woocommerce')
            )
        );
        
        $inline_js = "
            jQuery(document).ready(function($) {
                $(document).on('click', '.wc-sspaa-sync-stock', function(e) {
                    e.preventDefault();
                    var \$button = $(this);
                    var \$container = \$button.closest('.wc-sspaa-sync-container');
                    var \$spinner = \$container.find('.spinner');
                    var \$lastSyncSpan = \$container.find('.wc-sspaa-last-sync');
                    
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
                                if (response.data.is_obsolete_exempt) {
                                    \$lastSyncSpan.text('N/A (Obsolete)').css('color', '#999');
                                    \$('<div class=\\'notice error\\'><p>' + wcSspaaColData.strings.obsolete_exempt_message + '</p></div>').appendTo(\$container).delay(8000).fadeOut();
                                } else if (response.data.marked_obsolete) {
                                    \$lastSyncSpan.text(response.data.last_sync + ' (Now Obsolete)').css('color', 'orange');
                                    \$('<div class=\\'notice updated\\'><p>' + wcSspaaColData.strings.marked_obsolete_message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                                } else {
                                    \$lastSyncSpan.text(response.data.last_sync).css('color', '#46b450');
                                    \$('<div class=\\'notice updated\\'><p>' + response.data.message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                                }
                            } else {
                                \$lastSyncSpan.css('color', '#dc3232');
                                \$('<div class=\\'notice error\\'><p>' + response.data.message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            \$lastSyncSpan.css('color', '#dc3232');
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
        
        wp_register_script('wc-sspaa-col-inline-js-handle', '');
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
        wc_sspaa_log('Received sync request for single product');
        
        if (!check_ajax_referer('wc_sspaa_sync_nonce', 'nonce', false)) {
            wc_sspaa_log('Nonce verification failed for single product sync');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('edit_products')) {
            wc_sspaa_log('Permission denied for single product sync');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        wc_sspaa_log("Processing single sync request - Product ID: {$product_id}, SKU: {$sku}");
        
        if (!$product_id || !$sku) {
            wc_sspaa_log('Error: Invalid product ID or SKU for single product sync');
            wp_send_json_error(array('message' => 'Invalid product ID or SKU'));
            return;
        }

        if (get_post_meta($product_id, '_wc_sspaa_obsolete_exempt', true)) {
            wc_sspaa_log("Product ID: {$product_id} (SKU: {$sku}) is Obsolete exempt. Skipping API call for manual sync.");
            wp_send_json_success(array(
                'message' => __('Product is Obsolete. Sync skipped.', 'woocommerce'), 
                'is_obsolete_exempt' => true
            ));
            return;
        }

        // Check if SKU is in excluded list
        if (defined('WC_SSPAA_EXCLUDED_SKUS') && !empty(WC_SSPAA_EXCLUDED_SKUS) && in_array($sku, WC_SSPAA_EXCLUDED_SKUS)) {
            wc_sspaa_log("Product ID: {$product_id} (SKU: {$sku}) is in excluded SKUs list. Skipping manual sync.");
            wp_send_json_success(array(
                'message' => __('Product SKU is excluded from sync process.', 'woocommerce'), 
                'is_excluded_sku' => true
            ));
            return;
        }

        $lock_transient_key = 'wc_sspaa_manual_sync_active_lock_' . $product_id;
        $lock_timeout = 30; 

        if (get_transient($lock_transient_key)) {
            wc_sspaa_log("Manual sync for Product ID {$product_id}: Another sync operation is currently locked. Aborting.");
            wp_send_json_error(array('message' => __('Another sync operation for this product is in progress. Please try again in a moment.', 'woocommerce')));
            return;
        }
        set_transient($lock_transient_key, true, $lock_timeout);
        
        try {
            $product_post = get_post($product_id);
            $product_type = $product_post->post_type;
            $parent_id = $product_post->post_parent;
            
            $api_handler = new WC_SSPAA_API_Handler();
            $response = $api_handler->get_product_data($sku);
            usleep(5000000);

            $raw_response_for_log = is_string($response) ? $response : json_encode($response);
            $loggable_response = (strlen($raw_response_for_log) > 500) ? substr($raw_response_for_log, 0, 500) . '... (truncated)' : $raw_response_for_log;
            wc_sspaa_log('Single Sync API Response for SKU ' . $sku . ': ' . $loggable_response);
            
            if (is_array($response) && 
                isset($response['products']) && empty($response['products']) && 
                isset($response['count']) && $response['count'] === 0 && 
                isset($response['pages']) && $response['pages'] === 0) {
                
                update_post_meta($product_id, '_wc_sspaa_obsolete_exempt', current_time('timestamp'));
                update_post_meta($product_id, '_stock', 0);
                wc_update_product_stock_status($product_id, 'obsolete');
                $current_time = current_time('mysql');
                update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
                wc_sspaa_log("SKU {$sku} (Product ID: {$product_id}) marked as Obsolete exempt with 'obsolete' stock status during single sync. Stock set to 0.");

                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id);
                }
                delete_transient($lock_transient_key);
                wp_send_json_success(array(
                    'message' => __('Product marked as Obsolete. Stock set to 0.', 'woocommerce'),
                    'last_sync' => $current_time,
                    'marked_obsolete' => true
                ));
                return;
            }
            
            if (isset($response['products']) && !empty($response['products'])) {
                if (delete_post_meta($product_id, '_wc_sspaa_obsolete_exempt')) {
                    wc_sspaa_log("SKU {$sku} (Product ID: {$product_id}) Obsolete exemption removed during single sync.");
                }

                $product_data = $response['products'][0];
                $quantity = 0;
                foreach ($product_data['inventory_quantities'] as $inventory) {
                    if ($inventory['warehouse'] === '1') {
                        $quantity = floatval($inventory['quantity']);
                        break;
                    }
                }
                if ($quantity < 0) $quantity = 0;
                
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
                $current_time = current_time('mysql');
                update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);

                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id);
                }
                delete_transient($lock_transient_key);
                wp_send_json_success(array(
                    'message' => 'Stock updated successfully',
                    'last_sync' => $current_time
                ));
            } else {
                delete_transient($lock_transient_key);
                wc_sspaa_log('Error: No product data found for SKU: ' . $sku . ' in single sync.');
                wp_send_json_error(array('message' => 'No product data found for SKU'));
            }
        } catch (Exception $e) {
            delete_transient($lock_transient_key);
            wc_sspaa_log('Error syncing single product ID: ' . $product_id . ' - ' . $e->getMessage());
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
        $has_obsolete_only = true; // Track if parent should be obsolete
        
        foreach ($variations as $variation) {
            $variation_stock = get_post_meta($variation->ID, '_stock', true);
            $variation_stock_status = get_post_meta($variation->ID, '_stock_status', true);
            
            if ($variation_stock === '') {
                continue;
            }
            
            $variation_stock = floatval($variation_stock);
            $total_stock += $variation_stock;
            
            if ($variation_stock > 0) {
                $has_stock = true;
            }
            
            // If any variation is not obsolete, the parent shouldn't be obsolete
            if ($variation_stock_status !== 'obsolete') {
                $has_obsolete_only = false;
            }
        }
        
        // Update parent product stock
        update_post_meta($parent_id, '_stock', $total_stock);
        
        // Determine parent stock status
        if ($has_obsolete_only && $total_stock === 0) {
            wc_update_product_stock_status($parent_id, 'obsolete');
            $status_text = 'obsolete';
        } elseif ($has_stock) {
            wc_update_product_stock_status($parent_id, 'instock');
            $status_text = 'instock';
        } else {
            wc_update_product_stock_status($parent_id, 'outofstock');
            $status_text = 'outofstock';
        }
        
        // Save last sync time for parent product
        $current_time = current_time('mysql');
        update_post_meta($parent_id, '_wc_sspaa_last_sync', $current_time);
        
        wc_sspaa_log("Updated parent product ID: {$parent_id} with total stock: {$total_stock}, Status: {$status_text}");
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>