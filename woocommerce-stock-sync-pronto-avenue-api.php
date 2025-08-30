<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with sequential processing. Now includes GTIN synchronisation from API APN field, daily log file management with 14-day retention, dual warehouse stock calculation for SkyWatcher Australia, and optimized API usage for 10 calls/second performance.
Version: 1.4.3
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
require_once plugin_dir_path(__FILE__) . 'includes/class-gtin-updater.php'; // Include the GTIN updater class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-time-col.php'; // Include the stock sync time column class
require_once plugin_dir_path(__FILE__) . 'includes/class-products-page-sync-button.php'; // Include the products page sync button class

// Define domain-specific sync times (Sydney time, 24-hour format)
define('WC_SSPAA_DOMAIN_SYNC_SCHEDULE', array(
    'store.zerotechoptics.com' => '00:25:00',
    'skywatcheraustralia.com.au' => '00:55:00',
    'zerotech.com.au' => '01:25:00',
    'zerotechoutdoors.com.au' => '01:55:00',
    'nitecoreaustralia.com.au' => '02:25:00'
));
define('WC_SSPAA_DEFAULT_SYNC_TIME', '03:00:00'); // Default for other domains

// Define SKUs to exclude from sync process - add or remove SKUs as needed
define('WC_SSPAA_EXCLUDED_SKUS', array(
    '91523',
    '91530', 
    '91531',
    '11074-XLT'
));

// Define API rate limit settings (Task 1.4.3: Optimized for 10 calls/second API limit)
define('WC_SSPAA_API_DELAY_MICROSECONDS', 142857); // ~0.143 seconds (7 calls/second with safety margin)
define('WC_SSPAA_API_CALLS_PER_SECOND', 7);        // Target rate with 30% safety margin

