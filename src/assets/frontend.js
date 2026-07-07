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
	var unsubscribeBlocksStore = null;
	var lastBlocksSignature = '';

	function hasClassicBreakdown() {
		var breakdowns = document.querySelectorAll( '.wcprsv-vat-breakdown, [data-wcprsv-breakdown]' );

		for ( var i = 0; i < breakdowns.length; i++ ) {
			if ( ! breakdowns[ i ].closest( '.wcprsv-block-breakdown' ) ) {
				return true;
			}
		}

		return false;
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

	function removeBlockBreakdown() {
		var existing = document.querySelector( '.wcprsv-block-breakdown' );

		if ( existing ) {
			existing.remove();
		}
	}

	function getBlocksSnapshot() {
		if (
			! window.wp ||
			! window.wp.data ||
			! window.wc ||
			! window.wc.wcBlocksData ||
			! window.wc.wcBlocksData.cartStore
		) {
			return null;
		}

		var store = window.wp.data.select( window.wc.wcBlocksData.cartStore );

		if ( ! store ) {
			return null;
		}

		return {
			cartData: typeof store.getCartData === 'function' ? store.getCartData() : null,
			totals: typeof store.getCartTotals === 'function' ? store.getCartTotals() : null,
			shippingRates: typeof store.getShippingRates === 'function' ? store.getShippingRates() : null
		};
	}

	function getBlocksSnapshotSignature( snapshot ) {
		if ( ! snapshot ) {
			return '';
		}

		try {
			return JSON.stringify( {
				items: snapshot.cartData && snapshot.cartData.items ? snapshot.cartData.items.map( function ( item ) {
					return item && item.totals ? item.totals : null;
				} ) : [],
				shippingRates: snapshot.shippingRates,
				totals: snapshot.totals
			} );
		} catch ( error ) {
			return String( Date.now() );
		}
	}

	function refresh() {
		if ( isRefreshing ) {
			return;
		}

		isRefreshing = true;

		var snapshot = getBlocksSnapshot();

		window.fetch(
			window.wcprsvData.endpoint,
			{
				method: snapshot ? 'POST' : 'GET',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.wcprsvData.nonce
				},
				body: snapshot ? JSON.stringify( { blocks_snapshot: snapshot } ) : null
			}
		)
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.debug && window.console ) {
					window.console.groupCollapsed( 'WooCommerce Pro-rata Shipping VAT' );
					window.console.log( data.debug );
					window.console.groupEnd();
				}

				if ( data && data.has_breakdown && data.html ) {
					render( data.html );
				} else {
					removeBlockBreakdown();
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

	function watchBlocksStore() {
		if (
			unsubscribeBlocksStore ||
			! window.wp ||
			! window.wp.data ||
			typeof window.wp.data.subscribe !== 'function' ||
			! window.wc ||
			! window.wc.wcBlocksData ||
			! window.wc.wcBlocksData.cartStore
		) {
			return;
		}

		unsubscribeBlocksStore = window.wp.data.subscribe( function () {
			var snapshot = getBlocksSnapshot();
			var signature = getBlocksSnapshotSignature( snapshot );

			if ( signature && signature !== lastBlocksSignature ) {
				lastBlocksSignature = signature;
				scheduleRefresh();
			}
		}, window.wc.wcBlocksData.cartStore );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		watchBlocksStore();
		scheduleRefresh();
	} );
	document.body.addEventListener( 'wc-blocks_added_to_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_updated_cart_totals', scheduleRefresh );
}() );
