<?php
/**
 * E2E integration test — buy-all-rounds with real order lifecycle (v1.0.28).
 *
 * Proves end-to-end that a buyer who purchases every ticket (in 10 rounds of 1000)
 * wins ALL 19 instant-win prizes and receives the expected woo-wallet credit.
 *
 * Structure
 * ─────────
 * PHASE 1 — SMOKE  N=20, 1 ticket_pct + 1 woo_wallet prize, buy-all in 1 round.
 *            Validates real order lifecycle wiring before the heavy test.
 * PHASE 2 — FULL   N=10000, 9 ticket_pct + 10 woo_wallet prizes, 10×1000 rounds.
 *
 * Run:
 *   cd '/Users/minhle/Local Sites/competitions-core/app/public'
 *   SOCK="$HOME/Library/Application Support/Local/run/eSYfFlERw/mysql/mysqld.sock"
 *   PHAR="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"
 *   php -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
 *       -d memory_limit=1536M "$PHAR" \
 *       eval-file wp-content/plugins/nera-instant-win-threshold/tests/test-buy-all-rounds-e2e.php \
 *       2>&1 | grep -vi deprecated
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file <this file>\n" );

if ( ! class_exists( 'WC_Product_Lottery' ) || ! function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ) {
	WP_CLI::error( 'WooCommerce + Lottery for WooCommerce + Nera Instant Win Threshold must be active.' );
}
if ( ! function_exists( 'woo_wallet' ) ) {
	WP_CLI::error( 'WooWallet plugin must be active for wallet credit assertions.' );
}

// ---------------------------------------------------------------------------
// Assertion harness (same style as test-projection-drain.php)
// ---------------------------------------------------------------------------
$GLOBALS['t_pass']   = 0;
$GLOBALS['t_fail']   = 0;
$GLOBALS['t_posts']  = array(); // tracked post IDs
$GLOBALS['t_orders'] = array(); // tracked order IDs
$GLOBALS['t_users']  = array(); // tracked user IDs

function t_ok( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['t_pass'];
		WP_CLI::log( '  ✓ PASS  ' . $label );
	} else {
		++$GLOBALS['t_fail'];
		WP_CLI::log( '  ✗ FAIL  ' . $label );
	}
}
function t_section( $name ) {
	WP_CLI::log( '' );
	WP_CLI::log( '── ' . $name );
}
function t_track( $id ) {
	if ( $id ) {
		$GLOBALS['t_posts'][] = (int) $id;
	}
	return $id;
}
function t_track_order( $id ) {
	if ( $id ) {
		$GLOBALS['t_orders'][] = (int) $id;
	}
	return $id;
}

// ---------------------------------------------------------------------------
// Fixture builders
// ---------------------------------------------------------------------------

/**
 * Create a temp lottery product (automatic, shuffle, instant winners on).
 */
