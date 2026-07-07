<?php
/**
 * Plugin Name: WooCommerce Pro-rata Shipping VAT
 * Plugin URI: https://github.com/TheStingPilot/woocommerce-pro-rata-shipping-vat
 * Description: Calculates Dutch pro-rata VAT on WooCommerce shipping costs for carts with mixed VAT rates.
 * Version: 0.1.19
 * Author: TheStingPilot, Codex
 * Author URI: https://github.com/TheStingPilot
 * Text Domain: wc-pro-rata-shipping-vat
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package WCProRataShippingVAT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCPRSV_VERSION', '0.1.19' );
define( 'WCPRSV_FILE', __FILE__ );
define( 'WCPRSV_PATH', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
);

require_once WCPRSV_PATH . 'includes/class-wcprsv-calculator.php';
require_once WCPRSV_PATH . 'includes/class-wcprsv-settings.php';
require_once WCPRSV_PATH . 'includes/class-wcprsv-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'WooCommerce Pro-rata Shipping VAT requires WooCommerce to be active.', 'wc-pro-rata-shipping-vat' );
					echo '</p></div>';
				}
			);

			return;
		}

		WCPRSV_Plugin::instance()->init();
	}
);
