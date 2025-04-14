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
     * Get product data from API
     *
     * @param string $sku The product SKU to fetch
     * @return array|null Product data or null on error
     */
    public function get_product_data($sku)
    {
        $url = $this->api_url . '?code=' . urlencode($sku);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            )
        ));

        if (is_wp_error($response)) {
            wc_sspaa_log('API request failed: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wc_sspaa_log('JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }
    
    /**
     * Test API connection with specified or default credentials
     *
     * @return array Test result with status and message
     */
    public function test_connection()
    {
        $test_sku = 'TEST'; // Use a generic SKU for testing
        
        $response = wp_remote_get($this->api_url . '?code=' . urlencode($test_sku), array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API Connection Failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 401) {
            return array(
                'success' => false,
                'message' => 'API Connection Failed: Authentication error (Invalid credentials)'
            );
        }
        
        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'success' => false,
                'message' => 'API Connection Failed: Received HTTP status ' . $status_code
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'API Connection Failed: Invalid JSON response'
            );
        }
        
        return array(
            'success' => true,
            'message' => 'API Connection Successful'
        );
    }
}
?>