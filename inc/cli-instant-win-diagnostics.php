<?php
/**
 * WP-CLI helpers to diagnose instant-winner visibility (admin list + storefront).
 *
 * Usage: wp nera-iwt inspect-log 967
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI', false ) ) {
	return;
}

/**
 * @param array<int, string> $args Positional args.
 */
function nera_iwt_cli_inspect_instant_log( array $args ) {
	$log_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
	if ( $log_id <= 0 ) {
		WP_CLI::error( 'Usage: wp nera-iwt inspect-log <post_id>' );
	}

	$post = get_post( $log_id );
	if ( ! $post ) {
		WP_CLI::error( sprintf( 'No post found for ID %d.', $log_id ) );
	}

	$log_pt  = class_exists( 'LTY_Register_Post_Types', false ) ? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_LOG_POSTTYPE : 'lty_ins_winner_log';
	$rule_pt = class_exists( 'LTY_Register_Post_Types', false ) ? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE : 'lty_instant_winners';

	WP_CLI::log( '--- Post row ---' );
	WP_CLI::log( sprintf( 'ID: %d', (int) $post->ID ) );
	WP_CLI::log( sprintf( 'post_type: %s', $post->post_type ) );
	WP_CLI::log( sprintf( 'post_status: %s', $post->post_status ) );
	WP_CLI::log( sprintf( 'post_parent (rule id for logs): %d', (int) $post->post_parent ) );

	if ( $rule_pt === $post->post_type ) {
		WP_CLI::warning(
			'This post is an instant-winner **rule** (`lty_instant_winners`), not a log. The product “Instant Win Prizes” **log table** lists `lty_ins_winner_log` rows only. Open the rule row to see its linked log ID, or inspect the log post instead.'
		);
	}

	if ( $log_pt !== $post->post_type ) {
		WP_CLI::warning( sprintf( 'Expected log post_type `%s` for the LFW admin log list.', $log_pt ) );
	}

	$lottery_id = get_post_meta( $log_id, 'lty_lottery_id', true );
	$relist     = get_post_meta( $log_id, 'lty_current_relist_count', true );

	WP_CLI::log( '--- Post meta (LFW list filters) ---' );
	WP_CLI::log( sprintf( 'lty_lottery_id: %s', '' === (string) $lottery_id ? '(empty)' : (string) $lottery_id ) );
	WP_CLI::log( sprintf( 'lty_current_relist_count: %s', '' === (string) $relist && ! is_numeric( $relist ) ? '(missing / empty)' : (string) $relist ) );

	$statuses = function_exists( 'lty_get_instant_winner_log_statuses' ) ? lty_get_instant_winner_log_statuses() : array();
	WP_CLI::log( 'Allowed log post_status values: ' . implode( ', ', $statuses ) );
	if ( ! in_array( $post->post_status, $statuses, true ) ) {
		WP_CLI::warning( 'post_status is NOT in LFW log statuses — the admin list hides this row when filtering by status.' );
	}

	$pid = absint( $lottery_id );
	if ( $pid <= 0 ) {
		WP_CLI::warning( 'Cannot compare relist: lty_lottery_id is empty or invalid.' );
		WP_CLI::success( 'Inspect complete.' );
		return;
	}

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
	if ( ! $product || ! $product->exists() ) {
		WP_CLI::warning( sprintf( 'No WC product for lty_lottery_id=%d.', $pid ) );
		WP_CLI::success( 'Inspect complete.' );
		return;
	}

	$expected_relist = method_exists( $product, 'get_current_relist_count' ) ? (int) $product->get_current_relist_count() : 0;
	WP_CLI::log( sprintf( 'Product #%d get_current_relist_count(): %d', $pid, $expected_relist ) );

	$stored = is_numeric( $relist ) ? (int) $relist : null;
	if ( null === $stored ) {
		WP_CLI::warning(
			'Log is missing numeric `lty_current_relist_count`. LFW admin SQL requires pm1.meta_value = current relist; logs without this meta will not appear.'
		);
	} elseif ( $stored !== $expected_relist ) {
		WP_CLI::warning(
			sprintf(
				'Mismatch: log has lty_current_relist_count=%d but product expects %d for this cycle. Update meta or re-save rules from the product screen.',
				$stored,
				$expected_relist
			)
		);
	} else {
		WP_CLI::log( 'Relist meta matches product current relist count.' );
	}

	if ( function_exists( 'lty_get_instant_winner_log_ids' ) && $log_pt === $post->post_type ) {
		$ids = lty_get_instant_winner_log_ids( $pid, false, $expected_relist, 'all' );
		WP_CLI::log( sprintf( 'lty_get_instant_winner_log_ids( %d, false, %d, all ) returned %d ids.', $pid, $expected_relist, count( $ids ) ) );
		if ( in_array( $log_id, array_map( 'absint', $ids ), true ) ) {
			WP_CLI::success( sprintf( 'ID %d IS included in that query (check admin status filter / pagination / wrong product tab).', $log_id ) );
		} else {
			WP_CLI::warning( sprintf( 'ID %d is NOT returned by lty_get_instant_winner_log_ids — fix meta/status above.', $log_id ) );
		}
	}

	WP_CLI::success( 'Inspect complete.' );
}

