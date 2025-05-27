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
   - [x] **1.3.9** Examine and address the problem where deactivating and reactivating the plugin fails to fully remove all scheduled actions (cron jobs) associated with stock sync batches. On plugin deactivation, guarantee that every scheduled batch event is thoroughly and reliably cleared, preventing any leftover or duplicate scheduled actions when the plugin is reactivated. Ensure comprehensive debug logging is in place to verify the removal of all scheduled events, and upon reactivation, confirm that only the correct, newly scheduled batch events exist. Review the current deactivation logic, pinpoint any deficiencies, and apply a best-practice approach to ensure a pristine state for scheduled actions during every deactivate/reactivate cycle.
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
   - [x] **1.3.10** Investigate and resolve the API rate limiting issue (HTTP 429 errors) observed during stock synchronisation. The provided logs show multiple API requests being made within a short timeframe (often within the same second), exceeding the external API's rate limit (indicated as 1 request per 2 seconds in the error response). Analyse the code responsible for making API calls during the sync process and implement a robust solution, such as introducing appropriate delays or rate limiting, to ensure API requests comply with the external service's rate limits. Apply best practices for handling external API interactions and add necessary debug logging to confirm the rate limit is being respected and errors are no longer occurring.
      [2025-05-14 17:00:16] Saving last sync time: 2025-05-15 03:00:16 for product ID: 34547
      [2025-05-14 17:00:18] Processing product ID: 34550, Type: product, Parent: 0, SKU: SC4
      [2025-05-14 17:00:18] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=SC4
      [2025-05-14 17:00:18] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 200; Raw response: {"products":[{"code":"SC4","description":"Superb Charger Four Slot Battery Charger","abc_class":"","description1":"Superb Charger Four Slot","description2":"Battery Charger","description3":"","apn":"6952506491513","group_code":"NITE","brand":"NITECORE","condition_code":"","default_conversion_factor":1,"issue_ctrl":"","stk_licences_rebate_flag":"","pack_weight":"0.0","title":"","stock_status":"S","sales_type":"","user_only_alpha20_1":"","user_only_alpha20_2":"","user_only_alpha4_1":"","user_only_alpha4_2":"","user_only_alpha4_3":"","user_only_alpha4_4":"","user_only_date1":null,"user_only_date2":null,"user_only_num1":"0.0","user_only_num2":"0.0","user_only_num3":"0.0","user_only_num4":"0.0","pronto_user_group":"","pronto_user_group_1":"","saleability":true,"visibility":true,"uom":"EA","pack_size":"1.0","updated_at":"00:00 15-May-2025","created_at":"12:34 16-May-2018","prices":[{"debtor":"211023","breaks":[{"price_rule":"06","currency_code":"","inc_tax":"99.95","ex_tax":"90.8636","min_qty":1,"max_qty":null}],"base_price":{"currency_code":"","inc_tax":"99.95","ex_tax":"90.8636"}}],"images":[{"filename":"sc4.PNG","content_type":"image/png","updated_at":"21:39 15-Mar-2021","url":"/ts1615804751/attachments/Product/13216/sc4.PNG"}],"notes":[{"type":"SD","note":"B073YFBS9V"}],"alternative_products":[],"companion_products":[],"inventory_quantities":[{"warehouse":"1","quantity":"-5.0"},{"warehouse":"1SER","quantity":"0.0"}],"uoms":[{"code":"CTN","conv":"1.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"},{"code":"EA","conv":"1.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"},{"code":"PLT","conv":"999999.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"}],"categories":[{"slug":"100_0","url":"/100_0","search_url":"/_price_range_search/100_0"},{"slug":"NITE","url":"/NITE/NITE","search_url":"/_pronto/NITE/NITE"},{"slug":"NITE","url":"/NITE/NITE","search_url":"/NITE/NITE"},{"slug":"NITECORE","url":"/NITECORE","search_url":"/_pronto_brand/NITECORE"},{"slug":"battery-chargers","url":"/power/batteries/battery-chargers","search_url":"/_nitecore/power/batteries/battery-chargers"}]}],"count":1,"pages":1}
      [2025-05-14 17:00:18] Raw API response for SKU SC4: {"products":[{"code":"SC4","description":"Superb Charger Four Slot Battery Charger","abc_class":"","description1":"Superb Charger Four Slot","description2":"Battery Charger","description3":"","apn":"6952506491513","group_code":"NITE","brand":"NITECORE","condition_code":"","default_conversion_factor":1,"issue_ctrl":"","stk_licences_rebate_flag":"","pack_weight":"0.0","title":"","stock_status":"S","sales_type":"","user_only_alpha20_1":"","user_only_alpha20_2":"","user_only_alpha4_1":"","user_only_alpha4_2":"","user_only_alpha4_3":"","user_only_alpha4_4":"","user_only_date1":null,"user_only_date2":null,"user_only_num1":"0.0","user_only_num2":"0.0","user_only_num3":"0.0","user_only_num4":"0.0","pronto_user_group":"","pronto_user_group_1":"","saleability":true,"visibility":true,"uom":"EA","pack_size":"1.0","updated_at":"00:00 15-May-2025","created_at":"12:34 16-May-2018","prices":[{"debtor":"211023","breaks":[{"price_rule":"06","currency_code":"","inc_tax":"99.95","ex_tax":"90.8636","min_qty":1,"max_qty":null}],"base_price":{"currency_code":"","inc_tax":"99.95","ex_tax":"90.8636"}}],"images":[{"filename":"sc4.PNG","content_type":"image\/png","updated_at":"21:39 15-Mar-2021","url":"\/ts1615804751\/attachments\/Product\/13216\/sc4.PNG"}],"notes":[{"type":"SD","note":"B073YFBS9V"}],"alternative_products":[],"companion_products":[],"inventory_quantities":[{"warehouse":"1","quantity":"-5.0"},{"warehouse":"1SER","quantity":"0.0"}],"uoms":[{"code":"CTN","conv":"1.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"},{"code":"EA","conv":"1.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"},{"code":"PLT","conv":"999999.0","gtin":"","weight":"0.0","height":"0.0","width":"0.0","depth":"0.0"}],"categories":[{"slug":"100_0","url":"\/100_0","search_url":"\/_price_range_search\/100_0"},{"slug":"NITE","url":"\/NITE\/NITE","search_url":"\/_pronto\/NITE\/NITE"},{"slug":"NITE","url":"\/NITE\/NITE","search_url":"\/NITE\/NITE"},{"slug":"NITECORE","url":"\/NITECORE","search_url":"\/_pronto_brand\/NITECORE"},{"slug":"battery-chargers","url":"\/power\/batteries\/battery-chargers","search_url":"\/_nitecore\/power\/batteries\/battery-chargers"}]}],"count":1,"pages":1}
      [2025-05-14 17:00:18] Updating stock for SKU: SC4 with quantity: 0
      [2025-05-14 17:00:18] Saving last sync time: 2025-05-15 03:00:18 for product ID: 34550
      [2025-05-14 17:00:20] Processing product ID: 34553, Type: product, Parent: 0, SKU: SC2
      [2025-05-14 17:00:20] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=SC2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 2 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 2 seconds
      [2025-05-14 17:00:21] Raw API response for SKU SC2: null
      [2025-05-14 17:00:21] No product data found for SKU: SC2
      [2025-05-14 17:00:21] Processing product ID: 34556, Type: product, Parent: 0, SKU: UI1
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UI1
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] Raw API response for SKU UI1: null
      [2025-05-14 17:00:21] No product data found for SKU: UI1
      [2025-05-14 17:00:21] Processing product ID: 34559, Type: product, Parent: 0, SKU: UI2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UI2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] Raw API response for SKU UI2: null
      [2025-05-14 17:00:21] No product data found for SKU: UI2
      [2025-05-14 17:00:21] Processing product ID: 34562, Type: product, Parent: 0, SKU: UM2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UM2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] Raw API response for SKU UM2: null
      [2025-05-14 17:00:21] No product data found for SKU: UM2
      [2025-05-14 17:00:21] Processing product ID: 34566, Type: product, Parent: 0, SKU: UM4
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UM4
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] Raw API response for SKU UM4: null
      [2025-05-14 17:00:21] No product data found for SKU: UM4
      [2025-05-14 17:00:21] Processing product ID: 34569, Type: product, Parent: 0, SKU: UMS2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UMS2
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:21] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 1 seconds
      [2025-05-14 17:00:22] Raw API response for SKU UMS2: null
      [2025-05-14 17:00:22] No product data found for SKU: UMS2
      [2025-05-14 17:00:22] Processing product ID: 34572, Type: product, Parent: 0, SKU: UMS4
      [2025-05-14 17:00:22] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] Requesting URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json?code=UMS4
      [2025-05-14 17:00:22] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] HTTP status: 429; Raw response: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 2 seconds
      [2025-05-14 17:00:22] [Context: cron] [Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0] [Domain: N/A] JSON decode error: Syntax error; Raw body: avenue_api_calls_limit threshold of 1 tries per 2s and burst rate 20 tries exceeded for key "", hash prop/leaky_bucket/e916201f1efb45e5cb2bd7f494ccbe1c please try again after 2 seconds
      [2025-05-14 17:00:22] Raw API response for SKU UMS4: null
   - [x] **1.3.11** Implement and configure the API call delay for stock synchronisation. Set the delay between API requests to 3 seconds (3,000,000 microseconds). Verify that this delay is correctly applied and effective in preventing rate limit errors for both the scheduled cron sync and the manual "Sync Product" button.
   - [x] **1.3.13** Modify the logging system to create and write all debug logs to a dedicated log file within the plugin's directory structure (e.g., `wc-sspaa-debug.log`) instead of relying on WordPress's default `debug.log` file in the `wp-content` folder. Ensure proper file permissions, log rotation if necessary, and include timestamps in log entries for better debugging and maintenance.
   - [x] **1.3.14** Implement an immediate AJAX-based "Sync All Products" functionality that processes stock synchronisation directly without scheduling or relying on WordPress CRON jobs. The button should trigger real-time processing with proper progress feedback, error handling, and rate limiting compliance (maintaining the 3-second delay between API calls) to provide instant synchronisation capability for administrators.
   - [x] **1.3.15** Resolved PHP fatal error on Stock Sync Status page caused by attempting to access dynamic API credentials array that no longer exists. Simplified the system to use static credentials from config.php, removing complex domain-based credential selection functionality and related AJAX handlers. Updated all references to use WCAP_API_USERNAME and WCAP_API_PASSWORD constants instead of the dynamic $wc_sspaa_api_credentials array.
   - [x] **1.3.16** Update the "Sync All Products" button text to dynamically display the total number of products that will be synchronised. The button should read "Sync All (X) Products" where X is the actual count of products with SKUs that will be processed during the synchronisation.
   - [x] **1.3.17** Resolved critical logging function issue where the entire log file was being overwritten on every log entry instead of appending. Optimised logging to append entries first and perform cleanup only when needed (randomly 1 in 50 times or when file exceeds 5MB), preventing log loss during high-frequency operations like product synchronisation.
   - [x] **1.3.18** Move the "Sync All Products" button from the Stock Sync Status page to the WooCommerce All Products page (Products > All Products). When the button is clicked, display a live countdown timer that shows the estimated time remaining based on the total number of products to be synchronised, accounting for the 3-second delay between API calls. The timer should update in real-time and provide visual feedback on sync progress. 
   - [x] **1.3.19** Remove all functionality from the Stock Sync Status page and completely remove the Stock Sync Status page from the WordPress admin menu. This includes removing the menu registration, page handlers, and associated files. Ensure proper cleanup of any database options or settings related to this page to prevent orphaned data.
   - [x] **1.3.20** Implement domain-specific scheduled stock synchronisation with the following daily schedule:
     - store.zerotechoptics.com: 00:25
     - skywatcheraustralia.com.au: 00:55
     - zerotech.com.au: 01:25
     - zerotechoutdoors.com.au: 01:55
     - nitecoreaustralia.com.au: 02:25

     Requirements:
     - Use WordPress CRON to schedule the initial trigger at the specified times
     - The actual synchronisation process should use AJAX methodology (same as the "Sync All Products" button) so basically it is just a scheduled the "Sync All Products" button function 
     - Implement domain detection to determine which schedule applies to the current site, as the system is UTC time, I provided time is Sydney time in 24-hour format
     - Include proper error handling and logging for each scheduled sync
     - Maintain the 3-second delay between API calls during synchronisation
     - Log the start and completion times for each scheduled sync operation
   - [x] **1.3.21** Implement a 7-day log retention policy for the dedicated debug log file (`wc-sspaa-debug.log`). Modify the existing log cleanup functionality to automatically purge log entries older than 7 days instead of the current 4-day retention period. This should maintain system performance whilst providing sufficient debugging history for troubleshooting recent issues.
   - [x] **1.3.22** Implement intelligent stock synchronisation exemption for obsolete products:
     
     **Problem Analysis:**
     The system currently processes all products during each sync cycle, including products that consistently return empty responses from the API. This results in unnecessary API calls and processing overhead.
     
     **Example Log Entry:**
     ```
     [2025-05-27 12:13:46] [Context: admin] [Username: jerry@tasco.com.au] [Domain: skywatcheraustralia.com.au] HTTP status: 200; Raw response: {"products":[],"count":0,"pages":0}
     [2025-05-27 12:13:46] Raw API response for SKU SW1025AZ3-1: {"products":[],"count":0,"pages":0}
     ```
     
     **Requirements:**
     - When any product SKU returns the API response `{"products":[],"count":0,"pages":0}`, automatically mark that SKU as permanently out-of-stock (OOS)
     - Store OOS status in product meta data (e.g., `_wc_sspaa_oos_exempt`) with timestamp
     - Exempt OOS-marked SKUs from all future synchronisation cycles (both scheduled and manual)
     - Provide an admin interface or method to manually reset the OOS exemption status for specific products if needed
     - Log when products are marked as OOS exempt and when they are skipped during sync cycles
     - Consider implementing a periodic review system (e.g., monthly) to re-check previously exempted SKUs


### Side Project

- How can I write Shopify function in Shopify app that let Shopify admin api to get the stock level from our API?(@https://sales.tasco.net.au/userapi/json/product/v4_tasco.json )