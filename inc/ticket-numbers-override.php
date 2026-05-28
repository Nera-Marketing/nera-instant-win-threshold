<?php
/**
 * Prize Ticket Hold — exclude unavailable instant-win tickets from LFW generation
 *
 * How it works
 * ─────────────
 * lottery-for-woocommerce generates order ticket numbers in a while-loop inside
 * get_ticket_numbers() → lty_check_is_ticket_number_exists().  That query runs:
 *
 *   WHERE post_type  = 'lty_lottery_ticket'
 *     AND post_status IN ( lty_get_ticket_statuses() )   ← filterable
 *     AND post_parent = lottery_id
 *     AND meta lty_ticket_number IN ( $candidates )
 *
 * If a candidate ticket number is found the loop retries until it picks a
 * number that is NOT in the existing set.
 *
 * We register a custom post status 'nera_prize_hold' and inject it into
 * lty_ticket_statuses.  For every instant-win prize that is currently
 * unavailable (schedule not yet open, or ticket-sold-% not yet met) we create
 * a minimal lty_lottery_ticket post with that status.  LFW's existence check
 * finds those posts → retries → customers never receive an unavailable prize
 * ticket number.
 *
 * When a prize becomes available again we delete the hold post so LFW can
 * assign the number normally.
 *
 * Sync runs
 *   1. woocommerce_checkout_update_order_meta @ 1  (before LFW @ 10)
 *   2. nera_iwt_sync_hold_cron daily cron event
 *
 * LFW ticket generation is untouched; this plugin adds no custom generation
 * and no display overrides.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// CUSTOM POST STATUS — nera_prize_hold
// ---------------------------------------------------------------------------

/**
 * Register the nera_prize_hold post status used on lty_lottery_ticket posts.
 * Must run on 'init' so WordPress knows the status before any query uses it.
 *
 * @return void
 */
function nera_iwt_register_prize_hold_status() {
	register_post_status(
		'nera_prize_hold',
		array(
			'label'                     => _x( 'Prize Hold', 'post status', 'nera-instant-win-threshold' ),
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		)
	);
}
add_action( 'init', 'nera_iwt_register_prize_hold_status' );

/**
 * Inject nera_prize_hold into LFW's ticket status list so
 * lty_check_is_ticket_number_exists() finds our hold posts.
 *
 * @param array $statuses Current status slugs.
 * @return array
 */
function nera_iwt_add_prize_hold_to_ticket_statuses( $statuses ) {
	if ( ! in_array( 'nera_prize_hold', $statuses, true ) ) {
		$statuses[] = 'nera_prize_hold';
	}
	return $statuses;
}
add_filter( 'lty_ticket_statuses', 'nera_iwt_add_prize_hold_to_ticket_statuses' );

// ---------------------------------------------------------------------------
// QUERY — resolve unavailable prize ticket numbers for a lottery product
// ---------------------------------------------------------------------------

/**
 * Return the ticket numbers of all instant-win prizes that are currently
 * unavailable (locked) for the given lottery product.
 *
 * rule_type = schedule
 *   • schedule_at only   → unavailable when now < schedule_at
 *   • schedule_at + end  → unavailable when now < schedule_at  OR  now > schedule_end
 *
 * rule_type = ticket_pct
 *   • unavailable when sold% < configured threshold
 *
 * rule_type = instant → always available; excluded from result.
 *
 * @param WC_Product $product Lottery WooCommerce product object.
 * @return array Ticket number values (strings or ints as stored).
 */
