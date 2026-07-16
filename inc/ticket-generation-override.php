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
 *   1) Product LFW Maximum Tickets > 0  →  that value.
 *   2) NERA_IWT_MAX_TICKET_NUMBER > 0  →  constant (wp-config / mu-plugin shim fallback).
 *   3) 0  →  caller uses LFW-style math.
 *
 * Set NERA_IWT_MAX_TICKET_NUMBER to 0 in wp-config.php for pure LFW-style ceiling.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configured numeric ticket pool ceiling for shuffle/random pools and instant-win validation.
 *
 * Resolution order: 1) product LFW Maximum Tickets, 2) {@see NERA_IWT_MAX_TICKET_NUMBER} constant, 3) 0 (caller uses LFW-style math).
 *
 * @param WC_Product|null $product Lottery product.
 * @return int 0 or inclusive maximum ticket number (at least 1 when non-zero).
 */
function nera_iwt_get_configured_ticket_pool_max( $product ) {
	// Default the pool ceiling to the product's own LFW maximum tickets so the
	// pool always covers every buyer slot.
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

	if ( $configured > 0 ) {
		// Fixed pool (reserve-slots): ceiling is N, not N + locked prizes.
		return max( $configured, $base );
	}

	// No configured cap: pool covers maximum ticket sales only (no +held expansion).
	return $base;
}

/**
 * Whether shuffle/random generation returned fewer numbers than requested because the
 * unlocked pool is exhausted (typically infeasible ticket-% reserve-slots config).
 *
 * @param WC_Product $product   Lottery product.
 * @param int        $requested Requested quantity.
 * @param array      $generated Generated ticket numbers.
 * @return bool
 */
