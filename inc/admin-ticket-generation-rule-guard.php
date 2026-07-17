<?php
/**
 * Rule Type vs Ticket Generation Type: Schedule / Ticket % are Automatic-only; Held-back is
 * User-Chooses-only (the mirror). Enforced in the Rule-type dropdown, on Add/Save rule, and when
 * switching the product's Ticket Generation Type.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the lottery product uses Ticket Generation Type “Automatic” (`_lty_ticket_generation_type` === "1").
 *
 * @param WC_Product|null $product Product.
 * @return bool
 */
function nera_iwt_product_has_automatic_ticket_generation( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	if ( method_exists( $product, 'is_automatic_ticket' ) ) {
		return (bool) $product->is_automatic_ticket();
	}

	return false;
}

/**
 * Any instant-winner rule for this lottery uses Schedule Prize or Ticket Sold Percentage.
 *
 * @param int $product_id Lottery product ID.
 * @return bool
 */
function nera_iwt_product_has_ticket_pct_or_schedule_rules( $product_id ) {
	return nera_iwt_product_has_schedule_public_rules( $product_id )
		|| nera_iwt_product_has_ticket_pct_public_rules( $product_id );
}

/**
 * Admin notice when blocking switch away from Automatic ticket generation.
 *
 * @param int $product_id Lottery product ID.
 * @return string
 */
function nera_iwt_message_ticket_generation_conflict_rules( $product_id ) {
	$product_id = absint( $product_id );
	$has_sched  = nera_iwt_product_has_schedule_public_rules( $product_id );
	$has_pct    = nera_iwt_product_has_ticket_pct_public_rules( $product_id );

	if ( $has_sched && $has_pct ) {
		return __( 'This product has instant-win prizes that use Schedule Prize and Ticket Sold Percentage. Change those rules to Instant Prize or remove them before switching Ticket Generation Type away from Automatic.', 'nera-instant-win-threshold' );
	}

	if ( $has_sched ) {
		return __( 'This product has instant-win prizes that use Schedule Prize. Change those rules to Instant Prize or remove them before switching Ticket Generation Type away from Automatic.', 'nera-instant-win-threshold' );
	}

	if ( $has_pct ) {
		return __( 'This product has instant-win prizes that use Ticket Sold Percentage. Change those rules to Instant Prize or remove them before switching Ticket Generation Type away from Automatic.', 'nera-instant-win-threshold' );
	}

	return __( 'Change instant-win rules to Instant Prize or remove them before switching Ticket Generation Type away from Automatic.', 'nera-instant-win-threshold' );
}

/**
 * AJAX error when manual / user-chosen tickets cannot use advanced rule types.
 *
 * @param string $attempted_type Rule slug from the request (`schedule`, `ticket_pct`, or empty for generic).
 * @return string
 */
function nera_iwt_message_rule_type_requires_automatic_ticket_generation( $attempted_type = '' ) {
	$attempted_type = sanitize_key( (string) $attempted_type );

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $attempted_type ) {
		return __( 'Schedule Prize is only available when Ticket Generation Type is Automatic.', 'nera-instant-win-threshold' );
	}

	if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $attempted_type ) {
		return __( 'Ticket Sold Percentage is only available when Ticket Generation Type is Automatic.', 'nera-instant-win-threshold' );
	}

	if ( nera_iwt_is_schedule_prize_type_enabled() ) {
		return __( 'Ticket Sold Percentage and Schedule Prize are only available when Ticket Generation Type is Automatic.', 'nera-instant-win-threshold' );
	}

	return __( 'Ticket Sold Percentage is only available when Ticket Generation Type is Automatic.', 'nera-instant-win-threshold' );
}

/**
 * Block saving Ticket Generation Type away from Automatic while advanced rules exist.
 *
 * Runs before {@see LTY_Lottery_Product_Type_Handler::save_lottery_product_data_options()} (priority 10).
 *
 * @param int $post_id Product ID.
 * @return void
 */
