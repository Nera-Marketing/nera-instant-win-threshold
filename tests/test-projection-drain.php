<?php
/**
 * Integration test — instant-win threshold projection + drain fix (v1.0.28).
 *
 * Proves the guarantee "buy the whole pool -> receive every prize number" at the
 * ticket-generator level (which is what makes the buyer win), plus the safety
 * property that prizes stay locked when there is no order projection.
 *
 * Run:
 *   SOCK="$HOME/Library/Application Support/Local/run/eSYfFlERw/mysql/mysqld.sock"
 *   php -d mysqli.default_socket=$SOCK -d pdo_mysql.default_socket=$SOCK \
 *     /opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp \
 *     eval-file wp-content/plugins/nera-instant-win-threshold/tests/test-projection-drain.php
 *
 * Builds only temporary fixtures and force-deletes them in teardown (try/finally).
 * Idempotent — safe to re-run. Exits nonzero if any assertion fails.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file <this file>\n" );

if ( ! class_exists( 'WC_Product_Lottery' ) || ! function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ) {
	WP_CLI::error( 'WooCommerce + Lottery for WooCommerce + Nera Instant Win Threshold must be active.' );
}

// ---------------------------------------------------------------------------
// Tiny assertion harness
// ---------------------------------------------------------------------------
$GLOBALS['t_pass']     = 0;
$GLOBALS['t_fail']     = 0;
$GLOBALS['t_posts']    = array(); // tracked post IDs (products, rules, tickets)
$GLOBALS['t_orders']   = array(); // tracked order IDs

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

// ---------------------------------------------------------------------------
// Fixture builders
// ---------------------------------------------------------------------------

/**
 * Create a temporary automatic lottery product with a fixed numeric pool 1..N.
 *
 * @param int    $n           Maximum tickets / pool max.
 * @param string $number_type LFW ticket number type: '3' shuffle, '1' random.
 * @return int Product ID.
 */
