<?php
class WC_SSPAA_Stock_Sync_Time_Col
{
    public function __construct()
    {
        add_filter('manage_edit-product_columns', array($this, 'add_custom_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'display_sync_info_in_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'register_sortable_columns'));
        add_action('pre_get_posts', array($this, 'sort_custom_column'));
        add_action('restrict_manage_posts', array($this, 'add_sync_status_link'), 10);
    }

    public function add_custom_columns($columns)
    {
        $reordered_columns = array();
        foreach ($columns as $key => $value) {
            if ($key == 'price') {
                $reordered_columns['avenue_stock_sync'] = __('Avenue Stock Sync', 'woocommerce');
            }
            $reordered_columns[$key] = $value;
        }
        return $reordered_columns;
    }

    public function display_sync_info_in_column($column, $post_id)
    {
        if ('avenue_stock_sync' === $column) {
            $last_sync = get_post_meta($post_id, '_wc_sspaa_last_sync', true);
            
            if ($last_sync) {
                echo '<span style="color: #999; white-space: nowrap;">' . esc_html($last_sync) . '</span>';
                
                // Check for obsolete stock API response
                $api_response = get_post_meta($post_id, '_wc_sspaa_api_response', true);
                if ($api_response === '{"products":[],"count":0,"pages":0}') {
                    echo '<br><span style="color: red; font-size: 12px;">Obsolete Stock</span>';
                }
            } else {
                echo '<span style="color: #999;">N/A</span>';
            }
        }
    }

    public function register_sortable_columns($columns)
    {
        $columns['avenue_stock_sync'] = 'avenue_stock_sync';
        return $columns;
    }

    public function sort_custom_column($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ('avenue_stock_sync' === $query->get('orderby')) {
            $query->set('meta_key', '_wc_sspaa_last_sync');
            $query->set('orderby', 'meta_value');
        }
    }
    
    /**
     * Add link to Stock Sync Status page
     */
    public function add_sync_status_link() {
        global $post_type;
        
        if ($post_type === 'product') {
            $url = admin_url('edit.php?post_type=product&page=wc-sspaa-settings');
            echo '<a href="' . esc_url($url) . '" class="button" style="margin-right: 5px;">' . 
                esc_html__('Stock Sync Status', 'woocommerce') . '</a>';
        }
    }
}

new WC_SSPAA_Stock_Sync_Time_Col();
?>