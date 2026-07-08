=== WooCommerce Pro-rata Shipping VAT ===
Contributors: TheStingPilot, Codex
Tags: woocommerce, vat, tax, shipping, netherlands
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.14
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

Shipping rates with a zero cost remain zero. The VAT specification is still shown, with shipping amounts displayed as 0.00.

= How does WPML work? =

The plugin follows the same source-language approach as the Toko Lariso free shipping bar: English (`en`) is the source language. When WPML is active, the current language is read through WPML's current-language filter.

== Changelog ==

= 1.0.14 =
* Limit the single and double underline in the expanded VAT calculation to the final amount only.

= 1.0.13 =
* Add a single top line and double bottom line around the final total in the expanded VAT calculation.

= 1.0.12 =
* Add top and bottom divider lines around the VAT specification totals block.
* Keep the grand total emphasized without adding a separate internal divider.

= 1.0.11 =
* Change plugin gettext source strings from Dutch to English.
* Use English (`en`) as the WPML String Translation source language.
* Reconcile the cart/checkout VAT specification against WooCommerce's cart tax total to prevent one-cent tax mismatches.
* Keep the displayed VAT specification internally consistent by reconciling the inclusive total after tax rounding.

= 1.0.10 =
* Synchronize existing WPML String Translation records for this text domain to the configured source language.
* Prevent WPML from continuing to show the plugin's gettext strings under the wrong source language after scanning.

= 1.0.9 =
* Add the PDF VAT specification to WP Overnight credit notes.
* Preserve negative goods and VAT amounts on credit notes while keeping zero shipping amounts displayed as 0.00.

= 1.0.8 =
* Add WPML-aware source language handling, matching the Toko Lariso free shipping bar pattern.
* Add current/source language information to debug output when WPML is active.
* Load the plugin text domain from `/languages` when translation files are present.
* Add technical documentation with file-by-file and function-by-function explanations.

= 1.0.7 =
* Always show the VAT specification on cart and checkout, also for free shipping and pickup.
* Show zero shipping amounts in the VAT specification when no shipping costs are charged.
* Fix Blocks refresh logic so the inserted VAT specification can be updated instead of being mistaken for a classic checkout breakdown.
* Update regression tests for zero-shipping VAT specifications.

= 1.0.6 =
* Rebuild the Blocks VAT specification from current cart items, shipping totals, and tax lines when shipping-rate metadata is missing.
* Fix the specification disappearing after switching from pickup/free shipping back to paid shipping.
* Listen directly to WooCommerce Blocks cart-store changes so the specification refreshes when checkout switches delivery modes.
* Add regression coverage for paid Blocks shipping after metadata has been lost during checkout updates.

= 1.0.5 =
* Treat WooCommerce Blocks cart totals as authoritative for the current selected shipping total.
* Hide the pro-rata shipping VAT specification when Blocks reports zero shipping, even if stale paid shipping-rate metadata is still present.
* Add a regression test for pickup/free shipping with stale paid Blocks metadata.

= 1.0.4 =
* Use only the selected WooCommerce Blocks shipping rate when rendering the pro-rata VAT specification.
* Hide the specification for selected free shipping or pickup rates even when non-selected paid rates still contain plugin metadata.
* Use WooCommerce Blocks tax-line totals for goods VAT in the display to prevent one-cent differences.
* Extend local Blocks regression tests for paid shipping, free shipping via coupon/pickup, and tax-line rounding.

= 1.0.3 =
* Add pro-rata breakdown metadata directly to WooCommerce Blocks Store API shipping-rate output.
* Make Blocks display less dependent on a separate REST cart reconstruction by letting `wc/store/cart` carry the plugin breakdown.
* Add local regression coverage for Store API shipping-rate metadata enrichment.

= 1.0.2 =
* Fix WooCommerce Blocks cart/checkout display when the custom REST endpoint cannot see the WooCommerce server-side cart.
* Send the WooCommerce Blocks cart-store snapshot to the plugin endpoint and recover the pro-rata breakdown from shipping-rate metadata.
* Add the WooCommerce Blocks data-store script dependency when available.

= 1.0.1 =
* Hide the pro-rata shipping VAT specification when the actual cart shipping total is zero, including free shipping via coupons.
* Recalculate the customer-facing cart/checkout breakdown from the actual current cart shipping total when stored shipping-rate metadata no longer matches the cart total.
* Remove stale Blocks checkout/cart breakdown markup when the cart changes to free shipping.

= 1.0.0 =
* First production release.
* Calculates Dutch pro-rata VAT on WooCommerce shipping costs for carts with mixed VAT rates.
* Supports WooCommerce Blocks checkout, classic checkout, HPOS, order detail pages, and WP Overnight PDF invoices.
* Stores and reuses original pro-rata breakdown data so cart, checkout, orders, invoices, and accounting exports remain aligned.
* Includes customer-facing VAT specifications, debug logging, order total reconciliation, and an admin maintenance page for explicit recalculation of existing orders.
* Fixes development-cycle issues around stale WooCommerce shipping tax totals, Store API metadata gaps, protected WooCommerce methods, one-cent rounding differences, and existing-order recovery.
