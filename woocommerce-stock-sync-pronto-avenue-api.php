<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.8
Author: Jerry Li
Requires at least: 3.6
Requires PHP: 5.3
Tested up to: 6.7.2
WC requires at least: 3.0
WC tested up to: 9.6
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/config.php'; // Include the config file for credentials
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php'; // Include the API handler class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-updater.php'; // Include the stock updater class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-time-col.php'; // Include the stock sync time column class

function wc_sspaa_activate()
{
    wc_sspaa_log('Plugin activated');
    
    // Always clear any existing schedule to ensure a fresh start
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    
    // Create a dummy product if no products exist (for testing)
    global $wpdb;
    $count_query = "SELECT COUNT(post_id) FROM {$wpdb->postmeta}
        LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
        WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')";
    $product_count = $wpdb->get_var($count_query);
    
    wc_sspaa_log('Found ' . $product_count . ' products with SKUs on activation');
    
    // Schedule daily sync
    wc_sspaa_schedule_daily_sync();
    
    // Initialize options if they don't exist
    if (!get_option('wc_sspaa_last_successful_run')) {
        update_option('wc_sspaa_last_successful_run', 0);
    }
    
    // Check for real cron system
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        wc_sspaa_log('WARNING: WP-Cron is disabled. Please ensure a system cron job is set up to trigger WordPress cron events.');
    }
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false

    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_run_sync'); // Hook the daily sync function
    
    // Add AJAX handler for individual product sync
    add_action('wp_ajax_wc_sspaa_sync_single_product', 'wc_sspaa_handle_single_product_sync');
    add_action('wp_ajax_wc_sspaa_bulk_sync', 'wc_sspaa_handle_bulk_sync');
    
    // Add product edit page sync button
    add_action('woocommerce_product_options_inventory_product_data', 'wc_sspaa_add_product_sync_button');
    add_action('admin_footer', 'wc_sspaa_add_product_sync_js');
    
    // Add manual sync button to products page
    add_action('restrict_manage_posts', 'wc_sspaa_add_manual_sync_button', 50);
    add_action('admin_footer-edit.php', 'wc_sspaa_add_manual_sync_js');
    
    // Add admin notices for cron issues
    add_action('admin_init', 'wc_sspaa_check_wp_cron');
    add_action('admin_notices', 'wc_sspaa_display_cron_notices');
    
    // Handle force reschedule request
    if (isset($_GET['wc_sspaa_force_reschedule']) && $_GET['wc_sspaa_force_reschedule'] == 1) {
        add_action('admin_init', 'wc_sspaa_handle_force_reschedule');
    }
    
    // Handle real cron acknowledgment
    if (isset($_GET['wc_sspaa_acknowledge_real_cron']) && $_GET['wc_sspaa_acknowledge_real_cron'] == 1) {
        add_action('admin_init', 'wc_sspaa_handle_real_cron_acknowledgment');
    }
    
    // Show admin notices
    if (isset($_GET['rescheduled']) && $_GET['rescheduled'] == 1) {
        add_action('admin_notices', 'wc_sspaa_show_reschedule_notice');
    }
    
    if (isset($_GET['cron_acknowledged']) && $_GET['cron_acknowledged'] == 1) {
        add_action('admin_notices', 'wc_sspaa_show_cron_acknowledgment_notice');
    }
}
add_action('plugins_loaded', 'wc_sspaa_init');

// Schedule daily sync at 1AM except on weekends
function wc_sspaa_schedule_daily_sync()
{
    wc_sspaa_log('Attempting to schedule daily sync at 1AM except weekends.');
    
    // Clear any existing schedule
    $existing_schedules = _get_cron_array();
    $timestamp = wp_next_scheduled('wc_sspaa_daily_stock_sync');
    
    if ($timestamp) {
        wc_sspaa_log('Found existing schedule at: ' . date('Y-m-d H:i:s', $timestamp) . ' - clearing it');
        wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    } else {
        wc_sspaa_log('No existing schedule found.');
    }
    
    // Schedule new daily sync at 1AM
    $next_run = wc_sspaa_get_next_run_time();
    if ($next_run) {
        $success = wp_schedule_event($next_run, 'daily', 'wc_sspaa_daily_stock_sync');
        
        if ($success !== false) {
            wc_sspaa_log('Daily sync scheduled successfully for: ' . date('Y-m-d H:i:s', $next_run) . ' (site timezone)');
            wc_sspaa_log('Current UTC time: ' . date('Y-m-d H:i:s', time()) . ' - site timezone time: ' . date('Y-m-d H:i:s', current_time('timestamp')));
            
            // Verify the schedule was created
            $new_timestamp = wp_next_scheduled('wc_sspaa_daily_stock_sync');
            if ($new_timestamp) {
                wc_sspaa_log('Verified schedule: Next run at ' . date('Y-m-d H:i:s', $new_timestamp));
            } else {
                wc_sspaa_log('WARNING: Could not verify schedule was created.');
            }
        } else {
            wc_sspaa_log('ERROR: Failed to schedule event. Check WordPress cron system.');
        }
    } else {
        wc_sspaa_log('ERROR: Failed to calculate next run time.');
    }
}

