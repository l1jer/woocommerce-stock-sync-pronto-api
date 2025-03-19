<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.7
Author: Jerry Li
Requires at least: 3.6
Requires PHP: 5.3
Tested up to: 6.7.2
WC requires at least: 3.0
WC tested up to: 9.6
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/config.php'; // Include the config file for credentials
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php'; // Include the API handler class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-updater.php'; // Include the stock updater class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-time-col.php'; // Include the stock sync time column class

function wc_sspaa_activate()
{
    wc_sspaa_schedule_daily_sync(); // Schedule daily sync during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false

    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_run_sync'); // Hook the daily sync function
    
    // Add AJAX handler for individual product sync
    add_action('wp_ajax_wc_sspaa_sync_single_product', 'wc_sspaa_handle_single_product_sync');
}
add_action('plugins_loaded', 'wc_sspaa_init');

// Schedule daily sync at 1AM except on weekends
function wc_sspaa_schedule_daily_sync()
{
    wc_sspaa_log('Scheduling daily sync at 1AM except weekends.');
    
    // Clear any existing schedule
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    
    // Schedule new daily sync at 1AM
    $next_run = wc_sspaa_get_next_run_time();
    if ($next_run) {
        wp_schedule_event($next_run, 'daily', 'wc_sspaa_daily_stock_sync');
        wc_sspaa_log('Daily sync scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    }
}

// Get the next valid run time (1AM on non-weekend day)
function wc_sspaa_get_next_run_time()
{
    $timestamp = strtotime('1:00:00 tomorrow');
    $day_of_week = date('N', $timestamp);
    
    // If it's Saturday (6) or Sunday (7), adjust to Monday
    if ($day_of_week >= 6) {
        $timestamp = strtotime('next Monday 1:00:00', $timestamp - 86400);
    }
    
    return $timestamp;
}

// Run the sync process with dynamic batch calculation
function wc_sspaa_run_sync()
{
    global $wpdb;
    
    wc_sspaa_log('Starting daily stock sync');
    
    // Count total products with SKUs
    $count_query = "SELECT COUNT(post_id) FROM {$wpdb->postmeta}
        LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
        WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')";
    
    $total_products = $wpdb->get_var($count_query);
    wc_sspaa_log("Total products to sync: {$total_products}");
    
    if ($total_products == 0) {
        wc_sspaa_log("No products found to sync.");
        return;
    }
    
    // Calculate batches (15 products per batch)
    $batch_size = 15;
    $num_batches = ceil($total_products / $batch_size);
    
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false);
    
    $updated_count = 0;
    
    // Process each batch
    for ($i = 0; $i < $num_batches; $i++) {
        $offset = $i * $batch_size;
        wc_sspaa_log("Processing batch " . ($i+1) . "/{$num_batches} with offset {$offset}");
        $batch_updated = $stock_updater->update_stock($offset);
        $updated_count += $batch_updated;
        
        // Small pause between batches to prevent server overload
        if ($i < $num_batches - 1) {
            sleep(1);
        }
    }
    
    wc_sspaa_log("Daily stock sync completed. Updated {$updated_count} products.");
    
    // Schedule the next sync
    wc_sspaa_schedule_daily_sync();
}

// Handle single product sync AJAX request
function wc_sspaa_handle_single_product_sync()
{
    // Verify nonce
    check_ajax_referer('wc_sspaa_sync_product_nonce', 'security');
    
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Get product ID
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product ID.']);
        return;
    }
    
    // Enable debug for manual syncs
    add_filter('wc_sspaa_enable_debug', '__return_true');
    
    wc_sspaa_log("======= Manual sync triggered for product ID: {$product_id} =======");
    
    try {
        // Get product SKU
        $sku = get_post_meta($product_id, '_sku', true);
        
        if (empty($sku)) {
            wc_sspaa_log("Product ID {$product_id} has no SKU.");
            wp_send_json_error(['message' => 'Product has no SKU.']);
            return;
        }
        
        wc_sspaa_log("Syncing product with SKU: {$sku}");
        
        // Create handlers
        $api_handler = new WC_SSPAA_API_Handler();
        $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, true);
        
        // Sync the product
        $updated = $stock_updater->sync_single_product($product_id, $sku);
        
        // Check if product was marked as obsolete
        $is_obsolete = get_post_meta($product_id, '_wc_sspaa_is_obsolete', true) === 'yes';
        $stock_value = get_post_meta($product_id, '_stock', true);
        $new_sync_time = get_post_meta($product_id, '_wc_sspaa_last_sync', true);
        
        if ($updated === 'obsolete') {
            wc_sspaa_log("Product ID {$product_id} is obsolete (not found in API)");
            wp_send_json_success([
                'message' => "Product not found in API. Marked as obsolete and stock set to 0.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => true,
                'stock_value' => 0
            ]);
        } else if ($updated) {
            $new_stock = get_post_meta($product_id, '_stock', true);
            wc_sspaa_log("Successfully updated stock for product ID {$product_id} to {$new_stock}");
            wp_send_json_success([
                'message' => "Stock updated successfully to {$new_stock}.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => $is_obsolete,
                'stock_value' => $new_stock
            ]);
        } else {
            $current_stock = get_post_meta($product_id, '_stock', true);
            wc_sspaa_log("No change in stock for product ID {$product_id}");
            wp_send_json_success([
                'message' => "Stock check completed. No change required.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => $is_obsolete,
                'stock_value' => $current_stock
            ]);
        }
        
    } catch (Exception $e) {
        wc_sspaa_log("Error syncing product ID {$product_id}: " . $e->getMessage());
        wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
    }
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('Clearing scheduled sync events');
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    // Allow enabling debug via filter, especially for manual syncs
    $enable_debug = apply_filters('wc_sspaa_enable_debug', false);
    
    $timestamp = date("Y-m-d H:i:s");
    $log_message = "[$timestamp] $message\n";
    
    // Always log to debug.log for easier troubleshooting
    error_log($log_message, 3, plugin_dir_path(__FILE__) . 'debug.log');
    
    // For manual syncs, we can also log to PHP error log for immediate visibility
    if ($enable_debug) {
        error_log('WC Stock Sync: ' . $message);
    }
}
?>