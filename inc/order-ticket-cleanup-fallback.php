<?php
/**
 * Dead-order consistency: ticket cleanup (LFW parity) + stock recalc.
 *
 * LFW creates `lty_lottery_ticket` posts the moment an order is placed and
 * removes them in remove_lottery_ticket_for_order_cancel() when the order dies
 * (cancelled / refunded / failed) — but that path bails when either the
 * `lty_lottery_ticket_created_once` or `lty_ticket_ids_in_order` order meta is
 * missing (observed in production: three trashed bulk orders left 12,942
 * lty_ticket_pending posts behind), and LFW has no trash/delete handler at all.
 * Because pending tickets count as "placed" in LFW's save-time stock
 * recalculation (max_tickets − placed_ticket_count), surviving tickets
 * eventually drive the product to zero/negative stock: WooCommerce reports
 * "out of stock" while the sold counter still shows availability.
 *
 * The cleanup below (priority 20, after LFW's priority-10 handlers) queries
 * tickets directly by their `lty_order_id` meta, so it works even when the
 * order meta is gone. Two modes:
 *
 * • status (cancelled/refunded/failed) — full LFW parity: removes tickets of
 *   ANY status (LFW itself deletes buyer/winner tickets there too — a full
 *   refund forfeits the entries), resets instant-win logs, decrements the
 *   promoted-ticket counter, prunes hold metas, flushes product + per-user
 *   transients, and ALWAYS clears LFW's three order metas + order-item ticket
 *   metas. Clearing the metas restores LFW's revive contract: a later
 *   re-payment (failed → processing, or untrash → pay) passes the
 *   `created_once` guard in create_ticket_for_order_item() and the customer
 *   gets fresh tickets — with stale metas they would pay and receive nothing.
 *
 * • trash (woocommerce_trash_order / woocommerce_before_delete_order) —
 *   conservative: LFW never handles trash and admins do trash PAID orders
 *   during manual cleanup, so only pending tickets are removed. The order
 *   metas are cleared only when the order holds zero lottery tickets
 *   afterwards (fully-unpaid order → clean revival on untrash + pay; a paid
 *   order keeps its buyer tickets AND metas so untrash restores it untouched).
 *
 * Untrash needs no handler: WooCommerce restores the pre-trash status via
 * set_status(), so the normal transition hooks fire — with metas correctly
 * cleared, LFW either recreates tickets (processing/completed) or does nothing.
 *
 * A second leak (incident 2026-07-02 #2, order 154701): LFW recalculates
 * stock on product save as `max_tickets − placed_ticket_count`, and pending
 * tickets count as placed. Save the product while an unpaid order sits in
 * checkout and that order's reservation is baked into `_stock`. When the
 * order later dies, the tickets are deleted but nothing recalculates stock,
 * and WooCommerce's restock is a no-op because stock was never reduced for
 * the unpaid order — the deficit is permanent until the next manual product
 * save. The priority-30 recalc below closes this: after every cleanup pass it
 * re-runs LFW's own stock math for each lottery product in the dead order.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

// ─── Cleanup (priority 20) ────────────────────────────────────────────────────

/**
 * Remove a dead order's lottery tickets and restore consistent LFW state.
 *
 * Runs after LFW's own remove_lottery_ticket_for_order_cancel (priority 10);
 * when LFW's meta-based cleanup already did its job the ticket query finds
 * nothing and only stale-meta clearing (status mode) remains.
 *
 * @param int    $order_id Order ID.
 * @param string $mode     'status' (cancelled/refunded/failed — full parity)
 *                         or 'trash' (pending tickets only).
 */
