<?php
/**
 * Held-back prizes — automatic activation safety net (Phase 3).
 *
 * A held prize that is never activated by hand would advertise an "available" prize that can
 * never be won — a UK prize-competition compliance risk. This layer guarantees it cannot be
 * forgotten:
 *
 *  1. Auto-activation — once tickets sold reach a threshold (default 90%, configurable per
 *     product), every still-held prize is activated onto its own unsold number, most-valuable
 *     first. Checked after every order and hourly by cron. This cannot be switched off; only
 *     the threshold is tunable.
 *  2. Low-margin warning — when the unsold pool runs low relative to prizes still held, an
 *     admin dashboard notice + a throttled email are raised.
 *  3. Unplaceable alert — a held prize that cannot be given a number is flagged and the admin
 *     is emailed (the end-of-competition remedy handles the final disposition).
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default auto-activation threshold (percent of tickets sold) when a product sets none.
 * Override globally with define( 'NERA_IWT_HELD_AUTOTRIGGER_PCT', 85 );
 */
if ( ! defined( 'NERA_IWT_HELD_AUTOTRIGGER_PCT' ) ) {
	define( 'NERA_IWT_HELD_AUTOTRIGGER_PCT', 90 );
}

/**
 * Auto-activation threshold for a product: per-product meta, else global default, filterable.
 * Always within 1–100 (never 0/off — auto-activation is mandatory for compliance).
 *
 * @param WC_Product $product Lottery product.
 * @return int Percent 1–100.
 */
function nera_iwt_get_held_autotrigger_pct( $product ) {
	$pct = 0;
	if ( $product instanceof WC_Product ) {
		$meta = get_post_meta( $product->get_id(), 'nera_iwt_held_autotrigger_pct', true );
		if ( is_numeric( $meta ) ) {
			$pct = (int) $meta;
		}
	}
	if ( $pct < 1 || $pct > 100 ) {
		$pct = (int) NERA_IWT_HELD_AUTOTRIGGER_PCT;
	}
	$pct = max( 1, min( 100, $pct ) );

	/**
	 * Filter the held-prize auto-activation threshold percent for a product.
	 *
	 * @param int        $pct     Percent 1–100.
	 * @param WC_Product $product Lottery product.
	 */
	return (int) apply_filters( 'nera_iwt_held_autotrigger_pct', $pct, $product );
}

/**
 * Dashboard warning threshold (percent of tickets sold) for a product.
 *
 * Per-product meta `nera_iwt_held_warn_pct`, else default = auto% − 10. Always clamped BELOW the
 * auto-activation threshold (the yellow warning must precede the red auto-activate).
 *
 * @param WC_Product $product Lottery product.
 * @return int Percent 1–(auto−1).
 */
function nera_iwt_get_held_warn_pct( $product ) {
	$auto = nera_iwt_get_held_autotrigger_pct( $product );
	$max  = max( 1, $auto - 1 );

	$warn = 0;
	if ( $product instanceof WC_Product ) {
		$meta = get_post_meta( $product->get_id(), 'nera_iwt_held_warn_pct', true );
		if ( is_numeric( $meta ) ) {
			$warn = (int) $meta;
		}
	}
	if ( $warn < 1 ) {
		$warn = max( 1, $auto - 10 ); // default: 10 points before auto.
	}
	$warn = min( $warn, $max ); // never at/above the auto threshold.

	/**
	 * Filter the held-prize dashboard warning threshold percent for a product.
	 *
	 * @param int        $warn    Percent 1–(auto−1).
	 * @param WC_Product $product Lottery product.
	 */
	return (int) apply_filters( 'nera_iwt_held_warn_pct', $warn, $product );
}

/**
 * Whether held-back notification emails may be sent. OFF by default — email is deferred; the
 * dashboard warnings are the current channel. Enable later with
 * define( 'NERA_IWT_HELD_EMAILS_ENABLED', true ); (or the filter).
 *
 * @return bool
 */
