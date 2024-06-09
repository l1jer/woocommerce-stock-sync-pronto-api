<?php
class WCAP_Stock_Updater
{
    private $api_handler;
    private $delay;
    private $burst_limit;
    private $pause;
    private $batch_size;
    private $execution_time_limit;

    public function __construct($api_handler, $delay, $burst_limit, $pause, $batch_size, $execution_time_limit)
    {
        $this->api_handler = $api_handler;
        $this->delay = $delay;
        $this->burst_limit = $burst_limit;
        $this->pause = $pause;
        $this->batch_size = $batch_size;
        $this->execution_time_limit = $execution_time_limit;
    }

    public function update_stock($batch_offset)
    {
        $start_time = time();
        $products = wc_get_products(
            array(
                'limit' => $this->batch_size,
                'offset' => $batch_offset,
                'orderby' => 'ID',
                'order' => 'ASC',
                'return' => 'ids',
            )
        );

        $product_count = count($products);
        error_log("Number of products fetched: $product_count");

        foreach ($products as $product_id) {
            if ((time() - $start_time) >= $this->execution_time_limit) {
                error_log("Execution time limit reached. Pausing batch processing.");
                wp_schedule_single_event(time() + $this->pause, 'wcap_update_stock_batch', array($batch_offset + $this->batch_size));
                return;
            }

            $product = wc_get_product($product_id);
            if ($product->is_type('variable')) {
                // Handle variations
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    $variation_sku = $variation->get_sku();
                    if (!$variation_sku) {
                        wcap_log_error("Skipping variation with empty SKU. Variation ID: $variation_id");
                        continue;
                    }

                    error_log("Fetching variation data for SKU: $variation_sku");

                    $variation_response = $this->api_handler->get_product_data($variation_sku);
                    if (is_wp_error($variation_response)) {
                        wcap_log_error("API request error for SKU: $variation_sku. Error: " . $variation_response->get_error_message());
                        continue;
                    }

                    $variation_raw_response = wp_remote_retrieve_body($variation_response);
                    error_log("Raw API response for SKU $variation_sku: $variation_raw_response");

                    $variation_data = json_decode($variation_raw_response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        wcap_log_error("JSON decode error for SKU: $variation_sku. Error: " . json_last_error_msg());
                        continue;
                    }

                    if (empty($variation_data['products'][0]['inventory_quantities'][0]['quantity'])) {
                        wcap_log_error("No product data found for SKU: $variation_sku");
                        continue;
                    }

                    $variation_quantity = $variation_data['products'][0]['inventory_quantities'][0]['quantity'];
                    $variation_quantity = max(0, intval($variation_quantity));

                    error_log("Updating stock for SKU: $variation_sku with quantity: $variation_quantity");

                    $variation->set_stock_quantity($variation_quantity);
                    $variation->save();

                    $last_sync = current_time('mysql');
                    update_post_meta($variation_id, '_wcap_last_sync', $last_sync);

                    error_log("Saving last sync time: $last_sync for variation ID: $variation_id");
                }
            } else {
                $sku = $product->get_sku();
                if (!$sku) {
                    wcap_log_error("Skipping product with empty SKU. Product ID: $product_id");
                    continue;
                }

                error_log("Fetching product data for SKU: $sku");

                $response = $this->api_handler->get_product_data($sku);
                if (is_wp_error($response)) {
                    wcap_log_error("API request error for SKU: $sku. Error: " . $response->get_error_message());
                    continue;
                }

                $raw_response = wp_remote_retrieve_body($response);
                error_log("Raw API response for SKU $sku: $raw_response");

                $product_data = json_decode($raw_response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    wcap_log_error("JSON decode error for SKU: $sku. Error: " . json_last_error_msg());
                    continue;
                }

                if (empty($product_data['products'][0]['inventory_quantities'][0]['quantity'])) {
                    wcap_log_error("No product data found for SKU: $sku");
                    continue;
                }

                $quantity = $product_data['products'][0]['inventory_quantities'][0]['quantity'];
                $quantity = max(0, intval($quantity));

                error_log("Updating stock for SKU: $sku with quantity: $quantity");

                $product->set_stock_quantity($quantity);
                $product->save();

                $last_sync = current_time('mysql');
                update_post_meta($product_id, '_wcap_last_sync', $last_sync);

                error_log("Saving last sync time: $last_sync for product ID: $product_id");
            }
        }

        if ($product_count === $this->batch_size) {
            error_log("Scheduling next batch. Next offset: " . ($batch_offset + $this->batch_size));
            wp_schedule_single_event(time(), 'wcap_update_stock_batch', array($batch_offset + $this->batch_size));
        }
    }
}
?>