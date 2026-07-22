<?php
/**
 * Admin: protect instant-win rules that have already been awarded to a winner.
 *
 * On the WooCommerce product edit page, any instant-win configuration row whose
 * rule has been won (for the product's CURRENT relist round) is locked:
 *   - all of its fields are disabled and its remove-"x" is hidden (client-side,
 *     via assets/js/admin-winner-lock.js);
 *   - the underlying LFW save/remove AJAX endpoints are guarded server-side so a
 *     crafted request still cannot overwrite or delete a won rule.
 *
 * The instant-win table itself is rendered by the base Lottery for WooCommerce
 * plugin; this module only hooks into it and never edits LFW core. If a product
 * is relisted (ends and reopens for a fresh round) the previous round's rows
 * unlock again for the new round, while the earlier winner record is preserved.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether an instant-win rule has been awarded to a winner in the given relist round.
 *
 * @param int $rule_id      Instant-winner rule post ID.
 * @param int $relist_count The product's relist round to check (0 = first round).
 * @return bool
 */
function nera_iwt_rule_is_won( $rule_id, $relist_count = 0 ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0
		|| ! function_exists( 'lty_get_instant_winner_log_id_by_rule_id' )
		|| ! function_exists( 'lty_get_instant_winner_log' ) ) {
		return false;
	}

	$log_id = lty_get_instant_winner_log_id_by_rule_id( $rule_id, (int) $relist_count );
	if ( ! $log_id ) {
		return false;
	}

	$log = lty_get_instant_winner_log( $log_id );
	return is_object( $log ) && method_exists( $log, 'has_status' ) && $log->has_status( 'lty_won' );
}

/**
 * The product's current relist round, defaulting to 0 when unavailable.
 *
 * @param WC_Product|object|null $product Product object.
 * @return int
 */
function nera_iwt_current_relist_count( $product ) {
	if ( is_object( $product ) && is_callable( array( $product, 'get_current_relist_count' ) ) ) {
		return (int) $product->get_current_relist_count();
	}
	return 0;
}

/**
 * Rule IDs of a product's instant wins that are locked (won this round).
 *
 * @param WC_Product|object|null $product Product object.
 * @return int[]
 */
function nera_iwt_get_won_rule_ids( $product ) {
	$won = array();
	if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) )
		|| ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return $won;
	}

	$relist   = nera_iwt_current_relist_count( $product );
	$rule_ids = lty_get_instant_winner_rule_ids( $product->get_id() );
	if ( ! is_array( $rule_ids ) ) {
		return $won;
	}

	foreach ( $rule_ids as $rule_id ) {
		if ( nera_iwt_rule_is_won( $rule_id, $relist ) ) {
			$won[] = (int) $rule_id;
		}
	}

	return $won;
}

/**
 * Enqueue the client-side lock script on the product edit screen and hand it the
 * list of won rule IDs for this product.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function nera_iwt_enqueue_winner_lock( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	global $post;
	if ( ! $post || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		return;
	}

	wp_enqueue_script(
		'nera-iwt-winner-lock',
		plugins_url( 'assets/js/admin-winner-lock.js', NERA_IWT_PLUGIN_FILE ),
		array( 'jquery' ),
		NERA_IWT_VERSION,
		true
	);

	wp_localize_script(
		'nera-iwt-winner-lock',
		'neraIwtLock',
		array(
			'wonRuleIds'  => array_map( 'strval', nera_iwt_get_won_rule_ids( $product ) ),
			'lockedTitle' => __( 'Locked: this instant win has been awarded to a winner.', 'nera-instant-win-threshold' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'nera_iwt_enqueue_winner_lock' );

/**
 * Server-side guard: strip any won rule from the LFW "save instant winner rules"
 * payload before LFW processes it, so a won rule's data can never be overwritten.
 * Runs at priority 1, ahead of LFW's own (default-priority) handler.
 *
 * @return void
 */
function nera_iwt_guard_save_instant_winner_rules() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- LFW verifies the nonce in its own handler; we only remove entries (never trust/act on them) before it runs.
	if ( empty( $_POST['product_id'] ) || empty( $_POST['instant_winners_rules'] ) || ! is_array( $_POST['instant_winners_rules'] ) ) {
		return;
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$product = wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) );
	if ( ! $product ) {
		return;
	}

	$relist = nera_iwt_current_relist_count( $product );
	foreach ( array_keys( $_POST['instant_winners_rules'] ) as $rule_id ) {
		if ( nera_iwt_rule_is_won( $rule_id, $relist ) ) {
			unset( $_POST['instant_winners_rules'][ $rule_id ] );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'wp_ajax_lty_save_instant_winners_rules', 'nera_iwt_guard_save_instant_winner_rules', 1 );

/**
 * Server-side guard: reject deletion of any won rule via LFW's "remove instant
 * winner rule" AJAX. Runs at priority 1, ahead of LFW's own handler, and ends
 * the request with an error before LFW can delete anything.
 *
 * @return void
 */
function nera_iwt_guard_remove_instant_winner_rule() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- LFW verifies the nonce in its own handler; we only read IDs to decide whether to reject before it runs.
	if ( empty( $_POST['product_id'] ) || empty( $_POST['instant_winner_rule_ids'] ) ) {
		return;
	}

	$rule_ids = wp_unslash( $_POST['instant_winner_rule_ids'] );
	if ( ! is_array( $rule_ids ) ) {
		return;
	}

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) ) : null;
	$relist  = nera_iwt_current_relist_count( $product );

	foreach ( $rule_ids as $rule_id ) {
		if ( nera_iwt_rule_is_won( $rule_id, $relist ) ) {
			wp_send_json_error(
				array(
					'error' => __( 'This instant win has been awarded to a winner and can no longer be removed.', 'nera-instant-win-threshold' ),
				)
			);
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'wp_ajax_lty_remove_instant_winner_rule', 'nera_iwt_guard_remove_instant_winner_rule', 1 );
