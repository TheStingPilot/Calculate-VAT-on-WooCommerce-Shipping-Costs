<?php
/**
 * WooCommerce integration.
 *
 * @package WCProRataShippingVAT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class WCPRSV_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var WCPRSV_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var WCPRSV_Settings
	 */
	private $settings;

	/**
	 * Calculator service.
	 *
	 * @var WCPRSV_Calculator
	 */
	private $calculator;

	/**
	 * Get singleton instance.
	 *
	 * @return WCPRSV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings   = new WCPRSV_Settings();
		$this->calculator = new WCPRSV_Calculator();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings->init();

		add_filter( 'woocommerce_package_rates', array( $this, 'recalculate_package_rates' ), 100, 2 );
	}

	/**
	 * Recalculate shipping rate costs and taxes for a package.
	 *
	 * @param array $rates Shipping rates.
	 * @param array $package Package data.
	 * @return array
	 */
	public function recalculate_package_rates( $rates, $package ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() ) {
			return $rates;
		}

		$goods_by_tax_rate = $this->get_goods_by_tax_rate( $package );

		if ( empty( $goods_by_tax_rate ) ) {
			return $rates;
		}

		foreach ( $rates as $rate_id => $rate ) {
			if ( ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
				continue;
			}

			$cost = (float) $rate->get_cost();

			if ( $cost <= 0 ) {
				continue;
			}

			$result = $this->calculator->calculate(
				$cost,
				$this->settings->get_reference_vat_rate(),
				$goods_by_tax_rate,
				wc_get_price_decimals()
			);

			if ( empty( $result['lines'] ) ) {
				continue;
			}

			$rate->set_cost( wc_format_decimal( $result['shipping_excluding_vat'], wc_get_price_decimals() ) );
			$rate->set_taxes( $result['taxes'] );

			$rates[ $rate_id ] = $rate;
		}

		return $rates;
	}

	/**
	 * Group package contents by tax rate ID.
	 *
	 * @param array $package Package data.
	 * @return array
	 */
	private function get_goods_by_tax_rate( $package ) {
		$goods_by_tax_rate = array();

		if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
			return $goods_by_tax_rate;
		}

		foreach ( $package['contents'] as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
				continue;
			}

			$product = $cart_item['data'];

			if ( ! $product->is_taxable() ) {
				$this->add_goods_amount( $goods_by_tax_rate, '0', 0.0, $this->get_line_amount_excluding_vat( $cart_item ) );
				continue;
			}

			$tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
			$rate_id   = $this->get_primary_tax_rate_id( $tax_rates );
			$rate      = $this->get_primary_tax_rate_percentage( $tax_rates );
			$amount    = $this->get_line_amount_excluding_vat( $cart_item );

			$this->add_goods_amount( $goods_by_tax_rate, $rate_id, $rate, $amount );
		}

		return array_filter(
			$goods_by_tax_rate,
			static function ( $data ) {
				return $data['amount_ex_vat'] > 0;
			}
		);
	}

	/**
	 * Get a cart line amount excluding VAT after discounts where available.
	 *
	 * @param array $cart_item Cart item.
	 * @return float
	 */
	private function get_line_amount_excluding_vat( $cart_item ) {
		if ( isset( $cart_item['line_total'] ) ) {
			return max( 0.0, (float) $cart_item['line_total'] );
		}

		if ( isset( $cart_item['data'], $cart_item['quantity'] ) && is_a( $cart_item['data'], 'WC_Product' ) ) {
			return max( 0.0, (float) $cart_item['data']->get_price() * (float) $cart_item['quantity'] );
		}

		return 0.0;
	}

	/**
	 * Add a goods amount to a tax bucket.
	 *
	 * @param array  $goods_by_tax_rate Goods buckets.
	 * @param string $rate_id Tax rate ID.
	 * @param float  $rate Tax rate as decimal.
	 * @param float  $amount Amount excluding VAT.
	 * @return void
	 */
	private function add_goods_amount( array &$goods_by_tax_rate, $rate_id, $rate, $amount ) {
		$rate_id = (string) $rate_id;

		if ( ! isset( $goods_by_tax_rate[ $rate_id ] ) ) {
			$goods_by_tax_rate[ $rate_id ] = array(
				'amount_ex_vat' => 0.0,
				'rate'          => (float) $rate,
			);
		}

		$goods_by_tax_rate[ $rate_id ]['amount_ex_vat'] += max( 0.0, (float) $amount );
	}

	/**
	 * Pick the primary WooCommerce tax rate ID for a tax class.
	 *
	 * @param array $tax_rates WooCommerce tax rates.
	 * @return string
	 */
	private function get_primary_tax_rate_id( $tax_rates ) {
		if ( empty( $tax_rates ) || ! is_array( $tax_rates ) ) {
			return '0';
		}

		$rate_ids = array_keys( $tax_rates );

		return (string) reset( $rate_ids );
	}

	/**
	 * Pick the primary WooCommerce tax rate percentage for a tax class.
	 *
	 * @param array $tax_rates WooCommerce tax rates.
	 * @return float Decimal VAT rate.
	 */
	private function get_primary_tax_rate_percentage( $tax_rates ) {
		if ( empty( $tax_rates ) || ! is_array( $tax_rates ) ) {
			return 0.0;
		}

		$first_rate = reset( $tax_rates );
		$percentage = isset( $first_rate['rate'] ) ? (float) $first_rate['rate'] : 0.0;

		return max( 0.0, $percentage / 100 );
	}
}
