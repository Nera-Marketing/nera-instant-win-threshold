<?php
/**
 * Reserve-slots feasibility checks for ticket-% instant-win rules and product pool size.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fixed numeric pool size N for reserve-slots math (Ticket Number Max or LFW maximum).
 *
 * @param WC_Product $product Lottery product.
 * @return int 0 when unknown.
 */
function nera_iwt_get_reserve_slots_pool_n( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return 0;
	}
	$n = function_exists( 'nera_iwt_get_configured_ticket_pool_max' )
		? (int) nera_iwt_get_configured_ticket_pool_max( $product )
		: 0;
	return max( 0, $n );
}

/**
 * Ticket-sold count at which a ticket-% rule at threshold T becomes available.
 *
 * @param int $pool_n   Pool size N.
 * @param int $threshold Percent 0–100.
 * @return int
 */
function nera_iwt_ticket_pct_unlock_sold_count( $pool_n, $threshold ) {
	$pool_n    = max( 0, (int) $pool_n );
	$threshold = max( 0, min( 100, (int) $threshold ) );
	if ( $pool_n <= 0 || $threshold <= 0 ) {
		return 0;
	}
	return (int) ceil( ( $threshold / 100 ) * $pool_n );
}

/**
 * Count ticket-% rules on a product with threshold >= given value.
 *
 * @param int $product_id      Lottery product ID.
 * @param int $min_threshold   Minimum threshold (inclusive).
 * @param int $exclude_rule_id Rule post ID to omit (when validating an edit).
 * @param int $include_threshold When > 0, count a not-yet-saved rule at this threshold once.
 * @return int
 */
function nera_iwt_count_ticket_pct_rules_at_or_above( $product_id, $min_threshold, $exclude_rule_id = 0, $include_threshold = 0 ) {
	$product_id      = absint( $product_id );
	$min_threshold   = max( 0, min( 100, (int) $min_threshold ) );
	$exclude_rule_id = absint( $exclude_rule_id );
	$include_threshold = max( 0, min( 100, (int) $include_threshold ) );

	if ( $product_id <= 0 || ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return $include_threshold >= $min_threshold && $include_threshold > 0 ? 1 : 0;
	}

	$count = 0;
	foreach ( (array) lty_get_instant_winner_rule_ids( $product_id ) as $rule_id ) {
		$rule_id = absint( $rule_id );
		if ( $rule_id <= 0 || $rule_id === $exclude_rule_id ) {
			continue;
		}
		$type = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
		if ( NERA_IWT_RULE_TYPE_TICKET_PCT !== $type ) {
			continue;
		}
		$pct = max( 0, min( 100, (int) get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) );
		if ( $pct >= $min_threshold ) {
			++$count;
		}
	}

	if ( $include_threshold >= $min_threshold && $include_threshold > 0 ) {
		++$count;
	}

	return $count;
}

/**
 * Whether a ticket-% threshold is feasible with reserve-slots on pool N.
 *
 * @param int $pool_n              Pool size.
 * @param int $threshold           Rule threshold 1–100.
 * @param int $locked_at_or_above  Count of ticket-% rules locked at/above this threshold.
 * @return true|WP_Error
 */
function nera_iwt_validate_ticket_pct_reserve_slots( $pool_n, $threshold, $locked_at_or_above ) {
	$pool_n             = max( 0, (int) $pool_n );
	$threshold          = max( 0, min( 100, (int) $threshold ) );
	$locked_at_or_above = max( 0, (int) $locked_at_or_above );

	if ( $threshold <= 0 ) {
		return true;
	}

	if ( $pool_n <= 0 ) {
		return true;
	}

	if ( 100 === $threshold ) {
		return new WP_Error(
			'nera_iwt_ticket_pct_100_infeasible',
			__( 'Ticket Sold Percentage cannot be 100% with a fixed ticket pool: the prize would only unlock after every ticket is sold, leaving no slot to assign that number.', 'nera-instant-win-threshold' )
		);
	}

	$unlock_at = nera_iwt_ticket_pct_unlock_sold_count( $pool_n, $threshold );
	$sellable  = $pool_n - $locked_at_or_above;

	if ( $unlock_at > $sellable ) {
		return new WP_Error(
			'nera_iwt_ticket_pct_reserve_deadlock',
			sprintf(
				/* translators: 1: threshold %, 2: tickets that must sell before unlock, 3: pool size N, 4: count of prizes locked at/above this threshold */
				__( 'Ticket Sold Percentage %1$d%% is too high for this pool: %2$d tickets must sell before this prize unlocks, but only %3$d sellable numbers exist while %4$d prize numbers are reserved at or above this threshold. Lower the percentage or reduce overlapping ticket-%% prizes.', 'nera-instant-win-threshold' ),
				$threshold,
				$unlock_at,
				$sellable,
				$locked_at_or_above
			)
		);
	}

	return true;
}

