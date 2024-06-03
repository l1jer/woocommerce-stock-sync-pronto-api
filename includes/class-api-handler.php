<?php
class WCAP_API_Handler
{
    private $api_url;
    private $username;
    private $password;

    public function __construct()
    {
        $this->api_url = WCAP_API_URL;
        $this->username = WCAP_API_USERNAME;
        $this->password = WCAP_API_PASSWORD;
    }

    public function get_product_data($sku)
    {
        $url = $this->api_url . '?code=' . urlencode($sku);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
            )
        )
        );

        if (is_wp_error($response)) {
            error_log('API request failed: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }
}
?>