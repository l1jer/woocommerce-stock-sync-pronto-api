# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.4
**Stable tag:** 1.1.6
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels in sync with an external API effortlessly. Here's what it does:

* Fetches all product data from the external API and updates WooCommerce stock levels.
* Handles large product catalogs and respects API rate limits with batch processing.
* Dynamically calculates and schedules batches based on your total product count.
* Allows configuration of sync start time in the Australia/Sydney timezone.
* Updates stock levels at user-configurable times.
* Logs detailed debug information for troubleshooting.
* Identifies obsolete stock items.

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
5. Configure the sync start time under Products → Stock Sync Status.

## Frequently Asked Questions

### How does this plugin update stock levels?

The plugin fetches product data from the external API and processes it in batches to update the stock levels of WooCommerce products.

### How often does the plugin fetch data from the API?

The plugin schedules batch processing based on your configured start time. By default, it starts at 00:59 AM (Australia/Sydney timezone) and processes batches at 30-minute intervals.

### How does the batch processing work?

The plugin counts your total products with SKUs and divides them into batches of 15 products each. These batches are processed at 30-minute intervals to respect API rate limits and server execution time constraints.

### Can I customize when the sync process starts?

Yes, you can set a custom start time under Products → Stock Sync Status. The time you enter is interpreted as Australia/Sydney timezone and automatically converted to UTC for scheduling.

### What happens if a product's stock quantity is negative?

If a product's stock quantity is negative, the plugin updates the stock quantity to 0 to prevent negative stock levels in WooCommerce.

### How do I know if a product is obsolete?

If the API returns a response indicating no product data ({"products":[],"count":0,"pages":0}), the plugin will display "Obsolete Stock" in red under the product's last sync time in the products list.

## Changelog

### 1.1.6
* Implemented dynamic batching based on product quantity
* Added configuration option to set sync start time in Australia/Sydney timezone
* Created a Stock Sync Status page under Products admin menu
* Added "Obsolete Stock" indicator for products with no API data
* Improved batch scheduling with 30-minute intervals

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
