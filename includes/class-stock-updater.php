<?php

class WC_SSPAA_Stock_Updater
{
    private $api_handler;
    private $delay;
    private $burst_limit;
    private $pause_duration;
    private $batch_size;
    private $execution_time_limit;
    private $enable_debug;
    private $update_gtins;

    public function __construct($api_handler, $delay, $burst_limit, $pause_duration, $batch_size, $execution_time_limit, $enable_debug = true, $update_gtins = false)
    {
        $this->api_handler = $api_handler;
        $this->delay = $delay;
        $this->burst_limit = $burst_limit;
        $this->pause_duration = $pause_duration;
        $this->batch_size = $batch_size;
        $this->execution_time_limit = $execution_time_limit;
        $this->enable_debug = $enable_debug;
        $this->update_gtins = $update_gtins;
    }

    /**
     * Update all products sequentially without batch limitations
     */
    public function update_all_products()
    {
        global $wpdb;

        // Build exclusion clause for SKUs
        $excluded_skus_clause = '';
        if (defined('WC_SSPAA_EXCLUDED_SKUS') && !empty(WC_SSPAA_EXCLUDED_SKUS)) {
            $excluded_skus = array_map(array($wpdb, 'prepare'), array_fill(0, count(WC_SSPAA_EXCLUDED_SKUS), '%s'), WC_SSPAA_EXCLUDED_SKUS);
            $excluded_skus_list = implode(',', $excluded_skus);
            $excluded_skus_clause = " AND pm_sku.meta_value NOT IN ({$excluded_skus_list})";
            $this->log("SKU Exclusion: The following SKUs are excluded from sync: " . implode(', ', WC_SSPAA_EXCLUDED_SKUS));
        }

        // Fetch all products from WooCommerce that have SKUs (Task 1.4.9: Include obsolete products for re-checking)
        $products_query = 
            "SELECT p.ID, pm_sku.meta_value AS sku, p.post_type, p.post_parent 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm_sku.meta_value != ''
            {$excluded_skus_clause} -- Exclude specific SKUs from sync
            ORDER BY p.ID ASC";
        
        $this->log("Executing product query: {$products_query}");
        $products = $wpdb->get_results($products_query);

        if ($wpdb->last_error) {
            $this->log("WPDB ERROR after fetching products: " . $wpdb->last_error);
            // Potentially stop if products could not be fetched
            return;
        }

        $total_to_process = count($products);
        $this->log("Starting sync. Total products with SKUs to process: {$total_to_process} (Task 1.4.9: Includes obsolete products for re-checking)");
        
        $processed_count = 0;
        $successful_syncs = 0;
        $failed_syncs = 0;
        $marked_obsolete = 0;
        $processed_products = array(); // To handle parent/variation processing once

        $this->log("Entering main product processing loop for {$total_to_process} products.");

        foreach ($products as $product_index => $product) {
            $current_product_number = $product_index + 1;
            $this->log("LOOP START: Iteration {$current_product_number}/{$total_to_process}. Attempting to process Product ID: " . (isset($product->ID) ? $product->ID : 'N/A') . ", SKU: " . (isset($product->sku) ? $product->sku : 'N/A') );

            try {
                // Main processing logic for each product
                $sku = isset($product->sku) ? $product->sku : null;
                $product_id = isset($product->ID) ? $product->ID : null;
                $product_type = isset($product->post_type) ? $product->post_type : null;
                $parent_id = isset($product->post_parent) ? $product->post_parent : null;

                // Enhanced logging for each product being processed (already good)
                $this->log("Processing product {$current_product_number}/{$total_to_process} - Product ID: {$product_id}, Type: {$product_type}, SKU: {$sku}");

                if ($product_id === null) {
                    $this->log("Critical Error: Product ID is null at iteration {$current_product_number}. Product data: " . print_r($product, true));
                    $failed_syncs++;
                    continue;
                }

                if (in_array($product_id, $processed_products)) {
                    $this->log("Product ID: {$product_id} (SKU: {$sku}) already processed (likely a variation of an already processed parent). Skipping.");
                    continue;
                }
                $processed_products[] = $product_id;
                $processed_count++;

                if (empty($sku)) {
                    $this->log("Skipping product ID: {$product_id} due to empty SKU. (This should not happen with the current SQL query)");
                    $failed_syncs++;
                    continue;
                }
            
                // Inner try-catch for API call and stock update logic
                try {
                    $this->log("Attempting API call for SKU: {$sku} (Product ID: {$product_id})");
                    $response = $this->api_handler->get_product_data($sku);
                    
                    $raw_response_for_log = is_string($response) ? $response : json_encode($response);
                    if (strlen($raw_response_for_log) > 1000) {
                        $loggable_response = substr($raw_response_for_log, 0, 1000) . '... (truncated)';
                    } else {
                        $loggable_response = $raw_response_for_log;
                    }
                    $this->log("Raw API response for SKU {$sku}: " . $loggable_response);

                    // Task 1.4.9: Check both APIs before marking as obsolete
                    if (is_array($response) && 
                        isset($response['products']) && empty($response['products']) && 
                        isset($response['count']) && $response['count'] === 0 && 
                        isset($response['pages']) && $response['pages'] === 0) {
                        
                        $this->log("[Task 1.4.9] Primary API returned empty for SKU {$sku}. Checking obsolete status with both APIs.");
                        $obsolete_check = $this->api_handler->check_obsolete_status($sku);
                        
                        if ($obsolete_check['is_obsolete']) {
                            $this->log("[Task 1.4.9] Marking SKU {$sku} (Product ID: {$product_id}) as Obsolete. Reason: {$obsolete_check['reason']}");
                            update_post_meta($product_id, '_wc_sspaa_obsolete_exempt', current_time('timestamp'));
                            update_post_meta($product_id, '_stock', 0);
                            wc_update_product_stock_status($product_id, 'obsolete');
                            update_post_meta($product_id, '_wc_sspaa_last_sync', current_time('mysql'));
                            $this->log("SKU {$sku} (Product ID: {$product_id}) marked as Obsolete exempt with 'obsolete' stock status. Stock set to 0.");
                            $marked_obsolete++;

                            if ($product_type === 'product_variation' && $parent_id > 0) {
                                $this->log("Updating parent product (ID: {$parent_id}) due to variation (ID: {$product_id}) becoming obsolete.");
                                $this->update_parent_product_stock($parent_id);
                            }
                        } else {
                            $this->log("[Task 1.4.9] SKU {$sku} (Product ID: {$product_id}) NOT marked as obsolete. Reason: {$obsolete_check['reason']}");
                            
                            // If DEFAULT API has valid data, use it for stock sync
                            if (isset($obsolete_check['default_response']['products']) && !empty($obsolete_check['default_response']['products'])) {
                                $this->log("[Task 1.4.9] Using DEFAULT API data for stock sync for SKU {$sku}");
                                $response = $obsolete_check['default_response'];
                            } elseif (isset($obsolete_check['scs_response']['products']) && !empty($obsolete_check['scs_response']['products'])) {
                                $this->log("[Task 1.4.9] Using SCS API data for stock sync for SKU {$sku}");
                                $response = $obsolete_check['scs_response'];
                            }
                        }
                    }

                    if (isset($response['products']) && !empty($response['products'])) {
                        $this->log("Valid API response received for SKU {$sku} (Product ID: {$product_id}).");
                        if (get_post_meta($product_id, '_wc_sspaa_obsolete_exempt', true)) {
                            if (delete_post_meta($product_id, '_wc_sspaa_obsolete_exempt')) {
                                $this->log("SKU {$sku} (Product ID: {$product_id}) Obsolete exemption removed due to valid stock data from API.");
                            } else {
                                $this->log("WARNING: Failed to remove Obsolete exemption for SKU {$sku} (Product ID: {$product_id}).");
                            }
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
                                
                                $this->log("DUAL WAREHOUSE CALCULATION for SKU {$sku} (Product ID: {$product_id}): warehouse:1 = {$warehouse_1_qty} (final: {$warehouse_1_final}), warehouse:AB = {$warehouse_ab_qty} (final: {$warehouse_ab_final}), combined stock = {$quantity}");
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
                                
                                $this->log("SINGLE WAREHOUSE CALCULATION for SKU {$sku} (Product ID: {$product_id}): warehouse:1 = {$quantity}");
                            }
                        } else {
                            $this->log("WARNING: 'inventory_quantities' not found or not an array in API response for SKU {$sku} (Product ID: {$product_id}). Assuming zero stock.");
                        }

                        $this->log("Attempting to update stock for SKU: {$sku} (Product ID: {$product_id}) with quantity: {$quantity}");
                        update_post_meta($product_id, '_stock', $quantity);
                        $new_status = ($quantity > 0) ? 'instock' : 'outofstock';
                        wc_update_product_stock_status($product_id, $new_status);
                        update_post_meta($product_id, '_wc_sspaa_last_sync', current_time('mysql'));
                        $this->log("Successfully updated stock for SKU: {$sku} (Product ID: {$product_id}) to {$quantity}, status: {$new_status}.");

                        // Check and update GTIN if enabled and missing
                        if ($this->update_gtins) {
                            $this->update_product_gtin_if_missing($product_id, $sku, $product_data);
                        }

                        if ($product_type === 'product_variation' && $parent_id > 0) {
                            $this->log("Updating parent product (ID: {$parent_id}) due to stock change in variation (ID: {$product_id}).");
                            $this->update_parent_product_stock($parent_id);
                        }
                        
                        $successful_syncs++;
                    } else {
                        $failed_syncs++;
                        $this->log("No product data or unexpected API response format for SKU: {$sku} (Product ID: {$product_id}). Response: " . $loggable_response);
                    }
                
                } catch (Exception $e) {
                    $failed_syncs++;
                    $this->log("INNER EXCEPTION caught while processing SKU: {$sku} (Product ID: {$product_id}): " . $e->getMessage());
                    $this->log("Inner stack trace: " . $e->getTraceAsString());
                } catch (Error $e) { 
                    $failed_syncs++;
                    $this->log("INNER FATAL ERROR caught while processing SKU: {$sku} (Product ID: {$product_id}): " . $e->getMessage());
                    $this->log("Inner stack trace: " . $e->getTraceAsString());
                }
            
                // This delay is critical
                if ($this->delay > 0) {
                    // Log what the next product will be, if it exists
                    $next_product_index = $product_index + 1;
                    if (isset($products[$next_product_index])) {
                        $next_product_id_log = isset($products[$next_product_index]->ID) ? $products[$next_product_index]->ID : 'N/A';
                        $next_product_sku_log = isset($products[$next_product_index]->sku) ? $products[$next_product_index]->sku : 'N/A';
                        $this->log("Preparing to pause. Next product in array (Index: {$next_product_index}): ID: {$next_product_id_log}, SKU: {$next_product_sku_log}");
                    } else {
                        $this->log("Preparing to pause. This is the last product in the array.");
                    }

                    $this->log("Pausing for " . ($this->delay / 1000000) . " seconds after processing SKU: {$sku} (Product ID: {$product_id})");
                    usleep($this->delay);
                    $this->log("Resumed after pause for SKU: {$sku} (Product ID: {$product_id})");
                }

            } catch (Exception $e) {
                $failed_syncs++;
                $this->log("OUTER EXCEPTION caught for Product ID: " . (isset($product_id) ? $product_id : 'N/A') . ", SKU: " . (isset($sku) ? $sku : 'N/A') . ": " . $e->getMessage());
                $this->log("Outer stack trace: " . $e->getTraceAsString());
                // If a fatal error occurs here, it's serious. Consider breaking or re-throwing depending on desired behavior.
                // For now, log and continue to attempt processing other products.
            } catch (Error $e) {
                $failed_syncs++;
                $this->log("OUTER FATAL ERROR caught for Product ID: " . (isset($product_id) ? $product_id : 'N/A') . ", SKU: " . (isset($sku) ? $sku : 'N/A') . ": " . $e->getMessage());
                $this->log("Outer stack trace: " . $e->getTraceAsString());
                // If a fatal error occurs here, it's serious. Consider breaking or re-throwing depending on desired behavior.
                // For now, log and continue to attempt processing other products.
            }
            $this->log("LOOP END: Iteration {$current_product_number}/{$total_to_process}. Finished processing Product ID: " . (isset($product_id) ? $product_id : 'N/A') );

        } // End foreach

