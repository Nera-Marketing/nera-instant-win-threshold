<?php
/**
 * Canonical ticket-number resolution for instant-win rules on lettered / formatted
 * (user-chooses) competitions.
 *
 * The bug this fixes
 * ──────────────────
 * On a user-chooses (manual) competition with the alphabet-with-sequence option — or
 * any prefix/suffix — the ticket a customer actually buys is stored as the FULL
 * formatted string produced by {@see LTY_Lottery_Product::get_overall_tickets()},
 * e.g. `L11382`, not the plain `11382`. Instant-win wins are matched by
 * {@see lty_get_rule_id_by_ticket_number()} with an EXACT `lty_ticket_number` meta
 * comparison. If the admin types the plain `11382` (or the wrong letter `M11382`),
 * LFW saves it verbatim, the string never equals the sold ticket's `L11382`, and the
 * prize can never be won — silently.
 *
 * The fix
 * ───────
 * On rule save (LFW's `lty_instant_winner_rule_data_before_save` filter, fired by both
 * the single-add and bulk-save AJAX handlers) we resolve whatever the admin typed to
 * the one canonical ticket string that the buyer's ticket will carry, using LFW's own
 * {@see get_overall_tickets()} list as the source of truth (so every alphabet mode,
 * prefix, suffix and zero-padding is handled without re-implementing LFW's formatter).
 * The admin-side AJAX validators reject out-of-range / ambiguous / wrong-letter input
 * up front so the mistake surfaces instead of being saved.
 *
 * Scope: only user-chooses (manual) products are touched — automatic sequential /
 * shuffle / random products store plain numeric strings and are left unchanged.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integer core of a formatted ticket string: strip the (known) prefix and suffix,
 * then read the first run of digits (the tab letter, if any, precedes the digits in
 * LFW's formatter, so the first digit run is always the ticket number).
 *
 * @param string $formatted Formatted ticket string, e.g. "L11382" or "NERA-00042-X".
 * @param string $prefix    Product ticket prefix ('' when none).
 * @param string $suffix    Product ticket suffix ('' when none).
 * @return int|null Integer core, or null when the string carries no digits.
 */
function nera_iwt_ticket_string_numeric_core( $formatted, $prefix, $suffix ) {
	$s = (string) $formatted;

	if ( '' !== $prefix && 0 === strpos( $s, $prefix ) ) {
		$s = substr( $s, strlen( $prefix ) );
	}
	if ( '' !== $suffix && '' !== $s && strlen( $s ) >= strlen( $suffix ) && substr( $s, -strlen( $suffix ) ) === $suffix ) {
		$s = substr( $s, 0, strlen( $s ) - strlen( $suffix ) );
	}

	if ( preg_match( '/(\d+)/', $s, $m ) ) {
		return (int) $m[1];
	}

	return null;
}

/**
 * Build (and request-cache) the canonical ticket lookup for a user-chooses product.
 *
 * `ordered` is the full ticket list in pool order (position 0 = 1st ticket), so a plain
 * running number N resolves to `ordered[N-1]`. `by_upper` maps an uppercased full ticket
 * string back to its stored casing.
 *
 * @param WC_Product $product Lottery product.
 * @return array{by_upper:array<string,string>,ordered:string[],example:string}
 */
function nera_iwt_manual_ticket_canonical_map( $product ) {
	static $cache = array();

	$pid = (int) $product->get_id();
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}

	$map = array(
		'by_upper' => array(),
		'ordered'  => array(),
		'example'  => '',
	);

	if ( ! method_exists( $product, 'get_overall_tickets' ) ) {
		$cache[ $pid ] = $map;
		return $map;
	}

	// Pool order is authoritative for running-position resolution — do NOT re-sort/filter.
	$map['ordered'] = array_map( 'strval', (array) $product->get_overall_tickets() );

	foreach ( $map['ordered'] as $ticket ) {
		if ( '' === $ticket ) {
			continue;
		}
		$map['by_upper'][ strtoupper( $ticket ) ] = $ticket;
		if ( '' === $map['example'] ) {
			$map['example'] = $ticket;
		}
	}

	$cache[ $pid ] = $map;
	return $map;
}

