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
    private $batch_stats;
    private $email_notification;

    public function __construct($api_handler, $delay, $burst_limit, $pause_duration, $batch_size, $execution_time_limit, $enable_debug = true)
    {
        $this->api_handler = $api_handler;
        $this->delay = $delay;
        $this->burst_limit = $burst_limit;
        $this->pause_duration = $pause_duration;
        $this->batch_size = $batch_size;
        $this->execution_time_limit = $execution_time_limit;
        $this->enable_debug = $enable_debug;
        $this->batch_stats = [
            'updated' => 0,
            'obsolete' => 0,
            'skipped' => 0,
            'errors' => []
        ];
    }
    
    /**
     * Set the email notification handler
     * 
     * @param WC_SSPAA_Email_Notification $email_notification
     */
    public function set_email_notification($email_notification) {
        $this->email_notification = $email_notification;
        $this->log('Email notification handler set');
    }

    public function update_stock($offset)
    {
        global $wpdb;

        $this->log('Starting stock update batch with offset: ' . $offset);
        $start_time = microtime(true);
        
        // Reset batch stats
        $this->batch_stats = [
            'updated' => 0,
            'obsolete' => 0,
            'skipped' => 0,
            'errors' => []
        ];

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

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;

            if (empty($sku)) {
                $this->log('Skipping product ID ' . $product_id . ' with empty SKU.');
                $this->batch_stats['skipped']++;
                continue;
            }

            $this->log('Fetching product data for product ID: ' . $product_id . ' with SKU: ' . $sku);

            try {
                $response = $this->api_handler->get_product_data($sku);
                
                if ($response === null) {
                    $error_message = 'API returned null response';
                    $this->log('Error: ' . $error_message);
                    $this->add_error($error_message, $sku, $product_id);
                    $this->batch_stats['skipped']++;
                    continue;
                }
                
                $this->log('Raw API response for SKU ' . $sku . ': ' . json_encode($response));
                
                // Store API response for obsolete stock detection
                $response_json = json_encode($response);
                update_post_meta($product_id, '_wc_sspaa_api_response', $response_json);

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

                    $this->log('Updating stock for product ID: ' . $product_id . ' with SKU: ' . $sku . ' with quantity: ' . $quantity);

                    // Update stock quantity
                    update_post_meta($product_id, '_stock', $quantity);
                    wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');

                    // Save last sync time
                    $current_time = current_time('mysql');
                    $this->log('Saving last sync time: ' . $current_time . ' for product ID: ' . $product_id);
                    update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
                    
                    $this->batch_stats['updated']++;

                    // Throttle the requests to respect the rate limit
                    usleep($this->delay);
                } else {
                    $this->log('No product data found for product ID: ' . $product_id . ' with SKU: ' . $sku . ' - marking as obsolete stock');
                    
                    // Save last sync time even for obsolete stock
                    $current_time = current_time('mysql');
                    update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);
                    
                    $this->batch_stats['obsolete']++;
                }
            } catch (Exception $e) {
                $error_message = 'Exception processing product: ' . $e->getMessage();
                $this->log('Error: ' . $error_message);
                $this->add_error($error_message, $sku, $product_id);
                $this->batch_stats['skipped']++;
            }
        }

        $execution_time = microtime(true) - $start_time;
        $this->log('Batch processing completed in ' . round($execution_time, 2) . ' seconds.');
        $this->log('Products updated: ' . $this->batch_stats['updated'] . 
                  ', Obsolete: ' . $this->batch_stats['obsolete'] . 
                  ', Skipped: ' . $this->batch_stats['skipped'] . 
                  ', Errors: ' . count($this->batch_stats['errors']));

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
        
        $this->log('Next batch will process ' . count($next_products) . ' products with offset: ' . $next_offset);
        
        // Update email notification if available
        if ($this->email_notification) {
            $this->email_notification->update_batch_stats($this->batch_stats);
        }
        
        return $this->batch_stats;
    }
    
    /**
     * Add an error to the batch statistics and email notification
     * 
     * @param string $error_message Error message
     * @param string $sku Product SKU
     * @param int $product_id Product ID
     */
    private function add_error($error_message, $sku = '', $product_id = 0) {
        $error = [
            'message' => $error_message,
            'sku' => $sku,
            'product_id' => $product_id,
            'time' => current_time('mysql')
        ];
        
        $this->batch_stats['errors'][] = $error;
        
        // Also add to email notification if available
        if ($this->email_notification) {
            $this->email_notification->add_error($error_message, $sku, $product_id);
        }
    }

    private function log($message)
    {
        if ($this->enable_debug) {
            // Use the global logging function for consistency
            wc_sspaa_log('UPDATER: ' . $message);
        }
    }
}