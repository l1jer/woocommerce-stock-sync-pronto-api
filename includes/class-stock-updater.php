<?php
class WCAP_Stock_Updater
{
    private $api_handler;
    private $delay;
    private $burst_limit;
    private $pause_duration;
    private $batch_size;
    private $execution_time_limit;

    public function __construct($api_handler, $delay, $burst_limit, $pause_duration, $batch_size, $execution_time_limit)
    {
        $this->api_handler = $api_handler;
        $this->delay = $delay;
        $this->burst_limit = $burst_limit;
        $this->pause_duration = $pause_duration;
        $this->batch_size = $batch_size;
        $this->execution_time_limit = $execution_time_limit;
    }

    public function update_stock($offset)
    {
        global $wpdb;

        // Fetch products from WooCommerce with pagination
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, meta_value AS sku FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type='product'
            ORDER BY ID ASC LIMIT %d OFFSET %d",
            $this->batch_size,
            $offset
        )
        );

        error_log('Number of products fetched: ' . count($products));

        foreach ($products as $product) {
            $sku = $product->sku;
            $product_id = $product->ID;

            if (empty($sku)) {
                error_log('Skipping product with empty SKU.');
                continue;
            }

            error_log('Fetching product data for SKU: ' . $sku);

            $response = $this->api_handler->get_product_data($sku);
            error_log('Raw API response for SKU ' . $sku . ': ' . json_encode($response));

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

                error_log('Updating stock for SKU: ' . $sku . ' with quantity: ' . $quantity);

                // Update stock quantity
                update_post_meta($product_id, '_stock', $quantity);
                wc_update_product_stock_status($product_id, ($quantity > 0) ? 'instock' : 'outofstock');

                // Save last sync time
                $current_time = current_time('mysql');
                error_log('Saving last sync time: ' . $current_time . ' for product ID: ' . $product_id);
                update_post_meta($product_id, '_wcap_last_sync', $current_time);

                // Throttle the requests to respect the rate limit
                usleep($this->delay);
            } else {
                error_log('No product data found for SKU: ' . $sku);
            }
        }

        $next_offset = $offset + $this->batch_size;
        $next_products = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type='product'
            ORDER BY ID ASC LIMIT %d OFFSET %d",
            $this->batch_size,
            $next_offset
        )
        );

        if (!empty($next_products)) {
            error_log('Scheduling next batch. Next offset: ' . $next_offset);
            wp_schedule_single_event(time() + $this->pause_duration, 'wcap_update_stock_batch', array($next_offset));
        }
    }
}
?>