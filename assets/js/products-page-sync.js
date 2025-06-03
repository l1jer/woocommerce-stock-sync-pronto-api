jQuery(document).ready(function($) {
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
        const estimatedTotalSeconds = totalProducts * 5;
        
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
        const $notice = $('<div class="wc-sspaa-notice ' + type + '">')
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
}); 