function t_make_product( $n, $number_type = '3' ) {
	$p = new WC_Product_Lottery();
	$p->set_name( 'NERA_IWT_TEST_PRODUCT' );
	$p->set_status( 'publish' );
	$p->set_catalog_visibility( 'hidden' );
	$p->set_lty_maximum_tickets( $n );
	$p->set_lty_ticket_generation_type( '1' ); // automatic
	$p->set_lty_ticket_number_type( $number_type );
	$p->set_lty_instant_winners( 'yes' );
	// Pool ceiling for shuffle/random generation = fixed 1..N.
	$p->update_meta_data( '_nera_iwt_ticket_number_max', $n );
	// Date window (only relevant to DB count; we override via transient anyway).
	$p->update_meta_data( '_lty_start_date_gmt', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
	$p->update_meta_data( '_lty_end_date_gmt', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
	$id = $p->save();
	t_track( $id );
	return (int) $id;
}

/**
 * Create a temporary instant-win rule attached to a lottery.
 *
 * @param int    $lottery_id Lottery (= product) ID.
 * @param int    $number     Prize ticket number.
 * @param string $type       'ticket_pct' | 'instant'.
 * @param int    $pct        Threshold percent for ticket_pct.
 * @return int Rule ID.
 */
function t_make_rule( $lottery_id, $number, $type = 'ticket_pct', $pct = 0 ) {
	$rid = wp_insert_post(
		array(
			'post_type'   => 'lty_instant_winners',
			'post_status' => 'publish',
			'post_parent' => $lottery_id,
			'post_title'  => 'NERA_IWT_TEST_RULE_' . $number,
		)
	);
	if ( ! $rid || is_wp_error( $rid ) ) {
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
 * Seed "already sold" buyer tickets for the given numbers, and pin the purchased
 * count transient so get_purchased_ticket_count() reports exactly that many.
 *
 * @param int   $lottery_id Lottery (= product) ID.
 * @param int[] $numbers    Ticket numbers to mark sold (must avoid prize numbers).
 * @return void
 */
function t_seed_sold( $lottery_id, array $numbers ) {
	foreach ( $numbers as $n ) {
		$tid = lty_create_new_lottery_ticket(
			array( 'lty_ticket_number' => (string) $n ),
			array(
				'post_parent' => $lottery_id,
				'post_status' => 'lty_ticket_buyer',
			)
		);
		t_track( $tid );
	}
	set_transient( 'lty_purchased_ticket_count_' . $lottery_id, count( $numbers ), HOUR_IN_SECONDS );
}

/**
 * Seed the request-local generation projection the real way: a genuine WC order
 * with the product at the given quantity, fed through the patched setter.
 *
 * @param int $product_id Product ID.
 * @param int $qty        In-flight quantity.
 * @return void
 */
function t_project( $product_id, $qty ) {
	$order   = wc_create_order();
	$product = wc_get_product( $product_id );
	$order->add_product( $product, $qty );
	$order->save();
	$GLOBALS['t_orders'][] = (int) $order->get_id();
	$map = nera_iwt_set_order_generation_projection( $order );
	// Sanity: setter must have recorded this product's quantity.
	t_ok( isset( $map[ $product_id ] ) && (int) $map[ $product_id ] === (int) $qty, "projection map recorded product#$product_id qty=$qty (via real order + nera_iwt_get_order_projected_quantities)" );
}

/** Reset object cache so fresh placed-ticket / meta queries are seen. */
function t_fresh_product( $id ) {
	wp_cache_flush();
	return wc_get_product( $id );
}

/** Map generator output to a set of int ticket numbers. */
function t_set( $nums ) {
	$out = array();
	foreach ( (array) $nums as $n ) {
		$out[ (int) $n ] = true;
	}
	return $out;
}

// ===========================================================================
// RUN
// ===========================================================================
$run_failed = false;
try {

	// -----------------------------------------------------------------------
	// CASES A / B / C — N=20, prizes #18 (90%) and #20 (99%), pre-sold 16 (80%)
	// -----------------------------------------------------------------------
	$N   = 20;
	$pid = t_make_product( $N, '3' );
	$lid = (int) lty_get_lottery_id( $pid );
	t_make_rule( $lid, 18, 'ticket_pct', 90 );
	t_make_rule( $lid, 20, 'ticket_pct', 99 );
	t_seed_sold( $lid, range( 1, 16 ) ); // unsold = {17,18,19,20}

	t_section( 'A. Unavailability projection (sold 80%)' );
	$pA = t_fresh_product( $pid );
	$uA0 = t_set( nera_iwt_get_unavailable_prize_ticket_numbers( $pA, 0 ) );
	t_ok( isset( $uA0[18] ) && isset( $uA0[20] ), 'A1 extra_sold=0 -> #18 & #20 HELD (reproduces old stuck state)' );
	$uA4 = t_set( nera_iwt_get_unavailable_prize_ticket_numbers( $pA, 4 ) );
	t_ok( empty( $uA4 ), 'A2 extra_sold=4 -> projected 100% releases BOTH (unavailable set empty)' );

	t_section( 'B. Generator drain WITH projection (qty=4) — must include held prizes' );
	t_project( $pid, 4 );
	$pB  = t_fresh_product( $pid );
	$bSh = t_set( lty_get_remaining_shuffle_ticket_numbers( $pB, 4 ) );
	t_ok( count( $bSh ) === 4 && isset( $bSh[17], $bSh[18], $bSh[19], $bSh[20] ), 'B1 shuffle returns all 4 unsold incl prizes #18 & #20 (no shortfall)' );
	t_project( $pid, 4 ); // re-seed (cleared between, harmless)
	$pB2 = t_fresh_product( $pid );
	$bRn = t_set( lty_get_random_ticket_numbers( $pB2, 4 ) );
	t_ok( count( $bRn ) === 4 && isset( $bRn[17], $bRn[18], $bRn[19], $bRn[20] ), 'B2 random returns all 4 unsold incl prizes (Part B exact-drain)' );
	nera_iwt_clear_order_generation_projection();

	t_section( 'C. Safety — NO projection (fallback 0) — prizes must stay locked' );
	nera_iwt_clear_order_generation_projection();
	$pC  = t_fresh_product( $pid );
	$cSh = t_set( lty_get_remaining_shuffle_ticket_numbers( $pC, 4 ) );
	t_ok( ! isset( $cSh[18] ) && ! isset( $cSh[20] ) && count( $cSh ) === 2, 'C1 shuffle excludes #18 & #20, returns only {17,19} (no premature release)' );
	$cRn = t_set( lty_get_random_ticket_numbers( $pC, 4 ) );
	t_ok( ! isset( $cRn[18] ) && ! isset( $cRn[20] ) && count( $cRn ) === 2, 'C2 random excludes #18 & #20, returns only {17,19}' );

	// -----------------------------------------------------------------------
	// CASE D — N=10000, 1000 prizes, buy-all single shot (the headline)
	// -----------------------------------------------------------------------
	t_section( 'D. Buy-all N=10000, 1000 prizes (5 ticket_pct + 995 instant)' );
	$ND   = 10000;
	$pidD = t_make_product( $ND, '3' );
	$lidD = (int) lty_get_lottery_id( $pidD );

	$pct_prizes = array( 2500 => 25, 5000 => 50, 7500 => 75, 9000 => 90, 9900 => 99 );
	$prize_nums = array();
	for ( $num = 10; $num <= $ND; $num += 10 ) { // 1000 distinct numbers
		if ( isset( $pct_prizes[ $num ] ) ) {
			t_make_rule( $lidD, $num, 'ticket_pct', $pct_prizes[ $num ] );
		} else {
			t_make_rule( $lidD, $num, 'instant', 0 );
		}
		$prize_nums[] = $num;
	}
	set_transient( 'lty_purchased_ticket_count_' . $lidD, 0, HOUR_IN_SECONDS );
	t_ok( count( lty_get_instant_winner_rule_ids( $lidD ) ) === 1000, 'D0 created 1000 instant-win rules' );

	// D1 — with projection qty=10000: all 10000 numbers incl every prize.
	t_project( $pidD, $ND );
	$pD1 = t_fresh_product( $pidD );
	$d1  = lty_get_remaining_shuffle_ticket_numbers( $pD1, $ND );
	$d1set = t_set( $d1 );
	$missing1 = array();
	foreach ( $prize_nums as $pn ) {
		if ( ! isset( $d1set[ $pn ] ) ) {
			$missing1[] = $pn;
		}
	}
	t_ok( count( $d1 ) === $ND, 'D1 shuffle returns all 10000 numbers (no shortfall)' );
	t_ok( empty( $missing1 ), 'D1 shuffle output contains ALL 1000 prize numbers (buyer would win all)' );
	nera_iwt_clear_order_generation_projection();

	// D2 — no projection: ticket_pct prizes excluded -> 9995 returned, 5 missing.
	$pD2 = t_fresh_product( $pidD );
	$d2  = lty_get_remaining_shuffle_ticket_numbers( $pD2, $ND );
	$d2set = t_set( $d2 );
	$pct_absent  = ! isset( $d2set[2500], $d2set[5000], $d2set[7500], $d2set[9000], $d2set[9900] );
	$instant_present = isset( $d2set[10], $d2set[20], $d2set[30] ); // sample instant prizes
	t_ok( count( $d2 ) === $ND - 5, 'D2 NO projection -> 9995 numbers (5 ticket_pct prizes held back)' );
	t_ok( $pct_absent, 'D2 the 5 ticket_pct prize numbers are ABSENT without projection (proves projection is what releases them)' );
	t_ok( $instant_present, 'D2 instant prizes still present (never held)' );

	// D3 — random type, with projection: same guarantee.
	t_project( $pidD, $ND );
	$pD3 = t_fresh_product( $pidD );
	$d3  = lty_get_random_ticket_numbers( $pD3, $ND );
	$d3set = t_set( $d3 );
	$missing3 = array();
	foreach ( $prize_nums as $pn ) {
		if ( ! isset( $d3set[ $pn ] ) ) {
			$missing3[] = $pn;
		}
	}
	t_ok( count( $d3 ) === $ND && empty( $missing3 ), 'D3 random returns all 10000 incl every prize (Part B drain at scale)' );
	nera_iwt_clear_order_generation_projection();

	// -----------------------------------------------------------------------
	// CASE E — split buy-all (N=100): held at 60%, released+drained in final batch
	// -----------------------------------------------------------------------
	t_section( 'E. Split buy-all N=100: prizes #75/#90/#99 held at 60%, released by projected final 40' );
	$NE   = 100;
	$pidE = t_make_product( $NE, '3' );
	$lidE = (int) lty_get_lottery_id( $pidE );
	t_make_rule( $lidE, 75, 'ticket_pct', 75 );
	t_make_rule( $lidE, 90, 'ticket_pct', 90 );
	t_make_rule( $lidE, 99, 'ticket_pct', 99 );
	// Pre-sell 60 non-prize numbers (1..74 minus prizes, capped at 60).
	$seedE = array();
	for ( $n = 1; $n <= 100 && count( $seedE ) < 60; $n++ ) {
		if ( in_array( $n, array( 75, 90, 99 ), true ) ) {
			continue;
		}
		$seedE[] = $n;
	}
	t_seed_sold( $lidE, $seedE ); // sold = 60 -> 60%

	$pE = t_fresh_product( $pidE );
	$uE = t_set( nera_iwt_get_unavailable_prize_ticket_numbers( $pE, 0 ) );
	t_ok( isset( $uE[75], $uE[90], $uE[99] ), 'E1 at 60% (extra_sold=0): #75/#90/#99 all HELD' );

	$remainingE = $NE - count( $seedE ); // 40
	t_project( $pidE, $remainingE );
	$pE2 = t_fresh_product( $pidE );
	$eOut = lty_get_remaining_shuffle_ticket_numbers( $pE2, $remainingE );
	$eSet = t_set( $eOut );
	t_ok( count( $eOut ) === $remainingE && isset( $eSet[75], $eSet[90], $eSet[99] ), 'E2 projected final batch drains all 40 remaining incl #75/#90/#99 (split sellout wins all)' );
	nera_iwt_clear_order_generation_projection();

} catch ( \Throwable $e ) {
	$run_failed = true;
	WP_CLI::log( '' );
	WP_CLI::log( '!! EXCEPTION: ' . $e->getMessage() );
	WP_CLI::log( $e->getTraceAsString() );
} finally {
	// -----------------------------------------------------------------------
	// Teardown — force delete every tracked fixture + transients + projection.
	// -----------------------------------------------------------------------
	t_section( 'Teardown' );
	nera_iwt_clear_order_generation_projection();
	$deleted = 0;
	foreach ( array_unique( $GLOBALS['t_orders'] ) as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
			++$deleted;
		}
	}
	foreach ( array_unique( $GLOBALS['t_posts'] ) as $pid ) {
		// Clear pinned transient if this was a product (harmless otherwise).
		delete_transient( 'lty_purchased_ticket_count_' . $pid );
		if ( get_post( $pid ) ) {
			wp_delete_post( $pid, true );
			++$deleted;
		}
	}
	WP_CLI::log( "  removed $deleted fixture objects" );

	// Leak check: no NERA_IWT_TEST_* posts should remain.
	global $wpdb;
	$leak = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'NERA_IWT_TEST_%'"
	);
	t_ok( 0 === $leak, "no leftover NERA_IWT_TEST_* posts (found $leak)" );
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
