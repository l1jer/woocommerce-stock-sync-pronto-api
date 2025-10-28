<?php
class WC_SSPAA_API_Handler
{
    private $api_url;
    private $username;
    private $password;

    /**
     * Constructor
     *
     * @param string $username Optional custom username to override the default
     * @param string $password Optional custom password to override the default
     */
    public function __construct($username = null, $password = null)
    {
        $this->api_url = WCAP_API_URL;
        $this->username = $username ? $username : WCAP_API_USERNAME;
        $this->password = $password ? $password : WCAP_API_PASSWORD;
    }

    /**
     * Get product data from API with fallback support (Task 1.4.7)
     *
     * @param string $sku The product SKU to fetch
     * @return array|null Product data or null on error
     */
    public function get_product_data($sku)
    {
        // Try primary API first
        $primary_result = $this->get_product_data_from_endpoint($sku, 'primary');
        
        // Check if primary API response is valid
        if ($this->is_valid_api_response($primary_result, $sku)) {
            return $primary_result;
        }
        
        // If primary API failed or returned invalid data, try fallback API
        wc_sspaa_log("[FALLBACK API] Primary API failed or returned invalid data for SKU: {$sku}. Attempting fallback API.");
        
        $fallback_result = $this->get_product_data_from_endpoint($sku, 'fallback');
        
        // Check if fallback API response is valid
        if ($this->is_valid_api_response($fallback_result, $sku)) {
            wc_sspaa_log("[FALLBACK API] Successfully retrieved valid data for SKU: {$sku} from fallback API.");
            return $fallback_result;
        }
        
        // Both APIs failed
        wc_sspaa_log("[FALLBACK API] Both primary and fallback APIs failed for SKU: {$sku}. Returning null.");
        return $primary_result; // Return primary result for consistent error handling
    }
    
    /**
     * Check if product should be marked as obsolete by querying both APIs (Task 1.4.9)
     *
     * @param string $sku The product SKU to check
     * @return array Result with 'is_obsolete' boolean and 'scs_response'/'default_response' arrays
     */
    public function check_obsolete_status($sku)
    {
        wc_sspaa_log("[OBSOLETE CHECK] Starting obsolete status check for SKU: {$sku}");
        
        // Check SCS API first
        $scs_result = $this->get_product_data_from_endpoint_with_credentials(
            $sku, 
            WCAP_API_URL, 
            WCAP_API_USERNAME, 
            WCAP_API_PASSWORD, 
            'SCS'
        );
        
        $scs_is_empty = $this->is_empty_product_response($scs_result);
        wc_sspaa_log("[OBSOLETE CHECK] SCS API response for SKU {$sku}: " . ($scs_is_empty ? 'EMPTY' : 'HAS DATA'));
        
        // If SCS API has data, product is not obsolete
        if (!$scs_is_empty) {
            wc_sspaa_log("[OBSOLETE CHECK] SKU {$sku} is NOT obsolete - SCS API returned valid product data");
            return array(
                'is_obsolete' => false,
                'scs_response' => $scs_result,
                'default_response' => null,
                'reason' => 'SCS API returned product data'
            );
        }
        
        // SCS API returned empty, now check DEFAULT API
        wc_sspaa_log("[OBSOLETE CHECK] SCS API returned empty for SKU {$sku}, checking DEFAULT API");
        
        $default_result = $this->get_product_data_from_endpoint_with_credentials(
            $sku, 
            WCAP_API_URL_FALLBACK, 
            WCAP_API_USERNAME_FALLBACK, 
            WCAP_API_PASSWORD_FALLBACK, 
            'DEFAULT'
        );
        
        $default_is_empty = $this->is_empty_product_response($default_result);
        wc_sspaa_log("[OBSOLETE CHECK] DEFAULT API response for SKU {$sku}: " . ($default_is_empty ? 'EMPTY' : 'HAS DATA'));
        
        // If DEFAULT API has data, product is not obsolete
        if (!$default_is_empty) {
            wc_sspaa_log("[OBSOLETE CHECK] SKU {$sku} is NOT obsolete - DEFAULT API returned valid product data");
            return array(
                'is_obsolete' => false,
                'scs_response' => $scs_result,
                'default_response' => $default_result,
                'reason' => 'DEFAULT API returned product data'
            );
        }
        
        // Both APIs returned empty, product is obsolete
        wc_sspaa_log("[OBSOLETE CHECK] SKU {$sku} IS OBSOLETE - Both SCS and DEFAULT APIs returned empty product arrays");
        return array(
            'is_obsolete' => true,
            'scs_response' => $scs_result,
            'default_response' => $default_result,
            'reason' => 'Both APIs returned empty product arrays'
        );
    }
    
