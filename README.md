# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.7.2
**WC tested up to:** 9.6
**Stable tag:** 1.1.8
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels in sync with an external API effortlessly. Here's what it does:

* Fetches all product data from the external API and updates WooCommerce stock levels.
* Handles large product catalogs and respects API rate limits with batch processing.
* Updates stock levels daily at 1 AM except on weekends.
* Logs detailed debug information for troubleshooting.
* Individual product sync buttons for per-product synchronization.
* Shows current stock level and marks obsolete products automatically.
* Bulk sync button on products listing page with real-time progress tracking.

## Installation

1. Download and install this plugin from the Plugins -> Add New admin screen.
2. Create a `config.php` file in the plugin directory with the following content:
    ```php
    <?php
    define('WCAP_API_URL', 'https://example.com/api/json/product/v4.json');
    define('WCAP_API_USERNAME', 'jerryjerryjerry');
    define('WCAP_API_PASSWORD', 'passpasspass');
    ?>
    ```
3. Add `config.php` to your `.gitignore` file to exclude it from version control.
4. Activate the plugin through the 'Plugins' screen in WordPress.

## Frequently Asked Questions

### How does this plugin update stock levels?

The plugin fetches product data from the external API and processes it in batches to update the stock levels of WooCommerce products.

### How often does the plugin fetch data from the API?

The plugin is scheduled to fetch data from the API daily at 1 AM, except on weekends (Saturday and Sunday).

### How does the batch processing work?

The plugin dynamically counts the total number of products and processes them in batches of 15 products each. This ensures that the server execution time limits are not exceeded while maintaining efficient processing.

### What happens if a product's stock quantity is negative?

If a product's stock quantity is negative, the plugin updates the stock quantity to 0 to prevent negative stock levels in WooCommerce.

### Can I manually sync individual products?

Yes, each product now has its own "Sync Stock" button in the "Avenue Stock Sync" column. This allows you to sync individual products on demand without having to run a full synchronization.

### How do I know if a product is obsolete?

Products not found in the API will be marked as "Obsolete Stock" and have their stock set to 0 automatically. This helps identify products that are no longer available from the supplier.

## Changelog

### 1.1.8
* Added bulk sync button on products listing page with real-time progress tracking
* Added WooCommerce HPOS (High-Performance Order Storage) compatibility
* Added option to acknowledge real cron job usage to suppress WP-Cron warnings
* Added individual product sync button on product edit page in inventory section
* Improved scheduling stability for the 1AM daily sync
* Enhanced cron monitoring with detailed diagnostics and status checks
* Added multiple UI improvements for better feedback during sync operations
* Fixed PHP syntax error in string formatting for batch processing

### 1.1.7
* Replaced global sync button with individual sync buttons for each product
* Added display of current stock quantity below the sync date/time
* Added "Obsolete Stock" indicator for products not found in API
* Set stock to 0 for obsolete products automatically
* Improved visual feedback during sync process
* Enhanced logging for better troubleshooting
* Fixed PHP syntax errors in string interpolation

### 1.1.6
* Replaced scheduled batch processing with dynamic product counting and batch processing
* Added manual sync button to product listing page
* Changed schedule to run once daily at 1 AM except on weekends (Saturday and Sunday)
* Improved overall performance and reduced server load
* Tested with PHP 8.2+, WordPress 6.7.2, and WooCommerce 9.6+

### 1.1.5
* Ensured batch processes are not scheduled multiple times if they already exist.
* Disable debug error message

### 1.1.4
* Scheduled batch processes at specific times of the day when the plugin is activated.
* Excluded specific SKUs from the stock sync process.
* Fixed the issue with duplicate dates displayed in the Avenue Stock Sync column.
* Enhanced logging to include timestamps and detailed information.

### 1.1.3
* Updated prefix from WCAP to wc_sspaa or WC_SSPAA.
* Ensured batches are processed correctly without long delays.
* Added debug file to log errors.
* Moved sortable column functionality to a separate file.

### 1.1.2
* Fixed the issue with sorting functionality for the "Avenue Stock Sync" column.
* Updated the changelog to reflect changes.

### 1.1.1
* Added get_product_data method to the API handler class.
* Set stock quantity to 0 if it is negative.
* Positioned "Avenue Stock Sync" column between "Stock" and "Price."
* Changed "No sync info" to "N/A."

### 1.1
* Added a new column "Avenue Stock Sync" to display the last sync date and time.
* Changed "No sync info" to "N/A".
* Ensured stock quantity is updated to 0 if it is negative.
* Improved column width handling to fit the content dynamically.
* Removed settings page as there are no options to configure anymore.
* Updated the cron schedule to run once daily at 2 AM.
* Added uninstall.php file to clean up options on uninstall.
* Added config.php file to store API credentials securely.

### 1.0.4
* Improved the scheduling and execution of batch processes to respect server execution time limits and API rate limits.
* Added detailed logging for better debugging and tracking of the plugin's operations.

### 1.0.3
* Implemented batch processing with delays to handle large product catalogs and avoid exceeding server execution time limits.
* Enhanced error handling and logging for better troubleshooting.

### 1.0.2
* Changed to fetch all product data from the external API in a single request and store it locally as a JSON file.
* Updated the stock levels using the locally stored JSON data to improve performance and reduce API calls.

### 1.0.1
* Initial implementation of fetching product data using API requests for each product.
* Scheduled cron jobs to update stock levels periodically.

### 1.0
* Initial release.
* Fetch all product data from the API and store it locally.
* Process stock updates from local JSON data in manageable batches.
* Log detailed debug information for troubleshooting.
* Handle API rate limits and server execution time constraints.
