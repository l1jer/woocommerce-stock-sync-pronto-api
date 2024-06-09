<?php
if (!defined('ABSPATH')) {
    exit;
}

// Add a new menu item under Products for Stock Sync Status
function wcap_add_stock_sync_status_menu()
{
    add_submenu_page(
        'edit.php?post_type=product',
        __('Stock Sync Status', 'woocommerce'),
        __('Stock Sync Status', 'woocommerce'),
        'manage_woocommerce',
        'wcap-stock-sync-status',
        'wcap_render_stock_sync_status_page'
    );
}
add_action('admin_menu', 'wcap_add_stock_sync_status_menu');

// Render the admin page for Stock Sync Status
function wcap_render_stock_sync_status_page()
{
    ?>
    <div class="wrap">
        <h1><?php _e('Stock Sync Status', 'woocommerce'); ?></h1>
        <div id="wcap-sync-status">
            <h2><?php _e('Sync Summary', 'woocommerce'); ?></h2>
            <textarea id="wcap-out-of-stock-summary" readonly style="width: 100%; height: 200px;"></textarea>
            <h2><?php _e('SKUs Not Found', 'woocommerce'); ?></h2>
            <textarea id="wcap-skus-not-found" readonly style="width: 100%; height: 200px;"></textarea>
        </div>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'wcap_get_sync_status'
            },
            success: function(response) {
                $('#wcap-out-of-stock-summary').val(response.out_of_stock_summary);
                $('#wcap-skus-not_found').val(response.skus_not_found);
            }
        });
    });
    </script>
    <?php
}

// Handle AJAX request to get sync status
function wcap_get_sync_status()
{
    // Fetch the sync status data from the database
    $out_of_stock_summary = get_option('wcap_out_of_stock_summary', 'No sync data available.');
    $skus_not_found = get_option('wcap_skus_not_found', 'No sync data available.');

    wp_send_json_success(
        array(
            'out_of_stock_summary' => $out_of_stock_summary,
            'skus_not_found' => $skus_not_found,
        )
    );
}
add_action('wp_ajax_wcap_get_sync_status', 'wcap_get_sync_status');
?>