function wc_sspaa_activate()
{
    wc_sspaa_schedule_daily_sync(); // Schedule daily sync during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    // Direct cron hook - no more AJAX for scheduled sync
    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_execute_scheduled_sync'); // Direct execution
    
    // Keep AJAX handlers for manual sync from admin interface
    add_action('wp_ajax_wc_sspaa_run_scheduled_sync', 'wc_sspaa_handle_manual_sync'); // For manual trigger only
    
    // Action to manually clear Obsolete exemption for a product
    add_action('admin_action_wc_sspaa_clear_obsolete_exemption', 'wc_sspaa_handle_clear_obsolete_exemption');
    
    // Manual trigger for testing scheduled sync
    add_action('admin_action_wc_sspaa_manual_trigger_scheduled_sync', 'wc_sspaa_manual_trigger_scheduled_sync');

    // Action to manually clear the sync lock
    add_action('admin_action_wc_sspaa_clear_sync_lock', 'wc_sspaa_handle_clear_sync_lock');

    // Action to manually trigger GTIN sync
    add_action('admin_action_wc_sspaa_manual_gtin_sync', 'wc_sspaa_handle_manual_gtin_sync');

    // Register "Obsolete" stock status with WooCommerce
    add_filter('woocommerce_product_stock_status_options', 'wc_sspaa_add_obsolete_stock_status_options');

    // Ensure "Obsolete" products are treated as out of stock
    add_filter('woocommerce_product_is_in_stock', 'wc_sspaa_product_is_in_stock_for_obsolete', 10, 2);

    // Customize the display of "Obsolete" status in the admin product list stock column
    add_filter('woocommerce_admin_stock_html', 'wc_sspaa_admin_stock_html_for_obsolete', 10, 2);
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_get_current_site_domain() {
    // Enhanced domain detection for cron context
    if (!empty($_SERVER['HTTP_HOST'])) {
        return strtolower(wp_unslash($_SERVER['HTTP_HOST']));
    }
    
    // Fallback to WordPress site URL
    $site_url = get_site_url();
    $parsed_url = parse_url($site_url);
    return strtolower($parsed_url['host'] ?? 'unknown');
}

function wc_sspaa_schedule_daily_sync()
{
    wc_sspaa_log('Attempting to schedule daily stock synchronisation.');

    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');

    $current_domain = wc_sspaa_get_current_site_domain();
    $sync_schedule = WC_SSPAA_DOMAIN_SYNC_SCHEDULE;
    $sync_time_sydney = $sync_schedule[$current_domain] ?? WC_SSPAA_DEFAULT_SYNC_TIME;

    wc_sspaa_log("Scheduling for domain: {$current_domain} at Sydney time: {$sync_time_sydney}");
    
    $sydney_timezone = new DateTimeZone('Australia/Sydney');
    $utc_timezone = new DateTimeZone('UTC');
    
    $today = new DateTime('now', $sydney_timezone);
    $today_date = $today->format('Y-m-d');

    $sydney_datetime = new DateTime($today_date . ' ' . $sync_time_sydney, $sydney_timezone);
    
    $now = new DateTime('now', $sydney_timezone);
    if ($sydney_datetime <= $now) {
        $sydney_datetime->add(new DateInterval('P1D'));
    }
    
    $sydney_datetime->setTimezone($utc_timezone);
    $utc_timestamp = $sydney_datetime->getTimestamp();
    
    // Store scheduling information for debugging
    $scheduling_info = array(
        'scheduled_utc_time' => $sydney_datetime->format('Y-m-d H:i:s'),
        'scheduled_sydney_time' => $sync_time_sydney,
        'scheduled_at' => current_time('mysql'),
        'domain' => $current_domain,
        'next_run_timestamp' => $utc_timestamp
    );
    update_option('wc_sspaa_last_scheduling_info', $scheduling_info);

    wc_sspaa_log('Scheduling daily sync trigger at Sydney time: ' . $sync_time_sydney . 
        ' (UTC time: ' . $sydney_datetime->format('Y-m-d H:i:s') . ') for domain: ' . $current_domain);
    
    $scheduled_result = wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_daily_stock_sync');
    
    if ($scheduled_result === false) {
        wc_sspaa_log("[SCHEDULING ERROR] Failed to schedule daily sync event for domain: {$current_domain}");
    } else {
        wc_sspaa_log("[SCHEDULING SUCCESS] Daily sync event scheduled successfully for domain: {$current_domain}");
        
        // Verify the event was scheduled
        $next_scheduled = wp_next_scheduled('wc_sspaa_daily_stock_sync');
        if ($next_scheduled) {
            wc_sspaa_log("[SCHEDULING VERIFICATION] Next scheduled sync verified at: " . date('Y-m-d H:i:s', $next_scheduled) . " UTC");
        } else {
            wc_sspaa_log("[SCHEDULING ERROR] Event scheduling verification failed - no next scheduled time found");
        }
    }
}

// Direct execution of scheduled sync - no more AJAX/nonce complexity
function wc_sspaa_execute_scheduled_sync()
{
    $current_domain = wc_sspaa_get_current_site_domain();
    $start_time = current_time('mysql');
    
    wc_sspaa_log("[CRON EXECUTION] Triggered for domain: {$current_domain} at {$start_time}. Evaluating sync lock state.");
    
    $lock_transient_key = 'wc_sspaa_sync_all_active_lock';
    $lock_timeout = 3 * HOUR_IN_SECONDS; // Current lock duration

    if (get_transient($lock_transient_key)) {
        // A lock exists and is considered active by WordPress (i.e., not expired)
        wc_sspaa_log("[CRON EXECUTION] Active sync lock ('{$lock_transient_key}') found. Sync operation is likely already in progress or recently failed and lock is still valid. Aborting current scheduled sync for domain: {$current_domain}. Lock duration is {$lock_timeout} seconds.");
        return; // Correctly aborts if a valid lock is present
    } else {
        wc_sspaa_log("[CRON EXECUTION] No active sync lock ('{$lock_transient_key}') found. Proceeding to acquire lock for domain: {$current_domain}.");
    }
    
    // Set lock to prevent concurrent syncs
    $set_lock_success = set_transient($lock_transient_key, true, $lock_timeout);
    if ($set_lock_success) {
        wc_sspaa_log("[CRON EXECUTION] Sync lock acquired successfully for domain: {$current_domain}. Expiration: {$lock_timeout} seconds.");
    } else {
        wc_sspaa_log("[CRON EXECUTION ERROR] Failed to acquire sync lock for domain: {$current_domain}. Aborting. This may indicate issues with transient storage.");
        return; // Cannot proceed if lock cannot be set
    }

    try {
        // Get product count
        global $wpdb;
        
        // Build exclusion clause for SKUs
        $excluded_skus_clause = '';
        if (defined('WC_SSPAA_EXCLUDED_SKUS') && !empty(WC_SSPAA_EXCLUDED_SKUS)) {
            $excluded_skus = array_map(array($wpdb, 'prepare'), array_fill(0, count(WC_SSPAA_EXCLUDED_SKUS), '%s'), WC_SSPAA_EXCLUDED_SKUS);
            $excluded_skus_list = implode(',', $excluded_skus);
            $excluded_skus_clause = " AND pm.meta_value NOT IN ({$excluded_skus_list})";
        }
        
        $total_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} exempt ON exempt.post_id = p.ID AND exempt.meta_key = '_wc_sspaa_obsolete_exempt'
            WHERE pm.meta_key = '_sku' 
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''
            AND (exempt.meta_id IS NULL OR exempt.meta_value = '' OR exempt.meta_value = 0)
            {$excluded_skus_clause}"
        );
        
        wc_sspaa_log("[CRON EXECUTION] Total products to sync (excluding obsolete exempt): {$total_products}");
        
        if ($total_products == 0) {
            wc_sspaa_log("[CRON EXECUTION] No products found to sync for domain: {$current_domain}. Releasing lock.");
            $delete_lock_on_no_products = delete_transient($lock_transient_key);
            if ($delete_lock_on_no_products) {
                wc_sspaa_log("[CRON EXECUTION] Sync lock released as no products to sync for domain: {$current_domain}.");
            } else {
                wc_sspaa_log("[CRON EXECUTION WARNING] Attempted to release sync lock (no products) for domain: {$current_domain}, but it was not found.");
            }
            return;
        }
        
        // Execute stock sync directly
        $api_handler = new WC_SSPAA_API_Handler();
        $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, WC_SSPAA_API_DELAY_MICROSECONDS, 0, 0, 0, 0, true); // Optimized delay
        
        wc_sspaa_log("[CRON EXECUTION] Beginning product synchronisation process...");
        $stock_updater->update_all_products();
        
        $end_time = current_time('mysql');
        wc_sspaa_log("[CRON EXECUTION] Completed scheduled stock sync for domain: {$current_domain}. Started: {$start_time}, Ended: {$end_time}");
        
        // Store completion time
        update_option('wc_sspaa_last_scheduled_sync_completion', array(
            'domain' => $current_domain,
            'completed_at' => $end_time,
            'products_synced' => $total_products // This might be off if update_all_products has internal skips
        ));
        
        // Clean up lock
        $delete_lock_success = delete_transient($lock_transient_key);
        if ($delete_lock_success) {
            wc_sspaa_log("[CRON EXECUTION] Sync lock released successfully after completion for domain: {$current_domain}");
        } else {
            wc_sspaa_log("[CRON EXECUTION WARNING] Attempted to release sync lock after completion for domain: {$current_domain}, but it was not found (possibly expired or cleared elsewhere).");
        }

    } catch (Exception $e) {
        wc_sspaa_log("[CRON EXECUTION ERROR] Exception during sync for domain {$current_domain}: " . $e->getMessage());
        wc_sspaa_log("[CRON EXECUTION ERROR] Stack trace: " . $e->getTraceAsString());
        
        $delete_lock_exception = delete_transient($lock_transient_key);
        if ($delete_lock_exception) {
            wc_sspaa_log("[CRON EXECUTION] Sync lock released after exception for domain: {$current_domain}");
        } else {
            wc_sspaa_log("[CRON EXECUTION WARNING] Attempted to release sync lock after exception for domain: {$current_domain}, but it was not found.");
        }
        
    } catch (Error $e) {
        wc_sspaa_log("[CRON EXECUTION FATAL] Fatal error during sync for domain {$current_domain}: " . $e->getMessage());
        wc_sspaa_log("[CRON EXECUTION FATAL] Stack trace: " . $e->getTraceAsString());
        
        $delete_lock_fatal = delete_transient($lock_transient_key);
        if ($delete_lock_fatal) {
            wc_sspaa_log("[CRON EXECUTION] Sync lock released after fatal error for domain: {$current_domain}");
        } else {
            wc_sspaa_log("[CRON EXECUTION WARNING] Attempted to release sync lock after fatal error for domain: {$current_domain}, but it was not found.");
        }
    }
}

