# Technical Documentation

Version: 2.1.0

This plugin calculates the VAT composition of WooCommerce shipping costs according to the Dutch pro-rata principle. It reads WooCommerce's own tax settings to decide how configured shipping costs must be interpreted.

When WooCommerce prices are entered including VAT, the configured shipping cost is treated as excluding the WooCommerce shipping tax class. The plugin first converts that configured cost to the customer-facing inclusive shipping amount and then splits that amount across the VAT rates present in the cart.

When WooCommerce prices are entered excluding VAT, the configured shipping cost is already excluding VAT. The plugin splits that excluding-VAT shipping amount directly across the VAT rates present in the cart and then calculates VAT per rate.

The plugin supports:

- WooCommerce classic cart and checkout.
- WooCommerce Cart/Checkout Blocks.
- WooCommerce HPOS.
- WP Overnight PDF Invoices & Packing Slips invoices and credit notes.
- Manual recalculation of existing orders.
- WPML source language `en`.

## Files

```text
woocommerce-pro-rata-shipping-vat.php
includes/class-wcprsv-calculator.php
includes/class-wcprsv-settings.php
includes/class-wcprsv-plugin.php
assets/frontend.js
assets/frontend.css
uninstall.php
tools/test-calculator.php
tools/test-blocks-snapshot.php
```

## Bootstrap File

### `woocommerce-pro-rata-shipping-vat.php`

This is the WordPress plugin bootstrap file.

It does the following:

- Defines the WordPress plugin header.
- Defines `WCPRSV_VERSION`, `WCPRSV_FILE`, and `WCPRSV_PATH`.
- Declares WooCommerce Cart/Checkout Blocks compatibility.
- Declares WooCommerce HPOS compatibility.
- Loads the three PHP classes.
- Checks on `plugins_loaded` whether WooCommerce is active.
- Starts the plugin with `WCPRSV_Plugin::instance()->init()`.

If WooCommerce is not active, the file shows an admin notice.

## Calculator

### `includes/class-wcprsv-calculator.php`

This class contains only the calculation logic. It does not know about WordPress hooks, carts, orders, HTML, or JavaScript. This keeps the calculation testable and isolated.

### Class `WCPRSV_Calculator`

#### `calculate($shipping_excluding_reference_vat, $reference_vat_rate, array $goods_by_tax_rate, $price_decimals = 2)`

Used for shops where WooCommerce prices are entered including VAT. The reference VAT rate is no longer a plugin setting; it is derived from WooCommerce's `woocommerce_shipping_tax_class` setting.

Example:

```text
6.38 excluding 9% VAT = 6.95 including VAT
```

The function:

- Prevents negative input from affecting the result.
- First converts the configured shipping amount to an inclusive shipping amount.
- Calls `calculate_from_including_vat()`.

#### `calculate_from_excluding_vat($shipping_excluding_vat, array $goods_by_tax_rate, $price_decimals = 2)`

Used for shops where WooCommerce prices are entered excluding VAT.

Input:

- Total shipping amount excluding VAT.
- Goods amount excluding VAT per VAT rate.
- Number of price decimals.

The function:

- Calculates the total goods amount excluding VAT.
- Determines each VAT rate's share of the goods value.
- Splits the excluding-VAT shipping amount over those shares.
- Calculates shipping VAT per rate.
- Calculates shipping including VAT per rate.
- Rounds visible and reusable monetary values per cell, similar to Excel `ROUND(..., 2)`.
- Applies any cent-level excluding-VAT rounding difference to the largest goods group.

#### `calculate_from_including_vat($shipping_including_vat, array $goods_by_tax_rate, $price_decimals = 2)`

This is the main calculation function.

Input:

- Total shipping amount including VAT.
- Goods amount excluding VAT per VAT rate.
- Number of price decimals.

The function:

- Calculates the total goods amount excluding VAT.
- Determines each VAT rate's share of the goods value.
- Splits the inclusive shipping amount over those shares.
- Converts each shipping share back to an excluding-VAT amount.
- Calculates shipping VAT per rate.
- Rounds visible and reusable monetary values per cell, similar to Excel `ROUND(..., 2)`.
- Applies any cent-level rounding difference to the largest goods group.

Output:

```text
shipping_including_vat
shipping_excluding_vat
taxes
lines
```

Each line contains values such as:

```text
goods_amount_ex_vat
vat_rate
share
shipping_excluding_vat
shipping_vat
shipping_including_vat
```

