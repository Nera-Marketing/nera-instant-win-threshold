<?php
/**
 * Admin: hide (not remove) the Export / Import buttons on the product edit
 * page's Instant Winner tab — both the instant-winner rules table and the
 * prize-groups section.
 *
 * The buttons are rendered by Lottery for WooCommerce; we only hide them with
 * CSS so the underlying export/import feature (and its data) stays fully intact.
 * A stylesheet rule also survives LFW's AJAX table re-renders automatically, so
 * no JavaScript is needed.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue the hide-export/import CSS on the product edit screen only.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function nera_iwt_hide_export_import_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	$handle = 'nera-iwt-hide-export-import';
	// Inline-only stylesheet (no src): register a handle so wp_add_inline_style
	// has something to attach to.
	wp_register_style( $handle, false, array(), NERA_IWT_VERSION );
	wp_enqueue_style( $handle );

	$css = '.lty-import-instant-winner-rule-btn,'
		. '.lty-export-instant-winner-rules,'
		. '.lty-import-instant-winner-prize-groups-btn,'
		. '.lty-export-instant-winner-prize-groups-btn{display:none !important;}';

	wp_add_inline_style( $handle, $css );
}
add_action( 'admin_enqueue_scripts', 'nera_iwt_hide_export_import_assets' );