/**
 * Resolve an admin-entered instant-win ticket number to the canonical ticket string
 * the buyer's ticket will carry.
 *
 * Returns the input unchanged for products that store plain numeric tickets (automatic
 * sequential / shuffle / random) and for empty input (held-back / blank rows). Returns
 * a WP_Error for out-of-range, ambiguous (same number in several tabs), or otherwise
 * invalid (wrong-letter / typo) entries on user-chooses products.
 *
 * @param WC_Product $product Lottery product.
 * @param mixed      $raw     Admin-entered ticket number.
 * @return string|WP_Error Canonical ticket string, or WP_Error when invalid.
 */
function nera_iwt_canonicalize_instant_win_ticket_number( $product, $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	if ( ! $product instanceof WC_Product ) {
		return $raw;
	}

	// Only user-chooses (manual) products store a formatted ticket string.
	if ( ! method_exists( $product, 'is_manual_ticket' ) || ! $product->is_manual_ticket() ) {
		return $raw;
	}

	$map = nera_iwt_manual_ticket_canonical_map( $product );
	if ( empty( $map['by_upper'] ) ) {
		// List unavailable (product not fully configured) — never touch or reject.
		return $raw;
	}

	// 1) Already a valid full ticket string (any case) → normalise to stored casing.
	$upper = strtoupper( $raw );
	if ( isset( $map['by_upper'][ $upper ] ) ) {
		return $map['by_upper'][ $upper ];
	}

	// 2) Plain integer → the Nth ticket in pool order (running position across all tabs).
	//    Continuous-numbered comps (start 1): N is the visible number, e.g. 1382 → B1382.
	//    Reset-per-tab comps: N counts straight through the tabs, e.g. 101 → B1, 201 → C1.
	//    The admin can always type the full ticket (e.g. B13) instead; that path is handled above.
	if ( ctype_digit( $raw ) ) {
		$n     = (int) $raw;
		$total = count( $map['ordered'] );

		if ( $n >= 1 && $n <= $total && '' !== (string) $map['ordered'][ $n - 1 ] ) {
			return (string) $map['ordered'][ $n - 1 ];
		}

		return new WP_Error(
			'nera_iwt_ticket_number_out_of_range',
			sprintf(
				/* translators: 1: entered number, 2: total tickets, 3: example valid ticket */
				__( 'Ticket Number %1$s is out of range for this competition (valid 1–%2$d; example ticket: %3$s).', 'nera-instant-win-threshold' ),
				$raw,
				$total,
				$map['example']
			)
		);
	}

	// 3) Not a plain number and not a known ticket → wrong letter / typo.
	return new WP_Error(
		'nera_iwt_ticket_number_invalid',
		sprintf(
			/* translators: 1: entered value, 2: example valid ticket */
			__( '“%1$s” is not a valid ticket for this competition (example valid ticket: %2$s).', 'nera-instant-win-threshold' ),
			$raw,
			$map['example']
		)
	);
}

/**
 * Product currently being saved via the instant-winner AJAX handlers, read from the
 * request. LFW's `lty_instant_winner_rule_data_before_save` filter passes only the rule
 * data, so the product id comes from the (already nonce-checked) request payload.
 *
 * @return WC_Product|null
 */
function nera_iwt_current_ajax_lottery_product() {
	// Nonce is verified by LFW's own add/save handlers before this filter runs.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	if ( $product_id <= 0 ) {
		return null;
	}

	$product = wc_get_product( $product_id );
	return $product instanceof WC_Product ? $product : null;
}

/**
 * Canonicalise the rule's ticket number just before LFW persists it.
 *
 * On WP_Error (invalid entry) the value is left unchanged so nothing is corrupted; the
 * AJAX validators (priority 5, before LFW) reject invalid entries before we get here.
 *
 * @param array $rule_data Prepared rule meta about to be saved.
 * @return array
 */
function nera_iwt_filter_canonicalize_rule_ticket_number( $rule_data ) {
	if ( ! is_array( $rule_data ) || ! isset( $rule_data['lty_ticket_number'] ) ) {
		return $rule_data;
	}

	$product = nera_iwt_current_ajax_lottery_product();
	if ( ! $product ) {
		return $rule_data;
	}

	$canonical = nera_iwt_canonicalize_instant_win_ticket_number( $product, $rule_data['lty_ticket_number'] );
	if ( is_wp_error( $canonical ) ) {
		return $rule_data;
	}

	$rule_data['lty_ticket_number'] = $canonical;
	return $rule_data;
}
add_filter( 'lty_instant_winner_rule_data_before_save', 'nera_iwt_filter_canonicalize_rule_ticket_number' );
