<?php
class WC_SSPAA_Stock_Sync_Time_Col
{
    public function __construct()
    {
        add_filter('manage_edit-product_columns', array($this, 'add_custom_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'display_sync_info_in_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'register_sortable_columns'));
        add_action('pre_get_posts', array($this, 'sort_custom_column'));
        add_action('admin_footer', array($this, 'add_sync_button_js'));
    }

    public function add_custom_columns($columns)
    {
        $reordered_columns = array();
        foreach ($columns as $key => $value) {
            if ($key == 'price') {
                $reordered_columns['avenue_stock_sync'] = __('Avenue Stock Sync', 'woocommerce');
            }
            $reordered_columns[$key] = $value;
        }
        return $reordered_columns;
    }

    public function display_sync_info_in_column($column, $post_id)
    {
        if ('avenue_stock_sync' === $column) {
            $last_sync = get_post_meta($post_id, '_wc_sspaa_last_sync', true);
            $sku = get_post_meta($post_id, '_sku', true);
            $is_obsolete = get_post_meta($post_id, '_wc_sspaa_is_obsolete', true) === 'yes';
            $stock_value = get_post_meta($post_id, '_stock', true);
            
            echo '<div class="wc-sspaa-sync-container" data-product-id="' . esc_attr($post_id) . '">';
            
            // Display last sync time
            if ($last_sync) {
                echo '<span class="wc-sspaa-sync-time" style="color: #999; white-space: nowrap; display: block; margin-bottom: 3px;">' . esc_html($last_sync) . '</span>';
                
                // Show stock value or obsolete status
                if ($is_obsolete) {
                    echo '<span class="wc-sspaa-stock-status" style="color: #dc3232; font-weight: bold; display: block; margin-bottom: 5px;">Obsolete Stock</span>';
                } else if ($stock_value !== '') {
                    echo '<span class="wc-sspaa-stock-value" style="color: #007cba; display: block; margin-bottom: 5px;">Stock: ' . esc_html($stock_value) . '</span>';
                }
            } else {
                echo '<span class="wc-sspaa-sync-time" style="color: #999; display: block; margin-bottom: 5px;">N/A</span>';
            }
            
            // Add sync button if product has SKU
            if (!empty($sku)) {
                echo '<button type="button" class="wc-sspaa-sync-product button button-small" data-product-id="' . esc_attr($post_id) . '">Sync Stock</button>';
                echo '<span class="wc-sspaa-sync-status" style="display:none; margin-top: 5px;"></span>';
            } else {
                echo '<span style="color: #dc3232; font-style: italic; font-size: 0.9em;">No SKU</span>';
            }
            
            echo '</div>';
        }
    }

    public function register_sortable_columns($columns)
    {
        $columns['avenue_stock_sync'] = 'avenue_stock_sync';
        return $columns;
    }

    public function sort_custom_column($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ('avenue_stock_sync' === $query->get('orderby')) {
            $query->set('meta_key', '_wc_sspaa_last_sync');
            $query->set('orderby', 'meta_value');
        }
    }
    
    /**
     * Add JavaScript for sync buttons to admin footer
     */
    public function add_sync_button_js()
    {
        // Only add JS on products screen
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-product') {
            return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Store the nonce here for security
            var syncNonce = '<?php echo wp_create_nonce('wc_sspaa_sync_product_nonce'); ?>';
            
            // Handle click on sync button
            $('.wc-sspaa-sync-product').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var container = button.closest('.wc-sspaa-sync-container');
                var productId = button.data('product-id');
                var statusEl = container.find('.wc-sspaa-sync-status');
                
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
                        security: syncNonce
                    },
                    success: function(response) {
                        console.log('Sync response:', response);
                        
                        if (response.success) {
                            // Update the sync time display
                            container.find('.wc-sspaa-sync-time').text(response.data.new_sync_time);
                            
                            // Remove any existing stock status/value elements
                            container.find('.wc-sspaa-stock-status, .wc-sspaa-stock-value').remove();
                            
                            // Add either obsolete status or stock value
                            if (response.data.is_obsolete) {
                                $('<span class="wc-sspaa-stock-status" style="color: #dc3232; font-weight: bold; display: block; margin-bottom: 5px;">Obsolete Stock</span>')
                                    .insertAfter(container.find('.wc-sspaa-sync-time'));
                            } else {
                                $('<span class="wc-sspaa-stock-value" style="color: #007cba; display: block; margin-bottom: 5px;">Stock: ' + response.data.stock_value + '</span>')
                                    .insertAfter(container.find('.wc-sspaa-sync-time'));
                            }
                            
                            // Display message
                            statusEl.html(response.data.message).css('color', '#46b450');
                            
                            // Reset button after 3 seconds
                            setTimeout(function() {
                                button.prop('disabled', false).text('Sync Stock');
                                statusEl.fadeOut(500);
                            }, 3000);
                        } else {
                            statusEl.html(response.data.message).css('color', '#dc3232');
                            button.prop('disabled', false).text('Sync Stock');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        statusEl.html('Error: ' + error).css('color', '#dc3232');
                        button.prop('disabled', false).text('Sync Stock');
                    }
                });
            });
        });
        </script>
        <style>
        .wc-sspaa-sync-container {
            position: relative;
        }
        .wc-sspaa-sync-product {
            margin-top: 3px !important;
        }
        .wc-sspaa-sync-status {
            font-size: 12px;
            display: block;
            margin-top: 5px;
        }
        .wc-sspaa-stock-value,
        .wc-sspaa-stock-status {
            font-size: 13px;
            line-height: 1.4;
        }
        </style>
        <?php
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>