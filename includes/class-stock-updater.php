<?php

class WC_SSPAA_Stock_Updater
{
    private $api_handler;
    private $delay;
    private $burst_limit;
    private $pause_duration;
    private $batch_size;
    private $execution_time_limit;
    private $excluded_skus = [
        'ZTC-T1LG-S',
        'ZTC-T1LG-M',
        'ZTC-T1LG-L',
        'ZTC-T1LG-XL',
        'ZTC-T1LG-2XL',
        'ZTC-T1DG-S',
        'ZTC-T1DG-M',
        'ZTC-T1DG-L',
        'ZTC-T1DG-XL',
        'ZTC-T1DG-2XL',
        'ZTC-T1OG-S',
        'ZTC-T1OG-M',
        'ZTC-T1OG-L',
        'ZTC-T1OG-XL',
        'ZTC-T1OG-2XL',
        'ZTC-T1N-S',
        'ZTC-T1N-M',
        'ZTC-T1N-L',
        'ZTC-T1N-XL',
        'ZTC-T1N-2XL',
        'ZTC-T2N-S',
        'ZTC-T2N-M',
        'ZTC-T2N-L',
        'ZTC-T2N-XL',
        'ZTC-T2N-2XL',
        'ZTC-T2LG-S',
        'ZTC-T2LG-M',
        'ZTC-T2LG-L',
        'ZTC-T2LG-XL',
        'ZTC-T2LG-2XL',
        'ZTC-T2DG-S',
        'ZTC-T2DG-M',
        'ZTC-T2DG-L',
        'ZTC-T2DG-XL',
        'ZTC-T2DG-2XL',
        'ZTC-T2OG-S',
        'ZTC-T2OG-M',
        'ZTC-T2OG-L',
        'ZTC-T2OG-XL',
        'ZTC-T2OG-2XL',
        'ZTC-HDG-S',
        'ZTC-HDG-M',
        'ZTC-HDG-L',
        'ZTC-HDG-XL',
        'ZTC-HDG-2XL',
        'ZTC-TLS1G-S',
        'ZTC-TLS1G-M',
        'ZTC-TLS1G-L',
        'ZTC-TLS1G-XL',
        'ZTC-TLS1G-2XL',
        'ZTC-TLS1OG-S',
        'ZTC-TLS1OG-M',
        'ZTC-TLS1OG-L',
        'ZTC-TLS1OG-XL',
        'ZTC-TLS1OG-2XL',
    ];
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

        $this->log('Number of products fetched: '.count($products));

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;

            if (empty($sku)) {
                $this->log('Skipping product with empty SKU.');
                continue;
            }

            // Exclude specific SKUs
            if (in_array($sku, $this->excluded_skus)) {
                $this->log('Skipping excluded SKU: '.$sku);
                continue;
            }

            $this->log('Fetching product data for SKU: '.$sku);

            $response = $this->api_handler->get_product_data($sku);
            $this->log('Raw API response for SKU '.$sku.': '.json_encode($response));

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

                $this->log('Updating stock for SKU: '.$sku.' with quantity: '.$quantity);

                // Update stock quantity
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');

                // Save last sync time
                $current_time = current_time('mysql');
                $this->log('Saving last sync time: '.$current_time.' for product ID: '.$product_id);
                update_post_meta($product_id, '_wc_sspaa_last_sync', $current_time);

                // Throttle the requests to respect the rate limit
                usleep($this->delay);
            } else {
                $this->log('No product data found for SKU: '.$sku);
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

        // Ensure batch processes are not scheduled multiple times if they already exist.
        // Removed scheduling of next batch to prevent multiple events
    }

    private function log($message)
    {
        if ($this->enable_debug) {
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__).'../debug.log');
        }
    }
}