function nera_iwt_cleanup_dead_order( $order_id, $mode = 'status' ) {
	$order_id = absint( is_object( $order_id ) ? $order_id->get_id() : $order_id );
	if ( ! $order_id || ! function_exists( 'lty_get_instant_winner_log' ) || ! class_exists( 'LTY_Transient_Handler' ) ) {
		return;
	}

	global $wpdb;

	// Promoted statuses come from LFW's own (filterable) list; nera_prize_hold
	// reserve tickets are never order-bound and are excluded by both the status
	// list and the lty_order_id join.
	$promoted_statuses = function_exists( 'lty_get_lottery_ticket_statuses' )
		? (array) lty_get_lottery_ticket_statuses()
		: array( 'lty_ticket_buyer', 'lty_ticket_winner' );
	$statuses          = ( 'trash' === $mode )
		? array( 'lty_ticket_pending' )
		: array_merge( array( 'lty_ticket_pending' ), $promoted_statuses );

	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$tickets      = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_parent AS product_id, p.post_status,
			        pm_num.meta_value AS ticket_number, pm_user.meta_value AS user_id
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm_ord ON pm_ord.post_id = p.ID AND pm_ord.meta_key = 'lty_order_id' AND pm_ord.meta_value = %s
			 LEFT JOIN {$wpdb->postmeta} pm_num ON pm_num.post_id = p.ID AND pm_num.meta_key = 'lty_ticket_number'
			 LEFT JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID AND pm_user.meta_key = 'lty_user_id'
			 WHERE p.post_type = 'lty_lottery_ticket' AND p.post_status IN ({$placeholders})",
			array_merge( array( (string) $order_id ), $statuses )
		)
	);

	if ( ! empty( $tickets ) ) {
		// Instant-win logs to reset: locked by this order (works when the
		// ticket→log linkage is gone) plus LFW's per-ticket parity lookup.
		$log_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'lty_order_id' AND pm.meta_value = %s
					 WHERE p.post_type = 'lty_ins_winner_log' AND p.post_status IN ( 'lty_pending', 'lty_won' )",
					(string) $order_id
				)
			)
		);
		if ( function_exists( 'lty_get_instant_winner_log_id_by_ticket_id' ) ) {
			foreach ( $tickets as $ticket ) {
				$log_id = lty_get_instant_winner_log_id_by_ticket_id( (int) $ticket->ID );
				if ( $log_id ) {
					$log_ids[] = (int) $log_id;
				}
			}
		}
		foreach ( array_unique( $log_ids ) as $log_id ) {
			$log = lty_get_instant_winner_log( $log_id );
			if ( is_object( $log ) && $log->exists() ) {
				// Mirror LFW's cancel path: unassign a won prize before release.
				if ( $log->has_status( 'lty_won' ) ) {
					$log->remove_won_prize();
				}
				$log->remove_instant_winner();
				$log->update_status( 'lty_available' );
			}
		}

		$by_product = array();
		foreach ( $tickets as $ticket ) {
			$by_product[ (int) $ticket->product_id ][] = $ticket;
		}

		foreach ( $by_product as $product_id => $product_tickets ) {
			$promoted_deleted = 0;
			$user_ids         = array();
			foreach ( $product_tickets as $ticket ) {
				if ( wp_delete_post( (int) $ticket->ID, true ) && in_array( $ticket->post_status, $promoted_statuses, true ) ) {
					$promoted_deleted++;
				}
				if ( ! empty( $ticket->user_id ) ) {
					$user_ids[ (int) $ticket->user_id ] = true;
				}
			}

			// Only promoted tickets ever incremented LFW's lty_ticket_count —
			// deliberately NOT LFW's blanket decrement, which drifts negative
			// for never-promoted pending tickets.
			if ( $promoted_deleted > 0 ) {
				$ticket_count = (int) get_post_meta( $product_id, '_lty_ticket_count', true );
				update_post_meta( $product_id, '_lty_ticket_count', max( 0, $ticket_count - $promoted_deleted ) );
			}

			// Prune the deleted numbers from both hold-ticket metas (LFW writes
			// the underscore and non-underscore keys inconsistently).
			$numbers = array_map( 'strval', array_filter( wp_list_pluck( $product_tickets, 'ticket_number' ), 'strlen' ) );
			if ( ! empty( $numbers ) ) {
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
			}

			// Per-user counters gate LFW's user max-ticket limits — flush them too.
			foreach ( array_keys( $user_ids ) as $user_id ) {
				LTY_Transient_Handler::delete_all_transients( $product_id, $user_id );
			}
			LTY_Transient_Handler::delete_all_transients( $product_id );
		}
	}

	// ── Order-meta clearing: restore LFW's revive contract ──
	$order = wc_get_order( $order_id );
	if ( ! is_object( $order ) ) {
		return;
	}

	$clear_metas = ( 'status' === $mode );
	if ( 'trash' === $mode ) {
		$all_statuses  = array_merge( array( 'lty_ticket_pending' ), $promoted_statuses );
		$placeholders  = implode( ',', array_fill( 0, count( $all_statuses ), '%s' ) );
		$tickets_left  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'lty_order_id' AND pm.meta_value = %s
				 WHERE p.post_type = 'lty_lottery_ticket' AND p.post_status IN ({$placeholders})",
				array_merge( array( (string) $order_id ), $all_statuses )
			)
		);
		$clear_metas   = ( 0 === $tickets_left );
	}

	$has_stale_meta = $order->get_meta( 'lty_ticket_ids_in_order' )
		|| $order->get_meta( 'lty_lottery_ticket_created_once' )
		|| $order->get_meta( 'lty_lottery_ticket_updated_once' );

	if ( $clear_metas && $has_stale_meta ) {
		if ( class_exists( 'LTY_Order_Handler' ) && method_exists( 'LTY_Order_Handler', 'delete_tickets_meta_in_order_item' ) ) {
			LTY_Order_Handler::delete_tickets_meta_in_order_item( $order_id );
		}
		$order->delete_meta_data( 'lty_ticket_ids_in_order' );
		$order->delete_meta_data( 'lty_lottery_ticket_created_once' );
		$order->delete_meta_data( 'lty_lottery_ticket_updated_once' );
		$order->save();
	}
}