/**
 * Validate reserve-slots feasibility for one rule row (add/save AJAX).
 *
 * @param WC_Product $product         Lottery product.
 * @param array      $row             Rule row (nera_public_rule_type, nera_ticket_pct, ticket_number).
 * @param int        $exclude_rule_id Existing rule ID when editing.
 * @return true|WP_Error
 */
function nera_iwt_validate_rule_reserve_slots_feasibility( $product, array $row, $exclude_rule_id = 0 ) {
	if ( ! $product instanceof WC_Product ) {
		return true;
	}

	$type = isset( $row['nera_public_rule_type'] ) ? sanitize_key( (string) $row['nera_public_rule_type'] ) : NERA_IWT_RULE_TYPE_INSTANT;

	// Schedule: time-based unlock — sellout guarantee does not apply (see docs/RESERVE-SLOTS.md).
	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type ) {
		return true;
	}

	if ( NERA_IWT_RULE_TYPE_TICKET_PCT !== $type ) {
		return true;
	}

	$pool_n    = nera_iwt_get_reserve_slots_pool_n( $product );
	$threshold = max( 0, min( 100, (int) ( $row['nera_ticket_pct'] ?? 0 ) ) );
	$locked    = nera_iwt_count_ticket_pct_rules_at_or_above(
		$product->get_id(),
		$threshold,
		$exclude_rule_id,
		$threshold
	);

	return nera_iwt_validate_ticket_pct_reserve_slots( $pool_n, $threshold, $locked );
}

/**
 * Validate all ticket-% rules on a product together (bulk save).
 *
 * @param WC_Product $product Lottery product.
 * @param array      $rules_by_id Rows keyed by rule ID (may include unsaved edits).
 * @return true|WP_Error
 */
function nera_iwt_validate_product_ticket_pct_reserve_slots( $product, array $rules_by_id = array() ) {
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return true;
	}

	$pool_n = nera_iwt_get_reserve_slots_pool_n( $product );
	if ( $pool_n <= 0 ) {
		return true;
	}

	$thresholds = array();
	foreach ( (array) lty_get_instant_winner_rule_ids( $product->get_id() ) as $rule_id ) {
		$rule_id = absint( $rule_id );
		if ( $rule_id <= 0 ) {
			continue;
		}
		$type = NERA_IWT_RULE_TYPE_INSTANT;
		$pct  = 0;
		$row_override = null;
		if ( isset( $rules_by_id[ $rule_id ] ) && is_array( $rules_by_id[ $rule_id ] ) ) {
			$row_override = $rules_by_id[ $rule_id ];
		} elseif ( isset( $rules_by_id[ (string) $rule_id ] ) && is_array( $rules_by_id[ (string) $rule_id ] ) ) {
			$row_override = $rules_by_id[ (string) $rule_id ];
		}
		if ( is_array( $row_override ) ) {
			$row  = $row_override;
			$type = isset( $row['nera_public_rule_type'] ) ? sanitize_key( (string) $row['nera_public_rule_type'] ) : $type;
			$pct  = max( 0, min( 100, (int) ( $row['nera_ticket_pct'] ?? 0 ) ) );
		} else {
			$type = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
			$pct  = max( 0, min( 100, (int) get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) );
		}
		if ( NERA_IWT_RULE_TYPE_TICKET_PCT !== $type || $pct <= 0 ) {
			continue;
		}
		$thresholds[] = $pct;
	}

	foreach ( $thresholds as $threshold ) {
		$locked = 0;
		foreach ( $thresholds as $other ) {
			if ( $other >= $threshold ) {
				++$locked;
			}
		}
		$result = nera_iwt_validate_ticket_pct_reserve_slots( $pool_n, $threshold, $locked );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return true;
}

/**
 * Ticket Number Max must be >= LFW maximum tickets when both are set.
 *
 * @param WC_Product $product Lottery product.
 * @param int        $ticket_number_max Proposed cap.
 * @return true|WP_Error
 */
function nera_iwt_validate_product_ticket_number_max_vs_lfw( $product, $ticket_number_max ) {
	if ( ! $product instanceof WC_Product || $ticket_number_max <= 0 ) {
		return true;
	}
	if ( ! method_exists( $product, 'get_lty_maximum_tickets' ) ) {
		return true;
	}
	$lfw_max = absint( $product->get_lty_maximum_tickets() );
	if ( $lfw_max > 0 && $ticket_number_max < $lfw_max ) {
		return new WP_Error(
			'nera_iwt_ticket_max_below_lfw',
			sprintf(
				/* translators: 1: Ticket Number Max, 2: LFW maximum tickets */
				__( 'Ticket Number Max (%1$d) must be at least the product Maximum Tickets (%2$d) so the full sellout pool can be filled.', 'nera-instant-win-threshold' ),
				$ticket_number_max,
				$lfw_max
			)
		);
	}
	return true;
}
