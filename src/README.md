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

## Changelog

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
