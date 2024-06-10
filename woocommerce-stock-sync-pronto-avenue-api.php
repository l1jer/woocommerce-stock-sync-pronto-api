<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.3
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

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25); // Initialize stock updater with delay, burst limit, pause, batch size, and execution time limit

    // Schedule the cron job for stock updates at 12am daily
    if (!wp_next_scheduled('wc_sspaa_update_stock')) {
        wc_sspaa_log('Scheduling initial wc_sspaa_update_stock event.');
        wp_schedule_event(strtotime('13:59:00'), 'daily', 'wc_sspaa_update_stock'); // Schedule the event to run daily at 12am AEST
    }
    add_action('wc_sspaa_update_stock', 'wc_sspaa_schedule_batches'); // Hook the schedule batches function to the cron event
    add_action('wc_sspaa_update_stock_batch', 'wc_sspaa_process_batch', 10, 1); // Hook the batch processing function to the batch event
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_schedule_batches()
{
    wc_sspaa_log('Scheduling initial batch processing.');
    wp_schedule_single_event(time(), 'wc_sspaa_update_stock_batch', array(0)); // Schedule the first batch processing event with offset 0
}

function wc_sspaa_process_batch($batch_offset)
{
    wc_sspaa_log('Processing batch with offset: ' . $batch_offset);
    $api_handler = new WC_SSPAA_API_Handler(); // Initialize API handler
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25); // Initialize stock updater
    $stock_updater->update_stock($batch_offset); // Call the update stock method with the current batch offset
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wp_clear_scheduled_hook('wc_sspaa_update_stock'); // Clear the main cron event
    wp_clear_scheduled_hook('wc_sspaa_update_stock_batch'); // Clear the batch processing event
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Add a new column for Avenue Stock Sync and position it between Stock and Price
function wc_sspaa_add_custom_columns($columns)
{
    $reordered_columns = array();
    foreach ($columns as $key => $value) {
        if ($key == 'price') {
            $reordered_columns['avenue_stock_sync'] = __('Avenue Stock Sync', 'woocommerce');
        }
        $reordered_columns[$key] = $value;
    }
    return $reordered_columns;
}
add_filter('manage_edit-product_columns', 'wc_sspaa_add_custom_columns');

// Display the sync date and time in the new column
function wc_sspaa_display_sync_info_in_column($column, $post_id)
{
    if ('avenue_stock_sync' === $column) {
        $last_sync = get_post_meta($post_id, '_wc_sspaa_last_sync', true);
        if ($last_sync) {
            echo '<span style="color: #999; white-space: nowrap;">' . esc_html($last_sync) . '</span>';
        } else {
            echo '<span style="color: #999;">N/A</span>';
        }
    }
}
add_action('manage_product_posts_custom_column', 'wc_sspaa_display_sync_info_in_column', 10, 2);

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . 'debug.log');
}
?>