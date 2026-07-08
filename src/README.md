# WooCommerce Pro-rata Shipping VAT

Calculates Dutch pro-rata VAT on WooCommerce shipping costs for carts with mixed VAT rates.

Authors: TheStingPilot and Codex.

## What It Does

The plugin is intended for shops where shipping costs are configured as an amount excluding a reference VAT rate, for example `6.38` excluding `9%` VAT. It first converts that amount to the inclusive customer-facing shipping total, then splits that inclusive total pro-rata over the VAT rates present in the cart.

For example:

```text
6.38 excluding 9% VAT = 6.95 including VAT
```

If the cart contains both 9% and 21% goods, the `6.95` total is distributed by the value of the goods in each VAT group. Each part is then converted back to the correct excluding-VAT amount plus VAT amount.

## Calculation

```text
shipping_including_vat = configured_shipping_cost * (1 + reference_vat_rate)
share_per_vat_rate = goods_excluding_vat_for_rate / total_goods_excluding_vat
shipping_including_vat_for_rate = shipping_including_vat * share_per_vat_rate
shipping_excluding_vat_for_rate = shipping_including_vat_for_rate / (1 + vat_rate)
shipping_vat_for_rate = shipping_including_vat_for_rate - shipping_excluding_vat_for_rate
```

The final WooCommerce shipping rate is updated with:

```text
shipping cost = sum of shipping_excluding_vat_for_rate
shipping taxes = VAT amount per WooCommerce tax rate ID
```

## Settings

Go to `WooCommerce > Settings > Tax`.

The plugin adds:

- Enable pro-rata shipping VAT
- Reference VAT rate for configured shipping costs

Use `9` as the reference VAT rate when your shipping method is currently configured as excluding 9% VAT.

## Customer Display

The plugin shows a compact VAT build-up on the cart and checkout pages. Customers can see the VAT per rate at a glance and open the calculation details for goods excluding VAT, shipping excluding VAT, goods VAT, shipping VAT, and total including VAT.

## Technical Documentation

See `TECHNICAL.md` for a file-by-file and function-by-function explanation of the PHP classes, WooCommerce hooks, Blocks JavaScript, calculation flow, order handling, PDF rendering, debug logging, and WPML behavior.

## WPML Behavior

The plugin uses English as the source language:

```text
en
```

When WPML is active, the current frontend language is read through the `wpml_current_language` filter. The source language can be filtered with `wcprsv_wpml_source_language`, but the admin settings keep the stored source language on English by default.

## Changelog

### 1.0.14

- Limit the single and double underline in the expanded VAT calculation to the final amount only.

### 1.0.13

- Add a single top line and double bottom line around the final total in the expanded VAT calculation.

### 1.0.12

- Add top and bottom divider lines around the VAT specification totals block.
- Keep the grand total emphasized without adding a separate internal divider.

### 1.0.11

- Change plugin gettext source strings from Dutch to English.
- Use English (`en`) as the WPML String Translation source language.
- Reconcile the cart/checkout VAT specification against WooCommerce's cart tax total to prevent one-cent tax mismatches.
- Keep the displayed VAT specification internally consistent by reconciling the inclusive total after tax rounding.

### 1.0.10

- Synchronize existing WPML String Translation records for this text domain to the configured source language.
- Prevent WPML from continuing to show the plugin's gettext strings under the wrong source language after scanning.

### 1.0.9

- Add the PDF VAT specification to WP Overnight credit notes.
- Preserve negative goods and VAT amounts on credit notes while keeping zero shipping amounts displayed as 0.00.

### 1.0.8

- Add WPML-aware source language handling, matching the Toko Lariso free shipping bar pattern.
- Add current/source language information to debug output when WPML is active.
- Load the plugin text domain from `/languages` when translation files are present.
- Add technical documentation with file-by-file and function-by-function explanations.

### 1.0.7

- Always show the VAT specification on cart and checkout, also for free shipping and pickup.
- Show zero shipping amounts in the VAT specification when no shipping costs are charged.
- Fix Blocks refresh logic so the inserted VAT specification can be updated instead of being mistaken for a classic checkout breakdown.
- Update regression tests for zero-shipping VAT specifications.

### 1.0.6

- Rebuild the Blocks VAT specification from current cart items, shipping totals, and tax lines when shipping-rate metadata is missing.
- Fix the specification disappearing after switching from pickup/free shipping back to paid shipping.
- Listen directly to WooCommerce Blocks cart-store changes so the specification refreshes when checkout switches delivery modes.
- Add regression coverage for paid Blocks shipping after metadata has been lost during checkout updates.

