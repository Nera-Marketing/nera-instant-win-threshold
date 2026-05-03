/**
 * Client-side schedule filter for instant-win prizes + toggle count sync.
 *
 * Intercepts window.fetch for /wp-json/nera/v1/instant-wins/<id>, filters
 * schedule prizes by the visitor's local clock, fixes stats, and rewrites
 * the PHP-rendered toggle (#instant-wins-toggle-btn) so it always matches Vue.
 *
 * Header counts start hidden (nera-iwt-header-counts--pending) until the first
 * successful client-filtered response, or a timeout fallback to server-rendered text.
 *
 * Works with both the plugin template (data-nera-iwt-*) and the theme template
 * (plain spans / text nodes) — no theme edits required.
 */
/* global window, Response, document, neraIwtClient */
( function () {
	'use strict';

	/** @type {string} */
	var PENDING_CLASS = 'nera-iwt-header-counts--pending';

	var neraIwtFallbackTimer = null;

	var neraIwtBootstrapTimer = null;

	/**
	 * Parse schedule_at for local-time comparison (no Z / offset suffix).
	 *
	 * @param {string} raw Raw string from REST (e.g. "2026-05-03T16:20" or "2026-05-03 16:20:00").
	 * @return {number} epoch ms or NaN
	 */
	function neraIwtParseScheduleLocalMs( raw ) {
		if ( ! raw || typeof raw !== 'string' ) {
			return NaN;
		}
		var s = raw.trim();
		if ( s.indexOf( 'T' ) === -1 ) {
			s = s.replace( ' ', 'T' );
		}
		// Y-m-dTH:i → add seconds for engines that are picky
		if ( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test( s ) ) {
			s += ':00';
		}
		return new Date( s ).getTime();
	}

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
				var now = Date.now();

				var filtered = prizes.filter( function ( prize ) {
					if ( prize.rule_type !== 'schedule' || ! prize.schedule_at ) {
						return true;
					}
					var at = neraIwtParseScheduleLocalMs( prize.schedule_at );
					if ( isNaN( at ) ) {
						return true;
					}
					return now >= at;
				} );

				var totalWon = 0;
				var remaining = 0;
				filtered.forEach( function ( p ) {
					var won = parseInt( p.won_count, 10 ) || 0;
					var tot = parseInt( p.total_available, 10 ) || 0;
					totalWon += won;
					remaining += Math.max( 0, tot - won );
				} );

				var newStats = {
					total_available: remaining + totalWon,
					total_won:       totalWon,
				};

				// Always sync toggle to the same numbers Vue will use (fixes PHP vs REST drift).
				requestAnimationFrame( function () {
					neraIwtUpdateToggleCounts( remaining, totalWon );
				} );

				var origStats = body.data.stats || {};
				var statsSame = String( newStats.total_available ) === String( origStats.total_available )
					&& String( newStats.total_won ) === String( origStats.total_won );
				var prizesSame = filtered.length === prizes.length;

				if ( prizesSame && statsSame ) {
					return response;
				}

				var newBody = JSON.parse( JSON.stringify( body ) );
				newBody.data.prizes = filtered;
				newBody.data.stats = newStats;

				return new Response( JSON.stringify( newBody ), {
					status:     response.status,
					statusText: response.statusText,
					headers:    { 'Content-Type': 'application/json' },
				} );

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
