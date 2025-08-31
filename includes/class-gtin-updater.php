<?php

class WC_SSPAA_GTIN_Updater
{
    private $api_handler;
    private $enable_debug;

    public function __construct($api_handler, $enable_debug = true)
    {
        $this->api_handler = $api_handler;
        $this->enable_debug = $enable_debug;
    }

    /**
     * Update GTINs for all products that are missing them
     */
    public function update_missing_gtins()
    {
        global $wpdb;

        $this->log("Starting GTIN update process for products with missing GTINs.");

        // Build exclusion clause for SKUs
        $excluded_skus_clause = '';
        if (defined('WC_SSPAA_EXCLUDED_SKUS') && !empty(WC_SSPAA_EXCLUDED_SKUS)) {
            $excluded_skus = array_map(array($wpdb, 'prepare'), array_fill(0, count(WC_SSPAA_EXCLUDED_SKUS), '%s'), WC_SSPAA_EXCLUDED_SKUS);
            $excluded_skus_list = implode(',', $excluded_skus);
            $excluded_skus_clause = " AND pm_sku.meta_value NOT IN ({$excluded_skus_list})";
        }

        // Find products with SKUs but missing or empty GTINs
        $products_query = 
            "SELECT p.ID, pm_sku.meta_value AS sku, p.post_type, p.post_parent,
                    pm_gtin.meta_value AS current_gtin
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_gtin ON p.ID = pm_gtin.post_id AND pm_gtin.meta_key = '_wc_gtin'
            LEFT JOIN {$wpdb->postmeta} pm_obsolete ON p.ID = pm_obsolete.post_id AND pm_obsolete.meta_key = '_wc_sspaa_obsolete_exempt'
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm_sku.meta_value != ''
            AND (pm_gtin.meta_id IS NULL OR pm_gtin.meta_value = '' OR pm_gtin.meta_value IS NULL)
            AND (pm_obsolete.meta_id IS NULL OR pm_obsolete.meta_value = '' OR pm_obsolete.meta_value = 0)
            {$excluded_skus_clause}
            ORDER BY p.ID ASC";

        $this->log("Executing GTIN query: {$products_query}");
        $products = $wpdb->get_results($products_query);

        if ($wpdb->last_error) {
            $this->log("WPDB ERROR after fetching products for GTIN update: " . $wpdb->last_error);
            return;
        }

        $total_to_process = count($products);
        $this->log("Found {$total_to_process} products with missing GTINs to process.");

        if ($total_to_process === 0) {
            $this->log("No products found with missing GTINs. GTIN update process completed.");
            return;
        }

        $processed_count = 0;
        $successful_updates = 0;
        $failed_updates = 0;
        $skipped_no_apn = 0;

        foreach ($products as $product_index => $product) {
            $current_product_number = $product_index + 1;
            $sku = isset($product->sku) ? $product->sku : null;
            $product_id = isset($product->ID) ? $product->ID : null;
            $product_type = isset($product->post_type) ? $product->post_type : null;
            $current_gtin = isset($product->current_gtin) ? $product->current_gtin : '';

            $this->log("Processing GTIN update {$current_product_number}/{$total_to_process} - Product ID: {$product_id}, SKU: {$sku}, Current GTIN: " . ($current_gtin ?: 'empty'));

            if (empty($sku) || empty($product_id)) {
                $this->log("Skipping product due to missing SKU or Product ID. Product ID: {$product_id}, SKU: {$sku}");
                $failed_updates++;
                continue;
            }

            try {
                // Get product data from API
                $this->log("Fetching API data for SKU: {$sku} to retrieve APN/GTIN information");
                $response = $this->api_handler->get_product_data($sku);

                if (is_array($response) && isset($response['products']) && !empty($response['products'])) {
                    $product_data = $response['products'][0];
                    
                    // Check if 'apn' field exists and is not empty
                    if (isset($product_data['apn']) && !empty($product_data['apn'])) {
                        $apn_value = trim($product_data['apn']);
                        
                        $this->log("Found APN value '{$apn_value}' for SKU: {$sku} (Product ID: {$product_id})");
                        
                        // Update the GTIN field
                        $update_result = update_post_meta($product_id, '_wc_gtin', $apn_value);
                        
                        if ($update_result) {
                            $this->log("Successfully updated GTIN for Product ID: {$product_id}, SKU: {$sku} with APN value: {$apn_value}");
                            $successful_updates++;
                            
                            // Also update the last sync time to track when GTIN was updated
                            update_post_meta($product_id, '_wc_sspaa_gtin_last_sync', current_time('mysql'));
                        } else {
                            $this->log("Failed to update GTIN meta for Product ID: {$product_id}, SKU: {$sku}");
                            $failed_updates++;
                        }
                    } else {
                        $this->log("No APN field found or APN is empty for SKU: {$sku} (Product ID: {$product_id})");
                        $skipped_no_apn++;
                    }
                } elseif (is_array($response) && 
                         isset($response['products']) && empty($response['products']) && 
                         isset($response['count']) && $response['count'] === 0) {
                    $this->log("SKU {$sku} (Product ID: {$product_id}) returned empty API response - product may be obsolete, skipping GTIN update");
                    $skipped_no_apn++;
                } else {
                    $this->log("Invalid or unexpected API response for SKU: {$sku} (Product ID: {$product_id})");
                    $failed_updates++;
                }

                $processed_count++;

                // Add optimized delay to respect API rate limits (Task 1.4.3)
                if ($current_product_number < $total_to_process) {
                    $delay_seconds = WC_SSPAA_API_DELAY_MICROSECONDS / 1000000;
                    $this->log("Pausing for {$delay_seconds} seconds before processing next product (optimized API rate limiting)");
                    usleep(WC_SSPAA_API_DELAY_MICROSECONDS);
                }

            } catch (Exception $e) {
                $failed_updates++;
                $this->log("Exception while processing GTIN update for SKU: {$sku} (Product ID: {$product_id}): " . $e->getMessage());
                $this->log("Stack trace: " . $e->getTraceAsString());
            } catch (Error $e) {
                $failed_updates++;
                $this->log("Fatal error while processing GTIN update for SKU: {$sku} (Product ID: {$product_id}): " . $e->getMessage());
                $this->log("Stack trace: " . $e->getTraceAsString());
            }
        }

        $this->log("GTIN update process completed. Processed: {$processed_count}, Successful updates: {$successful_updates}, Failed updates: {$failed_updates}, Skipped (no APN): {$skipped_no_apn}");
        
        // Store completion information
        update_option('wc_sspaa_last_gtin_sync_completion', array(
            'completed_at' => current_time('mysql'),
            'processed' => $processed_count,
            'successful' => $successful_updates,
            'failed' => $failed_updates,
            'skipped_no_apn' => $skipped_no_apn
        ));
    }

