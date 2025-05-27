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
        
        $products = $wpdb->get_results($products_query);

        $total_to_process = count($products);
        // Get total count of all products with SKUs for logging context, including exempt ones
        $total_all_sku_products = $wpdb->get_var(
            "SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation') AND pm_sku.meta_value != ''"
        );
        $this->log("Starting sync. Total products with SKUs: {$total_all_sku_products}. Products to process (not Obsolete exempt): {$total_to_process}");
        
        $processed_count = 0;
        $successful_syncs = 0;
        $failed_syncs = 0;
        $marked_obsolete = 0;
        $processed_products = array(); // To handle parent/variation processing once

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;
            $product_type = $product->post_type;
            $parent_id = $product->post_parent;

            if (in_array($product_id, $processed_products)) {
                continue;
            }
            $processed_products[] = $product_id;
            $processed_count++;

            if (empty($sku)) {
                $this->log("Skipping product ID: {$product_id} with empty SKU. (Should not happen with current query)");
                $failed_syncs++;
                continue;
            }

            $this->log("Processing product {$processed_count}/{$total_to_process} - ID: {$product_id}, Type: {$product_type}, SKU: {$sku}");

            $response = $this->api_handler->get_product_data($sku);
            $raw_response_for_log = is_string($response) ? $response : json_encode($response);
            // Be careful not to log excessively large successful responses if they occur
            $loggable_response = (strlen($raw_response_for_log) > 1000) ? substr($raw_response_for_log, 0, 1000) . '... (truncated)' : $raw_response_for_log;
            $this->log('API response for SKU ' . $sku . ': ' . $loggable_response);

            // Check for the specific Obsolete empty response: {"products":[],"count":0,"pages":0}
            if (is_array($response) && 
                isset($response['products']) && empty($response['products']) && 
                isset($response['count']) && $response['count'] === 0 && 
                isset($response['pages']) && $response['pages'] === 0) {
                
                update_post_meta($product_id, '_wc_sspaa_obsolete_exempt', current_time('timestamp'));
                update_post_meta($product_id, '_stock', 0);
                wc_update_product_stock_status($product_id, 'outofstock');
                update_post_meta($product_id, '_wc_sspaa_last_sync', current_time('mysql')); // Still update sync time
                $this->log("SKU {$sku} (Product ID: {$product_id}) marked as Obsolete exempt due to empty API response. Stock set to 0.");
                $marked_obsolete++;

                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id); // Update parent if a variation is marked Obsolete
                }

            } elseif (isset($response['products']) && !empty($response['products'])) {
                // Clear Obsolete exempt flag if it exists, as we have valid data now
                if (delete_post_meta($product_id, '_wc_sspaa_obsolete_exempt')) {
                    $this->log("SKU {$sku} (Product ID: {$product_id}) Obsolete exemption removed due to valid stock data from API.");
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

                $this->log('Updating stock for SKU: ' . $sku . ' with quantity: ' . $quantity);
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');
                update_post_meta($product_id, '_wc_sspaa_last_sync', current_time('mysql'));

                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id);
                }
                
                $successful_syncs++;
            } else {
                $failed_syncs++;
                $this->log('No product data or unexpected response for SKU: ' . $sku . '. See raw response above.');
            }
            
            if ($this->delay > 0) {
                usleep($this->delay);
            }
        }

        $this->log("Sync completed. Total with SKUs: {$total_all_sku_products}, Processed (non-exempt): {$processed_count}, Successful: {$successful_syncs}, Failed/Other: {$failed_syncs}, Newly marked Obsolete: {$marked_obsolete}");
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
        
        $this->log("Updated parent product ID: {$parent_id} with total stock: {$total_stock}, Status: " . ($has_stock ? 'instock' : 'outofstock'));
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