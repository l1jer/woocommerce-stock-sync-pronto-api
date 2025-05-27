<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with sequential processing.
Version: 1.3.22
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

// Define domain-specific sync times (Sydney time, 24-hour format)
define('WC_SSPAA_DOMAIN_SYNC_SCHEDULE', array(
    'store.zerotechoptics.com' => '00:25:00',
    'skywatcheraustralia.com.au' => '00:55:00',
    'zerotech.com.au' => '01:25:00',
    'zerotechoutdoors.com.au' => '01:55:00',
    'nitecoreaustralia.com.au' => '02:25:00'
));
define('WC_SSPAA_DEFAULT_SYNC_TIME', '03:00:00'); // Default for other domains

function wc_sspaa_activate()
{
    wc_sspaa_schedule_daily_sync(); // Schedule daily sync during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    // This init is for general plugin setup, not for direct cron processing anymore.
    // The stock_updater instance for cron will be created in the AJAX handler.
    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_trigger_scheduled_sync_handler'); // Cron hook
    add_action('wp_ajax_wc_sspaa_run_scheduled_sync', 'wc_sspaa_handle_scheduled_sync'); // AJAX handler for logged-in users (cron might be either)
    add_action('wp_ajax_nopriv_wc_sspaa_run_scheduled_sync', 'wc_sspaa_handle_scheduled_sync'); // AJAX handler for non-logged-in users (cron might be either)

    // Action to manually clear Obsolete exemption for a product
    add_action('admin_action_wc_sspaa_clear_obsolete_exemption', 'wc_sspaa_handle_clear_obsolete_exemption');
}
add_action('plugins_loaded', 'wc_sspaa_init');

function wc_sspaa_get_current_site_domain() {
    return strtolower(wp_unslash($_SERVER['HTTP_HOST'] ?? parse_url(get_site_url(), PHP_URL_HOST)));
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
    
    // Generate a nonce for the self-trigger
    $nonce_action = 'wc_sspaa_cron_trigger_' . $current_domain;
    $nonce = wp_create_nonce($nonce_action);
    // Store the nonce action as well, as wp_verify_nonce needs it.
    set_transient('wc_sspaa_cron_nonce_val_' . $current_domain, $nonce, HOUR_IN_SECONDS); // Valid for 1 hour
    set_transient('wc_sspaa_cron_nonce_action_' . $current_domain, $nonce_action, HOUR_IN_SECONDS);


    wc_sspaa_log('Scheduling daily sync trigger at Sydney time: ' . $sync_time_sydney . 
        ' (UTC time: ' . $sydney_datetime->format('Y-m-d H:i:s') . ') for domain: ' . $current_domain);
    
    wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_daily_stock_sync');
}

// This function is triggered by the WP Cron event
function wc_sspaa_trigger_scheduled_sync_handler()
{
    $current_domain = wc_sspaa_get_current_site_domain();
    wc_sspaa_log("[CRON Triggered] for domain {$current_domain}: Initiating self-request to start stock synchronisation.");

    $nonce_val = get_transient('wc_sspaa_cron_nonce_val_' . $current_domain);
    $nonce_action = get_transient('wc_sspaa_cron_nonce_action_' . $current_domain);

    if (!$nonce_val || !$nonce_action) {
        wc_sspaa_log("[CRON Triggered] for domain {$current_domain}: Nonce not found or expired. Cannot trigger sync.");
        return;
    }

    $ajax_url = admin_url('admin-ajax.php');
    $request_args = array(
        'method'      => 'POST',
        'timeout'     => 0.01, // Make it non-blocking
        'blocking'    => false, // Make it non-blocking
        'sslverify'   => apply_filters('https_local_ssl_verify', false),
        'body'        => array(
            'action'    => 'wc_sspaa_run_scheduled_sync',
            '_ajax_nonce' => $nonce_val, // Pass the nonce value
            'nonce_action_key' => $nonce_action, // Pass the original action string for verification
            'domain_check' => $current_domain // For logging/verification in handler
        ),
    );

    $response = wp_remote_post($ajax_url, $request_args);

    if (is_wp_error($response)) {
        wc_sspaa_log("[CRON Triggered] for domain {$current_domain}: Failed to make self-request. Error: " . $response->get_error_message());
    } else {
        wc_sspaa_log("[CRON Triggered] for domain {$current_domain}: Self-request initiated successfully.");
    }
}