### 1.0.5

- Treat WooCommerce Blocks cart totals as authoritative for the current selected shipping total.
- Hide the pro-rata shipping VAT specification when Blocks reports zero shipping, even if stale paid shipping-rate metadata is still present.
- Add a regression test for pickup/free shipping with stale paid Blocks metadata.

### 1.0.4

- Use only the selected WooCommerce Blocks shipping rate when rendering the pro-rata VAT specification.
- Hide the specification for selected free shipping or pickup rates even when non-selected paid rates still contain plugin metadata.
- Use WooCommerce Blocks tax-line totals for goods VAT in the display to prevent one-cent differences.
- Extend local Blocks regression tests for paid shipping, free shipping via coupon/pickup, and tax-line rounding.

### 1.0.3

- Add pro-rata breakdown metadata directly to WooCommerce Blocks Store API shipping-rate output.
- Make Blocks display less dependent on a separate REST cart reconstruction by letting `wc/store/cart` carry the plugin breakdown.
- Add local regression coverage for Store API shipping-rate metadata enrichment.

### 1.0.2

- Fix WooCommerce Blocks cart/checkout display when the custom REST endpoint cannot see the WooCommerce server-side cart.
- Send the WooCommerce Blocks cart-store snapshot to the plugin endpoint and recover the pro-rata breakdown from shipping-rate metadata.
- Add the WooCommerce Blocks data-store script dependency when available.

### 1.0.1

- Hide the pro-rata shipping VAT specification when the actual cart shipping total is zero, including free shipping via coupons.
- Recalculate the customer-facing cart/checkout breakdown from the actual current cart shipping total when stored shipping-rate metadata no longer matches the cart total.
- Remove stale Blocks checkout/cart breakdown markup when the cart changes to free shipping.

### 1.0.0

First production release.

This release contains the complete implementation and stabilization work from the 0.1.x development cycle:

- Calculate Dutch pro-rata VAT on WooCommerce shipping costs by converting the configured shipping amount to an inclusive amount and distributing it over the VAT rates present in the cart.
- Support standard WooCommerce tax rates, including carts with 0%, 9%, 21%, and mixed VAT products.
- Preserve the customer-facing inclusive shipping total while correcting the internal split between shipping excluding VAT and VAT per tax rate.
- Add compatibility declarations for WooCommerce Cart and Checkout Blocks and HPOS.
- Support both WooCommerce Blocks checkout and classic checkout.
- Store the pro-rata breakdown in session data, shipping rate metadata, order shipping item metadata, and order metadata so later order views and invoices use the original calculation instead of recalculating from already-adjusted amounts.
- Persist corrected shipping totals and shipping tax amounts on WooCommerce order shipping items.
- Apply the pro-rata shipping VAT amounts to WooCommerce order tax lines so order totals, customer order views, invoices, and accounting exports use the same amounts.
- Add late checkout finalizers for both Store API / Blocks checkout and classic checkout to repair order totals after WooCommerce has created the order.
- Reconcile order totals when WooCommerce keeps stale shipping tax values after recalculation.
- Add a compact customer-facing VAT specification on cart, checkout, and order detail pages.
- Add an expandable calculation view showing goods excluding VAT, shipping excluding VAT, goods VAT, shipping VAT, and totals per VAT rate.
- Add a PDF invoice VAT specification for WP Overnight PDF invoices.
- Improve the VAT specification layout from a raw table to a compact, responsive summary with detail rows.
- Use Excel-style two-decimal rounding for visible values and reconcile displayed totals so customers do not see cent-level inconsistencies.
- Use stored WooCommerce order tax totals for invoice/order VAT specification rows to avoid one-cent differences between WooCommerce totals and the PDF specification.
- Fix Store API / Blocks checkout cases where selected shipping rate metadata was unavailable by recalculating from package/order data when needed.
- Fix checkout fatal errors caused by WooCommerce API differences, including unavailable `WC_Shipping_Rate::get_meta()` and protected shipping item tax methods.
- Add compatibility guards around WooCommerce tax item methods.
- Add a debug setting that logs calculation stages to the browser console and WooCommerce logs.
- Make debug logging compact and exception-safe so it cannot block checkout.
- Add a WooCommerce admin maintenance page to analyze and explicitly recalculate existing orders.
- Store audit metadata when an order is manually recalculated, including previous breakdown data, recalculation time, user, and note.
- Improve existing order recalculation by recovering the original inclusive shipping amount from stored breakdown metadata, previous recalculation history, and shipping item metadata.
