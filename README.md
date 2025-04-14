# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.4
**Stable tag:** 1.3.5
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels in sync with an external API effortlessly. Here's what it does:

* Fetches all product data from the external API and updates WooCommerce stock levels.
* Handles large product catalogs and respects API rate limits with batch processing.
* Updates stock levels daily at 2 AM.
* Logs detailed debug information for troubleshooting.

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

## Changelog

### 1.3.5
* Version number update for release management

### 1.3.4
* Added support for marking products as "Obsolete" in red text when API returns empty results
* Improved handling of obsolete products in parent-child relationships for variable products

### 1.3.3
* Fixed timezone issue with batch scheduling to correctly use Sydney time (AEST/AEDT) instead of UTC
* Improved logging for scheduled batch times showing both Sydney and UTC times

### 1.3.0
* Added dynamic API credentials management based on website context
* Added dropdown menu for API credential selection in the Stock Sync Status page
* Added Test API Connection button to verify credentials
* Display active API credentials on the settings page
* API credentials automatically determined based on current website domain

### 1.2.7
* Remove additional logs.
* Remove unnecessary debug logs from Stock Sync Time Column class.

### 1.2.6
* Present the last synchronisation completion date and time in both UTC and Sydney time.

### 1.2.5
* Display the date and time of the next scheduled batch in both UTC and Sydney time.

### 1.2.4
* Indicate the total number of batches involved in a complete stock synchronisation cycle.

### 1.2.3
* Show the total number of products that have SKUs.

### 1.2.2
* Implement an option to update the start time for the daily stock synchronisation (in Sydney time) with a Save Time button (formatted as hh:mm:ss), while also displaying the corresponding UTC date and time in a read-only format next to it.

### 1.2.1
* Display the current UTC time alongside the current AEST time, accounting for both daylight saving and non-daylight saving periods, also indicating if it is daylight saving or non-daylight saving period.

### 1.2.0
* Added new "Stock Sync Status" page under the Products tab
* Added feature to display current UTC and AEST/AEDT time with DST status
* Added option to update daily stock synchronization start time
* Added display of total products with SKUs and total batches
* Added display of next scheduled batch and last sync completion times
* Improved batch scheduling system to use configurable start time

### 1.1.8
* Fixed dynamic batch calculation to properly account for all product types, including variable products
* Added proper handling of parent-child relationships for variable products
* Improved SQL queries for better performance and accuracy
* Enhanced logging to provide more detailed information about product processing

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
