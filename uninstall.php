<?php
/**
 * Uninstall file
 * Called when plugin is uninstalled
 *
 * Tasks:
 * 1. Reschedules paused events
 * 2. Removes wcap_* options from wp_options
 *
 * @package woocommerce-stock-sync-api
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Reschedule paused events and remove options
 */
function wcap_uninstall_plugin()
{
    // 1. Reschedule paused events
    $paused_events = get_option('wcap_paused_events', array());

    foreach ($paused_events as $event) {
        if (!empty($event['hook'])) {
            wp_schedule_event(time(), $event['schedule'], $event['hook'], $event['args']);
        }
    }

    // 2. Remove wcap_* options from wp_options
    delete_option('wcap_paused_events');
    delete_option('wcap_batch_offset');
    delete_option('wcap_cron_frequency');
}

// Execute the uninstall function
wcap_uninstall_plugin();
?>