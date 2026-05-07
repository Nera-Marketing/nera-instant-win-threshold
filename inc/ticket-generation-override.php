<?php
/**
 * Ticket Generation Override — extended numeric range for shuffle / random types
 *
 * Loaded by the mu-plugin shim (wp-content/mu-plugins/nera-iwt-ticket-generation.php)
 * BEFORE lottery-for-woocommerce declares its own versions, so our `if (!function_exists())`
 * guards win the pluggable-function race.
 *
 * Scope
 * ──────
 * Affected ticket types     : shuffle, random  (auto-assigned, non-sequential)
 * Unaffected ticket types   : sequential, manual  (LFW's own functions handle those)
 *
 * Pool upper bound (shuffle + random numeric range):
 *
 *   • Product meta `_nera_iwt_ticket_number_max` > 0  →  that value (Ticket Generation Settings in admin).
 *   • Else NERA_IWT_MAX_TICKET_NUMBER > 0  →  constant (wp-config / mu-plugin shim fallback).
 *   • Else  →  1 … _lty_maximum_tickets + count( unavailable prize-hold tickets ).
 *
 * Set NERA_IWT_MAX_TICKET_NUMBER to 0 in wp-config.php for pure LFW-style ceiling when no product meta.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configured numeric ticket pool ceiling for shuffle/random pools and instant-win validation.
 *
 * Order: per-product meta `_nera_iwt_ticket_number_max`, then {@see NERA_IWT_MAX_TICKET_NUMBER}, then 0 (caller uses LFW-style math).
 *
 * @param WC_Product|null $product Lottery product.
 * @return int 0 or inclusive maximum ticket number (at least 1 when non-zero).
 */
function nera_iwt_get_configured_ticket_pool_max( $product ) {
	if ( is_object( $product ) && method_exists( $product, 'get_meta' ) ) {
		$m = absint( $product->get_meta( '_nera_iwt_ticket_number_max', true ) );
		if ( $m > 0 ) {
			return max( 1, min( $m, 99999999 ) );
		}
	}

	if ( defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) && NERA_IWT_MAX_TICKET_NUMBER > 0 ) {
		return max( 1, (int) NERA_IWT_MAX_TICKET_NUMBER );
	}

	return 0;
}

/**
 * Resolve the inclusive upper bound for shuffle / random ticket pools.
 *
 * @param WC_Product $product Lottery product.
 * @return int At least 1.
 */
function nera_iwt_resolve_shuffle_random_pool_max( $product ) {

	$configured = nera_iwt_get_configured_ticket_pool_max( $product );
	if ( $configured > 0 ) {
		return $configured;
	}

	$base = 1;
	if ( is_object( $product ) && method_exists( $product, 'get_lty_maximum_tickets' ) ) {
		$base = max( 1, absint( $product->get_lty_maximum_tickets() ) );
	}

	$extra = 0;
	if ( function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ) {
		$held = nera_iwt_get_unavailable_prize_ticket_numbers( $product );
		if ( is_array( $held ) ) {
			$extra = count( $held );
		}
	}

	return max( 1, $base + $extra );
}

// ---------------------------------------------------------------------------
// RANDOM ticket numbers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'lty_get_random_ticket_numbers' ) ) {

	/**
	 * Generate unique random ticket numbers from 1 … {@see nera_iwt_resolve_shuffle_random_pool_max()}.
	 *
	 * Replaces LFW's default which uses a configurable character type / length /
	 * prefix / suffix — unrelated to a numeric range.
	 *
	 * Already-placed ticket numbers (lty_ticket_pending / buyer / winner posts) are
	 * excluded up-front so the caller's collision loop in get_ticket_numbers() has
	 * fewer retries.  The loop still performs lty_check_is_ticket_number_exists()
	 * as the authoritative guard, so this is an optimisation only.
	 *
	 * @param WC_Product $product  Lottery product.
	 * @param int        $quantity Number of ticket numbers to generate.
	 * @return array
	 */
	function lty_get_random_ticket_numbers( $product, $quantity ) {

		$max      = nera_iwt_resolve_shuffle_random_pool_max( $product );
		$quantity = max( 1, (int) $quantity );

		$placed = is_object( $product ) && method_exists( $product, 'get_placed_tickets' )
			? array_map( 'strval', (array) $product->get_placed_tickets() )
			: array();

		$ticket_numbers = array();
		// Safety cap: avoids infinite loops when the pool is nearly exhausted.
		$max_attempts = $quantity * 200;
		$attempts     = 0;

		while ( count( $ticket_numbers ) < $quantity && $attempts < $max_attempts ) {
			$num = (string) mt_rand( 1, $max );
			if ( ! in_array( $num, $ticket_numbers, true ) && ! in_array( $num, $placed, true ) ) {
				$ticket_numbers[] = $num;
			}
			++$attempts;
		}

		return $ticket_numbers;
	}
}

// ---------------------------------------------------------------------------
// SHUFFLE ticket numbers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'lty_get_remaining_shuffle_ticket_numbers' ) ) {

	/**
	 * Pick ticket numbers from a shuffled pool of  1 … {@see nera_iwt_resolve_shuffle_random_pool_max()}
	 * minus already-placed tickets.
	 *
	 * Replaces LFW's default which builds its pool from the product's
	 * get_formatted_shuffle_ticket_numbers() (capped at _lty_maximum_tickets).
	 *
	 * Note: range(1, 99999) produces ~100 k integers (~800 KB) in memory — fine
	 * for typical competition traffic.  If performance becomes a concern, switch
	 * to the random approach (pick candidates one-by-one) rather than materialising
	 * the full pool.
	 *
	 * @param WC_Product $product  Lottery product.
	 * @param int        $quantity Number of ticket numbers to return.
	 * @return array
	 */
	function lty_get_remaining_shuffle_ticket_numbers( $product, $quantity ) {

		$max      = nera_iwt_resolve_shuffle_random_pool_max( $product );
		$quantity = max( 1, (int) $quantity );

		$placed = is_object( $product ) && method_exists( $product, 'get_placed_tickets' )
			? array_map( 'intval', (array) $product->get_placed_tickets() )
			: array();

		// Build the available pool as integers, then shuffle.
		$pool = array_values( array_diff( range( 1, $max ), $placed ) );

		if ( empty( $pool ) ) {
			return array();
		}

		shuffle( $pool );

		// Convert to strings to match LFW's expected ticket number format.
		return array_map( 'strval', array_slice( $pool, 0, $quantity ) );
	}
}