// Manual trigger function for testing scheduled sync
function wc_sspaa_manual_trigger_scheduled_sync()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce'));
    }

    // Security check
    check_admin_referer('wc_sspaa_manual_trigger');

    $current_domain = wc_sspaa_get_current_site_domain();
    wc_sspaa_log("[MANUAL TRIGGER] Admin manually triggered scheduled sync test for domain: {$current_domain}");
    
    // Check if cron event is scheduled
    $next_scheduled = wp_next_scheduled('wc_sspaa_daily_stock_sync');
    if ($next_scheduled) {
        wc_sspaa_log("[MANUAL TRIGGER] Cron event is scheduled for: " . date('Y-m-d H:i:s', $next_scheduled) . " UTC");
    } else {
        wc_sspaa_log("[MANUAL TRIGGER] WARNING: No cron event found scheduled - attempting to reschedule");
        wc_sspaa_schedule_daily_sync();
    }
    
    // Execute the sync directly
    wc_sspaa_log("[MANUAL TRIGGER] Executing scheduled sync directly...");
    wc_sspaa_execute_scheduled_sync();
    
    // Redirect back with success message
    $redirect_url = admin_url('edit.php?post_type=product&manual_sync_triggered=1');
    wp_safe_redirect($redirect_url);
    exit;
}

