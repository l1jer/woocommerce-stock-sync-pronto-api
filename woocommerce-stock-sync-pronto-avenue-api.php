<?php
/*
Plugin Name: WooCommerce Stock Sync with Pronto Avenue API
Description: Integrates WooCommerce with an external API to automatically update product stock levels based on SKU codes. Fetches product data, matches SKUs, and updates stock levels, handling API rate limits and server execution time constraints with batch processing.
Version: 1.1.6
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

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/config.php'; // Include the config file for credentials
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php'; // Include the API handler class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-updater.php'; // Include the stock updater class
require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-time-col.php'; // Include the stock sync time column class

function wc_sspaa_activate()
{
    wc_sspaa_schedule_daily_sync(); // Schedule daily sync during activation
}
register_activation_hook(__FILE__, 'wc_sspaa_activate');

function wc_sspaa_init()
{
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false); // Set enable_debug to false

    add_action('wc_sspaa_daily_stock_sync', 'wc_sspaa_run_sync'); // Hook the daily sync function
    
    // Add admin actions for manual sync
    add_action('admin_footer-edit.php', 'wc_sspaa_add_manual_sync_button');
    add_action('wp_ajax_wc_sspaa_manual_sync', 'wc_sspaa_handle_manual_sync');
}
add_action('plugins_loaded', 'wc_sspaa_init');

// Schedule daily sync at 1AM except on weekends
function wc_sspaa_schedule_daily_sync()
{
    wc_sspaa_log('Scheduling daily sync at 1AM except weekends.');
    
    // Clear any existing schedule
    wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
    
    // Schedule new daily sync at 1AM
    $next_run = wc_sspaa_get_next_run_time();
    if ($next_run) {
        wp_schedule_event($next_run, 'daily', 'wc_sspaa_daily_stock_sync');
        wc_sspaa_log('Daily sync scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    }
}

// Get the next valid run time (1AM on non-weekend day)
function wc_sspaa_get_next_run_time()
{
    $timestamp = strtotime('1:00:00 tomorrow');
    $day_of_week = date('N', $timestamp);
    
    // If it's Saturday (6) or Sunday (7), adjust to Monday
    if ($day_of_week >= 6) {
        $timestamp = strtotime('next Monday 1:00:00', $timestamp - 86400);
    }
    
    return $timestamp;
}

// Run the sync process with dynamic batch calculation
function wc_sspaa_run_sync()
{
    global $wpdb;
    
    wc_sspaa_log('Starting daily stock sync');
    
    // Count total products with SKUs
    $count_query = "SELECT COUNT(post_id) FROM {$wpdb->postmeta}
        LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
        WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')";
    
    $total_products = $wpdb->get_var($count_query);
    wc_sspaa_log("Total products to sync: {$total_products}");
    
    // Calculate batches (15 products per batch)
    $batch_size = 15;
    $num_batches = ceil($total_products / $batch_size);
    
    $api_handler = new WC_SSPAA_API_Handler();
    $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 2000000, 15, 2000000, 15, 25, false);
    
    // Process each batch
    for ($i = 0; $i < $num_batches; $i++) {
        $offset = $i * $batch_size;
        wc_sspaa_log("Processing batch {$i} with offset {$offset}");
        $stock_updater->update_stock($offset);
        
        // Small pause between batches to prevent server overload
        sleep(1);
    }
    
    wc_sspaa_log('Daily stock sync completed');
    
    // Schedule the next sync
    wc_sspaa_schedule_daily_sync();
}

// Add manual sync button to product listing page
function wc_sspaa_add_manual_sync_button()
{
    global $current_screen;
    
    if ($current_screen->id != 'edit-product')
        return;
    
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($){
            $('.tablenav.top .bulkactions').append(
                '<button type="button" id="wc-sspaa-sync-button" class="button action">Sync Stock with Pronto API</button>'
            );
            
            $('#wc-sspaa-sync-button').on('click', function(){
                if(confirm('Are you sure you want to sync all product stock levels with Pronto API?')){
                    $(this).prop('disabled', true).text('Syncing...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wc_sspaa_manual_sync',
                            security: '<?php echo wp_create_nonce('wc_sspaa_manual_sync_nonce'); ?>'
                        },
                        success: function(response){
                            alert(response.data.message);
                            location.reload();
                        },
                        error: function(){
                            alert('An error occurred. Please try again.');
                            $('#wc-sspaa-sync-button').prop('disabled', false).text('Sync Stock with Pronto API');
                        }
                    });
                }
            });
        });
    </script>
    <?php
}

// Handle manual sync AJAX request
function wc_sspaa_handle_manual_sync()
{
    // Verify nonce
    check_ajax_referer('wc_sspaa_manual_sync_nonce', 'security');
    
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Run sync process
    wc_sspaa_log('Manual sync triggered by admin');
    wc_sspaa_run_sync();
    
    wp_send_json_success(['message' => 'Stock sync completed successfully!']);
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
    $timestamp = date("Y-m-d H:i:s");
    error_log("[$timestamp] $message\n", 3, plugin_dir_path(__FILE__) . 'debug.log');
}
?>