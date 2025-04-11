# Project Review and Enhancement Implementation

## Objective:
Review, understand, and analyse the existing project, then implement the following changes while adhering to the project's standards and best practices. Please do not do anything outside of tasks. Adding logs wherever needed. 

### Tasks:
- [x] **1.1.10** The dynamic batch calculation must account for all product types, including variable products. Currently, several products' stock is not being synchronised, which may be due to this oversight. 

- [x] **1.1.11** Eliminate the non-functional "Start Manual Sync" button on the "wc-sspaa-settings" page, as it is currently ineffective.

- [x] **1.1.12** Make the project dynamic by automatically using the correct set of API credentials from the `config.php` file based on the website context.
   - [x] Add a dropdown menu in the **Stock Sync Status** page that lists all available API usernames and passwords (display their website URL instead of showing the credentials in the dropdown list). This selection should update dynamically via AJAX without clicking any save button.
   - [x] Add a **Test API Connection** button next to the dropdown. When clicked, it should test the currently selected credentials and display either **SUCCESS** or **FAIL** based on the response.
   - [x] The `config.php` contains 3 different sets of usernames and passwords for API access. Depending on the website being used, load and apply the appropriate credentials. Additionally, display the currently active credentials (both name and value) on the **wc-sspaa-setting** page for reference.

- [x] **1.1.13** Completely remove all code and functions related to **Notification Email** functionality, ensuring that any references, hooks, or dependencies associated with sending notification emails are also eliminated.

- [x] **1.1.14** The "Sync Stock" button on each product line in the All Products page is currently returning an error message: "Sync error: API Error: Could not retrieve data". This issue needs to be investigated and resolved to ensure proper functionality.

- [x] **1.1.14a** Clean remaining email related code.

- [ ] **1.1.15** Replace the function of the **"Stock Sync Status"** button on the **All Products** admin page so that instead of redirecting, it manually triggers the stock sync process (optionally via AJAX but still following existing API rules), provides feedback in debug.log and a success message when successfully executed, and retains its original styling and position.

- [ ] **1.1.15a** The debug.log shows repeating logs such as:
   ```
   [2025-04-11 02:35:49] [Memory: 104.77MB] API: Handler initialised with URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json
   [2025-04-11 02:35:49] [Memory: 104.77MB] CORE: Plugin initialised - version 1.1.13
   [2025-04-11 02:35:49] [Memory: 104.77MB] API: Handler initialised with URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json
   [2025-04-11 02:35:49] [Memory: 104.78MB] CORE: Plugin initialised - version 1.1.13
   [2025-04-11 02:35:49] [Memory: 104.77MB] API: Handler initialised with URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json
   [2025-04-11 02:35:49] [Memory: 104.78MB] CORE: Plugin initialised - version 1.1.13
   [2025-04-11 02:35:52] [Memory: 104.82MB] API: Handler initialised with URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json
   [2025-04-11 02:35:52] [Memory: 104.82MB] CORE: Plugin initialised - version 1.1.13
   [2025-04-11 02:35:52] [Memory: 108.59MB] API: Handler initialised with URL: https://sales.tasco.net.au/userapi/json/product/v4_tasco.json
   ```
   Also, it does not indicate the error when the API fails; please add logs for these.

- [ ] **1.1.15b** Remove repeating debug logs