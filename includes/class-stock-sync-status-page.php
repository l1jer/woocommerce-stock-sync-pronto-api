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
        
        // Add new AJAX handlers for API credentials
        add_action('wp_ajax_wc_sspaa_test_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_wc_sspaa_save_api_credentials', array($this, 'ajax_save_api_credentials'));
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
        
        // Get the current domain for initializing the dropdown
        $current_domain = str_replace('www.', '', $_SERVER['HTTP_HOST']);
        
        // Get global API credentials array
        global $wc_sspaa_api_credentials;
        $active_credentials = array();
        
        // Get currently active credentials for display
        if (isset($wc_sspaa_api_credentials[$current_domain])) {
            $active_credentials = $wc_sspaa_api_credentials[$current_domain];
        } else {
            // Use the first set if no match
            $first_site = array_key_first($wc_sspaa_api_credentials);
            $active_credentials = $wc_sspaa_api_credentials[$first_site];
        }
        
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_settings_nonce'),
            'currentDomain' => $current_domain,
            'activeUsername' => $active_credentials['username'],
            'activePassword' => $active_credentials['password']
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
        ');
    }

    /**
     * Render the status page
     */
    public function render_page() {
        // Get global API credentials array for the dropdown
        global $wc_sspaa_api_credentials;
        
        // Get current domain for preselecting dropdown
        $current_domain = str_replace('www.', '', $_SERVER['HTTP_HOST']);
        
        // Determine active credentials
        $active_credentials = array();
        if (isset($wc_sspaa_api_credentials[$current_domain])) {
            $active_credentials = $wc_sspaa_api_credentials[$current_domain];
        } else {
            $first_site = array_key_first($wc_sspaa_api_credentials);
            $active_credentials = $wc_sspaa_api_credentials[$first_site];
        }
        
        ?>
        <div class="wrap wc-sspaa-settings-container">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <p><?php _e('This page provides status information about your WooCommerce stock synchronization with the Pronto Avenue API.', 'woocommerce'); ?></p>
            
            <div class="wc-sspaa-status-section">
                <h2><?php _e('API Credentials', 'woocommerce'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Select Website', 'woocommerce'); ?></th>
                        <td>
                            <select id="wc-sspaa-api-site-select" name="wc_sspaa_api_site">
                                <?php foreach ($wc_sspaa_api_credentials as $domain => $creds): ?>
                                    <option value="<?php echo esc_attr($domain); ?>" <?php selected($domain, $current_domain); ?>>
                                        <?php echo esc_html($creds['display_name']); ?> (<?php echo esc_html($domain); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button" id="wc-sspaa-test-api-connection"><?php _e('Test API Connection', 'woocommerce'); ?></button>
                            <span id="wc-sspaa-api-test-result"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Current Credentials', 'woocommerce'); ?></th>
                        <td>
                            <div class="wc-sspaa-credentials-display">
                                <strong><?php _e('Username:', 'woocommerce'); ?></strong>
                                <code id="wc-sspaa-api-username"><?php echo esc_html($active_credentials['username']); ?></code>
                                <strong><?php _e('Password:', 'woocommerce'); ?></strong>
                                <code id="wc-sspaa-api-password"><?php echo esc_html($active_credentials['password']); ?></code>
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
            
            // Calculate total batches dynamically
            $batch_size = 15; // Must match the main plugin
            $total_batches = ceil($products_with_skus / $batch_size);
            wc_sspaa_log('[Status Page] Total products with SKUs: ' . $products_with_skus . ', Batch size: ' . $batch_size . ', Total batches: ' . $total_batches);
            
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
            
            // Check if last_sync is a valid timestamp before parsing
            if ($last_sync && is_numeric(strtotime($last_sync))) { 
                try {
                    // Convert from local WP time to UTC
                    $wp_timezone = new DateTimeZone(wp_timezone_string());
                    $utc_timezone = new DateTimeZone('UTC');
                    $sydney_timezone = new DateTimeZone('Australia/Sydney');
                    
                    $dt = new DateTime($last_sync, $wp_timezone);
                    $dt->setTimezone($utc_timezone);
                    $last_sync_utc = $dt->format('Y-m-d H:i:s');
                    
                    $dt->setTimezone($sydney_timezone);
                    $last_sync_sydney = $dt->format('Y-m-d H:i:s');
                } catch (Exception $date_ex) {
                    // Handle potential date parsing errors gracefully
                    $last_sync_utc = 'Error parsing date';
                    $last_sync_sydney = 'Error parsing date';
                    // Optionally log the error: error_log('Error parsing last sync date: ' . $date_ex->getMessage());
                }
            } elseif ($last_sync) {
                // Handle non-timestamp values like 'Obsolete'
                $last_sync_utc = $last_sync; // Display the value directly (e.g., "Obsolete")
                $last_sync_sydney = $last_sync;
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
        
        // Set up Sydney timezone
        $sydney_timezone = new DateTimeZone('Australia/Sydney');
        $utc_timezone = new DateTimeZone('UTC');
        
        // Get current date in Sydney timezone
        $today = new DateTime('now', $sydney_timezone);
        $today_date = $today->format('Y-m-d');
        
        // Schedule new events
        foreach ($batch_times as $batch) {
            if (!wp_next_scheduled('wc_sspaa_update_stock_batch', array($batch['offset']))) {
                // Create Sydney datetime with the batch time
                $sydney_datetime = new DateTime($today_date . ' ' . $batch['time'], $sydney_timezone);
                
                // Convert to UTC for scheduling
                $sydney_datetime->setTimezone($utc_timezone);
                $utc_timestamp = $sydney_datetime->getTimestamp();
                
                wp_schedule_event($utc_timestamp, 'daily', 'wc_sspaa_update_stock_batch', array($batch['offset']));
            }
        }
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
        
        $site_domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        
        // Validate domain
        if (empty($site_domain)) {
            wp_send_json_error(array('message' => 'No domain specified'));
            return;
        }
        
        // Get global credentials array
        global $wc_sspaa_api_credentials;
        
        // Check if domain exists in our credentials
        if (!isset($wc_sspaa_api_credentials[$site_domain])) {
            wp_send_json_error(array('message' => 'Invalid domain specified'));
            return;
        }
        
        // Get credentials for the selected domain
        $credentials = $wc_sspaa_api_credentials[$site_domain];
        
        // Create API handler with specified credentials
        $api_handler = new WC_SSPAA_API_Handler($credentials['username'], $credentials['password']);
        
        // Test the connection
        $test_result = $api_handler->test_connection();
        
        if ($test_result['success']) {
            wp_send_json_success(array(
                'message' => $test_result['message'],
                'username' => $credentials['username'],
                'password' => $credentials['password']
            ));
        } else {
            wp_send_json_error(array('message' => $test_result['message']));
        }
    }
    
    /**
     * AJAX handler to save selected API credentials
     */
    public function ajax_save_api_credentials() {
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
        
        $site_domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        
        // Validate domain
        if (empty($site_domain)) {
            wp_send_json_error(array('message' => 'No domain specified'));
            return;
        }
        
        // Get global credentials array
        global $wc_sspaa_api_credentials;
        
        // Check if domain exists in our credentials
        if (!isset($wc_sspaa_api_credentials[$site_domain])) {
            wp_send_json_error(array('message' => 'Invalid domain specified'));
            return;
        }
        
        // Get credentials for the selected domain
        $credentials = $wc_sspaa_api_credentials[$site_domain];
        
        // Update option to save selected domain for persistence across page loads
        update_option('wc_sspaa_selected_domain', $site_domain);
        
        wp_send_json_success(array(
            'message' => 'API credentials updated successfully',
            'username' => $credentials['username'],
            'password' => $credentials['password']
        ));
    }
}

// Initialize the class
new WC_SSPAA_Stock_Sync_Status_Page(); 