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

## Development Status

This is an initial implementation. Before public release, test it against:

- Standard WooCommerce checkout
- Your invoice plugin
- Your e-boekhouden integration
- Carts with only 0%, only 9%, only 21%, and mixed VAT products
- Coupons and discounts