// Get the next valid run time (1AM on non-weekend day)
function wc_sspaa_get_next_run_time()
{
    // Use WordPress site timezone for scheduling
    $timezone_string = get_option('timezone_string');
    $gmt_offset = get_option('gmt_offset');
    
    if (!empty($timezone_string)) {
        wc_sspaa_log('Site timezone set to: ' . $timezone_string);
    } elseif ($gmt_offset) {
        wc_sspaa_log('Site using GMT offset: ' . $gmt_offset);
    } else {
        wc_sspaa_log('WARNING: No timezone settings found. Using server default.');
    }
    
    // Get timestamp for 1AM tomorrow in site's timezone
    $site_time = current_time('timestamp');
    $current_hour = (int)date('G', $site_time);
    $current_date = date('Y-m-d', $site_time);
    
    // If it's before 1AM, schedule for today at 1AM
    if ($current_hour < 1) {
        $timestamp = strtotime($current_date . ' 01:00:00');
    } else {
        // Otherwise schedule for tomorrow at 1AM
        $timestamp = strtotime($current_date . ' 01:00:00 + 1 day');
    }
    
    $day_of_week = (int)date('N', $timestamp);
    wc_sspaa_log('Initial next run timestamp: ' . date('Y-m-d H:i:s', $timestamp) . ' (Day: ' . $day_of_week . ')');
    
    // If it's Saturday (6) or Sunday (7), adjust to Monday
    if ($day_of_week >= 6) {
        // Calculate days to add (to reach Monday)
        $days_to_add = $day_of_week == 6 ? 2 : 1;
        $timestamp = strtotime("+{$days_to_add} days", $timestamp);
        wc_sspaa_log('Adjusted for weekend - new timestamp: ' . date('Y-m-d H:i:s', $timestamp));
    }
    
    return $timestamp;
}

// Run the sync process with dynamic batch calculation
function wc_sspaa_run_sync()
{
    global $wpdb;
    
    wc_sspaa_log('Starting daily stock sync');
    
    // Record start time
    $start_time = current_time('timestamp');
    
    try {
        // Count total products with SKUs
        $count_query = "SELECT COUNT(post_id) FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')";
        
        $total_products = $wpdb->get_var($count_query);
        wc_sspaa_log("Total products to sync: {$total_products}");
        
        if ($total_products == 0) {
            wc_sspaa_log("No products found to sync.");
            
            // Even with no products, record successful run
            update_option('wc_sspaa_last_successful_run', current_time('timestamp'));
            update_option('wc_sspaa_last_run_stats', array(
                'start_time' => $start_time,
                'end_time' => current_time('timestamp'),
                'products_found' => 0,
                'products_updated' => 0
            ));
            
            // Schedule the next sync
            wc_sspaa_schedule_daily_sync();
            return;
        }
        
        // Calculate batches (15 products per batch)
        $batch_size = 15;
        $num_batches = ceil($total_products / $batch_size);
        
        $api_handler = new WC_SSPAA_API_Handler();
        $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false);
        
        $updated_count = 0;
        
        // Process each batch
        for ($i = 0; $i < $num_batches; $i++) {
            $offset = $i * $batch_size;
            wc_sspaa_log("Processing batch " . ($i+1) . "/{$num_batches} with offset {$offset}");
            $batch_updated = $stock_updater->update_stock($offset);
            $updated_count += $batch_updated;
            
            // Small pause between batches to prevent server overload
            if ($i < $num_batches - 1) {
                sleep(1);
            }
        }
        
        // Record successful run
        $end_time = current_time('timestamp');
        $duration = $end_time - $start_time;
        wc_sspaa_log("Daily stock sync completed successfully. Updated {$updated_count}/{$total_products} products in {$duration} seconds.");
        
        update_option('wc_sspaa_last_successful_run', $end_time);
        update_option('wc_sspaa_last_run_stats', array(
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration' => $duration,
            'products_found' => $total_products,
            'products_updated' => $updated_count
        ));
        
        // Schedule the next sync
        wc_sspaa_schedule_daily_sync();
        
    } catch (Exception $e) {
        wc_sspaa_log("ERROR during daily sync: " . $e->getMessage());
        
        // Record failed run
        update_option('wc_sspaa_last_failed_run', current_time('timestamp'));
        update_option('wc_sspaa_last_error', $e->getMessage());
        
        // Still try to schedule next run
        wc_sspaa_schedule_daily_sync();
    }
}

