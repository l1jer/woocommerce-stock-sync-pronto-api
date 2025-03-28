<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.9
Author: Jerry Li
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_SSPAA_VERSION', '1.1.9');
define('WC_SSPAA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_SSPAA_DEBUG', true); // Set to true to enable detailed logging
define('WC_SSPAA_EMAIL_RECIPIENT', 'jerry@tasco.com.au'); // Email recipient for sync reports

// Check for WordPress debugging
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Enable WordPress mail logging if debugging is enabled
if (WC_SSPAA_DEBUG && !defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Include standard WordPress mail settings for compatibility
if (!defined('WPMS_ON')) {
    define('WPMS_ON', true); // Enable WordPress Mail SMTP mode
}

// WordPress email debugging
add_action('phpmailer_init', 'wc_sspaa_log_mailer');
function wc_sspaa_log_mailer($mailer) {
    wc_sspaa_log('MAILER: To: ' . print_r($mailer->getToAddresses(), true), true);
    wc_sspaa_log('MAILER: Subject: ' . $mailer->Subject, true);
    wc_sspaa_log('MAILER: From: ' . $mailer->From . ' <' . $mailer->FromName . '>', true);
}

// Include necessary files
require_once WC_SSPAA_PLUGIN_DIR . 'includes/config.php'; // Include the config file for credentials
require_once WC_SSPAA_PLUGIN_DIR . 'includes/class-api-handler.php'; // Include the API handler class
require_once WC_SSPAA_PLUGIN_DIR . 'includes/class-stock-updater.php'; // Include the stock updater class
require_once WC_SSPAA_PLUGIN_DIR . 'includes/class-stock-sync-time-col.php'; // Include the stock sync time column class
require_once WC_SSPAA_PLUGIN_DIR . 'includes/class-settings.php'; // Include the settings class
require_once WC_SSPAA_PLUGIN_DIR . 'includes/class-email-notification.php'; // Include the email notification class

// Global variable to store the email notification instance for the current sync cycle
$wc_sspaa_email_notification = null;

function wc_sspaa_activate()
{
    wc_sspaa_log('CORE: Plugin activated - version ' . WC_SSPAA_VERSION);
    wc_sspaa_schedule_batches(); // Schedule batches during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, WC_SSPAA_DEBUG);

    add_action('wc_sspaa_update_stock_batch', 'wc_sspaa_process_batch', 10, 1); // Hook the batch processing function
    add_action('wc_sspaa_sync_complete', 'wc_sspaa_complete_sync', 10, 0); // Hook for completing the sync process
    
    // Setup reschedule hook to allow updating batches when settings change
    add_action('update_option_wc_sspaa_start_time', 'wc_sspaa_reschedule_batches', 10, 2);
    
    // Add admin hooks for email testing
    add_action('admin_init', 'wc_sspaa_register_email_test');
    
    wc_sspaa_log('CORE: Plugin initialized - version ' . WC_SSPAA_VERSION);
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
    wc_sspaa_log('CORE: Scheduling batch processing.');
    
    // Clear any existing scheduled batches
    wc_sspaa_clear_scheduled_batches();
    
    // Count total products
    $total_products = wc_sspaa_count_products();
    wc_sspaa_log('CORE: Total products with SKUs: ' . $total_products);
    
    // Define batch size
    $products_per_batch = 15;
    
    // Calculate number of batches
    $num_batches = ceil($total_products / $products_per_batch);
    wc_sspaa_log('CORE: Number of batches needed: ' . $num_batches);
    
    // Get start time in UTC
    $start_time_utc = WC_SSPAA_Settings::get_start_time_utc();
    list($start_hour, $start_minute) = explode(':', $start_time_utc);
    wc_sspaa_log('CORE: Using start time (UTC): ' . $start_time_utc);
    
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
        
        $batch_time = date('Y-m-d H:i:s', $batch_timestamp);
        
        if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($offset))) {
            wc_sspaa_log('CORE: Scheduling batch ' . ($i + 1) . ' with offset: ' . $offset . ' for ' . $batch_time . ' UTC');
            wp_schedule_event($batch_timestamp, 'daily', 'wc_sspaa_update_stock_batch', array($offset));
        } else {
            wc_sspaa_log('CORE: Batch with offset ' . $offset . ' is already scheduled.');
        }
    }
    
    // Schedule the sync completion event after the last batch
    $completion_timestamp = $batch_timestamp + (30 * 60); // 30 minutes after the last batch
    $completion_time = date('Y-m-d H:i:s', $completion_timestamp);
    
    if (!wp_next_scheduled('wc_sspaa_sync_complete')) {
        wc_sspaa_log('CORE: Scheduling sync completion for ' . $completion_time . ' UTC');
        wp_schedule_event($completion_timestamp, 'daily', 'wc_sspaa_sync_complete');
    } else {
        wp_clear_scheduled_hook('wc_sspaa_sync_complete');
        wc_sspaa_log('CORE: Rescheduling sync completion for ' . $completion_time . ' UTC');
        wp_schedule_event($completion_timestamp, 'daily', 'wc_sspaa_sync_complete');
    }
    
    // Initialize the email notification for this sync cycle
    global $wc_sspaa_email_notification;
    $wc_sspaa_email_notification = new WC_SSPAA_Email_Notification(WC_SSPAA_EMAIL_RECIPIENT);
    $wc_sspaa_email_notification->start_sync($total_products, $num_batches);
    
    // Save this in a transient to be used by the batches
    set_transient('wc_sspaa_current_sync_batch_count', $num_batches, 24 * HOUR_IN_SECONDS);
    set_transient('wc_sspaa_current_sync_total_products', $total_products, 24 * HOUR_IN_SECONDS);
    set_transient('wc_sspaa_current_sync_start_time', current_time('mysql'), 24 * HOUR_IN_SECONDS);
    
    wc_sspaa_log('CORE: Batch scheduling completed - ' . $num_batches . ' batches scheduled');
}

