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
   - [x] **1.3.7** Investigate and resolve the issue where a significant number of products are not being synchronised during the daily scheduled stock sync on certain sites (e.g., nitecoreaustralia.com.au, which has 190 products). The debug log indicates that not all products are processed in the scheduled batches, whereas other sites (such as zerotech.com.au with 168 products) do not exhibit this problem. Analyse the batch processing logic, identify any discrepancies or missed products, and implement a robust solution to ensure all products (including all product types and variations) are reliably included and synchronised in every scheduled sync cycle.
   See below log from nitecoreaustralia.com.au website:
      [2025-04-19 15:00:10] Processing batch with offset: 0
      [2025-04-19 15:30:29] Processing batch with offset: 15
      [2025-04-19 16:00:11] Processing batch with offset: 30
      [2025-04-19 16:30:06] Processing batch with offset: 45
      [2025-04-19 17:00:09] Processing batch with offset: 60
      [2025-04-19 17:30:15] Processing batch with offset: 75
      [2025-04-19 18:00:35] Processing batch with offset: 90
      [2025-04-19 18:30:29] Processing batch with offset: 105
      [2025-04-19 19:00:27] Processing batch with offset: 120 
      [2025-04-19 19:30:27] Processing batch with offset: 165
      [2025-04-19 19:31:00] Processing batch with offset: 135
      [2025-04-19 20:00:38] Processing batch with offset: 150
   - [x] **1.3.8** Implement a mechanism so that the plugin's debug.log file only retains log entries from the last 4 days. Older log entries (older than 4 days from the current date/time) should be automatically deleted or purged on a regular basis, ensuring the log file remains current and does not grow indefinitely.
   - [ ] **1.3.9** Examine and address the problem where deactivating and reactivating the plugin fails to fully remove all scheduled actions (cron jobs) associated with stock sync batches. On plugin deactivation, guarantee that every scheduled batch event is thoroughly and reliably cleared, preventing any leftover or duplicate scheduled actions when the plugin is reactivated. Ensure comprehensive debug logging is in place to verify the removal of all scheduled events, and upon reactivation, confirm that only the correct, newly scheduled batch events exist. Review the current deactivation logic, pinpoint any deficiencies, and apply a best-practice approach to ensure a pristine state for scheduled actions during every deactivate/reactivate cycle.
      [2025-04-22 14:31:43] Deactivating plugin and clearing scheduled events.
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 0
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 15
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 30
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 45
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 60
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 75
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 90
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 105
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 120
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 135
      [2025-04-22 14:31:43] Clearing scheduled batch with offset: 150
      [2025-04-22 14:31:51] Scheduling batch processing.
      [2025-04-22 14:31:51] Using sync time: 15:29:00
      [2025-04-22 14:31:51] Total products with SKUs: 190, Batch size: 15, Total batches: 13
      [2025-04-22 14:31:51] Scheduling batch with offset: 0 at Sydney time: 15:29:00 (UTC time: 2025-04-23 05:29:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 15 at Sydney time: 15:59:00 (UTC time: 2025-04-23 05:59:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 30 at Sydney time: 16:29:00 (UTC time: 2025-04-23 06:29:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 45 at Sydney time: 16:59:00 (UTC time: 2025-04-23 06:59:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 60 at Sydney time: 17:29:00 (UTC time: 2025-04-23 07:29:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 75 at Sydney time: 17:59:00 (UTC time: 2025-04-23 07:59:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 90 at Sydney time: 18:29:00 (UTC time: 2025-04-23 08:29:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 105 at Sydney time: 18:59:00 (UTC time: 2025-04-23 08:59:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 120 at Sydney time: 19:29:00 (UTC time: 2025-04-23 09:29:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 135 at Sydney time: 19:59:00 (UTC time: 2025-04-23 09:59:00)
      [2025-04-22 14:31:51] Scheduling batch with offset: 150 at Sydney time: 20:29:00 (UTC time: 2025-04-23 10:29:00)
      [2025-04-22 14:31:51] Batch with offset 165 is already scheduled.
      [2025-04-22 14:31:51] Scheduling batch with offset: 180 at Sydney time: 21:29:00 (UTC time: 2025-04-23 11:29:00)
      ----------------------------------------
      As seen in the debug log: "Processing batch with offset:" entries progress sequentially for offsets 0 through 120, then jump to 165, 135, and 150, after which no further batches are processed. Even though the total product count is 190, some products are not synchronised.

      **Action Required:**  
      Perform a comprehensive review of the batch scheduling and processing logic. Determine the underlying cause for missing or unscheduled batches, which leads to unsynchronised products. Ensure the batch system consistently includes all products (covering every type and variation) in each sync cycle, regardless of the total product count. Strengthen logging and validation to verify that every product is scheduled and processed in the sync. Deliver a detailed analysis of the issue and implement or recommend a solution that guarantees all products are synchronised in every future cycle.

<!-- Archived -->
   - [ ] Implement a button on the All Products admin page titled "Sync All Products Now". Upon clicking this button, the following actions should occur:
     - Initiate a manual AJAX function that triggers the synchronisation of all products immediately, bypassing any scheduled tasks or cron jobs. Each product's stock should be synced based on its SKU, with a delay of 3 seconds between each sync to comply with API rate limits.
     - Incorporate debug logging to capture the progress of the sync cycle, including details of successes, failures, and any relevant API responses.
     - Provide user feedback through the UI, such as a loading indicator and notifications for success or failure. Additionally, log each sync event in the `debug.log` file located within the plugin folder.
   - [ ] If the API response is `{"products":[],"count":0,"pages":0}`, mark the corresponding product as "Obsolete" in the "Avenue Stock Sync" column on the All Products page, using red text to highlight its status.