// Handle single product sync AJAX request
function wc_sspaa_handle_single_product_sync()
{
    // Verify nonce
    check_ajax_referer('wc_sspaa_sync_product_nonce', 'security');
    
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Get product ID
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product ID.']);
        return;
    }
    
    // Enable debug for manual syncs
    add_filter('wc_sspaa_enable_debug', '__return_true');
    
    wc_sspaa_log("======= Manual sync triggered for product ID: {$product_id} =======");
    
    try {
        // Get product SKU
        $sku = get_post_meta($product_id, '_sku', true);
        
        if (empty($sku)) {
            wc_sspaa_log("Product ID {$product_id} has no SKU.");
            wp_send_json_error(['message' => 'Product has no SKU.']);
            return;
        }
        
        wc_sspaa_log("Syncing product with SKU: {$sku}");
        
        // Create handlers
        $api_handler = new WC_SSPAA_API_Handler();
        $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, true);
        
        // Sync the product
        $updated = $stock_updater->sync_single_product($product_id, $sku);
        
        // Check if product was marked as obsolete
        $is_obsolete = get_post_meta($product_id, '_wc_sspaa_is_obsolete', true) === 'yes';
        $stock_value = get_post_meta($product_id, '_stock', true);
        $new_sync_time = get_post_meta($product_id, '_wc_sspaa_last_sync', true);
        
        if ($updated === 'obsolete') {
            wc_sspaa_log("Product ID {$product_id} is obsolete (not found in API)");
            wp_send_json_success([
                'message' => "Product not found in API. Marked as obsolete and stock set to 0.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => true,
                'stock_value' => 0
            ]);
        } else if ($updated) {
            $new_stock = get_post_meta($product_id, '_stock', true);
            wc_sspaa_log("Successfully updated stock for product ID {$product_id} to {$new_stock}");
            wp_send_json_success([
                'message' => "Stock updated successfully to {$new_stock}.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => $is_obsolete,
                'stock_value' => $new_stock
            ]);
        } else {
            $current_stock = get_post_meta($product_id, '_stock', true);
            wc_sspaa_log("No change in stock for product ID {$product_id}");
            wp_send_json_success([
                'message' => "Stock check completed. No change required.",
                'new_sync_time' => $new_sync_time,
                'is_obsolete' => $is_obsolete,
                'stock_value' => $current_stock
            ]);
        }
        
    } catch (Exception $e) {
        wc_sspaa_log("Error syncing product ID {$product_id}: " . $e->getMessage());
        wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
    }
}

// Deactivate the plugin and clear scheduled events
function wc_sspaa_deactivate()
{
    wc_sspaa_log('Clearing scheduled sync events');
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
}
register_deactivation_hook(__FILE__, 'wc_sspaa_deactivate');

// Logging function to include timestamps and details
function wc_sspaa_log($message)
{
    // Allow enabling debug via filter, especially for manual syncs
    $enable_debug = apply_filters('wc_sspaa_enable_debug', false);
    
    $timestamp = date("Y-m-d H:i:s");
    $log_message = "[$timestamp] $message\n";
    
    // Always log to debug.log for easier troubleshooting
    error_log($log_message, 3, plugin_dir_path(__FILE__) . 'debug.log');
    
    // For manual syncs, we can also log to PHP error log for immediate visibility
    if ($enable_debug) {
        error_log('WC Stock Sync: ' . $message);
    }
}