function nera_iwt_held_emails_enabled() {
	$on = defined( 'NERA_IWT_HELD_EMAILS_ENABLED' ) && NERA_IWT_HELD_EMAILS_ENABLED;
	return (bool) apply_filters( 'nera_iwt_held_emails_enabled', $on );
}

/**
 * Held-prize rule IDs for a product, most-valuable first (Q3 priority), optionally only those
 * still held (not yet activated).
 *
 * @param WC_Product $product   Lottery product.
 * @param bool       $only_held True to return only rules whose state is not 'active'.
 * @return int[] Rule IDs ordered by prize amount desc.
 */
function nera_iwt_get_held_prize_rule_ids( $product, $only_held = true ) {
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return array();
	}

	$rows = array();
	foreach ( (array) lty_get_instant_winner_rule_ids( $product->get_id() ) as $rid ) {
		$rid = absint( $rid );
		if ( $rid <= 0 ) {
			continue;
		}
		if ( NERA_IWT_RULE_TYPE_HELD !== (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true ) ) {
			continue;
		}
		$state = (string) get_post_meta( $rid, 'nera_iwt_held_state', true );
		if ( $only_held ) {
			// "Still held" = genuinely awaiting activation. Exclude prizes already resolved:
			// activated (live), drawn, or won — otherwise a Drawn/Won prize keeps counting as
			// "not activated" (stale dashboard warning) and the auto-activation sweep re-processes
			// it (and mis-marks it unplaceable). Unwon 'unplaceable' still counts (real problem).
			if ( 'active' === $state || 'drawn' === $state ) {
				continue;
			}
			if ( function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rid ) ) {
				continue;
			}
		}
		$amount  = (float) get_post_meta( $rid, 'lty_prize_amount', true );
		$rows[]  = array( 'id' => $rid, 'amount' => $amount );
	}

	// Most valuable first; stable on ties by rule ID (creation order).
	usort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['amount'] === $b['amount'] ) {
				return $a['id'] <=> $b['id'];
			}
			return $b['amount'] <=> $a['amount'];
		}
	);

	return array_map( static function ( $r ) {
		return (int) $r['id'];
	}, $rows );
}

/**
 * Count of currently-unsold, assignable ticket numbers for a product.
 *
 * @param WC_Product $product Lottery product.
 * @return int
 */
function nera_iwt_held_unsold_count( $product ) {
	$sellable = nera_iwt_held_all_sellable_ticket_strings( $product );
	if ( empty( $sellable ) ) {
		return 0;
	}
	$taken = nera_iwt_held_taken_ticket_strings( $product, 0 );
	$free  = 0;
	foreach ( $sellable as $t ) {
		if ( ! isset( $taken[ $t ] ) ) {
			++$free;
		}
	}
	return $free;
}

/**
 * Flush LFW's cached ticket-count transients for a product.
 *
 * The sold count is served from the `lty_purchased_ticket_count_{id}` transient (1h TTL). When our
 * order hook fires immediately after a purchase, that transient is still stale — it does not yet
 * include the ticket LFW just created — so the sold % reads low and a threshold-crossing order is
 * missed until the hourly cron or a product re-save refreshes it. Flushing here forces the very
 * next count read to recompute from the database.
 *
 * @param WC_Product $product Lottery product.
 * @return void
 */
function nera_iwt_held_flush_ticket_counts( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$pid = $product->get_id();
	if ( class_exists( 'LTY_Transient_Handler' ) && method_exists( 'LTY_Transient_Handler', 'delete_all_transients' ) ) {
		LTY_Transient_Handler::delete_all_transients( $pid );
	} else {
		delete_transient( 'lty_purchased_ticket_count_' . $pid );
		delete_transient( 'lty_placed_ticket_count_' . $pid );
	}
}

/**
 * Auto-activate every still-held prize on a product once the sold threshold is reached, and
 * raise safety warnings. Idempotent and safe to call on every order + cron.
 *
 * @param WC_Product $product    Lottery product.
 * @param int        $extra_sold In-flight tickets to project into the sold % (checkout path).
 * @return void
 */