/**
 * Reschedule batches when settings change
 */
function wc_sspaa_reschedule_batches($old_value, $new_value) {
    if ($old_value !== $new_value) {
        wc_sspaa_log('CORE: Start time changed from ' . $old_value . ' to ' . $new_value . '. Rescheduling batches.');
        wc_sspaa_schedule_batches();
    }
}

/**
 * Clear all scheduled batch events
 */
function wc_sspaa_clear_scheduled_batches() {
    $cron = _get_cron_array();
    
    if (empty($cron)) {
        wc_sspaa_log('CORE: No scheduled crons found');
        return;
    }
    
    $cleared_count = 0;
    
    foreach ($cron as $timestamp => $hooks) {
        if (isset($hooks['wc_sspaa_update_stock_batch'])) {
            foreach ($hooks['wc_sspaa_update_stock_batch'] as $key => $event) {
                if (isset($event['args'][0])) {
                    $offset = $event['args'][0];
                    $time = date('Y-m-d H:i:s', $timestamp);
                    wc_sspaa_log('CORE: Clearing scheduled batch with offset: ' . $offset . ' at time: ' . $time);
                    wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($offset));
                    $cleared_count++;
                }
            }
        }
    }
    
    // Also clear the sync complete event
    if (wp_next_scheduled('wc_sspaa_sync_complete')) {
        wp_clear_scheduled_hook('wc_sspaa_sync_complete');
        $cleared_count++;
        wc_sspaa_log('CORE: Cleared sync completion event');
    }
    
    wc_sspaa_log('CORE: Cleared ' . $cleared_count . ' scheduled events');
}

/**
 * Process a batch of products
 * 
 * @param int $batch_offset The offset for this batch
 */
function wc_sspaa_process_batch($batch_offset)
{
    wc_sspaa_log('CORE: Processing batch with offset: ' . $batch_offset);
    
    $batch_start = microtime(true);
    
    // Create email notification handler if needed
    $email_notification = wc_sspaa_get_email_notification();
    
    // Create instances for this batch
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, WC_SSPAA_DEBUG);
    
    // Set email notification handler
    $stock_updater->set_email_notification($email_notification);
    
    // Process the batch
    $batch_stats = $stock_updater->update_stock($batch_offset);
    
    // Track completed batches for sync completion
    $completed_batches = get_transient('wc_sspaa_completed_batches') ?: 0;
    $completed_batches++;
    set_transient('wc_sspaa_completed_batches', $completed_batches, 24 * HOUR_IN_SECONDS);
    
    $total_batches = get_transient('wc_sspaa_current_sync_batch_count') ?: 0;
    $percent_complete = $total_batches > 0 ? round(($completed_batches / $total_batches) * 100) : 0;
    
    $execution_time = microtime(true) - $batch_start;
    wc_sspaa_log('CORE: Batch processing with offset ' . $batch_offset . ' completed in ' . round($execution_time, 2) . 
                ' seconds. Progress: ' . $completed_batches . '/' . $total_batches . ' (' . $percent_complete . '%)');
}

/**
 * Get the email notification instance, creating it if needed
 * 
 * @return WC_SSPAA_Email_Notification
 */