        $this->log("Exited main product processing loop.");
        $this->log("Sync completed. Total processed: {$processed_count}, Successful: {$successful_syncs}, Failed/Other: {$failed_syncs}, Newly marked Obsolete: {$marked_obsolete}");
        update_option('wc_sspaa_last_sync_completion', current_time('mysql'));
    }

    /**
     * Legacy method for batch processing (kept for backward compatibility)
     */
    public function update_stock($offset = 0)
    {
        // This method is now deprecated but kept for compatibility
        $this->log('Warning: update_stock with offset is deprecated. Use update_all_products() instead.');
        $this->update_all_products();
    }

    /**
     * Update parent variable product stock status based on variations
     *
     * @param int $parent_id Parent product ID
     */
    private function update_parent_product_stock($parent_id)
    {
        global $wpdb;
        
        $this->log("Updating stock for parent product ID: {$parent_id}");
        
        // Get all variations
        $variations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_parent = %d AND post_type = 'product_variation'",
                $parent_id
            )
        );
        
        if (empty($variations)) {
            $this->log("No variations found for parent product ID: {$parent_id}");
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
        
        $this->log("Updated parent product ID: {$parent_id} with total stock: {$total_stock}, Status: {$status_text}");
    }

    /**
     * Update GTIN for a product if it's missing and APN is available in API data
     *
     * @param int $product_id Product ID
     * @param string $sku Product SKU
     * @param array $product_data API response data for the product
     */
    private function update_product_gtin_if_missing($product_id, $sku, $product_data)
    {
        // Check if GTIN already exists
        $current_gtin = get_post_meta($product_id, '_wc_gtin', true);
        
        if (!empty($current_gtin)) {
            // GTIN already exists, skip update
            return;
        }

        // Check if APN is available in the API response
        if (isset($product_data['apn']) && !empty($product_data['apn'])) {
            $apn_value = trim($product_data['apn']);
            
            $this->log("Found missing GTIN for SKU: {$sku} (Product ID: {$product_id}), updating with APN value: {$apn_value}");
            
            // Update the GTIN field
            $update_result = update_post_meta($product_id, '_wc_gtin', $apn_value);
            
            if ($update_result) {
                // Also update the GTIN sync time
                update_post_meta($product_id, '_wc_sspaa_gtin_last_sync', current_time('mysql'));
                $this->log("Successfully updated GTIN for Product ID: {$product_id}, SKU: {$sku} with APN value: {$apn_value}");
            } else {
                $this->log("Failed to update GTIN meta for Product ID: {$product_id}, SKU: {$sku}");
            }
        } else {
            $this->log("No APN field found in API response for SKU: {$sku} (Product ID: {$product_id}) - GTIN will remain empty");
        }
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

    private function log($message)
    {
        if ($this->enable_debug) {
            // Use the main plugin logging function for consistency
            wc_sspaa_log($message);
        }
    }
}