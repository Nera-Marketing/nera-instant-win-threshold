/**
 * Lottery for WooCommerce admin bugfix: Instant Win Prizes rule image
 * "Remove" button clears the wrong hidden field (`.lty-selected-image-id`,
 * which doesn't exist on this row) instead of the real one
 * (`.lty-instant-winner-image-id`), so a "removed" image silently survives
 * every Save/Update. See inc/admin-instant-winner-image-fix.php for the
 * full explanation.
 *
 * This adds a second, independent click handler on the same
 * `.lty-remove-image` buttons — both the rule table rows and the "Add new
 * rule" popup use the same markup/classes — that clears the real field.
 * The vendor plugin's own handler keeps running too (it still correctly
 * swaps the preview image and hides the button); this only supplies the
 * one thing it was missing.
 */
( function ( $ ) {
	'use strict';

	function clearRealImageId( event ) {
		$( event.currentTarget )
			.closest( 'tr, p' )
			.find( '.lty-instant-winner-image-id' )
			.val( '' );
	}

	function init() {
		$( document ).on( 'click', '.lty-remove-image', clearRealImageId );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( jQuery );