function wc_sspaa_get_email_notification() {
    global $wc_sspaa_email_notification;
    
    if ($wc_sspaa_email_notification === null) {
        // Get email recipient from options, defaulting to the constant if not set
        $email_recipient = get_option('wc_sspaa_email_recipient', WC_SSPAA_EMAIL_RECIPIENT);
        $wc_sspaa_email_notification = new WC_SSPAA_Email_Notification($email_recipient);
        
        // If we're in the middle of a sync cycle, initialize it with saved data
        $total_products = get_transient('wc_sspaa_current_sync_total_products');
        $batch_count = get_transient('wc_sspaa_current_sync_batch_count');
        
        if ($total_products && $batch_count) {
            $wc_sspaa_email_notification->start_sync($total_products, $batch_count);
        }
    }
    
    return $wc_sspaa_email_notification;
}

/**
 * Complete the sync process and send email notification
 */
function wc_sspaa_complete_sync() {
    wc_sspaa_log('CORE: Sync process complete, sending email notification');
    
    // Get email notification handler
    $email_notification = wc_sspaa_get_email_notification();
    
    // Complete sync and send report
    $email_notification->complete_sync();
    
    // Clean up transients
    delete_transient('wc_sspaa_completed_batches');
    delete_transient('wc_sspaa_current_sync_batch_count');
    delete_transient('wc_sspaa_current_sync_total_products');
    delete_transient('wc_sspaa_current_sync_start_time');
    
    wc_sspaa_log('CORE: Sync completion process finished');
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('CORE: Plugin deactivating - clearing scheduled batches');
    wc_sspaa_clear_scheduled_batches();
    wc_sspaa_log('CORE: Plugin deactivated');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

/**
 * Logging function to include timestamps and details
 * 
 * @param string $message The message to log
 * @param bool $force Force logging even if debugging is disabled
 */
function wc_sspaa_log($message, $force = false)
{
    if ($force || WC_SSPAA_DEBUG) {
        $timestamp = date("Y-m-d H:i:s");
        $memory_usage = round(memory_get_usage() / 1024 / 1024, 2); // Memory usage in MB
        $log_entry = "[$timestamp] [Memory: {$memory_usage}MB] $message\n";
        error_log($log_entry, 3, WC_SSPAA_PLUGIN_DIR . 'debug.log');
    }
}

/**
 * Register email test feature
 */
function wc_sspaa_register_email_test() {
    // Check for email test action
    if (isset($_GET['wc_sspaa_test_email']) && current_user_can('manage_options')) {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wc_sspaa_test_email')) {
            wp_die('Security check failed');
        }
        
        $result = wc_sspaa_send_test_email();
        
        // Redirect back with result
        wp_redirect(add_query_arg(array('test_email_sent' => $result ? 'success' : 'failed'), admin_url('edit.php?post_type=product&page=wc-sspaa-settings')));
        exit;
    }
    
    // Show admin notice for test result
    if (isset($_GET['test_email_sent'])) {
        if ($_GET['test_email_sent'] === 'success') {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Test email sent successfully. Please check your inbox and spam folder.</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to send test email. Please check server configuration.</p></div>';
            });
        }
    }
}

/**
 * Send a test email
 * 
 * @return bool Success or failure
 */
function wc_sspaa_send_test_email() {
    wc_sspaa_log('CORE: Sending test email', true);
    
    $recipient = get_option('wc_sspaa_email_recipient', WC_SSPAA_EMAIL_RECIPIENT);
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    
    $subject = 'WC Stock Sync Email Test - ' . current_time('mysql');
    $message = '<!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>Email Test</title>
    </head>
    <body>
        <h1>WordPress Email Test</h1>
        <p>This is a test email to verify the email functionality is working correctly in your WooCommerce Stock Sync plugin.</p>
        <p><strong>Site:</strong> ' . esc_html($site_name) . ' (' . esc_html($site_url) . ')</p>
        <p><strong>Time:</strong> ' . current_time('mysql') . '</p>
        <p><strong>Plugin Version:</strong> ' . WC_SSPAA_VERSION . '</p>
        <hr>
        <p>If you received this email, it means the email functionality is working correctly.</p>
    </body>
    </html>';
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <wordpress@' . parse_url($site_url, PHP_URL_HOST) . '>',
        'Reply-To: ' . $admin_email,
    );
    
    // Save a copy for debugging
    $debug_file = WC_SSPAA_PLUGIN_DIR . 'test_email.html';
    file_put_contents($debug_file, $message);
    
    // Send the test email
    $result = wp_mail($recipient, $subject, $message, $headers);
    
    if ($result) {
        wc_sspaa_log('CORE: Test email sent successfully to ' . $recipient, true);
        
        // Also send to admin if different
        if ($admin_email && $admin_email !== $recipient) {
            wp_mail($admin_email, '[COPY] ' . $subject, $message, $headers);
            wc_sspaa_log('CORE: Copy of test email sent to admin: ' . $admin_email, true);
        }
    } else {
        wc_sspaa_log('CORE: Failed to send test email to ' . $recipient, true);
    }
    
    return $result;
}
?>