function e2e_make_product( $n, $label = 'NERA_IWT_E2E_PRODUCT' ) {
	$p = new WC_Product_Lottery();
	$p->set_name( $label );
	$p->set_status( 'publish' );
	$p->set_catalog_visibility( 'hidden' );
	$p->set_lty_maximum_tickets( $n );
	$p->update_meta_data( '_lty_user_maximum_tickets', $n );
	$p->set_lty_ticket_generation_type( '1' ); // automatic
	$p->set_lty_ticket_number_type( '3' );      // shuffle
	$p->set_lty_instant_winners( 'yes' );
	// Pool ceiling for shuffle generation = fixed 1..N.
	$p->update_meta_data( '_nera_iwt_ticket_number_max', $n );
	// Open date window.
	$p->update_meta_data( '_lty_start_date_gmt', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
	$p->update_meta_data( '_lty_end_date_gmt',   gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
	// No question configured — avoids answer-cancel gate.
	$p->update_meta_data( '_lty_lottery_question', '' );
	$p->set_price( '1.00' );
	$p->set_regular_price( '1.00' );
	$id = $p->save();

	// Set lottery status to started (admin save normally does this; we do it manually here).
	$p2 = wc_get_product( $id );
	$p2->update_lottery_status( 'lty_lottery_started' );

	t_track( $id );
	return (int) $id;
}

/**
 * Create a temp instant-win rule.
 */
function e2e_make_rule( $lottery_id, $number, $type = 'ticket_pct', $pct = 0 ) {
	$rid = wp_insert_post(
		array(
			'post_type'   => 'lty_instant_winners',
			'post_status' => 'publish',
			'post_parent' => $lottery_id,
			'post_title'  => 'NERA_IWT_E2E_RULE_' . $number,
		)
	);
	if ( ! $rid || is_wp_error( $rid ) ) {
		WP_CLI::log( '  !! Failed to create rule for ticket #' . $number );
		return 0;
	}
	update_post_meta( $rid, 'lty_ticket_number', (string) $number );
	update_post_meta( $rid, 'nera_iwt_public_rule_type', $type );
	if ( 'ticket_pct' === $type ) {
		update_post_meta( $rid, 'nera_iwt_ticket_pct', (int) $pct );
	}
	t_track( $rid );
	return (int) $rid;
}

/**
 * Create a temp instant-win log for a rule.
 * The log must pre-exist (admin normally creates it); we create it here.
 */
function e2e_make_log( $rule_id, $lottery_id, $ticket_number, $prize_type = 'physical', $prize_amount = 0, $relist_count = 0 ) {
	$meta_args = array(
		'lty_lottery_id'           => $lottery_id,
		'lty_ticket_number'        => (string) $ticket_number,
		'lty_prize_type'           => $prize_type,
		'lty_prize_amount'         => $prize_amount,
		'lty_current_relist_count' => $relist_count,
	);
	$post_args = array(
		'post_parent' => $rule_id,
		'post_status' => 'lty_available',
		'post_title'  => 'NERA_IWT_E2E_LOG_' . $ticket_number,
	);
	$log_id = lty_create_new_instant_winner_log( $meta_args, $post_args );
	if ( ! $log_id || is_wp_error( $log_id ) ) {
		WP_CLI::log( '  !! Failed to create log for rule ' . $rule_id );
		return 0;
	}
	t_track( $log_id );
	return (int) $log_id;
}

/**
 * Create a temp WP user for the buyer.
 */
function e2e_make_user( $suffix = '' ) {
	$uid = wp_create_user(
		'nera_e2e_buyer' . $suffix . '_' . time(),
		wp_generate_password(),
		'nera_e2e' . $suffix . '_' . time() . '@example.com'
	);
	if ( is_wp_error( $uid ) ) {
		WP_CLI::error( 'Could not create test user: ' . $uid->get_error_message() );
	}
	$GLOBALS['t_users'][] = (int) $uid;
	return (int) $uid;
}

/**
 * Run one round of the buy-all lifecycle:
 * 1. Create order with $qty tickets for $product.
 * 2. Fire checkout hook (triggers nera projection @1 + LFW create+declare @10).
 * 3. Update to processing (fires confirm+win via status hook).
 * 4. Defensive direct call to update_lottery_ticket_in_order (idempotent via guard).
 */
function e2e_run_round( $uid, $product_id, $qty ) {
	$product = wc_get_product( $product_id );

	$order = wc_create_order();
	$order->set_customer_id( $uid );
	$order->set_billing_first_name( 'E2E' );
	$order->set_billing_last_name( 'Buyer' );
	$order->set_billing_email( get_userdata( $uid )->user_email );
	$order->add_product( $product, $qty );
	$order->calculate_totals();
	$order->save();
	t_track_order( $order->get_id() );

	// Fire checkout hook: nera proj @1 + LFW create+declare @10.
	do_action( 'woocommerce_checkout_update_order_meta', $order->get_id() );

	// Reload order from DB (hooks may have updated meta).
	$order = wc_get_order( $order->get_id() );

	// Update to processing fires confirm+win via registered status hook.
	$order->update_status( 'processing' );

	// Defensive: direct call (idempotent via lty_lottery_ticket_updated_once guard).
	// Re-fetch order so the guard meta is visible.
	$order = wc_get_order( $order->get_id() );
	LTY_Order_Handler::update_lottery_ticket_in_order( $order->get_id(), $order );

	// Flush object cache so next-round queries see fresh state.
	wp_cache_flush();

	return $order->get_id();
}

/**
 * Count logs with status lty_won that are children of rules parented to lottery_id.
 */
function e2e_count_won_logs( $rule_ids ) {
	if ( empty( $rule_ids ) ) {
		return 0;
	}
	$count = 0;
	foreach ( $rule_ids as $rid ) {
		$log_id = lty_get_instant_winner_log_id_by_rule_id( $rid, 0 );
		if ( $log_id ) {
			$log = lty_get_instant_winner_log( $log_id );
			if ( $log->has_status( 'lty_won' ) ) {
				++$count;
			}
		}
	}
	return $count;
}

/**
 * Count nera_prize_hold posts for a lottery (leftover after sell-out should be 0).
 */
function e2e_count_hold_posts( $lottery_id ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'lty_lottery_ticket'
			   AND post_status = 'nera_prize_hold'
			   AND post_parent = %d",
			$lottery_id
		)
	);
}

