<?php
/**
 * Held-back prizes (Option B) — activation lifecycle.
 *
 * A held-back prize (rule type {@see NERA_IWT_RULE_TYPE_HELD}) shows publicly as an
 * available prize with NO ticket number and cannot be won (`lty_get_rule_id_by_ticket_number`
 * never matches an empty number). Activating it assigns a definitely-unsold ticket number so
 * the next customer who buys that number wins — the number stays secret (the storefront never
 * exposes an unwon prize's number).
 *
 * This file owns: the unsold-number picker, manual/auto activation with an atomic unsold
 * re-check, deactivation, and the admin AJAX endpoints. The admin button is rendered by
 * {@see nera_iwt_admin_rule_column_cell()} and wired by assets/admin-rule-visibility.js.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the lottery product that owns an instant-winner rule.
 *
 * @param int $rule_id Rule post ID.
 * @return WC_Product|null
 */
function nera_iwt_held_get_rule_product( $rule_id ) {
	$rule_id    = absint( $rule_id );
	$lottery_id = absint( get_post_meta( $rule_id, 'lty_lottery_id', true ) );
	if ( $lottery_id <= 0 ) {
		$lottery_id = (int) wp_get_post_parent_id( $rule_id );
	}
	if ( $lottery_id <= 0 ) {
		return null;
	}
	$product = wc_get_product( $lottery_id );
	return $product instanceof WC_Product ? $product : null;
}

/**
 * All valid ticket strings for a product, in the exact form a buyer's ticket is stored.
 *
 *  - user-chooses (manual): the formatted grid strings (letter/prefix/pad) from LFW's own
 *    get_overall_tickets() — the same values buyers submit and that win-matching compares.
 *  - automatic: this plugin's generator override assigns plain 1..pool_max, so the pool is
 *    the plain integer strings 1..pool_max.
 *
 * @param WC_Product $product Lottery product.
 * @return string[]
 */
function nera_iwt_held_all_sellable_ticket_strings( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	if ( method_exists( $product, 'is_manual_ticket' ) && $product->is_manual_ticket() && method_exists( $product, 'get_overall_tickets' ) ) {
		return array_values( array_filter( array_map( 'strval', (array) $product->get_overall_tickets() ), 'strlen' ) );
	}

	$max = function_exists( 'nera_iwt_get_configured_ticket_pool_max' ) ? (int) nera_iwt_get_configured_ticket_pool_max( $product ) : 0;
	if ( $max <= 0 ) {
		return array();
	}

	$out = array();
	for ( $i = 1; $i <= $max; $i++ ) {
		$out[] = (string) $i;
	}
	return $out;
}

/**
 * Ticket strings that are NOT assignable to a held prize: already-placed tickets (buyer /
 * winner / pending), in-flight cart holds, and numbers already assigned to other instant-win
 * rules (so two prizes never land on the same number).
 *
 * @param WC_Product $product         Lottery product.
 * @param int        $exclude_rule_id Rule being activated (its own number is not a conflict).
 * @return array<string,true> O(1) lookup hash.
 */
function nera_iwt_held_taken_ticket_strings( $product, $exclude_rule_id = 0 ) {
	$taken = array();
	if ( ! $product instanceof WC_Product ) {
		return $taken;
	}

	if ( method_exists( $product, 'get_placed_tickets' ) ) {
		foreach ( (array) $product->get_placed_tickets() as $n ) {
			$taken[ (string) $n ] = true;
		}
	}

	// LFW writes cart-hold reservations under two keys inconsistently.
	foreach ( array( '_lty_hold_tickets', 'lty_hold_tickets' ) as $key ) {
		$holds = get_post_meta( $product->get_id(), $key, true );
		if ( is_array( $holds ) ) {
			foreach ( $holds as $n ) {
				$taken[ (string) $n ] = true;
			}
		}
	}

	if ( function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		$exclude_rule_id = absint( $exclude_rule_id );
		foreach ( (array) lty_get_instant_winner_rule_ids( $product->get_id() ) as $rid ) {
			if ( absint( $rid ) === $exclude_rule_id ) {
				continue;
			}
			$num = (string) get_post_meta( absint( $rid ), 'lty_ticket_number', true );
			if ( '' !== $num ) {
				$taken[ $num ] = true;
			}
		}
	}

	return $taken;
}

/**
 * Whether a specific ticket string is currently a valid, unsold, assignable number.
 *
 * @param WC_Product $product         Lottery product.
 * @param string     $ticket_string   Canonical ticket string.
 * @param int        $exclude_rule_id Rule being activated.
 * @return bool
 */
