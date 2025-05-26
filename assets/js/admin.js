jQuery(document).ready(function($) {
    // console.log('WC Stock Sync Admin JS loaded');
    
    // Handle sync button click
    $(document).on('click', '.wc-sspaa-sync-stock', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $container = $button.closest('.wc-sspaa-sync-container');
        const $spinner = $container.find('.spinner');
        const $lastSync = $container.find('.wc-sspaa-last-sync');
        
        const productId = $button.data('product-id');
        const sku = $button.data('sku');
        
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

    // Stock Sync Status page functionality
    if ($('.wc-sspaa-settings-container').length > 0) {
        // Function to update current time displays
        function updateTimeDisplays() {
            // Get current UTC time
            const now = new Date();
            const utcString = now.toISOString().replace('T', ' ').substr(0, 19);
            
            // Get Sydney time (AEST/AEDT)
            const sydneyTime = new Date(now.toLocaleString('en-US', { timeZone: 'Australia/Sydney' }));
            const sydneyString = sydneyTime.toLocaleString('en-GB', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            }).replace(/(\d+)\/(\d+)\/(\d+)/, '$3-$2-$1');
            
            // Check if DST is active in Sydney
            const stdOffset = 10 * 60; // Standard offset in minutes (AEST = UTC+10:00)
            const sydneyOffset = sydneyTime.getTimezoneOffset() / -60 + (now.getTimezoneOffset() / 60);
            const isDST = sydneyOffset > 10;
            
            // Update the displays
            $('#wc-sspaa-current-utc-time').text('UTC: ' + utcString);
            $('#wc-sspaa-current-aest-time').text((isDST ? 'AEDT' : 'AEST') + ': ' + sydneyString);
            $('#wc-sspaa-dst-status').text('DST: ' + (isDST ? 'Active (UTC+11:00)' : 'Inactive (UTC+10:00)'));
        }
        
        // Function to fetch synchronization statistics
        function fetchSyncStats() {
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_get_stock_sync_stats',
                    nonce: wcSspaaAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Update products with SKUs
                        $('#wc-sspaa-products-with-skus').text(data.products_with_skus);
                        
                        // Update sync method (no longer batch-based)
                        $('#wc-sspaa-sync-method').text(data.sync_method);
                        
                        // Update next sync time
                        $('#wc-sspaa-next-sync-utc').text('UTC: ' + data.next_sync_utc);
                        $('#wc-sspaa-next-sync-sydney').text('Sydney: ' + data.next_sync_sydney);
                        
                        // Update last sync time
                        $('#wc-sspaa-last-sync-utc').text('UTC: ' + data.last_sync_utc);
                        $('#wc-sspaa-last-sync-sydney').text('Sydney: ' + data.last_sync_sydney);
                        
                        // Update sync button text with product count
                        const productCount = data.products_with_skus;
                        const buttonText = 'Sync All (' + productCount + ') Products Now';
                        $('#wc-sspaa-sync-all-products').text(buttonText);
                    } else {
                        showNotice('Error fetching synchronization statistics: ' + response.data.message, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showNotice('Network error occurred when fetching statistics: ' + textStatus, 'error');
                }
            });
        }
        
        // Function to update UTC time when sync time is changed
        function updateSyncTimeUTC() {
            const syncTime = $('#wc-sspaa-sync-time').val();
            if (!syncTime) return;
            
            // Get current time in Sydney
            const now = new Date();
            const sydneyNow = new Date(now.toLocaleString('en-US', { timeZone: 'Australia/Sydney' }));
            
            // Create a date with today's date and the entered time
            const [hours, minutes, seconds] = syncTime.split(':');
            const sydneyDate = new Date(sydneyNow);
            sydneyDate.setHours(parseInt(hours), parseInt(minutes), parseInt(seconds));
            
            // Convert to UTC
            const utcTime = new Date(sydneyDate.toLocaleString('en-US', { timeZone: 'UTC' }));
            const utcString = utcTime.toISOString().replace('T', ' ').substr(0, 19);
            
            // Update display
            $('#wc-sspaa-sync-time-utc').text('UTC: ' + utcString);
        }
        
        // Function to save sync time
        function saveSyncTime() {
            const syncTime = $('#wc-sspaa-sync-time').val();
            if (!syncTime) {
                showNotice('Please enter a valid time in HH:MM:SS format.', 'error');
                return;
            }
            
            // Save time via AJAX
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_save_sync_time',
                    nonce: wcSspaaAdmin.nonce,
                    sync_time: syncTime
                },
                beforeSend: function() {
                    $('#wc-sspaa-save-time').prop('disabled', true).text('Saving...');
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('Sync time saved successfully. Daily sync has been rescheduled.', 'success');
                        $('#wc-sspaa-sync-time-utc').text('UTC: ' + response.data.utc_time);
                        
                        // Refresh stats after a short delay to get the new sync times
                        setTimeout(fetchSyncStats, 1000);
                    } else {
                        showNotice('Error saving sync time: ' + response.data.message, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showNotice('Network error occurred when saving time: ' + textStatus, 'error');
                },
                complete: function() {
                    $('#wc-sspaa-save-time').prop('disabled', false).text('Save Time');
                }
            });
        }
        
        // Function to test API connection
        function testApiConnection() {
            // Test connection via AJAX
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_test_api_connection',
                    nonce: wcSspaaAdmin.nonce
                },
                beforeSend: function() {
                    // Clear previous test result
                    $('#wc-sspaa-api-test-result').removeClass('success failure').html('');
                    // Disable button during test
                    $('#wc-sspaa-test-api-connection').prop('disabled', true).text('Testing...');
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('#wc-sspaa-api-test-result')
                            .addClass('success')
                            .text('SUCCESS');
                    } else {
                        // Show error message
                        $('#wc-sspaa-api-test-result')
                            .addClass('failure')
                            .text('FAIL');
                            
                        // Show detailed error in notice
                        showNotice(response.data.message, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Show error message
                    $('#wc-sspaa-api-test-result')
                        .addClass('failure')
                        .text('FAIL');
                        
                    showNotice('Network error occurred when testing API connection: ' + textStatus, 'error');
                },
                complete: function() {
                    // Re-enable button
                    $('#wc-sspaa-test-api-connection').prop('disabled', false).text('Test API Connection');
                }
            });
        }
        
        // Function to sync all products immediately
        function syncAllProducts() {
            // Get current button text to restore later
            const originalButtonText = $('#wc-sspaa-sync-all-products').text();
            
            // Disable button and show progress
            $('#wc-sspaa-sync-all-products').prop('disabled', true).text('Syncing...');
            $('#wc-sspaa-sync-all-spinner').addClass('is-active');
            $('#wc-sspaa-sync-progress').show();
            
            // Clear any previous notices
            $('.wc-sspaa-notice').remove();
            
            // Send AJAX request
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_sync_all_products',
                    nonce: wcSspaaAdmin.nonce
                },
                timeout: 0, // No timeout - let it run as long as needed
                success: function(response) {
                    if (response.success) {
                        showNotice('✅ ' + response.data.message, 'success');
                        
                        // Refresh stats after sync completion to get updated button text
                        setTimeout(fetchSyncStats, 2000);
                    } else {
                        showNotice('❌ Error: ' + response.data.message, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    let errorMsg = 'Network error occurred: ' + textStatus;
                    if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errorMsg = jqXHR.responseJSON.data.message;
                    }
                    showNotice('❌ ' + errorMsg, 'error');
                    console.error('AJAX Error:', textStatus, errorThrown, jqXHR);
                },
                complete: function() {
                    // Re-enable button and hide progress
                    $('#wc-sspaa-sync-all-products').prop('disabled', false).text(originalButtonText);
                    $('#wc-sspaa-sync-all-spinner').removeClass('is-active');
                    $('#wc-sspaa-sync-progress').hide();
                }
            });
        }
        
        // Function to show notices
        function showNotice(message, type) {
            const $notice = $('<div class="wc-sspaa-notice ' + type + '">')
                .html(message)
                .prependTo('.wc-sspaa-settings-container')
                .delay(10000) // Show longer for sync completion messages
                .fadeOut(400, function() { $(this).remove(); });
        }
        
        // Bind event handlers
        $('#wc-sspaa-sync-time').on('change input', updateSyncTimeUTC);
        $('#wc-sspaa-save-time').on('click', saveSyncTime);
        $('#wc-sspaa-test-api-connection').on('click', testApiConnection);
        $('#wc-sspaa-sync-all-products').on('click', syncAllProducts);
        
        // Initialize
        updateTimeDisplays();
        fetchSyncStats();
        updateSyncTimeUTC();
        
        // Update time every second
        setInterval(updateTimeDisplays, 1000);
        
        // Refresh stats every 30 seconds
        setInterval(fetchSyncStats, 30000);
    }
}); 