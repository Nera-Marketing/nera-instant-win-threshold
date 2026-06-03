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

	// _nera_iwt_ticket_number_max not configured: default the pool ceiling to the
	// product's own LFW maximum tickets so the pool always covers every buyer slot.
	if ( is_object( $product ) && method_exists( $product, 'get_lty_maximum_tickets' ) ) {
		$lty_max = absint( $product->get_lty_maximum_tickets() );
		if ( $lty_max > 0 ) {
			return $lty_max;
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

	$lfw_based = max( 1, $base + $extra );

	if ( $configured > 0 ) {
		// The configured cap sets the preferred pool ceiling but must never fall
		// below the product's own maximum tickets — if it did, the pool would be
		// exhausted before all buyer slots are filled, causing checkout errors.
		return max( $configured, $lfw_based );
	}

	return $lfw_based;
}

/**
 * Ticket numbers the generator must never assign, as an O(1) lookup hash
 * ( "number" => true ): already-placed tickets PLUS instant-win prize tickets
 * that are currently locked (schedule not yet open, or ticket-sold % below the
 * configured threshold).
 *
 * Excluding locked prize numbers directly in the generator — not only via the
 * nera_prize_hold posts that LFW's existence check reads — guarantees a locked
 * prize can never be auto-assigned on ANY order-creation path (classic checkout,
 * block checkout, or the REST/Store-API order-save path), independent of whether
 * the hold-sync hook happened to run for that particular request. This is what
 * prevents a ticket-% prize from being won before its threshold is reached.
 *
 * @param WC_Product $product Lottery product.
 * @return array<string,bool>
 */
function nera_iwt_generator_excluded_ticket_lookup( $product ) {
	$lookup = array();

	if ( is_object( $product ) && method_exists( $product, 'get_placed_tickets' ) ) {
		foreach ( (array) $product->get_placed_tickets() as $n ) {
			$lookup[ (string) $n ] = true;
		}
	}

	if ( function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ) {
		foreach ( (array) nera_iwt_get_unavailable_prize_ticket_numbers( $product ) as $n ) {
			$lookup[ (string) $n ] = true;
		}
	}

	return $lookup;
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

		// Excludes placed tickets AND currently-locked instant-win prize numbers.
		$placed_lookup = nera_iwt_generator_excluded_ticket_lookup( $product );

		$ticket_numbers = array();
		$picked         = array();
		// Safety cap: avoids infinite loops when the pool is nearly exhausted.
		$max_attempts = $quantity * 200;
		$attempts     = 0;

		while ( count( $ticket_numbers ) < $quantity && $attempts < $max_attempts ) {
			$num = (string) mt_rand( 1, $max );
			if ( ! isset( $picked[ $num ] ) && ! isset( $placed_lookup[ $num ] ) ) {
				$picked[ $num ]   = true;
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
	 * Two strategies, chosen by pool size to avoid materialising a huge range:
	 *   • Small pool (<= NERA_IWT_SHUFFLE_MATERIALIZE_MAX): build the full
	 *     range, diff placed, shuffle, slice — exact even when nearly exhausted.
	 *   • Large pool: rejection sampling (random picks). range(1, 999999) would
	 *     allocate ~1M integers (~30 MB+) and shuffle them on EVERY checkout-loop
	 *     iteration, which exhausts memory / CPU time and stalls checkout.
	 *
	 * @param WC_Product $product  Lottery product.
	 * @param int        $quantity Number of ticket numbers to return.
	 * @return array
	 */
	function lty_get_remaining_shuffle_ticket_numbers( $product, $quantity ) {

		$max      = nera_iwt_resolve_shuffle_random_pool_max( $product );
		$quantity = max( 1, (int) $quantity );

		// Excludes placed tickets AND currently-locked instant-win prize numbers.
		$placed_lookup = nera_iwt_generator_excluded_ticket_lookup( $product );

		// Threshold above which we never materialise the full range in memory.
		$materialize_max = defined( 'NERA_IWT_SHUFFLE_MATERIALIZE_MAX' )
			? (int) NERA_IWT_SHUFFLE_MATERIALIZE_MAX
			: 50000;

		if ( $max <= $materialize_max ) {
			// Small pool: exact shuffle of the remaining numbers.
			$exclude_int = array_map( 'intval', array_keys( $placed_lookup ) );
			$pool        = array_values( array_diff( range( 1, $max ), $exclude_int ) );
			if ( empty( $pool ) ) {
				return array();
			}
			shuffle( $pool );
			return array_map( 'strval', array_slice( $pool, 0, $quantity ) );
		}

		// Large pool: rejection sampling — never allocates the whole range.
		$ticket_numbers = array();
		$picked         = array();
		$max_attempts   = $quantity * 200;
		$attempts       = 0;

		while ( count( $ticket_numbers ) < $quantity && $attempts < $max_attempts ) {
			$num = (string) mt_rand( 1, $max );
			if ( ! isset( $picked[ $num ] ) && ! isset( $placed_lookup[ $num ] ) ) {
				$picked[ $num ]   = true;
				$ticket_numbers[] = $num;
			}
			++$attempts;
		}

		return $ticket_numbers;
	}
}
