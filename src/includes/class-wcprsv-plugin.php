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
		add_action( 'woocommerce_cart_totals_after_shipping', array( $this, 'render_classic_breakdown' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render_classic_breakdown' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_storefront_endpoint' ) );
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
			$rate->add_meta_data( '_wcprsv_breakdown', $result );

			$rates[ $rate_id ] = $rate;
		}

		return $rates;
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
	 * Enqueue frontend assets for Cart and Checkout Blocks.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( ! ( is_cart() || is_checkout() ) || ! $this->settings->is_enabled() || ! wc_tax_enabled() ) {
			return;
		}

		wp_enqueue_script(
			'wcprsv-frontend',
			plugins_url( 'assets/frontend.js', WCPRSV_FILE ),
			array(),
			WCPRSV_VERSION,
			true
		);

		wp_localize_script(
			'wcprsv-frontend',
			'wcprsvData',
			array(
				'endpoint' => esc_url_raw( rest_url( 'wcprsv/v1/breakdown' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
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
				'methods'             => 'GET',
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
	public function get_breakdown_response() {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		$breakdown = $this->get_selected_shipping_breakdown();

		return rest_ensure_response(
			array(
				'has_breakdown' => ! empty( $breakdown['lines'] ),
				'html'          => ! empty( $breakdown['lines'] ) ? $this->get_breakdown_html( $breakdown ) : '',
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

			$rate      = $package['rates'][ $chosen_rate_id ];
			$breakdown = is_a( $rate, 'WC_Shipping_Rate' ) ? $rate->get_meta( '_wcprsv_breakdown' ) : array();

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
	 * Build the customer-facing breakdown HTML.
	 *
	 * @param array $breakdown Pro-rata breakdown.
	 * @return string
	 */
	private function get_breakdown_html( array $breakdown ) {
		ob_start();
		?>
		<div class="wcprsv-breakdown" data-wcprsv-breakdown="1">
			<strong><?php echo esc_html__( 'BTW-berekening verzendkosten', 'wc-pro-rata-shipping-vat' ); ?></strong>
			<table class="wcprsv-breakdown__table" style="width:100%; margin-top:.5em;">
				<thead>
					<tr>
						<th style="text-align:left;"><?php echo esc_html__( 'BTW', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="text-align:right;"><?php echo esc_html__( 'Goederen ex. BTW', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="text-align:right;"><?php echo esc_html__( 'Verzending ex. BTW', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="text-align:right;"><?php echo esc_html__( 'BTW verzending', 'wc-pro-rata-shipping-vat' ); ?></th>
						<th style="text-align:right;"><?php echo esc_html__( 'Verzending incl. BTW', 'wc-pro-rata-shipping-vat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $breakdown['lines'] as $line ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_vat_rate( $line['vat_rate'] ) ); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $line['goods_amount_ex_vat'] ) ); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $line['shipping_excluding_vat'] ) ); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $line['shipping_vat'] ) ); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $line['shipping_including_vat'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row" colspan="2" style="text-align:left;"><?php echo esc_html__( 'Totaal verzendkosten', 'wc-pro-rata-shipping-vat' ); ?></th>
						<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $breakdown['shipping_excluding_vat'] ) ); ?></td>
						<td style="text-align:right;"><?php echo wp_kses_post( wc_price( array_sum( $breakdown['taxes'] ) ) ); ?></td>
						<td style="text-align:right;"><?php echo wp_kses_post( wc_price( $breakdown['shipping_including_vat'] ) ); ?></td>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
		return ob_get_clean();
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