function nera_iwt_auto_activate_due_held_prizes( $product, $extra_sold = 0 ) {
	if ( ! $product instanceof WC_Product || 'lottery' !== $product->get_type() ) {
		return;
	}

	$held_ids = nera_iwt_get_held_prize_rule_ids( $product, true );
	if ( empty( $held_ids ) ) {
		return;
	}

	// Drop LFW's stale cached count so the threshold check below sees tickets bought moments ago.
	nera_iwt_held_flush_ticket_counts( $product );

	$pct_sold  = nera_iwt_get_lottery_ticket_sold_percent( $product, $extra_sold );
	$threshold = nera_iwt_get_held_autotrigger_pct( $product );
	if ( null === $pct_sold || (float) $pct_sold < (float) $threshold ) {
		return;
	}

	$activated   = array();
	$unplaceable = array();
	foreach ( $held_ids as $rule_id ) {
		$res = nera_iwt_activate_held_prize( $rule_id, '' );
		if ( is_wp_error( $res ) ) {
			// 'already active' / 'already won' can happen if a concurrent run or a purchase beat us
			// — not a failure, and must NOT be re-marked unplaceable.
			$code = $res->get_error_code();
			if ( 'nera_iwt_held_already_active' === $code || 'nera_iwt_held_already_won' === $code ) {
				continue;
			}
			$unplaceable[ $rule_id ] = $res->get_error_message();
			nera_iwt_held_mark_unplaceable( $rule_id, $res->get_error_message() );
		} else {
			$activated[ $rule_id ] = $res['number'];
		}
	}

	if ( ! empty( $activated ) || ! empty( $unplaceable ) ) {
		nera_iwt_held_email_auto_activation_summary( $product, $threshold, $activated, $unplaceable );
	}

	/**
	 * Fires after an auto-activation sweep for a product.
	 *
	 * @param WC_Product $product     Lottery product.
	 * @param array      $activated   rule_id => assigned number.
	 * @param array      $unplaceable rule_id => error message.
	 */
	do_action( 'nera_iwt_held_auto_activation_swept', $product, $activated, $unplaceable );
}

/**
 * Flag a held prize that could not be placed (no unsold number) so the storefront can hide it
 * and the end-of-competition remedy can pick it up.
 *
 * @param int    $rule_id Rule post ID.
 * @param string $reason  Failure reason.
 * @return void
 */
function nera_iwt_held_mark_unplaceable( $rule_id, $reason = '' ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 ) {
		return;
	}
	update_post_meta( $rule_id, 'nera_iwt_held_state', 'unplaceable' );
	update_post_meta( $rule_id, 'nera_iwt_held_unplaceable_reason', (string) $reason );

	if ( function_exists( 'nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule' ) ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id );
	}

	/**
	 * Fires when a held prize is marked unplaceable.
	 *
	 * @param int    $rule_id Rule post ID.
	 * @param string $reason  Failure reason.
	 */
	do_action( 'nera_iwt_held_prize_unplaceable', $rule_id, $reason );
}

/**
 * Live dashboard warning level for a product's held prizes:
 *  - 'red'    → fewer unsold ticket numbers than held prizes still waiting (some can't be placed).
 *  - 'yellow' → tickets sold reached the warn threshold (auto-activation is approaching).
 *  - null     → no warning.
 * Red takes precedence.
 *
 * @param WC_Product $product Lottery product.
 * @return array{level:string,held:int,unsold:int,sold_pct:?float,warn:int,auto:int}|null
 */