    /**
     * Update GTIN for a specific product by SKU
     *
     * @param string $sku Product SKU
     * @return array Result with success status and message
     */
    public function update_gtin_by_sku($sku)
    {
        if (empty($sku)) {
            return array(
                'success' => false,
                'message' => 'SKU cannot be empty'
            );
        }

        global $wpdb;

        // Find the product by SKU
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' AND meta_value = %s 
            LIMIT 1",
            $sku
        ));

        if (!$product_id) {
            return array(
                'success' => false,
                'message' => "No product found with SKU: {$sku}"
            );
        }

        try {
            // Get current GTIN
            $current_gtin = get_post_meta($product_id, '_wc_gtin', true);
            
            // Get product data from API
            $response = $this->api_handler->get_product_data($sku);

            if (is_array($response) && isset($response['products']) && !empty($response['products'])) {
                $product_data = $response['products'][0];
                
                if (isset($product_data['apn']) && !empty($product_data['apn'])) {
                    $apn_value = trim($product_data['apn']);
                    
                    // Update the GTIN field
                    $update_result = update_post_meta($product_id, '_wc_gtin', $apn_value);
                    
                    if ($update_result) {
                        update_post_meta($product_id, '_wc_sspaa_gtin_last_sync', current_time('mysql'));
                        
                        return array(
                            'success' => true,
                            'message' => "GTIN updated successfully for SKU: {$sku} (Product ID: {$product_id}). Previous GTIN: " . ($current_gtin ?: 'empty') . ", New GTIN: {$apn_value}"
                        );
                    } else {
                        return array(
                            'success' => false,
                            'message' => "Failed to update GTIN meta for SKU: {$sku} (Product ID: {$product_id})"
                        );
                    }
                } else {
                    return array(
                        'success' => false,
                        'message' => "No APN field found in API response for SKU: {$sku}"
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => "Invalid API response for SKU: {$sku}"
                );
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => "Exception occurred: " . $e->getMessage()
            );
        }
    }

    /**
     * Get statistics about GTIN status
     *
     * @return array Statistics about GTINs
     */
    public function get_gtin_statistics()
    {
        global $wpdb;

        // Build exclusion clause for SKUs
        $excluded_skus_clause = '';
        if (defined('WC_SSPAA_EXCLUDED_SKUS') && !empty(WC_SSPAA_EXCLUDED_SKUS)) {
            $excluded_skus = array_map(array($wpdb, 'prepare'), array_fill(0, count(WC_SSPAA_EXCLUDED_SKUS), '%s'), WC_SSPAA_EXCLUDED_SKUS);
            $excluded_skus_list = implode(',', $excluded_skus);
            $excluded_skus_clause = " AND pm_sku.meta_value NOT IN ({$excluded_skus_list})";
        }

        // Total products with SKUs
        $total_with_sku = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_obsolete ON p.ID = pm_obsolete.post_id AND pm_obsolete.meta_key = '_wc_sspaa_obsolete_exempt'
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm_sku.meta_value != ''
            AND (pm_obsolete.meta_id IS NULL OR pm_obsolete.meta_value = '' OR pm_obsolete.meta_value = 0)
            {$excluded_skus_clause}"
        );

        // Products with GTINs
        $total_with_gtin = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            INNER JOIN {$wpdb->postmeta} pm_gtin ON p.ID = pm_gtin.post_id AND pm_gtin.meta_key = '_wc_gtin'
            LEFT JOIN {$wpdb->postmeta} pm_obsolete ON p.ID = pm_obsolete.post_id AND pm_obsolete.meta_key = '_wc_sspaa_obsolete_exempt'
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm_sku.meta_value != ''
            AND pm_gtin.meta_value != ''
            AND pm_gtin.meta_value IS NOT NULL
            AND (pm_obsolete.meta_id IS NULL OR pm_obsolete.meta_value = '' OR pm_obsolete.meta_value = 0)
            {$excluded_skus_clause}"
        );

        $missing_gtin = $total_with_sku - $total_with_gtin;

        return array(
            'total_with_sku' => intval($total_with_sku),
            'total_with_gtin' => intval($total_with_gtin),
            'missing_gtin' => intval($missing_gtin),
            'percentage_with_gtin' => $total_with_sku > 0 ? round(($total_with_gtin / $total_with_sku) * 100, 2) : 0
        );
    }

    private function log($message)
    {
        if ($this->enable_debug) {
            // Use the main plugin logging function with GTIN prefix
            wc_sspaa_log("[GTIN] {$message}");
        }
    }
}
?>
