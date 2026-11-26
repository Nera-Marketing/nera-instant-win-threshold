<?php
/**
 * Lottery for WooCommerce admin bugfix: Instant Win Prizes rule image
 * "Remove" button clears the wrong hidden field.
 *
 * The vendor plugin's own `.lty-remove-image` click handler
 * (lottery-for-woocommerce/assets/js/admin/admin.js) empties
 * `.lty-selected-image-id` — a field used only by a different, unrelated
 * image picker on the vendor plugin's settings page — instead of this rule
 * row's actual `.lty-instant-winner-image-id` hidden input. The preview
 * swaps to a placeholder and the button hides itself, so the delete LOOKS
 * like it worked, but the real attachment ID is never cleared: the rule
 * "Save" AJAX call (and the log entry it also updates) faithfully
 * re-persists the old image every time.
 *
 * Fixed here rather than by editing lottery-for-woocommerce directly, so
 * the fix survives a vendor plugin update: a small admin script enqueued
 * on the product edit screen adds a SECOND click handler on the same
 * `.lty-remove-image` buttons that clears the real field. It runs alongside
 * the vendor plugin's own (now harmless-but-still-firing) handler rather
 * than replacing it — both handlers touch different, unrelated fields, so
 * there is nothing to unbind or race against.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue the fix, only on the product edit screen.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function nera_iwt_enqueue_instant_winner_image_fix( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_script(
		'nera-iwt-instant-winner-image-fix',
		plugins_url( 'assets/js/admin-instant-winner-image-fix.js', NERA_IWT_PLUGIN_FILE ),
		array( 'jquery' ),
		NERA_IWT_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'nera_iwt_enqueue_instant_winner_image_fix' );
