<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with sequential processing.
Version: 1.3.19
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
require_once plugin_dir_path(__FILE__) . 'includes/class-products-page-sync-button.php'; // Include the products page sync button class

function wc_sspaa_activate()
{
    wc_sspaa_schedule_daily_sync(); // Schedule daily sync during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 15000000, 0, 0, 0, 0, false); // 15 second delay, no batch limits

    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_process_all_products'); // Hook the daily sync function
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_schedule_daily_sync()
{
    wc_sspaa_log('Scheduling daily stock synchronisation.');

    // Clear any existing scheduled events first
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');

    // Get the saved sync time or use default
    $sync_time = get_option('wc_sspaa_sync_time', '02:00:00');
    wc_sspaa_log("Using sync time: {$sync_time}");
    
    // Set up Sydney timezone
    $sydney_timezone = new DateTimeZone('Australia/Sydney');
    $utc_timezone = new DateTimeZone('UTC');
    
    // Get current date in Sydney timezone
    $today = new DateTime('now', $sydney_timezone);
    $today_date = $today->format('Y-m-d');

    // Create Sydney datetime with the sync time
    $sydney_datetime = new DateTime($today_date . ' ' . $sync_time, $sydney_timezone);
    
    // If the time has already passed today, schedule for tomorrow
    $now = new DateTime('now', $sydney_timezone);
    if ($sydney_datetime <= $now) {
        $sydney_datetime->add(new DateInterval('P1D'));
    }
    
    // Convert to UTC for scheduling
    $sydney_datetime->setTimezone($utc_timezone);
    $utc_timestamp = $sydney_datetime->getTimestamp();
    
    wc_sspaa_log('Scheduling daily sync at Sydney time: ' . $sync_time . 
        ' (UTC time: ' . $sydney_datetime->format('Y-m-d H:i:s') . ')');
    
    wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_daily_stock_sync');
}

function wc_sspaa_process_all_products()
{
    wc_sspaa_log('Starting complete stock synchronisation for all products.');
    
    global $wpdb;
    
    // Get total count of products with SKUs
    $total_products = $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_sku' 
        AND p.post_type IN ('product', 'product_variation')
        AND pm.meta_value != ''"
    );
    
    wc_sspaa_log("Total products with SKUs to sync: {$total_products}");
    
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 15000000, 0, 0, 0, 0, true); // 15 second delay, debug enabled
    
    // Process all products sequentially
    $stock_updater->update_all_products();
    
    wc_sspaa_log('Completed stock synchronisation for all products.');
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('Deactivating plugin and clearing scheduled events.');
    
    // Clear the daily sync event
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    
    // Remove the saved sync time option
    delete_option('wc_sspaa_sync_time');
    
    wc_sspaa_log('All scheduled events cleared successfully.');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    $log_file = plugin_dir_path(__FILE__) . 'wc-sspaa-debug.log'; // Dedicated log file
    $new_log_entry = "[$timestamp] $message\n";

    // Ensure proper file permissions and create file if it doesn't exist
    if (!file_exists($log_file)) {
        // Create the file with proper permissions
        if (touch($log_file)) {
            chmod($log_file, 0644); // Read/write for owner, read for others
        }
    }

    // Append the new log entry first (most important operation)
    if (is_writable($log_file) || is_writable(dirname($log_file))) {
        file_put_contents($log_file, $new_log_entry, FILE_APPEND | LOCK_EX);
    }

    // Only perform log cleanup occasionally to avoid constant file rewrites
    // Check if cleanup is needed (randomly 1 in 50 chance, or if file is very large)
    $should_cleanup = (rand(1, 50) === 1) || (file_exists($log_file) && filesize($log_file) > 5242880); // 5MB threshold
    
    if ($should_cleanup && file_exists($log_file) && is_readable($log_file) && is_writable($log_file)) {
    $max_age_days = 4;
    $max_age_seconds = $max_age_days * 86400;
    $now = time();

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $retained_lines = [];
        $cleanup_needed = false;
        
        if ($lines !== false) {
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $entry_time = strtotime($matches[1]);
                if ($entry_time !== false && ($now - $entry_time) <= $max_age_seconds) {
                    $retained_lines[] = $line;
                    } else {
                        $cleanup_needed = true; // Found old entries to remove
                }
            } else {
                // If the line does not match the expected format, keep it for safety
                $retained_lines[] = $line;
            }
        }
            
            // Only rewrite the file if we actually found old entries to remove
            if ($cleanup_needed && count($retained_lines) < count($lines)) {
        file_put_contents($log_file, implode("\n", $retained_lines) . "\n", LOCK_EX);
                // Log the cleanup action (but avoid infinite recursion)
                $cleanup_message = "[$timestamp] Log cleanup completed: removed " . (count($lines) - count($retained_lines)) . " old entries\n";
                file_put_contents($log_file, $cleanup_message, FILE_APPEND | LOCK_EX);
            }
        }
    }
}
?>