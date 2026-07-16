<?php
/**
 * Held-back prizes — end-of-competition draw remedy (Phase 4, admin-reviewed).
 *
 * When a competition finishes with a held-back prize still unwon — either it was activated
 * onto a number that no customer ever bought, or it could not be placed at all — the prize
 * must still be awarded (UK compliance). Rather than award automatically, this layer:
 *
 *  1. On close ({@see lty_lottery_product_after_finished}) flags each unwon held prize as
 *     'needs a draw' and emails the admin.
 *  2. Exposes a "Run draw" admin control that draws a random SOLD ticket and awards the prize
 *     to that entrant through LFW's own instant-winner-log award path.
 *
 * A human reviews before any real prize is awarded.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the end-of-competition DRAW remedy (Needs-draw → Run draw → Drawn) is active.
 *
 * TEMPORARILY OFF by default (feature parked). Re-enable later with
 * define( 'NERA_IWT_ENABLE_HELD_DRAW', true ); in wp-config.php (before plugins load), or the filter.
 * When off: comp close does NOT flag prizes for a draw, Run draw is refused, and the
 * Needs-draw / Drawn statuses never render (a legacy 'drawn' prize shows as Won).
 */
if ( ! defined( 'NERA_IWT_ENABLE_HELD_DRAW' ) ) {
	define( 'NERA_IWT_ENABLE_HELD_DRAW', false );
}

/**
 * @return bool Whether the held-prize end-of-competition draw remedy is enabled.
 */
function nera_iwt_held_draw_enabled() {
	$on = defined( 'NERA_IWT_ENABLE_HELD_DRAW' ) && NERA_IWT_ENABLE_HELD_DRAW;
	return (bool) apply_filters( 'nera_iwt_held_draw_enabled', $on );
}

/**
 * Held-prize rules on a product that are unwon at close and therefore need a draw:
 * activated-but-never-bought ('active' with no winner) or 'unplaceable'.
 *
 * @param WC_Product $product Lottery product.
 * @return int[] Rule IDs.
 */
function nera_iwt_held_needs_draw_rule_ids( $product ) {
	$out = array();
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return $out;
	}

	foreach ( (array) lty_get_instant_winner_rule_ids( $product->get_id() ) as $rid ) {
		$rid = absint( $rid );
		if ( $rid <= 0 ) {
			continue;
		}
		if ( NERA_IWT_RULE_TYPE_HELD !== (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true ) ) {
			continue;
		}
		$state = (string) get_post_meta( $rid, 'nera_iwt_held_state', true );
		if ( 'active' !== $state && 'unplaceable' !== $state ) {
			continue;
		}
		if ( function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rid ) ) {
			continue; // already won on its number — no remedy needed.
		}
		$out[] = $rid;
	}

	return $out;
}

/**
 * On competition close, flag unwon held prizes for a draw and email the admin.
 *
 * @param int $lottery_product_id Lottery product ID.
 * @return void
 */
function nera_iwt_held_flag_draws_on_close( $lottery_product_id ) {
	if ( ! nera_iwt_held_draw_enabled() ) {
		return; // draw remedy parked — leave unwon Live prizes as-is on close.
	}
	$product = wc_get_product( absint( $lottery_product_id ) );
	if ( ! $product instanceof WC_Product || 'lottery' !== $product->get_type() ) {
		return;
	}

	$rule_ids = nera_iwt_held_needs_draw_rule_ids( $product );
	if ( empty( $rule_ids ) ) {
		return;
	}

	foreach ( $rule_ids as $rid ) {
		update_post_meta( $rid, 'nera_iwt_held_needs_draw', 1 );
	}

	nera_iwt_held_email_draw_needed( $product, $rule_ids );

	/**
	 * Fires when held prizes are flagged for an end-of-competition draw.
	 *
	 * @param WC_Product $product  Lottery product.
	 * @param int[]      $rule_ids Rule IDs needing a draw.
	 */
	do_action( 'nera_iwt_held_draws_flagged', $product, $rule_ids );
}
add_action( 'lty_lottery_product_after_finished', 'nera_iwt_held_flag_draws_on_close' );

/**
 * Draw a random sold-ticket winner for a held prize and award it via LFW's award path.
 *
 * @param int $rule_id Rule post ID.
 * @return array{rule_id:int,ticket_number:string,user:string}|WP_Error
 */