// Handle manual AJAX sync requests from admin interface
function wc_sspaa_handle_manual_sync()
{
    // This is only for manual triggers from admin, not for cron
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied', 403);
        return;
    }
    
    // For manual triggers, we can verify the nonce properly
    if (!check_ajax_referer('wc_sspaa_manual_sync_nonce', '_ajax_nonce', false)) {
        wp_send_json_error('Security check failed', 403);
        return;
    }
    
    $current_domain = wc_sspaa_get_current_site_domain();
    wc_sspaa_log("[MANUAL AJAX SYNC] Starting manual sync for domain: {$current_domain}");
    
    // Execute the same sync function
    wc_sspaa_execute_scheduled_sync();
    
    wp_send_json_success('Manual sync completed for ' . $current_domain);
}

function wc_sspaa_handle_clear_obsolete_exemption() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce'));
    }

    $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
    
    if (empty($product_id)) {
        wp_die(__('No product ID provided.', 'woocommerce'));
    }

    check_admin_referer('wc_sspaa_clear_obsolete_exempt_' . $product_id);

    $obsolete_meta_existed = delete_post_meta($product_id, '_wc_sspaa_obsolete_exempt');
    $product = wc_get_product($product_id);

    if ($product) {
        // If the product was 'obsolete', set it to 'outofstock' after clearing exemption.
        // The next sync will determine the correct status.
        if ($product->get_stock_status() === 'obsolete') {
            $product->set_stock_status('outofstock');
            $product->save();
            wc_sspaa_log("Admin action: Product ID {$product_id} stock status changed from 'obsolete' to 'outofstock' after clearing exemption.");
        }
    }

    if ($obsolete_meta_existed) {
        add_action('admin_notices', function() use ($product_id) {
            $product = wc_get_product($product_id);
            $product_name = $product ? $product->get_formatted_name() : 'Product ID: ' . $product_id;
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                sprintf(__('Obsolete exemption cleared for %s. It will be included in the next sync cycle and status set to "Out of Stock" if previously "Obsolete".', 'woocommerce'), esc_html($product_name)) . 
            '</p></div>';
        });
        wc_sspaa_log("Admin action: Obsolete exemption cleared for product ID: {$product_id} by user ID: " . get_current_user_id());
    } else {
        add_action('admin_notices', function() use ($product_id) {
            $product = wc_get_product($product_id);
            $product_name = $product ? $product->get_formatted_name() : 'Product ID: ' . $product_id;
            echo '<div class="notice notice-warning is-dismissible"><p>' . 
                sprintf(__('Obsolete exemption was not found or already cleared for %s.', 'woocommerce'), esc_html($product_name)) . 
            '</p></div>';
        });
        wc_sspaa_log("Admin action: Attempted to clear Obsolete exemption for product ID: {$product_id}, but no exemption was found. User ID: " . get_current_user_id());
    }

    $redirect_url = admin_url('edit.php?post_type=product');
    if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'product_edit') {
        $redirect_url = admin_url('post.php?post=' . $product_id . '&action=edit');
    }
    wp_safe_redirect($redirect_url);
    exit;
}

