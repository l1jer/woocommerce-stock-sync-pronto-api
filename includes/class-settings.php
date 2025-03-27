<?php

class WC_SSPAA_Settings {
    private static $instance = null;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Singleton pattern to get instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Add submenu page under Products
     */
    public function add_submenu_page() {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Stock Sync Status', 'woocommerce'),
            __('Stock Sync Status', 'woocommerce'),
            'manage_woocommerce',
            'wc-sspaa-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_sspaa_settings', 'wc_sspaa_start_time');
    }
    
    /**
     * Get the start time for syncing
     * 
     * @return string Start time in H:i format (default 00:59)
     */
    public static function get_start_time() {
        $start_time = get_option('wc_sspaa_start_time', '00:59');
        return $start_time;
    }
    
    /**
     * Get the start time in UTC
     * 
     * @return string Start time in H:i format converted to UTC
     */
    public static function get_start_time_utc() {
        $start_time = self::get_start_time();
        
        // Get site timezone
        $timezone = new DateTimeZone(wp_timezone_string());
        $sydney_timezone = new DateTimeZone('Australia/Sydney');
        
        // Create DateTime object for the start time in Sydney timezone
        $date = new DateTime('today ' . $start_time, $sydney_timezone);
        
        // Convert to UTC
        $date->setTimezone(new DateTimeZone('UTC'));
        
        return $date->format('H:i');
    }
    
    /**
     * Settings page content
     */
    public function settings_page() {
        $timezone_string = wp_timezone_string();
        $current_time = current_time('mysql');
        $start_time = self::get_start_time();
        $start_time_utc = self::get_start_time_utc();
        
        // Count total products with SKUs
        global $wpdb;
        $total_products = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
            LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
            WHERE meta_key='_sku' AND post_type IN ('product', 'product_variation')"
        );
        
        // Calculate number of batches needed (15 products per batch)
        $products_per_batch = 15;
        $num_batches = ceil($total_products / $products_per_batch);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Stock Sync Status', 'woocommerce'); ?></h1>
            
            <div class="wc-sspaa-status-box">
                <h2><?php echo esc_html__('Current Status', 'woocommerce'); ?></h2>
                <p><?php echo esc_html__('Current website time:', 'woocommerce'); ?> <strong><?php echo esc_html($current_time); ?></strong> (<?php echo esc_html($timezone_string); ?>)</p>
                <p><?php echo esc_html__('Total products with SKUs:', 'woocommerce'); ?> <strong><?php echo esc_html($total_products); ?></strong></p>
                <p><?php echo esc_html__('Products per batch:', 'woocommerce'); ?> <strong><?php echo esc_html($products_per_batch); ?></strong></p>
                <p><?php echo esc_html__('Number of batches:', 'woocommerce'); ?> <strong><?php echo esc_html($num_batches); ?></strong></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_sspaa_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html__('Sync Start Time', 'woocommerce'); ?></th>
                        <td>
                            <input type="time" name="wc_sspaa_start_time" value="<?php echo esc_attr($start_time); ?>" />
                            <p class="description">
                                <?php echo esc_html__('Set the time (Australia/Sydney timezone) when the daily sync cycle should begin. The system will schedule batches at 30-minute intervals after this time.', 'woocommerce'); ?>
                            </p>
                            <p class="description">
                                <?php echo esc_html__('Start time in UTC:', 'woocommerce'); ?> <strong><?php echo esc_html($start_time_utc); ?></strong>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div class="wc-sspaa-status-box">
                <h2><?php echo esc_html__('Scheduled Batches', 'woocommerce'); ?></h2>
                <?php
                $schedule_info = $this->get_batch_schedule_info();
                if (empty($schedule_info)) {
                    echo '<p>' . esc_html__('No batches are currently scheduled.', 'woocommerce') . '</p>';
                } else {
                    echo '<table class="widefat">';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>' . esc_html__('Batch', 'woocommerce') . '</th>';
                    echo '<th>' . esc_html__('Schedule Time (UTC)', 'woocommerce') . '</th>';
                    echo '<th>' . esc_html__('Schedule Time (Sydney)', 'woocommerce') . '</th>';
                    echo '<th>' . esc_html__('Products Range', 'woocommerce') . '</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    $count = 1;
                    foreach ($schedule_info as $info) {
                        echo '<tr>';
                        echo '<td>' . esc_html($count) . '</td>';
                        echo '<td>' . esc_html($info['time_utc']) . '</td>';
                        echo '<td>' . esc_html($info['time_sydney']) . '</td>';
                        echo '<td>' . esc_html($info['offset']) . ' - ' . esc_html(min($info['offset'] + 15, $total_products)) . '</td>';
                        echo '</tr>';
                        $count++;
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                }
                ?>
            </div>
        </div>
        <style>
            .wc-sspaa-status-box {
                background: #fff;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                border: 1px solid #ccd0d4;
                padding: 15px;
                margin: 20px 0;
            }
        </style>
        <?php
    }
    
    /**
     * Get scheduled batch information
     */
    private function get_batch_schedule_info() {
        $schedule_info = array();
        $cron = _get_cron_array();
        
        if (empty($cron)) {
            return $schedule_info;
        }
        
        foreach ($cron as $timestamp => $hooks) {
            if (isset($hooks['wc_sspaa_update_stock_batch'])) {
                foreach ($hooks['wc_sspaa_update_stock_batch'] as $key => $event) {
                    if (isset($event['args'][0])) {
                        $offset = $event['args'][0];
                        
                        // UTC time
                        $utc_time = date('Y-m-d H:i:s', $timestamp);
                        
                        // Convert to Sydney time
                        $utc_datetime = new DateTime($utc_time, new DateTimeZone('UTC'));
                        $utc_datetime->setTimezone(new DateTimeZone('Australia/Sydney'));
                        $sydney_time = $utc_datetime->format('Y-m-d H:i:s');
                        
                        $schedule_info[] = array(
                            'offset' => $offset,
                            'time_utc' => $utc_time,
                            'time_sydney' => $sydney_time
                        );
                    }
                }
            }
        }
        
        // Sort by offset
        usort($schedule_info, function($a, $b) {
            return $a['offset'] - $b['offset'];
        });
        
        return $schedule_info;
    }
}

// Initialize the settings
WC_SSPAA_Settings::get_instance(); 