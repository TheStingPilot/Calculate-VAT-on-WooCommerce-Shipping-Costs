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
	var domObserver = null;
	var lastBlocksSignature = '';

	function hasClassicBreakdown() {
		var breakdowns = document.querySelectorAll( '.wcprsv-vat-breakdown, [data-wcprsv-breakdown]' );

		for ( var i = 0; i < breakdowns.length; i++ ) {
			if ( isVisible( breakdowns[ i ] ) && ! breakdowns[ i ].closest( '.wcprsv-block-breakdown' ) ) {
				return true;
			}
		}

		return false;
	}

	function isVisible( element ) {
		return !! (
			element &&
			(
				element.offsetWidth ||
				element.offsetHeight ||
				( typeof element.getClientRects === 'function' && element.getClientRects().length )
			)
		);
	}

	function findPlacementInContainer( container ) {
		var shipping = container.querySelector( '.wc-block-components-totals-shipping' );

		if ( isVisible( shipping ) ) {
			return {
				element: shipping,
				position: 'afterend'
			};
		}

		var footer = container.querySelector( '.wc-block-components-totals-footer-item' );

		if ( isVisible( footer ) ) {
			return {
				element: footer,
				position: 'beforebegin'
			};
		}

		var totals = container.querySelectorAll( '.wc-block-components-totals-item, .wc-block-components-totals-wrapper' );

		for ( var i = totals.length - 1; i >= 0; i-- ) {
			if ( isVisible( totals[ i ] ) ) {
				return {
					element: totals[ i ],
					position: 'beforebegin'
				};
			}
		}

		return null;
	}

	function findPlacement() {
		var containers = [
			'.wp-block-woocommerce-checkout-order-summary-block',
			'.wp-block-woocommerce-cart-order-summary-block',
			'.wc-block-checkout__sidebar',
			'.wc-block-cart__sidebar',
			'.wc-block-components-sidebar',
			'.woocommerce-checkout-review-order',
			'.cart_totals'
		];
		var placement;

		for ( var j = 0; j < containers.length; j++ ) {
			var container = document.querySelector( containers[ j ] );

			if ( ! isVisible( container ) ) {
				continue;
			}

			placement = findPlacementInContainer( container );

			if ( placement ) {
				return placement;
			}
		}

		for ( var i = 0; i < targetSelectors.length; i++ ) {
			var target = document.querySelector( targetSelectors[ i ] );

			if ( isVisible( target ) ) {
				return {
					element: target,
					position: target.matches( '.wc-block-components-totals-footer-item' ) ? 'beforebegin' : 'afterend'
				};
			}
		}

		return null;
	}

	function render( html ) {
		if ( hasClassicBreakdown() ) {
			return;
		}

		var placement = findPlacement();

		if ( ! placement ) {
			return;
		}

		var existing = document.querySelector( '.wcprsv-block-breakdown' );

		if ( existing ) {
			existing.remove();
		}

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'wcprsv-block-breakdown';
		wrapper.innerHTML = html;
		placement.element.insertAdjacentElement( placement.position, wrapper );
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

	function watchDomChanges() {
		if ( domObserver || typeof window.MutationObserver !== 'function' ) {
			return;
		}

		domObserver = new window.MutationObserver( function () {
			if ( ! document.querySelector( '.wcprsv-block-breakdown' ) ) {
				scheduleRefresh();
			}
		} );

		domObserver.observe(
			document.body,
			{
				childList: true,
				subtree: true
			}
		);
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
		watchDomChanges();
		scheduleRefresh();
	} );
	window.addEventListener( 'resize', scheduleRefresh );
	window.addEventListener( 'orientationchange', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_added_to_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', scheduleRefresh );
	document.body.addEventListener( 'wc-blocks_updated_cart_totals', scheduleRefresh );
}() );
