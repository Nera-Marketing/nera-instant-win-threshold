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
 *   • NERA_IWT_MAX_TICKET_NUMBER > 0  →  1 … constant value
 *   • NERA_IWT_MAX_TICKET_NUMBER is 0 or unset  →  1 … _lty_maximum_tickets
 *       + count( nera_iwt_get_unavailable_prize_ticket_numbers( $product ) )
 *       so extra slots exist beyond the product cap when prize-hold tickets
 *       reserve numbers in the pool.
 *
 * Set to 0 in wp-config.php to use the LFW-style ceiling with the unavailable
 * offset:  define( 'NERA_IWT_MAX_TICKET_NUMBER', 0 );
 *
 * NERA_IWT_MAX_TICKET_NUMBER is defined in the mu-plugin shim (so it is available
 * before any plugin loads) and in the main nera-instant-win-threshold plugin file.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the inclusive upper bound for shuffle / random ticket pools.
 *
 * @param WC_Product $product Lottery product.
 * @return int At least 1.
 */
function nera_iwt_resolve_shuffle_random_pool_max( $product ) {

	if ( defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) && NERA_IWT_MAX_TICKET_NUMBER > 0 ) {
		return max( 1, (int) NERA_IWT_MAX_TICKET_NUMBER );
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
