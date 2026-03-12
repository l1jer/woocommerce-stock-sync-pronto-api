# Patterns Reference – WC Stock Sync with Pronto API

Detailed code patterns for less common but important scenarios. Read when SKILL.md guidance is insufficient.

---

## API response validation

The Pronto API can return HTTP 200 with an empty product array (not a network error). Always validate the payload:

```php
private function is_valid_api_response( ?array $result, string $sku ): bool {
    if ( is_null( $result ) ) {
        wc_sspaa_log( "[API VALIDATE] Null response for SKU: {$sku}" );
        return false;
    }
    // API returns { "products": [...] } — empty array means SKU not found
    if ( empty( $result['products'] ) ) {
        wc_sspaa_log( "[API VALIDATE] Empty product list for SKU: {$sku}" );
        return false;
    }
    return true;
}
```

---

## Dual warehouse stock calculation (SkyWatcher AU)

Two warehouses contribute to the displayed stock quantity. Sum them before writing to WooCommerce:

```php
$qty_wh1 = (int) ( $api_data['products'][0]['WarehouseQty1'] ?? 0 );
$qty_wh2 = (int) ( $api_data['products'][0]['WarehouseQty2'] ?? 0 );
$total_qty = $qty_wh1 + $qty_wh2;
```

Only apply this logic when `defined('WC_SSPAA_DUAL_WAREHOUSE_DOMAINS')` and the current domain matches.

---

## Adding a new admin column to the products list

1. Create `includes/class-{column-slug}.php` extending or mimicking `WC_SSPAA_Stock_Sync_Time_Col`.
2. Hook into `manage_edit-product_columns` (add column header) and `manage_product_posts_custom_column` (render cell).
3. For sortable columns, also hook `manage_edit-product_sortable_columns` and `pre_get_posts`.

```php
add_filter( 'manage_edit-product_columns', 'wc_sspaa_add_my_column' );
function wc_sspaa_add_my_column( array $columns ): array {
    $columns['wc_sspaa_my_column'] = __( 'My Column', 'woocommerce-stock-sync-pronto-avenue-api' );
    return $columns;
}

add_action( 'manage_product_posts_custom_column', 'wc_sspaa_render_my_column', 10, 2 );
function wc_sspaa_render_my_column( string $column, int $post_id ): void {
    if ( 'wc_sspaa_my_column' !== $column ) {
        return;
    }
    $value = get_post_meta( $post_id, '_wc_sspaa_my_meta', true );
    echo esc_html( $value ?: '—' );
}
```

---

## AJAX handler pattern (admin-only)

```php
// Register in bootstrap file
add_action( 'wp_ajax_wc_sspaa_my_action', 'wc_sspaa_handle_my_action' );

function wc_sspaa_handle_my_action(): void {
    check_ajax_referer( 'wc_sspaa_my_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => 'Invalid product ID.' ], 400 );
    }

    // Do work...
    wc_sspaa_log( "[MY ACTION] Triggered for product ID: {$product_id}" );

    wp_send_json_success( [ 'message' => 'Done.' ] );
}
```

Enqueue the corresponding nonce via `wp_localize_script()` when registering the JS asset.

---

## Option / transient patterns

```php
// Persistent option
$setting = get_option( 'wc_sspaa_my_setting', 'default_value' );
update_option( 'wc_sspaa_my_setting', sanitize_text_field( $new_value ) );

// Short-lived cache (avoid redundant API calls within a single request cycle)
$cache_key = 'wc_sspaa_api_' . md5( $sku );
$cached = get_transient( $cache_key );
if ( false === $cached ) {
    $cached = $api->get_product_data( $sku );
    set_transient( $cache_key, $cached, 5 * MINUTE_IN_SECONDS );
}
```

Only introduce transients when there is measured evidence of a performance problem.

---

## Error handling in long-running sync

The stock updater runs inside a WP-Cron callback. Use try/catch sparingly — rely on early returns and log errors:

```php
public function process_single_product( int $product_id, string $sku ): void {
    $data = $this->api_handler->get_product_data( $sku );

    if ( ! $this->is_valid_api_response( $data, $sku ) ) {
        wc_sspaa_log( "[SYNC] Skipping product ID {$product_id} — invalid API response." );
        return;
    }

    $product = wc_get_product( $product_id );
    if ( ! $product instanceof WC_Product ) {
        wc_sspaa_log( "[SYNC] Could not load WC_Product for ID {$product_id}." );
        return;
    }

    // Process...
}
```

Set `set_time_limit(0)` at the start of long-running cron callbacks to prevent PHP timeouts mid-sync.

---

## Log file management (14-day retention)

The log rotation helper is already implemented in the bootstrap file (`wc_sspaa_cleanup_old_logs()`). When writing new log files, always name them with the date pattern `wc-sspaa-YYYY-MM-DD.log` so the cleanup routine can identify and remove them correctly.

---

## WooCommerce Settings API tab

When adding a new settings section:

```php
add_filter( 'woocommerce_get_sections_products', 'wc_sspaa_add_settings_section' );
function wc_sspaa_add_settings_section( array $sections ): array {
    $sections['wc_sspaa'] = __( 'Stock Sync', 'woocommerce-stock-sync-pronto-avenue-api' );
    return $sections;
}

add_filter( 'woocommerce_get_settings_products', 'wc_sspaa_get_settings', 10, 2 );
function wc_sspaa_get_settings( array $settings, string $current_section ): array {
    if ( 'wc_sspaa' !== $current_section ) {
        return $settings;
    }
    return [
        [ 'title' => __( 'Stock Sync Settings', 'woocommerce-stock-sync-pronto-avenue-api' ), 'type' => 'title', 'id' => 'wc_sspaa_settings' ],
        [
            'title'   => __( 'Enable Debug Logging', 'woocommerce-stock-sync-pronto-avenue-api' ),
            'type'    => 'checkbox',
            'id'      => 'wc_sspaa_debug_logging',
            'default' => 'yes',
        ],
        [ 'type' => 'sectionend', 'id' => 'wc_sspaa_settings' ],
    ];
}
```

---

## Testing guidance

There is no automated test suite in this plugin yet. Manually verify changes by:

1. Triggering a manual sync via the products page button and checking the log file for the current date.
2. Querying `SELECT meta_value FROM wp_postmeta WHERE meta_key = '_stock' AND post_id = <id>` before and after sync.
3. Checking `_wc_sspaa_last_sync` postmeta is updated to the current timestamp.
4. Confirming excluded SKUs are not updated (check logs for `[SKIP]` entries).
5. Simulating an API failure by temporarily providing an invalid URL and verifying fallback logic triggers.
