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
		$this->load_textdomain();
		$this->settings->init();

		add_filter( 'woocommerce_package_rates', array( $this, 'recalculate_package_rates' ), 100, 2 );
		add_filter( 'woocommerce_store_api_cart_shipping_rates', array( $this, 'enrich_store_api_shipping_rates' ), 100, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'set_order_shipping_item_taxes' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_order_breakdown' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'finalize_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'finalize_classic_order' ), 20, 3 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'render_classic_breakdown' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_classic_breakdown' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_breakdown' ) );
		add_action( 'wpo_wcpdf_after_order_details', array( $this, 'render_pdf_invoice_breakdown' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_storefront_endpoint' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'sync_wpml_string_source_language' ) );
	}

	/**
	 * Load translations when present.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wc-pro-rata-shipping-vat',
			false,
			dirname( plugin_basename( WCPRSV_FILE ) ) . '/languages'
		);
	}

	/**
	 * Mark this plugin's WPML String Translation strings as English source strings.
	 *
	 * WPML scans gettext strings as English by default. This plugin is authored
	 * with English customer-facing strings, so existing WPML string records for
	 * this text domain need their source language set to English.
	 *
	 * @return void
	 */
	public function sync_wpml_string_source_language() {
		if ( ! is_admin() || ! $this->is_wpml_string_translation_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'icl_strings';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$source_language = $this->settings->get_wpml_source_language();

		$wpdb->update(
			$table,
			array( 'language' => $source_language ),
			array( 'context' => 'wc-pro-rata-shipping-vat' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Check whether WPML String Translation appears to be active.
	 *
	 * @return bool
	 */
	private function is_wpml_string_translation_available() {
		return has_action( 'wpml_register_single_string' ) || has_filter( 'wpml_translate_single_string' ) || defined( 'WPML_ST_VERSION' );
	}

	/**
	 * Calculate a breakdown from the shipping cost as configured in WooCommerce.
	 *
	 * When product prices are entered including tax, WooCommerce shipping costs
	 * are treated as excluding the configured shipping tax class. When product
	 * prices are entered excluding tax, the shipping cost is already excluding
	 * VAT and is split directly over the goods VAT rates.
	 *
	 * @param float $shipping_cost Shipping cost from WooCommerce.
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate.
	 * @param int|null $price_decimals Number of decimals, or null for WooCommerce default.
	 * @return array
	 */
	private function calculate_shipping_breakdown_from_configured_cost( $shipping_cost, array $goods_by_tax_rate, $price_decimals = null ) {
		$price_decimals = null === $price_decimals ? wc_get_price_decimals() : $price_decimals;

		if ( $this->prices_entered_including_tax() ) {
			return $this->calculator->calculate(
				$shipping_cost,
				$this->get_configured_shipping_reference_vat_rate(),
				$goods_by_tax_rate,
				$price_decimals
			);
		}

		return $this->calculator->calculate_from_excluding_vat(
			$shipping_cost,
			$goods_by_tax_rate,
			$price_decimals
		);
	}

	/**
	 * Calculate a breakdown from current WooCommerce shipping totals.
	 *
	 * @param float $shipping_excluding_vat Shipping excluding VAT.
	 * @param float $shipping_vat Shipping VAT.
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate.
	 * @param int|null $price_decimals Number of decimals, or null for WooCommerce default.
	 * @return array
	 */
	private function calculate_shipping_breakdown_from_totals( $shipping_excluding_vat, $shipping_vat, array $goods_by_tax_rate, $price_decimals = null ) {
		$price_decimals = null === $price_decimals ? wc_get_price_decimals() : $price_decimals;

		if ( $this->prices_entered_including_tax() ) {
			return $this->calculator->calculate_from_including_vat(
				(float) $shipping_excluding_vat + (float) $shipping_vat,
				$goods_by_tax_rate,
				$price_decimals
			);
		}

		return $this->calculator->calculate_from_excluding_vat(
			$shipping_excluding_vat,
			$goods_by_tax_rate,
			$price_decimals
		);
	}

	/**
	 * Whether WooCommerce product prices are entered including tax.
	 *
	 * @return bool
	 */
	private function prices_entered_including_tax() {
		if ( function_exists( 'wc_prices_include_tax' ) ) {
			return wc_prices_include_tax();
		}

		return 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' );
	}

	/**
	 * Get the shipping tax class rate used as reference for inclusive shops.
	 *
	 * @return float Decimal VAT rate.
	 */
	private function get_configured_shipping_reference_vat_rate() {
		$tax_class = get_option( 'woocommerce_shipping_tax_class', '' );

		if ( 'inherit' === $tax_class ) {
			return 0.0;
		}

		if ( ! class_exists( 'WC_Tax' ) ) {
			return 0.0;
		}

		if ( method_exists( 'WC_Tax', 'get_shipping_tax_rates' ) ) {
			$rates = WC_Tax::get_shipping_tax_rates( $tax_class );
		} elseif ( method_exists( 'WC_Tax', 'get_rates' ) ) {
			$rates = WC_Tax::get_rates( $tax_class );
		} else {
			return 0.0;
		}

		if ( empty( $rates ) || ! is_array( $rates ) ) {
			return 0.0;
		}

		if ( method_exists( 'WC_Tax', 'calc_tax' ) ) {
			return $this->round_money( array_sum( WC_Tax::calc_tax( 100, $rates, false ) ) ) / 100;
		}

		$rate = 0.0;

		foreach ( $rates as $tax_rate ) {
			if ( isset( $tax_rate['rate'] ) ) {
				$rate += (float) $tax_rate['rate'];
			} elseif ( isset( $tax_rate['tax_rate'] ) ) {
				$rate += (float) $tax_rate['tax_rate'];
			}
		}

		return max( 0.0, $rate / 100 );
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

			$result = $this->calculate_shipping_breakdown_from_configured_cost( $cost, $goods_by_tax_rate );

			if ( empty( $result['lines'] ) ) {
				continue;
			}

			$this->debug_log(
				'package_rate_calculated',
				array(
					'rate_id'                => $rate_id,
					'original_rate_cost'     => $cost,
					'prices_include_tax'     => $this->prices_entered_including_tax(),
					'shipping_reference_vat' => $this->get_configured_shipping_reference_vat_rate(),
					'goods_by_tax_rate'      => $goods_by_tax_rate,
					'calculated_breakdown'   => $result,
				)
			);

			$rate->set_cost( wc_format_decimal( $result['shipping_excluding_vat'], wc_get_price_decimals() ) );
			$rate->set_taxes( $result['taxes'] );
			$rate->add_meta_data( '_wcprsv_breakdown', $result );
			$this->store_session_breakdown( $rate_id, $result );

			$rates[ $rate_id ] = $rate;
		}

		return $rates;
	}

	/**
	 * Add pro-rata breakdown metadata to WooCommerce Blocks Store API shipping rates.
	 *
	 * @param array $shipping_rates Store API shipping rates.
	 * @param mixed $cart Store API cart object.
	 * @return array
	 */
	public function enrich_store_api_shipping_rates( $shipping_rates, $cart ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || empty( $shipping_rates ) || ! WC()->shipping() ) {
			return $shipping_rates;
		}

		$packages = WC()->shipping()->get_packages();

		foreach ( $shipping_rates as $package_index => &$package_data ) {
			if ( empty( $package_data['shipping_rates'] ) || ! is_array( $package_data['shipping_rates'] ) ) {
				continue;
			}

			$package_key = isset( $package_data['package_id'] ) ? absint( $package_data['package_id'] ) : $package_index;
			$package     = $packages[ $package_key ] ?? $packages[ $package_index ] ?? array();

			if ( empty( $package['rates'] ) ) {
				continue;
			}

			foreach ( $package_data['shipping_rates'] as &$rate_data ) {
				if ( $this->is_store_api_rate_free( $rate_data ) ) {
					continue;
				}

				$rate_id = $rate_data['rate_id'] ?? $rate_data['id'] ?? '';
				$rate    = ! empty( $rate_id ) && ! empty( $package['rates'][ $rate_id ] ) ? $package['rates'][ $rate_id ] : null;

				if ( ! is_a( $rate, 'WC_Shipping_Rate' ) && 1 === count( $package['rates'] ) ) {
					$rate = reset( $package['rates'] );
				}

				if ( ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
					continue;
				}

				$breakdown = $this->get_shipping_rate_meta( $rate, '_wcprsv_breakdown' );

				if ( empty( $breakdown['lines'] ) ) {
					$breakdown = $this->calculate_package_rate_breakdown( $rate, $package );
				}

				if ( empty( $breakdown['lines'] ) || $this->round_money( $breakdown['shipping_including_vat'] ) <= 0 ) {
					continue;
				}

				$this->add_breakdown_to_store_api_rate( $rate_data, $breakdown );
			}
			unset( $rate_data );
		}
		unset( $package_data );

		return $shipping_rates;
	}

	/**
	 * Determine whether a Store API shipping rate is free.
	 *
	 * @param array $rate_data Store API rate data.
	 * @return bool
	 */
	private function is_store_api_rate_free( array $rate_data ) {
		$price = $rate_data['price'] ?? $rate_data['cost'] ?? null;

		if ( isset( $rate_data['prices']['price'] ) ) {
			$price = $rate_data['prices']['price'];
		}

		if ( null === $price ) {
			return false;
		}

		return 0.0 === (float) $price;
	}

	/**
	 * Add breakdown data to one Store API shipping rate.
	 *
	 * @param array $rate_data Store API rate data.
	 * @param array $breakdown Pro-rata breakdown.
	 * @return void
	 */
	private function add_breakdown_to_store_api_rate( array &$rate_data, array $breakdown ) {
		$rate_data['_wcprsv_breakdown'] = $breakdown;

		if ( empty( $rate_data['meta_data'] ) || ! is_array( $rate_data['meta_data'] ) ) {
			$rate_data['meta_data'] = array();
		}

		$encoded = wp_json_encode( $breakdown );
		$updated = false;

		foreach ( $rate_data['meta_data'] as &$meta ) {
			if ( is_array( $meta ) && isset( $meta['key'] ) && '_wcprsv_breakdown' === $meta['key'] ) {
				$meta['value'] = $encoded;
				$updated = true;
				break;
			}
		}
		unset( $meta );

		if ( ! $updated ) {
			$rate_data['meta_data'][] = array(
				'key'   => '_wcprsv_breakdown',
				'value' => $encoded,
			);
		}
	}

	/**
	 * Persist the pro-rata shipping totals on the order shipping item.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item.
	 * @param string                 $package_key Package key.
	 * @param array                  $package Shipping package.
	 * @param WC_Order               $order Order object.
	 * @return void
	 */
	public function set_order_shipping_item_taxes( $item, $package_key, $package, $order ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || ! is_a( $item, 'WC_Order_Item_Shipping' ) ) {
			return;
		}

		$breakdown = $this->get_package_shipping_breakdown( $package_key, $package );

		if ( empty( $breakdown['lines'] ) ) {
			$breakdown = $this->calculate_order_shipping_item_breakdown( $item, $package );
		}

		if ( empty( $breakdown['lines'] ) ) {
			$this->debug_log(
				'checkout_create_order_shipping_item_no_breakdown',
				array(
					'package_key'     => $package_key,
					'item_total'      => $item->get_total(),
					'item_taxes'      => $item->get_taxes(),
					'package_summary' => $this->summarize_package( $package ),
				)
			);
			return;
		}

		$this->debug_log(
			'checkout_create_order_shipping_item_apply',
			array(
				'package_key'       => $package_key,
				'before_item_total' => $item->get_total(),
				'before_item_taxes' => $item->get_taxes(),
				'breakdown'         => $breakdown,
			)
		);

		$item->set_total( wc_format_decimal( $breakdown['shipping_excluding_vat'], wc_get_price_decimals() ) );
		$this->set_shipping_item_tax_data( $item, $breakdown['taxes'] );
		$item->update_meta_data( '_wcprsv_breakdown', $breakdown );

		$this->debug_log(
			'checkout_create_order_shipping_item_after',
			array(
				'after_item_total' => $item->get_total(),
				'after_item_taxes' => $item->get_taxes(),
			)
		);
	}

	/**
	 * Calculate the breakdown directly while WooCommerce creates the order shipping item.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item.
	 * @param array                  $package Shipping package.
	 * @return array
	 */
	private function calculate_order_shipping_item_breakdown( $item, array $package ) {
		$goods_by_tax_rate = $this->get_goods_by_tax_rate( $package );

		if ( empty( $goods_by_tax_rate ) ) {
			return array();
		}

		$item_total = (float) $item->get_total();
		$item_taxes = $item->get_taxes();
		$item_tax_total = 0.0;

		if ( ! empty( $item_taxes['total'] ) && is_array( $item_taxes['total'] ) ) {
			$item_tax_total = array_sum( array_map( 'floatval', $item_taxes['total'] ) );
		}

		if ( $item_tax_total > 0 ) {
			$result = $this->calculate_shipping_breakdown_from_totals(
				$item_total,
				$item_tax_total,
				$goods_by_tax_rate,
				wc_get_price_decimals()
			);

			$this->debug_log(
				'order_shipping_item_breakdown_from_totals',
				array(
					'item_total'        => $item_total,
					'item_tax_total'    => $item_tax_total,
					'prices_include_tax' => $this->prices_entered_including_tax(),
					'goods_by_tax_rate' => $goods_by_tax_rate,
					'breakdown'         => $result,
				)
			);

			return $result;
		}

		$result = $this->calculate_shipping_breakdown_from_configured_cost( $item_total, $goods_by_tax_rate );

		$this->debug_log(
			'order_shipping_item_breakdown_from_configured_cost',
			array(
				'item_total'          => $item_total,
				'prices_include_tax'  => $this->prices_entered_including_tax(),
				'shipping_reference_vat' => $this->get_configured_shipping_reference_vat_rate(),
				'goods_by_tax_rate'   => $goods_by_tax_rate,
				'breakdown'           => $result,
			)
		);

		return $result;
	}

	/**
	 * Store the final pro-rata breakdown on the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Checkout data.
	 * @return void
	 */
	public function store_order_breakdown( $order, $data ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$breakdown = $this->get_breakdown_from_order_shipping_items( $order );

		if ( empty( $breakdown['lines'] ) ) {
			$breakdown = $this->get_selected_shipping_breakdown();
		}

		if ( empty( $breakdown['lines'] ) ) {
			return;
		}

		$this->debug_log(
			'checkout_create_order_store_breakdown',
			array(
				'order_id'          => $order->get_id(),
				'order_total_before' => $order->get_total(),
				'shipping_total_before' => $order->get_shipping_total(),
				'shipping_tax_before' => $order->get_shipping_tax(),
				'breakdown'         => $breakdown,
			)
		);

		$this->apply_order_tax_lines( $order, $breakdown );
		$order->update_meta_data( '_wcprsv_breakdown', $breakdown );
	}

	/**
	 * Finalize Store API / Checkout Blocks orders after WooCommerce has built the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function finalize_store_api_order( $order ) {
		$this->finalize_order_shipping_breakdown( $order );
	}

	/**
	 * Finalize classic checkout orders after WooCommerce has built the order.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function finalize_classic_order( $order_id, $posted_data, $order ) {
		$this->finalize_order_shipping_breakdown( $order );
	}

	/**
	 * Apply the cart pro-rata shipping breakdown to the completed order.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	private function finalize_order_shipping_breakdown( $order ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$breakdown = $this->get_selected_shipping_breakdown();

		if ( empty( $breakdown['lines'] ) ) {
			$breakdown = $this->get_breakdown_from_order_shipping_items( $order );
		}

		if ( empty( $breakdown['lines'] ) ) {
			$this->debug_log(
				'finalize_order_no_breakdown',
				array(
					'order_id'       => $order->get_id(),
					'order_total'    => $order->get_total(),
					'shipping_total' => $order->get_shipping_total(),
					'shipping_tax'   => $order->get_shipping_tax(),
				)
			);
			return;
		}

		$this->debug_log(
			'finalize_order_before_apply',
			array(
				'order_id'              => $order->get_id(),
				'order_total_before'    => $order->get_total(),
				'shipping_total_before' => $order->get_shipping_total(),
				'shipping_tax_before'   => $order->get_shipping_tax(),
				'breakdown'             => $breakdown,
			)
		);

		$this->apply_breakdown_to_order_shipping_items( $order, $breakdown );
		$this->apply_order_tax_lines( $order, $breakdown );
		$order->update_meta_data( '_wcprsv_breakdown', $breakdown );
		$order->calculate_totals( false );
		$this->reconcile_order_total( $order, $breakdown );
		$order->save();

		$this->debug_log(
			'finalize_order_after_apply',
			array(
				'order_id'             => $order->get_id(),
				'order_total_after'    => $order->get_total(),
				'shipping_total_after' => $order->get_shipping_total(),
				'shipping_tax_after'   => $order->get_shipping_tax(),
				'tax_totals_after'     => $this->summarize_order_tax_items( $order ),
			)
		);
	}

	/**
	 * Apply the pro-rata breakdown to the order's shipping items.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $breakdown Pro-rata breakdown.
	 * @return void
	 */
	private function apply_breakdown_to_order_shipping_items( $order, array $breakdown ) {
		$shipping_items = $order->get_items( 'shipping' );

		if ( empty( $shipping_items ) ) {
			return;
		}

		$first = true;

		foreach ( $shipping_items as $item ) {
			if ( $first ) {
				$item->set_total( wc_format_decimal( $breakdown['shipping_excluding_vat'], wc_get_price_decimals() ) );
				$this->set_shipping_item_tax_data( $item, $breakdown['taxes'] );
				$item->update_meta_data( '_wcprsv_breakdown', $breakdown );
				$item->save();
				$first = false;
				continue;
			}

			$item->set_total( 0 );
			$this->set_shipping_item_tax_data( $item, array() );
			$item->save();
		}
	}

	/**
	 * Set complete shipping tax data on an order shipping item.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item.
	 * @param array                  $taxes Taxes keyed by rate ID.
	 * @return void
	 */
	private function set_shipping_item_tax_data( $item, array $taxes ) {
		$taxes = array_map(
			static function ( $amount ) {
				return wc_format_decimal( $amount, wc_get_price_decimals() );
			},
			$taxes
		);

		$item->set_taxes(
			array(
				'total'    => $taxes,
				'subtotal' => $taxes,
			)
		);
	}

	/**
	 * Reconcile the order total after WooCommerce has recalculated totals.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $breakdown Pro-rata breakdown.
	 * @return void
	 */
	private function reconcile_order_total( $order, array $breakdown ) {
		$shipping_tax_total = $this->round_money( array_sum( array_map( 'floatval', $breakdown['taxes'] ) ) );

		if ( method_exists( $order, 'set_shipping_tax' ) ) {
			$order->set_shipping_tax( $shipping_tax_total );
		}

		$expected_total = $this->calculate_expected_order_total( $order, $breakdown );
		$current_total = $this->round_money( $order->get_total() );

		if ( $expected_total !== $current_total ) {
			$this->debug_log(
				'reconcile_order_total',
				array(
					'order_id'       => $order->get_id(),
					'current_total'  => $current_total,
					'expected_total' => $expected_total,
					'order_subtotal' => $order->get_subtotal(),
					'cart_tax'       => $order->get_cart_tax(),
					'shipping_ex'    => $breakdown['shipping_excluding_vat'],
					'shipping_tax'   => $shipping_tax_total,
					'shipping_taxes' => $breakdown['taxes'],
				)
			);
			$order->set_total( $expected_total );
		}
	}

	/**
	 * Calculate the order total that matches a pro-rata shipping breakdown.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $breakdown Pro-rata breakdown.
	 * @return float
	 */
	private function calculate_expected_order_total( $order, array $breakdown ) {
		$shipping_tax_total = $this->round_money( array_sum( array_map( 'floatval', $breakdown['taxes'] ) ) );
		$fee_total          = method_exists( $order, 'get_total_fees' ) ? (float) $order->get_total_fees() : 0.0;
		$fee_tax            = method_exists( $order, 'get_fee_tax' ) ? (float) $order->get_fee_tax() : 0.0;

		return $this->round_money(
			(float) $order->get_subtotal()
			- (float) $order->get_total_discount()
			+ (float) $order->get_cart_tax()
			+ (float) $breakdown['shipping_excluding_vat']
			+ $shipping_tax_total
			+ $fee_total
			+ $fee_tax
		);
	}

	/**
	 * Register the WooCommerce maintenance page.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Pro-rata shipping VAT', 'wc-pro-rata-shipping-vat' ),
			__( 'Pro-rata shipping VAT', 'wc-pro-rata-shipping-vat' ),
			'manage_woocommerce',
			'wcprsv-maintenance',
			array( $this, 'render_maintenance_page' )
		);
	}

	/**
	 * Render the order maintenance page.
	 *
	 * @return void
	 */
	public function render_maintenance_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-pro-rata-shipping-vat' ) );
		}

		$results = array();
		$notice  = '';
		$ids     = '';
		$action  = isset( $_POST['wcprsv_action'] ) ? sanitize_key( wp_unslash( $_POST['wcprsv_action'] ) ) : 'analyze';

		if ( isset( $_POST['wcprsv_order_ids'] ) ) {
			check_admin_referer( 'wcprsv_maintenance' );

			$ids       = sanitize_textarea_field( wp_unslash( $_POST['wcprsv_order_ids'] ) );
			$order_ids = $this->parse_order_ids( $ids );

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					$results[] = array(
						'order_id' => $order_id,
						'status'   => __( 'Not found', 'wc-pro-rata-shipping-vat' ),
					);
					continue;
				}

				$analysis = $this->analyze_order_recalculation( $order );

				if ( 'recalculate' === $action && empty( $analysis['error'] ) ) {
					$analysis = $this->recalculate_existing_order( $order, $analysis );
				}

				$results[] = $analysis;
			}

			$notice = 'recalculate' === $action
				? __( 'Recalculation completed.', 'wc-pro-rata-shipping-vat' )
				: __( 'Analysis completed.', 'wc-pro-rata-shipping-vat' );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Pro-rata shipping VAT maintenance', 'wc-pro-rata-shipping-vat' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Analyze or recalculate existing WooCommerce orders. Accounting exports are not sent again; this action only updates WooCommerce order data.', 'wc-pro-rata-shipping-vat' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'wcprsv_maintenance' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcprsv_order_ids"><?php echo esc_html__( 'Order numbers', 'wc-pro-rata-shipping-vat' ); ?></label></th>
						<td>
							<textarea id="wcprsv_order_ids" name="wcprsv_order_ids" rows="4" class="large-text" placeholder="33210, 33211"><?php echo esc_textarea( $ids ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Use commas, spaces, or new lines to enter multiple orders.', 'wc-pro-rata-shipping-vat' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button class="button button-secondary" type="submit" name="wcprsv_action" value="analyze"><?php echo esc_html__( 'Analyze', 'wc-pro-rata-shipping-vat' ); ?></button>
					<button class="button button-primary" type="submit" name="wcprsv_action" value="recalculate" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to recalculate the selected orders?', 'wc-pro-rata-shipping-vat' ) ); ?>');"><?php echo esc_html__( 'Recalculate orders', 'wc-pro-rata-shipping-vat' ); ?></button>
				</p>
			</form>
			<?php $this->render_maintenance_results( $results ); ?>
		</div>
		<?php
	}

	/**
	 * Parse order IDs from textarea input.
	 *
	 * @param string $input Raw order ID list.
	 * @return array
	 */
	private function parse_order_ids( $input ) {
		$ids = preg_split( '/[\s,;]+/', (string) $input );
		$ids = array_filter( array_map( 'absint', $ids ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Analyze an existing order without changing it.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function analyze_order_recalculation( $order ) {
		$goods_by_tax_rate = $this->get_order_goods_by_tax_rate( $order );
		$shipping_source   = $this->get_order_maintenance_shipping_including_vat( $order );
		$shipping_incl     = $shipping_source['amount'];
		$shipping_excl     = $this->round_money( (float) $order->get_shipping_total() );
		$shipping_tax      = $this->round_money( (float) $order->get_shipping_tax() );

		$result = array(
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'date'                 => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			'current_total'        => $this->round_money( $order->get_total() ),
			'current_shipping_ex'  => $this->round_money( $order->get_shipping_total() ),
			'current_shipping_tax' => $this->round_money( $order->get_shipping_tax() ),
			'shipping_source'      => $shipping_source['source'],
			'status'               => __( 'OK', 'wc-pro-rata-shipping-vat' ),
		);

		if ( empty( $goods_by_tax_rate ) || $shipping_incl <= 0 ) {
			$result['status'] = __( 'Not supported', 'wc-pro-rata-shipping-vat' );
			$result['error']  = __( 'No taxable goods or shipping costs found.', 'wc-pro-rata-shipping-vat' );
			return $result;
		}

		$breakdown = $this->prices_entered_including_tax()
			? $this->calculator->calculate_from_including_vat( $shipping_incl, $goods_by_tax_rate, wc_get_price_decimals() )
			: $this->calculate_shipping_breakdown_from_totals( $shipping_excl, $shipping_tax, $goods_by_tax_rate, wc_get_price_decimals() );

		if ( empty( $breakdown['lines'] ) ) {
			$result['status'] = __( 'Not supported', 'wc-pro-rata-shipping-vat' );
			$result['error']  = __( 'No VAT specification can be calculated.', 'wc-pro-rata-shipping-vat' );
			return $result;
		}

		$expected_total         = $this->calculate_expected_order_total( $order, $breakdown );
		$new_shipping_tax       = $this->round_money( array_sum( array_map( 'floatval', $breakdown['taxes'] ) ) );
		$result['new_total']    = $expected_total;
		$result['new_shipping_ex']  = $this->round_money( $breakdown['shipping_excluding_vat'] );
		$result['new_shipping_tax'] = $new_shipping_tax;
		$result['difference']   = $this->round_money( $expected_total - (float) $order->get_total() );
		$result['breakdown']    = $breakdown;
		$result['status']       = 0.0 === $result['difference'] && $result['current_shipping_tax'] === $new_shipping_tax ? __( 'OK', 'wc-pro-rata-shipping-vat' ) : __( 'Difference', 'wc-pro-rata-shipping-vat' );

		return $result;
	}

	/**
	 * Get the best inclusive shipping amount for existing order maintenance.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_maintenance_shipping_including_vat( $order ) {
		$current_amount = $this->round_money( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() );
		$best           = array(
			'amount' => $current_amount,
			'source' => __( 'Current order', 'wc-pro-rata-shipping-vat' ),
		);

		foreach ( $this->get_stored_order_breakdown_candidates( $order ) as $candidate ) {
			if ( empty( $candidate['breakdown']['lines'] ) || ! isset( $candidate['breakdown']['shipping_including_vat'] ) ) {
				continue;
			}

			$amount = $this->round_money( $candidate['breakdown']['shipping_including_vat'] );

			if ( $amount > $best['amount'] ) {
				$best = array(
					'amount' => $amount,
					'source' => $candidate['source'],
				);
			}
		}

		return $best;
	}

	/**
	 * Collect stored breakdowns that may contain the original inclusive shipping amount.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_stored_order_breakdown_candidates( $order ) {
		$candidates = array();

		$this->add_breakdown_candidate( $candidates, $order->get_meta( '_wcprsv_breakdown' ), __( 'Order meta', 'wc-pro-rata-shipping-vat' ) );
		$this->add_breakdown_candidate( $candidates, $order->get_meta( '_wcprsv_previous_breakdown' ), __( 'Previous breakdown', 'wc-pro-rata-shipping-vat' ) );

		$history = $order->get_meta( '_wcprsv_recalculation_history' );

		if ( is_array( $history ) ) {
			foreach ( $history as $entry ) {
				if ( ! empty( $entry['breakdown'] ) ) {
					$this->add_breakdown_candidate( $candidates, $entry['breakdown'], __( 'History', 'wc-pro-rata-shipping-vat' ) );
				}
			}
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$this->add_breakdown_candidate( $candidates, $item->get_meta( '_wcprsv_breakdown' ), __( 'Shipping item meta', 'wc-pro-rata-shipping-vat' ) );
		}

		return $candidates;
	}

	/**
	 * Add a valid breakdown candidate.
	 *
	 * @param array  $candidates Candidate list.
	 * @param mixed  $breakdown Potential breakdown.
	 * @param string $source Source label.
	 * @return void
	 */
	private function add_breakdown_candidate( array &$candidates, $breakdown, $source ) {
		if ( empty( $breakdown['lines'] ) || ! isset( $breakdown['shipping_including_vat'] ) ) {
			return;
		}

		$candidates[] = array(
			'breakdown' => $breakdown,
			'source'    => $source,
		);
	}

	/**
	 * Recalculate an existing order and save an audit trail.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $analysis Analysis result.
	 * @return array
	 */
	private function recalculate_existing_order( $order, array $analysis ) {
		$breakdown = $analysis['breakdown'];
		$previous  = array(
			'recalculated_at' => current_time( 'mysql' ),
			'recalculated_by' => get_current_user_id(),
			'order_total'     => $order->get_total(),
			'shipping_total'  => $order->get_shipping_total(),
			'shipping_tax'    => $order->get_shipping_tax(),
			'tax_items'       => $this->summarize_order_tax_items( $order ),
			'breakdown'       => $order->get_meta( '_wcprsv_breakdown' ),
		);

		$history = $order->get_meta( '_wcprsv_recalculation_history' );
		$history = is_array( $history ) ? $history : array();
		$history[] = $previous;

		if ( count( $history ) > 10 ) {
			$history = array_slice( $history, -10 );
		}

		$this->apply_breakdown_to_order_shipping_items( $order, $breakdown );
		$this->apply_order_tax_lines( $order, $breakdown );
		$order->update_meta_data( '_wcprsv_previous_breakdown', $previous['breakdown'] );
		$order->update_meta_data( '_wcprsv_recalculation_history', $history );
		$order->update_meta_data( '_wcprsv_recalculated_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_wcprsv_recalculated_by', get_current_user_id() );
		$order->update_meta_data( '_wcprsv_recalculation_note', 'Manual admin recalculation.' );
		$order->update_meta_data( '_wcprsv_breakdown', $breakdown );
		$order->calculate_totals( false );
		$this->reconcile_order_total( $order, $breakdown );
		$order->save();

		$updated = $this->analyze_order_recalculation( $order );
		$updated['status'] = __( 'Recalculated', 'wc-pro-rata-shipping-vat' );

		return $updated;
	}

	/**
	 * Render maintenance results.
	 *
	 * @param array $results Results.
	 * @return void
	 */
	private function render_maintenance_results( array $results ) {
		if ( empty( $results ) ) {
			return;
		}

		?>
		<h2><?php echo esc_html__( 'Results', 'wc-pro-rata-shipping-vat' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Order', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Date', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Current total', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'New total', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Difference', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Shipping VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
					<th><?php echo esc_html__( 'Source', 'wc-pro-rata-shipping-vat' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $result ) : ?>
					<tr>
						<td><?php echo esc_html( $result['order_number'] ?? $result['order_id'] ); ?></td>
						<td><?php echo esc_html( $result['date'] ?? '' ); ?></td>
						<td>
							<strong><?php echo esc_html( $result['status'] ?? '' ); ?></strong>
							<?php if ( ! empty( $result['error'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( $result['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo isset( $result['current_total'] ) ? wp_kses_post( wc_price( $result['current_total'] ) ) : ''; ?></td>
						<td><?php echo isset( $result['new_total'] ) ? wp_kses_post( wc_price( $result['new_total'] ) ) : ''; ?></td>
						<td><?php echo isset( $result['difference'] ) ? wp_kses_post( wc_price( $result['difference'] ) ) : ''; ?></td>
						<td>
							<?php
							if ( isset( $result['current_shipping_tax'], $result['new_shipping_tax'] ) ) {
								echo wp_kses_post( wc_price( $result['current_shipping_tax'] ) . ' &rarr; ' . wc_price( $result['new_shipping_tax'] ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $result['shipping_source'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the breakdown in classic cart and checkout totals tables.
	 *
	 * @return void
	 */
	public function render_classic_breakdown() {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() ) {
			return;
		}

		$breakdown = $this->get_selected_shipping_breakdown();

		if ( empty( $breakdown['lines'] ) ) {
			return;
		}

		echo '<tr class="wcprsv-vat-breakdown"><td colspan="2">';
		echo $this->get_breakdown_html( $breakdown ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</td></tr>';
	}

	/**
	 * Render the VAT breakdown on the customer order details page.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function render_order_breakdown( $order ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$breakdown = $this->get_order_breakdown( $order );

		if ( empty( $breakdown['lines'] ) ) {
			return;
		}

		echo $this->get_breakdown_html( $breakdown, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the VAT breakdown in WP Overnight PDF invoices and credit notes.
	 *
	 * @param string $document_type PDF document type.
	 * @param mixed  $order Order or refund object.
	 * @return void
	 */
	public function render_pdf_invoice_breakdown( $document_type, $order ) {
		if ( ! $this->settings->is_enabled() || ! wc_tax_enabled() || ! $this->is_supported_pdf_document_type( $document_type ) || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$breakdown = $this->is_credit_note_document_type( $document_type ) ? $this->get_credit_note_breakdown( $order ) : $this->get_order_breakdown( $order );

		if ( empty( $breakdown['lines'] ) ) {
			return;
		}

		echo $this->get_pdf_breakdown_html( $breakdown, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue frontend assets for Cart and Checkout Blocks.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		$should_enqueue_style = is_cart() || is_checkout() || ( function_exists( 'is_view_order_page' ) && is_view_order_page() );
		$should_enqueue_script = is_cart() || is_checkout();

		if ( ! $should_enqueue_style || ! $this->settings->is_enabled() || ! wc_tax_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'wcprsv-frontend',
			plugins_url( 'assets/frontend.css', WCPRSV_FILE ),
			array(),
			WCPRSV_VERSION
		);

		if ( ! $should_enqueue_script ) {
			return;
		}

		$dependencies = array();

		if ( wp_script_is( 'wp-data', 'registered' ) ) {
			$dependencies[] = 'wp-data';
		}

		if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) {
			$dependencies[] = 'wc-blocks-data-store';
		}

		wp_enqueue_script(
			'wcprsv-frontend',
			plugins_url( 'assets/frontend.js', WCPRSV_FILE ),
			$dependencies,
			WCPRSV_VERSION,
			true
		);

		wp_localize_script(
			'wcprsv-frontend',
			'wcprsvData',
			array(
				'endpoint'       => esc_url_raw( rest_url( 'wcprsv/v1/breakdown' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'debug'          => $this->settings->is_debug_enabled(),
				'price_decimals' => wc_get_price_decimals(),
			)
		);
	}

	/**
	 * Register a small storefront endpoint for the block checkout display.
	 *
	 * @return void
	 */
	public function register_storefront_endpoint() {
		register_rest_route(
			'wcprsv/v1',
			'/breakdown',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'get_breakdown_response' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return rendered breakdown data for the current cart.
	 *
	 * @return WP_REST_Response
	 */
	public function get_breakdown_response( $request = null ) {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$breakdown = $this->get_selected_shipping_breakdown();
		$snapshot  = is_a( $request, 'WP_REST_Request' ) ? $request->get_param( 'blocks_snapshot' ) : null;

		if ( empty( $breakdown['lines'] ) && is_array( $snapshot ) ) {
			$breakdown = $this->calculate_breakdown_from_blocks_snapshot( $snapshot );
		}

		return rest_ensure_response(
			array(
				'has_breakdown' => ! empty( $breakdown['lines'] ),
				'html'          => ! empty( $breakdown['lines'] ) ? $this->get_breakdown_html( $breakdown ) : '',
				'debug'         => $this->settings->is_debug_enabled() ? $this->get_frontend_debug_payload( $breakdown, $snapshot ) : null,
			)
		);
	}

	/**
	 * Get the pro-rata breakdown for selected shipping rates.
	 *
	 * @return array
	 */
	private function get_selected_shipping_breakdown() {
		if ( ! WC()->cart || ! WC()->session ) {
			return array();
		}

		$current_shipping_including_vat = $this->get_current_shipping_including_vat( false );

		if ( $current_shipping_including_vat <= 0 ) {
			return $this->calculate_current_cart_breakdown();
		}

		$packages       = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$combined       = array(
			'shipping_including_vat' => 0.0,
			'shipping_excluding_vat' => 0.0,
			'taxes'                  => array(),
			'lines'                  => array(),
		);

		foreach ( $packages as $package_index => $package ) {
			$chosen_rate_id = $chosen_methods[ $package_index ] ?? '';

			if ( empty( $chosen_rate_id ) || empty( $package['rates'][ $chosen_rate_id ] ) ) {
				continue;
			}

			$breakdown = $this->get_package_shipping_breakdown( $package_index, $package );

			if ( empty( $breakdown['lines'] ) ) {
				continue;
			}

			$combined['shipping_including_vat'] += (float) $breakdown['shipping_including_vat'];
			$combined['shipping_excluding_vat'] += (float) $breakdown['shipping_excluding_vat'];

			foreach ( $breakdown['taxes'] as $tax_rate_id => $tax_amount ) {
				if ( ! isset( $combined['taxes'][ $tax_rate_id ] ) ) {
					$combined['taxes'][ $tax_rate_id ] = 0.0;
				}

				$combined['taxes'][ $tax_rate_id ] += (float) $tax_amount;
			}

			foreach ( $breakdown['lines'] as $tax_rate_id => $line ) {
				if ( ! isset( $combined['lines'][ $tax_rate_id ] ) ) {
					$combined['lines'][ $tax_rate_id ] = $line;
					continue;
				}

				$combined['lines'][ $tax_rate_id ]['goods_amount_ex_vat']    += (float) $line['goods_amount_ex_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_excluding_vat'] += (float) $line['shipping_excluding_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_vat']           += (float) $line['shipping_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_including_vat'] += (float) $line['shipping_including_vat'];
			}
		}

		if ( ! empty( $combined['lines'] ) ) {
			$combined_shipping_including_vat = $this->round_money( $combined['shipping_including_vat'] );
			$current_shipping_including_vat  = $this->round_money( $current_shipping_including_vat );

			if ( $combined_shipping_including_vat === $current_shipping_including_vat ) {
				return $combined;
			}
		}

		return $this->calculate_current_cart_breakdown();
	}

	/**
	 * Get the pro-rata breakdown for a selected package shipping rate.
	 *
	 * @param string|int $package_key Package key.
	 * @param array      $package Shipping package.
	 * @return array
	 */
	private function get_package_shipping_breakdown( $package_key, $package ) {
		if ( ! WC()->session ) {
			return array();
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$chosen_rate_id = $chosen_methods[ $package_key ] ?? '';

		if ( empty( $chosen_rate_id ) && ! empty( $package['rates'] ) && 1 === count( $package['rates'] ) ) {
			$rate_ids = array_keys( $package['rates'] );
			$chosen_rate_id = (string) reset( $rate_ids );
		}

		if ( empty( $chosen_rate_id ) ) {
			return array();
		}

		if ( ! empty( $package['rates'][ $chosen_rate_id ] ) && is_a( $package['rates'][ $chosen_rate_id ], 'WC_Shipping_Rate' ) ) {
			$rate      = $package['rates'][ $chosen_rate_id ];
			$breakdown = $this->get_shipping_rate_meta( $package['rates'][ $chosen_rate_id ], '_wcprsv_breakdown' );

			if ( ! empty( $breakdown['lines'] ) ) {
				return $breakdown;
			}
		}

		$breakdown = $this->get_session_breakdown( $chosen_rate_id );

		if ( ! empty( $breakdown['lines'] ) ) {
			return $breakdown;
		}

		if ( empty( $rate ) ) {
			return array();
		}

		return $this->calculate_package_rate_breakdown( $rate, $package );
	}

	/**
	 * Calculate a package shipping breakdown directly from a rate and package.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @param array            $package Shipping package.
	 * @return array
	 */
	private function calculate_package_rate_breakdown( $rate, array $package ) {
		$goods_by_tax_rate = $this->get_goods_by_tax_rate( $package );

		if ( empty( $goods_by_tax_rate ) ) {
			return array();
		}

		$rate_cost = (float) $rate->get_cost();
		$rate_tax  = array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) );

		if ( $rate_tax > 0 ) {
			return $this->calculate_shipping_breakdown_from_totals(
				$rate_cost,
				$rate_tax,
				$goods_by_tax_rate,
				wc_get_price_decimals()
			);
		}

		return $this->calculate_shipping_breakdown_from_configured_cost( $rate_cost, $goods_by_tax_rate );
	}

	/**
	 * Read shipping rate metadata across WooCommerce versions.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @param string           $key Meta key.
	 * @return mixed
	 */
	private function get_shipping_rate_meta( $rate, $key ) {
		if ( method_exists( $rate, 'get_meta' ) ) {
			return $rate->get_meta( $key );
		}

		if ( ! method_exists( $rate, 'get_meta_data' ) ) {
			return null;
		}

		foreach ( $rate->get_meta_data() as $meta ) {
			if ( is_object( $meta ) && method_exists( $meta, 'get_data' ) ) {
				$data = $meta->get_data();
			} elseif ( is_array( $meta ) ) {
				$data = $meta;
			} else {
				continue;
			}

			if ( isset( $data['key'] ) && $key === $data['key'] ) {
				return $data['value'] ?? null;
			}
		}

		return null;
	}

	/**
	 * Store a shipping breakdown in the session for checkout order creation.
	 *
	 * @param string $rate_id Shipping rate ID.
	 * @param array  $breakdown Pro-rata breakdown.
	 * @return void
	 */
	private function store_session_breakdown( $rate_id, array $breakdown ) {
		if ( ! WC()->session ) {
			return;
		}

		$breakdowns = WC()->session->get( 'wcprsv_shipping_breakdowns', array() );
		$breakdowns[ (string) $rate_id ] = $breakdown;
		WC()->session->set( 'wcprsv_shipping_breakdowns', $breakdowns );
	}

	/**
	 * Retrieve a shipping breakdown from the session.
	 *
	 * @param string $rate_id Shipping rate ID.
	 * @return array
	 */
	private function get_session_breakdown( $rate_id ) {
		if ( ! WC()->session ) {
			return array();
		}

		$breakdowns = WC()->session->get( 'wcprsv_shipping_breakdowns', array() );

		return ! empty( $breakdowns[ (string) $rate_id ]['lines'] ) ? $breakdowns[ (string) $rate_id ] : array();
	}

	/**
	 * Calculate the display breakdown directly from the current cart totals.
	 *
	 * @return array
	 */
	private function calculate_current_cart_breakdown() {
		if ( ! WC()->cart ) {
			return array();
		}

		$goods_by_tax_rate = $this->get_goods_by_tax_rate(
			array(
				'contents' => WC()->cart->get_cart(),
			)
		);

		if ( empty( $goods_by_tax_rate ) ) {
			return array();
		}

		$breakdown = $this->calculate_shipping_breakdown_from_totals(
			(float) WC()->cart->get_shipping_total(),
			(float) WC()->cart->get_shipping_tax(),
			$goods_by_tax_rate,
			wc_get_price_decimals()
		);

		return empty( $breakdown['lines'] ) ? $this->build_zero_shipping_breakdown( $goods_by_tax_rate ) : $breakdown;
	}

	/**
	 * Build a breakdown from WooCommerce Blocks cart store data.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function calculate_breakdown_from_blocks_snapshot( array $snapshot ) {
		$has_snapshot_shipping_totals = $this->blocks_snapshot_has_shipping_totals( $snapshot );
		$snapshot_shipping_incl      = $this->get_blocks_snapshot_shipping_including_vat( $snapshot );

		if ( $has_snapshot_shipping_totals && $snapshot_shipping_incl <= 0 ) {
			return $this->calculate_breakdown_from_blocks_totals( $snapshot, 0.0 );
		}

		$selected_rates        = $this->get_blocks_snapshot_selected_shipping_rates( $snapshot );
		$has_selected_rates    = ! empty( $selected_rates );
		$selected_shipping_incl = $has_selected_rates ? $this->get_blocks_shipping_rates_including_vat( $selected_rates, $snapshot ) : 0.0;

		if ( $has_selected_rates && $selected_shipping_incl <= 0 ) {
			return $this->calculate_breakdown_from_blocks_totals( $snapshot, 0.0 );
		}

		$current_shipping_incl = $has_snapshot_shipping_totals ? $snapshot_shipping_incl : $selected_shipping_incl;

		if ( $current_shipping_incl <= 0 ) {
			return $this->calculate_breakdown_from_blocks_totals( $snapshot, 0.0 );
		}

		$candidates = $has_selected_rates ? $this->get_blocks_shipping_rate_breakdown_candidates( $selected_rates ) : $this->get_blocks_snapshot_breakdown_candidates( $snapshot );

		if ( empty( $candidates ) ) {
			return $this->calculate_breakdown_from_blocks_totals( $snapshot, $current_shipping_incl );
		}

		$combined = $this->combine_breakdowns( $candidates );

		if ( ! empty( $combined['lines'] ) && $this->round_money( $combined['shipping_including_vat'] ) === $this->round_money( $current_shipping_incl ) ) {
			return $this->apply_blocks_snapshot_goods_tax( $combined, $snapshot );
		}

		foreach ( $candidates as $candidate ) {
			if ( ! empty( $candidate['lines'] ) && $this->round_money( $candidate['shipping_including_vat'] ) === $this->round_money( $current_shipping_incl ) ) {
				return $this->apply_blocks_snapshot_goods_tax( $candidate, $snapshot );
			}
		}

		if ( 1 === count( $candidates ) ) {
			return $this->apply_blocks_snapshot_goods_tax( reset( $candidates ), $snapshot );
		}

		return $this->calculate_breakdown_from_blocks_totals( $snapshot, $current_shipping_incl );
	}

	/**
	 * Rebuild a Blocks breakdown from current cart totals when rate metadata is missing.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @param float $shipping_including_vat Current shipping including VAT.
	 * @return array
	 */
	private function calculate_breakdown_from_blocks_totals( array $snapshot, $shipping_including_vat ) {
		$goods_by_tax_rate = $this->get_blocks_snapshot_goods_by_tax_rate( $snapshot );

		if ( empty( $goods_by_tax_rate ) ) {
			return array();
		}

		$breakdown = $this->calculate_shipping_breakdown_from_totals(
			$this->get_blocks_snapshot_shipping_excluding_vat( $snapshot, $shipping_including_vat ),
			$this->get_blocks_snapshot_shipping_vat( $snapshot ),
			$goods_by_tax_rate,
			$this->get_blocks_snapshot_minor_units( $snapshot )
		);

		if ( empty( $breakdown['lines'] ) ) {
			$breakdown = $this->build_zero_shipping_breakdown( $goods_by_tax_rate );
		}

		return $this->apply_blocks_snapshot_goods_tax( $breakdown, $snapshot );
	}

	/**
	 * Build a goods VAT specification when there are no shipping costs.
	 *
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate.
	 * @return array
	 */
	private function build_zero_shipping_breakdown( array $goods_by_tax_rate ) {
		$total_goods = 0.0;

		foreach ( $goods_by_tax_rate as $data ) {
			$total_goods += max( 0.0, (float) ( $data['amount_ex_vat'] ?? 0 ) );
		}

		$breakdown = array(
			'shipping_including_vat' => 0.0,
			'shipping_excluding_vat' => 0.0,
			'taxes'                  => array(),
			'lines'                  => array(),
		);

		if ( $total_goods <= 0 ) {
			return $breakdown;
		}

		foreach ( $goods_by_tax_rate as $tax_rate_id => $data ) {
			$goods_amount = max( 0.0, (float) ( $data['amount_ex_vat'] ?? 0 ) );
			$vat_rate     = max( 0.0, (float) ( $data['rate'] ?? 0 ) );

			if ( $goods_amount <= 0 ) {
				continue;
			}

			$tax_rate_id = (string) $tax_rate_id;

			$breakdown['taxes'][ $tax_rate_id ] = 0.0;
			$breakdown['lines'][ $tax_rate_id ] = array(
				'goods_amount_ex_vat'       => $goods_amount,
				'vat_rate'                  => $vat_rate,
				'share'                     => $goods_amount / $total_goods,
				'shipping_excluding_vat'    => 0.0,
				'shipping_vat'              => 0.0,
				'shipping_including_vat'    => 0.0,
				'unrounded_excluding_vat'   => 0.0,
				'unrounded_vat'             => 0.0,
				'unrounded_including_vat'   => 0.0,
			);
		}

		return $breakdown;
	}

	/**
	 * Get the selected shipping rates from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function get_blocks_snapshot_selected_shipping_rates( array $snapshot ) {
		$shipping_groups = array();

		if ( ! empty( $snapshot['shippingRates'] ) && is_array( $snapshot['shippingRates'] ) ) {
			$shipping_groups = $snapshot['shippingRates'];
		} elseif ( ! empty( $snapshot['cartData']['shippingRates'] ) && is_array( $snapshot['cartData']['shippingRates'] ) ) {
			$shipping_groups = $snapshot['cartData']['shippingRates'];
		}

		$selected = array();

		foreach ( $shipping_groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rates = array();

			if ( ! empty( $group['shipping_rates'] ) && is_array( $group['shipping_rates'] ) ) {
				$rates = $group['shipping_rates'];
			} elseif ( ! empty( $group['rates'] ) && is_array( $group['rates'] ) ) {
				$rates = $group['rates'];
			}

			foreach ( $rates as $rate ) {
				if ( is_array( $rate ) && ! empty( $rate['selected'] ) ) {
					$selected[] = $rate;
				}
			}

			if ( empty( $selected ) && 1 === count( $rates ) && is_array( reset( $rates ) ) ) {
				$selected[] = reset( $rates );
			}
		}

		return $selected;
	}

	/**
	 * Determine whether a Blocks snapshot contains authoritative shipping totals.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return bool
	 */
	private function blocks_snapshot_has_shipping_totals( array $snapshot ) {
		$totals = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();

		if ( array_key_exists( 'total_shipping', $totals ) || array_key_exists( 'total_shipping_tax', $totals ) ) {
			return true;
		}

		return isset( $snapshot['cartData']['totals'] ) && is_array( $snapshot['cartData']['totals'] ) && ( array_key_exists( 'total_shipping', $snapshot['cartData']['totals'] ) || array_key_exists( 'total_shipping_tax', $snapshot['cartData']['totals'] ) );
	}

	/**
	 * Get selected Blocks shipping rates including VAT.
	 *
	 * @param array $rates Selected Store API rates.
	 * @param array $snapshot Blocks cart snapshot.
	 * @return float
	 */
	private function get_blocks_shipping_rates_including_vat( array $rates, array $snapshot ) {
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );
		$total       = 0.0;

		foreach ( $rates as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}

			$price = $rate['price'] ?? $rate['cost'] ?? 0;

			if ( isset( $rate['prices']['price'] ) ) {
				$price = $rate['prices']['price'];
			}

			$total += $this->parse_store_api_amount( $price, $minor_units );

			if ( ! empty( $rate['taxes'] ) && is_array( $rate['taxes'] ) ) {
				foreach ( $rate['taxes'] as $tax ) {
					if ( is_array( $tax ) ) {
						$total += $this->parse_store_api_amount( $tax['price'] ?? $tax['total'] ?? 0, $minor_units );
					} else {
						$total += $this->parse_store_api_amount( $tax, $minor_units );
					}
				}
			}
		}

		return $this->round_money( $total );
	}

	/**
	 * Get current shipping including VAT from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return float
	 */
	private function get_blocks_snapshot_shipping_including_vat( array $snapshot ) {
		$totals      = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();
		$totals      = empty( $totals ) && ! empty( $snapshot['cartData']['totals'] ) && is_array( $snapshot['cartData']['totals'] ) ? $snapshot['cartData']['totals'] : $totals;
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );
		$shipping    = $this->parse_store_api_amount( $totals['total_shipping'] ?? 0, $minor_units );
		$shipping_tax = $this->parse_store_api_amount( $totals['total_shipping_tax'] ?? 0, $minor_units );

		return $this->round_money( $shipping + $shipping_tax );
	}

	/**
	 * Get current shipping excluding VAT from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @param float $fallback_including Fallback inclusive shipping amount.
	 * @return float
	 */
	private function get_blocks_snapshot_shipping_excluding_vat( array $snapshot, $fallback_including = 0.0 ) {
		$totals      = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();
		$totals      = empty( $totals ) && ! empty( $snapshot['cartData']['totals'] ) && is_array( $snapshot['cartData']['totals'] ) ? $snapshot['cartData']['totals'] : $totals;
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );

		if ( array_key_exists( 'total_shipping', $totals ) ) {
			return $this->round_money( $this->parse_store_api_amount( $totals['total_shipping'], $minor_units ) );
		}

		return $this->round_money( max( 0.0, (float) $fallback_including - $this->get_blocks_snapshot_shipping_vat( $snapshot ) ) );
	}

	/**
	 * Get current shipping VAT from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return float
	 */
	private function get_blocks_snapshot_shipping_vat( array $snapshot ) {
		$totals      = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();
		$totals      = empty( $totals ) && ! empty( $snapshot['cartData']['totals'] ) && is_array( $snapshot['cartData']['totals'] ) ? $snapshot['cartData']['totals'] : $totals;
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );

		return $this->round_money( $this->parse_store_api_amount( $totals['total_shipping_tax'] ?? 0, $minor_units ) );
	}

	/**
	 * Get currency minor units from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return int
	 */
	private function get_blocks_snapshot_minor_units( array $snapshot ) {
		$totals = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();

		if ( isset( $totals['currency_minor_unit'] ) ) {
			return absint( $totals['currency_minor_unit'] );
		}

		if ( isset( $snapshot['cartData']['totals']['currency_minor_unit'] ) ) {
			return absint( $snapshot['cartData']['totals']['currency_minor_unit'] );
		}

		return wc_get_price_decimals();
	}

	/**
	 * Parse a WooCommerce Store API amount.
	 *
	 * @param mixed $amount Store API amount.
	 * @param int   $minor_units Currency minor units.
	 * @return float
	 */
	private function parse_store_api_amount( $amount, $minor_units ) {
		$amount = trim( (string) $amount );

		if ( '' === $amount ) {
			return 0.0;
		}

		if ( false !== strpos( $amount, '.' ) || false !== strpos( $amount, ',' ) ) {
			return (float) str_replace( ',', '.', $amount );
		}

		return (float) $amount / ( 10 ** max( 0, (int) $minor_units ) );
	}

	/**
	 * Find breakdown metadata in a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function get_blocks_snapshot_breakdown_candidates( array $snapshot ) {
		$candidates = array();
		$this->collect_blocks_snapshot_breakdowns( $snapshot, $candidates );

		return array_values( $candidates );
	}

	/**
	 * Find breakdown metadata in selected Blocks shipping rates.
	 *
	 * @param array $rates Selected Store API rates.
	 * @return array
	 */
	private function get_blocks_shipping_rate_breakdown_candidates( array $rates ) {
		$candidates = array();

		foreach ( $rates as $rate ) {
			$this->collect_blocks_snapshot_breakdowns( $rate, $candidates );
		}

		return array_values( $candidates );
	}

	/**
	 * Apply Store API product tax totals to display breakdown lines.
	 *
	 * @param array $breakdown Pro-rata breakdown.
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function apply_blocks_snapshot_goods_tax( array $breakdown, array $snapshot ) {
		$tax_by_rate = $this->get_blocks_snapshot_tax_by_rate( $snapshot );

		if ( empty( $tax_by_rate ) || empty( $breakdown['lines'] ) ) {
			return $breakdown;
		}

		foreach ( $breakdown['lines'] as &$line ) {
			$key = $this->format_tax_rate_key( $line['vat_rate'] );

			if ( ! isset( $tax_by_rate[ $key ] ) ) {
				continue;
			}

			$line['goods_vat'] = $this->round_money( $tax_by_rate[ $key ] - (float) $line['shipping_vat'] );
		}
		unset( $line );

		return $breakdown;
	}

	/**
	 * Get Store API tax totals keyed by percentage.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function get_blocks_snapshot_tax_by_rate( array $snapshot ) {
		$totals      = ! empty( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();
		$tax_lines   = ! empty( $totals['tax_lines'] ) && is_array( $totals['tax_lines'] ) ? $totals['tax_lines'] : array();
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );

		if ( empty( $tax_lines ) && ! empty( $snapshot['cartData']['totals']['tax_lines'] ) && is_array( $snapshot['cartData']['totals']['tax_lines'] ) ) {
			$tax_lines = $snapshot['cartData']['totals']['tax_lines'];
		}

		$tax_by_rate = array();

		foreach ( $tax_lines as $tax_line ) {
			if ( ! is_array( $tax_line ) ) {
				continue;
			}

			$rate = $this->parse_store_api_tax_line_rate( $tax_line );

			if ( null === $rate ) {
				continue;
			}

			$amount = $this->parse_store_api_amount( $tax_line['price'] ?? $tax_line['total'] ?? 0, $minor_units );
			$key    = $this->format_tax_rate_key( $rate );

			if ( ! isset( $tax_by_rate[ $key ] ) ) {
				$tax_by_rate[ $key ] = 0.0;
			}

			$tax_by_rate[ $key ] += $amount;
		}

		return $tax_by_rate;
	}

	/**
	 * Reconstruct goods totals per VAT rate from a Blocks cart snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function get_blocks_snapshot_goods_by_tax_rate( array $snapshot ) {
		$items       = $this->get_blocks_snapshot_items( $snapshot );
		$minor_units = $this->get_blocks_snapshot_minor_units( $snapshot );
		$known_rates = array_keys( $this->get_blocks_snapshot_tax_by_rate( $snapshot ) );
		$goods       = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['totals'] ) || ! is_array( $item['totals'] ) ) {
				continue;
			}

			$totals = $item['totals'];
			$amount = $this->parse_store_api_amount( $totals['line_subtotal'] ?? $totals['line_total'] ?? 0, $minor_units );
			$tax    = $this->parse_store_api_amount( $totals['line_subtotal_tax'] ?? $totals['line_total_tax'] ?? 0, $minor_units );

			if ( $amount <= 0 ) {
				continue;
			}

			$rate = $amount > 0 ? $tax / $amount : 0.0;
			$rate = $this->match_blocks_snapshot_tax_rate( $rate, $known_rates );
			$key  = $this->format_tax_rate_key( $rate );

			if ( ! isset( $goods[ $key ] ) ) {
				$goods[ $key ] = array(
					'amount_ex_vat' => 0.0,
					'rate'          => $rate,
				);
			}

			$goods[ $key ]['amount_ex_vat'] += $amount;
		}

		return $goods;
	}

	/**
	 * Get cart items from a Blocks snapshot.
	 *
	 * @param array $snapshot Blocks cart snapshot.
	 * @return array
	 */
	private function get_blocks_snapshot_items( array $snapshot ) {
		if ( ! empty( $snapshot['cartData']['items'] ) && is_array( $snapshot['cartData']['items'] ) ) {
			return $snapshot['cartData']['items'];
		}

		if ( ! empty( $snapshot['items'] ) && is_array( $snapshot['items'] ) ) {
			return $snapshot['items'];
		}

		return array();
	}

	/**
	 * Match a rounded item tax rate to a known cart tax-line rate.
	 *
	 * @param float $item_rate Item rate inferred from rounded item totals.
	 * @param array $known_rate_keys Known formatted tax-rate keys.
	 * @return float
	 */
	private function match_blocks_snapshot_tax_rate( $item_rate, array $known_rate_keys ) {
		$item_rate = max( 0.0, (float) $item_rate );

		if ( empty( $known_rate_keys ) ) {
			return $item_rate;
		}

		$best_rate = $item_rate;
		$best_diff = null;

		foreach ( $known_rate_keys as $known_rate_key ) {
			$known_rate = (float) $known_rate_key;
			$diff       = abs( $known_rate - $item_rate );

			if ( null === $best_diff || $diff < $best_diff ) {
				$best_diff = $diff;
				$best_rate = $known_rate;
			}
		}

		return null !== $best_diff && $best_diff <= 0.015 ? $best_rate : $item_rate;
	}

	/**
	 * Parse a Store API tax line rate.
	 *
	 * @param array $tax_line Tax line.
	 * @return float|null
	 */
	private function parse_store_api_tax_line_rate( array $tax_line ) {
		foreach ( array( 'rate', 'rate_percent', 'percentage' ) as $key ) {
			if ( isset( $tax_line[ $key ] ) && is_numeric( $tax_line[ $key ] ) ) {
				$value = (float) $tax_line[ $key ];
				return $value > 1 ? $value / 100 : $value;
			}
		}

		$label = (string) ( $tax_line['name'] ?? $tax_line['label'] ?? '' );

		if ( preg_match( '/(\d+(?:[\\.,]\d+)?)\s*%/', $label, $matches ) ) {
			return (float) str_replace( ',', '.', $matches[1] ) / 100;
		}

		return null;
	}

	/**
	 * Recursively collect breakdown metadata from Blocks cart data.
	 *
	 * @param mixed $value Value to inspect.
	 * @param array $candidates Candidate breakdowns.
	 * @return void
	 */
	private function collect_blocks_snapshot_breakdowns( $value, array &$candidates ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( isset( $value['key'] ) && '_wcprsv_breakdown' === $value['key'] && isset( $value['value'] ) ) {
			$this->add_blocks_breakdown_candidate( $candidates, $value['value'] );
		}

		if ( isset( $value['_wcprsv_breakdown'] ) ) {
			$this->add_blocks_breakdown_candidate( $candidates, $value['_wcprsv_breakdown'] );
		}

		foreach ( $value as $child ) {
			$this->collect_blocks_snapshot_breakdowns( $child, $candidates );
		}
	}

	/**
	 * Add one Blocks breakdown candidate.
	 *
	 * @param array $candidates Candidate breakdowns.
	 * @param mixed $value Raw breakdown value.
	 * @return void
	 */
	private function add_blocks_breakdown_candidate( array &$candidates, $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		if ( empty( $value['lines'] ) || ! isset( $value['shipping_including_vat'] ) ) {
			return;
		}

		$key = md5( wp_json_encode( $value ) );
		$candidates[ $key ] = $value;
	}

	/**
	 * Combine multiple breakdowns.
	 *
	 * @param array $breakdowns Breakdown list.
	 * @return array
	 */
	private function combine_breakdowns( array $breakdowns ) {
		$combined = array(
			'shipping_including_vat' => 0.0,
			'shipping_excluding_vat' => 0.0,
			'taxes'                  => array(),
			'lines'                  => array(),
		);

		foreach ( $breakdowns as $breakdown ) {
			if ( empty( $breakdown['lines'] ) ) {
				continue;
			}

			$combined['shipping_including_vat'] += (float) $breakdown['shipping_including_vat'];
			$combined['shipping_excluding_vat'] += (float) $breakdown['shipping_excluding_vat'];

			foreach ( $breakdown['taxes'] as $tax_rate_id => $tax_amount ) {
				if ( ! isset( $combined['taxes'][ $tax_rate_id ] ) ) {
					$combined['taxes'][ $tax_rate_id ] = 0.0;
				}

				$combined['taxes'][ $tax_rate_id ] += (float) $tax_amount;
			}

			foreach ( $breakdown['lines'] as $tax_rate_id => $line ) {
				if ( ! isset( $combined['lines'][ $tax_rate_id ] ) ) {
					$combined['lines'][ $tax_rate_id ] = $line;
					continue;
				}

				$combined['lines'][ $tax_rate_id ]['goods_amount_ex_vat']    += (float) $line['goods_amount_ex_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_excluding_vat'] += (float) $line['shipping_excluding_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_vat']           += (float) $line['shipping_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_including_vat'] += (float) $line['shipping_including_vat'];
			}
		}

		return $combined;
	}

	/**
	 * Build debug payload for browser devtools.
	 *
	 * @param array $breakdown Pro-rata breakdown.
	 * @return array
	 */
	private function get_frontend_debug_payload( array $breakdown, $blocks_snapshot = null ) {
		return array(
			'cart'      => WC()->cart ? array(
				'subtotal'       => WC()->cart->get_subtotal(),
				'total'          => WC()->cart->get_total( 'edit' ),
				'shipping_total' => WC()->cart->get_shipping_total(),
				'shipping_tax'   => WC()->cart->get_shipping_tax(),
				'taxes'          => WC()->cart->get_taxes(),
			) : null,
			'packages'  => WC()->shipping() ? $this->summarize_packages( WC()->shipping()->get_packages() ) : array(),
			'breakdown' => $breakdown,
			'language'  => array(
				'current'     => $this->settings->get_current_language(),
				'source'      => $this->settings->get_wpml_source_language(),
				'wpml_active' => has_filter( 'wpml_current_language' ),
			),
			'blocks'    => is_array( $blocks_snapshot ) ? array(
				'shipping_including_vat' => $this->get_blocks_snapshot_shipping_including_vat( $blocks_snapshot ),
				'candidate_count'        => count( $this->get_blocks_snapshot_breakdown_candidates( $blocks_snapshot ) ),
			) : null,
		);
	}

	/**
	 * Get selected shipping including VAT from cart totals or selected package rates.
	 *
	 * @return float
	 */
	private function get_current_shipping_including_vat( $allow_rate_fallback = true ) {
		$shipping_including_vat = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();

		if ( $shipping_including_vat > 0 ) {
			return $shipping_including_vat;
		}

		if ( ! $allow_rate_fallback ) {
			return 0.0;
		}

		if ( ! WC()->shipping() || ! WC()->session ) {
			return 0.0;
		}

		$packages       = WC()->shipping()->get_packages();
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		foreach ( $packages as $package_index => $package ) {
			$chosen_rate_id = $chosen_methods[ $package_index ] ?? '';

			if ( empty( $chosen_rate_id ) || empty( $package['rates'][ $chosen_rate_id ] ) ) {
				continue;
			}

			$rate = $package['rates'][ $chosen_rate_id ];

			if ( ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
				continue;
			}

			$shipping_including_vat += (float) $rate->get_cost() + array_sum( $rate->get_taxes() );
		}

		return $shipping_including_vat;
	}

	/**
	 * Build the customer-facing breakdown HTML.
	 *
	 * @param array $breakdown Pro-rata breakdown.
	 * @return string
	 */
	private function get_breakdown_html( array $breakdown, $order = null ) {
		$display_lines      = $this->get_display_lines( $breakdown['lines'], $breakdown, $order );
		$goods_total        = $this->sum_display_column( $display_lines, 'goods_amount_ex_vat' );
		$shipping_total     = $this->sum_display_column( $display_lines, 'shipping_excluding_vat' );
		$goods_vat_total    = $this->sum_display_column( $display_lines, 'goods_vat' );
		$shipping_vat_total = $this->sum_display_column( $display_lines, 'shipping_vat' );
		$total_excluding    = $this->round_money( $goods_total + $shipping_total );
		$total_vat          = $this->round_money( $goods_vat_total + $shipping_vat_total );
		$total_including    = $this->round_money( $total_excluding + $total_vat );

		ob_start();
		?>
		<div class="wcprsv-breakdown" data-wcprsv-breakdown="1">
			<div class="wcprsv-breakdown__title"><?php echo esc_html__( 'VAT specification', 'wc-pro-rata-shipping-vat' ); ?></div>

			<div class="wcprsv-summary" role="table" aria-label="<?php echo esc_attr__( 'VAT specification', 'wc-pro-rata-shipping-vat' ); ?>">
				<div class="wcprsv-summary__row wcprsv-summary__row--head" role="row">
					<div role="columnheader"><?php echo esc_html__( 'Rate', 'wc-pro-rata-shipping-vat' ); ?></div>
					<div role="columnheader"><?php echo esc_html__( 'Goods', 'wc-pro-rata-shipping-vat' ); ?></div>
					<div role="columnheader"><?php echo esc_html__( 'Shipping', 'wc-pro-rata-shipping-vat' ); ?></div>
					<div role="columnheader"><?php echo esc_html__( 'VAT', 'wc-pro-rata-shipping-vat' ); ?></div>
				</div>
				<?php foreach ( $display_lines as $line ) : ?>
					<div class="wcprsv-summary__row" role="row">
						<div role="cell"><?php echo esc_html( $this->format_vat_rate( $line['vat_rate'] ) ); ?></div>
						<div role="cell"><?php echo wp_kses_post( wc_price( $line['goods_amount_ex_vat'] ) ); ?></div>
						<div role="cell"><?php echo wp_kses_post( wc_price( $line['shipping_excluding_vat'] ) ); ?></div>
						<div role="cell"><?php echo wp_kses_post( wc_price( $line['total_vat'] ) ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="wcprsv-totals">
				<div class="wcprsv-totals__row">
					<span><?php echo esc_html__( 'Total excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></span>
					<strong><?php echo wp_kses_post( wc_price( $total_excluding ) ); ?></strong>
				</div>
				<div class="wcprsv-totals__row">
					<span><?php echo esc_html__( 'Total VAT', 'wc-pro-rata-shipping-vat' ); ?></span>
					<strong><?php echo wp_kses_post( wc_price( $total_vat ) ); ?></strong>
				</div>
				<div class="wcprsv-totals__row wcprsv-totals__row--grand">
					<span><?php echo esc_html__( 'Total incl. VAT', 'wc-pro-rata-shipping-vat' ); ?></span>
					<strong><?php echo wp_kses_post( wc_price( $total_including ) ); ?></strong>
				</div>
			</div>

			<details class="wcprsv-details">
				<summary><?php echo esc_html__( 'Show calculation', 'wc-pro-rata-shipping-vat' ); ?></summary>
				<div class="wcprsv-detail-lines">
					<?php foreach ( $display_lines as $line ) : ?>
						<div class="wcprsv-detail-line">
							<div class="wcprsv-detail-line__title"><?php echo esc_html( $this->format_vat_rate( $line['vat_rate'] ) ); ?></div>
							<div><span><?php echo esc_html__( 'Goods excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></span><strong><?php echo wp_kses_post( wc_price( $line['goods_amount_ex_vat'] ) ); ?></strong></div>
							<div><span><?php echo esc_html__( 'Shipping excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></span><strong><?php echo wp_kses_post( wc_price( $line['shipping_excluding_vat'] ) ); ?></strong></div>
							<div><span><?php echo esc_html__( 'Goods VAT', 'wc-pro-rata-shipping-vat' ); ?></span><strong><?php echo wp_kses_post( wc_price( $line['goods_vat'] ) ); ?></strong></div>
							<div><span><?php echo esc_html__( 'Shipping VAT', 'wc-pro-rata-shipping-vat' ); ?></span><strong><?php echo wp_kses_post( wc_price( $line['shipping_vat'] ) ); ?></strong></div>
							<div><span><?php echo esc_html__( 'Incl. VAT', 'wc-pro-rata-shipping-vat' ); ?></span><strong><?php echo wp_kses_post( wc_price( $line['including_vat'] ) ); ?></strong></div>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build a PDF-friendly VAT breakdown table.
	 *
	 * @param array    $breakdown Pro-rata breakdown.
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_pdf_breakdown_html( array $breakdown, $order ) {
		$display_lines      = $this->get_display_lines( $breakdown['lines'], $breakdown, $order );
		$goods_total        = $this->sum_display_column( $display_lines, 'goods_amount_ex_vat' );
		$shipping_total     = $this->sum_display_column( $display_lines, 'shipping_excluding_vat' );
		$goods_vat_total    = $this->sum_display_column( $display_lines, 'goods_vat' );
		$shipping_vat_total = $this->sum_display_column( $display_lines, 'shipping_vat' );
		$total_excluding    = $this->round_money( $goods_total + $shipping_total );
		$total_vat          = $this->round_money( $goods_vat_total + $shipping_vat_total );
		$total_including    = $this->round_money( $total_excluding + $total_vat );

		ob_start();
		?>
		<div class="wcprsv-pdf-breakdown" style="margin-top: 18px;">
			<h3 style="margin: 0 0 8px;"><?php echo esc_html__( 'VAT specification', 'wc-pro-rata-shipping-vat' ); ?></h3>
			<table style="width: 100%; border-collapse: collapse; font-size: 10px;">
				<thead>
					<tr>
						<th style="border-bottom: 1px solid #999; text-align: left;"><?php echo esc_html__( 'Rate', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-bottom: 1px solid #999; text-align: right;"><?php echo esc_html__( 'Goods excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-bottom: 1px solid #999; text-align: right;"><?php echo esc_html__( 'Shipping excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-bottom: 1px solid #999; text-align: right;"><?php echo esc_html__( 'Goods VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-bottom: 1px solid #999; text-align: right;"><?php echo esc_html__( 'Shipping VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-bottom: 1px solid #999; text-align: right;"><?php echo esc_html__( 'Incl. VAT', 'wc-pro-rata-shipping-vat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $display_lines as $line ) : ?>
						<tr>
							<td style="padding-top: 4px;"><?php echo esc_html( $this->format_vat_rate( $line['vat_rate'] ) ); ?></td>
							<td style="padding-top: 4px; text-align: right;"><?php echo wp_kses_post( wc_price( $line['goods_amount_ex_vat'] ) ); ?></td>
							<td style="padding-top: 4px; text-align: right;"><?php echo wp_kses_post( wc_price( $line['shipping_excluding_vat'] ) ); ?></td>
							<td style="padding-top: 4px; text-align: right;"><?php echo wp_kses_post( wc_price( $line['goods_vat'] ) ); ?></td>
							<td style="padding-top: 4px; text-align: right;"><?php echo wp_kses_post( wc_price( $line['shipping_vat'] ) ); ?></td>
							<td style="padding-top: 4px; text-align: right;"><?php echo wp_kses_post( wc_price( $line['including_vat'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: left;"><?php echo esc_html__( 'Total', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $goods_total ) ); ?></th>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $shipping_total ) ); ?></th>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $goods_vat_total ) ); ?></th>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $shipping_vat_total ) ); ?></th>
						<th style="border-top: 1px solid #999; padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $total_including ) ); ?></th>
					</tr>
					<tr>
						<td colspan="5" style="padding-top: 5px; text-align: right;"><?php echo esc_html__( 'Total excl. VAT', 'wc-pro-rata-shipping-vat' ); ?></td>
						<td style="padding-top: 5px; text-align: right;"><?php echo wp_kses_post( wc_price( $total_excluding ) ); ?></td>
					</tr>
					<tr>
						<td colspan="5" style="text-align: right;"><?php echo esc_html__( 'Total VAT', 'wc-pro-rata-shipping-vat' ); ?></td>
						<td style="text-align: right;"><?php echo wp_kses_post( wc_price( $total_vat ) ); ?></td>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sum goods VAT for display lines.
	 *
	 * @param array $lines Breakdown lines.
	 * @return float
	 */
	private function sum_goods_vat( array $lines ) {
		$total = 0.0;

		foreach ( $lines as $line ) {
			$total += (float) $line['goods_amount_ex_vat'] * (float) $line['vat_rate'];
		}

		return $total;
	}

	/**
	 * Build display lines using Excel-style per-cell rounding.
	 *
	 * @param array $lines Raw breakdown lines.
	 * @param array $breakdown Full breakdown totals.
	 * @return array
	 */
	private function get_display_lines( array $lines, array $breakdown, $order = null ) {
		$display_lines           = array();
		$order_goods_tax_by_rate = is_a( $order, 'WC_Order' ) ? $this->get_order_goods_tax_by_rate( $order ) : array();
		$largest_key             = null;
		$largest_total           = -1.0;

		foreach ( $lines as $key => $line ) {
			$goods_amount = $this->round_money( $line['goods_amount_ex_vat'] );
			$shipping_ex  = $this->round_money( $line['shipping_excluding_vat'] );
			$goods_vat    = isset( $line['goods_vat'] ) ? $this->round_money( $line['goods_vat'] ) : $this->round_money( $goods_amount * $line['vat_rate'] );
			$goods_vat    = array_key_exists( (string) $key, $order_goods_tax_by_rate ) ? $order_goods_tax_by_rate[ (string) $key ] : $goods_vat;
			$shipping_vat = $this->round_money( $line['shipping_vat'] );
			$total_vat    = $this->round_money( $goods_vat + $shipping_vat );
			$including    = $this->round_money( $goods_amount + $shipping_ex + $total_vat );

			$display_lines[ $key ] = array(
				'vat_rate'               => (float) $line['vat_rate'],
				'goods_amount_ex_vat'    => $goods_amount,
				'shipping_excluding_vat' => $shipping_ex,
				'goods_vat'              => $goods_vat,
				'shipping_vat'           => $shipping_vat,
				'total_vat'              => $total_vat,
				'including_vat'          => $including,
			);

			if ( $including > $largest_total ) {
				$largest_key   = $key;
				$largest_total = $including;
			}
		}

		$this->reconcile_display_lines( $display_lines, $breakdown, $largest_key, $order );

		return $display_lines;
	}

	/**
	 * Reconcile rounded display cells with WooCommerce-facing totals.
	 *
	 * @param array       $display_lines Rounded display lines.
	 * @param array       $breakdown Full breakdown totals.
	 * @param string|null $largest_key Line key that receives rounding deltas.
	 * @return void
	 */
	private function reconcile_display_lines( array &$display_lines, array $breakdown, $largest_key, $order = null ) {
		if ( empty( $display_lines ) || null === $largest_key || ! isset( $display_lines[ $largest_key ] ) ) {
			return;
		}

		$cart_total_including     = is_a( $order, 'WC_Order' ) ? 0.0 : $this->get_cart_total_including_vat();
		$order_total_including    = is_a( $order, 'WC_Order' ) ? $this->round_money( $order->get_total() ) : 0.0;
		$target_total_including   = $cart_total_including > 0 ? $cart_total_including : $order_total_including;
		$target_shipping_ex       = $this->round_money( $breakdown['shipping_excluding_vat'] );
		$target_shipping_vat      = $this->round_money( $breakdown['shipping_including_vat'] - $breakdown['shipping_excluding_vat'] );
		$target_shipping_incl     = $this->round_money( $breakdown['shipping_including_vat'] );
		$current_shipping_ex      = $this->sum_display_column( $display_lines, 'shipping_excluding_vat' );
		$current_shipping_vat     = $this->sum_display_column( $display_lines, 'shipping_vat' );
		$shipping_incl_difference = $this->round_money( $target_shipping_incl - $current_shipping_ex - $current_shipping_vat );

		$display_lines[ $largest_key ]['shipping_vat'] = $this->round_money(
			$display_lines[ $largest_key ]['shipping_vat'] + $shipping_incl_difference
		);

		$shipping_ex_difference = $this->round_money( $target_shipping_ex - $current_shipping_ex );

		if ( 0.0 !== $shipping_ex_difference ) {
			$display_lines[ $largest_key ]['shipping_excluding_vat'] = $this->round_money(
				$display_lines[ $largest_key ]['shipping_excluding_vat'] + $shipping_ex_difference
			);
		}

		$shipping_vat_difference = $this->round_money( $target_shipping_vat - $this->sum_display_column( $display_lines, 'shipping_vat' ) );

		if ( 0.0 !== $shipping_vat_difference ) {
			$display_lines[ $largest_key ]['shipping_vat'] = $this->round_money(
				$display_lines[ $largest_key ]['shipping_vat'] + $shipping_vat_difference
			);
		}

		$goods_vat_difference = 0.0;

		if ( $cart_total_including > 0 ) {
			$target_goods_total = $this->round_money( $cart_total_including - $target_shipping_incl );
			$current_goods_total = $this->round_money(
				$this->sum_display_column( $display_lines, 'goods_amount_ex_vat' ) + $this->sum_display_column( $display_lines, 'goods_vat' )
			);
			$goods_vat_difference = $this->round_money( $target_goods_total - $current_goods_total );
		}

		if ( 0.0 !== $goods_vat_difference ) {
			$display_lines[ $largest_key ]['goods_vat'] = $this->round_money(
				$display_lines[ $largest_key ]['goods_vat'] + $goods_vat_difference
			);
		}

		$target_total_vat = is_a( $order, 'WC_Order' ) ? 0.0 : $this->get_cart_tax_total();

		if ( $target_total_vat > 0 ) {
			$current_total_vat = $this->round_money(
				$this->sum_display_column( $display_lines, 'goods_vat' ) + $this->sum_display_column( $display_lines, 'shipping_vat' )
			);
			$total_vat_difference = $this->round_money( $target_total_vat - $current_total_vat );

			if ( 0.0 !== $total_vat_difference ) {
				$display_lines[ $largest_key ]['goods_vat'] = $this->round_money(
					$display_lines[ $largest_key ]['goods_vat'] + $total_vat_difference
				);
			}
		}

		if ( $target_total_including > 0 ) {
			$current_including_total = 0.0;

			foreach ( $display_lines as $line ) {
				$current_including_total += (float) $line['goods_amount_ex_vat'] + (float) $line['shipping_excluding_vat'] + (float) $line['goods_vat'] + (float) $line['shipping_vat'];
			}

			$including_difference = $this->round_money( $target_total_including - $current_including_total );

			if ( 0.0 !== $including_difference ) {
				$display_lines[ $largest_key ]['goods_amount_ex_vat'] = $this->round_money(
					$display_lines[ $largest_key ]['goods_amount_ex_vat'] + $including_difference
				);
			}
		}

		foreach ( $display_lines as &$line ) {
			$line['total_vat']     = $this->round_money( $line['goods_vat'] + $line['shipping_vat'] );
			$line['including_vat'] = $this->round_money( $line['goods_amount_ex_vat'] + $line['shipping_excluding_vat'] + $line['total_vat'] );
		}
		unset( $line );
	}

	/**
	 * Sum a rounded display column.
	 *
	 * @param array  $lines Display lines.
	 * @param string $column Column name.
	 * @return float
	 */
	private function sum_display_column( array $lines, $column ) {
		$total = 0.0;

		foreach ( $lines as $line ) {
			$total += (float) $line[ $column ];
		}

		return $this->round_money( $total );
	}

	/**
	 * Get order goods tax totals keyed by WooCommerce tax rate ID.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_goods_tax_by_rate( $order ) {
		$goods_tax_by_rate = array();

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$goods_tax_by_rate[ (string) $tax_item->get_rate_id() ] = $this->round_money( $tax_item->get_tax_total() );
		}

		return $goods_tax_by_rate;
	}

	/**
	 * Round a monetary display value like Excel ROUND(cell, 2).
	 *
	 * @param float $value Raw value.
	 * @return float
	 */
	private function round_money( $value ) {
		return round( (float) $value, wc_get_price_decimals() );
	}

	/**
	 * Get the current cart total including VAT.
	 *
	 * @return float
	 */
	private function get_cart_total_including_vat() {
		if ( ! WC()->cart ) {
			return 0.0;
		}

		return $this->round_money( (float) WC()->cart->get_total( 'edit' ) );
	}

	/**
	 * Get the current cart tax total.
	 *
	 * @return float
	 */
	private function get_cart_tax_total() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return $this->round_money( array_sum( array_map( 'floatval', (array) WC()->cart->get_taxes() ) ) );
	}

	/**
	 * Check whether a WP Overnight PDF document type should show a VAT specification.
	 *
	 * @param string $document_type PDF document type.
	 * @return bool
	 */
	private function is_supported_pdf_document_type( $document_type ) {
		return in_array( $this->normalize_pdf_document_type( $document_type ), array( 'invoice', 'credit-note', 'credit-note-refund' ), true );
	}

	/**
	 * Check whether a PDF document type is a credit note.
	 *
	 * @param string $document_type PDF document type.
	 * @return bool
	 */
	private function is_credit_note_document_type( $document_type ) {
		return in_array( $this->normalize_pdf_document_type( $document_type ), array( 'credit-note', 'credit-note-refund' ), true );
	}

	/**
	 * Normalize a PDF document type for comparisons.
	 *
	 * @param string $document_type PDF document type.
	 * @return string
	 */
	private function normalize_pdf_document_type( $document_type ) {
		$document_type = strtolower( str_replace( '_', '-', (string) $document_type ) );

		if ( in_array( $document_type, array( 'creditnote', 'credit-note', 'credit-note-refund', 'refund' ), true ) ) {
			return 'refund' === $document_type ? 'credit-note-refund' : 'credit-note';
		}

		return $document_type;
	}

	/**
	 * Get a pro-rata breakdown from order meta or order line items.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_breakdown( $order ) {
		$breakdown = $order->get_meta( '_wcprsv_breakdown' );

		if ( ! empty( $breakdown['lines'] ) ) {
			return $breakdown;
		}

		$breakdown = $this->get_breakdown_from_order_shipping_items( $order );

		if ( ! empty( $breakdown['lines'] ) ) {
			return $breakdown;
		}

		$goods_by_tax_rate = $this->get_order_goods_by_tax_rate( $order );
		$shipping_excl     = $this->round_money( (float) $order->get_shipping_total() );
		$shipping_tax      = $this->round_money( (float) $order->get_shipping_tax() );
		$shipping_incl     = $this->round_money( $shipping_excl + $shipping_tax );

		if ( empty( $goods_by_tax_rate ) || $shipping_incl <= 0 ) {
			return array();
		}

		return $this->calculate_shipping_breakdown_from_totals( $shipping_excl, $shipping_tax, $goods_by_tax_rate, wc_get_price_decimals() );
	}

	/**
	 * Build a VAT specification for a credit note or refund document.
	 *
	 * @param mixed $credit_note Credit note or refund object.
	 * @return array
	 */
	private function get_credit_note_breakdown( $credit_note ) {
		$goods_by_tax_rate = $this->get_credit_note_goods_by_tax_rate( $credit_note );

		if ( empty( $goods_by_tax_rate ) ) {
			return array();
		}

		$shipping_incl = method_exists( $credit_note, 'get_shipping_total' ) && method_exists( $credit_note, 'get_shipping_tax' )
			? $this->round_money( (float) $credit_note->get_shipping_total() + (float) $credit_note->get_shipping_tax() )
			: 0.0;

		$absolute_goods = array();

		foreach ( $goods_by_tax_rate as $rate_id => $data ) {
			$absolute_goods[ $rate_id ] = array(
				'amount_ex_vat' => abs( (float) $data['amount_ex_vat'] ),
				'rate'          => (float) $data['rate'],
			);
		}

		if ( 0.0 === $shipping_incl ) {
			$breakdown = $this->build_zero_shipping_breakdown( $absolute_goods );
		} else {
			$shipping_excl = method_exists( $credit_note, 'get_shipping_total' ) ? abs( (float) $credit_note->get_shipping_total() ) : 0.0;
			$shipping_tax  = method_exists( $credit_note, 'get_shipping_tax' ) ? abs( (float) $credit_note->get_shipping_tax() ) : 0.0;
			$breakdown     = $this->calculate_shipping_breakdown_from_totals( $shipping_excl, $shipping_tax, $absolute_goods, wc_get_price_decimals() );
		}

		if ( empty( $breakdown['lines'] ) ) {
			return array();
		}

		$breakdown = $this->negate_breakdown_amounts( $breakdown );

		foreach ( $breakdown['lines'] as $rate_id => &$line ) {
			if ( isset( $goods_by_tax_rate[ $rate_id ]['goods_vat'] ) ) {
				$line['goods_vat'] = $this->round_money( $goods_by_tax_rate[ $rate_id ]['goods_vat'] );
			}
		}
		unset( $line );

		return $breakdown;
	}

	/**
	 * Negate all monetary values in a breakdown for credit notes.
	 *
	 * @param array $breakdown Positive breakdown.
	 * @return array
	 */
	private function negate_breakdown_amounts( array $breakdown ) {
		foreach ( array( 'shipping_including_vat', 'shipping_excluding_vat' ) as $key ) {
			if ( isset( $breakdown[ $key ] ) ) {
				$amount = abs( (float) $breakdown[ $key ] );
				$breakdown[ $key ] = $amount > 0 ? -1 * $amount : 0.0;
			}
		}

		foreach ( $breakdown['taxes'] as &$tax_amount ) {
			$amount = abs( (float) $tax_amount );
			$tax_amount = $amount > 0 ? -1 * $amount : 0.0;
		}
		unset( $tax_amount );

		foreach ( $breakdown['lines'] as &$line ) {
			foreach ( array( 'goods_amount_ex_vat', 'shipping_excluding_vat', 'shipping_vat', 'shipping_including_vat', 'unrounded_excluding_vat', 'unrounded_vat', 'unrounded_including_vat' ) as $key ) {
				if ( isset( $line[ $key ] ) ) {
					$amount = abs( (float) $line[ $key ] );
					$line[ $key ] = $amount > 0 ? -1 * $amount : 0.0;
				}
			}
		}
		unset( $line );

		return $breakdown;
	}

	/**
	 * Combine breakdown metadata stored on order shipping items.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_breakdown_from_order_shipping_items( $order ) {
		$combined = array(
			'shipping_including_vat' => 0.0,
			'shipping_excluding_vat' => 0.0,
			'taxes'                  => array(),
			'lines'                  => array(),
		);

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$breakdown = $item->get_meta( '_wcprsv_breakdown' );

			if ( empty( $breakdown['lines'] ) ) {
				continue;
			}

			$combined['shipping_including_vat'] += (float) $breakdown['shipping_including_vat'];
			$combined['shipping_excluding_vat'] += (float) $breakdown['shipping_excluding_vat'];

			foreach ( $breakdown['taxes'] as $tax_rate_id => $tax_amount ) {
				if ( ! isset( $combined['taxes'][ $tax_rate_id ] ) ) {
					$combined['taxes'][ $tax_rate_id ] = 0.0;
				}

				$combined['taxes'][ $tax_rate_id ] += (float) $tax_amount;
			}

			foreach ( $breakdown['lines'] as $tax_rate_id => $line ) {
				if ( ! isset( $combined['lines'][ $tax_rate_id ] ) ) {
					$combined['lines'][ $tax_rate_id ] = $line;
					continue;
				}

				$combined['lines'][ $tax_rate_id ]['goods_amount_ex_vat']    += (float) $line['goods_amount_ex_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_excluding_vat'] += (float) $line['shipping_excluding_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_vat']           += (float) $line['shipping_vat'];
				$combined['lines'][ $tax_rate_id ]['shipping_including_vat'] += (float) $line['shipping_including_vat'];
			}
		}

		return $combined;
	}

	/**
	 * Apply pro-rata shipping tax amounts to WooCommerce order tax lines.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $breakdown Pro-rata breakdown.
	 * @return void
	 */
	private function apply_order_tax_lines( $order, array $breakdown ) {
		if ( empty( $breakdown['taxes'] ) ) {
			return;
		}

		$tax_items_by_rate_id = array();

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$tax_items_by_rate_id[ (string) $tax_item->get_rate_id() ] = $tax_item;

			if ( method_exists( $tax_item, 'set_shipping_tax_total' ) ) {
				$tax_item->set_shipping_tax_total( 0 );
			}
		}

		foreach ( $breakdown['taxes'] as $rate_id => $shipping_tax_amount ) {
			$rate_id = (string) $rate_id;

			if ( ! isset( $tax_items_by_rate_id[ $rate_id ] ) ) {
				$tax_item = $this->create_order_tax_item( $rate_id );
				$order->add_item( $tax_item );
				$tax_items_by_rate_id[ $rate_id ] = $tax_item;
			}

			if ( method_exists( $tax_items_by_rate_id[ $rate_id ], 'set_shipping_tax_total' ) ) {
				$tax_items_by_rate_id[ $rate_id ]->set_shipping_tax_total( wc_format_decimal( $shipping_tax_amount, wc_get_price_decimals() ) );
			}
		}

		$this->debug_log(
			'apply_order_tax_lines',
			array(
				'order_id'   => $order->get_id(),
				'breakdown_taxes' => $breakdown['taxes'],
				'order_tax_items' => $this->summarize_order_tax_items( $order ),
			)
		);
	}

	/**
	 * Create an order tax item for a WooCommerce tax rate.
	 *
	 * @param string $rate_id Tax rate ID.
	 * @return WC_Order_Item_Tax
	 */
	private function create_order_tax_item( $rate_id ) {
		$tax_rate = class_exists( 'WC_Tax' ) && method_exists( 'WC_Tax', '_get_tax_rate' ) ? WC_Tax::_get_tax_rate( $rate_id ) : array();
		$tax_item = new WC_Order_Item_Tax();

		$tax_item->set_rate_id( (int) $rate_id );
		$tax_item->set_label( ! empty( $tax_rate['tax_rate_name'] ) ? $tax_rate['tax_rate_name'] : __( 'VAT', 'wc-pro-rata-shipping-vat' ) );
		if ( method_exists( 'WC_Tax', 'get_rate_code' ) ) {
			$tax_item->set_rate_code( WC_Tax::get_rate_code( $rate_id ) );
		}
		$tax_item->set_rate_percent( ! empty( $tax_rate['tax_rate'] ) ? (float) $tax_rate['tax_rate'] : 0 );
		$tax_item->set_compound( ! empty( $tax_rate['tax_rate_compound'] ) );
		$tax_item->set_tax_total( 0 );

		if ( method_exists( $tax_item, 'set_shipping_tax_total' ) ) {
			$tax_item->set_shipping_tax_total( 0 );
		}

		return $tax_item;
	}

	/**
	 * Write a debug log entry when enabled.
	 *
	 * @param string $stage Stage name.
	 * @param array  $data Debug data.
	 * @return void
	 */
	private function debug_log( $stage, array $data = array() ) {
		if ( ! $this->settings->is_debug_enabled() ) {
			return;
		}

		$payload = array(
			'stage' => $stage,
			'data'  => $this->sanitize_debug_data( $data ),
		);

		try {
			$message = wp_json_encode( $payload );

			if ( ! is_string( $message ) || '' === $message ) {
				$message = wp_json_encode(
					array(
						'stage' => $stage,
						'error' => 'Could not encode debug payload.',
					)
				);
			}

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->debug( $message, array( 'source' => 'wc-pro-rata-shipping-vat' ) );
				return;
			}

			error_log( '[wc-pro-rata-shipping-vat] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} catch ( Throwable $e ) {
			error_log( '[wc-pro-rata-shipping-vat] debug logging failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Sanitize debug data to keep checkout logging compact and safe.
	 *
	 * @param mixed $data Raw debug data.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_debug_data( $data, $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[max-depth]';
		}

		if ( is_null( $data ) || is_scalar( $data ) ) {
			return $data;
		}

		if ( is_object( $data ) ) {
			return array(
				'object' => get_class( $data ),
			);
		}

		if ( ! is_array( $data ) ) {
			return '[' . gettype( $data ) . ']';
		}

		$output = array();
		$count  = 0;

		foreach ( $data as $key => $value ) {
			if ( $count >= 80 ) {
				$output['__truncated'] = count( $data ) - $count;
				break;
			}

			$output[ $key ] = $this->sanitize_debug_data( $value, $depth + 1 );
			$count++;
		}

		return $output;
	}

	/**
	 * Summarize shipping packages for debug output.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	private function summarize_packages( array $packages ) {
		$summary = array();

		foreach ( $packages as $index => $package ) {
			$summary[ $index ] = $this->summarize_package( $package );
		}

		return $summary;
	}

	/**
	 * Summarize one shipping package for debug output.
	 *
	 * @param array $package Shipping package.
	 * @return array
	 */
	private function summarize_package( array $package ) {
		$rates = array();

		if ( ! empty( $package['rates'] ) ) {
			foreach ( $package['rates'] as $rate_id => $rate ) {
				if ( ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
					continue;
				}

				$rates[ $rate_id ] = array(
					'id'     => $rate->get_id(),
					'label'  => $rate->get_label(),
					'cost'   => $rate->get_cost(),
					'taxes'  => $rate->get_taxes(),
				);
			}
		}

		return array(
			'contents_count' => ! empty( $package['contents'] ) && is_array( $package['contents'] ) ? count( $package['contents'] ) : 0,
			'rates'          => $rates,
		);
	}

	/**
	 * Summarize order tax items for debug output.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function summarize_order_tax_items( $order ) {
		$items = array();

		foreach ( $order->get_items( 'tax' ) as $item_id => $item ) {
			$items[ $item_id ] = array(
				'rate_id'            => $item->get_rate_id(),
				'label'              => $item->get_label(),
				'tax_total'          => $item->get_tax_total(),
				'shipping_tax_total' => method_exists( $item, 'get_shipping_tax_total' ) ? $item->get_shipping_tax_total() : null,
			);
		}

		return $items;
	}

	/**
	 * Group order item totals by tax rate ID.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_goods_by_tax_rate( $order ) {
		$goods_by_tax_rate = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$line_total = (float) $item->get_total();
			$taxes      = $item->get_taxes();
			$tax_totals = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? array_filter( $taxes['total'] ) : array();

			if ( empty( $tax_totals ) ) {
				$this->add_goods_amount( $goods_by_tax_rate, '0', 0.0, $line_total );
				continue;
			}

			foreach ( $tax_totals as $rate_id => $tax_amount ) {
				$rate = $this->get_tax_rate_decimal_by_id( $rate_id );
				$this->add_goods_amount( $goods_by_tax_rate, $rate_id, $rate, $line_total );
			}
		}

		return $goods_by_tax_rate;
	}

	/**
	 * Group credit note or refund item totals by tax rate ID.
	 *
	 * @param mixed $credit_note Credit note or refund object.
	 * @return array
	 */
	private function get_credit_note_goods_by_tax_rate( $credit_note ) {
		$goods_by_tax_rate = array();

		if ( ! is_object( $credit_note ) || ! method_exists( $credit_note, 'get_items' ) ) {
			return $goods_by_tax_rate;
		}

		foreach ( $credit_note->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_total' ) || ! method_exists( $item, 'get_taxes' ) ) {
				continue;
			}

			$line_total = (float) $item->get_total();
			$taxes      = $item->get_taxes();
			$tax_totals = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? array_filter( $taxes['total'] ) : array();

			if ( empty( $tax_totals ) ) {
				$this->add_credit_note_goods_amount( $goods_by_tax_rate, '0', 0.0, $line_total, 0.0 );
				continue;
			}

			foreach ( $tax_totals as $rate_id => $tax_amount ) {
				$rate = $this->get_tax_rate_decimal_by_id( $rate_id );
				$this->add_credit_note_goods_amount( $goods_by_tax_rate, $rate_id, $rate, $line_total, (float) $tax_amount );
			}
		}

		return $goods_by_tax_rate;
	}

	/**
	 * Add a credit note goods amount to a VAT group.
	 *
	 * @param array      $goods_by_tax_rate Goods buckets.
	 * @param string|int $rate_id Tax rate ID.
	 * @param float      $rate Decimal tax rate.
	 * @param float      $amount Goods amount excluding VAT.
	 * @param float      $goods_vat Goods VAT amount.
	 * @return void
	 */
	private function add_credit_note_goods_amount( array &$goods_by_tax_rate, $rate_id, $rate, $amount, $goods_vat ) {
		$rate_id = (string) $rate_id;

		if ( ! isset( $goods_by_tax_rate[ $rate_id ] ) ) {
			$goods_by_tax_rate[ $rate_id ] = array(
				'amount_ex_vat' => 0.0,
				'rate'          => (float) $rate,
				'goods_vat'     => 0.0,
			);
		}

		$goods_by_tax_rate[ $rate_id ]['amount_ex_vat'] += (float) $amount;
		$goods_by_tax_rate[ $rate_id ]['goods_vat']     += (float) $goods_vat;
	}

	/**
	 * Get decimal tax rate by WooCommerce tax rate ID.
	 *
	 * @param string|int $rate_id Tax rate ID.
	 * @return float
	 */
	private function get_tax_rate_decimal_by_id( $rate_id ) {
		if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', '_get_tax_rate' ) ) {
			return 0.0;
		}

		$tax_rate = WC_Tax::_get_tax_rate( $rate_id );

		if ( empty( $tax_rate['tax_rate'] ) ) {
			return 0.0;
		}

		return max( 0.0, (float) $tax_rate['tax_rate'] / 100 );
	}

	/**
	 * Format a decimal VAT rate for display.
	 *
	 * @param float $rate Decimal VAT rate.
	 * @return string
	 */
	private function format_vat_rate( $rate ) {
		return wc_format_localized_decimal( (float) $rate * 100 ) . '%';
	}

	/**
	 * Format a decimal VAT rate into a stable lookup key.
	 *
	 * @param float $rate Decimal VAT rate.
	 * @return string
	 */
	private function format_tax_rate_key( $rate ) {
		return number_format( (float) $rate, 6, '.', '' );
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
