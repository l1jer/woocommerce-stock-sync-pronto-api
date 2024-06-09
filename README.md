# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.4
**Stable tag:** 1.1.3
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels in sync with an external API effortlessly. Here’s what it does:

* Fetches all product data from the external API and updates WooCommerce stock levels.
* Handles large product catalogs and respects API rate limits with batch processing.
* Updates stock levels daily at 2 AM.
* Logs detailed debug information for troubleshooting.
* Adds a Stock Sync Status page to view sync status and details about out-of-stock products and SKUs not found.

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

The plugin is scheduled to fetch data from the API daily at 2 AM.

### How does the batch processing work?

The plugin processes stock updates in batches, updating a specified number of products in each batch. This ensures that the server execution time limits are not exceeded. If the execution time limit is reached, the plugin schedules the next batch to continue processing.

### What happens if a product's stock quantity is negative?

If a product's stock quantity is negative, the plugin updates the stock quantity to 0 to prevent negative stock levels in WooCommerce.

### What is the Stock Sync Status page?

The Stock Sync Status page displays a summary of out-of-stock products and SKUs not found during the last sync process.

## Changelog

### 1.1.3
* Added "Stock Sync Status" page to display error logs with category filters.
* Modified batch event scheduling to align with the next server CRON job.
* Enhanced plugin to update stock levels for product variations as well.
* Fixed the issue where stock quantities were set to negative values.
* Adjusted column width for "Avenue Stock Sync" to fit content.

### 1.1.2
* Fixed the issue with sorting functionality for the "Avenue Stock Sync" column.
* Updated the changelog to reflect changes.

### 1.1.1
* Added get_product_data method to the API handler class.
* Set stock quantity to 0 if it is negative.
* Positioned "Avenue Stock Sync" column between "Stock" and "Price."

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

## Upgrade Notice

### 1.1.3
Added "Stock Sync Status" page to display error logs with category filters. Modified batch event scheduling to align with the next server CRON job. Enhanced plugin to update stock levels for product variations as well. Fixed the issue where stock quantities were set to negative values. Adjusted column width for "Avenue Stock Sync" to fit content.

### 1.1.2
Fixed the issue with sorting functionality for the "Avenue Stock Sync" column.

### 1.1.1
Added get_product_data method to the API handler class. Set stock quantity to 0 if it is negative. Positioned "Avenue Stock Sync" column between "Stock" and "Price."

### 1.1
Added a new column "Avenue Stock Sync" to display the last sync date and time. Changed "No sync info" to "N/A". Ensured stock quantity is updated to 0 if it is negative. Improved column width handling to fit the content dynamically. Removed settings page as there are no options to configure anymore. Updated the cron schedule to run once daily at 2 AM. Added uninstall.php file to clean up options on uninstall. Added config.php file to store API credentials securely.

### 1.0.4
Improved scheduling and execution of batch processes to respect server execution time limits and API rate limits.

### 1.0.3
Implemented batch processing with delays to handle large product catalogs and avoid exceeding server execution time limits.

### 1.0.2
Changed to fetch all product data from the external API in a single request and store it locally as a JSON file.

### 1.0.1
Initial implementation of fetching product data using API requests for each product and scheduled cron jobs to update stock levels periodically.

### 1.0
Initial release.
