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
            
            // Check if this is a variable product (Task 1.4.6)
            $product_post = get_post($post_id);
            $is_variable_product = ($product_post && $product_post->post_type === 'product' && !$sku);
            
            // For variable products, check if it has variations with SKUs
            $has_variations_with_skus = false;
            if ($is_variable_product) {
                $variations = $this->get_product_variations($post_id);
                $has_variations_with_skus = !empty($variations);
            }
            
            echo '<div class="wc-sspaa-sync-container">';
            if ($last_sync) {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; white-space: nowrap; display: block; margin-bottom: 5px;">' . esc_html($last_sync) . '</span>';
            } else {
                echo '<span class="wc-sspaa-last-sync" style="color: #999; display: block; margin-bottom: 5px;">N/A</span>';
            }

            if ($is_obsolete_exempt) {
                echo '<span style="color: red; display: block; margin-bottom: 5px; font-weight: bold;">Obsolete</span>';
            }            

            // Show sync button for simple products with SKUs OR variable products with variations that have SKUs
            if ($sku) {
                // Simple product or variation with SKU
                $tooltip_text = __('Synchronise product stock from API. If product is marked as Obsolete, this will also remove the Obsolete status and restore normal stock synchronisation.', 'woocommerce');
                echo '<button type="button" class="button wc-sspaa-sync-stock" data-product-id="' . esc_attr($post_id) . '" data-sku="' . esc_attr($sku) . '" data-product-type="simple" title="' . esc_attr($tooltip_text) . '">Sync Stock</button>';
                echo '<span class="spinner" style="float: none; margin-top: 0;"></span>';
            } elseif ($is_variable_product && $has_variations_with_skus) {
                // Variable product with variations that have SKUs (Task 1.4.6)
                $variation_count = count($this->get_product_variations($post_id));
                $tooltip_text = sprintf(
                    __('Synchronise stock for all %d variations of this variable product from API. If any variations are marked as Obsolete, this will also remove the Obsolete status and restore normal stock synchronisation.', 'woocommerce'),
                    $variation_count
                );
                echo '<button type="button" class="button wc-sspaa-sync-stock wc-sspaa-sync-variable" data-product-id="' . esc_attr($post_id) . '" data-sku="" data-product-type="variable" data-variation-count="' . esc_attr($variation_count) . '" title="' . esc_attr($tooltip_text) . '">Sync All Variations (' . $variation_count . ')</button>';
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
                'marked_obsolete_message' => __('Product marked as Obsolete. Stock set to 0.', 'woocommerce'),
                'obsolete_removed_message' => __('Obsolete status removed and stock updated successfully.', 'woocommerce')
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
                                sku: \$button.data('sku'),
                                product_type: \$button.data('product-type') || 'simple'
                            },
                        success: function(response) {
                            if (response.success) {
                                if (response.data.is_obsolete_exempt) {
                                    \$lastSyncSpan.text('N/A (Obsolete)').css('color', '#999');
                                    \$('<div class=\\'notice error\\'><p>' + wcSspaaColData.strings.obsolete_exempt_message + '</p></div>').appendTo(\$container).delay(8000).fadeOut();
                                } else if (response.data.marked_obsolete) {
                                    \$lastSyncSpan.text(response.data.last_sync + ' (Now Obsolete)').css('color', 'orange');
                                    \$('<div class=\\'notice updated\\'><p>' + wcSspaaColData.strings.marked_obsolete_message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
                                } else if (response.data.obsolete_removed) {
                                    \$lastSyncSpan.text(response.data.last_sync).css('color', '#46b450');
                                    \$('<div class=\\'notice updated\\'><p>' + wcSspaaColData.strings.obsolete_removed_message + '</p></div>').appendTo(\$container).delay(5000).fadeOut();
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
        $product_type = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : 'simple';
        
        wc_sspaa_log("Processing single sync request - Product ID: {$product_id}, SKU: {$sku}, Type: {$product_type}");
        
        if (!$product_id) {
            wc_sspaa_log('Error: Invalid product ID for single product sync');
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }
        
        // For simple products, SKU is required. For variable products, we'll sync variations
        if ($product_type === 'simple' && !$sku) {
            wc_sspaa_log('Error: SKU required for simple product sync');
            wp_send_json_error(array('message' => 'SKU required for simple product'));
            return;
        }

        // Handle variable product sync (Task 1.4.6)
        if ($product_type === 'variable') {
            $this->sync_variable_product($product_id);
            return;
        }
        
        // Handle simple product sync (existing logic)
        // Check if product is obsolete and remove obsolete status (Task 1.4.4.2)
        $was_obsolete = false;
        if (get_post_meta($product_id, '_wc_sspaa_obsolete_exempt', true)) {
            wc_sspaa_log("Product ID: {$product_id} (SKU: {$sku}) is currently Obsolete. Removing obsolete status and proceeding with sync (Task 1.4.4.2).");
            
            // Remove obsolete status
            delete_post_meta($product_id, '_wc_sspaa_obsolete_exempt');
            
            // Reset stock status from obsolete (will be updated based on API response)
            wc_update_product_stock_status($product_id, 'outofstock'); // Temporary status, will be updated after API call
            
            $was_obsolete = true;
            wc_sspaa_log("Obsolete status removed for Product ID: {$product_id} (SKU: {$sku}). Proceeding with stock sync.");
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
            usleep(WC_SSPAA_API_DELAY_MICROSECONDS); // Optimized delay (Task 1.4.3)

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

                if (isset($product_data['inventory_quantities']) && is_array($product_data['inventory_quantities'])) {
                    // Check if this is the SkyWatcher Australia domain for dual warehouse logic
                    $current_domain = $this->get_current_domain();
                    $is_skywatcher_domain = ($current_domain === 'skywatcheraustralia.com.au');
                    
                    if ($is_skywatcher_domain) {
                        // Dual warehouse logic for SkyWatcher Australia: warehouse:1 + warehouse:AB
                        $warehouse_1_qty = 0;
                        $warehouse_ab_qty = 0;
                        
                        foreach ($product_data['inventory_quantities'] as $inventory) {
                            if (isset($inventory['warehouse']) && isset($inventory['quantity'])) {
                                if ($inventory['warehouse'] === '1') {
                                    $warehouse_1_qty = floatval($inventory['quantity']);
                                } elseif ($inventory['warehouse'] === 'AB') {
                                    $warehouse_ab_qty = floatval($inventory['quantity']);
                                }
                            }
                        }
                        
                        // Convert negative quantities to zero, then sum them
                        $warehouse_1_final = max(0, $warehouse_1_qty);
                        $warehouse_ab_final = max(0, $warehouse_ab_qty);
                        $quantity = $warehouse_1_final + $warehouse_ab_final;
                        
                        wc_sspaa_log("INDIVIDUAL SYNC DUAL WAREHOUSE CALCULATION for SKU {$sku} (Product ID: {$product_id}): warehouse:1 = {$warehouse_1_qty} (final: {$warehouse_1_final}), warehouse:AB = {$warehouse_ab_qty} (final: {$warehouse_ab_final}), combined stock = {$quantity}");
                    } else {
                        // Standard single warehouse logic for all other domains
                        foreach ($product_data['inventory_quantities'] as $inventory) {
                            if (isset($inventory['warehouse']) && $inventory['warehouse'] === '1' && isset($inventory['quantity'])) {
                                $quantity = floatval($inventory['quantity']);
                                break;
                            }
                        }
                        
                        if ($quantity < 0) {
                            $quantity = 0;
                        }
                        
                        wc_sspaa_log("INDIVIDUAL SYNC SINGLE WAREHOUSE CALCULATION for SKU {$sku} (Product ID: {$product_id}): warehouse:1 = {$quantity}");
                    }
                } else {
                    wc_sspaa_log("WARNING: 'inventory_quantities' not found or not an array in individual sync API response for SKU {$sku} (Product ID: {$product_id}). Assuming zero stock.");
                }
                
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
                $current_time = current_time('mysql');
                update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);

                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id);
                }
                delete_transient($lock_transient_key);
                
                // Prepare success message with obsolete status removal info (Task 1.4.4.2)
                $message = 'Stock updated successfully';
                if ($was_obsolete) {
                    $message .= ' (Obsolete status removed)';
                    wc_sspaa_log("Successfully removed obsolete status and updated stock for Product ID: {$product_id} (SKU: {$sku}). New stock: {$quantity}");
                }
                
                wp_send_json_success(array(
                    'message' => $message,
                    'last_sync' => $current_time,
                    'obsolete_removed' => $was_obsolete
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
     * Sync all variations of a variable product (Task 1.4.6)
     *
     * @param int $variable_product_id The variable product ID
     */
    private function sync_variable_product($variable_product_id)
    {
        wc_sspaa_log("Starting variable product sync for Product ID: {$variable_product_id} (Task 1.4.6)");
        
        // Get all variations with SKUs
        $variations = $this->get_product_variations($variable_product_id);
        
        if (empty($variations)) {
            wc_sspaa_log("No variations with SKUs found for variable product ID: {$variable_product_id}");
            wp_send_json_error(array('message' => 'No variations with SKUs found for this variable product'));
            return;
        }
        
        $total_variations = count($variations);
        wc_sspaa_log("Found {$total_variations} variations to sync for variable product ID: {$variable_product_id}");
        
        $successful_syncs = 0;
        $failed_syncs = 0;
        $obsolete_removed_count = 0;
        $sync_results = array();
        
        // Create lock to prevent concurrent syncs
        $lock_transient_key = 'wc_sspaa_sync_lock_variable_' . $variable_product_id;
        if (get_transient($lock_transient_key)) {
            wc_sspaa_log("Variable product sync already in progress for Product ID: {$variable_product_id}");
            wp_send_json_error(array('message' => 'Sync already in progress for this variable product'));
            return;
        }
        set_transient($lock_transient_key, true, 300); // 5 minute lock
        
        try {
            $api_handler = new WC_SSPAA_API_Handler();
            
            foreach ($variations as $index => $variation) {
                $variation_id = $variation->ID;
                $variation_sku = $variation->sku;
                $current_variation = $index + 1;
                
                wc_sspaa_log("Syncing variation {$current_variation}/{$total_variations} - ID: {$variation_id}, SKU: {$variation_sku}");
                
                // Check if variation is obsolete and remove obsolete status (Task 1.4.4.2)
                $was_obsolete = false;
                if (get_post_meta($variation_id, '_wc_sspaa_obsolete_exempt', true)) {
                    wc_sspaa_log("Variation ID: {$variation_id} (SKU: {$variation_sku}) is currently Obsolete. Removing obsolete status.");
                    delete_post_meta($variation_id, '_wc_sspaa_obsolete_exempt');
                    wc_update_product_stock_status($variation_id, 'outofstock');
                    $was_obsolete = true;
                    $obsolete_removed_count++;
                    wc_sspaa_log("Obsolete status removed for variation ID: {$variation_id} (SKU: {$variation_sku})");
                }
                
                // Check if SKU is in excluded list
                if (defined('WC_SSPAA_EXCLUDED_SKUS') && in_array($variation_sku, WC_SSPAA_EXCLUDED_SKUS)) {
                    wc_sspaa_log("Variation SKU {$variation_sku} is in excluded list. Skipping sync.");
                    $failed_syncs++;
                    continue;
                }
                
                try {
                    // Get API data for this variation
                    $response = $api_handler->get_product_data($variation_sku);
                    usleep(WC_SSPAA_API_DELAY_MICROSECONDS); // API rate limiting
                    
                    if ($this->process_variation_sync_response($variation_id, $variation_sku, $response)) {
                        $successful_syncs++;
                        $sync_results[] = "✅ {$variation_sku}";
                    } else {
                        $failed_syncs++;
                        $sync_results[] = "❌ {$variation_sku}";
                    }
                    
                } catch (Exception $e) {
                    wc_sspaa_log("Error syncing variation ID: {$variation_id} (SKU: {$variation_sku}) - " . $e->getMessage());
                    $failed_syncs++;
                    $sync_results[] = "❌ {$variation_sku} (Error: " . $e->getMessage() . ")";
                }
            }
            
            // Update parent product stock and last sync time
            $this->update_parent_product_stock($variable_product_id);
            $current_time = current_time('mysql');
            update_post_meta($variable_product_id, '_wc_sspaa_last_sync', $current_time);
            
            delete_transient($lock_transient_key);
            
            // Prepare success message
            $message = sprintf(
                'Variable product sync completed. Synced %d/%d variations successfully.',
                $successful_syncs,
                $total_variations
            );
            
            if ($obsolete_removed_count > 0) {
                $message .= sprintf(' Removed obsolete status from %d variations.', $obsolete_removed_count);
            }
            
            wc_sspaa_log("Variable product sync completed for Product ID: {$variable_product_id}. Success: {$successful_syncs}, Failed: {$failed_syncs}, Obsolete removed: {$obsolete_removed_count}");
            
            wp_send_json_success(array(
                'message' => $message,
                'last_sync' => $current_time,
                'successful_syncs' => $successful_syncs,
                'failed_syncs' => $failed_syncs,
                'total_variations' => $total_variations,
                'obsolete_removed' => $obsolete_removed_count,
                'sync_results' => $sync_results
            ));
            
        } catch (Exception $e) {
            delete_transient($lock_transient_key);
            wc_sspaa_log('Error in variable product sync for Product ID: ' . $variable_product_id . ' - ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing variable product: ' . $e->getMessage()));
        }
    }
    
    /**
     * Process sync response for a single variation
     *
     * @param int $variation_id The variation ID
     * @param string $variation_sku The variation SKU
     * @param array|null $response API response
     * @return bool Success status
     */
    private function process_variation_sync_response($variation_id, $variation_sku, $response)
    {
        $raw_response_for_log = is_string($response) ? $response : json_encode($response);
        $loggable_response = (strlen($raw_response_for_log) > 500) ? substr($raw_response_for_log, 0, 500) . '... (truncated)' : $raw_response_for_log;
        wc_sspaa_log("Variation Sync API Response for SKU {$variation_sku}: {$loggable_response}");
        
        if (is_array($response) && 
            isset($response['products']) && empty($response['products']) && 
            isset($response['count']) && $response['count'] === 0 && 
            isset($response['pages']) && $response['pages'] === 0) {
            
            // Mark variation as obsolete
            update_post_meta($variation_id, '_wc_sspaa_obsolete_exempt', current_time('timestamp'));
            update_post_meta($variation_id, '_stock', 0);
            wc_update_product_stock_status($variation_id, 'obsolete');
            $current_time = current_time('mysql');
            update_post_meta($variation_id, '_wc_sspaa_last_sync', $current_time);
            wc_sspaa_log("Variation SKU {$variation_sku} (ID: {$variation_id}) marked as Obsolete with 'obsolete' stock status. Stock set to 0.");
            
            return true; // Consider this a successful sync (marked as obsolete)
        }
        
        if (!is_array($response) || !isset($response['products']) || empty($response['products'])) {
            wc_sspaa_log("No valid product data found for variation SKU: {$variation_sku}");
            return false;
        }
        
        $product_data = $response['products'][0];
        
        if (!isset($product_data['inventory_quantities']) || empty($product_data['inventory_quantities'])) {
            wc_sspaa_log("No inventory data found for variation SKU: {$variation_sku}");
            return false;
        }
        
        // Apply dual warehouse logic for skywatcheraustralia.com.au domain (same as Task 1.4.2)
        $current_domain = $this->get_current_domain();
        $final_stock_quantity = 0;
        
        if ($current_domain === 'skywatcheraustralia.com.au') {
            // Dual warehouse calculation
            $warehouse1_qty = 0;
            $warehouseAB_qty = 0;
            
            foreach ($product_data['inventory_quantities'] as $inventory) {
                if (isset($inventory['warehouse']) && isset($inventory['quantity'])) {
                    $warehouse = $inventory['warehouse'];
                    $quantity = floatval($inventory['quantity']);
                    
                    if ($warehouse === '1') {
                        $warehouse1_qty = $quantity;
                    } elseif ($warehouse === 'AB') {
                        $warehouseAB_qty = $quantity;
                    }
                }
            }
            
            $final_warehouse1 = max(0, $warehouse1_qty);
            $final_warehouseAB = max(0, $warehouseAB_qty);
            $final_stock_quantity = $final_warehouse1 + $final_warehouseAB;
            
            wc_sspaa_log("DUAL WAREHOUSE calculation for variation SKU {$variation_sku}: warehouse:1 = {$warehouse1_qty} (final: {$final_warehouse1}), warehouse:AB = {$warehouseAB_qty} (final: {$final_warehouseAB}), combined = {$final_stock_quantity}");
        } else {
            // Single warehouse calculation (warehouse:1 only)
            foreach ($product_data['inventory_quantities'] as $inventory) {
                if (isset($inventory['warehouse']) && $inventory['warehouse'] === '1' && isset($inventory['quantity'])) {
                    $final_stock_quantity = max(0, floatval($inventory['quantity']));
                    break;
                }
            }
            wc_sspaa_log("SINGLE WAREHOUSE calculation for variation SKU {$variation_sku}: warehouse:1 final stock = {$final_stock_quantity}");
        }
        
        // Update variation stock
        update_post_meta($variation_id, '_stock', $final_stock_quantity);
        
        $stock_status = ($final_stock_quantity > 0) ? 'instock' : 'outofstock';
        wc_update_product_stock_status($variation_id, $stock_status);
        
        $current_time = current_time('mysql');
        update_post_meta($variation_id, '_wc_sspaa_last_sync', $current_time);
        
        wc_sspaa_log("Updated variation stock: SKU={$variation_sku}, Stock={$final_stock_quantity}, Status={$stock_status}");
        
        return true;
    }
    
    /**
     * Get current domain for domain-specific logic
     *
     * @return string Current domain
     */
    private function get_current_domain()
    {
        // Enhanced domain detection for cron context
        if (!empty($_SERVER['HTTP_HOST'])) {
            return strtolower(wp_unslash($_SERVER['HTTP_HOST']));
        }
        
        // Fallback to WordPress site URL
        $site_url = get_site_url();
        $parsed_url = parse_url($site_url);
        return strtolower($parsed_url['host'] ?? 'unknown');
    }
    
    /**
     * Get product variations with SKUs for a variable product (Task 1.4.6)
     *
     * @param int $product_id The variable product ID
     * @return array Array of variation objects with ID and SKU
     */
    private function get_product_variations($product_id)
    {
        global $wpdb;
        
        // Get variations that have SKUs
        $variations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value as sku
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                WHERE p.post_parent = %d 
                AND p.post_type = 'product_variation'
                AND pm.meta_value != ''
                AND pm.meta_value IS NOT NULL",
                $product_id
            )
        );
        
        return $variations ? $variations : array();
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