function nera_iwt_run_held_draw( $rule_id ) {
	if ( ! nera_iwt_held_draw_enabled() ) {
		return new WP_Error( 'nera_iwt_held_draw_disabled', __( 'The end-of-competition draw is currently disabled.', 'nera-instant-win-threshold' ) );
	}
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 ) {
		return new WP_Error( 'nera_iwt_held_bad_rule', __( 'Invalid prize rule.', 'nera-instant-win-threshold' ) );
	}

	if ( NERA_IWT_RULE_TYPE_HELD !== (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true ) ) {
		return new WP_Error( 'nera_iwt_held_not_held', __( 'This prize is not a held-back prize.', 'nera-instant-win-threshold' ) );
	}

	if ( function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rule_id ) ) {
		return new WP_Error( 'nera_iwt_held_already_won', __( 'This prize already has a winner.', 'nera-instant-win-threshold' ) );
	}

	$product = nera_iwt_held_get_rule_product( $rule_id );
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'nera_iwt_held_no_product', __( 'Could not load the competition for this prize.', 'nera-instant-win-threshold' ) );
	}

	if ( ! function_exists( 'lty_get_ticket_ids' ) || ! function_exists( 'lty_get_instant_winner_log_id_by_rule_id' ) || ! function_exists( 'lty_get_instant_winner_log' ) ) {
		return new WP_Error( 'nera_iwt_held_lfw_missing', __( 'Lottery for WooCommerce functions are unavailable.', 'nera-instant-win-threshold' ) );
	}

	$relist    = is_callable( array( $product, 'get_current_relist_count' ) ) ? (int) $product->get_current_relist_count() : 0;
	$ticket_ids = lty_get_ticket_ids(
		array(
			'product_id'  => $product->get_id(),
			'post_status' => array( 'lty_ticket_buyer' ),
			'list_count'  => $relist,
			'limit'       => '-1',
		)
	);
	$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
	if ( empty( $ticket_ids ) ) {
		return new WP_Error( 'nera_iwt_held_no_entrants', __( 'No sold tickets are available to draw a winner from.', 'nera-instant-win-threshold' ) );
	}

	$ticket_id = $ticket_ids[ wp_rand( 0, count( $ticket_ids ) - 1 ) ];
	$ticket    = lty_get_lottery_ticket( $ticket_id );
	if ( ! is_object( $ticket ) ) {
		return new WP_Error( 'nera_iwt_held_bad_ticket', __( 'Could not load the drawn ticket.', 'nera-instant-win-threshold' ) );
	}

	$log_id = lty_get_instant_winner_log_id_by_rule_id( $rule_id, $relist );
	if ( ! $log_id ) {
		return new WP_Error( 'nera_iwt_held_no_log', __( 'Could not find the prize log to award.', 'nera-instant-win-threshold' ) );
	}

	$order_id = (int) ( method_exists( $ticket, 'get_order_id' ) ? $ticket->get_order_id() : 0 );
	if ( $order_id <= 0 && method_exists( $ticket, 'get_order' ) ) {
		$o        = $ticket->get_order();
		$order_id = is_object( $o ) ? (int) $o->get_id() : 0;
	}
	$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

	// Populate the winner identity on the log (mirrors LTY_Order_Handler::declare_instant_winner).
	lty_update_instant_winner_log(
		$log_id,
		array(
			'lty_ticket_id'     => $ticket_id,
			'lty_order_id'      => $order_id,
			'lty_user_id'       => $ticket->get_user_id(),
			'lty_user_name'     => $ticket->get_user_name(),
			'lty_user_email'    => $ticket->get_user_email(),
			'lty_ticket_number' => $ticket->get_lottery_ticket_number(),
		)
	);

	$log = lty_get_instant_winner_log( $log_id );
	if ( ! is_object( $log ) ) {
		return new WP_Error( 'nera_iwt_held_no_log', __( 'Could not load the prize log to award.', 'nera-instant-win-threshold' ) );
	}

	// Mark won and grant the prize through LFW's own assignment (coupon / product / physical).
	if ( method_exists( $log, 'update_status' ) ) {
		$log->update_status( 'lty_won' );
	}
	if ( is_object( $order ) && method_exists( $log, 'assign_winning_prize' ) ) {
		$log->assign_winning_prize( $order );
	}

	// Reflect the draw on the rule and stop advertising / re-drawing it.
	update_post_meta( $rule_id, 'lty_ticket_number', (string) $ticket->get_lottery_ticket_number() );
	update_post_meta( $rule_id, 'nera_iwt_held_number', (string) $ticket->get_lottery_ticket_number() );
	update_post_meta( $rule_id, 'nera_iwt_held_state', 'drawn' );
	delete_post_meta( $rule_id, 'nera_iwt_held_needs_draw' );

	if ( function_exists( 'nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule' ) ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id );
	}

	$winner = trim( (string) $ticket->get_user_name() );
	if ( '' === $winner ) {
		$winner = (string) $ticket->get_user_email();
	}

	nera_iwt_held_email_draw_result( $product, $rule_id, $ticket->get_lottery_ticket_number(), $winner );

	/**
	 * Fires after a held-prize end-of-competition draw awards a winner.
	 *
	 * @param int    $rule_id   Rule post ID.
	 * @param int    $ticket_id Drawn ticket ID.
	 * @param int    $log_id    Instant-winner log ID.
	 */
	do_action( 'nera_iwt_held_draw_awarded', $rule_id, $ticket_id, $log_id );

	return array(
		'rule_id'       => $rule_id,
		'ticket_number' => (string) $ticket->get_lottery_ticket_number(),
		'user'          => $winner,
	);
}

