<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.2
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

function wcap_init()
{
    $api_handler = new WCAP_API_Handler();
    $stock_updater = new WCAP_Stock_Updater($api_handler, 2000000, 20, 5000000, 10, 25); // Initialize stock updater with delay, burst limit, pause, batch size, and execution time limit

    // Schedule the cron job for stock updates at 2am daily
    if (!wp_next_scheduled('wcap_update_stock')) {
        error_log('Scheduling initial wcap_update_stock event.');
        wp_schedule_event(strtotime('02:00:00'), 'daily', 'wcap_update_stock'); // Schedule the event to run daily at 2am
    }
    add_action('wcap_update_stock', 'wcap_schedule_batches'); // Hook the schedule batches function to the cron event
    add_action('wcap_update_stock_batch', 'wcap_process_batch', 10, 1); // Hook the batch processing function to the batch event
}
add_action('plugins_loaded', 'wcap_init');

function wcap_schedule_batches()
{
    error_log('Scheduling initial batch processing.');
    wp_schedule_single_event(time(), 'wcap_update_stock_batch', array(0)); // Schedule the first batch processing event with offset 0
}

function wcap_process_batch($batch_offset)
{
    error_log('Processing batch with offset: ' . $batch_offset);
    $api_handler = new WCAP_API_Handler(); // Initialize API handler
    $stock_updater = new WCAP_Stock_Updater($api_handler, 2000000, 20, 5000000, 10, 25); // Initialize stock updater
    $stock_updater->update_stock($batch_offset); // Call the update stock method with the current batch offset
}

// Deactivate the plugin and clear scheduled events
function wcap_deactivate()
{
    wp_clear_scheduled_hook('wcap_update_stock'); // Clear the main cron event
    wp_clear_scheduled_hook('wcap_update_stock_batch'); // Clear the batch processing event
}
register_deactivation_hook(__FILE__, 'wcap_deactivate');

// Add a new column for Avenue Stock Sync and position it between Stock and Price
function wcap_add_custom_columns($columns)
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
add_filter('manage_edit-product_columns', 'wcap_add_custom_columns');

// Display the sync date and time in the new column
function wcap_display_sync_info_in_column($column, $post_id)
{
    if ('avenue_stock_sync' === $column) {
        $last_sync = get_post_meta($post_id, '_wcap_last_sync', true);
        if ($last_sync) {
            echo '<span style="color: #999; white-space: nowrap;">' . esc_html($last_sync) . '</span>';
        } else {
            echo '<span style="color: #999;">N/A</span>';
        }
    }
}
add_action('manage_product_posts_custom_column', 'wcap_display_sync_info_in_column', 10, 2);

// Ensure custom columns are registered and sortable
function wcap_register_sortable_columns($columns)
{
    $columns['avenue_stock_sync'] = 'avenue_stock_sync';
    return $columns;
}
add_filter('manage_edit-product_sortable_columns', 'wcap_register_sortable_columns');

// Implement sorting functionality for the custom column
function wcap_sort_custom_column($query)
{
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ('avenue_stock_sync' === $query->get('orderby')) {
        $query->set('meta_key', '_wcap_last_sync');
        $query->set('orderby', 'meta_value');
    }
}
add_action('pre_get_posts', 'wcap_sort_custom_column');
?>