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
                        
                        // Update total batches
                        $('#wc-sspaa-total-batches').text(data.total_batches);
                        
                        // Update next batch time
                        $('#wc-sspaa-next-batch-utc').text('UTC: ' + data.next_batch_utc);
                        $('#wc-sspaa-next-batch-sydney').text('Sydney: ' + data.next_batch_sydney);
                        
                        // Update last sync time
                        $('#wc-sspaa-last-sync-utc').text('UTC: ' + data.last_sync_utc);
                        $('#wc-sspaa-last-sync-sydney').text('Sydney: ' + data.last_sync_sydney);
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
                        showNotice('Sync time saved successfully. Batches have been rescheduled.', 'success');
                        $('#wc-sspaa-sync-time-utc').text('UTC: ' + response.data.utc_time);
                        
                        // Refresh stats after a short delay to get the new batch times
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
            const domain = $('#wc-sspaa-api-site-select').val();
            
            if (!domain) {
                showNotice('Please select a website to test the connection.', 'error');
                return;
            }
            
            // Clear previous test result
            $('#wc-sspaa-api-test-result').removeClass('success failure').html('');
            
            // Disable button during test
            $('#wc-sspaa-test-api-connection').prop('disabled', true).text('Testing...');
            
            // Test connection via AJAX
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_test_api_connection',
                    nonce: wcSspaaAdmin.nonce,
                    domain: domain
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
        
        // Function to update API credentials when domain selection changes
        function updateApiCredentials() {
            const domain = $('#wc-sspaa-api-site-select').val();
            
            if (!domain) {
                return;
            }
            
            // Save selected credentials via AJAX
            $.ajax({
                url: wcSspaaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_sspaa_save_api_credentials',
                    nonce: wcSspaaAdmin.nonce,
                    domain: domain
                },
                success: function(response) {
                    if (response.success) {
                        // Update credentials display
                        $('#wc-sspaa-api-username').text(response.data.username);
                        $('#wc-sspaa-api-password').text(response.data.password);
                        
                        // Show success message
                        showNotice('API credentials updated successfully', 'success');
                    } else {
                        showNotice('Error updating API credentials: ' + response.data.message, 'error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showNotice('Network error occurred when updating credentials: ' + textStatus, 'error');
                }
            });
        }
        
        // Function to show notices
        function showNotice(message, type) {
            const $notice = $('<div class="wc-sspaa-notice ' + type + '">')
                .text(message)
                .prependTo('.wc-sspaa-settings-container')
                .delay(5000)
                .fadeOut(400, function() { $(this).remove(); });
        }
        
        // Bind event handlers
        $('#wc-sspaa-sync-time').on('change input', updateSyncTimeUTC);
        $('#wc-sspaa-save-time').on('click', saveSyncTime);
        $('#wc-sspaa-test-api-connection').on('click', testApiConnection);
        $('#wc-sspaa-api-site-select').on('change', updateApiCredentials);
        
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