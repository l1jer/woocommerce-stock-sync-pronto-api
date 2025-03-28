<?php
/**
 * Email Notification Handler
 * 
 * Handles email notifications for stock sync processes
 */
class WC_SSPAA_Email_Notification {
    /**
     * Email recipient
     */
    private $recipient;
    
    /**
     * Statistics for the sync operation
     */
    private $stats = array(
        'total_products' => 0,
        'updated' => 0,
        'obsolete' => 0,
        'skipped' => 0,
        'errors' => array(),
        'start_time' => null,
        'end_time' => null,
        'duration' => 0,
        'batches' => 0
    );
    
    /**
     * Constructor
     * 
     * @param string $recipient Email recipient, defaults to jerry@tasco.com.au
     */
    public function __construct($recipient = 'jerry@tasco.com.au') {
        $this->recipient = $recipient;
        wc_sspaa_log('EMAIL: Notification handler initialized with recipient: ' . $this->recipient, true);
    }
    
    /**
     * Start tracking a sync operation
     * 
     * @param int $total_products Total number of products to be processed
     * @param int $batches Number of batches
     */
    public function start_sync($total_products, $batches) {
        $this->stats['start_time'] = current_time('mysql');
        $this->stats['total_products'] = $total_products;
        $this->stats['batches'] = $batches;
        wc_sspaa_log('EMAIL: Started tracking sync operation - Total products: ' . $total_products . ', Batches: ' . $batches, true);
    }
    