#### `sum_goods(array $goods_by_tax_rate)`

Adds all goods amounts excluding VAT.

#### `apply_rounding_delta(array &$lines, array &$taxes, $target_including, $price_decimals)`

Checks whether the rounded shipping lines add up exactly to the target inclusive shipping amount. If there is a cent-level difference, the difference is applied to the largest goods group.

## Settings

### `includes/class-wcprsv-settings.php`

This class manages the plugin settings in WooCommerce.

### Class `WCPRSV_Settings`

#### Constants

```text
OPTION_ENABLED
OPTION_DEBUG
OPTION_WPML_SOURCE_LANGUAGE
```

These constants are the WordPress option names used by the plugin.

#### `init()`

Registers hooks:

- Adds settings to `WooCommerce > Settings > Tax`.
- Migrates existing installations to WPML source language `en` in the WordPress admin.
- Forces the WPML source language to `en` when tax settings are saved.

#### `is_enabled()`

Returns whether the plugin is enabled. Default: `yes`.

#### `is_debug_enabled()`

Returns whether debug mode is enabled.

#### `get_wpml_source_language()`

Returns the WPML source language. Default: `en`.

The value can be filtered with:

```text
wcprsv_wpml_source_language
```

#### `get_current_language()`

Reads the current language through WPML:

```text
wpml_current_language
```

If WPML is not active, it falls back to the source language.

#### `force_wpml_source_language()`

Stores `en` as the source language. This follows the same design as the Toko Lariso Free Shipping Bar Pro plugin: the plugin explicitly controls the WPML source-language value. Existing `nl` values from older plugin versions are migrated to `en` in the admin.

#### `add_tax_settings($settings, $current_section)`

Adds settings to the WooCommerce tax settings page:

- Enable or disable the plugin.
- Debug mode.
- WPML source-language explanation.

The plugin deliberately does not add its own reference VAT rate setting. It uses WooCommerce's own tax settings:

- `woocommerce_prices_include_tax`
- `woocommerce_shipping_tax_class`

## WooCommerce Integration

### `includes/class-wcprsv-plugin.php`

This is the main plugin class. It contains the WooCommerce hooks, Blocks support, order handling, PDF output, debug logging, and maintenance page.

### Class `WCPRSV_Plugin`

#### `instance()`

Singleton entry point. Ensures that only one plugin instance exists.

#### `__construct()`

Creates the settings and calculator services.

#### `init()`

Registers all WordPress and WooCommerce hooks.

Important hooks:

- `woocommerce_package_rates`: recalculates shipping costs and shipping VAT.
- `woocommerce_store_api_cart_shipping_rates`: enriches Blocks shipping rates with breakdown metadata.
- `woocommerce_checkout_create_order_shipping_item`: writes shipping VAT to the order shipping item.
- `woocommerce_checkout_create_order`: stores the breakdown on the order.
- `woocommerce_store_api_checkout_order_processed`: finalizes Blocks orders.
- `woocommerce_checkout_order_processed`: finalizes classic checkout orders.
- `woocommerce_cart_totals_after_order_total`: classic cart display.
- `woocommerce_review_order_after_order_total`: classic checkout display.
- `woocommerce_order_details_after_order_table`: VAT specification on order details.
- `wpo_wcpdf_after_order_details`: VAT specification on PDF invoices and credit notes.
- `wp_enqueue_scripts`: loads frontend CSS and JavaScript.
- `rest_api_init`: registers the custom REST endpoint for Blocks.
- `admin_menu`: adds the maintenance page.

#### `load_textdomain()`

Loads translation files from `/languages` when present.

#### `sync_wpml_string_source_language()`

Synchronizes existing WPML String Translation records for this plugin's text domain to the English source language.

Records in the `icl_strings` table with context `wc-pro-rata-shipping-vat` are updated to source language `en`, so WPML String Translation treats the plugin's gettext strings as English source strings.

The update is limited to this plugin's text domain and runs in the WordPress admin when WPML String Translation is available.

#### `is_wpml_string_translation_available()`

Checks whether WPML String Translation appears to be active before attempting to synchronize string source language records.

#### `recalculate_package_rates($rates, $package)`

Recalculates WooCommerce shipping rates.

The function:

- Checks whether the plugin and WooCommerce taxes are enabled.
- Groups goods by VAT rate.
- Reads WooCommerce's configured shipping cost.
- For shops with prices entered including VAT, converts the configured shipping cost with the WooCommerce shipping tax class before the pro-rata split.
- For shops with prices entered excluding VAT, splits the configured shipping cost directly as excluding VAT.
- Sets the shipping cost to the total excluding VAT.
- Sets shipping taxes per WooCommerce tax rate ID.
- Stores the breakdown as rate metadata and in the WooCommerce session.

#### `enrich_store_api_shipping_rates($shipping_rates, $cart)`

Used for WooCommerce Blocks. Blocks uses the Store API and does not always expose the same PHP objects as the classic checkout.

This function:

- Loops through Store API shipping rates.
- Finds the matching WooCommerce shipping rate.
- Reads or calculates the breakdown.
- Adds `_wcprsv_breakdown` to the Store API response.

#### `is_store_api_rate_free(array $rate_data)`

Determines whether a Store API shipping rate is free.

#### `add_breakdown_to_store_api_rate(array &$rate_data, array $breakdown)`

Adds breakdown data to a Blocks shipping rate, both as a direct array value and as `meta_data`.

#### `set_order_shipping_item_taxes($item, $package_key, $package, $order)`

Runs when WooCommerce creates an order shipping item.

The function:

- Calculates the breakdown for the shipping item.
- Sets the correct taxes on the shipping item.
- Stores the breakdown in order item meta.

#### `calculate_order_shipping_item_breakdown($item, array $package)`

Calculates the breakdown for a shipping item during order creation.

#### `store_order_breakdown($order, $data)`

Stores the complete breakdown in order meta.

#### `finalize_store_api_order($order)`

Finalizes an order created through the WooCommerce Store API.

#### `finalize_classic_order($order_id, $posted_data, $order)`

Finalizes an order created through the classic checkout.

#### `finalize_order_shipping_breakdown($order)`

Central order finalization routine:

- Finds or calculates the breakdown.
- Applies shipping item taxes.
- Rebuilds order tax lines.
- Reconciles the order total when needed.
- Stores debug data when debug mode is enabled.

#### `apply_breakdown_to_order_shipping_items($order, array $breakdown)`

Applies the breakdown to all shipping items on the order.

#### `set_shipping_item_tax_data($item, array $taxes)`

Sets tax data on a shipping item without calling protected WooCommerce methods.

#### `reconcile_order_total($order, array $breakdown)`

Checks whether the order total matches goods, shipping, and VAT. Reconciles where needed.

#### `calculate_expected_order_total($order, array $breakdown)`

Calculates the expected order total from order lines and the breakdown.

## Maintenance Page

#### `register_admin_menu()`

Adds the maintenance page under WooCommerce.

#### `render_maintenance_page()`

Renders the maintenance page. An administrator can analyze orders or explicitly recalculate them.

#### `parse_order_ids($input)`

Reads order IDs from text input.

#### `analyze_order_recalculation($order)`

Analyzes what recalculation would do for an order.

#### `get_order_maintenance_shipping_including_vat($order)`

Determines the shipping amount including VAT for maintenance and recalculation.

#### `get_stored_order_breakdown_candidates($order)`

Finds existing breakdowns in order meta and shipping item meta.

#### `add_breakdown_candidate(array &$candidates, $breakdown, $source)`

Adds a found breakdown to the analysis candidate list.

#### `recalculate_existing_order($order, array $analysis)`

Explicitly recalculates an existing order.

Important: this function does not export anything to bookkeeping. It only updates the WooCommerce order and invoice basis.

#### `render_maintenance_results(array $results)`

Displays analysis or recalculation results.

## Frontend Display

#### `render_classic_breakdown()`

Renders the VAT specification in the classic cart and classic checkout.

#### `render_order_breakdown($order)`

Renders the VAT specification on the customer order page.

#### `render_pdf_invoice_breakdown($document_type, $order)`

Renders the VAT specification on PDF invoices and credit notes generated by WP Overnight PDF Invoices & Packing Slips.

#### `enqueue_frontend_assets()`

Loads:

- `assets/frontend.css`
- `assets/frontend.js`

Passes these values to JavaScript:

- REST endpoint.
- REST nonce.
- Debug state.
- Price decimals.

#### `register_storefront_endpoint()`

Registers:

```text
/wp-json/wcprsv/v1/breakdown
```

This endpoint is used by `frontend.js` for Blocks.

#### `get_breakdown_response($request = null)`