function nera_iwt_validate_product_ticket_generation_change( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! isset( $_REQUEST['_lty_ticket_generation_type'] ) ) {
		return;
	}

	$post_id = absint( $post_id );
	$product = wc_get_product( $post_id );

	if ( ! $product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		return;
	}

	if ( ! nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
		return;
	}

	$posted = wc_clean( wp_unslash( $_REQUEST['_lty_ticket_generation_type'] ) );

	if ( '1' === $posted ) {
		return;
	}

	if ( ! nera_iwt_product_has_ticket_pct_or_schedule_rules( $post_id ) ) {
		return;
	}

	if ( class_exists( 'WC_Admin_Meta_Boxes', false ) ) {
		WC_Admin_Meta_Boxes::add_error( nera_iwt_message_ticket_generation_conflict_rules( $post_id ) );
	}

	$_POST['_lty_ticket_generation_type']    = '1';
	$_REQUEST['_lty_ticket_generation_type'] = '1';
}

add_action( 'woocommerce_process_product_meta_lottery', 'nera_iwt_validate_product_ticket_generation_change', 5 );

/**
 * AJAX: Add Rule — disallow ticket_pct / schedule when product is not Automatic.
 *
 * @return void
 */
function nera_iwt_ajax_validate_add_rule_ticket_generation_rule_types() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winner_rule'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product || nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
		return;
	}

	$raw = isset( $_POST['instant_winner_rule'] ) ? wp_unslash( $_POST['instant_winner_rule'] ) : array();
	if ( ! is_array( $raw ) ) {
		return;
	}

	$type = isset( $raw['nera_public_rule_type'] ) ? sanitize_key( (string) $raw['nera_public_rule_type'] ) : NERA_IWT_RULE_TYPE_INSTANT;

	if ( NERA_IWT_RULE_TYPE_TICKET_PCT !== $type && NERA_IWT_RULE_TYPE_SCHEDULE !== $type ) {
		return;
	}

	wp_send_json_error( array( 'error' => nera_iwt_message_rule_type_requires_automatic_ticket_generation( $type ) ) );
}

/**
 * AJAX: Save Rules — same guard per row (grandfather existing schedule / ticket_pct rows).
 *
 * @return void
 */
function nera_iwt_ajax_validate_bulk_save_ticket_generation_rule_types() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winners_rules'] ) ) {
		return;
	}

	$product_id = absint( wp_unslash( $_POST['product_id'] ) );
	$product    = wc_get_product( $product_id );

	if ( ! $product || nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
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

		if ( NERA_IWT_RULE_TYPE_TICKET_PCT !== $type && NERA_IWT_RULE_TYPE_SCHEDULE !== $type ) {
			continue;
		}

		$prev = (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true );
		if ( $type === $prev ) {
			continue;
		}

		wp_send_json_error( array( 'error' => nera_iwt_message_rule_type_requires_automatic_ticket_generation( $type ) ) );
	}
}

add_action( 'wp_ajax_lty_add_instant_winner_rule', 'nera_iwt_ajax_validate_add_rule_ticket_generation_rule_types', 2 );
add_action( 'wp_ajax_lty_save_instant_winners_rules', 'nera_iwt_ajax_validate_bulk_save_ticket_generation_rule_types', 2 );

/* ─────────────────────────────────────────────────────────────────────────────
 * Mirror: Held-back Prize requires Ticket Generation Type “User Chooses the Ticket”.
 * (Automatic products use Ticket % / Schedule instead — its secret number is a ticket
 * the buyer picks, which only exists in User-Chooses mode.)
 * ───────────────────────────────────────────────────────────────────────────── */

/**
 * AJAX error when an Automatic product tries to use Held-back.
 *
 * @return string
 */
function nera_iwt_message_held_requires_user_chooses() {
	return __( 'Held-back Prize is only available when Ticket Generation Type is “User Chooses the Ticket”.', 'nera-instant-win-threshold' );
}

/**
 * Admin notice when blocking a switch TO Automatic while Held-back rules exist.
 *
 * @return string
 */