function nera_iwt_get_unavailable_prize_ticket_numbers( $product ) {

	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	if ( ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return array();
	}

	$lottery_id = method_exists( $product, 'get_lottery_id' )
		? (int) $product->get_lottery_id()
		: (int) $product->get_id();

	if ( $lottery_id <= 0 ) {
		return array();
	}

	$rule_ids = lty_get_instant_winner_rule_ids( $lottery_id );
	if ( ! is_array( $rule_ids ) || empty( $rule_ids ) ) {
		return array();
	}

	// Pre-compute shared values used across all rules.
	try {
		$now_local = new DateTimeImmutable( 'now', wp_timezone() );
	} catch ( Exception $e ) {
		$now_local = null;
	}
	$now_utc  = time();
	$pct_sold = nera_iwt_get_lottery_ticket_sold_percent( $product );

	$unavailable_tickets = array();

	foreach ( $rule_ids as $rule_id ) {

		$rule_id = absint( $rule_id );
		$type    = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );

		$valid_types = function_exists( 'nera_iwt_public_rule_type_slugs' )
			? nera_iwt_public_rule_type_slugs()
			: array( NERA_IWT_RULE_TYPE_INSTANT, NERA_IWT_RULE_TYPE_SCHEDULE, NERA_IWT_RULE_TYPE_TICKET_PCT );

		if ( '' === $type || ! in_array( $type, $valid_types, true ) ) {
			$type = NERA_IWT_RULE_TYPE_INSTANT;
		}

		// 'instant' prizes are always available — skip.
		if ( NERA_IWT_RULE_TYPE_INSTANT === $type ) {
			continue;
		}

		$is_unavailable = false;

		// -------------------------------------------------------------------
		// schedule rule
		// -------------------------------------------------------------------
		if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type ) {

			$at_l  = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_local', true ) );
			$end_l = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_end_local', true ) );

			if ( $now_local instanceof DateTimeImmutable && ( '' !== $at_l || '' !== $end_l ) ) {

				$at_dt  = '' !== $at_l  ? nera_iwt_parse_schedule_local_wp_timezone( $at_l )  : null;
				$end_dt = '' !== $end_l ? nera_iwt_parse_schedule_local_wp_timezone( $end_l ) : null;

				if ( null !== $at_dt || null !== $end_dt ) {
					if ( null !== $at_dt && null !== $end_dt ) {
						$is_unavailable = ( $now_local < $at_dt || $now_local > $end_dt );
					} elseif ( null !== $at_dt ) {
						$is_unavailable = ( $now_local < $at_dt );
					} else {
						$is_unavailable = ( $now_local > $end_dt );
					}
				}
			} else {
				// Fall back to UTC GMT meta.
				$at_gmt  = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_gmt', true ) );
				$end_gmt = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_end_gmt', true ) );
				$at_utc  = '' !== $at_gmt  ? nera_iwt_parse_schedule_gmt( $at_gmt )  : null;
				$end_utc = '' !== $end_gmt ? nera_iwt_parse_schedule_gmt( $end_gmt ) : null;

				if ( null !== $at_utc || null !== $end_utc ) {
					if ( null !== $at_utc && null !== $end_utc ) {
						$is_unavailable = ( $now_utc < $at_utc->getTimestamp() || $now_utc > $end_utc->getTimestamp() );
					} elseif ( null !== $at_utc ) {
						$is_unavailable = ( $now_utc < $at_utc->getTimestamp() );
					} else {
						$is_unavailable = ( $now_utc > $end_utc->getTimestamp() );
					}
				}
			}
		}

		// -------------------------------------------------------------------
		// ticket_pct rule
		// -------------------------------------------------------------------
		if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $type ) {

			$threshold = max( 0, min( 100, intval( get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) ) );

			if ( $threshold > 0 ) {
				$is_unavailable = ( null === $pct_sold || $pct_sold < (float) $threshold );
			}
		}

		if ( $is_unavailable ) {
			$ticket_number = get_post_meta( $rule_id, 'lty_ticket_number', true );
			if ( '' !== (string) $ticket_number ) {
				$unavailable_tickets[] = $ticket_number;
			}
		}
	}

	return $unavailable_tickets;
}

// ---------------------------------------------------------------------------
// SYNC — create / release hold ticket posts
// ---------------------------------------------------------------------------

/**
 * Keep hold ticket posts in sync with the current unavailability state.
 *
 * • Creates a nera_prize_hold lty_lottery_ticket post for every prize ticket
 *   that is currently unavailable and does not yet have a hold post.
 * • Deletes hold posts whose prize has since become available so LFW can
 *   assign those ticket numbers to customers again.
 *
 * @param WC_Product $product Lottery product.
 * @return void
 */