Returns JSON to the frontend:

```text
has_breakdown
html
debug
```

For Blocks, the frontend can send a `blocks_snapshot`. If the normal PHP cart does not contain a breakdown, the server can reconstruct the specification from that snapshot.

## Classic and Session Breakdown

#### `get_selected_shipping_breakdown()`

Attempts to find the breakdown for the chosen shipping method. If no usable stored breakdown exists, the plugin recalculates from the current cart.

#### `get_package_shipping_breakdown($package_key, $package)`

Finds the breakdown for a shipping package.

#### `calculate_package_rate_breakdown($rate, array $package)`

Calculates the breakdown for a WooCommerce shipping rate.

#### `get_shipping_rate_meta($rate, $key)`

Reads metadata from a shipping rate.

#### `store_session_breakdown($rate_id, array $breakdown)`

Stores a breakdown in the WooCommerce session.

#### `get_session_breakdown($rate_id)`

Reads a breakdown from the WooCommerce session.

#### `calculate_current_cart_breakdown()`

Recalculates the specification from the current WooCommerce cart. Even when shipping is zero, it returns a specification with shipping amounts set to `0.00`.

## Blocks Snapshot Flow

WooCommerce Blocks can lose shipping metadata while the customer switches between shipping, pickup, coupon states, and refreshes. The plugin therefore does not rely only on stored shipping metadata.

#### `calculate_breakdown_from_blocks_snapshot(array $snapshot)`

Main Blocks function.

The function:

- Reads current shipping totals.
- Reads selected shipping rates.
- Ignores stale paid shipping metadata when current shipping is zero.
- Uses metadata when it is available and matches the current totals.
- Rebuilds the specification from items, totals, and tax lines when metadata is missing.

#### `calculate_breakdown_from_blocks_totals(array $snapshot, $shipping_including_vat)`

Blocks reconstruction fallback.

Uses:

- Cart items.
- Shipping total.
- Tax lines.

#### `build_zero_shipping_breakdown(array $goods_by_tax_rate)`

Builds a VAT specification when shipping is free.

All shipping fields become zero:

```text
shipping_excluding_vat = 0.00
shipping_vat = 0.00
shipping_including_vat = 0.00
```

Goods and goods VAT remain visible.

#### `get_blocks_snapshot_selected_shipping_rates(array $snapshot)`

Reads selected shipping rates from a Blocks snapshot.

#### `blocks_snapshot_has_shipping_totals(array $snapshot)`

Checks whether the snapshot contains shipping totals.

#### `get_blocks_shipping_rates_including_vat(array $rates, array $snapshot)`

Calculates the selected shipping amount including VAT from Store API rate data.

#### `get_blocks_snapshot_shipping_including_vat(array $snapshot)`

Reads the shipping amount including VAT from Blocks totals.

#### `get_blocks_snapshot_minor_units(array $snapshot)`

Reads the number of currency minor units from the Store API. For euros this is normally `2`.

#### `parse_store_api_amount($amount, $minor_units)`

Converts Store API amounts to normal decimal amounts.

Example:

```text
695 with minor_units 2 becomes 6.95
```

#### `get_blocks_snapshot_breakdown_candidates(array $snapshot)`

Searches for breakdown metadata in a complete Blocks snapshot.

#### `get_blocks_shipping_rate_breakdown_candidates(array $rates)`

Searches for breakdown metadata only in selected shipping rates.

#### `apply_blocks_snapshot_goods_tax(array $breakdown, array $snapshot)`

Uses WooCommerce Blocks tax lines to correct goods VAT. This prevents cent differences between the VAT specification and the WooCommerce total.

#### `get_blocks_snapshot_tax_by_rate(array $snapshot)`

Reads tax lines and groups VAT amounts by percentage.

#### `get_blocks_snapshot_goods_by_tax_rate(array $snapshot)`

Reads cart items from Blocks and determines goods amounts per VAT rate.

#### `get_blocks_snapshot_items(array $snapshot)`

Reads items from `cartData.items` or `items`.

#### `match_blocks_snapshot_tax_rate($item_rate, array $known_rate_keys)`

Matches an item-derived VAT percentage to a known tax line. This avoids mistakes caused by rounding.

#### `parse_store_api_tax_line_rate(array $tax_line)`

Reads the VAT percentage from a Store API tax line.

#### `collect_blocks_snapshot_breakdowns($value, array &$candidates)`

