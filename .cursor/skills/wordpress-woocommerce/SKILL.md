---
name: wordpress-woocommerce
description: Professional WordPress/WooCommerce plugin development for WooCommerce Stock Sync with Pronto Avenue API. Covers plugin architecture, WC hooks, API integration, stock sync workflows, cron scheduling, logging, security, and database patterns. Use when adding features, fixing bugs, refactoring, or reviewing any PHP code in this plugin (wc_sspaa_ prefix, WC_SSPAA_ constants, class-*.php files, or the main plugin file).
---

# WordPress/WooCommerce – Stock Sync with Pronto API

## Plugin identity

| Item | Value |
|---|---|
| Function prefix | `wc_sspaa_` |
| Class prefix | `WC_SSPAA_` |
| Constant prefix | `WC_SSPAA_` |
| Text domain | `woocommerce-stock-sync-pronto-avenue-api` |
| Min PHP | 8.2 (strict types everywhere) |
| Log retention | 14 days |

Every new function, class, or constant **must** follow the prefix conventions above.

---

## Core architecture

```
woocommerce-stock-sync-pronto-avenue-api.php   ← bootstrap, hooks, cron registration
includes/
  config.php              ← API credentials (WCAP_API_URL/USERNAME/PASSWORD constants)
  class-api-handler.php   ← WC_SSPAA_API_Handler  (HTTP to Pronto API, fallback logic)
  class-stock-updater.php ← WC_SSPAA_Stock_Updater (loops products, calls API, writes stock)
  class-gtin-updater.php  ← WC_SSPAA_GTIN_Updater  (syncs APN field → _wc_gtin meta)
  class-stock-sync-time-col.php      ← admin products list column
  class-products-page-sync-button.php← manual trigger button
logs/
  .htaccess / index.php   ← deny direct access to log files
```

**New classes** go in `includes/class-{slug}.php`, named `WC_SSPAA_{Pascal_Case}`, and are `require_once`-d in the bootstrap file.

---

## Logging

Always use the plugin logger — never `error_log()` directly:

```php
wc_sspaa_log( "[CONTEXT] Message with detail: {$variable}" );
```

- Prefix with a bracketed context tag, e.g. `[OBSOLETE CHECK]`, `[FALLBACK API]`, `[CRON]`.
- Include SKU or product ID when available for traceability.
- Never log credentials, tokens, or PII.

---

## API handler patterns

```php
// Instantiate with default credentials from config.php constants
$api = new WC_SSPAA_API_Handler();

// Fetch with automatic primary → fallback chain
$data = $api->get_product_data( $sku );

// Check obsolete across both SCS + DEFAULT APIs
$result = $api->check_obsolete_status( $sku );
// $result['is_obsolete'] bool, $result['scs_response'], $result['default_response']
```

Rate limiting: `WC_SSPAA_API_DELAY_MICROSECONDS` (200 000 µs = 5 calls/s). Use `usleep( WC_SSPAA_API_DELAY_MICROSECONDS )` between calls; never remove or bypass this.

All `wp_remote_get()` calls **must** set an explicit timeout:

```php
wp_remote_get( $url, [
    'timeout'  => 30,
    'headers'  => [ 'Authorization' => 'Basic ' . base64_encode( "{$user}:{$pass}" ) ],
] );
```

---

## Stock update workflow

```
wc_sspaa_update_stock()   ← called by WP-Cron or manual trigger
  └─ WC_SSPAA_Stock_Updater::update_all_products()
       └─ for each WooCommerce product/variation with _sku:
            ├─ skip if SKU in WC_SSPAA_EXCLUDED_SKUS
            ├─ WC_SSPAA_API_Handler::get_product_data( $sku )
            ├─ WC_SSPAA_Stock_Updater::update_product_stock( $product_id, $api_data )
            └─ update _wc_sspaa_last_sync postmeta timestamp
```

**Product retrieval** — always use WooCommerce CRUD, not raw `get_post()`:

```php
$product = wc_get_product( $product_id );
if ( ! $product instanceof WC_Product ) {
    wc_sspaa_log( "[STOCK] Could not load product ID {$product_id}" );
    return;
}
$product->set_stock_quantity( $qty );
$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
$product->save();
```

---

## Cron scheduling

Schedules are domain-aware (see `WC_SSPAA_DOMAIN_SYNC_SCHEDULE`). When modifying cron:

1. Clear the old hook before re-registering: `wp_clear_scheduled_hook( 'wc_sspaa_sync_event' )`.
2. Use Sydney (Australia/Sydney) timezone for time calculations.
3. Register via `register_activation_hook` and clear via `register_deactivation_hook`.
4. Cron callbacks must be idempotent — safe to run multiple times without side effects.

---

## SKU exclusions

```php
// Check before processing any product
if ( defined('WC_SSPAA_EXCLUDED_SKUS') && in_array( $sku, WC_SSPAA_EXCLUDED_SKUS, true ) ) {
    wc_sspaa_log( "[SKIP] SKU {$sku} is in the exclusion list." );
    continue;
}
```

Add/remove SKUs only in the `WC_SSPAA_EXCLUDED_SKUS` constant in the bootstrap file.

---

## Database queries

Use `$wpdb` with `prepare()` for all custom queries:

```php
global $wpdb;
$value = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
        $product_id,
        '_sku'
    )
);
```

Prefer `get_post_meta()` / `update_post_meta()` for single meta reads/writes — only drop to `$wpdb` when bulk efficiency is required (e.g., fetching all SKUs at once).

---

## Security checklist

- **Nonces**: verify with `check_admin_referer()` or `check_ajax_referer()` on every form/AJAX handler.
- **Capabilities**: gate admin actions with `current_user_can( 'manage_woocommerce' )`.
- **Sanitisation**: `sanitize_text_field()`, `absint()`, `wp_kses_post()` for inputs.
- **Output escaping**: `esc_html()`, `esc_attr()`, `esc_url()` on all output.
- **Direct access guard**: `if ( ! defined('ABSPATH') ) { exit; }` at top of every PHP file.

---

## Hooks and extensibility

Register all hooks inside a dedicated init function or class constructor — never at file scope:

```php
add_action( 'init', 'wc_sspaa_init' );
function wc_sspaa_init(): void {
    // safe to use WC/WP APIs here
}
```

Unregister hooks on deactivation where relevant to avoid orphaned cron events or meta boxes.

---

## Versioning and changelog

After any functional change:

1. Bump `Version:` in the plugin header (main PHP file).
2. Add entry to `README.md` changelog section.
3. Follow semver: PATCH for bug fixes, MINOR for backward-compatible features, MAJOR for breaking changes.

---

## Additional reference

- For detailed API integration patterns and error-handling examples, see [patterns-reference.md](patterns-reference.md).
