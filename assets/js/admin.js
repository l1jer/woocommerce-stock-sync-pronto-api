jQuery(document).ready(function($) {
    console.log('WC Stock Sync Admin JS loaded');
    
    // Handle sync button click
    $(document).on('click', '.wc-sspaa-sync-stock', function(e) {
        e.preventDefault();
        console.log('Sync button clicked');
        
        const $button = $(this);
        const $container = $button.closest('.wc-sspaa-sync-container');
        const $spinner = $container.find('.spinner');
        const $lastSync = $container.find('.wc-sspaa-last-sync');
        
        const productId = $button.data('product-id');
        const sku = $button.data('sku');
        
        console.log('Product ID:', productId);
        console.log('SKU:', sku);
        
        // Disable button and show spinner
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        
        // Send AJAX request
        $.ajax({
            url: wcSspaaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_sspaa_sync_single_product',
                nonce: wcSspaaAdmin.nonce,
                product_id: productId,
                sku: sku
            },
            success: function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    // Update last sync time
                    $lastSync.text(response.data.last_sync);
                    
                    // Show success message
                    $container.append(
                        $('<div class="updated notice inline">')
                            .text('Stock updated successfully')
                            .delay(3000)
                            .fadeOut(400, function() { $(this).remove(); })
                    );
                } else {
                    // Show error message
                    $container.append(
                        $('<div class="error notice inline">')
                            .text(response.data.message || 'Error updating stock')
                            .delay(3000)
                            .fadeOut(400, function() { $(this).remove(); })
                    );
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', textStatus, errorThrown);
                // Show error message
                $container.append(
                    $('<div class="error notice inline">')
                        .text('Network error occurred: ' + textStatus)
                        .delay(3000)
                        .fadeOut(400, function() { $(this).remove(); })
                );
            },
            complete: function() {
                // Re-enable button and hide spinner
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
}); 