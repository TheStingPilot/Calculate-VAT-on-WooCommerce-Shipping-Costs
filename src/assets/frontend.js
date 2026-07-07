( function () {
	'use strict';

	if ( ! window.wcprsvData ) {
		return;
	}

	var targetSelectors = [
		'.wc-block-components-totals-shipping',
		'.wc-block-components-totals-footer-item',
		'.wp-block-woocommerce-cart-order-summary-block',
		'.wp-block-woocommerce-checkout-order-summary-block',
		'.cart_totals',
		'#order_review',
		'.woocommerce-checkout-review-order'
	];
	var isRefreshing = false;
	var refreshTimer = null;

	function hasClassicBreakdown() {
		return !! document.querySelector( '.wcprsv-vat-breakdown, [data-wcprsv-breakdown]' );
	}

	function findTarget() {
		for ( var i = 0; i < targetSelectors.length; i++ ) {
			var target = document.querySelector( targetSelectors[ i ] );

			if ( target ) {
				return target;
			}
		}

		return null;
	}

	function render( html ) {
		if ( hasClassicBreakdown() ) {
			return;
		}

		var target = findTarget();

		if ( ! target ) {
			return;
		}

		var existing = document.querySelector( '.wcprsv-block-breakdown' );

		if ( existing ) {
			existing.remove();
		}

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'wcprsv-block-breakdown';
		wrapper.innerHTML = html;
		target.insertAdjacentElement( 'afterend', wrapper );
	}

	function refresh() {
		if ( isRefreshing ) {
			return;
		}

		isRefreshing = true;

		window.fetch(
			window.wcprsvData.endpoint,
			{
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': window.wcprsvData.nonce
				}
			}
		)
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.has_breakdown && data.html ) {
					render( data.html );
				}
			} )
			.catch( function () {} )
			.finally( function () {
				isRefreshing = false;
			} );
	}

	function scheduleRefresh() {
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( refresh, 350 );
	}

	document.addEventListener( 'DOMContentLoaded', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_added_to_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_updated_cart_totals', scheduleRefresh );
}() );
