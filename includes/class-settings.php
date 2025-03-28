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
        register_setting('wc_sspaa_settings', 'wc_sspaa_email_recipient');
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
        
        // Get schedule info
        $schedule_info = $this->get_batch_schedule_info();
        $cron_info = $this->get_cron_info();
        
        // Output the settings page HTML
        echo '<div class="wrap wc-sspaa-settings">';
        echo '<h1>' . esc_html__('WooCommerce Stock Sync Settings', 'woocommerce') . '</h1>';
        
        echo '<div class="wc-sspaa-current-time">';
        echo '<strong>' . esc_html__('Current time:', 'woocommerce') . '</strong> ' . esc_html($current_time);
        echo ' (' . esc_html($timezone_string) . ')';
        echo '</div>';
        
        // Display any notices from actions
        settings_errors();
        
        // Create a dashboard-style layout
        echo '<div class="wc-sspaa-dashboard">';
        
        // Status card
        echo '<div class="wc-sspaa-card wc-sspaa-status">';
        echo '<div class="wc-sspaa-card-header"><h2>' . esc_html__('Sync Status', 'woocommerce') . '</h2></div>';
        echo '<div class="wc-sspaa-card-content">';
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th>' . esc_html__('Total Products with SKUs', 'woocommerce') . '</th>';
        echo '<td>' . esc_html($total_products) . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Products Per Batch', 'woocommerce') . '</th>';
        echo '<td>' . esc_html($products_per_batch) . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Number of Batches', 'woocommerce') . '</th>';
        echo '<td>' . esc_html($num_batches) . '</td>';
        echo '</tr>';
        
        // Display next scheduled update time
        if (!empty($cron_info['next_batch'])) {
            echo '<tr>';
            echo '<th>' . esc_html__('Next Scheduled Batch', 'woocommerce') . '</th>';
            echo '<td>' . esc_html($cron_info['next_batch']) . ' (UTC)</td>';
            echo '</tr>';
        }
        
        // Display next sync completion time
        if (!empty($cron_info['next_completion'])) {
            echo '<tr>';
            echo '<th>' . esc_html__('Next Sync Completion', 'woocommerce') . '</th>';
            echo '<td>' . esc_html($cron_info['next_completion']) . ' (Sydney)</td>';
            echo '</tr>';
        }
        
        // Display email recipient
        echo '<tr>';
        echo '<th>' . esc_html__('Email Notifications', 'woocommerce') . '</th>';
        echo '<td>' . esc_html(get_option('wc_sspaa_email_recipient', WC_SSPAA_EMAIL_RECIPIENT));
        
        // Add test email button
        $test_url = wp_nonce_url(add_query_arg('wc_sspaa_test_email', '1', admin_url('edit.php?post_type=product&page=wc-sspaa-settings')), 'wc_sspaa_test_email');
        echo ' <a href="' . esc_url($test_url) . '" class="button button-secondary wc-sspaa-test-email">' . esc_html__('Send Test Email', 'woocommerce') . '</a>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Start Time in Sydney', 'woocommerce') . '</th>';
        echo '<td>' . esc_html($start_time) . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th>' . esc_html__('Start Time in UTC', 'woocommerce') . '</th>';
        echo '<td>' . esc_html($start_time_utc) . '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>'; // End card content
        echo '</div>'; // End status card
        
        // Settings card
        echo '<div class="wc-sspaa-card wc-sspaa-settings-form">';
        echo '<div class="wc-sspaa-card-header"><h2>' . esc_html__('Settings', 'woocommerce') . '</h2></div>';
        echo '<div class="wc-sspaa-card-content">';
        
        // Settings form
        echo '<form method="post" action="options.php">';
        settings_fields('wc_sspaa_settings');
        do_settings_sections('wc_sspaa_settings');
        
        echo '<table class="form-table">';
        
        // Start time field
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="wc_sspaa_start_time">' . esc_html__('Start Time', 'woocommerce') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="time" id="wc_sspaa_start_time" name="wc_sspaa_start_time" value="' . esc_attr(get_option('wc_sspaa_start_time', '00:00')) . '" />';
        echo '<p class="description">' . esc_html__('The time to start the daily stock sync (Sydney time).', 'woocommerce') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        // Email recipient field
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="wc_sspaa_email_recipient">' . esc_html__('Email Recipient', 'woocommerce') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="email" id="wc_sspaa_email_recipient" name="wc_sspaa_email_recipient" value="' . esc_attr(get_option('wc_sspaa_email_recipient', WC_SSPAA_EMAIL_RECIPIENT)) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Email address to receive sync notifications and reports.', 'woocommerce') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        submit_button(__('Save Settings', 'woocommerce'));
        echo '</form>';
        
        echo '</div>'; // End card content
        echo '</div>'; // End settings card
        
        // Manual sync card
        echo '<div class="wc-sspaa-card wc-sspaa-manual">';
        echo '<div class="wc-sspaa-card-header"><h2>' . esc_html__('Manual Sync', 'woocommerce') . '</h2></div>';
        echo '<div class="wc-sspaa-card-content">';
        
        echo '<p>' . esc_html__('You can manually trigger a stock sync using the button below.', 'woocommerce') . '</p>';
        
        $manual_sync_url = wp_nonce_url(add_query_arg('wc_sspaa_manual_sync', '1', admin_url('edit.php?post_type=product&page=wc-sspaa-settings')), 'wc_sspaa_manual_sync');
        echo '<a href="' . esc_url($manual_sync_url) . '" class="button button-primary">' . esc_html__('Start Manual Sync', 'woocommerce') . '</a>';
        
        echo '</div>'; // End card content
        echo '</div>'; // End manual sync card
        
        // Scheduled batches card
        echo '<div class="wc-sspaa-card wc-sspaa-scheduled-batches">';
        echo '<div class="wc-sspaa-card-header"><h2>' . esc_html__('Scheduled Batches', 'woocommerce') . '</h2></div>';
        echo '<div class="wc-sspaa-card-content">';
        
        if (empty($schedule_info)) {
            echo '<p>' . esc_html__('No batches are currently scheduled.', 'woocommerce') . '</p>';
        } else {
            echo '<div class="wc-sspaa-table-container">';
            echo '<table class="widefat wc-sspaa-batches-table">';
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
                echo '<tr' . ($count % 2 == 0 ? ' class="alternate"' : '') . '>';
                echo '<td>' . esc_html($count) . '</td>';
                echo '<td>' . esc_html($info['time_utc']) . '</td>';
                echo '<td>' . esc_html($info['time_sydney']) . '</td>';
                echo '<td>' . esc_html($info['offset']) . ' - ' . esc_html(min($info['offset'] + $products_per_batch, $total_products)) . '</td>';
                echo '</tr>';
                $count++;
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>'; // End table container
        }
        
        echo '</div>'; // End card content
        echo '</div>'; // End scheduled batches card
        
        echo '</div>'; // End dashboard
        echo '</div>'; // End .wrap
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
    
    /**
     * Get the current cron info
     */
    private function get_cron_info() {
        $cron_info = array();
        $cron = _get_cron_array();
        
        if (empty($cron)) {
            return $cron_info;
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
                        
                        $cron_info['next_batch'] = $utc_time;
                        $cron_info['next_completion'] = $sydney_time;
                    }
                }
            }
        }
        
        return $cron_info;
    }
}

// Initialize the settings
WC_SSPAA_Settings::get_instance(); 