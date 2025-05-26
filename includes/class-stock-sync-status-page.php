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
        add_action('wp_ajax_wc_sspaa_test_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_wc_sspaa_sync_all_products', array($this, 'ajax_sync_all_products'));
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
        
        // Use static credentials instead of dynamic array
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_settings_nonce'),
            'activeUsername' => WCAP_API_USERNAME,
            'activePassword' => WCAP_API_PASSWORD
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
            .wc-sspaa-api-test-result {
                margin-left: 10px;
                padding: 5px 10px;
                display: inline-block;
                border-radius: 3px;
                font-weight: bold;
            }
            .wc-sspaa-api-test-result.success {
                background-color: #ecf7ed;
                color: #46b450;
            }
            .wc-sspaa-api-test-result.failure {
                background-color: #fbeaea;
                color: #dc3232;
            }
            .wc-sspaa-credentials-display {
                background: #f9f9f9;
                border: 1px solid #e5e5e5;
                padding: 10px;
                margin-top: 10px;
            }
            .wc-sspaa-credentials-display code {
                display: block;
                margin: 5px 0;
            }
            #wc-sspaa-sync-all-products {
                font-size: 14px;
                padding: 8px 16px;
                height: auto;
            }
            #wc-sspaa-sync-all-products:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            #wc-sspaa-sync-progress {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 4px;
                padding: 10px;
                color: #856404;
            }
            #wc-sspaa-sync-all-spinner.is-active {
                visibility: visible;
                display: inline-block;
            }
            .wc-sspaa-status-section {
                margin-bottom: 30px;
            }
            .wc-sspaa-status-section:last-child {
                margin-bottom: 0;
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
                <h2><?php _e('API Credentials', 'woocommerce'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Connection', 'woocommerce'); ?></th>
                        <td>
                            <button type="button" class="button" id="wc-sspaa-test-api-connection"><?php _e('Test API Connection', 'woocommerce'); ?></button>
                            <span id="wc-sspaa-api-test-result"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Current Credentials', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-credentials-display">
                                <strong><?php _e('Username:', 'woocommerce'); ?></strong>
                                <code id="wc-sspaa-api-username"><?php echo esc_html(WCAP_API_USERNAME); ?></code>
                                <strong><?php _e('Password:', 'woocommerce'); ?></strong>
                                <code id="wc-sspaa-api-password"><?php echo esc_html(WCAP_API_PASSWORD); ?></code>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
            
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
                        <th scope="row"><?php _e('Sync Method', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-sync-method">
                                <?php _e('Sequential (All products in one run)', 'woocommerce'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Next Scheduled Sync', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-next-sync-utc">
                                <?php _e('UTC: Loading...', 'woocommerce'); ?>
                            </div>
                            <div class="wc-sspaa-time-display" id="wc-sspaa-next-sync-sydney">
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
            
            <div class="wc-sspaa-status-section">
                <h2><?php _e('Manual Synchronization', 'woocommerce'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Immediate Sync', 'woocommerce'); ?></th>
                        <td>
                            <button type="button" class="button button-primary" id="wc-sspaa-sync-all-products">
                                <?php _e('Sync All Products Now', 'woocommerce'); ?>
                            </button>
                            <span class="spinner" id="wc-sspaa-sync-all-spinner" style="float: none; margin-left: 10px;"></span>
                            <p class="description">
                                <?php _e('This will immediately sync all products with the API. Please note this may take several minutes depending on the number of products.', 'woocommerce'); ?>
                            </p>
                            <div id="wc-sspaa-sync-progress" style="margin-top: 10px; display: none;">
                                <strong><?php _e('Sync in progress...', 'woocommerce'); ?></strong>
                                <p><?php _e('Please do not close this page or navigate away while the sync is running.', 'woocommerce'); ?></p>
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
            
            // No more batch processing - sequential sync
            
            // Get next scheduled daily sync time
            $next_sync = wp_next_scheduled('wc_sspaa_daily_stock_sync');
            $next_sync_utc = $next_sync ? date('Y-m-d H:i:s', $next_sync) : 'Not scheduled';
            
            // Convert to Sydney time
            $next_sync_sydney = 'Not scheduled';
            if ($next_sync) {
                // Create DateTime objects
                $dt = new DateTime('@' . $next_sync);
                $dt->setTimezone(new DateTimeZone('Australia/Sydney'));
                $next_sync_sydney = $dt->format('Y-m-d H:i:s');
            }
            
            // Get last sync completion time from option
            $last_sync_completion = get_option('wc_sspaa_last_sync_completion', '');
            
            // Convert to UTC and Sydney time
            $last_sync_utc = 'Never';
            $last_sync_sydney = 'Never';
            
            // Check if last_sync_completion is a valid timestamp before parsing
            if ($last_sync_completion && is_numeric(strtotime($last_sync_completion))) { 
                try {
                    // Convert from local WP time to UTC
                    $wp_timezone = new DateTimeZone(wp_timezone_string());
                    $utc_timezone = new DateTimeZone('UTC');
                    $sydney_timezone = new DateTimeZone('Australia/Sydney');
                    
                    $dt = new DateTime($last_sync_completion, $wp_timezone);
                    $dt->setTimezone($utc_timezone);
                    $last_sync_utc = $dt->format('Y-m-d H:i:s');
                    
                    $dt->setTimezone($sydney_timezone);
                    $last_sync_sydney = $dt->format('Y-m-d H:i:s');
                } catch (Exception $date_ex) {
                    // Handle potential date parsing errors gracefully
                    $last_sync_utc = 'Error parsing date';
                    $last_sync_sydney = 'Error parsing date';
                }
            } elseif ($last_sync_completion) {
                // Handle non-timestamp values
                $last_sync_utc = $last_sync_completion;
                $last_sync_sydney = $last_sync_completion;
            }
            
            // Return data for sequential sync
            wp_send_json_success(array(
                'products_with_skus' => $products_with_skus,
                'sync_method' => 'Sequential (All products in one run)',
                'next_sync_utc' => $next_sync_utc,
                'next_sync_sydney' => $next_sync_sydney,
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
            
            // Reschedule the daily sync
            $this->reschedule_daily_sync($sync_time);
            
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
     * Reschedule daily sync with new time
     */
    private function reschedule_daily_sync($sync_time) {
        wc_sspaa_log('[Reschedule] Updating daily sync time to: ' . $sync_time);

        // Clear existing scheduled event
        wp_clear_scheduled_hook('wc_sspaa_daily_stock_sync');
        wc_sspaa_log('[Reschedule] Cleared existing daily sync schedule');

        // Set up timezones
        $sydney_timezone = new DateTimeZone('Australia/Sydney');
        $utc_timezone = new DateTimeZone('UTC');
        
        // Get current date in Sydney timezone
        $today = new DateTime('now', $sydney_timezone);
        $today_date = $today->format('Y-m-d');

        // Create Sydney datetime with the sync time
        $sydney_datetime = new DateTime($today_date . ' ' . $sync_time, $sydney_timezone);
        
        // If the time has already passed today, schedule for tomorrow
        $now = new DateTime('now', $sydney_timezone);
        if ($sydney_datetime <= $now) {
            $sydney_datetime->add(new DateInterval('P1D'));
        }
        
        // Convert to UTC for scheduling
        $sydney_datetime->setTimezone($utc_timezone);
        $utc_timestamp = $sydney_datetime->getTimestamp();
        
        wc_sspaa_log('[Reschedule] Scheduling daily sync at Sydney time: ' . $sync_time . 
            ' (UTC time: ' . $sydney_datetime->format('Y-m-d H:i:s') . ')');
        
        // Schedule the new event
        wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_daily_stock_sync');
        
        wc_sspaa_log('[Reschedule] Daily sync rescheduled successfully');
    }

    /**
     * AJAX handler to test API connection
     */
    public function ajax_test_api_connection() {
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
        
        // Create API handler with static credentials
        $api_handler = new WC_SSPAA_API_Handler(WCAP_API_USERNAME, WCAP_API_PASSWORD);
        
        // Test the connection
        $test_result = $api_handler->test_connection();
        
        if ($test_result['success']) {
            wp_send_json_success(array(
                'message' => $test_result['message'],
                'username' => WCAP_API_USERNAME,
                'password' => WCAP_API_PASSWORD
            ));
        } else {
            wp_send_json_error(array('message' => $test_result['message']));
        }
    }
    
    /**
     * AJAX handler to sync all products immediately
     */
    public function ajax_sync_all_products() {
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
        
        // Check if another sync is already running
        $lock_transient_key = 'wc_sspaa_sync_all_active_lock';
        $lock_timeout = 3600; // Lock for 1 hour (should be enough for any sync)
        
        if (get_transient($lock_transient_key)) {
            wp_send_json_error(array('message' => 'Another sync operation is currently in progress. Please wait for it to complete.'));
            return;
        }
        
        // Set lock to prevent multiple syncs
        set_transient($lock_transient_key, true, $lock_timeout);
        
        try {
            wc_sspaa_log('Starting immediate sync all products via AJAX');
            
            global $wpdb;
            
            // Get total count of products with SKUs
            $total_products = $wpdb->get_var(
                "SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_sku' 
                AND p.post_type IN ('product', 'product_variation')
                AND pm.meta_value != ''"
            );
            
            wc_sspaa_log("AJAX sync: Total products with SKUs to sync: {$total_products}");
            
            // Create API handler and stock updater
            $api_handler = new WC_SSPAA_API_Handler();
            $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 3000000, 0, 0, 0, 0, true); // 3 second delay, debug enabled
            
            // Perform the sync
            $stock_updater->update_all_products();
            
            // Release lock
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Completed immediate sync all products via AJAX');
            
            wp_send_json_success(array(
                'message' => "Successfully synced all {$total_products} products. Check the logs for details.",
                'total_products' => $total_products
            ));
            
        } catch (Exception $e) {
            // Release lock on error
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Error in AJAX sync all products: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing products: ' . $e->getMessage()));
        }
    }
}

// Initialize the class
new WC_SSPAA_Stock_Sync_Status_Page(); 