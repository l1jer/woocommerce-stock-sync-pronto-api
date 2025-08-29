# WooCommerce Stock Sync with Pronto Avenue API

**Contributors:** Jerry Li
**Tags:** WooCommerce, stock, API, cron
**Requires at least:** 3.6
**Requires PHP:** 5.3
**Tested up to:** 6.4
**Stable tag:** 1.4.2
**License:** GPLv2
**License URI:** [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Synchronize your WooCommerce product stock levels and GTINs with an external API seamlessly and efficiently.

## Description

WooCommerce Stock Sync with Pronto Avenue API helps you keep your WooCommerce store's product stock levels and GTINs in sync with an external API effortlessly. The plugin now includes comprehensive GTIN synchronisation capabilities, automatically populating missing GTINs from the API's APN field. 


## Changelog

### 1.4.2
* **NEW:** Implemented dual warehouse stock calculation for SkyWatcher Australia domain (skywatcheraustralia.com.au)
* **ENHANCED:** Added domain-specific stock logic that combines warehouse:1 and warehouse:AB quantities for SkyWatcher Australia
* **ENHANCED:** Negative stock quantities are automatically converted to zero before calculation for both warehouses
* **ENHANCED:** All other domains continue using single warehouse:1 logic without any changes
* **ENHANCED:** Added comprehensive logging for dual warehouse calculations showing individual and combined stock values
* **ENHANCED:** Implemented proper domain detection within Stock Updater class for dynamic warehouse logic switching
* **CALCULATION:** Final stock = max(0, warehouse:1) + max(0, warehouse:AB) for SkyWatcher Australia only
* **LOGGING:** Detailed warehouse-specific logging distinguishes between single and dual warehouse calculations
* **BACKWARD COMPATIBLE:** Maintains existing functionality for all other domains while enhancing SkyWatcher Australia specifically

### 1.4.1
* **ENHANCED:** Improved logging system with daily log file management
* **NEW:** Created dedicated `logs/` directory for better organisation
* **NEW:** Daily log files (e.g., `wc-sspaa-2024-01-15.log`) for easier tracking
* **NEW:** Automatic cleanup of log files older than 14 days
* **NEW:** Added log file information button to admin interface
* **NEW:** Helper function `wc_sspaa_get_log_info()` for log management
* **ENHANCED:** Better log file security with `.htaccess` protection
* **ENHANCED:** Improved log file organisation and retention management
* **ENHANCED:** All classes now use daily log file structure consistently

### 1.4.0
* **NEW:** Added comprehensive GTIN synchronisation functionality
* **NEW:** Created `WC_SSPAA_GTIN_Updater` class to handle missing GTIN population from API APN field
* **NEW:** Added GTIN sync buttons to Products admin page: "Sync Missing GTINs" and "GTIN Stats"
* **NEW:** Integrated GTIN sync capability into existing stock updater (optional parameter)
* **NEW:** Added AJAX handlers for GTIN sync operations with proper security checks
* **NEW:** Added GTIN statistics functionality showing completion rates and missing GTINs count
* **NEW:** Added manual GTIN sync action with admin notices and error handling
* **NEW:** Enhanced JavaScript interface with GTIN sync progress indicators and notifications
* **ENHANCED:** Products now automatically populate missing GTINs during regular stock sync when enabled
* **ENHANCED:** Added comprehensive logging for GTIN sync operations with dedicated log prefix
* **ENHANCED:** Added proper error handling and retry mechanisms for GTIN sync processes
* **ENHANCED:** Added meta field `_wc_sspaa_gtin_last_sync` to track when GTINs were last updated
* **ENHANCED:** Updated admin interface with improved styling and user experience for GTIN operations

### 1.3.29
* **NEW:** Added SKU exclusion functionality to skip specific products from sync process
* Defined `WC_SSPAA_EXCLUDED_SKUS` constant in main plugin file for easy SKU management
* Updated all product count queries to exclude specified SKUs (91523, 91530, 91531, 11074-XLT)
* Enhanced manual sync to prevent excluded SKUs from being processed individually
* Added comprehensive logging to show which SKUs are excluded from sync operations
* Improved sync efficiency by filtering out unwanted products at database query level

### 1.3.28
* **ENHANCED:** Improved management and logging of the sync lock transient (`wc_sspaa_sync_all_active_lock`) within the scheduled sync process (`wc_sspaa_execute_scheduled_sync`). This includes more detailed logging for lock acquisition, state checking, and release, ensuring better transparency and confirming robust handling of the lock to prevent stuck syncs. The core mechanism relies on transient timeouts for failsafe clearing, which aligns with WordPress best practices.

### 1.3.27
* **REMOVED:** Removed temporary workaround that skipped SKU `ZTA-MULTI` during sync, as the underlying Cloudflare timeout issue on `zerotech.com.au` has been identified as a server configuration matter.

### 1.3.26
* **FIXED:** Completely resolved scheduled stock synchronisation failures across all websites by eliminating the flawed AJAX/nonce-based approach.
* **REDESIGNED:** Implemented direct cron execution for scheduled sync, removing all complexities related to nonce verification, AJAX requests, and authentication contexts.
* **ENHANCED:** Improved domain detection for cron contexts by adding fallback to WordPress site URL when $_SERVER['HTTP_HOST'] is unavailable.
* **SIMPLIFIED:** Removed all nonce generation, storage, and verification logic for scheduled syncs as it was fundamentally incompatible with WordPress cron execution context.
* **ENHANCED:** Manual trigger now directly executes the sync function instead of attempting to simulate cron behaviour.
* **ENHANCED:** Consolidated sync execution logic into a single function (wc_sspaa_execute_scheduled_sync) used by both cron and manual triggers.
* **IMPROVED:** Better error handling and logging with clear CRON EXECUTION tags for easier debugging of scheduled sync issues.
* **CLEANUP:** Removed obsolete transient storage for nonces and verification tokens, simplifying the codebase significantly.
* **RELIABILITY:** Scheduled sync now executes reliably in true cron context without dependency on user sessions or authentication states.

### 1.3.25
* **FIXED:** Resolved nonce verification failures in scheduled sync system by implementing dual verification approach (nonce + verification token).
* **ENHANCED:** Added verification token fallback mechanism when WordPress nonce verification fails due to context changes.
* **ENHANCED:** Improved debugging for nonce verification issues with detailed logging of stored vs received values.
* **ENHANCED:** Enhanced manual trigger function to use both nonce and verification token for consistency.
* **ENHANCED:** Updated deactivation cleanup to remove verification tokens alongside nonces.
* **ENHANCED:** Added automatic verification token regeneration when missing during cron execution.
* **ENHANCED:** Implemented timing-based verification fallback that allows sync within 10 minutes of scheduled time.
* **ENHANCED:** Added manual test override mechanism for testing purposes when all verification methods fail.
* **ENHANCED:** Improved fallback nonce and verification token generation with enhanced logging.
* **LOGGING:** Added comprehensive logging for verification token generation, storage, and validation processes.

### 1.3.24
* **FIXED:** Resolved scheduled stock synchronisation system failures across multiple websites by implementing comprehensive debugging and error handling improvements.
* **ENHANCED:** Extended nonce validity from 1 hour to 12 hours to account for timezone differences and scheduling delays, preventing nonce expiration issues.
* **NEW:** Added manual trigger function accessible via "Test Scheduled Sync" button on Products page for immediate testing of scheduled sync functionality without waiting for cron.
* **ENHANCED:** Implemented fallback nonce regeneration mechanism when nonces are not found or expired during cron execution.
* **ENHANCED:** Added comprehensive error handling and logging for scheduled sync failures with detailed stack traces for exceptions and fatal errors.
* **ENHANCED:** Improved sync process monitoring with enhanced logging at each stage of the synchronisation lifecycle.
* **ENHANCED:** Added verification of cron event scheduling with automatic rescheduling if events are missing.
* **ENHANCED:** Enhanced AJAX response handling with detailed response codes and body logging for better debugging.
* **ENHANCED:** Improved sync lock management with proper cleanup in all error scenarios to prevent stuck processes.
* **ENHANCED:** Added scheduling debug information storage for troubleshooting timing and nonce issues.
* **ENHANCED:** Updated SQL queries in scheduled sync to exclude obsolete exempt products for better performance.
* **ENHANCED:** Added admin notices to provide feedback when manual scheduled sync tests are triggered.

### 1.3.23
* **NEW:** Implemented intelligent stock synchronisation exemption for products identified as Obsolete.
* **ENHANCED:** Products returning an empty API response (`{"products":[],"count":0,"pages":0}`) are now automatically marked with `_wc_sspaa_obsolete_exempt` meta (timestamped) and stock set to 0.
* **ENHANCED:** Obsolete exempt products are excluded from subsequent main sync cycles, reducing unnecessary API calls.
* **ENHANCED:** If an Obsolete exempt product later returns valid stock data from the API, the exemption flag is automatically removed.
* **ENHANCED:** Individual product sync button in the product list now checks for Obsolete exemption; if exempt, it skips API call and notifies user.
* **ENHANCED:** If individual product sync receives an empty API response (indicating Obsolete), it marks the product as Obsolete exempt.
* **NEW:** Added an admin action (`wc_sspaa_clear_obsolete_exemption`) to allow manual clearing of the Obsolete exemption flag for a product via a specially crafted URL.
* **LOGGING:** Added logs for marking products as Obsolete exempt, removing exemption, and skipping exempt products.
* **UI:** Added a red text indicator "Obsolete" in the "Avenue Stock Sync" column for products marked as Obsolete exempt.
* **NEW:** Implemented custom "Obsolete" stock status for WooCommerce products identified as obsolete through empty API responses.
* **ENHANCED:** When products return empty API responses, they are now automatically assigned the "Obsolete" stock status instead of just "Out of Stock".
* **ENHANCED:** Custom "Obsolete" status is properly integrated with WooCommerce's stock management system and treated as out of stock for frontend behaviour.
* **ENHANCED:** Admin product list now displays "Obsolete" status distinctly in the stock column with appropriate styling.
* **ENHANCED:** Parent variable products are automatically set to "Obsolete" status when all variations are obsolete.
* **ENHANCED:** Manual obsolete exemption clearing now resets "Obsolete" status to "Out of Stock" for proper reprocessing in next sync cycle.
* **LOGGING:** Enhanced logging to include stock status changes when products are marked as or removed from obsolete status.

### 1.3.22
* **NEW:** Implemented intelligent stock synchronisation exemption for obsolete products.
* **ENHANCED:** Products returning an empty API response (`{"products":[],"count":0,"pages":0}`) are now automatically marked with `_wc_sspaa_obsolete_exempt` meta (timestamped) and stock set to 0.
* **ENHANCED:** Obsolete exempt products are excluded from subsequent main sync cycles, reducing unnecessary API calls.
* **ENHANCED:** If an Obsolete exempt product later returns valid stock data from the API, the exemption flag is automatically removed.
* **ENHANCED:** Individual product sync button in the product list now checks for Obsolete exemption; if exempt, it skips API call and notifies user.
* **ENHANCED:** If individual product sync receives an empty API response (indicating Obsolete), it marks the product as Obsolete exempt.
* **NEW:** Added an admin action (`wc_sspaa_clear_obsolete_exemption`) to allow manual clearing of the Obsolete exemption flag for a product via a specially crafted URL.
* **LOGGING:** Added logs for marking products as Obsolete exempt, removing exemption, and skipping exempt products.
* **UI:** Added a red text indicator "Obsolete" in the "Avenue Stock Sync" column for products marked as Obsolete exempt.

### 1.3.21
* **ENHANCED:** Updated log retention policy from 4 days to 7 days for the `wc-sspaa-debug.log` file.

### 1.3.20
* **NEW:** Implemented domain-specific daily stock synchronisation schedules.
* **NEW:** Scheduled sync now triggers an AJAX-like non-blocking background process for actual synchronisation.
* **ENHANCED:** Sync times are now configurable per domain (store.zerotechoptics.com: 00:25, skywatcheraustralia.com.au: 00:55, zerotech.com.au: 01:25, zerotechoutdoors.com.au: 01:55, nitecoreaustralia.com.au: 02:25 Sydney time), with a default for unlisted domains (03:00 Sydney time).
* **ENHANCED:** Scheduled synchronisation uses a 5-second delay between API calls, aligning with manual sync AJAX method.
* **ENHANCED:** Added nonce verification for the cron-triggered AJAX call for improved security.
* **ENHANCED:** Robust logging for scheduled trigger, AJAX handling, and sync process for each domain.
* **FIXED:** Uses a shared lock transient (`wc_sspaa_sync_all_active_lock`) to prevent overlap between scheduled syncs and the manual "Sync All Products" button.
* **CLEANUP:** Removed old `wc_sspaa_sync_time` option usage from scheduling; deactivation clears new cron nonces.

### 1.3.19
* **REMOVED:** Removed the "Stock Sync Status" page and all associated functionality.
* **REMOVED:** Removed the admin menu item for "Stock Sync Status".
* **CLEANUP:** Ensured `wc_sspaa_sync_time` option is deleted on plugin deactivation and uninstallation.
* **ENHANCED:** Streamlined plugin by removing legacy status page components.

### 1.3.18
* **NEW:** Moved "Sync All Products" button from Stock Sync Status page to WooCommerce All Products page (Products > All Products)
* **NEW:** Added live countdown timer showing estimated time remaining based on 5-second delay per product
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
* Maintains 5-second delay between API calls for rate limit compliance during manual sync
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
* Adjusted API call delay to 5 seconds (5,000,000 microseconds) for both scheduled cron sync and manual "Sync Product" button actions to further mitigate rate limiting issues. This provides a more conservative approach to API interaction.

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