function nera_iwt_held_product_warning( $product ) {
	if ( ! $product instanceof WC_Product || 'lottery' !== $product->get_type() ) {
		return null;
	}

	$held = count( nera_iwt_get_held_prize_rule_ids( $product, true ) ); // still-held (not active)
	if ( $held <= 0 ) {
		return null; // nothing waiting → no warning.
	}

	$unsold   = nera_iwt_held_unsold_count( $product );
	$sold_pct = nera_iwt_get_lottery_ticket_sold_percent( $product );
	$warn     = nera_iwt_get_held_warn_pct( $product );
	$auto     = nera_iwt_get_held_autotrigger_pct( $product );

	$level = '';
	if ( $unsold < $held ) {
		$level = 'red';    // mathematically cannot place every held prize.
	} elseif ( null !== $sold_pct && (float) $sold_pct >= (float) $warn ) {
		$level = 'yellow'; // approaching auto-activation.
	}

	if ( '' === $level ) {
		return null;
	}

	return array(
		'level'    => $level,
		'held'     => $held,
		'unsold'   => $unsold,
		'sold_pct' => ( null === $sold_pct ? null : round( (float) $sold_pct, 1 ) ),
		'warn'     => $warn,
		'auto'     => $auto,
	);
}

/**
 * Products (with held prizes) currently in a warning state, computed live. Scoped to products
 * that actually have held-back rules, so it stays cheap even with many competitions.
 *
 * @return array<int,array> product_id => warning data (+ 'name').
 */
function nera_iwt_held_dashboard_warnings() {
	if ( ! function_exists( 'lty_get_instant_winner_rule_statuses' ) ) {
		return array();
	}

	$rule_ids = get_posts(
		array(
			'post_type'      => class_exists( 'LTY_Register_Post_Types', false ) ? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE : 'lty_instant_winners',
			'post_status'    => lty_get_instant_winner_rule_statuses(),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_key'       => 'nera_iwt_public_rule_type',
			'meta_value'     => NERA_IWT_RULE_TYPE_HELD,
		)
	);

	$product_ids = array();
	foreach ( (array) $rule_ids as $rid ) {
		$pid = (int) get_post_meta( absint( $rid ), 'lty_lottery_id', true );
		if ( $pid <= 0 ) {
			$pid = (int) wp_get_post_parent_id( absint( $rid ) );
		}
		if ( $pid > 0 ) {
			$product_ids[ $pid ] = true;
		}
	}

	$out = array();
	foreach ( array_keys( $product_ids ) as $pid ) {
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$w = nera_iwt_held_product_warning( $product );
		if ( is_array( $w ) ) {
			$w['name']   = $product->get_name();
			$out[ $pid ] = $w;
		}
	}

	return $out;
}

// ---------------------------------------------------------------------------
// EMAIL
// ---------------------------------------------------------------------------

/**
 * Recipient for held-prize safety emails (site admin, filterable).
 *
 * @return string
 */
function nera_iwt_held_email_recipient() {
	if ( ! nera_iwt_held_emails_enabled() ) {
		return ''; // emails deferred — all held-back email sends are gated off here.
	}
	return (string) apply_filters( 'nera_iwt_held_email_recipient', get_option( 'admin_email' ) );
}

/**
 * Email an auto-activation summary.
 *
 * @param WC_Product $product     Lottery product.
 * @param int        $threshold   Threshold that fired.
 * @param array      $activated   rule_id => number.
 * @param array      $unplaceable rule_id => reason.
 * @return void
 */
function nera_iwt_held_email_auto_activation_summary( $product, $threshold, array $activated, array $unplaceable ) {
	$to = nera_iwt_held_email_recipient();
	if ( '' === $to ) {
		return;
	}

	$name = $product->get_name();
	/* translators: 1: competition name */
	$subject = sprintf( __( '[Held prizes] Auto-activation ran for %s', 'nera-instant-win-threshold' ), $name );

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: competition name, 2: threshold percent */
		__( 'The %1$s competition reached its %2$d%% auto-activation threshold for held-back prizes.', 'nera-instant-win-threshold' ),
		$name,
		(int) $threshold
	);
	$lines[] = '';
	if ( ! empty( $activated ) ) {
		$lines[] = __( 'Activated (winning numbers kept private):', 'nera-instant-win-threshold' );
		$lines[] = sprintf( __( '%d prize(s) placed on unsold numbers.', 'nera-instant-win-threshold' ), count( $activated ) );
		$lines[] = '';
	}
	if ( ! empty( $unplaceable ) ) {
		$lines[] = __( 'COULD NOT be placed (needs your attention):', 'nera-instant-win-threshold' );
		foreach ( $unplaceable as $rid => $reason ) {
			$lines[] = sprintf( '- Rule #%d: %s', (int) $rid, $reason );
		}
	}

	wp_mail( $to, $subject, implode( "\n", $lines ) );
}

