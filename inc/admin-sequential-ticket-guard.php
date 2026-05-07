<?php
/**
 * Block instant-win rule saves when the lottery product uses automatic Sequential
 * ticket numbering together with Schedule or Ticket Sold % prize visibility rules.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the lottery product is configured with Sequential **Ticket Number Pattern**
 * in admin (matches both automatic and “user chooses” modes in LFW).
 *
 * - Automatic tickets: `_lty_ticket_number_type` === "2" (Sequential).
 * - User chooses tickets: `_lty_tickets_per_tab_display_type` === "1" (Sequential tabs).
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function nera_iwt_product_has_sequential_ticket_number_pattern( $product ) {
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		return false;
	}

	if ( method_exists( $product, 'is_automatic_ticket' ) && $product->is_automatic_ticket() ) {
		$num = '';
		if ( method_exists( $product, 'get_meta' ) ) {
			$num = (string) $product->get_meta( '_lty_ticket_number_type', true );
		}
		if ( '' === $num && method_exists( $product, 'get_lty_ticket_number_type' ) ) {
			$num = (string) $product->get_lty_ticket_number_type( 'edit' );
		}
		return '2' === $num;
	}

	if ( method_exists( $product, 'is_manual_ticket' ) && $product->is_manual_ticket() ) {
		$tab = '';
		if ( method_exists( $product, 'get_meta' ) ) {
			$tab = (string) $product->get_meta( '_lty_tickets_per_tab_display_type', true );
		}
		if ( '' === $tab && method_exists( $product, 'get_lty_tickets_per_tab_display_type' ) ) {
			$tab = (string) $product->get_lty_tickets_per_tab_display_type( 'edit' );
		}
		return '1' === $tab;
	}

	return false;
}

/**
 * Rule types that conflict with predictable sequential ticket assignment.
 *
 * @param string $type Rule slug.
 * @return bool
 */
function nera_iwt_rule_type_requires_random_or_shuffled_tickets( $type ) {
	return NERA_IWT_RULE_TYPE_SCHEDULE === $type || NERA_IWT_RULE_TYPE_TICKET_PCT === $type;
}

/**
 * Admin-facing error when Sequential tickets clash with Schedule / Ticket-% prize rules.
 *
 * Copy mentioning Schedule Prize is omitted when {@see nera_iwt_is_schedule_prize_type_enabled()} is false,
 * except when the conflicting rule type is {@see NERA_IWT_RULE_TYPE_SCHEDULE} (legacy row).
 *
 * @param string $conflict_rule_type Rule slug being saved (`schedule` or `ticket_pct`).
 * @return string
 */
function nera_iwt_message_sequential_ticket_pattern_conflict( $conflict_rule_type ) {
	$conflict_rule_type = sanitize_key( (string) $conflict_rule_type );

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $conflict_rule_type ) {
		return __( 'This product uses Ticket Number Pattern “Sequential” (automatic tickets). Schedule Prize rules need ticket numbers that are not issued in strict purchase order. Change Ticket Number Pattern to Random or Shuffled in the product Lottery data, then save again.', 'nera-instant-win-threshold' );
	}

	if ( nera_iwt_is_schedule_prize_type_enabled() ) {
		return __( 'This product uses Ticket Number Pattern “Sequential” (automatic tickets). Scheduled prizes and Ticket Sold % prizes need ticket numbers that are not issued in strict purchase order. Change Ticket Number Pattern to Random or Shuffled in the product Lottery data, then save again.', 'nera-instant-win-threshold' );
	}

	return __( 'This product uses Ticket Number Pattern “Sequential” (automatic tickets). Ticket Sold % prizes need ticket numbers that are not issued in strict purchase order. Change Ticket Number Pattern to Random or Shuffled in the product Lottery data, then save again.', 'nera-instant-win-threshold' );
}

/**
 * Runs before Lottery for WooCommerce: validate Add Rule AJAX.
 *
 * @return void
 */
function nera_iwt_ajax_validate_add_rule_sequential_ticket_pattern() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winner_rule'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product || ! nera_iwt_product_has_sequential_ticket_number_pattern( $product ) ) {
		return;
	}

	$raw = isset( $_POST['instant_winner_rule'] ) ? wp_unslash( $_POST['instant_winner_rule'] ) : array();
	if ( ! is_array( $raw ) ) {
		return;
	}

	$type = isset( $raw['nera_public_rule_type'] ) ? sanitize_key( (string) $raw['nera_public_rule_type'] ) : NERA_IWT_RULE_TYPE_INSTANT;
	if ( ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
		$type = NERA_IWT_RULE_TYPE_INSTANT;
	}
	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type && ! nera_iwt_is_schedule_prize_type_enabled() ) {
		$type = NERA_IWT_RULE_TYPE_INSTANT;
	}

	if ( nera_iwt_rule_type_requires_random_or_shuffled_tickets( $type ) ) {
		wp_send_json_error( array( 'error' => nera_iwt_message_sequential_ticket_pattern_conflict( $type ) ) );
	}
}

/**
 * Runs before Lottery for WooCommerce: validate bulk Save Rules AJAX.
 *
 * @return void
 */
function nera_iwt_ajax_validate_bulk_save_sequential_ticket_pattern() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winners_rules'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product || ! nera_iwt_product_has_sequential_ticket_number_pattern( $product ) ) {
		return;
	}

	$raw_rules = wp_unslash( $_POST['instant_winners_rules'] );
	if ( ! is_array( $raw_rules ) ) {
		return;
	}

	foreach ( $raw_rules as $rule_id => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$rid = absint( $rule_id );
		if ( $rid <= 0 ) {
			continue;
		}

		$type = isset( $row['nera_public_rule_type'] ) ? sanitize_key( (string) $row['nera_public_rule_type'] ) : '';
		if ( '' === $type || ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
			$type = (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true );
			if ( '' === $type || ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
				$type = NERA_IWT_RULE_TYPE_INSTANT;
			}
		}
		if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type && ! nera_iwt_is_schedule_prize_type_enabled() ) {
			$prev = (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true );
			if ( NERA_IWT_RULE_TYPE_SCHEDULE !== $prev ) {
				$type = NERA_IWT_RULE_TYPE_INSTANT;
			}
		}

		if ( nera_iwt_rule_type_requires_random_or_shuffled_tickets( $type ) ) {
			wp_send_json_error( array( 'error' => nera_iwt_message_sequential_ticket_pattern_conflict( $type ) ) );
		}
	}
}

add_action( 'wp_ajax_lty_add_instant_winner_rule', 'nera_iwt_ajax_validate_add_rule_sequential_ticket_pattern', 1 );
add_action( 'wp_ajax_lty_save_instant_winners_rules', 'nera_iwt_ajax_validate_bulk_save_sequential_ticket_pattern', 1 );
