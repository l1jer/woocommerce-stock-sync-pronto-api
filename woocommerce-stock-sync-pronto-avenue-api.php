<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.3.10
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
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-status-page.php'; // Include the stock sync status page class

function wc_sspaa_activate()
{
    wc_sspaa_schedule_batches(); // Schedule batches during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 3000000, 15, 2000000, 15, 25, false); // Set enable_debug to false

    add_action('wc_sspaa_update_stock_batch', 'wc_sspaa_process_batch', 10, 1); // Hook the batch processing function
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_schedule_batches()
{
    wc_sspaa_log('Scheduling batch processing.');

    // Get the saved sync time or use default
    $sync_time = get_option('wc_sspaa_sync_time', '02:00:00');
    wc_sspaa_log("Using sync time: {$sync_time}");
    
    // Set up Sydney timezone
    $sydney_timezone = new DateTimeZone('Australia/Sydney');
    $utc_timezone = new DateTimeZone('UTC');
    
    global $wpdb;
    // Get total number of products with SKUs
    $products_with_skus = $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_sku' 
        AND p.post_type IN ('product', 'product_variation')
        AND pm.meta_value != ''"
    );
    $batch_size = 15;
    $total_batches = ceil($products_with_skus / $batch_size);
    wc_sspaa_log("Total products with SKUs: {$products_with_skus}, Batch size: {$batch_size}, Total batches: {$total_batches}");

    // Generate batch times
    $batch_times = [];
    for ($i = 0; $i < $total_batches; $i++) {
        $offset = $i * $batch_size;
        // Calculate time (add 30 minutes between each batch)
        $time_parts = explode(':', $sync_time);
        $hours = (int)$time_parts[0];
        $minutes = (int)$time_parts[1];
        $seconds = (int)$time_parts[2];
        // Add 30 minutes for each batch
        $minutes += ($i * 30);
        // Handle overflow
        $hours += floor($minutes / 60);
        $minutes = $minutes % 60;
        $hours = $hours % 24;
        $batch_time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        $batch_times[] = ['time' => $batch_time, 'offset' => $offset];
    }

    // Get current date in Sydney timezone
    $today = new DateTime('now', $sydney_timezone);
    $today_date = $today->format('Y-m-d');

    foreach ($batch_times as $batch) {
        if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($batch['offset']))) {
            // Create Sydney datetime with the batch time
            $sydney_datetime = new DateTime($today_date . ' ' . $batch['time'], $sydney_timezone);
            // Convert to UTC for scheduling
            $sydney_datetime->setTimezone($utc_timezone);
            $utc_timestamp = $sydney_datetime->getTimestamp();
            wc_sspaa_log('Scheduling batch with offset: ' . $batch['offset'] . 
                ' at Sydney time: ' . $batch['time'] . 
                ' (UTC time: ' . $sydney_datetime->format('Y-m-d H:i:s') . ')');
            wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_update_stock_batch', array($batch['offset']));
        } else {
            wc_sspaa_log('Batch with offset ' . $batch['offset'] . ' is already scheduled.');
        }
    }
}

function wc_sspaa_process_batch($batch_offset)
{
    wc_sspaa_log('Processing batch with offset: ' . $batch_offset);
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 3000000, 15, 2000000, 15, 25, true);
    $stock_updater->update_stock($batch_offset);
    // Removed scheduling of new events from within the batch processing function
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('Deactivating plugin and clearing scheduled events.');
    
    // Clear all scheduled batches
    for ($i = 0; $i < 11; $i++) {
        $offset = $i * 15;
        wc_sspaa_log('Clearing scheduled batch with offset: ' . $offset);
        wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($offset));
    }
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    $log_file = plugin_dir_path(__FILE__) . 'debug.log';
    $max_age_days = 4;
    $max_age_seconds = $max_age_days * 86400;
    $now = time();
    $new_log_entry = "[$timestamp] $message\n";

    // Purge old log entries (older than 4 days) before writing
    if (file_exists($log_file)) {
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $retained_lines = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $entry_time = strtotime($matches[1]);
                if ($entry_time !== false && ($now - $entry_time) <= $max_age_seconds) {
                    $retained_lines[] = $line;
                }
            } else {
                // If the line does not match the expected format, keep it for safety
                $retained_lines[] = $line;
            }
        }
        // Write back only the retained lines
        file_put_contents($log_file, implode("\n", $retained_lines) . "\n", LOCK_EX);
    }
    // Append the new log entry
    file_put_contents($log_file, $new_log_entry, FILE_APPEND | LOCK_EX);
}
?>