// Function to handle manual GTIN sync
function wc_sspaa_handle_manual_gtin_sync() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce'));
    }

    // Security check
    check_admin_referer('wc_sspaa_manual_gtin_sync');

    wc_sspaa_log("[MANUAL GTIN SYNC] Admin manually triggered GTIN sync");
    
    try {
        // Create API handler and GTIN updater
        $api_handler = new WC_SSPAA_API_Handler();
        $gtin_updater = new WC_SSPAA_GTIN_Updater($api_handler, true);
        
        // Perform the GTIN sync
        $gtin_updater->update_missing_gtins();
        
        // Get completion statistics
        $completion_data = get_option('wc_sspaa_last_gtin_sync_completion', array());
        
        wc_sspaa_log("[MANUAL GTIN SYNC] Completed manual GTIN sync");
        
        add_action('admin_notices', function() use ($completion_data) {
            $message = 'GTIN sync completed successfully.';
            if (!empty($completion_data)) {
                $message = sprintf(
                    'GTIN sync completed. Processed: %d, Successful updates: %d, Failed: %d, Skipped (no APN): %d',
                    $completion_data['processed'] ?? 0,
                    $completion_data['successful'] ?? 0,
                    $completion_data['failed'] ?? 0,
                    $completion_data['skipped_no_apn'] ?? 0
                );
            }
            echo '<div class="notice notice-success is-dismissible"><p><strong>Manual GTIN Sync:</strong> ' . esc_html($message) . '</p></div>';
        });
        
    } catch (Exception $e) {
        wc_sspaa_log("[MANUAL GTIN SYNC ERROR] Exception during manual GTIN sync: " . $e->getMessage());
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>GTIN Sync Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
    
    // Redirect back with success message
    $redirect_url = admin_url('edit.php?post_type=product&manual_gtin_sync_triggered=1');
    wp_safe_redirect($redirect_url);
    exit;
}