// ---------------------------------------------------------------------------
// TRIGGERS — after every order + hourly cron
// ---------------------------------------------------------------------------

/**
 * Run the auto-activation sweep for every lottery product in an order.
 *
 * @param int|WC_Order $order_or_id Order ID or object.
 * @return void
 */
function nera_iwt_held_auto_activate_for_order( $order_or_id ) {
	$order = wc_get_order( $order_or_id );
	if ( ! $order ) {
		return;
	}
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( $product && 'lottery' === $product->get_type() ) {
			nera_iwt_auto_activate_due_held_prizes( $product );
		}
	}
}
// NOTE: at checkout LFW has only created *pending* tickets (post_status lty_ticket_pending); the
// buyer tickets that get_purchased_ticket_count() counts are created later, when the order reaches
// a "complete" status (processing/completed) — see nera_iwt_held_auto_activate_on_ticket_confirmed
// below, which is the primary trigger. These two remain as a cheap best-effort for gateways that
// land an order straight in a counted status; they no-op when the buyer count hasn't moved yet.
add_action( 'woocommerce_checkout_update_order_meta', 'nera_iwt_held_auto_activate_for_order', 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'nera_iwt_held_auto_activate_for_order', 20 );

/**
 * Primary order trigger: LFW fires `lty_lottery_ticket_confirmed` right after it has promoted an
 * order's tickets to `lty_ticket_buyer` and flushed its count transients (LTY_Order_Handler, on
 * woocommerce_order_status_{processing|completed}). Only here is the sold count guaranteed to
 * include the just-purchased tickets — so this is where the auto-activation sweep reliably fires.
 *
 * @param int   $first_ticket_id First confirmed ticket ID (unused).
 * @param array $ticket_data     [product_id][ticket_id] => ticket_number (unused).
 * @param int   $order_id        Order whose tickets were confirmed.
 * @return void
 */
function nera_iwt_held_auto_activate_on_ticket_confirmed( $first_ticket_id, $ticket_data, $order_id ) {
	if ( $order_id ) {
		nera_iwt_held_auto_activate_for_order( $order_id );
	}
}
add_action( 'lty_lottery_ticket_confirmed', 'nera_iwt_held_auto_activate_on_ticket_confirmed', 20, 3 );

/**
 * REST/Store-API order-save path (mirrors the hold-sync REST guard in ticket-numbers-override).
 *
 * @param WC_Order $order Order object.
 * @return void
 */
function nera_iwt_held_auto_activate_for_order_rest( $order ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->is_rest_api_request() ) {
		return;
	}
	if ( method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) {
		return;
	}
	nera_iwt_held_auto_activate_for_order( $order );
}
add_action( 'woocommerce_after_order_object_save', 'nera_iwt_held_auto_activate_for_order_rest', 20 );

/**
 * Hourly cron sweep: auto-activate due held prizes on every published lottery product.
 * Piggybacks the existing nera_iwt_sync_hold_cron event.
 *
 * @return void
 */
function nera_iwt_held_cron_auto_activate_all() {
	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => 'lottery',
				),
			),
		)
	);

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product && 'lottery' === $product->get_type() ) {
			nera_iwt_auto_activate_due_held_prizes( $product );
		}
	}
}
add_action( 'nera_iwt_sync_hold_cron', 'nera_iwt_held_cron_auto_activate_all' );

// ---------------------------------------------------------------------------
// DASHBOARD NOTICE — live held-prize warnings on the WP Dashboard home
// ---------------------------------------------------------------------------