// Check if WP-Cron is functioning properly
function wc_sspaa_check_wp_cron() {
    // Only check for disabled WP-Cron if user hasn't acknowledged using a real cron job
    if (!get_option('wc_sspaa_using_real_cron', false) && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        update_option('wc_sspaa_cron_disabled', true);
    } else {
        delete_option('wc_sspaa_cron_disabled');
    }
    
    // Check when the last sync was supposed to run
    $next_scheduled = wp_next_scheduled('wc_sspaa_daily_stock_sync');
    $current_time = current_time('timestamp');
    
    if ($next_scheduled && $next_scheduled < ($current_time - 86400)) {
        // Next scheduled time is more than a day in the past - cron might not be working
        update_option('wc_sspaa_cron_not_running', true);
    } else {
        delete_option('wc_sspaa_cron_not_running');
    }
    
    // Check last successful run
    $last_run = get_option('wc_sspaa_last_successful_run', 0);
    if ($last_run > 0 && ($current_time - $last_run) > (86400 * 2)) {
        // No successful run in the past 2 days
        update_option('wc_sspaa_cron_missed_runs', true);
    } else {
        delete_option('wc_sspaa_cron_missed_runs');
    }
}

// Display admin notices for cron issues
function wc_sspaa_display_cron_notices() {
    $screen = get_current_screen();
    
    // Only show notices on relevant screens
    if (!current_user_can('manage_woocommerce') || 
        !in_array($screen->id, array('plugins', 'dashboard', 'edit-product'))) {
        return;
    }
    
    if (get_option('wc_sspaa_cron_disabled', false)) {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>WooCommerce Stock Sync Warning:</strong> WP-Cron is disabled (DISABLE_WP_CRON is set to true). The stock sync scheduled for 1AM will not run automatically. Please set up a system cron job to trigger WordPress cron.</p>';
        echo '<p><a href="' . wp_nonce_url(admin_url('admin.php?page=wc-status&tab=tools&wc_sspaa_acknowledge_real_cron=1'), 'wc_sspaa_acknowledge_cron') . '" class="button button-primary">I\'m Using Real Cron Jobs</a></p>';
        echo '</div>';
    }
    
    if (get_option('wc_sspaa_cron_not_running', false)) {
        echo '<div class="notice notice-error"><p><strong>WooCommerce Stock Sync Warning:</strong> The scheduled stock sync appears to be behind schedule. This might indicate that WP-Cron is not working properly. <a href="' . wp_nonce_url(admin_url('admin.php?page=wc-status&tab=tools&wc_sspaa_force_reschedule=1'), 'wc_sspaa_reschedule') . '">Click here to reschedule</a>.</p></div>';
    }
    
    if (get_option('wc_sspaa_cron_missed_runs', false)) {
        echo '<div class="notice notice-warning"><p><strong>WooCommerce Stock Sync Notice:</strong> No successful stock sync has run in the past 2 days. You might want to run a manual sync or check system cron configuration.</p></div>';
    }
}

// Handle force reschedule request
function wc_sspaa_handle_force_reschedule() {
    // Verify nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wc_sspaa_reschedule')) {
        wp_die('Security check failed');
    }
    
    // Verify user has permission
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You do not have permission to perform this action');
    }
    
    wc_sspaa_log('Force reschedule triggered by admin');
    
    // Clear existing schedule and set up a new one
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    wc_sspaa_schedule_daily_sync();
    
    // Clear warning flags
    delete_option('wc_sspaa_cron_not_running');
    delete_option('wc_sspaa_cron_missed_runs');
    
    // Redirect back to the admin page
    wp_redirect(admin_url('edit.php?post_type=product&rescheduled=1'));
    exit;
}

// Show admin notice for rescheduled events
function wc_sspaa_show_reschedule_notice() {
    $next_run = wp_next_scheduled('wc_sspaa_daily_stock_sync');
    $next_run_time = $next_run ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run) : 'Unknown';
    
    echo '<div class="notice notice-success is-dismissible"><p><strong>Stock Sync Rescheduled:</strong> The daily stock sync has been rescheduled. Next run: ' . esc_html($next_run_time) . '</p></div>';
}

// Show admin notice for cron acknowledgment
function wc_sspaa_show_cron_acknowledgment_notice() {
    echo '<div class="notice notice-success is-dismissible"><p><strong>Real Cron Jobs Acknowledged:</strong> The warning about disabled WP-Cron has been suppressed. Make sure your system cron job is properly configured to trigger WordPress cron events regularly.</p></div>';
}

