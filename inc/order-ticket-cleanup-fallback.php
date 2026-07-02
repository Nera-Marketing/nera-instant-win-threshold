<?php
/**
 * Order ticket cleanup fallback — orphaned pending-ticket protection.
 *
 * LFW removes a dead order's lottery tickets in remove_lottery_ticket_for_order_cancel(),
 * but that path depends entirely on the `lty_ticket_ids_in_order` order meta. When the
 * meta is missing (observed in production: three trashed bulk orders left 12,942
 * lty_ticket_pending posts behind), the tickets survive forever. Because LFW counts
 * pending tickets as "placed" when it recalculates stock on product save
 * (max_tickets - placed_ticket_count), orphaned pending tickets eventually drive the
 * product to zero/negative stock and WooCommerce blocks add-to-cart while the
 * progress bar still shows tickets available.
 *
 * This fallback runs AFTER LFW's own handlers and queries tickets directly by their
 * `lty_order_id` meta, so it works even when the order meta is gone. If LFW's cleanup
 * already did its job the query finds nothing and this is a no-op.
 *
 * A second, independent leak (incident 2026-07-02 #2, order 154701): LFW recalculates
 * stock on product save as `max_tickets − placed_ticket_count`, and pending tickets
 * count as placed. Save the product while an unpaid order sits in checkout and that
 * order's reservation is baked into `_stock`. When the order later dies, LFW deletes
 * the tickets but never recalculates stock, and WooCommerce's restock is a no-op
 * because stock was never reduced for the unpaid order — the deficit is permanent
 * until the next manual product save. The priority-30 recalc below closes this: after
 * every ticket-cleanup pass it re-runs LFW's own stock math for each lottery product
 * in the dead order.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delete every leftover lty_ticket_pending ticket that belongs to a dead order,
 * reset instant-win logs those tickets locked, prune the numbers from LFW's
 * hold-ticket metas, and flush LFW's counter transients.
 *
 * Only pending tickets are touched — buyer/winner tickets from paid orders are
 * never deleted, so firing this on refund of a completed order is safe (LFW's
 * own priority-10 handler owns that path; anything it removed is already gone).
 *
 * Stock is not touched here — nera_iwt_recalc_lottery_stock_for_order() runs at
 * priority 30 on the same hooks and re-applies LFW's stock formula afterwards.
 *
 * @param int $order_id Order ID.
 */
function nera_iwt_cleanup_orphaned_pending_tickets( $order_id ) {
	$order_id = absint( is_object( $order_id ) ? $order_id->get_id() : $order_id );
	if ( ! $order_id || ! function_exists( 'lty_get_instant_winner_log' ) || ! class_exists( 'LTY_Transient_Handler' ) ) {
		return;
	}

	global $wpdb;

	// Pending tickets still pointing at this order (LFW's meta-based cleanup missed them).
	$tickets = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_parent AS product_id, pm_num.meta_value AS ticket_number
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm_ord ON pm_ord.post_id = p.ID AND pm_ord.meta_key = 'lty_order_id' AND pm_ord.meta_value = %s
			 LEFT JOIN {$wpdb->postmeta} pm_num ON pm_num.post_id = p.ID AND pm_num.meta_key = 'lty_ticket_number'
			 WHERE p.post_type = 'lty_lottery_ticket' AND p.post_status = 'lty_ticket_pending'",
			(string) $order_id
		)
	);

	if ( empty( $tickets ) ) {
		return;
	}

	// Release instant-win prizes this order's tickets locked (lty_pending / lty_won logs).
	$log_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'lty_order_id' AND pm.meta_value = %s
			 WHERE p.post_type = 'lty_ins_winner_log' AND p.post_status IN ( 'lty_pending', 'lty_won' )",
			(string) $order_id
		)
	);
	foreach ( $log_ids as $log_id ) {
		$log = lty_get_instant_winner_log( (int) $log_id );
		if ( is_object( $log ) && $log->exists() ) {
			$log->remove_instant_winner();
		}
	}

	// Group by product so hold metas and transients are touched once per product.
	$by_product = array();
	foreach ( $tickets as $ticket ) {
		$by_product[ (int) $ticket->product_id ][] = $ticket;
	}

	foreach ( $by_product as $product_id => $product_tickets ) {
		foreach ( $product_tickets as $ticket ) {
			wp_delete_post( (int) $ticket->ID, true );
		}

		$numbers = array_map( 'strval', array_filter( wp_list_pluck( $product_tickets, 'ticket_number' ) ) );
		foreach ( array( '_lty_hold_tickets', 'lty_hold_tickets' ) as $hold_key ) {
			$holds = array_filter( (array) get_post_meta( $product_id, $hold_key, true ) );
			if ( empty( $holds ) ) {
				continue;
			}
			$pruned = array_values( array_diff( array_map( 'strval', $holds ), $numbers ) );
			if ( count( $pruned ) !== count( $holds ) ) {
				update_post_meta( $product_id, $hold_key, $pruned );
			}
		}

		LTY_Transient_Handler::delete_all_transients( $product_id );
	}
}