// Function to handle clearing the sync lock
function wc_sspaa_handle_clear_sync_lock() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce'));
    }

    // Verify nonce
    check_admin_referer('wc_sspaa_clear_lock_nonce');

    $lock_transient_key = 'wc_sspaa_sync_all_active_lock';
    $lock_cleared = delete_transient($lock_transient_key);

    if ($lock_cleared) {
        wc_sspaa_log("Admin action: Active sync lock ('{$lock_transient_key}') was cleared by user ID: " . get_current_user_id());
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                __('Active sync lock has been cleared. You can now start a new sync process.', 'woocommerce') . 
            '</p></div>';
        });
    } else {
        wc_sspaa_log("Admin action: Attempted to clear active sync lock ('{$lock_transient_key}') but it was not found (or already cleared). User ID: " . get_current_user_id());
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p>' . 
                __('Active sync lock was not found or already cleared.', 'woocommerce') . 
            '</p></div>';
        });
    }

    // Redirect back to the products page
    wp_safe_redirect(admin_url('edit.php?post_type=product'));
    exit;
}

// Register "Obsolete" stock status with WooCommerce
function wc_sspaa_add_obsolete_stock_status_options($statuses) {
    $statuses['obsolete'] = _x('Obsolete', 'Product stock status', 'woocommerce');
    return $statuses;
}

// Ensure "Obsolete" products are treated as out of stock
function wc_sspaa_product_is_in_stock_for_obsolete($is_in_stock, $product) {
    if (is_object($product) && $product->get_stock_status() === 'obsolete') {
        return false;
    }
    return $is_in_stock;
}

// Customize the display of "Obsolete" status in the admin product list stock column
function wc_sspaa_admin_stock_html_for_obsolete($html, $product) {
    if (is_object($product) && $product->get_stock_status() === 'obsolete') {
        $html = '<mark class="outofstock">' . esc_html__('Obsolete', 'woocommerce') . '</mark>'; // Use 'outofstock' class for styling
        if ($product->managing_stock()) {
            $html .= wc_help_tip(__('Stock quantity', 'woocommerce'));
            $html .= ' (' . wc_stock_amount($product->get_stock_quantity()) . ')';
        }
    }
    return $html;
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('Deactivating plugin and clearing scheduled events.');
    
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    delete_option('wc_sspaa_sync_time'); // This option is no longer used for setting time.
    
    // Clear any remaining nonces for all potential domains if plugin is deactivated
    $defined_domains = array_keys(WC_SSPAA_DOMAIN_SYNC_SCHEDULE);
    // Also add the current domain in case it's using default time and not in the list.
    $defined_domains[] = wc_sspaa_get_current_site_domain(); 
    $defined_domains = array_unique($defined_domains);

    foreach($defined_domains as $domain) {
        delete_transient('wc_sspaa_cron_nonce_val_' . $domain);
        delete_transient('wc_sspaa_cron_nonce_action_' . $domain);
        delete_transient('wc_sspaa_verification_token_' . $domain);
    }
    
    wc_sspaa_log('All scheduled events and cron nonces cleared successfully.');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

/**
 * Get information about log files
 *
 * @return array Log file information
 */
function wc_sspaa_get_log_info()
{
    $logs_dir = plugin_dir_path(__FILE__) . 'logs';
    $current_date = date("Y-m-d");
    $current_log_file = $logs_dir . '/wc-sspaa-' . $current_date . '.log';
    
    $log_files = array();
    $total_size = 0;
    $file_count = 0;
    
    if (is_dir($logs_dir)) {
        $files = glob($logs_dir . '/wc-sspaa-*.log');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $file_size = filesize($file);
                    $file_date = basename($file, '.log');
                    $file_date = str_replace('wc-sspaa-', '', $file_date);
                    
                    $log_files[] = array(
                        'filename' => basename($file),
                        'date' => $file_date,
                        'size' => $file_size,
                        'size_formatted' => size_format($file_size, 2),
                        'is_current' => ($file === $current_log_file)
                    );
                    
                    $total_size += $file_size;
                    $file_count++;
                }
            }
        }
    }
    
    // Sort by date (newest first)
    usort($log_files, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    
    return array(
        'logs_directory' => $logs_dir,
        'current_log_file' => $current_log_file,
        'current_date' => $current_date,
        'total_files' => $file_count,
        'total_size' => $total_size,
        'total_size_formatted' => size_format($total_size, 2),
        'log_files' => $log_files
    );
}

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    // Rate limiting: prevent excessive logging (max 10 logs per second)
    static $last_log_time = 0;
    static $log_count = 0;
    
    $current_time = microtime(true);
    if ($current_time - $last_log_time < 1.0) {
        $log_count++;
        if ($log_count > 10) {
            return; // Skip logging if too many logs in 1 second
        }
    } else {
        $last_log_time = $current_time;
        $log_count = 1;
    }
    
    $timestamp = date("Y-m-d H:i:s");
    
    // Create logs directory if it doesn't exist
    $logs_dir = plugin_dir_path(__FILE__) . 'logs';
    if (!file_exists($logs_dir)) {
        wp_mkdir_p($logs_dir);
        
        // Create .htaccess to protect logs directory
        $htaccess_content = "Order deny,allow\nDeny from all";
        file_put_contents($logs_dir . '/.htaccess', $htaccess_content);
        
        // Create index.php to prevent directory listing
        $index_content = "<?php\n// Silence is golden.";
        file_put_contents($logs_dir . '/index.php', $index_content);
    }
    
    // Create daily log file
    $current_date = date("Y-m-d");
    $log_file = $logs_dir . '/wc-sspaa-' . $current_date . '.log';
    
    $new_log_entry = "[$timestamp] $message\n";

    if (!file_exists($log_file)) {
        if (touch($log_file)) {
            chmod($log_file, 0644); 
        }
    }

    if (is_writable($log_file) || is_writable($logs_dir)) {
        file_put_contents($log_file, $new_log_entry, FILE_APPEND | LOCK_EX);
    }

    // Clean up old log files only occasionally (1 in 100 calls) to prevent performance issues
    static $cleanup_counter = 0;
    $cleanup_counter++;
    
    if ($cleanup_counter % 100 === 0) {
        wc_sspaa_cleanup_old_logs($logs_dir);
    }
}