WP_CLI::add_command(
	'nera-iwt inspect-log',
	'nera_iwt_cli_inspect_instant_log',
	array(
		'shortdesc' => 'Diagnose why an instant-winner log/rule post may not show in the LFW admin list.',
		'synopsis'  => array(
			array(
				'type'        => 'positional',
				'name'        => 'post_id',
				'description' => 'Log (lty_ins_winner_log) or rule post ID to inspect.',
				'optional'    => false,
			),
		),
	)
);

/**
 * Report fixed pool size, resolved generator max, and held prize numbers.
 *
 * ## OPTIONS
 *
 * [--product-id=<id>]
 * : Lottery product ID.
 *
 * @param array<int, string> $args       Positional.
 * @param array<string, mixed> $assoc_args Flags.
 */
function nera_iwt_cli_pool_status( array $args, array $assoc_args ) {
	unset( $args );
	$product_id = isset( $assoc_args['product-id'] ) ? absint( $assoc_args['product-id'] ) : 0;
	if ( $product_id <= 0 ) {
		WP_CLI::error( 'Usage: wp nera-iwt pool-status --product-id=<lottery_product_id>' );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		WP_CLI::error( sprintf( 'Product %d is not a lottery product.', $product_id ) );
	}

	$pool_n    = function_exists( 'nera_iwt_get_reserve_slots_pool_n' ) ? nera_iwt_get_reserve_slots_pool_n( $product ) : 0;
	$resolved  = function_exists( 'nera_iwt_resolve_shuffle_random_pool_max' ) ? nera_iwt_resolve_shuffle_random_pool_max( $product ) : 0;
	$lfw_max   = method_exists( $product, 'get_lty_maximum_tickets' ) ? (int) $product->get_lty_maximum_tickets() : 0;
	$held      = function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ? nera_iwt_get_unavailable_prize_ticket_numbers( $product ) : array();
	$placed    = method_exists( $product, 'get_placed_tickets' ) ? count( (array) $product->get_placed_tickets() ) : 0;

	WP_CLI::log( sprintf( 'Pool N (LFW maximum): %d', $pool_n ) );
	WP_CLI::log( sprintf( 'Resolved shuffle/random max: %d', $resolved ) );
	WP_CLI::log( sprintf( 'LFW maximum tickets: %d', $lfw_max ) );
	WP_CLI::log( sprintf( 'Placed tickets: %d', $placed ) );
	WP_CLI::log( sprintf( 'Currently held (locked) prize numbers: %d', count( $held ) ) );

	if ( $resolved > $pool_n && $pool_n > 0 ) {
		WP_CLI::warning( 'Resolved max exceeds pool N — legacy +held expansion may still be active (should not happen after reserve-slots fix).' );
	} elseif ( $resolved === $pool_n || ( $pool_n <= 0 && $resolved === $lfw_max ) ) {
		WP_CLI::log( 'Pool ceiling matches reserve-slots expectation (no +held expansion).' );
	}

	WP_CLI::success( 'Pool status complete.' );
}

WP_CLI::add_command( 'nera-iwt pool-status', 'nera_iwt_cli_pool_status' );

/**
 * Validate ticket-% reserve-slots feasibility for a product's rules.
 *
 * ## OPTIONS
 *
 * [--product-id=<id>]
 * : Lottery product ID.
 *
 * @param array<int, string> $args       Positional.
 * @param array<string, mixed> $assoc_args Flags.
 */
function nera_iwt_cli_test_feasibility( array $args, array $assoc_args ) {
	unset( $args );
	$product_id = isset( $assoc_args['product-id'] ) ? absint( $assoc_args['product-id'] ) : 0;
	if ( $product_id <= 0 ) {
		WP_CLI::error( 'Usage: wp nera-iwt test-feasibility --product-id=<lottery_product_id>' );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		WP_CLI::error( sprintf( 'Product %d is not a lottery product.', $product_id ) );
	}

	if ( ! function_exists( 'nera_iwt_validate_product_ticket_pct_reserve_slots' ) ) {
		WP_CLI::error( 'ticket-pool-feasibility.php is not loaded.' );
	}

	$pool_n = nera_iwt_get_reserve_slots_pool_n( $product );
	WP_CLI::log( sprintf( 'Pool N: %d', $pool_n ) );

	$result = nera_iwt_validate_product_ticket_pct_reserve_slots( $product, array() );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}

	// Infeasible probe: 100% on a synthetic pool.
	$probe = nera_iwt_validate_ticket_pct_reserve_slots( max( 1, $pool_n ), 100, 1 );
	if ( ! is_wp_error( $probe ) ) {
		WP_CLI::warning( 'Expected 100% threshold to be rejected — check nera_iwt_validate_ticket_pct_reserve_slots.' );
	} else {
		WP_CLI::log( 'Deadlock guard (100%): ' . $probe->get_error_message() );
	}

	WP_CLI::success( 'All ticket-% rules pass reserve-slots feasibility.' );
}

