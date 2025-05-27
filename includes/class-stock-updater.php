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

        // Fetch all products from WooCommerce that have SKUs
        $products = $wpdb->get_results(
                "SELECT p.ID, pm.meta_value AS sku, p.post_type, p.post_parent 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key='_sku' 
                AND p.post_type IN ('product', 'product_variation')
                AND pm.meta_value != ''
            ORDER BY p.ID ASC"
        );

        $total_products = count($products);
        $this->log('Starting sequential sync for ' . $total_products . ' products with SKUs');
        
        $processed_count = 0;
        $successful_syncs = 0;
        $failed_syncs = 0;
        $processed_products = array();

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;
            $product_type = $product->post_type;
            $parent_id = $product->post_parent;

            // Skip if already processed this product
            if (in_array($product_id, $processed_products)) {
                $this->log("Skipping already processed product ID: {$product_id}");
                continue;
            }

            // Add to processed products
            $processed_products[] = $product_id;
            $processed_count++;

            if (empty($sku)) {
                $this->log("Skipping product ID: {$product_id} with empty SKU.");
                $failed_syncs++;
                continue;
            }

            $this->log("Processing product {$processed_count}/{$total_products} - ID: {$product_id}, Type: {$product_type}, Parent: {$parent_id}, SKU: {$sku}");

            $response = $this->api_handler->get_product_data($sku);
            $raw_response_for_log = is_string($response) ? $response : json_encode($response);
            $this->log('Raw API response for SKU ' . $sku . ': ' . $raw_response_for_log);

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

                $this->log('Updating stock for SKU: ' . $sku . ' with quantity: ' . $quantity);

                // Update stock quantity
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');

                // Save last sync time
                $current_time = current_time('mysql');
                $this->log('Saving last sync time: ' . $current_time . ' for product ID: ' . $product_id);
                update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);

                // If this is a variable product, update the parent product stock status based on variations
                if ($product_type === 'product_variation' && $parent_id > 0) {
                    $this->update_parent_product_stock($parent_id);
                }
                
                $successful_syncs++;
                $this->log("Successfully synced product {$processed_count}/{$total_products} - SKU: {$sku}");
            } else {
                $failed_syncs++;
                if ($response === null) {
                    $this->log('No response or API error for SKU: ' . $sku . '. Check previous logs for API errors or JSON decode issues.');
                } else {
                    $this->log('No product data found in API response for SKU: ' . $sku);
                }
            }
            
            // Apply delay after each API call to respect rate limits
            if ($this->delay > 0) {
                $this->log("Applying {$this->delay} microsecond delay to respect API rate limit.");
            usleep($this->delay);
        }
        }

        $this->log("Sync completed. Total: {$total_products}, Processed: {$processed_count}, Successful: {$successful_syncs}, Failed: {$failed_syncs}");
        
        // Save completion time
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