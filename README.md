# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.4
**Stable tag:** 1.3.19
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels in sync with an external API effortlessly. 


## Changelog

### 1.3.19
* **REMOVED:** Removed the "Stock Sync Status" page and all associated functionality.
* **REMOVED:** Removed the admin menu item for "Stock Sync Status".
* **CLEANUP:** Ensured `wc_sspaa_sync_time` option is deleted on plugin deactivation and uninstallation.
* **ENHANCED:** Streamlined plugin by removing legacy status page components.

### 1.3.18
* **NEW:** Moved "Sync All Products" button from Stock Sync Status page to WooCommerce All Products page (Products > All Products)
* **NEW:** Added live countdown timer showing estimated time remaining based on 3-second delay per product
* **NEW:** Implemented real-time progress feedback with visual countdown display in HH:MM:SS format
* **ENHANCED:** Button dynamically displays total number of products with SKUs to be synchronised
* **ENHANCED:** Added confirmation dialog before starting sync operation to prevent accidental triggers
* **ENHANCED:** Improved user experience with better placement of sync functionality where products are managed
* **ENHANCED:** Maintains exact same sync functionality as previous implementation with identical AJAX handlers
* **ENHANCED:** Added automatic product count updates every 60 seconds to keep button text current
* **ENHANCED:** Enhanced visual feedback with spinner, progress container, and success/error notifications

### 1.3.17
* **FIXED:** Resolved critical logging issue where the entire log file was being overwritten on every log entry
* Optimised logging function to append entries first, then perform cleanup only when needed
* Implemented intelligent cleanup triggers: randomly (1 in 50 chance) or when file exceeds 5MB
* Prevented log loss during high-frequency logging operations (e.g., during product synchronisation)
* Improved logging performance by eliminating unnecessary file rewrites
* Added cleanup logging to track when old entries are purged from the log file

### 1.3.16
* **ENHANCED:** Updated "Sync All Products" button to dynamically display the total number of products with SKUs
* Button text now shows format: "Sync All (X) Products Now" where X is the current product count
* Improved user experience by providing clear indication of how many products will be synchronised
* Button text updates automatically when the statistics refresh every 30 seconds
* Enhanced visual feedback for administrators to understand the scope of synchronisation operations

### 1.3.15
* **FIXED:** Resolved PHP fatal error on Stock Sync Status page when accessing simplified static API credentials
* Removed dynamic website-based API credential selection system in favour of static credentials
* Simplified API credentials management to use single username/password pair from config.php
* Updated Stock Sync Status page to display static credentials without dropdown selection
* Cleaned up unused AJAX handlers and JavaScript functions related to dynamic credential selection
* Improved code maintainability by removing complex domain-based credential switching logic

### 1.3.13
* **NEW:** Implemented dedicated logging system using `wc-sspaa-debug.log` file within plugin directory
* **NEW:** Added immediate "Sync All Products Now" button in Stock Sync Status page for manual synchronisation
* Enhanced logging with proper file permissions and error handling for better debugging
* AJAX-based manual sync with real-time progress feedback and error handling
* Improved user experience with visual progress indicators and success/error notifications
* Maintains 3-second delay between API calls for rate limit compliance during manual sync
* Added locking mechanism to prevent multiple concurrent sync operations

### 1.3.12
* **MAJOR UPDATE:** Removed batch processing system and implemented sequential synchronisation
* All products are now processed in a single daily sync operation with 15-second delays between API calls
* Replaced multiple scheduled batch events with a single daily sync event
* Improved reliability by ensuring all products are processed in each sync cycle
* Enhanced logging with detailed progress tracking (processed count, success/failure rates)
* Updated admin interface to reflect sequential processing instead of batch-based approach
* Simplified cron scheduling - now uses single daily event instead of multiple batch events
* Added completion tracking with `wc_sspaa_last_sync_completion` option
* Improved API rate limit compliance with consistent 15-second delays between requests
* Updated Stock Sync Status page to show sync method and remove batch-related information

### 1.3.10
* Adjusted API call delay to 3 seconds (3,000,000 microseconds) for both scheduled cron sync and manual "Sync Product" button actions to further mitigate rate limiting issues. This provides a more conservative approach to API interaction.

### 1.3.9
* Resolved API rate limiting (HTTP 429) errors during stock synchronisation by ensuring a 2-second delay is strictly enforced after every API call attempt within batch processing, regardless of the API call's success or failure. This prevents cascading errors when the API's limit of "1 request per 2 seconds" is hit. Added more detailed logging around API calls and delays.

### 1.3.8
* Added automatic log retention: debug.log now only retains log entries from the last 4 days. Older entries are purged automatically before each new log write, ensuring the log file remains current and does not grow indefinitely.

### 1.3.7
* Fixed issue where not all products were synchronised if the product count exceeded the hardcoded batch limit. Batch scheduling is now fully dynamic and robust, ensuring every product (including all types and variations) is included in the daily sync cycle. Improved debug logging for batch scheduling and product coverage.

### 1.3.6
* Fixed bug where scheduled sync (cron) could use incorrect API credentials if HTTP_HOST was not set. Now uses the selected domain from Stock Sync Status page as fallback.
* Improved debug logging to show context, credentials, and API responses for both manual and scheduled syncs.

### 1.3.5
* Fixed PHP warning and deprecation notice in config.php by safely checking HTTP_HOST and handling null values for str_replace.

### 1.3.4
* Fixed PHP Warning and Deprecation notice in `includes/config.php` by safely checking `$_SERVER['HTTP_HOST']`

### 1.3.3
* Version number update for release management
* Fixed timezone issue with batch scheduling to correctly use Sydney time (AEST/AEDT) instead of UTC
* Improved logging for scheduled batch times showing both Sydney and UTC times

### 1.3.2
* Added Test API Connection button to Stock Sync Status page to verify selected API credentials and display SUCCESS or FAIL based on the response.

### 1.3.1
* Added dropdown menu in Stock Sync Status page to select from all available API usernames and passwords (displaying website URL instead of credentials). Selection updates dynamically via AJAX without needing to click save.

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