function nera_iwt_message_held_rules_block_automatic() {
	return __( 'This product has Held-back Prize rules, which need Ticket Generation Type “User Chooses the Ticket”. Change those rules to Instant Prize or remove them before switching to Automatic.', 'nera-instant-win-threshold' );
}

/**
 * Block saving Ticket Generation Type = Automatic while Held-back rules exist.
 *
 * @param int $post_id Product ID.
 * @return void
 */
function nera_iwt_validate_product_switch_to_automatic_with_held( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! isset( $_REQUEST['_lty_ticket_generation_type'] ) ) {
		return;
	}

	$post_id = absint( $post_id );
	$product = wc_get_product( $post_id );

	if ( ! $product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		return;
	}

	// Only when the product is currently NOT Automatic (a real switch INTO Automatic).
	if ( nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
		return;
	}

	$posted = wc_clean( wp_unslash( $_REQUEST['_lty_ticket_generation_type'] ) );

	if ( '1' !== $posted ) {
		return;
	}

	if ( ! nera_iwt_product_has_instant_win_rule_of_public_type( $post_id, NERA_IWT_RULE_TYPE_HELD ) ) {
		return;
	}

	if ( class_exists( 'WC_Admin_Meta_Boxes', false ) ) {
		WC_Admin_Meta_Boxes::add_error( nera_iwt_message_held_rules_block_automatic() );
	}

	// Revert to the product's current (non-automatic) generation type; fall back to User Chooses.
	$current = (string) $product->get_meta( '_lty_ticket_generation_type', true );
	if ( '' === $current || '1' === $current ) {
		$current = '2';
	}
	$_POST['_lty_ticket_generation_type']    = $current;
	$_REQUEST['_lty_ticket_generation_type'] = $current;
}

add_action( 'woocommerce_process_product_meta_lottery', 'nera_iwt_validate_product_switch_to_automatic_with_held', 5 );

/**
 * AJAX: Add Rule — disallow Held-back when the product is Automatic.
 *
 * @return void
 */
function nera_iwt_ajax_validate_add_rule_held_user_chooses() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winner_rule'] ) ) {
		return;
	}

	$product = wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) );

	if ( ! $product || ! nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
		return;
	}

	$raw = wp_unslash( $_POST['instant_winner_rule'] );
	if ( ! is_array( $raw ) ) {
		return;
	}

	$type = isset( $raw['nera_public_rule_type'] ) ? sanitize_key( (string) $raw['nera_public_rule_type'] ) : NERA_IWT_RULE_TYPE_INSTANT;

	if ( NERA_IWT_RULE_TYPE_HELD !== $type ) {
		return;
	}

	wp_send_json_error( array( 'error' => nera_iwt_message_held_requires_user_chooses() ) );
}

/**
 * AJAX: Save Rules — disallow Held-back when Automatic (grandfather existing held rows).
 *
 * @return void
 */
function nera_iwt_ajax_validate_bulk_save_held_user_chooses() {
	check_ajax_referer( 'lty-instant-winner', 'lty_security' );

	if ( ! isset( $_POST['product_id'], $_POST['instant_winners_rules'] ) ) {
		return;
	}

	$product = wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) );

	if ( ! $product || ! nera_iwt_product_has_automatic_ticket_generation( $product ) ) {
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

		if ( NERA_IWT_RULE_TYPE_HELD !== $type ) {
			continue;
		}

		// Grandfather a row that is already Held-back (don't block editing existing data).
		if ( NERA_IWT_RULE_TYPE_HELD === (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true ) ) {
			continue;
		}

		wp_send_json_error( array( 'error' => nera_iwt_message_held_requires_user_chooses() ) );
	}
}

add_action( 'wp_ajax_lty_add_instant_winner_rule', 'nera_iwt_ajax_validate_add_rule_held_user_chooses', 2 );
add_action( 'wp_ajax_lty_save_instant_winners_rules', 'nera_iwt_ajax_validate_bulk_save_held_user_chooses', 2 );
