/**
 * Checkout loading overlay — inject green bouncing dots + status message.
 *
 * WooCommerce's classic checkout blocks the form with jQuery blockUI during the
 * place-order AJAX request (?wc-ajax=checkout). The overlay element it inserts
 * (.blockUI.blockOverlay) is empty, so we inject our loader markup into it. A
 * MutationObserver re-injects whenever WooCommerce re-blocks the form (the overlay
 * is destroyed and recreated each time), which also covers payment-method changes
 * and update_checkout calls.
 */
( function () {
	'use strict';

	var cfg = window.neraIwtCheckout || {};
	var MESSAGE = cfg.message || 'Processing your order…';

	/**
	 * Build the loader element.
	 *
	 * @return {HTMLElement}
	 */
	function buildLoader() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'nera-iwt-checkout-loader';
		wrap.setAttribute( 'role', 'status' );
		wrap.setAttribute( 'aria-live', 'polite' );

		var dots = document.createElement( 'div' );
		dots.className = 'nera-iwt-dots';
		dots.appendChild( document.createElement( 'span' ) );
		dots.appendChild( document.createElement( 'span' ) );
		dots.appendChild( document.createElement( 'span' ) );

		var text = document.createElement( 'p' );
		text.className = 'nera-iwt-loader-text';
		text.textContent = MESSAGE;

		wrap.appendChild( dots );
		wrap.appendChild( text );
		return wrap;
	}

	/**
	 * Inject the loader into a blockUI overlay if not already present.
	 *
	 * @param {HTMLElement} overlay The .blockUI.blockOverlay element.
	 */
	function injectInto( overlay ) {
		if ( ! overlay || overlay.querySelector( '.nera-iwt-checkout-loader' ) ) {
			return;
		}
		// Only decorate overlays that belong to the checkout form / order review.
		var blocked = overlay.parentNode;
		if (
			blocked &&
			typeof blocked.closest === 'function' &&
			! blocked.closest( 'form.checkout' ) &&
			! blocked.closest( '.woocommerce-checkout-review-order' )
		) {
			return;
		}
		overlay.appendChild( buildLoader() );
	}

	/**
	 * Scan a node (and descendants) for blockUI overlays to decorate.
	 *
	 * @param {Node} node
	 */
	function scan( node ) {
		if ( ! node || 1 !== node.nodeType ) {
			return;
		}
		if ( node.classList && node.classList.contains( 'blockOverlay' ) ) {
			injectInto( node );
			return;
		}
		if ( typeof node.querySelectorAll === 'function' ) {
			var overlays = node.querySelectorAll( '.blockUI.blockOverlay' );
			for ( var i = 0; i < overlays.length; i++ ) {
				injectInto( overlays[ i ] );
			}
		}
	}

	function start() {
		// Decorate any overlay already present.
		scan( document.body );

		// Watch for overlays inserted by blockUI on each checkout block.
		var observer = new MutationObserver( function ( mutations ) {
			for ( var m = 0; m < mutations.length; m++ ) {
				var added = mutations[ m ].addedNodes;
				for ( var n = 0; n < added.length; n++ ) {
					scan( added[ n ] );
				}
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
