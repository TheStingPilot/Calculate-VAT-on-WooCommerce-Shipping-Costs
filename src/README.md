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

## Development Status

This is an initial implementation. Before public release, test it against:

- Standard WooCommerce checkout
- Your invoice plugin
- Your e-boekhouden integration
- Carts with only 0%, only 9%, only 21%, and mixed VAT products
- Coupons and discounts

## Changelog

### 0.1.19

- Improve existing order recalculation by recovering the original inclusive shipping amount from stored breakdown metadata, previous recalculations, and shipping item metadata.

### 0.1.18

- Add a WooCommerce admin maintenance page to analyze and explicitly recalculate existing orders.
- Store recalculation audit metadata on updated orders.

### 0.1.17

- Use stored WooCommerce order tax totals for invoice/order VAT specification rows to prevent one-cent display differences.

### 0.1.16

- Remove a direct call to WooCommerce's protected shipping item total-tax setter to prevent checkout fatal errors.

### 0.1.15

- Set shipping item tax data with both total and subtotal tax arrays so WooCommerce order totals include pro-rata shipping VAT.
- Add an order total reconciliation fallback when WooCommerce keeps stale shipping tax totals.

### 0.1.14

- Make debug logging compact and exception-safe so it cannot block checkout.
- Stop recalculating order totals in the early checkout create-order hook; order mutations remain in the late checkout finalizers.

### 0.1.13

- Add a debug setting for browser console and WooCommerce log output.
- Log pro-rata shipping VAT values across package rate calculation, checkout shipping item creation, order finalization, tax line application, and cart breakdown rendering.

### 0.1.12

- Add a late Store API checkout finalizer that reapplies the correct pro-rata shipping totals after WooCommerce Blocks has built the order.
- Save corrected order shipping items, tax lines, order metadata, and totals before payment processing.

### 0.1.11

- Fix mixed-rate Store API orders where the selected shipping rate metadata is unavailable by recalculating the breakdown directly from the order shipping item and package contents.

### 0.1.10

- Add a Store API checkout fallback that recalculates the pro-rata shipping breakdown directly from the selected package rate when shipping rate metadata/session data is unavailable.

### 0.1.9

- Fix checkout fatal error on WooCommerce versions where `WC_Shipping_Rate` does not expose `get_meta()`.
- Add compatibility guards around order tax line methods.

### 0.1.8

- Store the original pro-rata shipping breakdown in the session and on order shipping items so invoices do not recalculate from already-exclusive shipping totals.
- Recalculate order totals after applying pro-rata shipping taxes during checkout.
- Apply pro-rata shipping tax amounts to WooCommerce order tax lines for invoices and accounting exports.

### 0.1.7

- Persist pro-rata shipping tax amounts on WooCommerce order shipping items during checkout.
- Show the VAT breakdown on customer order details and WP Overnight PDF invoices.

### 0.1.6

- Reconcile the displayed VAT breakdown with WooCommerce cart totals so the visible breakdown, checkout total, and accounting export stay aligned.

### 0.1.5

- Round the customer-facing VAT breakdown per visible cell so displayed totals add up exactly.

### 0.1.4

- Improve the VAT breakdown layout with a compact summary and expandable calculation details.

### 0.1.3

- Show the full VAT build-up for goods and shipping on cart and checkout pages.
- Add a direct cart-total fallback for the customer-facing breakdown.

### 0.1.2

- Improve cart and checkout breakdown placement.
- Prevent repeated block checkout refresh calls.

### 0.1.1

- Show the pro-rata shipping VAT calculation on cart and checkout pages.

### 0.1.0

- Initial version.
