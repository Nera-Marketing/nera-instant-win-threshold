<?php
/**
 * Repair orphaned "Lottery for WooCommerce" pending tickets.
 *
 * When an unpaid lottery order is cancelled/refunded/trashed but LFW's own
 * cleanup fails (missing `lty_ticket_ids_in_order` order meta), its
 * `lty_ticket_pending` ticket posts survive forever. LFW counts pending
 * tickets as "placed", so the next product save recalculates stock as
 * max_tickets - placed and can drive the product to 0/negative stock
 * ("out of stock") while tickets are still available.
 *
 * This script finds pending tickets whose order is trashed / cancelled /
 * refunded / failed / missing, then (in apply mode):
 *   1. resets linked lty_ins_winner_log posts back to lty_available
 *   2. deletes the orphaned ticket posts
 *   3. prunes their ticket numbers from _lty_hold_tickets / lty_hold_tickets
 *   4. flushes LFW transients
 *   5. recalculates WooCommerce stock via LFW's own math
 *
 * Usage (dry-run by default):
 *   wp eval-file fix-orphaned-lottery-tickets.php <product_id|all> [apply] [clear-holds] --skip-themes
 *
 *   clear-holds  additionally empties BOTH hold-ticket metas entirely.
 *                Only use when no live carts exist (local clone / quiet window).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run via WP-CLI: wp eval-file fix-orphaned-lottery-tickets.php <product_id|all> [apply] [clear-holds]\n" );
}

$cli_args    = isset( $args ) && is_array( $args ) ? $args : array();
$target      = isset( $cli_args[0] ) ? $cli_args[0] : '';
$apply       = in_array( 'apply', $cli_args, true );
$clear_holds = in_array( 'clear-holds', $cli_args, true );

if ( '' === $target ) {
	WP_CLI::error( 'Pass a product ID or "all" as the first argument.' );
}

if ( ! function_exists( 'lty_is_lottery_product' ) ) {
	WP_CLI::error( 'Lottery for WooCommerce is not active.' );
}

$orphan_order_statuses = array( 'cancelled', 'refunded', 'failed', 'trash' );

global $wpdb;

// Resolve target product IDs from pending tickets on record.
if ( 'all' === $target ) {
	$product_ids = array_map( 'intval', $wpdb->get_col(
		"SELECT DISTINCT post_parent FROM {$wpdb->posts}
		 WHERE post_type = 'lty_lottery_ticket' AND post_status = 'lty_ticket_pending' AND post_parent > 0"
	) );
	if ( empty( $product_ids ) ) {
		WP_CLI::success( 'No pending lottery tickets anywhere — nothing to do.' );
		return;
	}
} else {
	$product_ids = array( absint( $target ) );
}

WP_CLI::log( sprintf( '%s MODE — products: %s', $apply ? 'APPLY' : 'DRY-RUN', implode( ', ', $product_ids ) ) );

foreach ( $product_ids as $product_id ) {
	WP_CLI::log( "\n=== Product {$product_id} ===" );

	$pending = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, pm_num.meta_value AS ticket_number, pm_ord.meta_value AS order_id
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm_num ON pm_num.post_id = p.ID AND pm_num.meta_key = 'lty_ticket_number'
		 LEFT JOIN {$wpdb->postmeta} pm_ord ON pm_ord.post_id = p.ID AND pm_ord.meta_key = 'lty_order_id'
		 WHERE p.post_type = 'lty_lottery_ticket' AND p.post_status = 'lty_ticket_pending' AND p.post_parent = %d",
		$product_id
	) );

	if ( empty( $pending ) ) {
		WP_CLI::log( 'No pending tickets. Skipping.' );
		continue;
	}

	// Classify each order once.
	$order_states = array();
	foreach ( array_unique( wp_list_pluck( $pending, 'order_id' ) ) as $oid ) {
		$oid = (int) $oid;
		if ( ! $oid ) {
			$order_states[ $oid ] = 'missing';
			continue;
		}
		$order                = wc_get_order( $oid );
		$order_states[ $oid ] = is_object( $order ) ? $order->get_status() : 'missing';
	}

	$orphans = array();
	$live    = array();
	foreach ( $pending as $row ) {
		$state = $order_states[ (int) $row->order_id ];
		if ( 'missing' === $state || in_array( $state, $orphan_order_statuses, true ) ) {
			$orphans[] = $row;
		} else {
			$live[] = $row;
		}
	}

	foreach ( $order_states as $oid => $state ) {
		WP_CLI::log( sprintf( '  order %d → %s', $oid, $state ) );
	}
	WP_CLI::log( sprintf( '  pending tickets: %d total, %d orphaned, %d on live orders (untouched)', count( $pending ), count( $orphans ), count( $live ) ) );

	if ( empty( $orphans ) ) {
		WP_CLI::log( '  Nothing orphaned. Skipping.' );
		continue;
	}

	$orphan_order_ids = array_unique( array_map( 'intval', wp_list_pluck( $orphans, 'order_id' ) ) );

	// Instant-win logs locked by these orders.
	$iw_placeholders = implode( ',', array_fill( 0, count( $orphan_order_ids ), '%d' ) );
	$iw_log_ids      = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'lty_order_id'
		 WHERE p.post_type = 'lty_ins_winner_log' AND p.post_status IN ('lty_pending', 'lty_won')
		 AND pm.meta_value IN ({$iw_placeholders})",
		$orphan_order_ids
	) ) );
	WP_CLI::log( sprintf( '  instant-win logs to reset: %d', count( $iw_log_ids ) ) );

	if ( ! $apply ) {
		WP_CLI::log( '  DRY-RUN: no changes made. Re-run with "apply" to execute.' );
		continue;
	}

	// 1. Reset instant-win logs via LFW's own entity method.
	foreach ( $iw_log_ids as $log_id ) {
		$log = lty_get_instant_winner_log( $log_id );
		if ( is_object( $log ) && $log->exists() ) {
			$log->remove_instant_winner();
		}
	}
	WP_CLI::log( sprintf( '  reset %d instant-win logs to lty_available', count( $iw_log_ids ) ) );

	// 2. Delete orphaned ticket posts.
	$deleted = 0;
	foreach ( $orphans as $row ) {
		if ( wp_delete_post( (int) $row->ID, true ) ) {
			$deleted++;
		}
		if ( 0 === $deleted % 2000 ) {
			WP_CLI::log( "    ...{$deleted} deleted" );
		}
	}
	WP_CLI::log( sprintf( '  deleted %d/%d orphaned ticket posts', $deleted, count( $orphans ) ) );

	// 3. Hold-ticket metas: prune orphaned numbers (or clear entirely).
	$orphan_numbers = array_map( 'strval', wp_list_pluck( $orphans, 'ticket_number' ) );
	foreach ( array( '_lty_hold_tickets', 'lty_hold_tickets' ) as $hold_key ) {
		$holds  = array_filter( (array) get_post_meta( $product_id, $hold_key, true ) );
		$before = count( $holds );
		$holds  = $clear_holds ? array() : array_values( array_diff( array_map( 'strval', $holds ), $orphan_numbers ) );
		update_post_meta( $product_id, $hold_key, $holds );
		WP_CLI::log( sprintf( '  %s: %d → %d entries%s', $hold_key, $before, count( $holds ), $clear_holds ? ' (cleared)' : '' ) );
	}

	// 4. Flush LFW transients so placed/purchased counts recompute.
	if ( class_exists( 'LTY_Transient_Handler' ) ) {
		LTY_Transient_Handler::delete_all_transients( $product_id );
	}

	// 5. Recalculate stock exactly like LFW's product-save handler.
	$product = wc_get_product( $product_id );
	if ( is_object( $product ) && lty_is_lottery_product( $product ) && ! $product->is_closed() ) {
		$max    = absint( $product->get_lty_maximum_tickets() );
		$placed = (int) $product->get_placed_ticket_count();
		$stock  = $max - $placed;
		wc_update_product_stock( $product_id, $stock, 'set', true );
		// The $updating flag skips WC's stock-status recalculation — set it explicitly.
		wc_update_product_stock_status( $product_id, $stock > 0 ? 'instock' : 'outofstock' );
		wc_delete_product_transients( $product_id );
		WP_CLI::log( sprintf( '  stock recalculated: %d max - %d placed = %d (%s)', $max, $placed, $stock, $stock > 0 ? 'instock' : 'CHECK MANUALLY' ) );
	} else {
		WP_CLI::warning( '  product missing/closed — stock left untouched.' );
	}
}

WP_CLI::success( $apply ? 'Repair complete.' : 'Dry-run complete.' );