    /**
     * Get product data from specific endpoint with explicit credentials (Task 1.4.9)
     *
     * @param string $sku The product SKU to fetch
     * @param string $api_url API endpoint URL
     * @param string $username API username
     * @param string $password API password
     * @param string $api_label Label for logging (e.g. 'SCS', 'DEFAULT')
     * @return array|null Product data or null on error
     */
    private function get_product_data_from_endpoint_with_credentials($sku, $api_url, $username, $password, $api_label)
    {
        $context = (defined('DOING_CRON') && DOING_CRON) ? 'cron' : (is_admin() ? 'admin' : 'frontend');
        $log_prefix = "[Context: $context] [{$api_label} API] [Username: {$username}] [Domain: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "] ";
        
        $url = $api_url . '?code=' . urlencode($sku);
        wc_sspaa_log($log_prefix . 'Requesting URL: ' . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wc_sspaa_log($log_prefix . 'API request failed: ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $loggable_body = strlen($body) > 500 ? substr($body, 0, 500) . '... (truncated)' : $body;
        wc_sspaa_log($log_prefix . 'HTTP status: ' . $status_code . '; Raw response: ' . $loggable_body);
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wc_sspaa_log($log_prefix . 'JSON decode error: ' . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
    
    /**
     * Check if API response indicates empty product array (Task 1.4.9)
     *
     * @param array|null $response API response data
     * @return bool True if response is {"products":[],"count":0,"pages":0}
     */
    private function is_empty_product_response($response)
    {
        if (!is_array($response)) {
            return false; // Null or invalid response is not the same as empty products
        }
        
        return isset($response['products']) && 
               is_array($response['products']) && 
               empty($response['products']) &&
               isset($response['count']) && 
               $response['count'] === 0 &&
               isset($response['pages']) && 
               $response['pages'] === 0;
    }
    
    /**
     * Get product data from specific API endpoint (Task 1.4.7)
     *
     * @param string $sku The product SKU to fetch
     * @param string $endpoint_type 'primary' or 'fallback'
     * @return array|null Product data or null on error
     */
    private function get_product_data_from_endpoint($sku, $endpoint_type = 'primary')
    {
        // Determine context
        $context = (defined('DOING_CRON') && DOING_CRON) ? 'cron' : (is_admin() ? 'admin' : 'frontend');
        
        // Set endpoint-specific configuration
        if ($endpoint_type === 'fallback') {
            $api_url = WCAP_API_URL_FALLBACK; // Use existing config constant
            $username = $this->username; // Same credentials work for both endpoints
            $password = $this->password; // Same credentials work for both endpoints
            $log_prefix = "[Context: $context] [FALLBACK API] [Username: {$username}] [Domain: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "] ";
        } else {
            $api_url = $this->api_url;
            $username = $this->username;
            $password = $this->password;
            $log_prefix = "[Context: $context] [PRIMARY API] [Username: {$username}] [Domain: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "] ";
        }

        $url = $api_url . '?code=' . urlencode($sku);
        wc_sspaa_log($log_prefix . 'Requesting URL: ' . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ),
            'timeout' => 30 // Increased timeout for fallback scenarios
        ));

