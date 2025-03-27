<?php
class WC_SSPAA_API_Handler
{
    private $api_url;
    private $username;
    private $password;

    public function __construct()
    {
        $this->api_url = WCAP_API_URL;
        $this->username = WCAP_API_USERNAME;
        $this->password = WCAP_API_PASSWORD;
        
        wc_sspaa_log('API: Handler initialized with URL: ' . $this->api_url);
    }

    public function get_product_data($sku)
    {
        $url = $this->api_url . '?code=' . urlencode($sku);
        
        wc_sspaa_log('API: Requesting data for SKU: ' . $sku . ' from URL: ' . $url);
        $start_time = microtime(true);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            ),
            'timeout' => 15, // Set a reasonable timeout
        )
        );

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wc_sspaa_log('API: Request failed: ' . $error_message);
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $execution_time = microtime(true) - $start_time;
        
        wc_sspaa_log('API: Response received in ' . round($execution_time, 2) . ' seconds with status code: ' . $status_code);
        
        if ($status_code !== 200) {
            wc_sspaa_log('API: Non-200 status code: ' . $status_code . ' for SKU: ' . $sku);
        }
        
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wc_sspaa_log('API: JSON decode error: ' . json_last_error_msg() . ' for SKU: ' . $sku);
            return null;
        }
        
        // Log the size of the response and number of products found
        $products_count = isset($data['count']) ? $data['count'] : 0;
        $response_size = strlen($body);
        wc_sspaa_log('API: Response size: ' . $response_size . ' bytes, Products found: ' . $products_count . ' for SKU: ' . $sku);

        return $data;
    }
}
?>