function nera_iwt_held_is_ticket_unsold( $product, $ticket_string, $exclude_rule_id = 0 ) {
	$ticket_string = (string) $ticket_string;
	if ( '' === $ticket_string || ! $product instanceof WC_Product ) {
		return false;
	}

	$sellable = nera_iwt_held_all_sellable_ticket_strings( $product );
	if ( ! empty( $sellable ) && ! in_array( $ticket_string, $sellable, true ) ) {
		return false;
	}

	$taken = nera_iwt_held_taken_ticket_strings( $product, $exclude_rule_id );
	return ! isset( $taken[ $ticket_string ] );
}

/**
 * Pick a random, definitely-unsold ticket number for a held prize.
 *
 * @param WC_Product $product         Lottery product.
 * @param int        $exclude_rule_id Rule being activated.
 * @return string|WP_Error Canonical ticket string, or WP_Error when the pool is exhausted.
 */
function nera_iwt_held_pick_unsold_number( $product, $exclude_rule_id = 0 ) {
	$sellable = nera_iwt_held_all_sellable_ticket_strings( $product );
	if ( empty( $sellable ) ) {
		return new WP_Error( 'nera_iwt_held_no_pool', __( 'This competition has no assignable ticket pool to place a held prize on.', 'nera-instant-win-threshold' ) );
	}

	$taken = nera_iwt_held_taken_ticket_strings( $product, $exclude_rule_id );

	$free = array();
	foreach ( $sellable as $t ) {
		if ( ! isset( $taken[ $t ] ) ) {
			$free[] = $t;
		}
	}

	if ( empty( $free ) ) {
		return new WP_Error( 'nera_iwt_held_pool_exhausted', __( 'No unsold ticket numbers remain to place this held prize on.', 'nera-instant-win-threshold' ) );
	}

	$idx = wp_rand( 0, count( $free ) - 1 );
	return $free[ $idx ];
}

/**
 * Mirror a held prize's assigned ticket number onto its child instant-winner log(s).
 *
 * @param int    $rule_id Rule post ID.
 * @param string $number  Ticket number to write ('' to clear).
 * @return void
 */
function nera_iwt_held_sync_ticket_number_to_logs( $rule_id, $number ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 ) {
		return;
	}

	$log_pt = class_exists( 'LTY_Register_Post_Types', false )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_LOG_POSTTYPE
		: 'lty_ins_winner_log';

	$statuses = function_exists( 'lty_get_instant_winner_log_statuses' )
		? lty_get_instant_winner_log_statuses()
		: array( 'lty_available', 'lty_pending', 'lty_won' );

	$logs = get_posts(
		array(
			'post_type'      => $log_pt,
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_parent'    => $rule_id,
		)
	);

	foreach ( (array) $logs as $log_id ) {
		update_post_meta( absint( $log_id ), 'lty_ticket_number', (string) $number );
	}
}

/**
 * Activate a held-back prize: assign a definitely-unsold ticket number and mark it active.
 *
 * @param int    $rule_id        Rule post ID.
 * @param string $typed_number   Optional admin-chosen number ('' → system picks).
 * @param bool   $allow_reassign Allow changing the number of an already-active (Live) prize —
 *                               the "Edit number" flow. Still blocked once a winner is assigned.
 * @return array{rule_id:int,number:string,state:string}|WP_Error
 */