Recursively searches nested arrays for `_wcprsv_breakdown`.

#### `add_blocks_breakdown_candidate(array &$candidates, $value)`

Adds a found breakdown to the candidate list.

#### `combine_breakdowns(array $breakdowns)`

Combines multiple breakdowns into one total breakdown.

## HTML and PDF

#### `get_frontend_debug_payload(array $breakdown, $blocks_snapshot = null)`

Builds debug data for the browser console. It contains cart totals, packages, breakdown data, Blocks information, and WPML language information.

#### `get_current_shipping_including_vat($allow_rate_fallback = true)`

Determines the current shipping amount including VAT.

#### `get_breakdown_html(array $breakdown, $order = null)`

Builds the customer-facing HTML specification.

Contains:

- A table with rate, goods, shipping, and VAT.
- Totals excluding VAT, VAT, and including VAT.
- Expandable `Show calculation` details.

#### `get_pdf_breakdown_html(array $breakdown, $order)`

Builds a PDF-friendly HTML table for invoices.

#### `sum_goods_vat(array $lines)`

Adds goods VAT.

#### `get_display_lines(array $lines, array $breakdown, $order = null)`

Converts raw calculator lines into rounded display lines.

#### `reconcile_display_lines(array &$display_lines, array $breakdown, $largest_key, $order = null)`

Adjusts display lines so that displayed totals match cart or order totals.

#### `sum_display_column(array $lines, $column)`

Adds one column across display lines.

#### `get_order_goods_tax_by_rate($order)`

Reads goods VAT per tax rate from an order.

#### `round_money($value)`

Rounds monetary amounts using WooCommerce price decimals.

#### `get_cart_total_including_vat()`

Reads the cart total including VAT.

#### `is_supported_pdf_document_type($document_type)`

Checks whether a WP Overnight document type should receive a VAT specification. Supported types include invoices and credit notes.

#### `is_credit_note_document_type($document_type)`

Checks whether the current PDF document is a credit note.

#### `normalize_pdf_document_type($document_type)`

Normalizes document type strings such as `credit_note`, `credit-note`, and `refund` to stable internal names.

## Order Breakdown

#### `get_order_breakdown($order)`

Retrieves the best breakdown for an order.

#### `get_credit_note_breakdown($credit_note)`

Builds a VAT specification for a credit note or refund document. Amounts are kept negative for credited goods and VAT, while zero shipping remains `0.00`.

#### `negate_breakdown_amounts(array $breakdown)`

Converts a positive breakdown into a credit-note breakdown by making monetary amounts negative. Zero values remain zero to avoid displaying negative zero amounts.

#### `get_breakdown_from_order_shipping_items($order)`

Reconstructs a breakdown from order shipping items.

#### `apply_order_tax_lines($order, array $breakdown)`

Ensures order tax lines match the breakdown.

#### `create_order_tax_item($rate_id)`

Creates a WooCommerce order tax item for a VAT rate.

## Debug

#### `debug_log($stage, array $data = array())`

Writes debug data to the WooCommerce logger when debug mode is enabled.

#### `sanitize_debug_data($data, $depth = 0)`

Limits debug data so logs stay compact and cannot block checkout.

#### `summarize_packages(array $packages)`

Builds a compact summary of WooCommerce shipping packages.

#### `summarize_package(array $package)`

Summarizes one package.

#### `summarize_order_tax_items($order)`

Summarizes order tax items.

## Goods and VAT Grouping

#### `get_order_goods_by_tax_rate($order)`

Groups order lines by VAT rate.

#### `get_credit_note_goods_by_tax_rate($credit_note)`

Groups credit note or refund item totals by VAT rate while preserving negative amounts.

#### `add_credit_note_goods_amount(array &$goods_by_tax_rate, $rate_id, $rate, $amount, $goods_vat)`

Adds a credit note line amount and VAT amount to a VAT group.

#### `get_tax_rate_decimal_by_id($rate_id)`

Reads a WooCommerce tax rate as a decimal.

#### `format_vat_rate($rate)`

Formats for example `0.21` as `21%`.

#### `format_tax_rate_key($rate)`

Builds a stable lookup key for VAT percentages.

#### `get_goods_by_tax_rate($package)`

Groups cart or package contents by VAT rate.

#### `get_line_amount_excluding_vat($cart_item)`

Determines a line amount excluding VAT.

