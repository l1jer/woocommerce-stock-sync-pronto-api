<?php
/**
 * Products Page Sync Button
 *
 * Handles adding the sync button with countdown timer to the WooCommerce All Products page
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_SSPAA_Products_Page_Sync {
    /**
     * Constructor
     */
    public function __construct() {
        // Add sync button to products page
        add_action('manage_posts_extra_tablenav', array($this, 'add_sync_button'), 10, 1);
        
        // Enqueue scripts and styles for products page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handler for sync all products
        add_action('wp_ajax_wc_sspaa_sync_all_products_with_timer', array($this, 'ajax_sync_all_products_with_timer'));
    }

    /**
     * Add sync button to products page
     */
    public function add_sync_button($which) {
        global $typenow;
        
        // Only show on product pages and on the top tablenav
        if ($typenow !== 'product' || $which !== 'top') {
            return;
        }
        
        // Get total count of products with SKUs
        global $wpdb;
        $total_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' 
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''"
        );
        
        ?>
        <div class="alignleft actions wc-sspaa-sync-section">
            <button type="button" class="button button-primary" id="wc-sspaa-sync-all-products-timer">
                Sync All (<?php echo esc_html($total_products); ?>) Products
            </button>
            <span class="spinner" id="wc-sspaa-sync-spinner"></span>
            
            <div id="wc-sspaa-sync-timer-container" style="display: none;">
                <div id="wc-sspaa-sync-progress-bar">
                    <div id="wc-sspaa-sync-progress-fill"></div>
                </div>
                <div id="wc-sspaa-sync-timer-text">
                    Estimated time remaining: <span id="wc-sspaa-countdown-timer">--:--</span>
                </div>
                <div id="wc-sspaa-sync-status">
                    Processing product <span id="wc-sspaa-current-product">0</span> of <span id="wc-sspaa-total-products"><?php echo esc_html($total_products); ?></span>
                </div>
            </div>
            
            <div id="wc-sspaa-sync-notices"></div>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles for products page
     */
    public function enqueue_scripts($hook) {
        // Only load on products page
        if ($hook !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'product') {
            return;
        }
        
        $js_path = '../assets/js/products-page-sync.js';
        $js_url = plugins_url($js_path, __FILE__);
        $js_file = plugin_dir_path(__FILE__) . $js_path;
        
        // Create the JS file if it doesn't exist (we'll create it next)
        if (!file_exists($js_file)) {
            // We'll create this file in the next step
            return;
        }
        
        wp_enqueue_script(
            'wc-sspaa-products-page-sync',
            $js_url,
            array('jquery'),
            filemtime($js_file),
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('wc-sspaa-products-page-sync', 'wcSspaaProductsSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_products_sync_nonce'),
            'apiDelay' => 3000, // 3 seconds delay between API calls
        ));
        
        // Add inline CSS for the sync interface
        wp_add_inline_style('wp-admin', '
            .wc-sspaa-sync-section {
                margin-right: 10px;
            }
            
            #wc-sspaa-sync-timer-container {
                margin-top: 10px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                max-width: 400px;
            }
            
            #wc-sspaa-sync-progress-bar {
                width: 100%;
                height: 20px;
                background-color: #f1f1f1;
                border-radius: 10px;
                overflow: hidden;
                margin-bottom: 10px;
            }
            
            #wc-sspaa-sync-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #0073aa, #00a0d2);
                width: 0%;
                transition: width 0.3s ease;
                border-radius: 10px;
            }
            
            #wc-sspaa-sync-timer-text {
                font-weight: bold;
                color: #0073aa;
                margin-bottom: 5px;
            }
            
            #wc-sspaa-sync-status {
                color: #666;
                font-size: 13px;
            }
            
            #wc-sspaa-countdown-timer {
                font-family: monospace;
                font-size: 16px;
                color: #d63638;
            }
            
            .wc-sspaa-notice {
                margin: 10px 0;
                padding: 10px;
                border-left: 4px solid #00a0d2;
                background-color: #f7fcfe;
                border-radius: 0 4px 4px 0;
            }
            
            .wc-sspaa-notice.success {
                border-left-color: #46b450;
                background-color: #ecf7ed;
                color: #155724;
            }
            
            .wc-sspaa-notice.error {
                border-left-color: #dc3232;
                background-color: #fbeaea;
                color: #721c24;
            }
            
            #wc-sspaa-sync-all-products-timer:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            
            #wc-sspaa-sync-spinner.is-active {
                visibility: visible;
                display: inline-block;
            }
        ');
    }

    /**
     * AJAX handler to sync all products with timer functionality
     */
    public function ajax_sync_all_products_with_timer() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_products_sync_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check if another sync is already running
        $lock_transient_key = 'wc_sspaa_sync_all_active_lock';
        $lock_timeout = 3600; // Lock for 1 hour
        
        if (get_transient($lock_transient_key)) {
            wp_send_json_error(array('message' => 'Another sync operation is currently in progress. Please wait for it to complete.'));
            return;
        }
        
        // Set lock to prevent multiple syncs
        set_transient($lock_transient_key, true, $lock_timeout);
        
        try {
            wc_sspaa_log('Starting immediate sync all products via AJAX from Products page');
            
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
            
            wc_sspaa_log("Products page AJAX sync: Total products with SKUs to sync: {$total_products}");
            
            // Calculate estimated time (3 seconds per product)
            $estimated_seconds = $total_products * 3;
            $estimated_minutes = floor($estimated_seconds / 60);
            $estimated_seconds_remainder = $estimated_seconds % 60;
            $estimated_time_formatted = sprintf('%02d:%02d', $estimated_minutes, $estimated_seconds_remainder);
            
            // Create API handler and stock updater
            $api_handler = new WC_SSPAA_API_Handler();
            $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 3000000, 0, 0, 0, 0, true); // 3 second delay, debug enabled
            
            // Perform the sync
            $stock_updater->update_all_products();
            
            // Release lock
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Completed immediate sync all products via AJAX from Products page');
            
            wp_send_json_success(array(
                'message' => "Successfully synced all {$total_products} products. Check the logs for details.",
                'total_products' => $total_products,
                'estimated_time' => $estimated_time_formatted
            ));
            
        } catch (Exception $e) {
            // Release lock on error
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Error in Products page AJAX sync all products: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing products: ' . $e->getMessage()));
        }
    }
}

// Initialize the class
new WC_SSPAA_Products_Page_Sync(); 