function nera_iwt_activate_held_prize( $rule_id, $typed_number = '', $allow_reassign = false ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 ) {
		return new WP_Error( 'nera_iwt_held_bad_rule', __( 'Invalid prize rule.', 'nera-instant-win-threshold' ) );
	}

	$type = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
	if ( NERA_IWT_RULE_TYPE_HELD !== $type ) {
		return new WP_Error( 'nera_iwt_held_not_held', __( 'This prize is not a held-back prize.', 'nera-instant-win-threshold' ) );
	}

	// A prize that already has a winner must never be (re-)activated: re-activation would rewrite
	// its winning number and corrupt the recorded win. Symmetric with the deactivate guard.
	if ( function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rule_id ) ) {
		return new WP_Error( 'nera_iwt_held_already_won', __( 'This held prize already has a winner and cannot be re-activated.', 'nera-instant-win-threshold' ) );
	}

	// Already-active prizes are only re-assignable through the "Edit number" flow ($allow_reassign);
	// a plain activate on an active prize is a no-op error.
	if ( ! $allow_reassign && 'active' === (string) get_post_meta( $rule_id, 'nera_iwt_held_state', true ) ) {
		return new WP_Error( 'nera_iwt_held_already_active', __( 'This held prize is already activated.', 'nera-instant-win-threshold' ) );
	}

	$product = nera_iwt_held_get_rule_product( $rule_id );
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error( 'nera_iwt_held_no_product', __( 'Could not load the competition for this prize.', 'nera-instant-win-threshold' ) );
	}

	$typed_number = trim( (string) $typed_number );

	if ( '' !== $typed_number ) {
		// Manual override: resolve to the canonical stored form, then require it to be unsold.
		$canonical = function_exists( 'nera_iwt_canonicalize_instant_win_ticket_number' )
			? nera_iwt_canonicalize_instant_win_ticket_number( $product, $typed_number )
			: $typed_number;
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		$canonical = (string) $canonical;
		if ( '' === $canonical ) {
			return new WP_Error( 'nera_iwt_held_empty_number', __( 'Enter a ticket number, or leave blank to let the system pick one.', 'nera-instant-win-threshold' ) );
		}
		if ( ! nera_iwt_held_is_ticket_unsold( $product, $canonical, $rule_id ) ) {
			return new WP_Error(
				'nera_iwt_held_ticket_taken',
				sprintf(
					/* translators: %s: ticket number */
					__( 'Ticket %s has already been sold or reserved — choose another number, or leave blank to let the system pick an unsold one.', 'nera-instant-win-threshold' ),
					$canonical
				)
			);
		}
		$number = $canonical;
	} else {
		$picked = nera_iwt_held_pick_unsold_number( $product, $rule_id );
		if ( is_wp_error( $picked ) ) {
			return $picked;
		}
		$number = (string) $picked;
	}

	// Final unsold re-check immediately before commit (minimise the pick→assign race).
	if ( ! nera_iwt_held_is_ticket_unsold( $product, $number, $rule_id ) ) {
		return new WP_Error( 'nera_iwt_held_raced', __( 'That ticket was just taken by a buyer — please activate again to pick another.', 'nera-instant-win-threshold' ) );
	}

	// Commit. Win-matching compares the rule's lty_ticket_number exactly; mirror onto logs too.
	// nera_iwt_held_number is the authoritative assigned number — it survives a later admin
	// re-save (which would otherwise let LFW's ticket-number input overwrite it).
	update_post_meta( $rule_id, 'lty_ticket_number', $number );
	update_post_meta( $rule_id, 'nera_iwt_held_number', $number );
	update_post_meta( $rule_id, 'nera_iwt_held_state', 'active' );
	nera_iwt_held_sync_ticket_number_to_logs( $rule_id, $number );

	if ( function_exists( 'nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule' ) ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id );
	}

	/**
	 * Fires after a held-back prize is activated onto a ticket number.
	 *
	 * @param int        $rule_id Rule post ID.
	 * @param string     $number  Assigned ticket number.
	 * @param WC_Product $product Lottery product.
	 */
	do_action( 'nera_iwt_held_prize_activated', $rule_id, $number, $product );

	return array(
		'rule_id' => $rule_id,
		'number'  => $number,
		'state'   => 'active',
	);
}

/**
 * Deactivate a held-back prize: clear its number and return it to the held state.
 *
 * Refused once a winner has been assigned (the prize has already been claimed).
 *
 * @param int $rule_id Rule post ID.
 * @return array{rule_id:int,state:string}|WP_Error
 */
function nera_iwt_deactivate_held_prize( $rule_id ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 ) {
		return new WP_Error( 'nera_iwt_held_bad_rule', __( 'Invalid prize rule.', 'nera-instant-win-threshold' ) );
	}

	if ( NERA_IWT_RULE_TYPE_HELD !== (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true ) ) {
		return new WP_Error( 'nera_iwt_held_not_held', __( 'This prize is not a held-back prize.', 'nera-instant-win-threshold' ) );
	}

	if ( function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rule_id ) ) {
		return new WP_Error( 'nera_iwt_held_already_won', __( 'This held prize already has a winner and cannot be deactivated.', 'nera-instant-win-threshold' ) );
	}

	update_post_meta( $rule_id, 'lty_ticket_number', '' );
	update_post_meta( $rule_id, 'nera_iwt_held_state', 'held' );
	delete_post_meta( $rule_id, 'nera_iwt_held_number' );
	nera_iwt_held_sync_ticket_number_to_logs( $rule_id, '' );

	if ( function_exists( 'nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule' ) ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id );
	}

	do_action( 'nera_iwt_held_prize_deactivated', $rule_id );

	return array(
		'rule_id' => $rule_id,
		'state'   => 'held',
	);
}

// ---------------------------------------------------------------------------
// ADMIN AJAX — activate / deactivate
// ---------------------------------------------------------------------------

/**
 * AJAX: activate a held-back prize.
 *
 * @return void
 */
