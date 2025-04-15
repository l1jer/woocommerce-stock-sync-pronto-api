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

        // Fetch products from WooCommerce with pagination, excluding those marked as 'Obsolete'
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS sku, p.post_type, p.post_parent 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                LEFT JOIN {$wpdb->postmeta} pm_sync ON pm_sync.post_id = p.ID AND pm_sync.meta_key = '_wc_sspaa_last_sync'
                WHERE pm.meta_key='_sku' 
                AND p.post_type IN ('product', 'product_variation')
                AND pm.meta_value != ''
                AND (pm_sync.meta_value IS NULL OR pm_sync.meta_value != 'Obsolete')
                ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                $this->batch_size,
                $offset
            )
        );

        $this->log('Number of products fetched: ' . count($products));
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

            if (empty($sku)) {
                $this->log("Skipping product ID: {$product_id} with empty SKU.");
                continue;
            }

            $this->log("Processing product ID: {$product_id}, Type: {$product_type}, Parent: {$parent_id}, SKU: {$sku}");

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

                // Throttle the requests to respect the rate limit
                usleep($this->delay);
            } else {
                // Handle empty response - likely obsolete product
                if (isset($response['products']) && empty($response['products']) && isset($response['count']) && $response['count'] === 0) {
                    $this->log('API response indicates SKU ' . $sku . ' is obsolete or not found. Setting stock to 0.');
                    // Set stock to 0 and status to outofstock
                    update_post_meta($product_id, '_stock', 0);
                    wc_update_product_stock_status($product_id, 'outofstock');
                    // Update sync status to 'Obsolete'
                    update_post_meta($product_id, '_wc_sspaa_last_sync', 'Obsolete');
                    
                    // Also update parent if it's a variation
                    if ($product_type === 'product_variation' && $parent_id > 0) {
                        $this->update_parent_product_stock($parent_id); // Update parent based on all variations
                    }
                } else {
                    // Log other potential errors or unexpected responses
                    $this->log('No product data found or unexpected API response for SKU: ' . $sku);
                }
            }
        }

        $next_offset = $offset + $this->batch_size;
        $next_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->postmeta}
                JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
                WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')
                AND meta_value != ''
                ORDER BY ID ASC LIMIT %d OFFSET %d",
                $this->batch_size,
                $next_offset
            )
        );

        // Ensure batch processes are not scheduled multiple times if they already exist.
        // Removed scheduling of next batch to prevent multiple events
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
            error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . '../debug.log');
        }
    }
}