function nera_iwt_sync_prize_hold_tickets( $product ) {

	if ( ! $product instanceof WC_Product || 'lottery' !== $product->get_type() ) {
		return;
	}

	if ( ! function_exists( 'lty_create_new_lottery_ticket' ) ) {
		return;
	}

	$lottery_id = method_exists( $product, 'get_lottery_id' )
		? (int) $product->get_lottery_id()
		: (int) $product->get_id();

	if ( $lottery_id <= 0 ) {
		return;
	}

	// Query existing hold posts for this product.
	$hold_posts = get_posts(
		array(
			'post_type'      => 'lty_lottery_ticket',
			'post_status'    => 'nera_prize_hold',
			'post_parent'    => $lottery_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	// Map: ticket_number (string) → post_id.
	$held = array();
	foreach ( $hold_posts as $post_id ) {
		$num = (string) get_post_meta( $post_id, 'lty_ticket_number', true );
		if ( '' !== $num ) {
			$held[ $num ] = (int) $post_id;
		}
	}

	// Current unavailable ticket numbers.
	$unavailable = array_map( 'strval', nera_iwt_get_unavailable_prize_ticket_numbers( $product ) );

	// CREATE hold posts for newly unavailable tickets.
	foreach ( $unavailable as $ticket_number ) {
		if ( ! isset( $held[ $ticket_number ] ) ) {
			lty_create_new_lottery_ticket(
				array( 'lty_ticket_number' => $ticket_number ),
				array(
					'post_parent' => $lottery_id,
					'post_status' => 'nera_prize_hold',
				)
			);
		}
	}

	// DELETE hold posts for tickets whose prize is now available.
	foreach ( $held as $ticket_number => $post_id ) {
		if ( ! in_array( $ticket_number, $unavailable, true ) ) {
			wp_delete_post( $post_id, true );
		}
	}
}

// ---------------------------------------------------------------------------
// CHECKOUT HOOK — sync before LFW assigns tickets (priority 1 < LFW's 10)
//
// LFW registers create_ticket_on_placing_order on BOTH the classic checkout
// (`woocommerce_checkout_update_order_meta`) and the block-based Store API
// (`woocommerce_store_api_checkout_order_processed`). We must mirror both,
// otherwise orders placed through the Cart/Checkout blocks (or any payment
// gateway that uses the Store API, e.g. woo-wallet) generate tickets without
// the hold posts being in place — held prize ticket numbers can then be
// assigned to buyers.
//
// Both hooks pass an order *object* (block API) or an order *ID* (classic);
// wc_get_order() accepts either, so a single handler covers both.
// ---------------------------------------------------------------------------

/**
 * Sync hold tickets for every lottery item in the order before LFW's
 * create_ticket_on_placing_order runs.
 *
 * @param int|WC_Order $order_or_id WooCommerce order ID (classic checkout) or
 *                                  WC_Order object (Store API block checkout).
 * @return void
 */
function nera_iwt_sync_hold_before_lfw( $order_or_id ) {

	$order = wc_get_order( $order_or_id );
	if ( ! $order ) {
		return;
	}

	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = $item->get_product();
		if ( ! $product || 'lottery' !== $product->get_type() ) {
			continue;
		}
		nera_iwt_sync_prize_hold_tickets( $product );
	}
}
add_action( 'woocommerce_checkout_update_order_meta', 'nera_iwt_sync_hold_before_lfw', 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'nera_iwt_sync_hold_before_lfw', 1 );

// ---------------------------------------------------------------------------
// CRON — periodic sync to release prizes that became available without a
//         checkout triggering the sync (e.g. schedule window opens overnight)
// ---------------------------------------------------------------------------

/**
 * Schedule the daily hold-sync cron event on plugin load.
 *
 * @return void
 */
function nera_iwt_schedule_hold_sync_cron() {
	if ( ! wp_next_scheduled( 'nera_iwt_sync_hold_cron' ) ) {
		wp_schedule_event( time(), 'hourly', 'nera_iwt_sync_hold_cron' );
	}
}
add_action( 'wp', 'nera_iwt_schedule_hold_sync_cron' );

/**
 * Cron callback: iterate every published lottery product and sync its hold tickets.
 *
 * @return void
 */
function nera_iwt_cron_sync_all_hold_tickets() {

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
			nera_iwt_sync_prize_hold_tickets( $product );
		}
	}
}
add_action( 'nera_iwt_sync_hold_cron', 'nera_iwt_cron_sync_all_hold_tickets' );
