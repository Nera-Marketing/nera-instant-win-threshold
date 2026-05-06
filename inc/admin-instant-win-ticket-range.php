<?php
/**
 * Instant-win rule: Ticket Number must fall within the lottery product’s numeric ticket pool.
 *
 * When NERA_IWT_MAX_TICKET_NUMBER > 0 the upper bound is that constant; otherwise it matches
 * Lottery for WooCommerce: effective starting number through starting + maximum tickets − 1.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ticket starting number used for pool bounds (automatic vs manual).
 *
 * Mirrors LFW (`get_ticket_start_number`, `get_automatic_ticket_start_number`), including 0 or any configured start.
 *
 * @param WC_Product $product Lottery product.
 * @return int
 */
function nera_iwt_get_effective_ticket_start_for_validation( $product ) {
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		return 1;
	}

	if ( method_exists( $product, 'is_manual_ticket' ) && $product->is_manual_ticket() && method_exists( $product, 'get_ticket_start_number' ) ) {
		return (int) call_user_func( array( $product, 'get_ticket_start_number' ) );
	}

	if ( method_exists( $product, 'get_automatic_ticket_start_number' ) ) {
		return (int) $product->get_automatic_ticket_start_number();
	}

	return 1;
}

/**
 * Inclusive upper bound for numeric instant-win ticket numbers.
 *
 * @param WC_Product $product Lottery product.
 * @return int At least the starting number.
 */
function nera_iwt_get_instant_win_ticket_upper_bound( $product ) {
	$start = nera_iwt_get_effective_ticket_start_for_validation( $product );

	if ( defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) && NERA_IWT_MAX_TICKET_NUMBER > 0 ) {
		return max( $start, (int) NERA_IWT_MAX_TICKET_NUMBER );
	}

	$max_tickets = 0;
	if ( method_exists( $product, 'get_lty_maximum_tickets' ) ) {
		$max_tickets = absint( call_user_func( array( $product, 'get_lty_maximum_tickets' ) ) );
	}

	return max( $start, $start + max( 0, $max_tickets - 1 ) );
}

/**
 * Parse a strictly numeric ticket string for range validation (no prefix/suffix).
 *
 * @param mixed $raw Ticket field value.
 * @return int|null Integer or null when not an all-digit string.
 */
function nera_iwt_parse_plain_numeric_ticket_for_range( $raw ) {
	$s = trim( (string) $raw );
	if ( '' === $s || ! ctype_digit( $s ) ) {
		return null;
	}

	return (int) $s;
}

/**
 * Whether the ticket number is outside the allowed numeric range (only enforced for all-digit values).
 *
 * @param WC_Product $product Lottery product.
 * @param mixed      $ticket_raw Submitted ticket number.
 * @return true|WP_Error True if OK or skipped (non-numeric pattern); WP_Error when out of range.
 */
function nera_iwt_validate_instant_win_ticket_number_range( $product, $ticket_raw ) {
	$n = nera_iwt_parse_plain_numeric_ticket_for_range( $ticket_raw );
	if ( null === $n ) {
		return true;
	}

	$min = nera_iwt_get_effective_ticket_start_for_validation( $product );
	$max = nera_iwt_get_instant_win_ticket_upper_bound( $product );

	if ( $n >= $min && $n <= $max ) {
		return true;
	}

	return new WP_Error(
		'nera_iwt_ticket_number_out_of_range',
		sprintf(
			/* translators: 1: minimum ticket number, 2: maximum ticket number */
			__( 'Ticket Number must be between %1$d and %2$d (inclusive).', 'nera-instant-win-threshold' ),
			$min,
			$max
		)
	);
}

/**
 * AJAX: validate Add Rule — after sequential-pattern guard.
 *
 * @return void
 */
function nera_iwt_ajax_validate_add_rule_ticket_number_range() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winner_rule'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product ) {
		return;
	}

	$raw = isset( $_POST['instant_winner_rule'] ) ? wp_unslash( $_POST['instant_winner_rule'] ) : array();
	if ( ! is_array( $raw ) || empty( $raw['ticket_number'] ) ) {
		return;
	}

	$result = nera_iwt_validate_instant_win_ticket_number_range( $product, $raw['ticket_number'] );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'error' => $result->get_error_message() ) );
	}
}

/**
 * AJAX: validate bulk Save Rules — after sequential-pattern guard.
 *
 * @return void
 */
function nera_iwt_ajax_validate_bulk_save_ticket_number_range() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winners_rules'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product ) {
		return;
	}

	$raw_rules = wp_unslash( $_POST['instant_winners_rules'] );
	if ( ! is_array( $raw_rules ) ) {
		return;
	}

	foreach ( $raw_rules as $row ) {
		if ( ! is_array( $row ) || empty( $row['ticket_number'] ) ) {
			continue;
		}

		$result = nera_iwt_validate_instant_win_ticket_number_range( $product, $row['ticket_number'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'error' => $result->get_error_message() ) );
		}
	}
}

add_action( 'wp_ajax_lty_add_instant_winner_rule', 'nera_iwt_ajax_validate_add_rule_ticket_number_range', 5 );
add_action( 'wp_ajax_lty_save_instant_winners_rules', 'nera_iwt_ajax_validate_bulk_save_ticket_number_range', 5 );