/**
 * Status-transition entry point (cancelled / refunded / failed).
 *
 * @param int $order_id Order ID.
 */
function nera_iwt_cleanup_dead_order_status( $order_id ) {
	nera_iwt_cleanup_dead_order( $order_id, 'status' );
}

/**
 * Trash / delete entry point.
 *
 * @param int $order_id Order ID.
 */
function nera_iwt_cleanup_dead_order_trash( $order_id ) {
	nera_iwt_cleanup_dead_order( $order_id, 'trash' );
}

// After LFW's priority-10 remove_lottery_ticket_for_order_cancel handlers.
add_action( 'woocommerce_order_status_cancelled', 'nera_iwt_cleanup_dead_order_status', 20 );
add_action( 'woocommerce_order_status_refunded', 'nera_iwt_cleanup_dead_order_status', 20 );
add_action( 'woocommerce_order_status_failed', 'nera_iwt_cleanup_dead_order_status', 20 );

// Trash/delete never fires the status hooks — LFW has no handler at all there.
// WooCommerce fires these two in BOTH storage modes (HPOS and legacy CPT), so
// no wp_trash_post/before_delete_post registration is needed.
add_action( 'woocommerce_trash_order', 'nera_iwt_cleanup_dead_order_trash', 20 );
add_action( 'woocommerce_before_delete_order', 'nera_iwt_cleanup_dead_order_trash', 20 );

// ─── Stock recalc after ticket cleanup (priority 30) ─────────────────────────

/**
 * Recalculate WooCommerce stock for every lottery product in a dead order.
 *
 * Runs at priority 30 — after LFW's ticket removal (10) and the cleanup above
 * (20) — and unconditionally re-applies LFW's own stock formula
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

add_action( 'woocommerce_order_status_cancelled', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_order_status_refunded', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_order_status_failed', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_trash_order', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
add_action( 'woocommerce_before_delete_order', 'nera_iwt_recalc_lottery_stock_for_order', 30 );