// Add product edit page sync button
function wc_sspaa_add_product_sync_button() {
    global $post;
    $product_id = $post->ID;
    $sku = get_post_meta($product_id, '_sku', true);
    $last_sync = get_post_meta($product_id, '_wc_sspaa_last_sync', true);
    $is_obsolete = get_post_meta($product_id, '_wc_sspaa_is_obsolete', true) === 'yes';
    $stock_value = get_post_meta($product_id, '_stock', true);

    echo '<div class="options_group">';
    
    echo '<div class="form-field wc_sspaa_sync_container">';
    echo '<label>' . __('Pronto Avenue API Sync', 'woocommerce') . '</label>';
    
    // Show last sync information if available
    if ($last_sync) {
        echo '<div class="wc_sspaa_sync_info" style="margin-bottom: 10px;">';
        echo '<p><strong>' . __('Last sync:', 'woocommerce') . '</strong> ' . esc_html($last_sync) . '</p>';
        
        if ($is_obsolete) {
            echo '<p style="color: #dc3232;"><strong>' . __('Status:', 'woocommerce') . '</strong> ' . __('Obsolete Stock', 'woocommerce') . '</p>';
        } else {
            echo '<p><strong>' . __('Current stock:', 'woocommerce') . '</strong> ' . esc_html($stock_value) . '</p>';
        }
        
        echo '</div>';
    }
    
    // Only show button if product has SKU
    if (!empty($sku)) {
        echo '<button type="button" class="button wc_sspaa_sync_button" data-product-id="' . esc_attr($product_id) . '">' . __('Sync Stock from Pronto API', 'woocommerce') . '</button>';
        echo '<span class="wc_sspaa_sync_status" style="display:none; margin-left: 10px;"></span>';
    } else {
        echo '<p class="description" style="color: #dc3232;">' . __('SKU is required for stock synchronization.', 'woocommerce') . '</p>';
    }
    
    echo '</div>';
    echo '</div>';
}

// Add product edit page sync button JavaScript
function wc_sspaa_add_product_sync_js() {
    $screen = get_current_screen();
    
    // Only add JS on product edit screen
    if (!$screen || $screen->id !== 'product') {
        return;
    }
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.wc_sspaa_sync_button').click(function(e) {
            e.preventDefault();
            
            var button = $(this);
            var container = button.closest('.wc_sspaa_sync_container');
            var productId = button.data('product-id');
            var statusEl = container.find('.wc_sspaa_sync_status');
            
            // Disable button and show loading state
            button.prop('disabled', true).text('Syncing...');
            statusEl.html('Contacting API...').css('color', '#007cba').show();
            
            console.log('Syncing product ID: ' + productId);
            
            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_sync_single_product',
                    product_id: productId,
                    security: '<?php echo wp_create_nonce('wc_sspaa_sync_product_nonce'); ?>'
                },
                success: function(response) {
                    console.log('Sync response:', response);
                    
                    if (response.success) {
                        statusEl.html(response.data.message).css('color', '#46b450');
                        
                        // Update the sync info
                        var syncInfo = container.find('.wc_sspaa_sync_info');
                        if (syncInfo.length) {
                            // Update existing info
                            syncInfo.find('p:first').html('<strong>Last sync:</strong> ' + response.data.new_sync_time);
                            
                            if (response.data.is_obsolete) {
                                syncInfo.find('p:last').html('<strong>Status:</strong> Obsolete Stock').css('color', '#dc3232');
                            } else {
                                syncInfo.find('p:last').html('<strong>Current stock:</strong> ' + response.data.stock_value).css('color', '');
                            }
                        } else {
                            // Create new info
                            var newInfo = $('<div class="wc_sspaa_sync_info" style="margin-bottom: 10px;"></div>');
                            newInfo.append('<p><strong>Last sync:</strong> ' + response.data.new_sync_time + '</p>');
                            
                            if (response.data.is_obsolete) {
                                newInfo.append('<p style="color: #dc3232;"><strong>Status:</strong> Obsolete Stock</p>');
                            } else {
                                newInfo.append('<p><strong>Current stock:</strong> ' + response.data.stock_value + '</p>');
                            }
                            
                            container.prepend(newInfo);
                        }
                    } else {
                        statusEl.html(response.data.message).css('color', '#dc3232');
                    }
                    
                    // Reset button after 3 seconds
                    setTimeout(function() {
                        button.prop('disabled', false).text('Sync Stock from Pronto API');
                    }, 3000);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    statusEl.html('Error: ' + error).css('color', '#dc3232');
                    button.prop('disabled', false).text('Sync Stock from Pronto API');
                }
            });
        });
    });
    </script>
    <style>
    .wc_sspaa_sync_container {
        padding: 10px 12px;
    }
    .wc_sspaa_sync_info {
        background: #f8f8f8;
        padding: 8px;
        border-left: 4px solid #007cba;
    }
    .wc_sspaa_sync_info p {
        margin: 5px 0;
    }
    .wc_sspaa_sync_button {
        margin-top: 5px !important;
    }
    .wc_sspaa_sync_status {
        line-height: 28px;
        font-style: italic;
    }
    </style>
    <?php
}