WP_CLI::add_command( 'nera-iwt test-feasibility', 'nera_iwt_cli_test_feasibility' );

/**
 * Stepwise sellout simulation (reserve-slots) without creating orders.
 *
 * ## OPTIONS
 *
 * [--n=<int>]
 * : Pool size (default 10000).
 *
 * [--prizes=<int>]
 * : Number of instant prizes, evenly spaced ticket numbers in 1..n (default 900).
 *
 * [--thresholds=<list>]
 * : Comma-separated ticket-% thresholds applied to first K prizes (e.g. 50,80,90).
 *
 * @param array<int, string> $args       Positional.
 * @param array<string, mixed> $assoc_args Flags.
 */
function nera_iwt_cli_simulate_sellout( array $args, array $assoc_args ) {
	unset( $args );
	$n       = isset( $assoc_args['n'] ) ? max( 1, (int) $assoc_args['n'] ) : 10000;
	$prizes  = isset( $assoc_args['prizes'] ) ? max( 0, (int) $assoc_args['prizes'] ) : 900;
	$thr_raw = isset( $assoc_args['thresholds'] ) ? (string) $assoc_args['thresholds'] : '';

	$thresholds = array();
	if ( '' !== $thr_raw ) {
		foreach ( explode( ',', $thr_raw ) as $part ) {
			$t = max( 0, min( 100, (int) trim( $part ) ) );
			if ( $t > 0 ) {
				$thresholds[] = $t;
			}
		}
	}

	// Prize ticket numbers evenly inside 1..n; first len(thresholds) prizes use those thresholds.
	$prize_list = array();
	if ( $prizes > 0 ) {
		$step = max( 1, (int) floor( $n / ( $prizes + 1 ) ) );
		for ( $i = 1; $i <= $prizes; $i++ ) {
			$num          = min( $n, $i * $step );
			$idx          = $i - 1;
			$threshold    = isset( $thresholds[ $idx ] ) ? $thresholds[ $idx ] : 0;
			$prize_list[] = array(
				'num'       => $num,
				'threshold' => $threshold,
			);
		}
	}

	$sold_set = array();
	$deadlock = false;

	while ( count( $sold_set ) < $n ) {
		$sold = count( $sold_set );
		$locked_nums = array();
		foreach ( $prize_list as $prize ) {
			$t = (int) $prize['threshold'];
			if ( $t <= 0 ) {
				continue;
			}
			if ( $sold < (int) ceil( ( $t / 100 ) * $n ) ) {
				$locked_nums[ (int) $prize['num'] ] = true;
			}
		}

		$sellable = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			if ( isset( $sold_set[ $i ] ) ) {
				continue;
			}
			if ( isset( $locked_nums[ $i ] ) ) {
				continue;
			}
			$sellable[] = $i;
		}

		if ( empty( $sellable ) ) {
			$deadlock = true;
			break;
		}

		$pick              = $sellable[ array_rand( $sellable ) ];
		$sold_set[ $pick ] = true;
	}

	$assigned_prize = 0;
	foreach ( $prize_list as $prize ) {
		if ( isset( $sold_set[ (int) $prize['num'] ] ) ) {
			++$assigned_prize;
		}
	}

	WP_CLI::log( sprintf( 'Simulated sold: %d / %d', count( $sold_set ), $n ) );
	WP_CLI::log( sprintf( 'Prize numbers sold: %d / %d', $assigned_prize, count( $prize_list ) ) );

	if ( $deadlock ) {
		WP_CLI::warning( sprintf( 'Deadlock at %d sold — infeasible ticket-%% config: %s', count( $sold_set ), $thr_raw ?: '(none)' ) );
		return;
	}

	if ( count( $sold_set ) >= $n && $assigned_prize === count( $prize_list ) ) {
		WP_CLI::success( 'Full sellout: every prize number inside 1..n was sold (fixed pool, no N+held expansion).' );
	} else {
		WP_CLI::warning( 'Simulation ended without assigning every prize number.' );
	}
}

WP_CLI::add_command( 'nera-iwt simulate-sellout', 'nera_iwt_cli_simulate_sellout' );