/**
 * Count lty_ticket_buyer tickets for the lottery.
 */
function e2e_count_buyer_tickets( $lottery_id ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'lty_lottery_ticket'
			   AND post_status = 'lty_ticket_buyer'
			   AND post_parent = %d",
			$lottery_id
		)
	);
}

/**
 * Get all ticket numbers held by the buyer (lty_ticket_buyer status).
 */
function e2e_get_buyer_ticket_numbers( $lottery_id, $uid ) {
	global $wpdb;
	$ticket_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'lty_user_id'
			 WHERE p.post_type = 'lty_lottery_ticket'
			   AND p.post_status = 'lty_ticket_buyer'
			   AND p.post_parent = %d
			   AND pm.meta_value = %d",
			$lottery_id,
			$uid
		)
	);
	$numbers = array();
	foreach ( $ticket_ids as $tid ) {
		$num = get_post_meta( $tid, 'lty_ticket_number', true );
		if ( $num ) {
			$numbers[] = (int) $num;
		}
	}
	return $numbers;
}

// ===========================================================================
// TEST RUN
// ===========================================================================
$run_failed = false;

try {

	// =======================================================================
	// PHASE 1 — SMOKE TEST
	// N=20, prize #10 (ticket_pct 50%) + prize #20 (woo_wallet, always instant)
	// Buy all 20 in a single round.
	// =======================================================================
	t_section( 'PHASE 1 — SMOKE TEST (N=20, 1 ticket_pct + 1 woo_wallet, 1 round)' );

	$uid_smoke = e2e_make_user( '_smoke' );
	$pid_smoke = e2e_make_product( 20, 'NERA_IWT_E2E_SMOKE_PRODUCT' );
	$lid_smoke = (int) lty_get_lottery_id( $pid_smoke );

	// Rule: #10 ticket_pct at 50%.
	$rule_pct_smoke  = e2e_make_rule( $lid_smoke, 10, 'ticket_pct', 50 );
	$log_pct_smoke   = e2e_make_log( $rule_pct_smoke, $lid_smoke, 10, 'physical', 0, 0 );

	// Rule: #20 instant (woo_wallet, £5).
	$rule_iw_smoke   = e2e_make_rule( $lid_smoke, 20, 'instant', 0 );
	$log_iw_smoke    = e2e_make_log( $rule_iw_smoke, $lid_smoke, 20, 'woo_wallet', 5, 0 );

	t_ok( $rule_pct_smoke > 0, 'S1 ticket_pct rule created' );
	t_ok( $log_pct_smoke  > 0, 'S2 ticket_pct log created' );
	t_ok( $rule_iw_smoke  > 0, 'S3 instant rule created' );
	t_ok( $log_iw_smoke   > 0, 'S4 instant log created' );

	// Snapshot wallet before.
	$wallet_before_smoke = (float) woo_wallet()->wallet->get_wallet_balance( $uid_smoke, 'edit' );

	// Run the single buy-all round (qty=20).
	$oid_smoke = e2e_run_round( $uid_smoke, $pid_smoke, 20 );

	// Assert: purchased count = 20.
	$buyer_count_smoke = e2e_count_buyer_tickets( $lid_smoke );
	t_ok( 20 === $buyer_count_smoke, "S5 purchased ticket count = 20 (got $buyer_count_smoke)" );

	// Assert: both logs won.
	$log_pct  = lty_get_instant_winner_log( $log_pct_smoke );
	$log_iw   = lty_get_instant_winner_log( $log_iw_smoke );
	t_ok( $log_pct->has_status( 'lty_won' ), 'S6 ticket_pct log flipped to lty_won' );
	t_ok( $log_iw->has_status( 'lty_won' ),  'S7 woo_wallet log flipped to lty_won' );

	// Assert: wallet credited by 5 (the woo_wallet prize).
	$wallet_after_smoke  = (float) woo_wallet()->wallet->get_wallet_balance( $uid_smoke, 'edit' );
	$wallet_delta_smoke  = $wallet_after_smoke - $wallet_before_smoke;
	t_ok( abs( $wallet_delta_smoke - 5.0 ) < 0.01, "S8 wallet credited +5.00 (got +$wallet_delta_smoke)" );

	// Assert: no leftover hold posts.
	$holds_smoke = e2e_count_hold_posts( $lid_smoke );
	t_ok( 0 === $holds_smoke, "S9 zero leftover nera_prize_hold posts (got $holds_smoke)" );

	// Assert: buyer holds prize numbers #10 and #20.
	$buyer_nums_smoke = e2e_get_buyer_ticket_numbers( $lid_smoke, $uid_smoke );
	t_ok( in_array( 10, $buyer_nums_smoke, true ), 'S10 buyer holds prize ticket #10' );
	t_ok( in_array( 20, $buyer_nums_smoke, true ), 'S11 buyer holds prize ticket #20' );

	WP_CLI::log( '' );
	WP_CLI::log( '  Smoke wallet delta: +' . $wallet_delta_smoke );
	WP_CLI::log( '  Smoke buyer tickets: ' . $buyer_count_smoke );
	WP_CLI::log( '  Smoke holds remaining: ' . $holds_smoke );

	// =======================================================================
	// PHASE 2 — FULL TEST
	// N=10000, 9 ticket_pct prizes (10%..90%) + 10 woo_wallet prizes (#9991..#10000)
	// 10 rounds of 1000 tickets each.
	// =======================================================================
	t_section( 'PHASE 2 — FULL TEST (N=10000, 19 prizes, 10 rounds × 1000)' );

	$uid_full = e2e_make_user( '_full' );
	$pid_full = e2e_make_product( 10000, 'NERA_IWT_E2E_FULL_PRODUCT' );
	$lid_full = (int) lty_get_lottery_id( $pid_full );

	t_ok( $lid_full > 0, "F0 lottery product created (id=$pid_full, lid=$lid_full)" );

	// --- 9 ticket_pct prizes at 10%..90% ---.
	$pct_rules = array(); // pct => rule_id
	$pct_logs  = array(); // pct => log_id
	foreach ( range( 1, 9 ) as $i ) {
		$pct     = $i * 10;
		$num     = $i * 1000; // #1000, #2000, ..., #9000
		$rule_id = e2e_make_rule( $lid_full, $num, 'ticket_pct', $pct );
		$log_id  = e2e_make_log( $rule_id, $lid_full, $num, 'physical', 0, 0 );
		$pct_rules[ $pct ] = $rule_id;
		$pct_logs[ $pct ]  = $log_id;
	}
	t_ok( 9 === count( $pct_rules ), 'F1 created 9 ticket_pct rules (10%..90%)' );

	// --- 10 woo_wallet prizes at #9991..#10000 ---.
	$iw_rules = array(); // ticket_number => rule_id
	$iw_logs  = array(); // ticket_number => log_id
	foreach ( range( 9991, 10000 ) as $num ) {
		$rule_id = e2e_make_rule( $lid_full, $num, 'instant', 0 );
		$log_id  = e2e_make_log( $rule_id, $lid_full, $num, 'woo_wallet', 5, 0 );
		$iw_rules[ $num ] = $rule_id;
		$iw_logs[ $num ]  = $log_id;
	}
	t_ok( 10 === count( $iw_rules ), 'F2 created 10 woo_wallet instant rules (#9991..#10000)' );

	$all_rule_ids = array_values( $pct_rules ) + array_values( $iw_rules );

	// Snapshot wallet.
	$wallet_before_full = (float) woo_wallet()->wallet->get_wallet_balance( $uid_full, 'edit' );

	// --- Run 10 rounds ---
	for ( $round = 1; $round <= 10; $round++ ) {
		t_section( "  Round $round / 10 (buying 1000 tickets)" );

		// Mid-run check BEFORE round 9: 90% prize log NOT yet lty_won.
		if ( 9 === $round ) {
			$log_90 = lty_get_instant_winner_log( $pct_logs[90] );
			t_ok( ! $log_90->has_status( 'lty_won' ), 'F-MID 90% prize NOT yet lty_won before round 9' );
		}

		$oid = e2e_run_round( $uid_full, $pid_full, 1000 );

		$buyer_count = e2e_count_buyer_tickets( $lid_full );
		$expected    = $round * 1000;
		t_ok( $expected === $buyer_count, "  F3.$round after round $round: purchased=$buyer_count (expected $expected)" );

		WP_CLI::log( "    round $round done — order #$oid, total buyer tickets: $buyer_count" );
	}

	// --- Post-sell-out assertions ---
	t_section( 'Post sell-out assertions' );

	// F4: Total purchased = 10000.
	$total_purchased = e2e_count_buyer_tickets( $lid_full );
	t_ok( 10000 === $total_purchased, "F4 total purchased = 10000 (got $total_purchased)" );

	// F5: All 19 logs are lty_won.
	$won_pct = 0;
	foreach ( $pct_logs as $pct => $log_id ) {
		$log = lty_get_instant_winner_log( $log_id );
		if ( $log->has_status( 'lty_won' ) ) {
			++$won_pct;
		} else {
			WP_CLI::log( "  !! ticket_pct {$pct}% log ($log_id) NOT lty_won — status: " . $log->get_status() );
		}
	}
	$won_iw = 0;
	foreach ( $iw_logs as $num => $log_id ) {
		$log = lty_get_instant_winner_log( $log_id );
		if ( $log->has_status( 'lty_won' ) ) {
			++$won_iw;
		} else {
			WP_CLI::log( "  !! woo_wallet log #$num ($log_id) NOT lty_won — status: " . $log->get_status() );
		}
	}
	t_ok( 9  === $won_pct, "F5a all 9 ticket_pct logs lty_won (got $won_pct)" );
	t_ok( 10 === $won_iw,  "F5b all 10 woo_wallet logs lty_won (got $won_iw)" );
	t_ok( 19 === ( $won_pct + $won_iw ), "F5c total 19/19 logs lty_won (got " . ( $won_pct + $won_iw ) . ")" );

	// F6: Zero leftover hold posts.
	$holds_full = e2e_count_hold_posts( $lid_full );
	t_ok( 0 === $holds_full, "F6 zero leftover nera_prize_hold posts (got $holds_full)" );

	// F7: Wallet balance delta = 50 (10 prizes × £5 each).
	$wallet_after_full = (float) woo_wallet()->wallet->get_wallet_balance( $uid_full, 'edit' );
	$wallet_delta_full = $wallet_after_full - $wallet_before_full;
	t_ok( abs( $wallet_delta_full - 50.0 ) < 0.01, "F7 wallet delta = +50.00 (got +$wallet_delta_full)" );

	// F8: Buyer holds all 19 prize ticket numbers.
	$buyer_nums_full = e2e_get_buyer_ticket_numbers( $lid_full, $uid_full );
	$buyer_set_full  = array_flip( $buyer_nums_full );
	$missing_prize_nums = array();
	foreach ( range( 1, 9 ) as $i ) {
		$n = $i * 1000;
		if ( ! isset( $buyer_set_full[ $n ] ) ) {
			$missing_prize_nums[] = $n;
		}
	}
	foreach ( range( 9991, 10000 ) as $n ) {
		if ( ! isset( $buyer_set_full[ $n ] ) ) {
			$missing_prize_nums[] = $n;
		}
	}
	t_ok( empty( $missing_prize_nums ), 'F8 buyer holds all 19 prize ticket numbers (missing: ' . implode( ', ', $missing_prize_nums ) . ')' );

	WP_CLI::log( '' );
	WP_CLI::log( '  Total purchased:    ' . $total_purchased );
	WP_CLI::log( '  Logs lty_won:       ' . ( $won_pct + $won_iw ) . ' / 19' );
	WP_CLI::log( '  Wallet delta:       +' . $wallet_delta_full );
	WP_CLI::log( '  Hold posts left:    ' . $holds_full );
	WP_CLI::log( '  Missing prize nums: ' . ( empty( $missing_prize_nums ) ? 'none' : implode( ', ', $missing_prize_nums ) ) );

} catch ( \Throwable $e ) {
	$run_failed = true;
	WP_CLI::log( '' );
	WP_CLI::log( '!! EXCEPTION: ' . $e->getMessage() );
	WP_CLI::log( $e->getTraceAsString() );
} finally {
	// -----------------------------------------------------------------------
	// Teardown — force delete every tracked fixture.
	// -----------------------------------------------------------------------
	t_section( 'Teardown' );
	nera_iwt_clear_order_generation_projection();

	$deleted = 0;
	// Orders.
	foreach ( array_unique( $GLOBALS['t_orders'] ) as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
			++$deleted;
		}
	}

	// Posts (products, rules, logs; ticket posts are children of the product).
	foreach ( array_unique( $GLOBALS['t_posts'] ) as $pid ) {
		delete_transient( 'lty_purchased_ticket_count_' . $pid );
		if ( get_post( $pid ) ) {
			wp_delete_post( $pid, true );
			++$deleted;
		}
	}

	// Tickets parented to our lotteries — sweep via SQL for speed.
	// We delete them via SQL because wp_delete_post() on thousands is slow.
	global $wpdb;
	// Find all lotteries we created (by name prefix).
	$lottery_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_title LIKE 'NERA_IWT_E2E_%'
		   AND post_type IN ('product','lty_instant_winners','lty_ins_winner_log')"
	);
	// Also check the product table.
	$product_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_title LIKE 'NERA_IWT_E2E_%'
		   AND post_type = 'product'"
	);
	foreach ( $product_ids as $lid ) {
		// Delete tickets.
		$tids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_parent = %d
				   AND post_type = 'lty_lottery_ticket'",
				(int) $lid
			)
		);
		if ( $tids ) {
			$in = implode( ',', array_map( 'intval', $tids ) );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
			$wpdb->query( "DELETE FROM {$wpdb->posts}    WHERE ID IN ($in)" );
			$deleted += count( $tids );
		}
	}

	// Users.
	foreach ( array_unique( $GLOBALS['t_users'] ) as $uid ) {
		if ( get_user_by( 'ID', $uid ) ) {
			wp_delete_user( $uid );
			++$deleted;
		}
	}

	WP_CLI::log( "  removed $deleted fixture objects" );

	// Leak check: no NERA_IWT_E2E_* posts should remain.
	$leak = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'NERA_IWT_E2E_%'"
	);
	t_ok( 0 === $leak, "Leak check: no NERA_IWT_E2E_* posts remain (found $leak)" );

	// Leak check: no nera_prize_hold posts for our lotteries.
	$hold_leak = 0;
	foreach ( $product_ids as $lid ) {
		$hold_leak += e2e_count_hold_posts( (int) $lid );
	}
	t_ok( 0 === $hold_leak, "Leak check: no nera_prize_hold posts remain (found $hold_leak)" );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '════════════════════════════════════════' );
WP_CLI::log( sprintf( ' RESULT: %d passed, %d failed', $GLOBALS['t_pass'], $GLOBALS['t_fail'] ) );
WP_CLI::log( '════════════════════════════════════════' );

if ( $run_failed || $GLOBALS['t_fail'] > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::halt( 0 );
