jQuery(document).ready(function($) {
    // Variables for countdown timer
    let countdownInterval;
    let syncStartTime;
    let totalProducts = 0;
    let estimatedDuration = 0;
    
    // Function to format time as MM:SS
    function formatTime(seconds) {
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        return String(minutes).padStart(2, '0') + ':' + String(remainingSeconds).padStart(2, '0');
    }
    
    // Function to update countdown timer
    function updateCountdown() {
        if (!syncStartTime || !estimatedDuration) {
            return;
        }
        
        const now = Date.now();
        const elapsed = Math.floor((now - syncStartTime) / 1000);
        const remaining = Math.max(0, estimatedDuration - elapsed);
        
        // Update countdown display
        $('#wc-sspaa-countdown-timer').text(formatTime(remaining));
        
        // Update progress bar
        const progress = Math.min(100, (elapsed / estimatedDuration) * 100);
        $('#wc-sspaa-sync-progress-fill').css('width', progress + '%');
        
        // Update current product estimate (rough calculation)
        const currentProduct = Math.min(totalProducts, Math.floor(elapsed / 3)); // 3 seconds per product
        $('#wc-sspaa-current-product').text(currentProduct);
        
        // Stop countdown when time is up
        if (remaining <= 0) {
            clearInterval(countdownInterval);
            $('#wc-sspaa-countdown-timer').text('00:00');
            $('#wc-sspaa-sync-progress-fill').css('width', '100%');
        }
    }
    
    // Function to start countdown timer
    function startCountdown(estimatedSeconds, productCount) {
        syncStartTime = Date.now();
        estimatedDuration = estimatedSeconds;
        totalProducts = productCount;
        
        // Show timer container
        $('#wc-sspaa-sync-timer-container').show();
        
        // Reset progress
        $('#wc-sspaa-sync-progress-fill').css('width', '0%');
        $('#wc-sspaa-current-product').text('0');
        $('#wc-sspaa-countdown-timer').text(formatTime(estimatedSeconds));
        
        // Start countdown interval
        countdownInterval = setInterval(updateCountdown, 1000);
    }
    
    // Function to stop countdown timer
    function stopCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        
        // Hide timer container
        $('#wc-sspaa-sync-timer-container').hide();
        
        // Reset variables
        syncStartTime = null;
        estimatedDuration = 0;
        totalProducts = 0;
    }
    
    // Function to show notice
    function showNotice(message, type) {
        const $notice = $('<div class="wc-sspaa-notice ' + type + '">')
            .html(message)
            .appendTo('#wc-sspaa-sync-notices');
        
        // Auto-remove notice after 10 seconds
        setTimeout(function() {
            $notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 10000);
    }
    
    // Function to sync all products with timer
    function syncAllProductsWithTimer() {
        // Get current button text and product count
        const $button = $('#wc-sspaa-sync-all-products-timer');
        const originalButtonText = $button.text();
        const productCount = parseInt($('#wc-sspaa-total-products').text()) || 0;
        
        // Calculate estimated time (3 seconds per product)
        const estimatedSeconds = productCount * 3;
        
        // Clear any previous notices
        $('#wc-sspaa-sync-notices').empty();
        
        // Disable button and show spinner
        $button.prop('disabled', true).text('Syncing...');
        $('#wc-sspaa-sync-spinner').addClass('is-active');
        
        // Start countdown timer
        startCountdown(estimatedSeconds, productCount);
        
        // Send AJAX request
        $.ajax({
            url: wcSspaaProductsSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_sspaa_sync_all_products_with_timer',
                nonce: wcSspaaProductsSync.nonce
            },
            timeout: 0, // No timeout - let it run as long as needed
            success: function(response) {
                if (response.success) {
                    showNotice('✅ ' + response.data.message, 'success');
                    
                    // Complete the progress bar
                    $('#wc-sspaa-sync-progress-fill').css('width', '100%');
                    $('#wc-sspaa-current-product').text(response.data.total_products);
                    $('#wc-sspaa-countdown-timer').text('00:00');
                    
                    // Hide timer after a short delay
                    setTimeout(function() {
                        stopCountdown();
                    }, 3000);
                    
                } else {
                    showNotice('❌ Error: ' + response.data.message, 'error');
                    stopCountdown();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                let errorMsg = 'Network error occurred: ' + textStatus;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMsg = jqXHR.responseJSON.data.message;
                }
                showNotice('❌ ' + errorMsg, 'error');
                stopCountdown();
                console.error('AJAX Error:', textStatus, errorThrown, jqXHR);
            },
            complete: function() {
                // Re-enable button and hide spinner
                $button.prop('disabled', false).text(originalButtonText);
                $('#wc-sspaa-sync-spinner').removeClass('is-active');
            }
        });
    }
    
    // Bind click event to sync button
    $(document).on('click', '#wc-sspaa-sync-all-products-timer', function(e) {
        e.preventDefault();
        
        // Confirm before starting sync
        if (confirm('This will sync all products with the external API. This process may take several minutes. Do you want to continue?')) {
            syncAllProductsWithTimer();
        }
    });
    
    // Clean up on page unload
    $(window).on('beforeunload', function() {
        stopCountdown();
    });
}); 