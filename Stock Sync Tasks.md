# Project Review and Enhancement Implementation

## Objective:
Review, understand, and analyse the existing project, then implement the following changes while adhering to the project's standards and best practices. Please do not do anything outside of tasks. Adding logs wherever needed. 

### Tasks:
- [x] **1.1.8** The dynamic batch calculation must account for all product types, including variable products. Currently, several products' stock is not being synchronised, which may be due to this oversight. 

- [x] **1.2** Create a new page titled "Stock Sync Status" under the Products tab, with the slug set to `wc-sspaa-settings`. This page should include the following functionalities:
   - [x] **1.2.1** Display the current UTC time alongside the current AEST time, accounting for both daylight saving and non-daylight saving periods, also indicating if it is daylight saving or non-daylight saving period.
   - [x] **1.2.2** Implement an option to update the start time for the daily stock synchronisation (in Sydney time) with a Save Time button (formatted as hh:mm:ss), while also displaying the corresponding UTC date and time in a read-only format next to it.
   - [x] **1.2.3** Show the total number of products that have SKUs.
   - [x] **1.2.4** Indicate the total number of batches involved in a complete stock synchronisation cycle.
   - [x] **1.2.5** Display the date and time of the next scheduled batch in both UTC and Sydney time.
   - [x] **1.2.6** Present the last synchronisation completion date and time in both UTC and Sydney time.
   - [x] **1.2.7** Remove additional logs.

- [x] **1.3** Make the project dynamic by automatically using the correct set of API credentials from the `config.php` file based on the website context. There are 3 sets of username and password based on different websites(see below API credentials) using a same API URL
   - [x] **1.3.1** Add a dropdown menu in the **Stock Sync Status** page that lists all available API usernames and passwords (display their website URL instead of showing the credentials in the dropdown list). This selection should update dynamically via AJAX without clicking any save button.
   - [x] **1.3.2** Add a **Test API Connection** button next to the dropdown. When clicked, it should test the currently selected credentials and display either **SUCCESS** or **FAIL** based on the response.
   - [x] **1.3.3** The `config.php` contains 3 different sets of usernames and passwords for API access. Depending on the website being used, load and apply the appropriate credentials. Additionally, display the currently active credentials (both name and value) on the **wc-sspaa-setting** page for reference.
   - [x] **1.3.4** Review the implementation of the Daily Sync Start Time feature.
         [x] Confirm which timezone is currently being used — it appears to be set to UTC.
         [x] Update the logic to ensure the Daily Sync Start Time uses the Sydney timezone (AEST) instead of UTC.
         [x] Test to verify the correct sync time runs based on Sydney (AEST) timezone.
   - [x] **1.3.5** Review, identify and understand these following logs then fix them in best practices:
                  [15-Apr-2025 12:00:04 UTC] PHP Warning:  Undefined array key "HTTP_HOST" in /home/customer/www/zerotech.com.au/public_html/wp-content/plugins/woocommerce-stock-sync-pronto-api/includes/config.php on line 27
                  [15-Apr-2025 12:00:04 UTC] PHP Deprecated:  str_replace(): Passing null to parameter #3 ($subject) of type array|string is deprecated in /home/customer/www/zerotech.com.au/public_html/wp-content/plugins/woocommerce-stock-sync-pronto-api/includes/config.php on line 27
   - [x] **1.3.6** The sync time has been updated to 22:00 AEST; however, I observed the following logs at 22:00 and 22:30 (AEST), yet no products have been updated on the All Products page. This indicates a potential issue with this essential feature. Please investigate and resolve this:
                  [2025-04-15 12:00:14] Processing batch with offset: 0
                  [2025-04-15 12:30:07] Processing batch with offset: 15

   - [ ] **1.3.7** Implement a button on the All Products admin page titled "Sync All Products Now". Upon clicking this button, the following actions should occur:
     - Initiate a manual AJAX function that triggers the synchronisation of all products immediately, bypassing any scheduled tasks or cron jobs. Each product's stock should be synced based on its SKU, with a delay of 3 seconds between each sync to comply with API rate limits.
     - Incorporate debug logging to capture the progress of the sync cycle, including details of successes, failures, and any relevant API responses.
     - Provide user feedback through the UI, such as a loading indicator and notifications for success or failure. Additionally, log each sync event in the `debug.log` file located within the plugin folder.
   - [ ] **1.3.8** If the API response is `{"products":[],"count":0,"pages":0}`, mark the corresponding product as "Obsolete" in the "Avenue Stock Sync" column on the All Products page, using red text to highlight its status.