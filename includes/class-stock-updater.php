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

    public function __construct($api_handler, $delay, $burst_limit, $pause_duration, $batch_size, $execution_time_limit, $enable_debug = true)
    {
        $this->api_handler = $api_handler;
        $this->delay = $delay;
        $this->burst_limit = $burst_limit;
        $this->pause_duration = $pause_duration;
        $this->batch_size = $batch_size;
        $this->execution_time_limit = $execution_time_limit;
        $this->enable_debug = $enable_debug;
    }

    /**
     * Update all products sequentially without batch limitations
     */
    public function update_all_products()
    {
        global $wpdb;

        // Fetch all products from WooCommerce that have SKUs and are not Obsolete exempt
        $products_query = 
            "SELECT p.ID, pm_sku.meta_value AS sku, p.post_type, p.post_parent 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_obsolete ON p.ID = pm_obsolete.post_id AND pm_obsolete.meta_key = '_wc_sspaa_obsolete_exempt'
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm_sku.meta_value != ''
            AND (pm_obsolete.meta_id IS NULL OR pm_obsolete.meta_value = '' OR pm_obsolete.meta_value = 0) -- Exclude if Obsolete exempt meta exists and is not empty/zero
            ORDER BY p.ID ASC";
        
        $this->log("Executing product query: {$products_query}");
        $products = $wpdb->get_results($products_query);

        if ($wpdb->last_error) {
            $this->log("WPDB ERROR after fetching products: " . $wpdb->last_error);
            // Potentially stop if products could not be fetched
            return;
        }

        $total_to_process = count($products);
        // Get total count of all products with SKUs for logging context, including exempt ones
        $total_all_sku_products = $wpdb->get_var(
            "SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation') AND pm_sku.meta_value != ''"
        );
        $this->log("Starting sync. Total products with SKUs (overall): {$total_all_sku_products}. Products to process (not Obsolete exempt): {$total_to_process}");
        
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

                // ============== TEMPORARY WORKAROUND: Skip SKU ZTA-MULTI ==============
                if ($sku === 'ZTA-MULTI') {
                    $this->log("TEMP WORKAROUND: Intentionally skipping Product ID: {$product_id}, SKU: {$sku} as per request.");
                    $failed_syncs++; // Count it as a failed/skipped sync for reporting
                    continue; // Skip to the next product
                }
                // =====================================================================

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

                    if (is_array($response) && 
                        isset($response['products']) && empty($response['products']) && 
                        isset($response['count']) && $response['count'] === 0 && 
                        isset($response['pages']) && $response['pages'] === 0) {
                        
                        $this->log("Marking SKU {$sku} (Product ID: {$product_id}) as Obsolete due to empty API response.");
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

                    } elseif (isset($response['products']) && !empty($response['products'])) {
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
                            foreach ($product_data['inventory_quantities'] as $inventory) {
                                if (isset($inventory['warehouse']) && $inventory['warehouse'] === '1' && isset($inventory['quantity'])) {
                                    $quantity = floatval($inventory['quantity']);
                                    break;
                                }
                            }
                        } else {
                            $this->log("WARNING: 'inventory_quantities' not found or not an array in API response for SKU {$sku} (Product ID: {$product_id}). Assuming zero stock.");
                        }

                        if ($quantity < 0) {
                            $quantity = 0;
                        }

                        $this->log("Attempting to update stock for SKU: {$sku} (Product ID: {$product_id}) with quantity: {$quantity}");
                        update_post_meta($product_id, '_stock', $quantity);
                        $new_status = ($quantity > 0) ? 'instock' : 'outofstock';
                        wc_update_product_stock_status($product_id, $new_status);
                        update_post_meta($product_id, '_wc_sspaa_last_sync', current_time('mysql'));
                        $this->log("Successfully updated stock for SKU: {$sku} (Product ID: {$product_id}) to {$quantity}, status: {$new_status}.");

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
        $this->log("Sync completed. Total with SKUs (overall): {$total_all_sku_products}, Processed (non-exempt): {$processed_count}, Successful: {$successful_syncs}, Failed/Other: {$failed_syncs}, Newly marked Obsolete: {$marked_obsolete}");
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

    private function log($message)
    {
        if ($this->enable_debug) {
            $timestamp = date('Y-m-d H:i:s');
            $log_file = plugin_dir_path(__FILE__) . '../wc-sspaa-debug.log'; // Use dedicated log file
            
            // Ensure proper file permissions and create file if it doesn't exist
            if (!file_exists($log_file)) {
                if (touch($log_file)) {
                    chmod($log_file, 0644);
                }
            }
            
            // Write to dedicated log file
            if (is_writable($log_file) || is_writable(dirname($log_file))) {
                error_log("[$timestamp] $message\n", 3, $log_file);
            }
        }
    }
}