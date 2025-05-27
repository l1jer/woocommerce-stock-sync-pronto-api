<?php
/**
 * Uninstall file
 * Called when plugin is uninstalled
 *
 * Tasks:
 * 1. Reschedules paused events
 * 2. Removes wc_sspaa_* options from wp_options
 *
 * @package woocommerce-stock-sync-api
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Reschedule paused events and remove options
 */
function wc_sspaa_uninstall_plugin()
{
    // 1. Reschedule paused events
    $paused_events = get_option('wc_sspaa_paused_events', array());

    foreach ($paused_events as $event) {
        if (!empty($event['hook'])) {
            wp_schedule_event(time(), $event['schedule'], $event['hook'], $event['args']);
        }
    }

    // 2. Remove wc_sspaa_* options from wp_options
    delete_option('wc_sspaa_paused_events');
    delete_option('wc_sspaa_batch_offset');
    delete_option('wc_sspaa_cron_frequency');
    delete_option('wc_sspaa_sync_time');
}

// Execute the uninstall function
wc_sspaa_uninstall_plugin();
?>