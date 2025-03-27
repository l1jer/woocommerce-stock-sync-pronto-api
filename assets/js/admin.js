/**
 * WooCommerce Stock Sync with Pronto Avenue API - Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize sync buttons
        initSyncButtons();
    });

    /**
     * Initialize sync buttons
     */
    function initSyncButtons() {
        $('.wc-sspaa-sync-btn').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const $container = $button.closest('.wc-sspaa-sync-data');
            const productId = $container.data('product-id');
            
            // Prevent multiple clicks
            if ($container.hasClass('wc-sspaa-syncing')) {
                return;
            }
            
            // Add syncing state
            $container.addClass('wc-sspaa-syncing');
            $button.text(wc_sspaa_params.syncing_text);
            
            // Send AJAX request
            $.ajax({
                url: wc_sspaa_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_sync_single_product',
                    product_id: productId,
                    nonce: wc_sspaa_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update sync time
                        $container.find('.wc-sspaa-sync-time').text(response.data.sync_time);
                        
                        // Handle obsolete stock
                        if (response.data.is_obsolete) {
                            if (!$container.find('.wc-sspaa-obsolete-stock').length) {
                                $container.find('.wc-sspaa-sync-time').after(
                                    $('<span class="wc-sspaa-obsolete-stock">Obsolete Stock</span>')
                                );
                            }
                        } else {
                            // Remove obsolete stock indicator if it exists
                            $container.find('.wc-sspaa-obsolete-stock').remove();
                        }
                        
                        // Show success feedback
                        $button.text(wc_sspaa_params.synced_text);
                        setTimeout(function() {
                            $button.text(wc_sspaa_params.sync_text);
                        }, 2000);
                    } else {
                        // Show error
                        $button.text(wc_sspaa_params.error_text);
                        console.error('Sync error:', response.data.message);
                        setTimeout(function() {
                            $button.text(wc_sspaa_params.sync_text);
                        }, 2000);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Show error
                    $button.text(wc_sspaa_params.error_text);
                    console.error('AJAX error:', textStatus, errorThrown);
                    setTimeout(function() {
                        $button.text(wc_sspaa_params.sync_text);
                    }, 2000);
                },
                complete: function() {
                    // Remove syncing state
                    $container.removeClass('wc-sspaa-syncing');
                }
            });
        });
    }

})(jQuery); 