#### `add_goods_amount(array &$goods_by_tax_rate, $rate_id, $rate, $amount)`

Adds a goods amount to a VAT group.

#### `get_primary_tax_rate_id($tax_rates)`

Reads the first WooCommerce tax rate ID from a tax rate array.

#### `get_primary_tax_rate_percentage($tax_rates)`

Reads the first VAT percentage from a tax rate array.

## JavaScript

### `assets/frontend.js`

This script is mainly needed for WooCommerce Blocks. The classic checkout usually receives HTML directly through PHP hooks.

#### Variables

```text
targetSelectors
isRefreshing
refreshTimer
unsubscribeBlocksStore
lastBlocksSignature
```

`targetSelectors` determines where the VAT specification is inserted in the page.

#### `hasClassicBreakdown()`

Checks whether PHP already rendered a classic breakdown. The function ignores the plugin's own Blocks wrapper so the script can replace its own output later.

#### `findTarget()`

Finds the best location in cart or checkout where the specification should be inserted.

#### `render(html)`

Inserts or replaces the Blocks VAT specification.

#### `removeBlockBreakdown()`

Removes the Blocks specification if the server returns no breakdown.

#### `getBlocksSnapshot()`

Reads current Blocks data from:

```text
window.wp.data
window.wc.wcBlocksData.cartStore
```

The snapshot contains:

```text
cartData
totals
shippingRates
```

#### `getBlocksSnapshotSignature(snapshot)`

Builds a compact JSON signature from items, shipping rates, and totals. When this signature changes, the script knows the cart or checkout state has changed.

#### `refresh()`

Requests the VAT specification from:

```text
/wp-json/wcprsv/v1/breakdown
```

For Blocks, the script sends the snapshot as the POST body. The server can then reconstruct a breakdown even when the WooCommerce PHP cart is temporarily empty or stale.

#### `scheduleRefresh()`

Debounced refresh. Prevents multiple immediate requests during small Blocks updates.

#### `watchBlocksStore()`

Listens directly to the WooCommerce Blocks cart store through `wp.data.subscribe`. This updates the specification when the customer switches between shipping, pickup, coupons, or other checkout states.

#### Event Listeners

The script listens to:

```text
DOMContentLoaded
wc-blocks_added_to_cart
wc-blocks_removed_from_cart
wc-blocks_updated_cart_totals
```

## CSS

### `assets/frontend.css`

Contains styling for:

- The compact VAT specification.
- The table-like summary.
- Totals.
- The expandable calculation details.
- Frontend layout independent from PDF output.

## Test Tools

### `tools/test-calculator.php`

Standalone smoke test for the calculator.

Checks include:

- Inclusive-price shops where the configured shipping amount is converted through WooCommerce's shipping tax class first.
- Excluding-price shops where the configured shipping amount is split directly as excluding VAT.
- 9%, 21%, and mixed-rate calculations.
- Cent-level rounding.

### `tools/test-blocks-snapshot.php`

Standalone regression test for WooCommerce Blocks snapshots.

Checks include:

- Store API metadata enrichment.
- Paid shipping with metadata.
- Paid shipping without metadata.
- Excluding-price shops with paid shipping and missing Blocks metadata.
- Integer Store API amounts as minor units.
- Free shipping or pickup with zero shipping amounts.
- Stale paid metadata when shipping is free.

## Main Design Choices

### 1. WooCommerce tax entry mode is leading

For shops where prices are entered including VAT, the customer-facing inclusive shipping total is leading. For shops where prices are entered excluding VAT, the configured excluding-VAT shipping amount is leading. The plugin uses WooCommerce's own tax settings to choose the correct path.

### 2. Round per cell

The plugin rounds monetary values like Excel: visible and reused intermediate values are rounded to two decimals.

### 3. Blocks metadata is useful, but not reliable enough

WooCommerce Blocks can lose shipping metadata during checkout updates. The plugin can therefore rebuild the breakdown from cart items, totals, and tax lines.

### 4. Zero shipping does not mean no specification

The plugin still shows a VAT specification for free shipping and pickup. Shipping columns are shown as `0.00`.

### 5. Orders are leading for invoices

For orders and invoices, the plugin uses stored order data or reconstructs from order lines. This keeps invoices reproducible.

### 6. WPML source language is English

The plugin follows the Toko Lariso approach for explicit source-language control: English (`en`) is the source language. WPML can provide the current frontend language, but the gettext source logic remains English.
