<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.5
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
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_schedule_batches()
{
    wc_sspaa_log('Scheduling batch processing.');

    $batch_times = [
        ['time' => '13:59:00', 'offset' => 0],
        ['time' => '14:29:00', 'offset' => 15],
        ['time' => '14:59:00', 'offset' => 30],
        ['time' => '15:29:00', 'offset' => 45],
        ['time' => '15:59:00', 'offset' => 60],
        ['time' => '16:29:00', 'offset' => 75],
        ['time' => '16:59:00', 'offset' => 90],
        ['time' => '17:29:00', 'offset' => 105],
        ['time' => '17:59:00', 'offset' => 120],
        ['time' => '18:29:00', 'offset' => 135],
        ['time' => '18:59:00', 'offset' => 150],
    ];

    foreach ($batch_times as $batch) {
        if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($batch['offset']))) {
            wc_sspaa_log('Scheduling batch with offset: ' . $batch['offset']);
            wp_schedule_event(strtotime($batch['time']), 'daily', 'wc_sspaa_update_stock_batch', array($batch['offset']));
        } else {
            wc_sspaa_log('Batch with offset ' . $batch['offset'] . ' is already scheduled.');
        }
    }
}

function wc_sspaa_process_batch($batch_offset)
{
    wc_sspaa_log('Processing batch with offset: ' . $batch_offset);
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false
    $stock_updater->update_stock($batch_offset);
    // Removed scheduling of new events from within the batch processing function
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    $batch_times = [
        ['time' => '13:59:00', 'offset' => 0],
        ['time' => '14:29:00', 'offset' => 15],
        ['time' => '14:59:00', 'offset' => 30],
        ['time' => '15:29:00', 'offset' => 45],
        ['time' => '15:59:00', 'offset' => 60],
        ['time' => '16:29:00', 'offset' => 75],
        ['time' => '16:59:00', 'offset' => 90],
        ['time' => '17:29:00', 'offset' => 105],
        ['time' => '17:59:00', 'offset' => 120],
        ['time' => '18:29:00', 'offset' => 135],
        ['time' => '18:59:00', 'offset' => 150],
    ];

    foreach ($batch_times as $batch) {
        wc_sspaa_log('Clearing scheduled batch with offset: ' . $batch['offset']);
        wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($batch['offset']));
    }
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . 'debug.log');
}
?>