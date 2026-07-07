=== WooCommerce Pro-rata Shipping VAT ===
Contributors: TheStingPilot, Codex
Tags: woocommerce, vat, tax, shipping, netherlands
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculates Dutch pro-rata VAT on WooCommerce shipping costs for carts with mixed VAT rates.

== Description ==

WooCommerce Pro-rata Shipping VAT recalculates shipping VAT when an order contains products with different VAT rates.

The plugin treats the configured shipping cost as an amount excluding a reference VAT rate, converts it to an inclusive customer-facing amount, and then distributes that inclusive amount proportionally across the VAT rates present in the cart.

This is useful for Dutch shops where shipping costs must follow the VAT treatment of the supplied goods.

Example:

* Shipping configured as 6.38 excluding 9% VAT.
* Customer-facing shipping total becomes 6.95 including VAT.
* If the cart contains 9% and 21% goods, the 6.95 total is split pro-rata over both rates.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate "WooCommerce Pro-rata Shipping VAT" in WordPress.
3. Go to WooCommerce > Settings > Tax.
4. Enable "Pro-rata shipping VAT".
5. Set the reference VAT rate for configured shipping costs. Use 9 if your shipping costs are currently entered excluding 9% VAT.

== Frequently Asked Questions ==

= Does this change the customer-facing shipping total? =

No. The plugin preserves the inclusive shipping amount and changes the internal split between shipping excluding VAT and VAT.

= Which VAT rates are supported? =

The plugin uses WooCommerce tax rates configured for the products in the cart. It is not hardcoded to 0%, 9%, and 21%.

= What happens with free shipping? =

Shipping rates with a zero cost are left unchanged.

== Changelog ==

= 0.1.0 =
* Initial version.
