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
   - [x] **1.3.5** If the API response is `{"products":[],"count":0,"pages":0}`, mark the corresponding product as "Obsolete" in the "Avenue Stock Sync" column on the All Products page, using red text to highlight its status.



Notes:
// API Credentials for different websites
// nitecoreaustralia.com.au
// Username: 8dac5f8d-3db8-4483-b5c3-4921b1e6c8d0
// Password: 6be417682d60679f630d34f4f8b3f9
//
// zerotech.com.au
// Username: 1ae562ff-e8d9-4dfc-aa31-9851fbf2a883
// Password: 16b7f82b82af78e6cf38739338270f
//
// store.zerotechoptics.com
// Username: 42163f7e-6778-4346-822b-6f9786dcfa1f
// Password: 062b372de782a56c96aec125b62093