function nera_iwt_ajax_activate_held_prize() {
	check_ajax_referer( 'nera_iwt_activate_held', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'error' => __( 'You do not have permission to do this.', 'nera-instant-win-threshold' ) ) );
	}

	$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
	$ticket  = isset( $_POST['ticket_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_number'] ) ) : '';
	// "Edit number" on a Live prize re-assigns the number without deactivating first.
	$allow_reassign = isset( $_POST['edit'] ) && '1' === (string) wp_unslash( $_POST['edit'] );

	$result = nera_iwt_activate_held_prize( $rule_id, $ticket, $allow_reassign );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'error' => $result->get_error_message() ) );
	}

	$result['status'] = 'available';
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nera_iwt_activate_held_prize', 'nera_iwt_ajax_activate_held_prize' );

/**
 * AJAX: deactivate a held-back prize.
 *
 * @return void
 */
function nera_iwt_ajax_deactivate_held_prize() {
	check_ajax_referer( 'nera_iwt_activate_held', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'error' => __( 'You do not have permission to do this.', 'nera-instant-win-threshold' ) ) );
	}

	$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

	$result = nera_iwt_deactivate_held_prize( $rule_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'error' => $result->get_error_message() ) );
	}

	$result['status'] = 'locked';
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_nera_iwt_deactivate_held_prize', 'nera_iwt_ajax_deactivate_held_prize' );

/**
 * Tab label for a ticket grid: the letters immediately before the digits (e.g. "L" in "L11382"),
 * falling back to A, B, C… by index.
 *
 * @param string $ticket Canonical ticket string.
 * @param int    $index  Zero-based tab index.
 * @return string
 */
function nera_iwt_held_tab_label( $ticket, $index ) {
	if ( preg_match( '/([A-Za-z]+)\d/', (string) $ticket, $m ) ) {
		return $m[1];
	}
	return chr( 65 + ( (int) $index % 26 ) );
}

/**
 * AJAX: return one tab of the ticket grid for a held prize's competition, each ticket flagged
 * sold/available, so the admin can pick a winning number visually (user-chooses comps only).
 *
 * @return void
 */
function nera_iwt_ajax_held_grid() {
	check_ajax_referer( 'nera_iwt_activate_held', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'error' => __( 'You do not have permission to do this.', 'nera-instant-win-threshold' ) ) );
	}

	$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
	$tab     = isset( $_POST['tab'] ) ? max( 0, (int) wp_unslash( $_POST['tab'] ) ) : 0;

	$product = nera_iwt_held_get_rule_product( $rule_id );
	if ( ! $product instanceof WC_Product ) {
		wp_send_json_error( array( 'error' => __( 'Could not load the competition for this prize.', 'nera-instant-win-threshold' ) ) );
	}

	if ( ! method_exists( $product, 'is_manual_ticket' ) || ! $product->is_manual_ticket() ) {
		wp_send_json_error( array( 'error' => __( 'The ticket grid is only available on user-chooses competitions. On automatic competitions, leave blank or use Suggest unsold.', 'nera-instant-win-threshold' ) ) );
	}

	$map     = nera_iwt_manual_ticket_canonical_map( $product );
	$ordered = isset( $map['ordered'] ) ? $map['ordered'] : array();
	$total   = count( $ordered );
	if ( $total <= 0 ) {
		wp_send_json_error( array( 'error' => __( 'This competition has no ticket pool to display.', 'nera-instant-win-threshold' ) ) );
	}

	$per_tab = method_exists( $product, 'get_lty_tickets_per_tab' ) ? (int) $product->get_lty_tickets_per_tab() : 0;
	if ( $per_tab < 1 ) {
		$per_tab = $total;
	}

	$tab_count = (int) ceil( $total / $per_tab );
	if ( $tab_count < 1 ) {
		$tab_count = 1;
	}
	if ( $tab >= $tab_count ) {
		$tab = 0;
	}

	$labels = array();
	for ( $i = 0; $i < $tab_count; $i++ ) {
		$first    = isset( $ordered[ $i * $per_tab ] ) ? (string) $ordered[ $i * $per_tab ] : '';
		$labels[] = nera_iwt_held_tab_label( $first, $i );
	}

	$taken   = nera_iwt_held_taken_ticket_strings( $product, $rule_id );
	$slice   = array_slice( $ordered, $tab * $per_tab, $per_tab );
	$tickets = array();
	foreach ( $slice as $t ) {
		$t = (string) $t;
		if ( '' === $t ) {
			continue;
		}
		$tickets[] = array(
			'n'    => $t,
			'sold' => isset( $taken[ $t ] ) ? 1 : 0,
		);
	}

	wp_send_json_success(
		array(
			'tab'      => $tab,
			'tabCount' => $tab_count,
			'labels'   => $labels,
			'tickets'  => $tickets,
		)
	);
}
add_action( 'wp_ajax_nera_iwt_held_grid', 'nera_iwt_ajax_held_grid' );