/**
 * Clean up old log files, keeping only the last 14 days
 *
 * @param string $logs_dir Path to logs directory
 */
function wc_sspaa_cleanup_old_logs($logs_dir)
{
    if (!is_dir($logs_dir) || !is_readable($logs_dir)) {
        return;
    }
    
    $max_age_days = 14;
    $max_age_seconds = $max_age_days * 86400;
    $now = time();
    $files_removed = 0;
    
    // Get all log files in the directory
    $log_files = glob($logs_dir . '/wc-sspaa-*.log');
    
    if (empty($log_files)) {
        return;
    }
    
    foreach ($log_files as $log_file) {
        if (!is_file($log_file)) {
            continue;
        }
        
        // Extract date from filename (wc-sspaa-2024-01-15.log)
        if (preg_match('/wc-sspaa-(\d{4}-\d{2}-\d{2})\.log$/', $log_file, $matches)) {
            $file_date = $matches[1];
            $file_timestamp = strtotime($file_date);
            
            if ($file_timestamp !== false && ($now - $file_timestamp) > $max_age_seconds) {
                // File is older than 14 days, remove it
                if (unlink($log_file)) {
                    $files_removed++;
                }
            }
        }
    }
    
    // Log cleanup operation (but only if we have a current log file)
    if ($files_removed > 0) {
        $current_date = date("Y-m-d");
        $current_log_file = $logs_dir . '/wc-sspaa-' . $current_date . '.log';
        
        if (file_exists($current_log_file) && is_writable($current_log_file)) {
            $cleanup_message = "[" . date("Y-m-d H:i:s") . "] [LOG CLEANUP] Removed {$files_removed} old log files older than {$max_age_days} days\n";
            file_put_contents($current_log_file, $cleanup_message, FILE_APPEND | LOCK_EX);
        }
    }
}
?>