<?php
class WCAP_API_Handler
{
    public function get_product_data($sku)
    {
        $url = WCAP_API_URL . '?code=' . urlencode($sku);
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(WCAP_API_USERNAME . ':' . WCAP_API_PASSWORD),
            ),
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            wcap_log_error("API request error for SKU: $sku. Error: " . $response->get_error_message());
        }

        return $response;
    }
}
?>