/**
 * Print the held-notice CSS once per request: bigger heading, flex layout, and a pulsing warning
 * icon on the right. Reduced-motion users get no animation.
 *
 * @return void
 */
function nera_iwt_held_notice_styles() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	echo '<style id="nera-iwt-held-notice-css">'
		. '.nera-iwt-held-notice{display:flex;align-items:center;gap:16px}'
		. '.nera-iwt-held-notice .nera-iwt-held-notice-body{flex:1 1 auto;min-width:0}'
		. '.nera-iwt-held-notice .nera-iwt-held-notice-title{font-size:16px;line-height:1.4;margin:.5em 0}'
		. '.nera-iwt-held-notice .nera-iwt-held-notice-title strong{font-size:16px;font-weight:700}'
		. '.nera-iwt-held-notice .nera-iwt-held-notice-icon{flex:0 0 auto;font-size:30px;width:30px;height:30px;line-height:30px;margin-right:4px;animation:nera-iwt-held-pulse 1.1s ease-in-out infinite}'
		. '.nera-iwt-held-notice--red .nera-iwt-held-notice-icon{color:#d63638}'
		. '.nera-iwt-held-notice--yellow .nera-iwt-held-notice-icon{color:#dba617}'
		. '@keyframes nera-iwt-held-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.35)}}'
		. '@media(prefers-reduced-motion:reduce){.nera-iwt-held-notice .nera-iwt-held-notice-icon{animation:none}}'
		. '</style>';
}

/**
 * Cached wrapper for {@see nera_iwt_held_dashboard_warnings()}. The notice now renders on every admin
 * screen (like WooCommerce's outdated-template notice), so the meta query behind it must not run on
 * every page load. Busted on any held-prize state change (see nera_iwt_held_flush_warnings_cache).
 *
 * @return array
 */