// Handle real cron acknowledgment
function wc_sspaa_handle_real_cron_acknowledgment() {
    // Verify nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wc_sspaa_acknowledge_cron')) {
        wp_die('Security check failed');
    }
    
    // Verify user has permission
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You do not have permission to perform this action');
    }
    
    wc_sspaa_log('Real cron jobs acknowledged by admin');
    
    // Set option to suppress disabled WP-Cron warnings
    update_option('wc_sspaa_using_real_cron', true);
    
    // Clear any disabled cron warnings
    delete_option('wc_sspaa_cron_disabled');
    
    // Redirect back to the admin page
    wp_redirect(admin_url('edit.php?post_type=product&cron_acknowledged=1'));
    exit;
}

// Add manual sync button to products page
function wc_sspaa_add_manual_sync_button() {
    global $typenow;
    
    // Only add on product list page
    if ($typenow != 'product') {
        return;
    }
    
    echo '<button type="button" id="wc-sspaa-bulk-sync-button" class="button">' . __('Sync Stock with Pronto API', 'woocommerce') . '</button>';
}

// Add manual sync JavaScript to products page
function wc_sspaa_add_manual_sync_js() {
    global $current_screen;
    
    // Only add JS on products screen
    if (!$current_screen || $current_screen->id !== 'edit-product') {
        return;
    }
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Add status container after the button
        $('#wc-sspaa-bulk-sync-button').after('<div id="wc-sspaa-bulk-sync-status" style="display:none; margin-top: 10px; padding: 15px; background-color: #f8f8f8; border-left: 4px solid #007cba;"></div>');
        
        // Handle bulk sync button click
        $('#wc-sspaa-bulk-sync-button').click(function(e) {
            e.preventDefault();
            
            if(!confirm('Are you sure you want to sync all product stock levels with Pronto API?')) {
                return;
            }
            
            var button = $(this);
            var statusEl = $('#wc-sspaa-bulk-sync-status');
            
            // Disable button and show loading state
            button.prop('disabled', true).text('Preparing sync...');
            statusEl.html('<p>Counting products and preparing sync process...</p>').show();
            
            // Start the sync process
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_bulk_sync',
                    security: '<?php echo wp_create_nonce('wc_sspaa_bulk_sync_nonce'); ?>',
                    start: true
                },
                success: function(response) {
                    if (response.success) {
                        // Update status
                        statusEl.html('<p>' + response.data.message + '</p>' +
                            '<div class="progress-bar" style="height: 20px; background-color: #eee; margin: 10px 0; position: relative;">' +
                            '<div class="progress-bar-fill" style="height: 20px; background-color: #007cba; width: 0%; transition: width 0.3s;"></div>' +
                            '<div class="progress-bar-text" style="position: absolute; top: 0; left: 0; right: 0; text-align: center; line-height: 20px; color: #000; font-weight: bold;">0%</div>' +
                            '</div>' +
                            '<p class="progress-status">Processing batch 1/' + response.data.total_batches + '...</p>'
                        );
                        
                        // Start processing batches
                        processBatch(response.data.batch_size, response.data.total_batches, 0, 0, response.data.total_products);
                    } else {
                        handleError(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    handleError('Error: ' + error);
                }
            });
            
            // Process batches sequentially with progress updates
            function processBatch(batchSize, totalBatches, currentBatch, totalUpdated, totalProducts) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_sspaa_bulk_sync',
                        security: '<?php echo wp_create_nonce('wc_sspaa_bulk_sync_nonce'); ?>',
                        batch_number: currentBatch,
                        batch_size: batchSize
                    },
                    success: function(response) {
                        if (response.success) {
                            var newTotalUpdated = totalUpdated + response.data.updated;
                            var progress = Math.round((currentBatch + 1) / totalBatches * 100);
                            
                            // Update progress bar
                            $('.progress-bar-fill').css('width', progress + '%');
                            $('.progress-bar-text').text(progress + '%');
                            $('.progress-status').html('Processed batch ' + (currentBatch + 1) + '/' + totalBatches + 
                                '. Updated ' + newTotalUpdated + ' products so far.');
                            
                            // Process next batch if more remain
                            if (currentBatch + 1 < totalBatches) {
                                processBatch(batchSize, totalBatches, currentBatch + 1, newTotalUpdated, totalProducts);
                            } else {
                                // All done!
                                statusEl.html(
                                    '<p style="color: #46b450; font-weight: bold;">Sync completed successfully!</p>' +
                                    '<p>Processed ' + totalProducts + ' products in ' + totalBatches + ' batches.</p>' +
                                    '<p>Updated stock for ' + newTotalUpdated + ' products.</p>' +
                                    '<p>Sync completed at: ' + new Date().toLocaleTimeString() + '</p>'
                                );
                                button.prop('disabled', false).text('Sync Stock with Pronto API');
                            }
                        } else {
                            handleError(response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        handleError('Error processing batch ' + (currentBatch + 1) + ': ' + error);
                    }
                });
            }
            
            function handleError(message) {
                statusEl.html('<p style="color: #dc3232;">' + message + '</p>');
                button.prop('disabled', false).text('Sync Stock with Pronto API');
            }
        });
    });
    </script>
    <?php
}

