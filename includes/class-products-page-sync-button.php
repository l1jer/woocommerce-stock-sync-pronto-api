<?php
/**
 * Products Page Sync Button
 *
 * Adds "Sync All Products" button to WooCommerce All Products page with countdown timer
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_SSPAA_Products_Page_Sync_Button {
    /**
     * Constructor
     */
    public function __construct() {
        // Add button to products page
        add_action('manage_posts_extra_tablenav', array($this, 'add_sync_button'), 10, 1);
        
        // Enqueue scripts and styles for products page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handler for sync all products (reuse existing one)
        add_action('wp_ajax_wc_sspaa_sync_all_products_with_timer', array($this, 'ajax_sync_all_products_with_timer'));
        
        // Add AJAX handler to get product count
        add_action('wp_ajax_wc_sspaa_get_product_count', array($this, 'ajax_get_product_count'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }

    /**
     * Add sync button to products page
     */
    public function add_sync_button($which) {
        global $typenow;
        
        // Only show on products page and on top tablenav
        if ($typenow !== 'product' || $which !== 'top') {
            return;
        }
        
        // Get product count for button text
        global $wpdb;
        $product_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' 
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''"
        );
        
        ?>
        <div class="alignleft actions wc-sspaa-sync-container">
            <button type="button" id="wc-sspaa-sync-all-products-page" class="button button-primary" data-product-count="<?php echo esc_attr($product_count); ?>">
                <?php echo sprintf(__('Sync All (%d) Products', 'woocommerce'), $product_count); ?>
            </button>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?action=wc_sspaa_manual_trigger_scheduled_sync'), 'wc_sspaa_manual_trigger'); ?>" 
               class="button button-secondary" 
               title="Test the scheduled sync functionality manually without waiting for the cron">
                <?php _e('Test Scheduled Sync', 'woocommerce'); ?>
            </a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?action=wc_sspaa_clear_sync_lock'), 'wc_sspaa_clear_lock_nonce'); ?>"
               class="button button-caution" 
               title="Clear the active sync lock if a sync process appears to be stuck. Use with caution." 
               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear the active sync lock? Only do this if a sync appears to be stuck.', 'woocommerce')); ?>');">
                <?php _e('Clear Active Sync Lock', 'woocommerce'); ?>
            </a>
            <span class="spinner" id="wc-sspaa-sync-spinner"></span>
            <div id="wc-sspaa-countdown-container" style="display: none;">
                <div id="wc-sspaa-countdown-timer">
                    <strong><?php _e('Estimated time remaining:', 'woocommerce'); ?></strong>
                    <span id="wc-sspaa-countdown-display">00:00:00</span>
                </div>
                <div id="wc-sspaa-progress-info">
                    <span id="wc-sspaa-progress-text"><?php _e('Preparing sync...', 'woocommerce'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        global $typenow;
        
        // Only load on products page
        if ($typenow !== 'product' || $hook !== 'edit.php') {
            return;
        }
        
        $js_path = '../assets/js/products-page-sync.js';
        $js_url = plugins_url($js_path, __FILE__);
        $js_file = plugin_dir_path(__FILE__) . $js_path;
        
        // Create the JS file if it doesn't exist
        if (!file_exists($js_file)) {
            $this->create_products_page_js();
        }
        
        wp_enqueue_script(
            'wc-sspaa-products-page-sync',
            $js_url,
            array('jquery'),
            filemtime($js_file),
            true
        );
        
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sspaa_products_page_nonce'),
            'apiDelay' => 5000, // 5 seconds delay for countdown timer estimation
            'strings' => array(
                'syncing' => __('Syncing...', 'woocommerce'),
                'syncComplete' => __('Sync Complete!', 'woocommerce'),
                'syncError' => __('Sync Error', 'woocommerce'),
                'preparingSync' => __('Preparing sync...', 'woocommerce'),
                'syncingProduct' => __('Syncing product %d of %d...', 'woocommerce'),
                'estimatedTimeRemaining' => __('Estimated time remaining:', 'woocommerce'),
                'syncAllProducts' => __('Sync All (%d) Products', 'woocommerce')
            )
        );
        
        wp_localize_script('wc-sspaa-products-page-sync', 'wcSspaaProductsPage', $script_data);
        
        // Add inline CSS for the countdown timer and button styling
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-sspaa-sync-container {
                margin-right: 10px;
            }
            #wc-sspaa-sync-all-products-page {
                font-size: 13px;
                padding: 6px 12px;
                height: auto;
                margin-right: 8px;
            }
            .wc-sspaa-sync-container .button-secondary {
                font-size: 13px;
                padding: 6px 12px;
                height: auto;
                margin-right: 8px;
            }
            #wc-sspaa-sync-all-products-page:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            #wc-sspaa-sync-spinner.is-active {
                visibility: visible;
                display: inline-block;
                margin-left: 8px;
            }
            #wc-sspaa-countdown-container {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 4px;
                padding: 10px;
                margin-top: 10px;
                color: #856404;
                max-width: 400px;
            }
            #wc-sspaa-countdown-timer {
                font-size: 14px;
                margin-bottom: 5px;
            }
            #wc-sspaa-countdown-display {
                font-family: monospace;
                font-size: 16px;
                font-weight: bold;
                color: #d63384;
            }
            #wc-sspaa-progress-info {
                font-size: 12px;
                color: #6c757d;
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
     * Create the products page JavaScript file
     */
    private function create_products_page_js() {
        $js_content = 'jQuery(document).ready(function($) {
    let countdownInterval;
    let syncStartTime;
    let totalProducts = 0;
    let currentProduct = 0;

    // Handle sync button click
    $("#wc-sspaa-sync-all-products-page").on("click", function(e) {
        e.preventDefault();
        
        const $button = $(this);
        totalProducts = parseInt($button.data("product-count"));
        
        if (totalProducts === 0) {
            showNotice("No products with SKUs found to sync.", "error");
            return;
        }
        
        // Confirm action
        if (!confirm("This will sync all " + totalProducts + " products. This may take a while. Continue?")) {
            return;
        }
        
        startSync();
    });

    function startSync() {
        const $button = $("#wc-sspaa-sync-all-products-page");
        const $spinner = $("#wc-sspaa-sync-spinner");
        const $countdownContainer = $("#wc-sspaa-countdown-container");
        
        // Disable button and show spinner
        $button.prop("disabled", true).text(wcSspaaProductsPage.strings.syncing);
        $spinner.addClass("is-active");
        $countdownContainer.show();
        
        // Clear any previous notices
        $(".wc-sspaa-notice").remove();
        
        // Record start time
        syncStartTime = Date.now();
        currentProduct = 0;
        
        // Start countdown timer
        startCountdownTimer();
        
        // Send AJAX request
        $.ajax({
            url: wcSspaaProductsPage.ajaxUrl,
            type: "POST",
            data: {
                action: "wc_sspaa_sync_all_products_with_timer",
                nonce: wcSspaaProductsPage.nonce
            },
            timeout: 0, // No timeout - let it run as long as needed
            success: function(response) {
                if (response.success) {
                    showNotice("✅ " + response.data.message, "success");
                    
                    // Update button text with new product count if provided
                    if (response.data.total_products) {
                        const newButtonText = wcSspaaProductsPage.strings.syncAllProducts.replace("%d", response.data.total_products);
                        $button.data("product-count", response.data.total_products);
                    }
                } else {
                    showNotice("❌ Error: " + response.data.message, "error");
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                let errorMsg = "Network error occurred: " + textStatus;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMsg = jqXHR.responseJSON.data.message;
                }
                showNotice("❌ " + errorMsg, "error");
                console.error("AJAX Error:", textStatus, errorThrown, jqXHR);
            },
            complete: function() {
                // Stop countdown and reset UI
                stopCountdownTimer();
                
                // Re-enable button
                const productCount = $button.data("product-count");
                const buttonText = wcSspaaProductsPage.strings.syncAllProducts.replace("%d", productCount);
                $button.prop("disabled", false).text(buttonText);
                $spinner.removeClass("is-active");
                $countdownContainer.hide();
            }
        });
    }

    function startCountdownTimer() {
        updateCountdownDisplay();
        countdownInterval = setInterval(updateCountdownDisplay, 1000);
    }

    function stopCountdownTimer() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    function updateCountdownDisplay() {
        const elapsedTime = Date.now() - syncStartTime;
        const elapsedSeconds = Math.floor(elapsedTime / 1000);
        
        // Calculate estimated total time (3 seconds per product)
        const estimatedTotalSeconds = totalProducts * 3;
        
        // Calculate remaining time
        const remainingSeconds = Math.max(0, estimatedTotalSeconds - elapsedSeconds);
        
        // Format time as HH:MM:SS
        const hours = Math.floor(remainingSeconds / 3600);
        const minutes = Math.floor((remainingSeconds % 3600) / 60);
        const seconds = remainingSeconds % 60;
        
        const timeString = String(hours).padStart(2, "0") + ":" + 
                          String(minutes).padStart(2, "0") + ":" + 
                          String(seconds).padStart(2, "0");
        
        $("#wc-sspaa-countdown-display").text(timeString);
        
        // Update progress text
        const progressText = wcSspaaProductsPage.strings.preparingSync;
        $("#wc-sspaa-progress-text").text(progressText);
    }

    function showNotice(message, type) {
        const $notice = $("<div class=\"wc-sspaa-notice " + type + "\">")
            .html(message)
            .insertAfter(".wp-header-end")
            .delay(8000)
            .fadeOut(400, function() { $(this).remove(); });
    }

    // Update product count periodically
    function updateProductCount() {
        $.ajax({
            url: wcSspaaProductsPage.ajaxUrl,
            type: "POST",
            data: {
                action: "wc_sspaa_get_product_count",
                nonce: wcSspaaProductsPage.nonce
            },
            success: function(response) {
                if (response.success && response.data.count) {
                    const $button = $("#wc-sspaa-sync-all-products-page");
                    const newCount = response.data.count;
                    const newButtonText = wcSspaaProductsPage.strings.syncAllProducts.replace("%d", newCount);
                    $button.data("product-count", newCount).text(newButtonText);
                }
            }
        });
    }

    // Update product count every 60 seconds
    setInterval(updateProductCount, 60000);
});';

        $js_file = plugin_dir_path(__FILE__) . '../assets/js/products-page-sync.js';
        
        // Ensure the assets/js directory exists
        $js_dir = dirname($js_file);
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        file_put_contents($js_file, $js_content);
    }

    /**
     * AJAX handler to sync all products with timer support (reuses existing functionality)
     */
    public function ajax_sync_all_products_with_timer() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_products_page_nonce', 'nonce', false)) {
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
            
            // Create API handler and stock updater
            $api_handler = new WC_SSPAA_API_Handler();
            $stock_updater = new WC_SSPAA_Stock_Updater($api_handler, 5000000, 0, 0, 0, 0, true); // 5 second delay, debug enabled
            
            // Perform the sync
            $stock_updater->update_all_products();
            
            // Release lock
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Completed immediate sync all products via AJAX from Products page');
        
            wp_send_json_success(array(
                'message' => "Successfully synced all {$total_products} products. Check the logs for details.",
                'total_products' => $total_products
            ));
            
        } catch (Exception $e) {
            // Release lock on error
            delete_transient($lock_transient_key);
            
            wc_sspaa_log('Error in Products page AJAX sync all products: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error syncing products: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX handler to get current product count
     */
    public function ajax_get_product_count() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wc_sspaa_products_page_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        global $wpdb;
        $product_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' 
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''"
        );
        
        wp_send_json_success(array('count' => (int)$product_count));
    }

    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        global $typenow;
        
        if ($typenow !== 'product') {
            return;
        }
        
        // Show manual trigger success message
        if (isset($_GET['manual_sync_triggered']) && $_GET['manual_sync_triggered'] == '1') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Manual Scheduled Sync Triggered:</strong> The scheduled sync test has been initiated. Check the debug log for detailed information about the sync process.</p>';
            echo '</div>';
        }
    }
}

// Initialize the class
new WC_SSPAA_Products_Page_Sync_Button(); 