function nera_iwt_held_get_warnings_cached() {
	$cached = get_transient( 'nera_iwt_held_warnings' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$warnings = nera_iwt_held_dashboard_warnings();
	set_transient( 'nera_iwt_held_warnings', $warnings, 5 * MINUTE_IN_SECONDS );
	return $warnings;
}

/**
 * Drop the cached warnings so the next admin page recomputes them after a state change.
 *
 * @return void
 */
function nera_iwt_held_flush_warnings_cache() {
	delete_transient( 'nera_iwt_held_warnings' );
}
add_action( 'nera_iwt_held_prize_activated', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'nera_iwt_held_prize_deactivated', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'nera_iwt_held_auto_activation_swept', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'nera_iwt_held_prize_unplaceable', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'nera_iwt_held_draw_awarded', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'lty_instant_winner_rules_saved', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'lty_instant_winner_rule_created', 'nera_iwt_held_flush_warnings_cache' );
add_action( 'lty_instant_winner_rules_deleted', 'nera_iwt_held_flush_warnings_cache' );

/**
 * Show live held-prize warnings on EVERY admin screen (like WooCommerce's outdated-template notice),
 * listing each affected competition by name (with an edit link) + numbers. Red = can't place all;
 * yellow = approaching auto-activation. Each banner has a bigger heading and a pulsing warning icon.
 *
 * @return void
 */
function nera_iwt_held_dashboard_notice() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$warnings = nera_iwt_held_get_warnings_cached();
	if ( empty( $warnings ) ) {
		return;
	}

	$red    = array();
	$yellow = array();
	foreach ( $warnings as $pid => $w ) {
		if ( 'red' === $w['level'] ) {
			$red[ $pid ] = $w;
		} else {
			$yellow[ $pid ] = $w;
		}
	}

	nera_iwt_held_notice_styles();

	if ( ! empty( $red ) ) {
		echo '<div class="notice notice-error nera-iwt-held-notice nera-iwt-held-notice--red"><div class="nera-iwt-held-notice-body"><p class="nera-iwt-held-notice-title"><strong>' . esc_html__( 'Held-back prizes — not enough tickets left:', 'nera-instant-win-threshold' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $red as $pid => $w ) {
			echo '<li>' . wp_kses_post(
				sprintf(
					/* translators: 1: competition link, 2: unsold count, 3: held count */
					__( '%1$s — only %2$d unsold ticket(s) but %3$d held prize(s) still waiting; some cannot be placed.', 'nera-instant-win-threshold' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( $w['name'] ) . '</a>',
					(int) $w['unsold'],
					(int) $w['held']
				)
			) . '</li>';
		}
		echo '</ul></div><span class="nera-iwt-held-notice-icon dashicons dashicons-warning" aria-hidden="true"></span></div>';
	}

	if ( ! empty( $yellow ) ) {
		echo '<div class="notice notice-warning nera-iwt-held-notice nera-iwt-held-notice--yellow"><div class="nera-iwt-held-notice-body"><p class="nera-iwt-held-notice-title"><strong>' . esc_html__( 'Held-back prizes — approaching auto-activation:', 'nera-instant-win-threshold' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $yellow as $pid => $w ) {
			echo '<li>' . wp_kses_post(
				sprintf(
					/* translators: 1: competition link, 2: percent sold, 3: held count, 4: auto percent */
					__( '%1$s — %2$s%% sold, %3$d held prize(s) not activated (auto-activates at %4$d%%). Activate manually if you want to choose the numbers.', 'nera-instant-win-threshold' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( $w['name'] ) . '</a>',
					( null === $w['sold_pct'] ? '?' : esc_html( (string) $w['sold_pct'] ) ),
					(int) $w['held'],
					(int) $w['auto']
				)
			) . '</li>';
		}
		echo '</ul></div><span class="nera-iwt-held-notice-icon dashicons dashicons-warning" aria-hidden="true"></span></div>';
	}
}
add_action( 'admin_notices', 'nera_iwt_held_dashboard_notice' );

// ---------------------------------------------------------------------------
// ADMIN FIELDS — per-product auto-activate % + warn %
//
// The two number inputs render in the Instant Win Prizes tab (a "Held-back settings" block
// injected by assets/admin-rule-visibility.js — the General tab is hidden for lottery products).
// They are saved with the main product Update below, and activate-on-save runs so a threshold
// change that is already met takes effect immediately (not only on the next order / cron).
// ---------------------------------------------------------------------------

/**
 * Save auto-activate % + warn %, then run an immediate activation sweep if already due.
 *
 * @param int $post_id Product ID.
 * @return void
 */
function nera_iwt_held_save_threshold_fields( $post_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies its nonce first.
	if ( isset( $_POST['nera_iwt_held_autotrigger_pct'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_POST['nera_iwt_held_autotrigger_pct'] ) );
		if ( '' === $raw ) {
			delete_post_meta( $post_id, 'nera_iwt_held_autotrigger_pct' );
		} else {
			update_post_meta( $post_id, 'nera_iwt_held_autotrigger_pct', max( 1, min( 100, (int) $raw ) ) );
		}
	}
	if ( isset( $_POST['nera_iwt_held_warn_pct'] ) ) {
		$raw_warn = sanitize_text_field( wp_unslash( $_POST['nera_iwt_held_warn_pct'] ) );
		if ( '' === $raw_warn ) {
			delete_post_meta( $post_id, 'nera_iwt_held_warn_pct' );
		} else {
			update_post_meta( $post_id, 'nera_iwt_held_warn_pct', max( 1, min( 100, (int) $raw_warn ) ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// activate-on-save: apply immediately if the (possibly just-changed) threshold is already met.
	$product = wc_get_product( $post_id );
	if ( $product instanceof WC_Product && 'lottery' === $product->get_type() && function_exists( 'nera_iwt_auto_activate_due_held_prizes' ) ) {
		nera_iwt_auto_activate_due_held_prizes( $product );
	}
}
// Priority 30: after LFW has saved the lottery meta (max tickets, dates, generation type).
add_action( 'woocommerce_process_product_meta', 'nera_iwt_held_save_threshold_fields', 30 );