// This function handles the self-request triggered by the cron job
function wc_sspaa_handle_scheduled_sync()
{
    $nonce_val = $_POST['_ajax_nonce'] ?? '';
    $nonce_action_key = $_POST['nonce_action_key'] ?? '';
    $triggered_domain = $_POST['domain_check'] ?? 'unknown';

    wc_sspaa_log("[Scheduled Sync Handler] Received trigger for domain: {$triggered_domain}. Verifying nonce action: {$nonce_action_key}");

    // Verify nonce
    // Note: We stored $nonce_action in transient, which is what wp_verify_nonce expects as its second param.
    if (!wp_verify_nonce($nonce_val, $nonce_action_key)) {
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Nonce verification failed. Aborting. Received nonce value: {$nonce_val}, Expected action: {$nonce_action_key}");
        wp_send_json_error('Nonce verification failed.', 403);
        return;
    }
    
    // Nonce is valid, clear it to prevent reuse
    $current_domain_for_transient = wc_sspaa_get_current_site_domain(); // Use current domain context for deleting transient
    delete_transient('wc_sspaa_cron_nonce_val_' . $current_domain_for_transient);
    delete_transient('wc_sspaa_cron_nonce_action_' . $current_domain_for_transient);

    wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Nonce verified. Starting stock synchronisation process.");
    
    // Check if another sync is already running (using the main lock key)
    $lock_transient_key = 'wc_sspaa_sync_all_active_lock'; // Use the same lock as manual "Sync All"
    $lock_timeout = 3 * HOUR_IN_SECONDS; // Allow up to 3 hours for a full sync

    if (get_transient($lock_transient_key)) {
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Another sync operation (manual or scheduled) is currently locked. Aborting this scheduled run.");
        wp_send_json_error('Another sync operation is in progress.', 429);
        return;
    }
    set_transient($lock_transient_key, true, $lock_timeout);

    try {
        global $wpdb;
        $total_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' 
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''"
        );
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Total products with SKUs to sync: {$total_products}. Using 3-second delay.");
        
        $api_handler = new WC_SSPAA_API_Handler();
        // Use 3-second delay (3,000,000 microseconds) for scheduled sync as per task 1.3.20
        $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 3000000, 0, 0, 0, 0, true); 
        
        $stock_updater->update_all_products();
        
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Completed stock synchronisation.");
        update_option('wc_sspaa_last_scheduled_sync_completion_time_' . $triggered_domain, current_time('mysql'));
        wp_send_json_success('Scheduled stock synchronisation completed for ' . $triggered_domain);

    } catch (Exception $e) {
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: ERROR during stock synchronisation - " . $e->getMessage());
        wp_send_json_error('Error during scheduled sync: ' . $e->getMessage(), 500);
    } finally {
        // Release lock
        delete_transient($lock_transient_key);
        wc_sspaa_log("[Scheduled Sync Handler] for domain {$triggered_domain}: Released sync lock.");
    }
    wp_die(); // this is required to terminate immediately and return a proper response
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

    if ($obsolete_meta_existed) {
        add_action('admin_notices', function() use ($product_id) {
            $product = wc_get_product($product_id);
            $product_name = $product ? $product->get_formatted_name() : 'Product ID: ' . $product_id;
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                sprintf(__('Obsolete exemption cleared for %s. It will be included in the next sync cycle.', 'woocommerce'), esc_html($product_name)) . 
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
    }
    
    wc_sspaa_log('All scheduled events and cron nonces cleared successfully.');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    $timestamp = date("Y-m-d H:i:s");
    $log_file = plugin_dir_path(__FILE__) . 'wc-sspaa-debug.log'; // Dedicated log file
    $new_log_entry = "[$timestamp] $message\n";

    if (!file_exists($log_file)) {
        if (touch($log_file)) {
            chmod($log_file, 0644); 
        }
    }

    if (is_writable($log_file) || is_writable(dirname($log_file))) {
        file_put_contents($log_file, $new_log_entry, FILE_APPEND | LOCK_EX);
    }

    $should_cleanup = (rand(1, 50) === 1) || (file_exists($log_file) && filesize($log_file) > 5242880); 
    
    if ($should_cleanup && file_exists($log_file) && is_readable($log_file) && is_writable($log_file)) {
    $max_age_days = 7;
    $max_age_seconds = $max_age_days * 86400;
    $now = time();

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $retained_lines = [];
        $cleanup_needed = false;
        
        if ($lines !== false) {
        foreach ($lines as $line) {
            if (preg_match('/^\\[(\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})\\]/', $line, $matches)) {
                $entry_time = strtotime($matches[1]);
                if ($entry_time !== false && ($now - $entry_time) <= $max_age_seconds) {
                    $retained_lines[] = $line;
                    } else {
                        $cleanup_needed = true; 
                }
            } else {
                $retained_lines[] = $line;
            }
        }
            
            if ($cleanup_needed && count($retained_lines) < count($lines)) {
        file_put_contents($log_file, implode("\n", $retained_lines) . "\n", LOCK_EX);
                $cleanup_message = "[$timestamp] Log cleanup completed: removed " . (count($lines) - count($retained_lines)) . " old entries\n";
                file_put_contents($log_file, $cleanup_message, FILE_APPEND | LOCK_EX);
            }
        }
    }
}
?>