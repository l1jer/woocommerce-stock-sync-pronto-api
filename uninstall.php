<?php
/**
 * Uninstall file
 * Called when plugin is uninstalled
 *
 * Tasks:
 * 1. Reschedules paused events
 * 2. Removes wc_sspaa_* options from wp_options
 * 3. Removes transients
 *
 * @package woocommerce-stock-sync-api
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Define log function if doesn't exist
if (!function_exists('wc_sspaa_log')) {
    function wc_sspaa_log($message, $force = true) {
        $timestamp = date("Y-m-d H:i:s");
        $log_entry = "[$timestamp] [UNINSTALL] $message\n";
        error_log($log_entry, 3, dirname(__FILE__) . '/debug.log');
    }
}

/**
 * Reschedule paused events and remove options
 */
function wc_sspaa_uninstall_plugin()
{
    wc_sspaa_log('Starting uninstall process');
    
    // 1. Reschedule paused events
    $paused_events = get_option('wc_sspaa_paused_events', array());

    foreach ($paused_events as $event) {
        if (!empty($event['hook'])) {
            wc_sspaa_log('Rescheduling paused event: ' . $event['hook']);
            wp_schedule_event(time(), $event['schedule'], $event['hook'], $event['args']);
        }
    }

    // Clear all scheduled batch events
    $cron = _get_cron_array();
    if (!empty($cron)) {
        $cleared_count = 0;
        foreach ($cron as $timestamp => $hooks) {
            if (isset($hooks['wc_sspaa_update_stock_batch'])) {
                foreach ($hooks['wc_sspaa_update_stock_batch'] as $key => $event) {
                    if (isset($event['args'][0])) {
                        $offset = $event['args'][0];
                        wc_sspaa_log('Clearing scheduled batch with offset: ' . $offset);
                        wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($offset));
                        $cleared_count++;
                    }
                }
            }
        }
        wc_sspaa_log('Cleared ' . $cleared_count . ' scheduled batch events');
    }

    // 2. Remove wc_sspaa_* options from wp_options
    wc_sspaa_log('Removing plugin options');
    delete_option('wc_sspaa_paused_events');
    delete_option('wc_sspaa_batch_offset');
    delete_option('wc_sspaa_cron_frequency');
    delete_option('wc_sspaa_start_time');
    delete_option('wc_sspaa_email_recipient');
    
    // 3. Remove transients
    wc_sspaa_log('Removing plugin transients');
    delete_transient('wc_sspaa_completed_batches');
    delete_transient('wc_sspaa_current_sync_batch_count');
    delete_transient('wc_sspaa_current_sync_total_products');
    delete_transient('wc_sspaa_current_sync_start_time');
    delete_transient('wc_sspaa_email_stats');
    
    // 4. Remove any saved email content files
    $files_to_remove = array(
        dirname(__FILE__) . '/email_content.html',
    );
    
    // Look for any report files
    $report_files = glob(dirname(__FILE__) . '/stock_sync_report_*.html');
    if ($report_files) {
        $files_to_remove = array_merge($files_to_remove, $report_files);
    }
    
    foreach ($files_to_remove as $file) {
        if (file_exists($file)) {
            wc_sspaa_log('Removing file: ' . $file);
            unlink($file);
        }
    }
    
    wc_sspaa_log('Uninstall process completed');
}

// Execute the uninstall function
wc_sspaa_uninstall_plugin();
?>