/**
 * wp_trash_post / before_delete_post guard — only act on classic-storage shop orders.
 * (HPOS orders fire the dedicated woocommerce_* hooks below instead.)
 *
 * @param int $post_id Post ID.
 */
function nera_iwt_cleanup_orphaned_pending_tickets_for_order_post( $post_id ) {
	if ( 'shop_order' === get_post_type( $post_id ) ) {
		nera_iwt_cleanup_orphaned_pending_tickets( (int) $post_id );
	}
}

// After LFW's priority-10 remove_lottery_ticket_for_order_cancel handlers.
add_action( 'woocommerce_order_status_cancelled', 'nera_iwt_cleanup_orphaned_pending_tickets', 20 );
add_action( 'woocommerce_order_status_refunded', 'nera_iwt_cleanup_orphaned_pending_tickets', 20 );
add_action( 'woocommerce_order_status_failed', 'nera_iwt_cleanup_orphaned_pending_tickets', 20 );

// Trash/delete never fires the status hooks above — LFW has no handler at all there.
add_action( 'woocommerce_trash_order', 'nera_iwt_cleanup_orphaned_pending_tickets', 20 );
add_action( 'woocommerce_before_delete_order', 'nera_iwt_cleanup_orphaned_pending_tickets', 20 );
add_action( 'wp_trash_post', 'nera_iwt_cleanup_orphaned_pending_tickets_for_order_post', 20 );
add_action( 'before_delete_post', 'nera_iwt_cleanup_orphaned_pending_tickets_for_order_post', 20 );

// ─── Stock recalc after ticket cleanup ────────────────────────────────────────

/**
 * Recalculate WooCommerce stock for every lottery product in a dead order.
 *
 * Runs at priority 30 — after LFW's ticket removal (10) and the orphan cleanup
 * above (20) — and unconditionally re-applies LFW's own stock formula
 * (`max_tickets − placed_ticket_count`), mirroring what LFW does on product save.
 * Always recalculating (rather than only when orphans were found) is what closes
 * the leak: LFW's own removal deletes tickets without touching stock, so any
 * reservation baked into `_stock` by an earlier product save must be recomputed
 * here. The formula is idempotent and also self-heals prior drift.
 *
 * A `set` can race a concurrent purchase's atomic stock decrement — the same
 * exposure LFW's save-time recalc already has; the next recalc or save corrects it.
 *
 * @param int $order_id Order ID.
 */
function nera_iwt_recalc_lottery_stock_for_order( $order_id ) {
	static $done = array();

	$order_id = absint( is_object( $order_id ) ? $order_id->get_id() : $order_id );
	if ( ! $order_id || isset( $done[ $order_id ] ) ) {
		return;
	}
	$done[ $order_id ] = true;

	if ( ! function_exists( 'lty_is_lottery_product' ) || ! class_exists( 'LTY_Transient_Handler' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) ) {
		return;
	}

	$product_ids = array();
	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
		if ( $product && 'lottery' === $product->get_type() ) {
			$product_ids[ $product->get_id() ] = true;
		}
	}

	foreach ( array_keys( $product_ids ) as $product_id ) {
		// Flush LFW's counters first so get_placed_ticket_count() recounts from the DB.
		LTY_Transient_Handler::delete_all_transients( $product_id );

		// Fresh object — no stale in-object ticket caches.
		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! lty_is_lottery_product( $product ) || $product->is_closed() ) {
			continue;
		}

		$max = absint( $product->get_lty_maximum_tickets() );
		if ( $max <= 0 ) {
			continue; // Unlimited lotteries don't manage a finite pool.
		}

		$stock = $max - (int) $product->get_placed_ticket_count();
		wc_update_product_stock( $product_id, $stock, 'set', true );
		// The $updating flag above skips WC's stock-status recalculation — set it explicitly.
		wc_update_product_stock_status( $product_id, $stock > 0 ? 'instock' : 'outofstock' );
		wc_delete_product_transients( $product_id );
	}
}

/**
 * wp_trash_post / before_delete_post guard for the stock recalc — classic-storage
 * shop orders only, matching the cleanup guard above.
 *
 * @param int $post_id Post ID.
 */
function nera_iwt_recalc_lottery_stock_for_order_post( $post_id ) {
	if ( 'shop_order' === get_post_type( $post_id ) ) {
		nera_iwt_recalc_lottery_stock_for_order( (int) $post_id );
	}
}

add_action( 'woocommerce_order_status_cancelled', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_order_status_refunded', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_order_status_failed', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_trash_order', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_before_delete_order', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'wp_trash_post', 'nera_iwt_recalc_lottery_stock_for_order_post', 30 );
add_action( 'before_delete_post', 'nera_iwt_recalc_lottery_stock_for_order_post', 30 );
