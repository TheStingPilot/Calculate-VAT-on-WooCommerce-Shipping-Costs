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
	const OPTION_WPML_SOURCE_LANGUAGE = 'wcprsv_wpml_source_language';

	/**
	 * Register settings hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_get_settings_tax', array( $this, 'add_tax_settings' ), 20, 2 );
		add_action( 'admin_init', array( $this, 'force_wpml_source_language' ), 5 );
		add_action( 'woocommerce_update_options_tax', array( $this, 'force_wpml_source_language' ), 20 );
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
	 * Source language used for WPML-aware plugin behavior.
	 *
	 * @return string
	 */
	public function get_wpml_source_language() {
		$language = get_option( self::OPTION_WPML_SOURCE_LANGUAGE, 'en' );

		if ( 'nl' === $language ) {
			$language = 'en';
		}

		$language = apply_filters( 'wcprsv_wpml_source_language', $language );

		return $language ? (string) $language : 'en';
	}

	/**
	 * Current frontend language, falling back to the source language.
	 *
	 * @return string
	 */
	public function get_current_language() {
		if ( has_filter( 'wpml_current_language' ) ) {
			$language = apply_filters( 'wpml_current_language', null );

			if ( $language ) {
				return (string) $language;
			}
		}

		return $this->get_wpml_source_language();
	}

	/**
	 * Keep English as the source language for WPML String Translation.
	 *
	 * @return void
	 */
	public function force_wpml_source_language() {
		update_option( self::OPTION_WPML_SOURCE_LANGUAGE, 'en', false );
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
				'title' => __( 'WPML source language', 'wc-pro-rata-shipping-vat' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: language code. */
					__( 'English (%s) is used as the source language for plugin behavior when WPML is active.', 'wc-pro-rata-shipping-vat' ),
					'en'
				),
				'id'    => 'wcprsv_wpml_options',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcprsv_wpml_options',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcprsv_options',
			),
		);

		return array_merge( $settings, $custom_settings );
	}
}
