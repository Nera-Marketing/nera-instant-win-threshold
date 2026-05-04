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
