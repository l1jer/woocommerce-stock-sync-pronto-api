<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.6
Author: Jerry Li
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
require_once plugin_dir_path(__FILE__) . 'includes/class-settings.php'; // Include the settings class

function wc_sspaa_activate()
{
    wc_sspaa_schedule_batches(); // Schedule batches during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false

    add_action('wc_sspaa_update_stock_batch', 'wc_sspaa_process_batch', 10, 1); // Hook the batch processing function
    
    // Setup reschedule hook to allow updating batches when settings change
    add_action('update_option_wc_sspaa_start_time', 'wc_sspaa_reschedule_batches', 10, 2);
}
add_action('plugins_loaded', 'wc_sspaa_init');

/**
 * Count total products with SKUs
 * 
 * @return int Number of products with SKUs
 */
function wc_sspaa_count_products() {
    global $wpdb;
    
    $total_products = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
        LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
        WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')"
    );
    
    return (int)$total_products;
}

/**
 * Schedule batches for stock update
 */
function wc_sspaa_schedule_batches()
{
    wc_sspaa_log('Scheduling batch processing.');
    
    // Clear any existing scheduled batches
    wc_sspaa_clear_scheduled_batches();
    
    // Count total products
    $total_products = wc_sspaa_count_products();
    wc_sspaa_log('Total products with SKUs: ' . $total_products);
    
    // Define batch size
    $products_per_batch = 15;
    
    // Calculate number of batches
    $num_batches = ceil($total_products / $products_per_batch);
    wc_sspaa_log('Number of batches needed: ' . $num_batches);
    
    // Get start time in UTC
    $start_time_utc = WC_SSPAA_Settings::get_start_time_utc();
    list($start_hour, $start_minute) = explode(':', $start_time_utc);
    
    // Schedule batches at 30-minute intervals
    $interval_minutes = 30;
    
    for ($i = 0; $i < $num_batches; $i++) {
        // Calculate offset for this batch
        $offset = $i * $products_per_batch;
        
        // Calculate time for this batch (start time + interval)
        $minutes_to_add = $i * $interval_minutes;
        $batch_timestamp = strtotime("today {$start_hour}:{$start_minute}") + ($minutes_to_add * 60);
        
        // If timestamp is in the past, schedule for tomorrow
        if ($batch_timestamp < time()) {
            $batch_timestamp = strtotime("tomorrow {$start_hour}:{$start_minute}") + ($minutes_to_add * 60);
        }
        
        if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($offset))) {
            wc_sspaa_log('Scheduling batch ' . ($i + 1) . ' with offset: ' . $offset . ' for ' . date('Y-m-d H:i:s', $batch_timestamp) . ' UTC');
            wp_schedule_event($batch_timestamp, 'daily', 'wc_sspaa_update_stock_batch', array($offset));
        } else {
            wc_sspaa_log('Batch with offset ' . $offset . ' is already scheduled.');
        }
    }
}

/**
 * Reschedule batches when settings change
 */
function wc_sspaa_reschedule_batches($old_value, $new_value) {
    if ($old_value !== $new_value) {
        wc_sspaa_log('Start time changed from ' . $old_value . ' to ' . $new_value . '. Rescheduling batches.');
        wc_sspaa_schedule_batches();
    }
}

/**
 * Clear all scheduled batch events
 */
function wc_sspaa_clear_scheduled_batches() {
    $cron = _get_cron_array();
    
    if (empty($cron)) {
        return;
    }
    
    foreach ($cron as $timestamp => $hooks) {
        if (isset($hooks['wc_sspaa_update_stock_batch'])) {
            foreach ($hooks['wc_sspaa_update_stock_batch'] as $key => $event) {
                if (isset($event['args'][0])) {
                    $offset = $event['args'][0];
                    wc_sspaa_log('Clearing scheduled batch with offset: ' . $offset);
                    wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($offset));
                }
            }
        }
    }
}

function wc_sspaa_process_batch($batch_offset)
{
    wc_sspaa_log('Processing batch with offset: ' . $batch_offset);
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false
    $stock_updater->update_stock($batch_offset);
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_clear_scheduled_batches();
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . 'debug.log');
}
?>