// ---------------------------------------------------------------------------
// EMAIL
// ---------------------------------------------------------------------------

/**
 * Email the admin that held prizes need an end-of-competition draw.
 *
 * @param WC_Product $product  Lottery product.
 * @param int[]      $rule_ids Rule IDs needing a draw.
 * @return void
 */
function nera_iwt_held_email_draw_needed( $product, array $rule_ids ) {
	$to = function_exists( 'nera_iwt_held_email_recipient' ) ? nera_iwt_held_email_recipient() : get_option( 'admin_email' );
	if ( '' === (string) $to ) {
		return;
	}

	$name = $product->get_name();
	/* translators: %s: competition name */
	$subject = sprintf( __( '[Held prizes] Draw needed after %s closed', 'nera-instant-win-threshold' ), $name );
	$body    = sprintf(
		/* translators: 1: competition name, 2: count */
		__( "The %1\$s competition has closed with %2\$d held-back prize(s) still unwon.\n\nOpen the product and use \"Run draw\" on each held prize to draw a random winner from the sold tickets.", 'nera-instant-win-threshold' ),
		$name,
		count( $rule_ids )
	);

	wp_mail( $to, $subject, $body );
}

/**
 * Email the admin the result of a held-prize draw.
 *
 * @param WC_Product $product       Lottery product.
 * @param int        $rule_id       Rule post ID.
 * @param string     $ticket_number Winning ticket number.
 * @param string     $winner        Winner name/email.
 * @return void
 */
function nera_iwt_held_email_draw_result( $product, $rule_id, $ticket_number, $winner ) {
	$to = function_exists( 'nera_iwt_held_email_recipient' ) ? nera_iwt_held_email_recipient() : get_option( 'admin_email' );
	if ( '' === (string) $to ) {
		return;
	}

	$name = $product->get_name();
	/* translators: %s: competition name */
	$subject = sprintf( __( '[Held prizes] Draw completed for %s', 'nera-instant-win-threshold' ), $name );
	$body    = sprintf(
		/* translators: 1: competition name, 2: ticket number, 3: winner */
		__( 'A held-back prize on %1$s was drawn. Winning ticket: %2$s. Winner: %3$s.', 'nera-instant-win-threshold' ),
		$name,
		$ticket_number,
		$winner
	);

	wp_mail( $to, $subject, $body );
}

// ---------------------------------------------------------------------------
// ADMIN AJAX
// ---------------------------------------------------------------------------

/**
 * AJAX: run an end-of-competition draw for a held prize.
 *
 * @return void
 */
function nera_iwt_ajax_run_held_draw() {
	check_ajax_referer( 'nera_iwt_activate_held', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'error' => __( 'You do not have permission to do this.', 'nera-instant-win-threshold' ) ) );
	}

	$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

	$result = nera_iwt_run_held_draw( $rule_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'error' => $result->get_error_message() ) );
	}

	$result['status'] = 'won';
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nera_iwt_run_held_draw', 'nera_iwt_ajax_run_held_draw' );
