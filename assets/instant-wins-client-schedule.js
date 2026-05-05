/**
 * Instant-win REST fetch hook: sync toggle/header counts with the API response.
 *
 * Intercepts window.fetch for /wp-json/nera/v1/instant-wins/<id> so the collapsible
 * header stays aligned with the prize list returned by the plugin (full CMS pool).
 * Schedule / ticket-% presentation is handled by the theme Vue layer using rule_type,
 * schedule_*, and ticket_pct on each prize row.
 *
 * Header counts start hidden (nera-iwt-header-counts--pending) until the first
 * successful response, or a timeout fallback to server-rendered text.
 */
/* global window, Response, document, neraIwtClient */
( function () {
	'use strict';

	/** @type {string} */
	var PENDING_CLASS = 'nera-iwt-header-counts--pending';

	var neraIwtFallbackTimer = null;

	var neraIwtBootstrapTimer = null;

	/**
	 * @param {HTMLElement|null} btn Optional toggle button.
	 */
	function neraIwtRevealToggleCounts( btn ) {
		if ( ! btn ) {
			btn = document.getElementById( 'instant-wins-toggle-btn' );
		}
		if ( ! btn ) {
			return;
		}
		btn.classList.remove( PENDING_CLASS );
		btn.setAttribute( 'aria-busy', 'false' );
		if ( neraIwtFallbackTimer ) {
			clearTimeout( neraIwtFallbackTimer );
			neraIwtFallbackTimer = null;
		}
	}

	/**
	 * @param {number} available Remaining (not won) slots after filter.
	 * @param {number} won       Won slots after filter.
	 */
	function neraIwtUpdateToggleCounts( available, won ) {
		var total = available + won;
		var btn = document.getElementById( 'instant-wins-toggle-btn' );
		if ( ! btn ) {
			return;
		}

		// Total line — plugin: [data-nera-iwt-total], theme: .text-left p span.font-semibold.text-warning
		var totalEl = btn.querySelector( '[data-nera-iwt-total]' )
			|| btn.querySelector( '.text-left p span.font-semibold.text-warning' );
		if ( totalEl ) {
			totalEl.textContent = String( total );
		}

		// Available badge number — plugin: [data-nera-iwt-available-count]
		var availCountEl = btn.querySelector( '[data-nera-iwt-available-count]' );
		if ( availCountEl ) {
			availCountEl.textContent = String( available );
		} else {
			neraIwtUpdateThemeStyleAvailableBadge( btn, available );
		}

		var availBadge = btn.querySelector( '[data-nera-iwt-available-badge]' )
			|| btn.querySelector( 'span.inline-flex.items-center.gap-1\\.5.text-success' )
			|| btn.querySelector( 'span.text-success.rounded-full.text-xs.font-semibold' );
		if ( availBadge ) {
			availBadge.style.display = available > 0 ? '' : 'none';
		}

		neraIwtRevealToggleCounts( btn );
	}

	/**
	 * Theme template: number is plain text after the ping icon span.
	 *
	 * @param {HTMLElement} btn       Toggle button.
	 * @param {number}      available New available count.
	 */
	function neraIwtUpdateThemeStyleAvailableBadge( btn, available ) {
		var badge = btn.querySelector( 'span.text-success.rounded-full.text-xs.font-semibold' );
		if ( ! badge ) {
			return;
		}
		var icon = badge.children[0];
		if ( ! icon || icon.nodeType !== 1 ) {
			return;
		}
		// Capture translated suffix (everything after the leading digits), e.g. " Available"
		var suffix = '';
		var n = icon.nextSibling;
		while ( n ) {
			if ( n.nodeType === 3 ) {
				suffix += n.textContent;
			}
			n = n.nextSibling;
		}
		var m = suffix.match( /^\s*\d+\s*(.*)$/ );
		suffix = m && m[1] ? ( ' ' + m[1] ) : ' Available';

		// Remove old text nodes after icon
		n = icon.nextSibling;
		while ( n ) {
			var nx = n.nextSibling;
			if ( n.nodeType === 3 ) {
				badge.removeChild( n );
			}
			n = nx;
		}
		badge.appendChild( document.createTextNode( ' ' + String( available ) + suffix ) );
	}

	/**
	 * REST URL for instant-wins (supports subdirectory installs via wp_localize_script).
	 *
	 * @param {string|number} productId Product ID.
	 * @return {string}
	 */
	function neraIwtInstantWinsRestUrl( productId ) {
		var base = ( typeof neraIwtClient !== 'undefined' && neraIwtClient && neraIwtClient.restBase )
			? String( neraIwtClient.restBase )
			: '/wp-json/nera/v1/instant-wins/';
		if ( base.indexOf( 'http' ) !== 0 && typeof window !== 'undefined' && window.location && window.location.origin ) {
			// Relative path: prefix with origin so fetch is well-defined.
			if ( base.charAt( 0 ) === '/' ) {
				base = window.location.origin + base;
			}
		}
		return base + String( productId );
	}

	/**
	 * Mark counts as loading; show after first filtered REST response or fallback.
	 */
	function neraIwtMarkTogglePending() {
		var btn = document.getElementById( 'instant-wins-toggle-btn' );
		var root = document.getElementById( 'instant-wins-root' );
		if ( ! btn || ! root ) {
			return;
		}
		btn.classList.add( PENDING_CLASS );
		btn.setAttribute( 'aria-busy', 'true' );

		// If the API never returns, show server-rendered numbers (avoids empty header).
		if ( neraIwtFallbackTimer ) {
			clearTimeout( neraIwtFallbackTimer );
		}
		neraIwtFallbackTimer = setTimeout( function () {
			neraIwtRevealToggleCounts( btn );
		}, 10000 );
	}

	/**
	 * Fire one instant-wins request so counts resolve using the visitor's timezone
	 * before or alongside the Vue mount fetch (patched above).
	 */
	function neraIwtBootstrapInstantWinsFetch() {
		var root = document.getElementById( 'instant-wins-root' );
		if ( ! root || ! root.getAttribute ) {
			return;
		}
		var id = root.getAttribute( 'data-product-id' );
		if ( ! id ) {
			return;
		}
		var url = neraIwtInstantWinsRestUrl( id );
		if ( typeof window.fetch !== 'function' ) {
			return;
		}
		window.fetch( url, { credentials: 'same-origin' } ).catch( function () {
			// neraIwtUpdateToggleCounts not called; fallback timer will reveal PHP text.
		} );
	}

	function neraIwtOnDomReady() {
		neraIwtMarkTogglePending();
		if ( neraIwtBootstrapTimer ) {
			clearTimeout( neraIwtBootstrapTimer );
		}
		// Defer one tick so layout and inline scripts are settled, then request REST.
		neraIwtBootstrapTimer = setTimeout( function () {
			neraIwtBootstrapInstantWinsFetch();
		}, 0 );
	}

	var _fetch = window.fetch;

	if ( typeof _fetch !== 'function' ) {
		return;
	}

	var INSTANT_WINS_PATTERN = /\/nera\/v1\/instant-wins\/\d+/;

	window.fetch = function ( resource, init ) {
		var promise = _fetch.apply( window, arguments );

		var url = typeof resource === 'string'
			? resource
			: ( resource && typeof resource.url === 'string' ? resource.url : '' );

		if ( ! INSTANT_WINS_PATTERN.test( url ) ) {
			return promise;
		}

		return promise.then( function ( response ) {
			if ( ! response.ok ) {
				requestAnimationFrame( function () {
					neraIwtRevealToggleCounts( null );
				} );
				return response;
			}

			return response.clone().json().then( function ( body ) {
				if ( ! body || ! body.data ) {
					requestAnimationFrame( function () {
						neraIwtRevealToggleCounts( null );
					} );
					return response;
				}

				var prizes = Array.isArray( body.data.prizes ) ? body.data.prizes : [];
				var totalWon = 0;
				var remaining = 0;
				prizes.forEach( function ( p ) {
					var won = parseInt( p.won_count, 10 ) || 0;
					var tot = parseInt( p.total_available, 10 ) || 0;
					totalWon += won;
					remaining += Math.max( 0, tot - won );
				} );

				requestAnimationFrame( function () {
					neraIwtUpdateToggleCounts( remaining, totalWon );
				} );

				return response;

			} ).catch( function () {
				requestAnimationFrame( function () {
					neraIwtRevealToggleCounts( null );
				} );
				return response;
			} );
		} );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', neraIwtOnDomReady );
	} else {
		neraIwtOnDomReady();
	}
}() );
