<?php
/**
 * Plugin settings.
 *
 * @package WCProRataShippingVAT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds settings to WooCommerce's tax settings screen.
 */
class WCPRSV_Settings {
	const OPTION_ENABLED            = 'wcprsv_enabled';
	const OPTION_REFERENCE_VAT_RATE = 'wcprsv_reference_vat_rate';
	const OPTION_DEBUG              = 'wcprsv_debug';

	/**
	 * Register settings hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_get_settings_tax', array( $this, 'add_tax_settings' ), 20, 2 );
	}

	/**
	 * Whether the plugin should modify shipping taxes.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Reference VAT rate used by existing shipping settings.
	 *
	 * @return float Decimal VAT rate.
	 */
	public function get_reference_vat_rate() {
		$rate = (float) get_option( self::OPTION_REFERENCE_VAT_RATE, '9' );

		if ( $rate < 0 ) {
			$rate = 0;
		}

		return $rate / 100;
	}

	/**
	 * Whether debug output should be emitted.
	 *
	 * @return bool
	 */
	public function is_debug_enabled() {
		return 'yes' === get_option( self::OPTION_DEBUG, 'no' );
	}

	/**
	 * Add settings fields to the WooCommerce tax settings page.
	 *
	 * @param array  $settings Existing settings.
	 * @param string $current_section Current settings section.
	 * @return array
	 */
	public function add_tax_settings( $settings, $current_section ) {
		if ( '' !== $current_section ) {
			return $settings;
		}

		$custom_settings = array(
			array(
				'title' => __( 'Pro-rata shipping VAT', 'wc-pro-rata-shipping-vat' ),
				'type'  => 'title',
				'desc'  => __( 'Distribute VAT on shipping costs proportionally across the VAT rates present in the cart.', 'wc-pro-rata-shipping-vat' ),
				'id'    => 'wcprsv_options',
			),
			array(
				'title'   => __( 'Enable pro-rata shipping VAT', 'wc-pro-rata-shipping-vat' ),
				'id'      => self::OPTION_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
				'desc'    => __( 'Recalculate shipping VAT when the cart contains taxable goods.', 'wc-pro-rata-shipping-vat' ),
			),
			array(
				'title'             => __( 'Reference VAT rate for configured shipping costs', 'wc-pro-rata-shipping-vat' ),
				'id'                => self::OPTION_REFERENCE_VAT_RATE,
				'default'           => '9',
				'type'              => 'number',
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc'              => __( 'Percentage used to convert existing shipping costs from excluding VAT to including VAT. Use 9 when shipping is currently entered as excluding 9% VAT.', 'wc-pro-rata-shipping-vat' ),
				'desc_tip'          => true,
			),
			array(
				'title'   => __( 'Debug', 'wc-pro-rata-shipping-vat' ),
				'id'      => self::OPTION_DEBUG,
				'default' => 'no',
				'type'    => 'checkbox',
				'desc'    => __( 'Log pro-rata shipping VAT calculations to the browser console and WooCommerce logs.', 'wc-pro-rata-shipping-vat' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcprsv_options',
			),
		);

		return array_merge( $settings, $custom_settings );
	}
}
