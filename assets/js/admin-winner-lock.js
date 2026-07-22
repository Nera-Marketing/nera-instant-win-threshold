/**
 * Lock instant-win rows that have been awarded to a winner.
 *
 * The list of won rule IDs is provided server-side in `neraIwtLock.wonRuleIds`.
 * For each matching row we disable every field, hide the remove-"x", neutralise
 * the image upload/remove buttons and select2 widgets, and show a lock badge.
 *
 * The instant-win table is re-rendered via AJAX (save / delete / pagination), so
 * we re-apply the lock after every AJAX request as well as on initial load. All
 * work is idempotent, guarded by a per-row data flag.
 *
 * @package Nera_Instant_Win_Threshold
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.neraIwtLock === 'undefined' ) {
		return;
	}

	var wonIds = ( neraIwtLock.wonRuleIds || [] ).map( String );
	var lockedTitle = neraIwtLock.lockedTitle || 'Locked: awarded to a winner.';

	if ( ! wonIds.length ) {
		return;
	}

	// One-time style for the locked row + badge.
	function injectStyle() {
		if ( document.getElementById( 'nera-iwt-lock-style' ) ) {
			return;
		}
		var css =
			'.nera-iwt-locked-row td{opacity:.75;}' +
			'.nera-iwt-locked-row .select2-container,' +
			'.nera-iwt-locked-row .lty-select-image,' +
			'.nera-iwt-locked-row .lty-remove-image{pointer-events:none;opacity:.5;}' +
			'.nera-iwt-lock-badge{display:inline-block;color:#8a6d3b;}' +
			'.nera-iwt-lock-badge .dashicons{width:auto;height:auto;font-size:18px;line-height:1;vertical-align:middle;}';
		var style = document.createElement( 'style' );
		style.id = 'nera-iwt-lock-style';
		style.textContent = css;
		document.head.appendChild( style );
	}

	// Read a row's rule ID from the remove-"x" span's data attribute.
	function ruleIdOfRow( $row ) {
		var $x = $row.find( '.lty-remove-instant-winner-rule' ).first();
		if ( $x.length ) {
			return String( $x.attr( 'data-instant_winner_rule_id' ) || '' );
		}
		return '';
	}

	function lockRow( $row ) {
		if ( $row.data( 'neraIwtLocked' ) ) {
			return;
		}
		$row.data( 'neraIwtLocked', true ).addClass( 'nera-iwt-locked-row' );

		// Disable every form control in the row (also excludes them from the
		// save payload, so their stored values are never resubmitted).
		$row.find( 'input, select, textarea' ).each( function () {
			var $el = $( this );
			$el.prop( 'disabled', true );
			// Reflect disabled state in any select2/selectWoo widget.
			if ( $el.is( 'select' ) && $el.hasClass( 'select2-hidden-accessible' ) ) {
				$el.trigger( 'change.select2' );
			}
		} );

		// Hide the remove-"x" and drop a lock badge in its place.
		var $action = $row.find( '.lty-instant-winner-action-column' ).first();
		$action.find( '.lty-remove-instant-winner-rule' ).hide();
		if ( ! $action.find( '.nera-iwt-lock-badge' ).length ) {
			$action.append(
				'<span class="nera-iwt-lock-badge" title="' +
					$( '<div/>' ).text( lockedTitle ).html() +
					'"><span class="dashicons dashicons-lock"></span></span>'
			);
		}
	}

	function applyLocks() {
		injectStyle();
		$( '.lty-instant-winners-rules-contents tbody tr' ).each( function () {
			var $row = $( this );
			var id = ruleIdOfRow( $row );
			if ( id && wonIds.indexOf( id ) !== -1 ) {
				lockRow( $row );
			}
		} );
	}

	$( function () {
		applyLocks();
	} );

	// LFW re-renders the table via AJAX (save / remove / pagination); re-apply
	// after each request. Idempotent, so running on every ajaxComplete is safe.
	$( document ).on( 'ajaxComplete', function () {
		applyLocks();
	} );
} )( jQuery );