    /**
     * Update statistics for a batch
     * 
     * @param array $batch_stats Statistics for the batch
     */
    public function update_batch_stats($batch_stats) {
        $this->stats['updated'] += isset($batch_stats['updated']) ? $batch_stats['updated'] : 0;
        $this->stats['obsolete'] += isset($batch_stats['obsolete']) ? $batch_stats['obsolete'] : 0;
        $this->stats['skipped'] += isset($batch_stats['skipped']) ? $batch_stats['skipped'] : 0;
        
        if (isset($batch_stats['errors']) && is_array($batch_stats['errors'])) {
            $this->stats['errors'] = array_merge($this->stats['errors'], $batch_stats['errors']);
        }
        
        wc_sspaa_log('EMAIL: Updated stats - Updated: ' . $this->stats['updated'] . 
                     ', Obsolete: ' . $this->stats['obsolete'] . 
                     ', Skipped: ' . $this->stats['skipped'] . 
                     ', Errors: ' . count($this->stats['errors']), true);
                     
        // Save stats to a transient to ensure we don't lose data across page loads
        set_transient('wc_sspaa_email_stats', $this->stats, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Complete the sync operation and send email report
     */
    public function complete_sync() {
        // Load stats from transient if available
        $saved_stats = get_transient('wc_sspaa_email_stats');
        if ($saved_stats) {
            $this->stats = $saved_stats;
            wc_sspaa_log('EMAIL: Loaded stats from transient', true);
        }
        
        $this->stats['end_time'] = current_time('mysql');
        
        // Calculate duration
        $start = new DateTime($this->stats['start_time']);
        $end = new DateTime($this->stats['end_time']);
        $this->stats['duration'] = $end->getTimestamp() - $start->getTimestamp();
        
        wc_sspaa_log('EMAIL: Sync operation completed - Duration: ' . $this->format_duration($this->stats['duration']), true);
        
        // Send email report
        $this->send_report();
        
        // Clean up transient
        delete_transient('wc_sspaa_email_stats');
    }
    
    /**
     * Add error to the tracking
     * 
     * @param string $error_message Error message
     * @param string $sku Product SKU (optional)
     * @param int $product_id Product ID (optional)
     */
    public function add_error($error_message, $sku = '', $product_id = 0) {
        $error = array(
            'message' => $error_message,
            'sku' => $sku,
            'product_id' => $product_id,
            'time' => current_time('mysql')
        );
        
        $this->stats['errors'][] = $error;
        wc_sspaa_log('EMAIL: Added error - ' . $error_message . 
                    ($sku ? ' for SKU: ' . $sku : '') . 
                    ($product_id ? ' (Product ID: ' . $product_id . ')' : ''), true);
                    
        // Update transient
        set_transient('wc_sspaa_email_stats', $this->stats, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Send the email report
     */
    private function send_report() {
        $subject = sprintf(
            'WooCommerce Stock Sync Report - %s',
            wp_date('Y-m-d H:i:s')
        );
        
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        
        $message = $this->generate_report_html();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <wordpress@' . parse_url($site_url, PHP_URL_HOST) . '>',
            'Reply-To: ' . get_option('admin_email'),
        );
        
        // Log email content for debugging
        wc_sspaa_log('EMAIL: Attempting to send email to: ' . $this->recipient, true);
        wc_sspaa_log('EMAIL: Subject: ' . $subject, true);
        wc_sspaa_log('EMAIL: Headers: ' . print_r($headers, true), true);
        
        // Save a copy of the email content to a file for debugging
        $debug_file = WC_SSPAA_PLUGIN_DIR . 'email_content.html';
        file_put_contents($debug_file, $message);
        wc_sspaa_log('EMAIL: Email content saved to: ' . $debug_file, true);
        
        // First, test WordPress email functionality
        $test_result = $this->test_wp_mail();
        if (!$test_result) {
            wc_sspaa_log('EMAIL: WordPress email test failed. Will attempt to use alternative methods.', true);
        }
        
        // Add email debugging filter
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
        
        // Make sure email is being sent
        add_action('wp_mail_failed', array($this, 'log_mail_error'));
        
        // Use WordPress email function with BCC to ensure delivery
        $to = $this->recipient;
        
        // Use additional force sending approach
        add_filter('wp_mail_from', array($this, 'set_mail_from'));
        add_filter('wp_mail_from_name', array($this, 'set_mail_from_name'));
        
        // Use WordPress email function
        $result = wp_mail($to, $subject, $message, $headers);
        
        // Remove the filters after sending
        remove_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
        remove_filter('wp_mail_from', array($this, 'set_mail_from'));
        remove_filter('wp_mail_from_name', array($this, 'set_mail_from_name'));
        
        if ($result) {
            wc_sspaa_log('EMAIL: Report sent successfully to ' . $this->recipient, true);
            
            // Also send a backup email to admin
            $admin_email = get_option('admin_email');
            if ($admin_email && $admin_email !== $this->recipient) {
                wp_mail($admin_email, '[COPY] ' . $subject, $message, $headers);
                wc_sspaa_log('EMAIL: Backup report sent to admin: ' . $admin_email, true);
            }
        } else {
            wc_sspaa_log('EMAIL: Failed to send report to ' . $this->recipient, true);
            
            // Try an alternative method
            $this->send_alternative_email($subject, $message, $headers);
        }
    }
    
    /**
     * Test WordPress mail functionality with a simple email
     * 
     * @return bool Success or failure
     */
    private function test_wp_mail() {
        wc_sspaa_log('EMAIL: Testing WordPress mail functionality', true);
        
        $admin_email = get_option('admin_email');
        $test_subject = 'WC Stock Sync Email Test - ' . current_time('mysql');
        $test_message = 'This is a test email to verify WordPress email functionality is working correctly. Time: ' . current_time('mysql');
        
        // Try to send a simple test email
        $test_result = wp_mail($admin_email, $test_subject, $test_message);
        
        if ($test_result) {
            wc_sspaa_log('EMAIL: Test email sent successfully to ' . $admin_email, true);
        } else {
            wc_sspaa_log('EMAIL: Failed to send test email to ' . $admin_email, true);
        }
        
        return $test_result;
    }
    
    /**
     * Set HTML content type
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Set mail from address
     */
    public function set_mail_from() {
        $site_url = get_bloginfo('url');
        return 'wordpress@' . parse_url($site_url, PHP_URL_HOST);
    }
    
    /**
     * Set mail from name
     */
    public function set_mail_from_name() {
        return get_bloginfo('name');
    }
    
    /**
     * Log wp_mail errors
     */
    public function log_mail_error($wp_error) {
        wc_sspaa_log('EMAIL ERROR: ' . $wp_error->get_error_message(), true);
    }
    
    /**
     * Alternative email method as fallback
     */
    private function send_alternative_email($subject, $message, $headers) {
        wc_sspaa_log('EMAIL: Attempting alternative email method', true);
        
        // Try PHP's mail function directly
        $header_string = implode("\r\n", $headers);
        $mail_result = mail($this->recipient, $subject, $message, $header_string);
        
        if ($mail_result) {
            wc_sspaa_log('EMAIL: Alternative method success', true);
        } else {
            wc_sspaa_log('EMAIL: Alternative method failed. Last PHP error: ' . error_get_last()['message'], true);
            
            // Save report as a file in case email fails
            $filename = 'stock_sync_report_' . date('Y-m-d_H-i-s') . '.html';
            $file_path = WC_SSPAA_PLUGIN_DIR . $filename;
            file_put_contents($file_path, $message);
            wc_sspaa_log('EMAIL: Saved report to file: ' . $file_path, true);
        }
    }
    
    /**
     * Generate HTML for the email report
     * 
     * @return string HTML content
     */
    private function generate_report_html() {
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        
        $has_errors = !empty($this->stats['errors']);
        $success_rate = $this->stats['total_products'] > 0 
                       ? round(($this->stats['updated'] / $this->stats['total_products']) * 100, 1) 
                       : 0;
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title>WooCommerce Stock Sync Report</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 800px;
                    margin: 0 auto;
                }
                .header {
                    background-color: #2271b1;
                    color: white;
                    padding: 20px;
                    text-align: center;
                }
                .content {
                    padding: 20px;
                }
                .summary {
                    background-color: #f8f9fa;
                    padding: 15px;
                    margin-bottom: 20px;
                    border-radius: 4px;
                }
                .statistics {
                    margin-bottom: 20px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th, td {
                    padding: 10px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }
                th {
                    background-color: #f2f2f2;
                }
                .error-list {
                    background-color: ' . ($has_errors ? '#fff9f9' : '#f8fff9') . ';
                    border-left: 4px solid ' . ($has_errors ? '#dc3232' : '#46b450') . ';
                    padding: 12px;
                    margin-bottom: 20px;
                }
                .footer {
                    font-size: 12px;
                    color: #777;
                    text-align: center;
                    margin-top: 30px;
                    padding-top: 10px;
                    border-top: 1px solid #eee;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>WooCommerce Stock Sync Report</h1>
                <p>' . esc_html($site_name) . ' (' . esc_html($site_url) . ')</p>
            </div>
            
            <div class="content">
                <div class="summary">
                    <h2>Summary</h2>
                    <p>Sync process ' . ($has_errors ? 'completed with errors' : 'completed successfully') . '.</p>
                    <p><strong>Start Time:</strong> ' . esc_html($this->stats['start_time']) . '</p>
                    <p><strong>End Time:</strong> ' . esc_html($this->stats['end_time']) . '</p>
                    <p><strong>Duration:</strong> ' . esc_html($this->format_duration($this->stats['duration'])) . '</p>
                    <p><strong>Success Rate:</strong> ' . esc_html($success_rate) . '%</p>
                </div>
                
                <div class="statistics">
                    <h2>Statistics</h2>
                    <table>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                        </tr>
                        <tr>
                            <td>Total Products</td>
                            <td>' . esc_html($this->stats['total_products']) . '</td>
                        </tr>
                        <tr>
                            <td>Products Updated</td>
                            <td>' . esc_html($this->stats['updated']) . '</td>
                        </tr>
                        <tr>
                            <td>Obsolete Products</td>
                            <td>' . esc_html($this->stats['obsolete']) . '</td>
                        </tr>
                        <tr>
                            <td>Products Skipped</td>
                            <td>' . esc_html($this->stats['skipped']) . '</td>
                        </tr>
                        <tr>
                            <td>Number of Batches</td>
                            <td>' . esc_html($this->stats['batches']) . '</td>
                        </tr>
                        <tr>
                            <td>Errors</td>
                            <td>' . esc_html(count($this->stats['errors'])) . '</td>
                        </tr>
                    </table>
                </div>';
        
        $html .= '<div class="error-list">
                    <h2>' . ($has_errors ? 'Errors' : 'No Errors Detected') . '</h2>';
        
        if ($has_errors) {
            $html .= '<table>
                <tr>
                    <th>Time</th>
                    <th>Product</th>
                    <th>Error</th>
                </tr>';
            
            foreach ($this->stats['errors'] as $error) {
                $product_info = '';
                if (!empty($error['sku'])) {
                    $product_info .= 'SKU: ' . esc_html($error['sku']);
                }
                if (!empty($error['product_id'])) {
                    $product_info .= ($product_info ? ', ' : '') . 'ID: ' . esc_html($error['product_id']);
                }
                
                $html .= '<tr>
                    <td>' . esc_html($error['time']) . '</td>
                    <td>' . ($product_info ? esc_html($product_info) : 'N/A') . '</td>
                    <td>' . esc_html($error['message']) . '</td>
                </tr>';
            }
            
            $html .= '</table>';
        } else {
            $html .= '<p>All products were processed successfully without errors.</p>';
        }
        
        $html .= '</div>
                <div class="footer">
                    <p>This email was automatically generated by the WooCommerce Stock Sync plugin.</p>
                    <p>Plugin Version: ' . WC_SSPAA_VERSION . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Format duration in seconds to a human-readable format
     * 
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function format_duration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = array();
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = $secs . ' second' . ($secs != 1 ? 's' : '');
        }
        
        return implode(', ', $parts);
    }
}
?> 