        if (is_wp_error($response)) {
            wc_sspaa_log($log_prefix . 'API request failed: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        wc_sspaa_log($log_prefix . 'HTTP status: ' . $status_code . '; Raw response: ' . $body);

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wc_sspaa_log($log_prefix . 'JSON decode error: ' . json_last_error_msg() . '; Raw body: ' . $body);
            return null;
        }

        return $data;
    }
    
    /**
     * Check if API response is valid for stock synchronisation (Task 1.4.7)
     *
     * @param array|null $response API response data
     * @param string $sku SKU being processed
     * @return bool True if response is valid for stock sync
     */
    private function is_valid_api_response($response, $sku)
    {
        // Null response is invalid
        if ($response === null) {
            wc_sspaa_log("[API VALIDATION] Response is null for SKU: {$sku}");
            return false;
        }
        
        // Must be array
        if (!is_array($response)) {
            wc_sspaa_log("[API VALIDATION] Response is not an array for SKU: {$sku}");
            return false;
        }
        
        // Empty products array indicates obsolete product (valid response, but no stock data)
        if (isset($response['products']) && empty($response['products']) && 
            isset($response['count']) && $response['count'] === 0 && 
            isset($response['pages']) && $response['pages'] === 0) {
            wc_sspaa_log("[API VALIDATION] Valid obsolete response for SKU: {$sku} (empty products array)");
            return true; // This is a valid response indicating obsolete product
        }
        
        // Must have products array with data
        if (!isset($response['products']) || !is_array($response['products']) || empty($response['products'])) {
            wc_sspaa_log("[API VALIDATION] No products array or empty products for SKU: {$sku}");
            return false;
        }
        
        // First product must have inventory_quantities for stock sync
        $product_data = $response['products'][0];
        if (!isset($product_data['inventory_quantities']) || !is_array($product_data['inventory_quantities'])) {
            wc_sspaa_log("[API VALIDATION] No inventory_quantities found for SKU: {$sku}");
            return false;
        }
        
        wc_sspaa_log("[API VALIDATION] Valid response with stock data for SKU: {$sku}");
        return true;
    }
    
    /**
     * Test API connection with specified or default credentials (Task 1.4.7: Enhanced with fallback support)
     *
     * @return array Test result with status and message
     */
    public function test_connection()
    {
        $test_sku = 'TEST'; // Use a generic SKU for testing
        
        // Test primary API first
        $primary_result = $this->test_single_endpoint($this->api_url, $this->username, $this->password, 'Primary');
        
        if ($primary_result['success']) {
            return $primary_result;
        }
        
        // Test fallback API if primary fails
        $fallback_result = $this->test_single_endpoint(
            WCAP_API_URL_FALLBACK, 
            $this->username, 
            $this->password, 
            'Fallback'
        );
        
        if ($fallback_result['success']) {
            return array(
                'success' => true,
                'message' => 'Primary API failed, but Fallback API Connection Successful'
            );
        }
        
        // Both failed
        return array(
            'success' => false,
            'message' => 'Both Primary and Fallback API connections failed. Primary: ' . $primary_result['message'] . '. Fallback: ' . $fallback_result['message']
        );
    }
    
    /**
     * Test a single API endpoint (Task 1.4.7)
     *
     * @param string $api_url API endpoint URL
     * @param string $username API username
     * @param string $password API password
     * @param string $endpoint_name Name for logging (Primary/Fallback)
     * @return array Test result with status and message
     */
    private function test_single_endpoint($api_url, $username, $password, $endpoint_name)
    {
        $test_sku = 'TEST';
        
        $response = wp_remote_get($api_url . '?code=' . urlencode($test_sku), array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $endpoint_name . ' API Connection Failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 401) {
            return array(
                'success' => false,
                'message' => $endpoint_name . ' API Connection Failed: Authentication error (Invalid credentials)'
            );
        }
        
        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'success' => false,
                'message' => $endpoint_name . ' API Connection Failed: Received HTTP status ' . $status_code
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => $endpoint_name . ' API Connection Failed: Invalid JSON response'
            );
        }
        
        return array(
            'success' => true,
            'message' => $endpoint_name . ' API Connection Successful'
        );
    }
}
?>