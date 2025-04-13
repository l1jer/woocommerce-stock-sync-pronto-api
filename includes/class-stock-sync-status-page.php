<?php
/**
 * Stock Sync Status Page
 *
 * Handles displaying and managing the Stock Sync Status page in the admin area
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_SSPAA_Stock_Sync_Status_Page {
    /**
     * Constructor
     */
    public function __construct() {
        // Add menu item
        add_action('admin_menu', array($this, 'add_menu_item'));
        
        // Register scripts and styles for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handlers
        add_action('wp_ajax_wc_sspaa_get_stock_sync_stats', array($this, 'ajax_get_stock_sync_stats'));
        add_action('wp_ajax_wc_sspaa_save_sync_time', array($this, 'ajax_save_sync_time'));
    }

    /**
     * Add menu item
     */
    public function add_menu_item() {
        add_submenu_page(
            'edit.php?post_type=product',         // Parent slug (Products menu)
            __('Stock Sync Status', 'woocommerce'), // Page title
            __('Stock Sync Status', 'woocommerce'), // Menu title
            'manage_woocommerce',                   // Capability
            'wc-sspaa-settings',                    // Menu slug
            array($this, 'render_page')             // Callback function
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Only load on our settings page
        if ('product_page_wc-sspaa-settings' !== $hook) {
            return;
        }
        
        $js_path = '../assets/js/admin.js';
        $js_url = plugins_url($js_path, __FILE__);
        $js_file = plugin_dir_path(__FILE__) . $js_path;
        
        if (!file_exists($js_file)) {
            return;
        }
        
        wp_enqueue_script(
            'wc-sspaa-admin',
            $js_url,
            array('jquery'),
            filemtime($js_file),
            true
        );
        
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_settings_nonce')
        );
        
        wp_localize_script('wc-sspaa-admin', 'wcSspaaAdmin', $script_data);
        
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-sspaa-settings-container {
                max-width: 1100px;
                margin: 20px 0;
                padding: 20px;
                background-color: #fff;
                border: 1px solid #ddd;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .wc-sspaa-settings-container h1 {
                margin-top: 0;
            }
            .wc-sspaa-settings-container .form-table {
                margin-top: 20px;
            }
            .wc-sspaa-settings-container .form-table th {
                padding: 15px 10px 15px 0;
                width: 200px;
            }
            .wc-sspaa-time-display {
                background: #f9f9f9;
                border: 1px solid #e5e5e5;
                padding: 10px;
                display: inline-block;
                min-width: 200px;
            }
            .wc-sspaa-notice {
                padding: 10px;
                margin: 10px 0;
                border-left: 4px solid #00a0d2;
                background-color: #f7fcfe;
            }
            .wc-sspaa-notice.success {
                border-left-color: #46b450;
                background-color: #ecf7ed;
            }
            .wc-sspaa-notice.error {
                border-left-color: #dc3232;
                background-color: #fbeaea;
            }
        ');
    }

    /**
     * Render the status page
     */
    public function render_page() {
        ?>
        <div class="wrap wc-sspaa-settings-container">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <p><?php _e('This page provides status information about your WooCommerce stock synchronization with the Pronto Avenue API.', 'woocommerce'); ?></p>
            
            <div class="wc-sspaa-status-section">
                <h2><?php _e('Time Information', 'woocommerce'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Current Time', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-current-utc-time">
                                <?php _e('UTC: Loading...', 'woocommerce'); ?>
                            </div>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-current-aest-time">
                                <?php _e('AEST: Loading...', 'woocommerce'); ?>
                            </div>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-dst-status">
                                <?php _e('DST: Checking...', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Daily Sync Start Time', 'woocommerce'); ?></th>
                        <td>
                            <input type="time" id="wc-sspaa-sync-time" name="wc_sspaa_sync_time" step="1" value="<?php echo esc_attr(get_option('wc_sspaa_sync_time', '02:00:00')); ?>" />
                            <button type="button" class="button" id="wc-sspaa-save-time"><?php _e('Save Time', 'woocommerce'); ?></button>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-sync-time-utc">
                                <?php _e('UTC: Not set', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="wc-sspaa-status-section">
                <h2><?php _e('Synchronization Information', 'woocommerce'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Products with SKUs', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-products-with-skus">
                                <?php _e('Loading...', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Total Batches', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-total-batches">
                                <?php _e('Loading...', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Next Scheduled Batch', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-next-batch-utc">
                                <?php _e('UTC: Loading...', 'woocommerce'); ?>
                            </div>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-next-batch-sydney">
                                <?php _e('Sydney: Loading...', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Last Sync Completion', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-last-sync-utc">
                                <?php _e('UTC: Loading...', 'woocommerce'); ?>
                            </div>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-last-sync-sydney">
                                <?php _e('Sydney: Loading...', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to get synchronization statistics
     */
    public function ajax_get_stock_sync_stats() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_settings_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        global $wpdb;
        
        try {
            // Get count of products with SKUs
            $products_with_skus = $wpdb->get_var(
                "SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_sku' 
                AND p.post_type IN ('product', 'product_variation')
                AND pm.meta_value != ''"
            );
            
            // Calculate total batches
            $batch_size = 15; // Same as in wc_sspaa_init()
            $total_batches = ceil($products_with_skus / $batch_size);
            
            // Get next scheduled batch time
            $next_batch = wp_next_scheduled('wc_sspaa_update_stock_batch', array(0));
            $next_batch_utc = $next_batch ? date('Y-m-d H:i:s', $next_batch) : 'Not scheduled';
            
            // Convert to Sydney time
            $next_batch_sydney = 'Not scheduled';
            if ($next_batch) {
                // Create DateTime objects
                $dt = new DateTime('@' . $next_batch);
                $dt->setTimezone(new DateTimeZone('Australia/Sydney'));
                $next_batch_sydney = $dt->format('Y-m-d H:i:s');
            }
            
            // Get last sync completion time
            $last_sync = $wpdb->get_var(
                "SELECT MAX(meta_value) 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wc_sspaa_last_sync'"
            );
            
            // Convert to UTC and Sydney time
            $last_sync_utc = 'Never';
            $last_sync_sydney = 'Never';
            
            if ($last_sync) {
                // Convert from local WP time to UTC
                $wp_timezone = new DateTimeZone(wp_timezone_string());
                $utc_timezone = new DateTimeZone('UTC');
                $sydney_timezone = new DateTimeZone('Australia/Sydney');
                
                $dt = new DateTime($last_sync, $wp_timezone);
                $dt->setTimezone($utc_timezone);
                $last_sync_utc = $dt->format('Y-m-d H:i:s');
                
                $dt->setTimezone($sydney_timezone);
                $last_sync_sydney = $dt->format('Y-m-d H:i:s');
            }
            
            // Return data
            wp_send_json_success(array(
                'products_with_skus' => $products_with_skus,
                'total_batches' => $total_batches,
                'next_batch_utc' => $next_batch_utc,
                'next_batch_sydney' => $next_batch_sydney,
                'last_sync_utc' => $last_sync_utc,
                'last_sync_sydney' => $last_sync_sydney
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error fetching statistics: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler to save sync start time
     */
    public function ajax_save_sync_time() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_settings_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $sync_time = isset($_POST['sync_time']) ? sanitize_text_field($_POST['sync_time']) : '';
        
        if (empty($sync_time) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $sync_time)) {
            wp_send_json_error(array('message' => 'Invalid time format. Please use HH:MM:SS.'));
            return;
        }
        
        try {
            // Store the sync time
            update_option('wc_sspaa_sync_time', $sync_time);
            
            // Calculate UTC time
            $sydney_timezone = new DateTimeZone('Australia/Sydney');
            $utc_timezone = new DateTimeZone('UTC');
            
            list($hours, $minutes, $seconds) = explode(':', $sync_time);
            
            // Create a DateTime object for today with the given time in Sydney
            $today = date('Y-m-d');
            $dt = new DateTime("{$today} {$sync_time}", $sydney_timezone);
            
            // Convert to UTC
            $dt->setTimezone($utc_timezone);
            $utc_time = $dt->format('Y-m-d H:i:s');
            
            // Reschedule the batches
            $this->reschedule_batches($sync_time);
            
            wp_send_json_success(array(
                'sync_time' => $sync_time,
                'utc_time' => $utc_time,
                'message' => 'Sync time saved successfully'
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error saving sync time: ' . $e->getMessage()));
        }
    }
    
    /**
     * Reschedule batch processes with new time
     */
    private function reschedule_batches($sync_time) {
        global $wpdb;
        
        // Clear existing scheduled events
        $batch_times = [];
        for ($i = 0; $i < 11; $i++) {
            $offset = $i * 15;
            wp_clear_scheduled_hook('wc_sspaa_update_stock_batch', array($offset));
            
            // Calculate new time (add 30 minutes between each batch)
            $time_parts = explode(':', $sync_time);
            $hours = (int)$time_parts[0];
            $minutes = (int)$time_parts[1];
            $seconds = (int)$time_parts[2];
            
            // Add 30 minutes for each batch
            $minutes += ($i * 30);
            
            // Handle overflow
            $hours += floor($minutes / 60);
            $minutes = $minutes % 60;
            $hours = $hours % 24;
            
            $new_time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            $batch_times[] = ['time' => $new_time, 'offset' => $offset];
        }
        
        // Schedule new events
        foreach ($batch_times as $batch) {
            if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($batch['offset']))) {
                wp_schedule_event(strtotime($batch['time']), 'daily', 'wc_sspaa_update_stock_batch', array($batch['offset']));
            }
        }
    }
}

// Initialize the class
new WC_SSPAA_Stock_Sync_Status_Page(); 