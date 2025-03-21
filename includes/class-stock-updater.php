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

    public function update_stock($offset)
    {
        global $wpdb;

        // Fetch products from WooCommerce with pagination
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, meta_value AS sku FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')
            ORDER BY ID ASC LIMIT %d OFFSET %d",
                $this->batch_size,
                $offset
            )
        );

        $this->log('Number of products fetched: ' . count($products));
        
        $updated_count = 0;

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;

            if (empty($sku)) {
                $this->log('Skipping product with empty SKU.');
                continue;
            }

            $this->log('Fetching product data for SKU: ' . $sku);

            if ($this->sync_product_stock($product_id, $sku)) {
                $updated_count++;
            }
        }

        $next_offset = $offset + $this->batch_size;
        $next_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')
            ORDER BY ID ASC LIMIT %d OFFSET %d",
                $this->batch_size,
                $next_offset
            )
        );

        // Return count of updated products
        return $updated_count;
    }
    
    /**
     * Sync a single product by ID and SKU
     *
     * @param int $product_id The product ID
     * @param string $sku The product SKU
     * @return bool True if stock was updated, false if unchanged
     */
    public function sync_single_product($product_id, $sku)
    {
        if (empty($sku)) {
            $this->log('Cannot sync product with empty SKU.');
            return false;
        }
        
        return $this->sync_product_stock($product_id, $sku);
    }
    
    /**
     * Sync product stock from API
     *
     * @param int $product_id The product ID
     * @param string $sku The product SKU
     * @return mixed True if stock was updated, false if unchanged, 'obsolete' if product not found in API
     */
    private function sync_product_stock($product_id, $sku)
    {
        $response = $this->api_handler->get_product_data($sku);
        $this->log('Raw API response for SKU ' . $sku . ': ' . json_encode($response));

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

            // Get current stock before update
            $current_stock = get_post_meta($product_id, '_stock', true);
            
            // Update stock quantity
            update_post_meta($product_id, '_stock', $quantity);
            update_post_meta($product_id, '_wc_sspaa_stock_value', $quantity); // Store stock value for display
            update_post_meta($product_id, '_wc_sspaa_is_obsolete', 'no'); // Mark as not obsolete
            wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');

            // Save last sync time
            $current_time = current_time('mysql');
            $this->log('Saving last sync time: ' . $current_time . ' for product ID: ' . $product_id);
            update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
            
            // Count only if stock actually changed
            if ($current_stock !== '' . $quantity) {
                $this->log('Stock updated for product ID: ' . $product_id . ' from ' . $current_stock . ' to ' . $quantity);
                
                // Throttle the requests to respect the rate limit
                usleep($this->delay);
                
                return true;
            } else {
                $this->log('Stock unchanged for product ID: ' . $product_id . ' (remains at ' . $quantity . ')');
                
                // Throttle the requests to respect the rate limit
                usleep($this->delay);
                
                return false;
            }
        } else {
            $this->log('No product data found for SKU: ' . $sku . ' - marking as obsolete and setting stock to 0');
            
            // Get current stock before update
            $current_stock = get_post_meta($product_id, '_stock', true);
            
            // Set stock to 0 for obsolete products
            update_post_meta($product_id, '_stock', 0);
            update_post_meta($product_id, '_wc_sspaa_stock_value', 0);
            update_post_meta($product_id, '_wc_sspaa_is_obsolete', 'yes');
            wc_update_product_stock_status($product_id, 'outofstock');
            
            // Save last sync time
            $current_time = current_time('mysql');
            update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
            
            $this->log('Stock set to 0 for obsolete product ID: ' . $product_id);
            
            // Throttle the requests to respect the rate limit
            usleep($this->delay);
            
            // Return true if stock was changed, false if it was already 0
            return $current_stock !== '0' ? true : 'obsolete';
        }
    }

    private function log($message)
    {
        if ($this->enable_debug) {
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . '../debug.log');
        }
    }
}