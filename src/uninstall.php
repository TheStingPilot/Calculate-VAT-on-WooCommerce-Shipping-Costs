<?php
/**
 * Remove plugin options on uninstall.
 *
 * @package WCProRataShippingVAT
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcprsv_enabled' );
delete_option( 'wcprsv_reference_vat_rate' );
delete_option( 'wcprsv_debug' );
