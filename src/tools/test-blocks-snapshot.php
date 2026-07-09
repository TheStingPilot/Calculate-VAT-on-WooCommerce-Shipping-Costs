<?php
/**
 * Regression test for WooCommerce Blocks snapshots when the REST cart is empty.
 *
 * Run from the plugin root:
 * php src/tools/test-blocks-snapshot.php
 *
 * @package WCProRataShippingVAT
 */

define( 'WCPRSV_ALLOW_STANDALONE', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return 2;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! class_exists( 'WC_Tax' ) ) {
	class WC_Tax {
		public static function _get_tax_rate( $rate_id ) {
			return array(
				'tax_rate' => '1' === (string) $rate_id ? 21 : 9,
			);
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private $total;
		private $tax_items;

		public function __construct( $total, array $tax_items ) {
			$this->total     = $total;
			$this->tax_items = $tax_items;
		}

		public function get_total() {
			return $this->total;
		}

		public function get_items( $type = '' ) {
			return 'tax' === $type ? $this->tax_items : array();
		}
	}
}

if ( ! class_exists( 'WCPRSV_Test_Tax_Item' ) ) {
	class WCPRSV_Test_Tax_Item {
		private $rate_id;
		private $tax_total;

		public function __construct( $rate_id, $tax_total ) {
			$this->rate_id   = $rate_id;
			$this->tax_total = $tax_total;
		}

		public function get_rate_id() {
			return $this->rate_id;
		}

		public function get_tax_total() {
			return $this->tax_total;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-wcprsv-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-wcprsv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wcprsv-plugin.php';

echo 'Running Blocks snapshot tests...' . PHP_EOL;

$breakdown = array(
	'shipping_including_vat' => 6.95,
	'shipping_excluding_vat' => 5.74,
	'taxes'                  => array(
		'1' => 1.21,
	),
	'lines'                  => array(
		'1' => array(
			'goods_amount_ex_vat'       => 9.50,
			'vat_rate'                  => 0.21,
			'share'                     => 1,
			'shipping_excluding_vat'    => 5.74,
			'shipping_vat'              => 1.21,
			'shipping_including_vat'    => 6.95,
			'unrounded_excluding_vat'   => 5.74380165,
			'unrounded_vat'             => 1.20619835,
			'unrounded_including_vat'   => 6.95,
		),
	),
);

$reflection = new ReflectionClass( 'WCPRSV_Plugin' );
$plugin = $reflection->newInstanceWithoutConstructor();

$calculator_property = $reflection->getProperty( 'calculator' );
$calculator_property->setAccessible( true );
$calculator_property->setValue( $plugin, new WCPRSV_Calculator() );

$enrich_method = $reflection->getMethod( 'add_breakdown_to_store_api_rate' );
$enrich_method->setAccessible( true );

$rate_data = array(
	'rate_id' => 'flat_rate:1',
	'selected' => true,
	'price'   => '574',
	'taxes'   => array(
		array(
			'price' => '121',
			'rate'  => '21',
		),
	),
);

$enrich_method->invokeArgs( $plugin, array( &$rate_data, $breakdown ) );

if ( empty( $rate_data['_wcprsv_breakdown']['lines'] ) || empty( $rate_data['meta_data'][0]['value'] ) ) {
	echo json_encode( $rate_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: Store API rate was not enriched with breakdown metadata.' . PHP_EOL );
	exit( 1 );
}

$snapshot = array(
	'totals'        => array(
		'total_shipping'       => '574',
		'total_shipping_tax'   => '121',
		'currency_minor_unit'  => 2,
		'tax_lines'            => array(
			array(
				'name'  => '21% VAT Netherlands',
				'price' => '321',
				'rate'  => '21',
			),
		),
	),
	'shippingRates' => array(
		array(
			'package_id' => 0,
			'shipping_rates' => array(
				$rate_data,
			),
		),
	),
	'cartData'      => array(
		'items'  => array(
			array(
				'totals' => array(
					'line_subtotal'     => '950',
					'line_subtotal_tax' => '200',
				),
			),
		),
		'totals' => array(
			'currency_minor_unit' => 2,
		),
	),
);

$method = $reflection->getMethod( 'calculate_breakdown_from_blocks_snapshot' );
$method->setAccessible( true );

$display_lines_method = $reflection->getMethod( 'get_display_lines' );
$display_lines_method->setAccessible( true );

$credit_note_method = $reflection->getMethod( 'get_credit_note_breakdown' );
$credit_note_method->setAccessible( true );

$free_shipping_order_breakdown = array(
	'shipping_including_vat' => 0.0,
	'shipping_excluding_vat' => 0.0,
	'taxes'                  => array(
		'10' => 0.0,
	),
	'lines'                  => array(
		'10' => array(
			'goods_amount_ex_vat'    => 63.21,
			'vat_rate'               => 0.09,
			'share'                  => 1,
			'shipping_excluding_vat' => 0.0,
			'shipping_vat'           => 0.0,
			'shipping_including_vat' => 0.0,
		),
	),
);
$order_with_one_cent_display_delta = new WC_Order(
	68.90,
	array(
		new WCPRSV_Test_Tax_Item( '10', 5.68 ),
	)
);
$display_lines = $display_lines_method->invoke(
	$plugin,
	$free_shipping_order_breakdown['lines'],
	$free_shipping_order_breakdown,
	$order_with_one_cent_display_delta
);
$display_line = $display_lines['10'] ?? array();

if (
	63.22 !== ( $display_line['goods_amount_ex_vat'] ?? null ) ||
	5.68 !== ( $display_line['goods_vat'] ?? null ) ||
	68.90 !== ( $display_line['including_vat'] ?? null )
) {
	echo json_encode( $display_lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: order/PDF display lines should reconcile to the WooCommerce order total without changing stored VAT.' . PHP_EOL );
	exit( 1 );
}

$result = $method->invoke( $plugin, $snapshot );
$expected = $breakdown;
$expected['lines']['1']['goods_vat'] = 2.00;

if ( $expected !== $result ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: paid shipping breakdown was not recovered.' . PHP_EOL );
	exit( 1 );
}

$int_snapshot = $snapshot;
$int_snapshot['totals']['total_shipping']     = 574;
$int_snapshot['totals']['total_shipping_tax'] = 121;
$int_snapshot['totals']['tax_lines'][0]['price'] = 321;
$int_snapshot['shippingRates'][0]['shipping_rates'][0]['price'] = 574;
$int_snapshot['shippingRates'][0]['shipping_rates'][0]['taxes'][0]['price'] = 121;

$result = $method->invoke( $plugin, $int_snapshot );

if ( $expected !== $result ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: integer Store API amounts were not parsed as minor units.' . PHP_EOL );
	exit( 1 );
}

$metadata_lost_snapshot = $snapshot;
unset( $metadata_lost_snapshot['shippingRates'][0]['shipping_rates'][0]['_wcprsv_breakdown'] );
$metadata_lost_snapshot['shippingRates'][0]['shipping_rates'][0]['meta_data'] = array();

$result = $method->invoke( $plugin, $metadata_lost_snapshot );
$rebuilt_line = ! empty( $result['lines'] ) ? reset( $result['lines'] ) : array();

if (
	6.95 !== ( $result['shipping_including_vat'] ?? null ) ||
	5.74 !== ( $result['shipping_excluding_vat'] ?? null ) ||
	9.50 !== ( $rebuilt_line['goods_amount_ex_vat'] ?? null ) ||
	1.21 !== ( $rebuilt_line['shipping_vat'] ?? null ) ||
	2.00 !== ( $rebuilt_line['goods_vat'] ?? null )
) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: paid shipping should be reconstructed when Blocks metadata is missing.' . PHP_EOL );
	exit( 1 );
}

$snapshot['shippingRates'][0]['shipping_rates'] = array(
	array_merge(
		$rate_data,
		array(
			'selected' => false,
		)
	),
	array(
		'rate_id'   => 'local_pickup:1',
		'selected'  => true,
		'price'     => '0',
		'taxes'     => array(),
		'meta_data' => array(),
	),
);
$snapshot['totals']['total_shipping'] = '0';
$snapshot['totals']['total_shipping_tax'] = '0';
$snapshot['totals']['tax_lines'][0]['price'] = '200';
$result = $method->invoke( $plugin, $snapshot );
$free_line = ! empty( $result['lines'] ) ? reset( $result['lines'] ) : array();

if (
	0.0 !== ( $result['shipping_including_vat'] ?? null ) ||
	0.0 !== ( $result['shipping_excluding_vat'] ?? null ) ||
	9.50 !== ( $free_line['goods_amount_ex_vat'] ?? null ) ||
	0.0 !== ( $free_line['shipping_excluding_vat'] ?? null ) ||
	0.0 !== ( $free_line['shipping_vat'] ?? null ) ||
	2.00 !== ( $free_line['goods_vat'] ?? null )
) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: free shipping should return a zero-shipping VAT specification.' . PHP_EOL );
	exit( 1 );
}

$stale_paid_rate_snapshot = $snapshot;
$stale_paid_rate_snapshot['totals']['total_shipping']     = '0';
$stale_paid_rate_snapshot['totals']['total_shipping_tax'] = '0';
$stale_paid_rate_snapshot['shippingRates'][0]['shipping_rates'] = array(
	array_merge(
		$rate_data,
		array(
			'selected' => true,
		)
	),
);

$result = $method->invoke( $plugin, $stale_paid_rate_snapshot );
$stale_free_line = ! empty( $result['lines'] ) ? reset( $result['lines'] ) : array();

if (
	0.0 !== ( $result['shipping_including_vat'] ?? null ) ||
	0.0 !== ( $stale_free_line['shipping_excluding_vat'] ?? null ) ||
	0.0 !== ( $stale_free_line['shipping_vat'] ?? null ) ||
	2.00 !== ( $stale_free_line['goods_vat'] ?? null )
) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: zero Blocks shipping totals must ignore stale paid rate metadata.' . PHP_EOL );
	exit( 1 );
}

$credit_note = new class {
	public function get_items( $type = '' ) {
		return array(
			new class {
				public function get_total() {
					return -9.50;
				}

				public function get_taxes() {
					return array(
						'total' => array(
							'1' => -2.00,
						),
					);
				}
			},
		);
	}

	public function get_shipping_total() {
		return 0.0;
	}

	public function get_shipping_tax() {
		return 0.0;
	}
};

$result = $credit_note_method->invoke( $plugin, $credit_note );
$credit_line = ! empty( $result['lines'] ) ? reset( $result['lines'] ) : array();

if (
	0.0 !== ( $result['shipping_including_vat'] ?? null ) ||
	-9.50 !== ( $credit_line['goods_amount_ex_vat'] ?? null ) ||
	0.0 !== ( $credit_line['shipping_excluding_vat'] ?? null ) ||
	0.0 !== ( $credit_line['shipping_vat'] ?? null ) ||
	-2.00 !== ( $credit_line['goods_vat'] ?? null )
) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	fwrite( STDERR, 'Blocks snapshot test failed: credit note breakdown should preserve negative goods VAT and zero shipping.' . PHP_EOL );
	exit( 1 );
}

echo 'Blocks snapshot tests passed.' . PHP_EOL;