function nera_iwt_is_pool_generation_shortfall( $product, $requested, $generated, $extra_sold = 0 ) {
	$requested = max( 1, (int) $requested );
	$generated = is_array( $generated ) ? $generated : array();
	if ( count( $generated ) >= $requested ) {
		return false;
	}

	$max = nera_iwt_resolve_shuffle_random_pool_max( $product );
	if ( $max <= 0 ) {
		return false;
	}

	$lookup = nera_iwt_generator_excluded_ticket_lookup( $product, $extra_sold );
	$unlocked_remaining = 0;
	for ( $i = 1; $i <= $max; $i++ ) {
		if ( ! isset( $lookup[ (string) $i ] ) ) {
			++$unlocked_remaining;
		}
	}

	return $unlocked_remaining < ( $requested - count( $generated ) );
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
function nera_iwt_generator_excluded_ticket_lookup( $product, $extra_sold = 0 ) {
	$lookup = array();

	if ( is_object( $product ) && method_exists( $product, 'get_placed_tickets' ) ) {
		foreach ( (array) $product->get_placed_tickets() as $n ) {
			$lookup[ (string) $n ] = true;
		}
	}

	if ( function_exists( 'nera_iwt_get_unavailable_prize_ticket_numbers' ) ) {
		foreach ( (array) nera_iwt_get_unavailable_prize_ticket_numbers( $product, $extra_sold ) as $n ) {
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

		// Resolve in-flight projection (0 if not set; never falls back to $quantity).
		$extra_sold = function_exists( 'nera_iwt_get_generation_projection_extra_sold' )
			? nera_iwt_get_generation_projection_extra_sold( $product )
			: 0;

		// Excludes placed tickets AND currently-locked instant-win prize numbers (with projection).
		$placed_lookup = nera_iwt_generator_excluded_ticket_lookup( $product, $extra_sold );

		// Threshold above which we never materialise the full range in memory.
		$materialize_max = defined( 'NERA_IWT_SHUFFLE_MATERIALIZE_MAX' )
			? (int) NERA_IWT_SHUFFLE_MATERIALIZE_MAX
			: 50000;

		if ( $max <= $materialize_max ) {
			// Small pool: exact drain — guarantees all remaining numbers are returned when
			// the buyer requests >= pool size (no random under-fill near exhaustion).
			$exclude_int    = array_map( 'intval', array_keys( $placed_lookup ) );
			$pool           = array_values( array_diff( range( 1, $max ), $exclude_int ) );
			if ( empty( $pool ) ) {
				return array();
			}
			shuffle( $pool );
			$ticket_numbers = array_map( 'strval', array_slice( $pool, 0, $quantity ) );
			if ( nera_iwt_is_pool_generation_shortfall( $product, $quantity, $ticket_numbers, $extra_sold ) ) {
				do_action( 'nera_iwt_pool_generation_exhausted', $product, $quantity, $ticket_numbers );
			}
			return $ticket_numbers;
		}

		// Large pool: rejection sampling — never allocates the whole range.
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

		if ( nera_iwt_is_pool_generation_shortfall( $product, $quantity, $ticket_numbers, $extra_sold ) ) {
			do_action( 'nera_iwt_pool_generation_exhausted', $product, $quantity, $ticket_numbers );
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

		// Resolve in-flight projection (0 if not set; never falls back to $quantity).
		$extra_sold = function_exists( 'nera_iwt_get_generation_projection_extra_sold' )
			? nera_iwt_get_generation_projection_extra_sold( $product )
			: 0;

		// Excludes placed tickets AND currently-locked instant-win prize numbers (with projection).
		$placed_lookup = nera_iwt_generator_excluded_ticket_lookup( $product, $extra_sold );

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
			$ticket_numbers = array_map( 'strval', array_slice( $pool, 0, $quantity ) );
			if ( nera_iwt_is_pool_generation_shortfall( $product, $quantity, $ticket_numbers, $extra_sold ) ) {
				do_action( 'nera_iwt_pool_generation_exhausted', $product, $quantity, $ticket_numbers );
			}
			return $ticket_numbers;
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

		if ( nera_iwt_is_pool_generation_shortfall( $product, $quantity, $ticket_numbers, $extra_sold ) ) {
			do_action( 'nera_iwt_pool_generation_exhausted', $product, $quantity, $ticket_numbers );
		}

		return $ticket_numbers;
	}
}

// ---------------------------------------------------------------------------
// RULE MATCH BY TICKET NUMBER — an empty / unassigned number is never a match.
//
// Held-back prizes carry an EMPTY lty_ticket_number until activation. LFW's own
// lty_get_rule_id_by_ticket_number() matches by exact meta value, so an empty
// number would (a) collide with every other empty held prize on save — LFW's add
// throws "Ticket Number Already exists" and its bulk-save skips the row — and
// (b) never usefully match a sold ticket anyway (sold tickets always carry a real
// number). Returning false for an empty number lets any number of held prizes
// coexist and save, without changing behaviour for real numbers.
//
// Pluggable in LFW (if !function_exists); this file loads via the mu-plugin shim
// BEFORE lottery-for-woocommerce, so this definition wins. Bodies run at call time
// (LFW fully loaded), so referencing LFW symbols here is safe.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'lty_get_rule_id_by_ticket_number' ) ) {

	/**
	 * Instant-winner rule ID whose stored ticket number equals $ticket_number, or false.
	 * An empty $ticket_number returns false (unassigned held prizes never collide/match).
	 *
	 * @param int    $lottery_id    Lottery ID.
	 * @param string $ticket_number Ticket number to match.
	 * @return int|false
	 */
	function lty_get_rule_id_by_ticket_number( $lottery_id, $ticket_number ) {
		if ( '' === (string) $ticket_number ) {
			return false;
		}

		$rule_ids = get_posts(
			array(
				'post_type'      => LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE,
				'post_status'    => lty_get_instant_winner_rule_statuses(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => 'lty_ticket_number',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $ticket_number,
				'post_parent'    => $lottery_id,
			)
		);

		return ( is_array( $rule_ids ) && ! empty( $rule_ids ) ) ? reset( $rule_ids ) : false;
	}
}