// Handle bulk sync AJAX request
function wc_sspaa_handle_bulk_sync() {
    // Verify nonce
    check_ajax_referer('wc_sspaa_bulk_sync_nonce', 'security');
    
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Enable debug for manual syncs
    add_filter('wc_sspaa_enable_debug', '__return_true');
    
    // Starting a new sync process
    if (isset($_POST['start']) && $_POST['start']) {
        wc_sspaa_log("======= Bulk stock sync initiated from Products page =======");
        
        try {
            global $wpdb;
            
            // Count total products with SKUs
            $count_query = "SELECT COUNT(post_id) FROM {$wpdb->postmeta}
                LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
                WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')";
            
            $total_products = $wpdb->get_var($count_query);
            wc_sspaa_log("Total products to sync: {$total_products}");
            
            if ($total_products == 0) {
                wp_send_json_error(['message' => 'No products found to sync.']);
                return;
            }
            
            // Calculate batches (15 products per batch)
            $batch_size = 15;
            $num_batches = ceil($total_products / $batch_size);
            
            // Return batch information to start the process
            wp_send_json_success([
                'message' => "Found {$total_products} products. Processing in {$num_batches} batches.",
                'total_products' => $total_products,
                'batch_size' => $batch_size,
                'total_batches' => $num_batches
            ]);
            
        } catch (Exception $e) {
            wc_sspaa_log("Error preparing bulk sync: " . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    // Processing a batch
    else if (isset($_POST['batch_number']) && isset($_POST['batch_size'])) {
        $batch_number = intval($_POST['batch_number']);
        $batch_size = intval($_POST['batch_size']);
        $offset = $batch_number * $batch_size;
        
        wc_sspaa_log("Processing bulk sync batch {$batch_number} with offset {$offset}");
        
        try {
            $api_handler = new WC_SSPAA_API_Handler();
            $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, true);
            
            // Process the batch
            $updated_count = $stock_updater->update_stock($offset);
            
            wc_sspaa_log("Batch {$batch_number} completed. Updated {$updated_count} products.");
            
            wp_send_json_success([
                'updated' => $updated_count,
                'batch' => $batch_number
            ]);
            
        } catch (Exception $e) {
            wc_sspaa_log("Error processing batch {$batch_number}: " . $e->getMessage());
            wp_send_json_error(['message' => 'Error processing batch: ' . $e->getMessage()]);
        }
    }
    else {
        wp_send_json_error(['message' => 'Invalid request parameters.']);
    }
}
?>