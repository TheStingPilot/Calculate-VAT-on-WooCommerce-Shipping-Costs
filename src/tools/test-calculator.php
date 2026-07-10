<?php
/**
 * Minimal calculator smoke test based on the supplied spreadsheet example.
 *
 * Run from the plugin root:
 * php tools/test-calculator.php
 *
 * @package WCProRataShippingVAT
 */

define( 'WCPRSV_ALLOW_STANDALONE', true );

require_once dirname( __DIR__ ) . '/includes/class-wcprsv-calculator.php';

$calculator = new WCPRSV_Calculator();

$cases = array(
	'inclusive mixed spreadsheet case' => array(
		'mode'     => 'including_reference',
		'goods'    => array(
			'0'  => array(
				'amount_ex_vat' => 0.0,
				'rate'          => 0.0,
			),
			'9'  => array(
				'amount_ex_vat' => 7.48,
				'rate'          => 0.09,
			),
			'21' => array(
				'amount_ex_vat' => 24.75,
				'rate'          => 0.21,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 6.95,
			'shipping_excluding_vat' => 5.89,
			'taxes'                  => array(
				'9'  => 0.13,
				'21' => 0.93,
			),
		),
	),
	'inclusive all 21 percent'         => array(
		'mode'     => 'including_reference',
		'goods'    => array(
			'21' => array(
				'amount_ex_vat' => 100.0,
				'rate'          => 0.21,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 6.95,
			'shipping_excluding_vat' => 5.75,
			'taxes'                  => array(
				'21' => 1.20,
			),
		),
	),
	'inclusive all 0 percent'          => array(
		'mode'     => 'including_reference',
		'goods'    => array(
			'0' => array(
				'amount_ex_vat' => 100.0,
				'rate'          => 0.0,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 6.95,
			'shipping_excluding_vat' => 6.95,
			'taxes'                  => array(
				'0' => 0.0,
			),
		),
	),
	'exclusive mixed spreadsheet case' => array(
		'mode'     => 'excluding',
		'goods'    => array(
			'0'  => array(
				'amount_ex_vat' => 0.0,
				'rate'          => 0.0,
			),
			'9'  => array(
				'amount_ex_vat' => 45.79,
				'rate'          => 0.09,
			),
			'21' => array(
				'amount_ex_vat' => 7.69,
				'rate'          => 0.21,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 6.59,
			'shipping_excluding_vat' => 5.95,
			'taxes'                  => array(
				'9'  => 0.46,
				'21' => 0.18,
			),
		),
	),
	'exclusive all 9 percent'          => array(
		'mode'     => 'excluding',
		'goods'    => array(
			'9' => array(
				'amount_ex_vat' => 100.0,
				'rate'          => 0.09,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 6.49,
			'shipping_excluding_vat' => 5.95,
			'taxes'                  => array(
				'9' => 0.54,
			),
		),
	),
	'exclusive all 21 percent'         => array(
		'mode'     => 'excluding',
		'goods'    => array(
			'21' => array(
				'amount_ex_vat' => 100.0,
				'rate'          => 0.21,
			),
		),
		'expect'   => array(
			'shipping_including_vat' => 7.20,
			'shipping_excluding_vat' => 5.95,
			'taxes'                  => array(
				'21' => 1.25,
			),
		),
	),
);

foreach ( $cases as $name => $case ) {
	$result = 'excluding' === $case['mode']
		? $calculator->calculate_from_excluding_vat( 5.95, $case['goods'], 2 )
		: $calculator->calculate( 6.38, 0.09, $case['goods'], 2 );
	$actual = array(
		'shipping_including_vat' => $result['shipping_including_vat'],
		'shipping_excluding_vat' => $result['shipping_excluding_vat'],
		'taxes'                  => $result['taxes'],
	);

	if ( $case['expect'] !== $actual ) {
		echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
		fwrite( STDERR, 'Calculator smoke test failed: ' . $name . PHP_EOL );
		exit( 1 );
	}
}

echo 'Calculator smoke tests passed.' . PHP_EOL;
