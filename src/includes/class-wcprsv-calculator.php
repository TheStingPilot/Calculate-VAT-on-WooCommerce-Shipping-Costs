<?php
/**
 * Pro-rata shipping VAT calculator.
 *
 * @package WCProRataShippingVAT
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WCPRSV_ALLOW_STANDALONE' ) ) {
	exit;
}

/**
 * Calculates shipping VAT by distributing an inclusive shipping total across VAT
 * rates in proportion to the goods value per rate.
 */
class WCPRSV_Calculator {
	/**
	 * Calculate pro-rata shipping amounts.
	 *
	 * @param float $shipping_excluding_reference_vat Shipping amount as configured, excluding the reference VAT rate.
	 * @param float $reference_vat_rate Reference VAT rate as decimal, e.g. 0.09.
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate ID. Each item requires amount_ex_vat and rate.
	 * @param int   $price_decimals Number of decimals to use for monetary output.
	 * @return array
	 */
	public function calculate( $shipping_excluding_reference_vat, $reference_vat_rate, array $goods_by_tax_rate, $price_decimals = 2 ) {
		$shipping_excluding_reference_vat = max( 0.0, (float) $shipping_excluding_reference_vat );
		$reference_vat_rate              = max( 0.0, (float) $reference_vat_rate );
		$price_decimals                  = max( 0, (int) $price_decimals );

		$shipping_including_vat = $shipping_excluding_reference_vat * ( 1 + $reference_vat_rate );

		return $this->calculate_from_including_vat( $shipping_including_vat, $goods_by_tax_rate, $price_decimals );
	}

	/**
	 * Calculate pro-rata shipping amounts from an inclusive shipping amount.
	 *
	 * @param float $shipping_including_vat Shipping amount including VAT.
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate ID. Each item requires amount_ex_vat and rate.
	 * @param int   $price_decimals Number of decimals to use for monetary output.
	 * @return array
	 */
	public function calculate_from_including_vat( $shipping_including_vat, array $goods_by_tax_rate, $price_decimals = 2 ) {
		$shipping_including_vat = max( 0.0, (float) $shipping_including_vat );
		$price_decimals        = max( 0, (int) $price_decimals );
		$total_goods           = $this->sum_goods( $goods_by_tax_rate );

		if ( $shipping_including_vat <= 0 || $total_goods <= 0 ) {
			return array(
				'shipping_including_vat' => round( $shipping_including_vat, $price_decimals ),
				'shipping_excluding_vat' => round( $shipping_including_vat, $price_decimals ),
				'taxes'                  => array(),
				'lines'                  => array(),
			);
		}

		$lines     = array();
		$taxes     = array();
		$total_ex  = 0.0;
		$total_tax = 0.0;

		foreach ( $goods_by_tax_rate as $tax_rate_id => $data ) {
			$goods_amount = max( 0.0, (float) ( $data['amount_ex_vat'] ?? 0 ) );
			$vat_rate     = max( 0.0, (float) ( $data['rate'] ?? 0 ) );

			if ( $goods_amount <= 0 ) {
				continue;
			}

			$share         = $goods_amount / $total_goods;
			$line_incl     = $shipping_including_vat * $share;
			$line_ex       = $vat_rate > 0 ? $line_incl / ( 1 + $vat_rate ) : $line_incl;
			$line_tax      = $line_incl - $line_ex;
			$tax_rate_id   = (string) $tax_rate_id;
			$rounded_tax   = round( $line_tax, $price_decimals );
			$rounded_ex    = round( $line_ex, $price_decimals );
			$rounded_incl  = round( $line_incl, $price_decimals );
			$taxes[ $tax_rate_id ] = $rounded_tax;

			$lines[ $tax_rate_id ] = array(
				'goods_amount_ex_vat'       => $goods_amount,
				'vat_rate'                  => $vat_rate,
				'share'                     => $share,
				'shipping_excluding_vat'    => $rounded_ex,
				'shipping_vat'              => $rounded_tax,
				'shipping_including_vat'    => $rounded_incl,
				'unrounded_excluding_vat'   => $line_ex,
				'unrounded_vat'             => $line_tax,
				'unrounded_including_vat'   => $line_incl,
			);

			$total_ex  += $rounded_ex;
			$total_tax += $rounded_tax;
		}

		$target_including = round( $shipping_including_vat, $price_decimals );
		$this->apply_rounding_delta( $lines, $taxes, $target_including, $price_decimals );

		$total_ex  = 0.0;
		$total_tax = 0.0;

		foreach ( $lines as $line ) {
			$total_ex  += $line['shipping_excluding_vat'];
			$total_tax += $line['shipping_vat'];
		}

		return array(
			'shipping_including_vat' => $target_including,
			'shipping_excluding_vat' => round( $total_ex, $price_decimals ),
			'taxes'                  => $taxes,
			'lines'                  => $lines,
		);
	}

	/**
	 * Sum goods values across tax groups.
	 *
	 * @param array $goods_by_tax_rate Goods totals keyed by tax rate ID.
	 * @return float
	 */
	private function sum_goods( array $goods_by_tax_rate ) {
		$total = 0.0;

		foreach ( $goods_by_tax_rate as $data ) {
			$total += max( 0.0, (float) ( $data['amount_ex_vat'] ?? 0 ) );
		}

		return $total;
	}

	/**
	 * Adjust the largest tax line for cent-level rounding differences.
	 *
	 * @param array $lines Lines keyed by tax rate ID.
	 * @param array $taxes Tax amounts keyed by tax rate ID.
	 * @param float $target_including Target inclusive shipping amount.
	 * @param int   $price_decimals Number of decimals to round to.
	 * @return void
	 */
	private function apply_rounding_delta( array &$lines, array &$taxes, $target_including, $price_decimals ) {
		if ( empty( $lines ) ) {
			return;
		}

		$current_including = 0.0;
		$largest_key       = null;
		$largest_amount    = -1.0;

		foreach ( $lines as $key => $line ) {
			$current_including += $line['shipping_excluding_vat'] + $line['shipping_vat'];

			if ( $line['goods_amount_ex_vat'] > $largest_amount ) {
				$largest_key    = $key;
				$largest_amount = $line['goods_amount_ex_vat'];
			}
		}

		$delta = round( $target_including - $current_including, $price_decimals );

		if ( 0.0 === $delta || null === $largest_key ) {
			return;
		}

		$lines[ $largest_key ]['shipping_vat']           = round( $lines[ $largest_key ]['shipping_vat'] + $delta, $price_decimals );
		$lines[ $largest_key ]['shipping_including_vat'] = round( $lines[ $largest_key ]['shipping_excluding_vat'] + $lines[ $largest_key ]['shipping_vat'], $price_decimals );
		$taxes[ $largest_key ]                           = $lines[ $largest_key ]['shipping_vat'];
	}
}
