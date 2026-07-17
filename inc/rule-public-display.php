<?php
/**
 * Public display rule: Instant vs scheduled vs ticket-% (admin UI + meta persistence).
 *
 * Storefront visibility lives in {@see inc/visibility.php}; cache/REST hooks in {@see inc/cache.php}.
 * This file owns: rule-type constants, schedule-time helpers, admin column/popup UI,
 * AJAX persistence, and the `posts_results` filter that hides logs from public LFW queries.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/** @var string */
const NERA_IWT_RULE_TYPE_INSTANT = 'instant';

/** @var string */
const NERA_IWT_RULE_TYPE_SCHEDULE = 'schedule';

/** @var string */
const NERA_IWT_RULE_TYPE_TICKET_PCT = 'ticket_pct';

/** @var string Held-back prize (Option B): shows as available with no number until activated. */
const NERA_IWT_RULE_TYPE_HELD = 'held';

/**
 * Allowed rule_type values stored in post meta.
 *
 * @return string[]
 */
function nera_iwt_public_rule_type_slugs() {
	return array(
		NERA_IWT_RULE_TYPE_INSTANT,
		NERA_IWT_RULE_TYPE_SCHEDULE,
		NERA_IWT_RULE_TYPE_TICKET_PCT,
		NERA_IWT_RULE_TYPE_HELD,
	);
}

/**
 * Labels for admin UI.
 *
 * @return array<string, string>
 */
function nera_iwt_public_rule_type_labels() {
	return array(
		NERA_IWT_RULE_TYPE_INSTANT    => __( 'Instant Prize', 'nera-instant-win-threshold' ),
		NERA_IWT_RULE_TYPE_SCHEDULE   => __( 'Schedule Prize', 'nera-instant-win-threshold' ),
		NERA_IWT_RULE_TYPE_TICKET_PCT => __( 'Ticket Sold Percentage Prize', 'nera-instant-win-threshold' ),
		NERA_IWT_RULE_TYPE_HELD       => __( 'Held-back Prize', 'nera-instant-win-threshold' ),
	);
}

/**
 * Whether “Schedule Prize” is selectable in admin Rule type UI.
 *
 * @return bool
 */
function nera_iwt_is_schedule_prize_type_enabled() {
	if ( ! defined( 'NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE' ) ) {
		return false;
	}

	$v = NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE;

	if ( true === $v ) {
		return true;
	}

	return 1 === (int) $v;
}

/**
 * Whether “Held-back Prize” is selectable in the admin Rule type UI.
 *
 * Gated by NERA_IWT_ENABLE_HELD_PRIZE_TYPE (default off). Existing rules already set to
 * Held-back remain editable until switched away, even while the type is disabled.
 *
 * @return bool
 */
function nera_iwt_is_held_prize_type_enabled() {
	if ( ! defined( 'NERA_IWT_ENABLE_HELD_PRIZE_TYPE' ) ) {
		return false;
	}

	$v = NERA_IWT_ENABLE_HELD_PRIZE_TYPE;

	if ( true === $v ) {
		return true;
	}

	return 1 === (int) $v;
}

/**
 * Whether any instant-win rule for this lottery uses a given public rule type slug.
 *
 * @param int    $product_id Lottery product ID.
 * @param string $type_slug  One of {@see nera_iwt_public_rule_type_slugs()}.
 * @return bool
 */
function nera_iwt_product_has_instant_win_rule_of_public_type( $product_id, $type_slug ) {
	$product_id = absint( $product_id );
	$type_slug  = sanitize_key( (string) $type_slug );

	if ( $product_id <= 0 || '' === $type_slug || ! function_exists( 'lty_get_instant_winner_rule_ids' ) ) {
		return false;
	}

	$rule_ids = lty_get_instant_winner_rule_ids( $product_id );
	if ( ! is_array( $rule_ids ) ) {
		return false;
	}

	foreach ( $rule_ids as $rid ) {
		$rid = absint( $rid );
		if ( $rid <= 0 ) {
			continue;
		}
		$t = (string) get_post_meta( $rid, 'nera_iwt_public_rule_type', true );
		if ( $type_slug === $t ) {
			return true;
		}
	}

	return false;
}

/**
 * @param int $product_id Lottery product ID.
 * @return bool
 */
function nera_iwt_product_has_schedule_public_rules( $product_id ) {
	return nera_iwt_product_has_instant_win_rule_of_public_type( $product_id, NERA_IWT_RULE_TYPE_SCHEDULE );
}

/**
 * @param int $product_id Lottery product ID.
 * @return bool
 */
function nera_iwt_product_has_ticket_pct_public_rules( $product_id ) {
	return nera_iwt_product_has_instant_win_rule_of_public_type( $product_id, NERA_IWT_RULE_TYPE_TICKET_PCT );
}

/**
 * Labels for the Rule type dropdown: Schedule / Ticket % only when Ticket Generation Type is Automatic;
 * Held-back only when NOT Automatic (User Chooses the Ticket). Schedule also gated by
 * {@see nera_iwt_is_schedule_prize_type_enabled()}. Grandfathers the current row type so switching
 * generation mode never hides (and silently converts) the stored slug.
 *
 * @param string           $current_type Stored slug for this rule row (empty in Add Rule modal).
 * @param WC_Product|null $product       Lottery product on the edit screen; null assumes Automatic-friendly options.
 * @return array<string, string>
 */
function nera_iwt_public_rule_type_labels_for_admin_select( $current_type = '', $product = null ) {
	$all          = nera_iwt_public_rule_type_labels();
	$current_type = (string) $current_type;

	$assume_auto = true;
	if ( $product instanceof WC_Product && function_exists( 'nera_iwt_product_has_automatic_ticket_generation' ) ) {
		$assume_auto = nera_iwt_product_has_automatic_ticket_generation( $product );
	}

	$show_advanced   = $assume_auto;
	$show_ticket_pct = $show_advanced || NERA_IWT_RULE_TYPE_TICKET_PCT === $current_type;
	$show_schedule   = ( $show_advanced && nera_iwt_is_schedule_prize_type_enabled() ) || NERA_IWT_RULE_TYPE_SCHEDULE === $current_type;
	// Held-back is the User-Chooses counterpart to Ticket %/Schedule (which are Automatic-only):
	// its secret winning number is a ticket the buyer PICKS, so it only applies when the customer
	// chooses their ticket. Hidden on Automatic products; the current row's stored type is
	// grandfathered so an existing held row never silently converts.
	$show_held       = ( nera_iwt_is_held_prize_type_enabled() && ! $assume_auto ) || NERA_IWT_RULE_TYPE_HELD === $current_type;

	$out = array();
	foreach ( $all as $slug => $label ) {
		if ( NERA_IWT_RULE_TYPE_SCHEDULE === $slug && ! $show_schedule ) {
			continue;
		}
		if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $slug && ! $show_ticket_pct ) {
			continue;
		}
		if ( NERA_IWT_RULE_TYPE_HELD === $slug && ! $show_held ) {
			continue;
		}
		$out[ $slug ] = $label;
	}

	return $out;
}

/**
 * @param string $local Datetime-local fragment e.g. 2026-05-03T14:30.
 * @return string MySQL datetime UTC or empty.
 */
function nera_iwt_schedule_local_string_to_gmt( $local ) {
	$local = trim( (string) $local );
	if ( '' === $local ) {
		return '';
	}
	try {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $local, wp_timezone() );
		if ( ! $dt ) {
			$dt = new DateTimeImmutable( $local, wp_timezone() );
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	} catch ( Exception $e ) {
		return '';
	}
}

/**
 * @param string $gmt_mysql UTC mysql datetime.
 * @return string Value for datetime-local input.
 */
function nera_iwt_schedule_gmt_to_local_input( $gmt_mysql ) {
	$gmt_mysql = trim( (string) $gmt_mysql );
	if ( '' === $gmt_mysql ) {
		return '';
	}
	try {
		$utc = new DateTimeImmutable( $gmt_mysql, new DateTimeZone( 'UTC' ) );
		return $utc->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	} catch ( Exception $e ) {
		return '';
	}
}

/**
 * Persist visibility meta on an instant-winner rule post.
 *
 * Only runs when the row explicitly carries `nera_public_rule_type`; the list table renders
 * these values read-only, so a bulk save without our fields must preserve previously stored meta.
 *
 * @param int   $rule_id Rule post ID.
 * @param array $row     Row from wc_clean() POST (add or save).
 */
function nera_iwt_persist_rule_visibility_meta( $rule_id, array $row ) {
	if ( ! array_key_exists( 'nera_public_rule_type', $row ) ) {
		return;
	}

	$type = sanitize_key( (string) $row['nera_public_rule_type'] );
	if ( ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
		$type = NERA_IWT_RULE_TYPE_INSTANT;
	}

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type && ! nera_iwt_is_schedule_prize_type_enabled() ) {
		$prev = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
		if ( NERA_IWT_RULE_TYPE_SCHEDULE !== $prev ) {
			$type = NERA_IWT_RULE_TYPE_INSTANT;
		}
	}

	if ( NERA_IWT_RULE_TYPE_HELD === $type && ! nera_iwt_is_held_prize_type_enabled() ) {
		$prev = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
		if ( NERA_IWT_RULE_TYPE_HELD !== $prev ) {
			$type = NERA_IWT_RULE_TYPE_INSTANT;
		}
	}

	$lottery_id = absint( get_post_meta( $rule_id, 'lty_lottery_id', true ) );
	$product    = $lottery_id > 0 ? wc_get_product( $lottery_id ) : null;

	if (
		( NERA_IWT_RULE_TYPE_TICKET_PCT === $type || NERA_IWT_RULE_TYPE_SCHEDULE === $type )
		&& $product instanceof WC_Product
		&& function_exists( 'nera_iwt_product_has_automatic_ticket_generation' )
		&& ! nera_iwt_product_has_automatic_ticket_generation( $product )
	) {
		$prev_type = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
		if ( $type !== $prev_type ) {
			$type = NERA_IWT_RULE_TYPE_INSTANT;
		}
	}

	update_post_meta( $rule_id, 'nera_iwt_public_rule_type', $type );

	$gmt           = '';
	$local_raw     = '';
	$gmt_end       = '';
	$local_raw_end = '';
	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type && ! empty( $row['nera_schedule_at'] ) ) {
		$local_raw = sanitize_text_field( (string) $row['nera_schedule_at'] );
		$gmt       = nera_iwt_schedule_local_string_to_gmt( $local_raw );
	}
	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type && ! empty( $row['nera_schedule_end'] ) ) {
		$local_raw_end = sanitize_text_field( (string) $row['nera_schedule_end'] );
		$gmt_end       = nera_iwt_schedule_local_string_to_gmt( $local_raw_end );
	}
	// End requires start; cannot be earlier than Schedule at (datetime-local strings compare lexicographically).
	if ( '' === $local_raw && '' !== $local_raw_end ) {
		$local_raw_end = '';
		$gmt_end       = '';
	}
	if ( '' !== $local_raw && '' !== $local_raw_end && strcmp( $local_raw_end, $local_raw ) < 0 ) {
		$local_raw_end = $local_raw;
		$gmt_end       = nera_iwt_schedule_local_string_to_gmt( $local_raw_end );
	}
	update_post_meta( $rule_id, 'nera_iwt_schedule_at_gmt', $gmt );
	// Raw local string (e.g. "2026-05-03T16:20") kept for client-side schedule comparison.
	update_post_meta( $rule_id, 'nera_iwt_schedule_at_local', $local_raw );
	update_post_meta( $rule_id, 'nera_iwt_schedule_end_gmt', $gmt_end );
	update_post_meta( $rule_id, 'nera_iwt_schedule_end_local', $local_raw_end );

	$pct = max( 0, min( 100, intval( $row['nera_ticket_pct'] ?? 0 ) ) );
	update_post_meta( $rule_id, 'nera_iwt_ticket_pct', $pct );

	// Held-back state: a new/held rule starts 'held'; an already-activated rule keeps its number.
	if ( NERA_IWT_RULE_TYPE_HELD === $type ) {
		$state = (string) get_post_meta( $rule_id, 'nera_iwt_held_state', true );
		if ( 'active' !== $state && 'drawn' !== $state ) {
			update_post_meta( $rule_id, 'nera_iwt_held_state', 'held' );
			// Option B: a held (not-yet-activated) prize must carry NO ticket number, so any
			// number left over from a previous type cannot be won before activation.
			update_post_meta( $rule_id, 'lty_ticket_number', '' );
			if ( function_exists( 'nera_iwt_held_sync_ticket_number_to_logs' ) ) {
				nera_iwt_held_sync_ticket_number_to_logs( $rule_id, '' );
			}
		} else {
			// active / drawn: the number is system-managed via Activate / Run draw. Restore the
			// authoritative assigned number so a stale/edited LFW ticket-number input on this
			// save cannot silently change the winning number.
			$managed = (string) get_post_meta( $rule_id, 'nera_iwt_held_number', true );
			if ( '' !== $managed ) {
				update_post_meta( $rule_id, 'lty_ticket_number', $managed );
				if ( function_exists( 'nera_iwt_held_sync_ticket_number_to_logs' ) ) {
					nera_iwt_held_sync_ticket_number_to_logs( $rule_id, $managed );
				}
			}
		}
	} else {
		// Switched away from held → drop the held markers (activation no longer applies).
		delete_post_meta( $rule_id, 'nera_iwt_held_state' );
		delete_post_meta( $rule_id, 'nera_iwt_held_needs_draw' );
		delete_post_meta( $rule_id, 'nera_iwt_held_number' );
	}

	nera_iwt_push_rule_visibility_to_child_logs( $rule_id );
	nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id );
}

/**
 * Mirror visibility meta from a rule post onto every child instant-winner log.
 *
 * Visibility decisions on the front read **rule** meta, so this sync is now optional —
 * we keep it for backward compatibility and for any LFW path that reads log meta directly.
 *
 * @param int $rule_id Rule post ID (`lty_instant_winners`).
 */
function nera_iwt_push_rule_visibility_to_child_logs( $rule_id ) {
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
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	if ( ! is_array( $logs ) || empty( $logs ) ) {
		return;
	}

	$keys = array(
		'nera_iwt_public_rule_type',
		'nera_iwt_schedule_at_gmt',
		'nera_iwt_schedule_at_local',
		'nera_iwt_schedule_end_gmt',
		'nera_iwt_schedule_end_local',
		'nera_iwt_ticket_pct',
	);
	foreach ( $logs as $log_id ) {
		$log_id = absint( $log_id );
		if ( $log_id <= 0 ) {
			continue;
		}
		foreach ( $keys as $meta_key ) {
			$v = get_post_meta( $rule_id, $meta_key, true );
			update_post_meta( $log_id, $meta_key, $v );
		}
	}
}

/**
 * Instant-winner rule post ID for a log (LFW stores rule id as `post_parent`).
 *
 * @param int $log_id Log post ID.
 * @return int Rule ID or 0.
 */
function nera_iwt_get_instant_winner_rule_id_for_log( $log_id ) {
	$pid = wp_get_post_parent_id( $log_id );
	return $pid > 0 ? (int) $pid : 0;
}

/**
 * Backward-compat helper: read meta from log first, fall back to parent rule.
 *
 * Storefront visibility no longer relies on this (see {@see nera_iwt_resolve_rule_visibility_for_log()})
 * but third parties / templates may still call it.
 *
 * @param int    $log_id   Log post ID.
 * @param string $meta_key Meta key.
 * @return mixed
 */
function nera_iwt_get_post_meta_log_then_rule( $log_id, $meta_key ) {
	$log_id = absint( $log_id );
	if ( $log_id <= 0 || '' === (string) $meta_key ) {
		return '';
	}

	$v_log = get_post_meta( $log_id, $meta_key, true );
	$has   = false;
	if ( is_string( $v_log ) ) {
		$has = '' !== trim( $v_log );
	} elseif ( false !== $v_log && null !== $v_log && '' !== $v_log ) {
		$has = true;
	}

	if ( $has ) {
		return $v_log;
	}

	$rule_id = nera_iwt_get_instant_winner_rule_id_for_log( $log_id );
	if ( $rule_id <= 0 ) {
		return '';
	}

	return get_post_meta( $rule_id, $meta_key, true );
}

/**
 * Ensure Add Rule AJAX always carries visibility keys so meta can be saved.
 *
 * @param array $rule Row from wc_clean( $_POST['instant_winner_rule'] ).
 * @return array
 */
function nera_iwt_normalize_add_rule_visibility_payload( array $rule ) {
	if ( ! array_key_exists( 'nera_public_rule_type', $rule ) ) {
		$rule['nera_public_rule_type'] = NERA_IWT_RULE_TYPE_INSTANT;
	}
	if ( ! array_key_exists( 'nera_schedule_at', $rule ) ) {
		$rule['nera_schedule_at'] = '';
	}
	if ( ! array_key_exists( 'nera_schedule_end', $rule ) ) {
		$rule['nera_schedule_end'] = '';
	}
	if ( ! array_key_exists( 'nera_ticket_pct', $rule ) ) {
		$rule['nera_ticket_pct'] = '0';
	}
	return $rule;
}

/**
 * Overlay Nera visibility keys from raw (unslashed) POST row onto a cleaned rule row.
 *
 * @param array $rule    Row after wc_clean().
 * @param mixed $raw_row Same logical row from wp_unslash( $_POST[...] ).
 * @return array
 */
function nera_iwt_merge_raw_rule_row_nera_keys( array $rule, $raw_row ) {
	if ( ! is_array( $raw_row ) ) {
		return $rule;
	}
	if ( array_key_exists( 'nera_public_rule_type', $raw_row ) ) {
		$rule['nera_public_rule_type'] = sanitize_key( (string) $raw_row['nera_public_rule_type'] );
	}
	if ( array_key_exists( 'nera_schedule_at', $raw_row ) ) {
		$rule['nera_schedule_at'] = sanitize_text_field( (string) $raw_row['nera_schedule_at'] );
	}
	if ( array_key_exists( 'nera_schedule_end', $raw_row ) ) {
		$rule['nera_schedule_end'] = sanitize_text_field( (string) $raw_row['nera_schedule_end'] );
	}
	if ( array_key_exists( 'nera_ticket_pct', $raw_row ) ) {
		$rule['nera_ticket_pct'] = sanitize_text_field( (string) $raw_row['nera_ticket_pct'] );
	}
	return $rule;
}

/**
 * Persist visibility meta when LFW inserts a brand-new instant-winner rule via AJAX.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function nera_iwt_persist_visibility_wp_insert_new_rule( $post_id, $post, $update ) {
	if ( $update || ! $post instanceof WP_Post ) {
		return;
	}

	$rule_pt = class_exists( 'LTY_Register_Post_Types', false )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE
		: 'lty_instant_winners';

	if ( $rule_pt !== $post->post_type ) {
		return;
	}

	if ( ! wp_doing_ajax() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( ! isset( $_POST['action'] ) || 'lty_add_instant_winner_rule' !== sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
		return;
	}

	$rule = isset( $_POST['instant_winner_rule'] ) ? wc_clean( wp_unslash( $_POST['instant_winner_rule'] ) ) : array();
	if ( ! is_array( $rule ) ) {
		$rule = array();
	}
	$raw_rule = isset( $_POST['instant_winner_rule'] ) ? wp_unslash( $_POST['instant_winner_rule'] ) : array();
	$rule     = nera_iwt_merge_raw_rule_row_nera_keys( $rule, is_array( $raw_rule ) ? $raw_rule : array() );
	$rule     = nera_iwt_normalize_add_rule_visibility_payload( $rule );
	nera_iwt_persist_rule_visibility_meta( $post_id, $rule );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

add_action( 'wp_insert_post', 'nera_iwt_persist_visibility_wp_insert_new_rule', 30, 3 );

/**
 * Public rule type for an instant-winner log (canonical value lives on the parent rule).
 *
 * @param int $log_id Log post ID.
 * @return string One of nera_iwt_public_rule_type_slugs().
 */
function nera_iwt_get_log_public_rule_type( $log_id ) {
	$vis = function_exists( 'nera_iwt_resolve_rule_visibility_for_log' )
		? nera_iwt_resolve_rule_visibility_for_log( $log_id )
		: null;
	if ( is_array( $vis ) && isset( $vis['type'] ) ) {
		return (string) $vis['type'];
	}
	// Fallback: direct meta (e.g. visibility module not loaded yet).
	$type = (string) get_post_meta( $log_id, 'nera_iwt_public_rule_type', true );
	if ( '' === $type || ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
		return NERA_IWT_RULE_TYPE_INSTANT;
	}
	return $type;
}

/**
 * Sold tickets as a percentage of maximum tickets for the lottery product.
 *
 * @param WC_Product $product    Product.
 * @param int        $extra_sold Additional in-flight tickets to project into the sold count. Default 0.
 * @return float|null Percent 0–100, or null if maximum is unknown/zero.
 */
function nera_iwt_get_lottery_ticket_sold_percent( $product, $extra_sold = 0 ) {
	if ( ! $product instanceof WC_Product ) {
		return null;
	}
	$max = 0;
	if ( method_exists( $product, 'get_lty_maximum_tickets' ) ) {
		$max = intval( $product->get_lty_maximum_tickets() );
	}
	$max = absint( apply_filters( 'lty_progress_bar_maximum_tickets', $max, $product ) );
	if ( $max <= 0 ) {
		return null;
	}
	$purchased = 0;
	if ( method_exists( $product, 'get_purchased_ticket_count' ) ) {
		$purchased = absint( $product->get_purchased_ticket_count() );
	}
	$purchased += max( 0, (int) $extra_sold );
	return ( $purchased / $max ) * 100.0;
}

/**
 * Counts for the public instant-wins section header (single source for badge + Vue stats).
 *
 * Uses {@see nera_iwt_get_all_instant_winner_log_ids_for_product()} — the same full CMS pool
 * as the REST payload — so header counts match every configured prize row.
 *
 * @param WC_Product $product Lottery product.
 * @return array{available:int,won:int,total:int}
 */
function nera_iwt_get_public_instant_wins_section_counts( $product ) {
	$empty = array(
		'available' => 0,
		'won'       => 0,
		'total'     => 0,
	);
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_get_instant_winner_log_ids' ) ) {
		return $empty;
	}

	$ids = nera_iwt_get_all_instant_winner_log_ids_for_product( $product->get_id() );
	if ( empty( $ids ) ) {
		return $empty;
	}

	$won       = 0;
	$available = 0;

	foreach ( $ids as $log_id ) {
		$log_id = absint( $log_id );
		if ( $log_id <= 0 ) {
			continue;
		}
		$log = function_exists( 'lty_get_instant_winner_log' ) ? lty_get_instant_winner_log( $log_id ) : null;
		if ( ! is_object( $log ) || ! method_exists( $log, 'has_status' ) ) {
			continue;
		}
		if ( $log->has_status( 'lty_won' ) ) {
			++$won;
			continue;
		}
		++$available;
	}

	return array(
		'available' => $available,
		'won'       => $won,
		'total'     => $available + $won,
	);
}

/**
 * All instant-winner log IDs for a lottery product’s current relist (full CMS prize list).
 *
 * @param int $product_id Lottery product ID.
 * @return int[]
 */
function nera_iwt_get_all_instant_winner_log_ids_for_product( $product_id ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 || ! function_exists( 'lty_get_instant_winner_log_ids' ) ) {
		return array();
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->exists() ) {
		return array();
	}

	$list_count = method_exists( $product, 'get_current_relist_count' )
		? (int) $product->get_current_relist_count()
		: 0;

	$ids = lty_get_instant_winner_log_ids( $product_id, false, $list_count, 'all' );
	if ( ! is_array( $ids ) || empty( $ids ) ) {
		return array();
	}

	$out = array();
	foreach ( $ids as $id ) {
		$log_id = absint( $id );
		if ( $log_id > 0 ) {
			$out[] = $log_id;
		}
	}
	return $out;
}

/**
 * Instant-winner log IDs for a lottery product after Nera storefront visibility rules.
 *
 * Always filters by the product's current relist count (matches LFW product object behaviour),
 * then runs each ID through {@see nera_iwt_instant_winner_log_included_in_storefront_list()}.
 *
 * @param int $product_id Lottery product ID.
 * @return int[]
 */
function nera_iwt_get_storefront_instant_winner_log_ids( $product_id ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 || ! function_exists( 'lty_get_instant_winner_log_ids' ) ) {
		return array();
	}
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->exists() ) {
		return array();
	}

	$list_count = method_exists( $product, 'get_current_relist_count' )
		? (int) $product->get_current_relist_count()
		: 0;

	$ids = lty_get_instant_winner_log_ids( $product_id, false, $list_count, 'all' );
	if ( ! is_array( $ids ) || empty( $ids ) ) {
		return array();
	}

	$out = array();
	foreach ( $ids as $id ) {
		$log_id = absint( $id );
		if ( $log_id <= 0 ) {
			continue;
		}
		if ( nera_iwt_instant_winner_log_included_in_storefront_list( $log_id, $product ) ) {
			$out[] = $log_id;
		}
	}
	return $out;
}

/**
 * Extract lottery product ID from a meta_query built like lty_get_instant_winner_log_ids().
 *
 * @param array $meta_query WP_Query meta_query array.
 * @return int 0 if not found.
 */
function nera_iwt_extract_lottery_id_from_meta_query( $meta_query ) {
	if ( ! is_array( $meta_query ) ) {
		return 0;
	}
	foreach ( $meta_query as $key => $clause ) {
		if ( 'relation' === $key ) {
			continue;
		}
		if ( ! is_array( $clause ) ) {
			continue;
		}
		if ( isset( $clause['key'], $clause['value'] ) && 'lty_lottery_id' === $clause['key'] ) {
			return absint( $clause['value'] );
		}
		$nested = nera_iwt_extract_lottery_id_from_meta_query( $clause );
		if ( $nested > 0 ) {
			return $nested;
		}
	}
	return 0;
}

/**
 * Whether this WP_Query matches LFW's instant-winner log listing (get_posts with fields=ids).
 *
 * @param WP_Query $query Query object.
 * @return bool
 */
function nera_iwt_is_lfw_instant_winner_log_ids_query( $query ) {
	if ( ! $query instanceof WP_Query ) {
		return false;
	}

	$log_pt = class_exists( 'LTY_Register_Post_Types', false )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_LOG_POSTTYPE
		: 'lty_ins_winner_log';

	if ( $query->get( 'post_type' ) !== $log_pt ) {
		return false;
	}

	if ( 'ids' !== $query->get( 'fields' ) ) {
		return false;
	}

	$mq = $query->get( 'meta_query' );
	if ( ! is_array( $mq ) || empty( $mq ) ) {
		return false;
	}

	return nera_iwt_extract_lottery_id_from_meta_query( $mq ) > 0;
}

/**
 * Storefront instant-winner log queries: do not strip rows by schedule / ticket-% visibility.
 * The product-page instant-win section (REST + templates) lists every CMS-configured prize.
 *
 * @param array<int|WP_Post> $posts Array of post IDs or post objects.
 * @param WP_Query           $query Query instance.
 * @return array<int|WP_Post>
 */
function nera_iwt_posts_results_instant_winner_visibility( $posts, $query ) {
	unset( $query );
	return $posts;
}

add_filter( 'posts_results', 'nera_iwt_posts_results_instant_winner_visibility', 10, 2 );

/**
 * Save visibility meta when LFW creates/updates a rule via AJAX.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 * @param bool    $update  Whether this is an update.
 */
function nera_iwt_save_rule_visibility_on_rule_save( $post_id, $post, $update ) {
	unset( $post, $update );

	if ( ! wp_doing_ajax() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

	if ( 'lty_add_instant_winner_rule' === $action ) {
		$rule = isset( $_POST['instant_winner_rule'] ) ? wc_clean( wp_unslash( $_POST['instant_winner_rule'] ) ) : array();
		if ( ! is_array( $rule ) ) {
			$rule = array();
		}
		$raw_rule = isset( $_POST['instant_winner_rule'] ) ? wp_unslash( $_POST['instant_winner_rule'] ) : array();
		$rule     = nera_iwt_merge_raw_rule_row_nera_keys( $rule, is_array( $raw_rule ) ? $raw_rule : array() );
		$rule     = nera_iwt_normalize_add_rule_visibility_payload( $rule );
		nera_iwt_persist_rule_visibility_meta( $post_id, $rule );
	} elseif ( 'lty_save_instant_winners_rules' === $action ) {
		$rules = isset( $_POST['instant_winners_rules'] ) ? wc_clean( wp_unslash( $_POST['instant_winners_rules'] ) ) : array();
		if ( isset( $rules[ $post_id ] ) && is_array( $rules[ $post_id ] ) ) {
			$row     = $rules[ $post_id ];
			$raw     = isset( $_POST['instant_winners_rules'] ) ? wp_unslash( $_POST['instant_winners_rules'] ) : array();
			$raw_row = array();
			if ( is_array( $raw ) ) {
				if ( isset( $raw[ $post_id ] ) && is_array( $raw[ $post_id ] ) ) {
					$raw_row = $raw[ $post_id ];
				} elseif ( isset( $raw[ (string) $post_id ] ) && is_array( $raw[ (string) $post_id ] ) ) {
					$raw_row = $raw[ (string) $post_id ];
				}
			}
			$row = nera_iwt_merge_raw_rule_row_nera_keys( $row, $raw_row );
			nera_iwt_persist_rule_visibility_meta( $post_id, $row );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

add_action( 'save_post_lty_instant_winners', 'nera_iwt_save_rule_visibility_on_rule_save', 15, 3 );

/**
 * Mirror visibility meta from parent rule onto each log on log save (legacy compat).
 *
 * @param int     $post_id Log ID.
 * @param WP_Post $post    Log post.
 */
function nera_iwt_sync_visibility_meta_to_log( $post_id, $post ) {
	$rule_id = isset( $post->post_parent ) ? intval( $post->post_parent ) : 0;
	if ( ! $rule_id ) {
		return;
	}

	foreach ( array( 'nera_iwt_public_rule_type', 'nera_iwt_schedule_at_gmt', 'nera_iwt_schedule_at_local', 'nera_iwt_schedule_end_gmt', 'nera_iwt_schedule_end_local', 'nera_iwt_ticket_pct' ) as $meta_key ) {
		$v = get_post_meta( $rule_id, $meta_key, true );
		update_post_meta( $post_id, $meta_key, $v );
	}

	// Stamp lty_lottery_id on the log when it's missing. LFW only writes it on a product Save — NOT
	// when a win log is created at purchase — yet every storefront lookup (the public winner list and
	// the order-received result popup via lty_get_instant_winner_log_ids_by_order_id) filters logs by
	// lty_lottery_id = product_id. Without it, a genuine win shows "not won". Derive from the rule's
	// lty_lottery_id, falling back to the rule's parent product.
	$lottery_id = absint( get_post_meta( $post_id, 'lty_lottery_id', true ) );
	if ( $lottery_id <= 0 ) {
		$lottery_id = absint( get_post_meta( $rule_id, 'lty_lottery_id', true ) );
		if ( $lottery_id <= 0 ) {
			$lottery_id = absint( wp_get_post_parent_id( $rule_id ) );
		}
		if ( $lottery_id > 0 ) {
			update_post_meta( $post_id, 'lty_lottery_id', $lottery_id );
		}
	}

	nera_iwt_maybe_clear_theme_instant_wins_cache( $lottery_id );
}

add_action( 'save_post_lty_ins_winner_log', 'nera_iwt_sync_visibility_meta_to_log', 15, 2 );

/**
 * Help tip copy for the Rule type column (depends on schedule flag + ticket generation type).
 *
 * @param WP_Post|null $post Current admin post.
 * @return string
 */
function nera_iwt_admin_rule_type_column_help_tip_text( $post ) {
	$product = ( $post instanceof WP_Post && 'product' === $post->post_type ) ? wc_get_product( $post->ID ) : null;

	if (
		$product instanceof WC_Product
		&& function_exists( 'lty_is_lottery_product' )
		&& lty_is_lottery_product( $product )
		&& function_exists( 'nera_iwt_product_has_automatic_ticket_generation' )
		&& ! nera_iwt_product_has_automatic_ticket_generation( $product )
	) {
		$pid             = (int) $product->get_id();
		$mention_schedule = nera_iwt_is_schedule_prize_type_enabled() || nera_iwt_product_has_schedule_public_rules( $pid );
		if ( $mention_schedule ) {
			return __( 'Controls when this prize appears on the public product page. With Ticket Generation Type set to User Chooses the Ticket, only Instant Prize is available here. Switch Ticket Generation Type to Automatic to use Ticket Sold Percentage or Schedule prizes.', 'nera-instant-win-threshold' );
		}

		return __( 'Controls when this prize appears on the public product page. With Ticket Generation Type set to User Chooses the Ticket, only Instant Prize is available here. Switch Ticket Generation Type to Automatic to use Ticket Sold Percentage prizes.', 'nera-instant-win-threshold' );
	}

	if ( nera_iwt_is_schedule_prize_type_enabled() ) {
		return __( 'Controls when this prize appears on the public product page. Instant = always. Schedule = after a date/time. Ticket % = when sold tickets reach the configured percentage.', 'nera-instant-win-threshold' );
	}

	return __( 'Controls when this prize appears on the public product page. Instant = always. Ticket % = when sold tickets reach the configured percentage. (Schedule Prize is disabled via NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE.)', 'nera-instant-win-threshold' );
}

/**
 * Admin: table header — Rule type.
 */
function nera_iwt_admin_rule_column_header() {
	global $post;
	?>
	<th class="nera-iwt-public-rule-type-column">
		<b><?php esc_html_e( 'Rule type', 'nera-instant-win-threshold' ); ?></b>
		<?php
		echo wp_kses_post(
			wc_help_tip( nera_iwt_admin_rule_type_column_help_tip_text( $post ) )
		);
		?>
	</th>
	<?php
}

add_action( 'lty_instant_winner_rule_column', 'nera_iwt_admin_rule_column_header', 5 );

/**
 * Whether a rule's prize is currently available (unlocked) in the admin context.
 *
 *  - instant     → always available.
 *  - ticket_pct  → available once current ticket-sold % reaches the threshold
 *                  (pct 0 means "no gate" → available).
 *  - schedule    → available within the [start, end] window (server UTC).
 *
 * @param string          $type          Rule type slug.
 * @param int             $pct           Ticket-% threshold.
 * @param string          $sched_gmt     Schedule start (GMT 'Y-m-d H:i:s') or ''.
 * @param string          $sched_end_gmt Schedule end (GMT 'Y-m-d H:i:s') or ''.
 * @param WC_Product|null $product       Lottery product.
 * @return bool
 */
function nera_iwt_admin_rule_is_available( $type, $pct, $sched_gmt, $sched_end_gmt, $product ) {
	if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $type ) {
		$pct = (int) $pct;
		if ( $pct <= 0 ) {
			return true;
		}
		$sold = ( $product instanceof WC_Product ) ? nera_iwt_get_lottery_ticket_sold_percent( $product ) : null;
		if ( null === $sold ) {
			return false;
		}
		return (float) $sold >= (float) $pct;
	}

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type ) {
		$now = time();
		$at  = ( '' !== (string) $sched_gmt ) ? strtotime( $sched_gmt . ' UTC' ) : false;
		$end = ( '' !== (string) $sched_end_gmt ) ? strtotime( $sched_end_gmt . ' UTC' ) : false;
		if ( $at && $now < $at ) {
			return false;
		}
		if ( $end && $now > $end ) {
			return false;
		}
		return true;
	}

	// instant (and any unknown type) → available.
	return true;
}

/**
 * Whether an instant-winner rule already has an assigned winner (lty_won log).
 *
 * @param int $rule_id Rule post ID.
 */
function nera_iwt_rule_has_assigned_winner( int $rule_id ): bool {
	if ( $rule_id <= 0 || ! function_exists( 'lty_get_instant_winner_log_id_by_rule_id' ) || ! function_exists( 'lty_get_instant_winner_log' ) ) {
		return false;
	}

	$log_id = lty_get_instant_winner_log_id_by_rule_id( $rule_id, 0 );
	if ( ! $log_id ) {
		return false;
	}

	$log = lty_get_instant_winner_log( $log_id );

	return is_object( $log ) && method_exists( $log, 'has_status' ) && $log->has_status( 'lty_won' );
}

/**
 * Resolve the admin row status for colour-coding the prizes table.
 *
 *  - 'won'       → winner already assigned (orange) — takes precedence over gate/schedule.
 *  - 'locked'    → not yet available and no winner (red).
 *  - 'available' → available and not yet won (green).
 *
 * @param int             $rule_id       Rule post ID.
 * @param string          $type          Rule type slug.
 * @param int             $pct           Ticket-% threshold.
 * @param string          $sched_gmt     Schedule start (GMT) or ''.
 * @param string          $sched_end_gmt Schedule end (GMT) or ''.
 * @param WC_Product|null $product       Lottery product.
 * @return string One of 'locked' | 'won' | 'available'.
 */
function nera_iwt_admin_rule_status( $rule_id, $type, $pct, $sched_gmt, $sched_end_gmt, $product ) {
	$rule_id = (int) $rule_id;

	if ( nera_iwt_rule_has_assigned_winner( $rule_id ) ) {
		return 'won';
	}

	// Held-back: 'available' (green) once activated (a number is assigned), otherwise
	// 'locked' (red) — it is held and not yet winnable, even though the public page shows
	// it as available.
	if ( NERA_IWT_RULE_TYPE_HELD === $type ) {
		$state = (string) get_post_meta( $rule_id, 'nera_iwt_held_state', true );
		if ( 'unplaceable' === $state ) {
			return 'unplaceable'; // merged status: its own (purple) dot — a problem needing attention.
		}
		return 'active' === $state ? 'available' : 'locked';
	}

	if ( ! nera_iwt_admin_rule_is_available( $type, $pct, $sched_gmt, $sched_end_gmt, $product ) ) {
		return 'locked';
	}

	return 'available';
}

/**
 * Human tooltip for the merged status dot: the unified state plus the specific reason.
 * Shown on hover/focus of the coloured dot beside each prize ID.
 *
 * @param int             $rule_id Rule post ID.
 * @param string          $type    Rule type slug.
 * @param string          $status  One of locked|available|won|unplaceable.
 * @param int             $pct     Ticket-% threshold.
 * @param WC_Product|null $product Lottery product.
 * @return string
 */
function nera_iwt_admin_rule_status_tip( $rule_id, $type, $status, $pct, $product ) {
	if ( 'won' === $status ) {
		return __( 'Won — a winner has been assigned.', 'nera-instant-win-threshold' );
	}
	if ( 'unplaceable' === $status ) {
		return __( 'Needs attention — no unsold number left to hold this prize.', 'nera-instant-win-threshold' );
	}
	if ( NERA_IWT_RULE_TYPE_HELD === $type ) {
		return 'available' === $status
			? __( 'Available — held prize is live; waiting for a buyer.', 'nera-instant-win-threshold' )
			: __( 'Not available yet — held back, not activated.', 'nera-instant-win-threshold' );
	}
	if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $type ) {
		if ( 'available' === $status ) {
			return __( 'Available — ticket-sold % reached.', 'nera-instant-win-threshold' );
		}
		$sold = ( $product instanceof WC_Product && function_exists( 'nera_iwt_get_lottery_ticket_sold_percent' ) )
			? nera_iwt_get_lottery_ticket_sold_percent( $product )
			: null;
		if ( null !== $sold ) {
			return sprintf(
				/* translators: 1: current sold %, 2: threshold % */
				__( 'Not available yet — %1$s%% sold, unlocks at %2$d%%.', 'nera-instant-win-threshold' ),
				(string) $sold,
				(int) $pct
			);
		}
		return sprintf(
			/* translators: %d: threshold % */
			__( 'Not available yet — unlocks at %d%% sold.', 'nera-instant-win-threshold' ),
			(int) $pct
		);
	}
	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $type ) {
		return 'available' === $status
			? __( 'Available — within the schedule window.', 'nera-instant-win-threshold' )
			: __( 'Not available yet — outside the schedule window.', 'nera-instant-win-threshold' );
	}
	return 'available' === $status
		? __( 'Available — instant prize.', 'nera-instant-win-threshold' )
		: __( 'Not available yet.', 'nera-instant-win-threshold' );
}

/**
 * Human-facing status badge for a held-back prize, driving the admin row + legend.
 *
 * Precedence: drawn → won → needs-draw → unplaceable → live → pending.
 *
 * @param int $rule_id Rule post ID.
 * @return array{slug:string,label:string,tone:string} tone ∈ crit|ok|warn.
 */
function nera_iwt_held_status_badge( $rule_id ) {
	$rule_id = (int) $rule_id;
	$state   = (string) get_post_meta( $rule_id, 'nera_iwt_held_state', true );
	$won     = function_exists( 'nera_iwt_rule_has_assigned_winner' ) && nera_iwt_rule_has_assigned_winner( $rule_id );
	$needs   = (int) get_post_meta( $rule_id, 'nera_iwt_held_needs_draw', true );
	$draw_on = ! function_exists( 'nera_iwt_held_draw_enabled' ) || nera_iwt_held_draw_enabled();

	if ( 'drawn' === $state ) {
		// Draw remedy off → a (legacy) drawn prize is simply a Won prize.
		return $draw_on
			? array( 'slug' => 'drawn', 'label' => __( 'Drawn', 'nera-instant-win-threshold' ), 'tone' => 'warn' )
			: array( 'slug' => 'won', 'label' => __( 'Won', 'nera-instant-win-threshold' ), 'tone' => 'warn' );
	}
	if ( $won ) {
		return array( 'slug' => 'won', 'label' => __( 'Won', 'nera-instant-win-threshold' ), 'tone' => 'warn' );
	}
	if ( $needs && $draw_on ) {
		return array( 'slug' => 'needs-draw', 'label' => __( 'Needs draw', 'nera-instant-win-threshold' ), 'tone' => 'warn' );
	}
	if ( 'unplaceable' === $state ) {
		return array( 'slug' => 'unplaceable', 'label' => __( 'Unplaceable', 'nera-instant-win-threshold' ), 'tone' => 'crit' );
	}
	if ( 'active' === $state ) {
		return array( 'slug' => 'live', 'label' => __( 'Live', 'nera-instant-win-threshold' ), 'tone' => 'ok' );
	}
	return array( 'slug' => 'pending', 'label' => __( 'Pending', 'nera-instant-win-threshold' ), 'tone' => 'crit' );
}

/**
 * Winner identity recorded on a held prize's log (for the Won / Drawn rows).
 *
 * @param int $rule_id Rule post ID.
 * @return array{name:string,ticket:string,order:int}
 */
function nera_iwt_held_winner_info( $rule_id ) {
	$out = array( 'name' => '', 'ticket' => '', 'order' => 0 );
	if ( ! function_exists( 'lty_get_instant_winner_log_id_by_rule_id' ) ) {
		return $out;
	}
	$log_id = lty_get_instant_winner_log_id_by_rule_id( (int) $rule_id, 0 );
	if ( ! $log_id ) {
		return $out;
	}
	$name = trim( (string) get_post_meta( $log_id, 'lty_user_name', true ) );
	if ( '' === $name ) {
		$name = (string) get_post_meta( $log_id, 'lty_user_email', true );
	}
	$out['name']   = $name;
	$out['ticket'] = (string) get_post_meta( $log_id, 'lty_ticket_number', true );
	$out['order']  = (int) get_post_meta( $log_id, 'lty_order_id', true );
	return $out;
}

/**
 * Admin: editable table cell (Rule type, Schedule at / end, Ticket sold %) — same behaviour as Add Rule modal.
 *
 * @param LTY_Instant_Winner_Rule $instant_winner Rule object.
 */
function nera_iwt_admin_rule_column_cell( $instant_winner ) {
	if ( ! is_object( $instant_winner ) || ! method_exists( $instant_winner, 'get_id' ) ) {
		return;
	}

	global $post;

	$rule_id     = (int) $instant_winner->get_id();
	$type        = get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
	$type        = in_array( (string) $type, nera_iwt_public_rule_type_slugs(), true ) ? (string) $type : NERA_IWT_RULE_TYPE_INSTANT;
	$sched_gmt     = (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_gmt', true );
	$sched_end_gmt = (string) get_post_meta( $rule_id, 'nera_iwt_schedule_end_gmt', true );
	$pct           = max( 0, min( 100, intval( get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) ) );
	$sched_local   = nera_iwt_schedule_gmt_to_local_input( $sched_gmt );
	$sched_end_local = nera_iwt_schedule_gmt_to_local_input( $sched_end_gmt );

	$product = ( $post instanceof WP_Post && 'product' === $post->post_type ) ? wc_get_product( $post->ID ) : null;
	if ( ( ! $product instanceof WC_Product ) && method_exists( $instant_winner, 'get_product_id' ) ) {
		$product = wc_get_product( (int) $instant_winner->get_product_id() );
	}
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		$product = null;
	}

	$labels = nera_iwt_public_rule_type_labels_for_admin_select( $type, $product );

	// Live status for row colour-coding (see admin-rule-visibility.js / .css).
	$status     = nera_iwt_admin_rule_status( $rule_id, $type, $pct, $sched_gmt, $sched_end_gmt, $product );
	$status_tip = nera_iwt_admin_rule_status_tip( $rule_id, $type, $status, $pct, $product );
	?>
	<td class="nera-iwt-public-rule-type-column" data-nera-status="<?php echo esc_attr( $status ); ?>" data-nera-tip="<?php echo esc_attr( $status_tip ); ?>">
		<div class="nera-iwt-rule-visibility-fields nera-iwt-rule-visibility-table-fields">
			<p class="nera-iwt-table-field-row">
				<label class="screen-reader-text" for="nera-iwt-public-rule-type-<?php echo esc_attr( (string) $rule_id ); ?>">
					<?php esc_html_e( 'Rule type', 'nera-instant-win-threshold' ); ?>
				</label>
				<select
					id="nera-iwt-public-rule-type-<?php echo esc_attr( (string) $rule_id ); ?>"
					class="nera-iwt-public-rule-type"
				>
					<?php foreach ( $labels as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $type, true ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="nera-iwt-row-schedule nera-iwt-popup-conditional-row nera-iwt-table-field-row">
				<label class="screen-reader-text" for="nera-iwt-schedule-at-<?php echo esc_attr( (string) $rule_id ); ?>">
					<?php esc_html_e( 'Schedule at', 'nera-instant-win-threshold' ); ?>
				</label>
				<input
					type="datetime-local"
					id="nera-iwt-schedule-at-<?php echo esc_attr( (string) $rule_id ); ?>"
					class="nera-iwt-schedule-at"
					value="<?php echo esc_attr( $sched_local ); ?>"
					step="60"
				/>
			</p>
			<p class="nera-iwt-row-schedule nera-iwt-popup-conditional-row nera-iwt-table-field-row">
				<label class="screen-reader-text" for="nera-iwt-schedule-end-<?php echo esc_attr( (string) $rule_id ); ?>">
					<?php esc_html_e( 'Schedule End', 'nera-instant-win-threshold' ); ?>
				</label>
				<span class="nera-iwt-schedule-end-field">
					<input
						type="datetime-local"
						id="nera-iwt-schedule-end-<?php echo esc_attr( (string) $rule_id ); ?>"
						class="nera-iwt-schedule-end"
						value="<?php echo esc_attr( $sched_end_local ); ?>"
						step="60"
					/>
					<button
						type="button"
						class="button button-small nera-iwt-schedule-end-clear"
						aria-label="<?php echo esc_attr__( 'Clear schedule end', 'nera-instant-win-threshold' ); ?>"
					>&times;</button>
				</span>
			</p>
			<p class="nera-iwt-row-ticket-pct nera-iwt-popup-conditional-row nera-iwt-table-field-row">
				<label class="screen-reader-text" for="nera-iwt-ticket-pct-<?php echo esc_attr( (string) $rule_id ); ?>">
					<?php esc_html_e( 'Ticket sold (%)', 'nera-instant-win-threshold' ); ?>
				</label>
				<input
					type="number"
					id="nera-iwt-ticket-pct-<?php echo esc_attr( (string) $rule_id ); ?>"
					class="nera-iwt-ticket-pct"
					min="0"
					max="100"
					step="1"
					inputmode="numeric"
					value="<?php echo esc_attr( (string) $pct ); ?>"
				/>
			</p>
			<?php
			$held_state_raw = (string) get_post_meta( $rule_id, 'nera_iwt_held_state', true );
			$held_state     = 'active' === $held_state_raw ? 'active' : 'held';
			$held_badge     = nera_iwt_held_status_badge( $rule_id );
			?>
			<div class="nera-iwt-held-controls" data-rule-id="<?php echo esc_attr( (string) $rule_id ); ?>" data-held-state="<?php echo esc_attr( $held_state ); ?>" data-held-badge="<?php echo esc_attr( $held_badge['slug'] ); ?>" hidden>
				<?php
				// Hidden data-carrier (rule id + held state). The visible status now lives entirely in the
				// merged colour dot on the ID (data-nera-status) — no badge/stripe here anymore. The action
				// icons render inside, then JS (neraIwtRelocateHeldActions) moves them into the Action column.
				?>
				<span class="nera-iwt-held-actions" data-rule-id="<?php echo esc_attr( (string) $rule_id ); ?>" data-held-badge="<?php echo esc_attr( $held_badge['slug'] ); ?>">
					<span class="dashicons dashicons-awards nera-iwt-open-activate nera-iwt-act--set" role="button" title="<?php echo esc_attr__( 'Set winning ticket…', 'nera-instant-win-threshold' ); ?>" aria-label="<?php echo esc_attr__( 'Set winning ticket…', 'nera-instant-win-threshold' ); ?>"></span>
					<span class="dashicons dashicons-edit nera-iwt-held-edit nera-iwt-act--edit" role="button" title="<?php echo esc_attr__( 'Edit number', 'nera-instant-win-threshold' ); ?>" aria-label="<?php echo esc_attr__( 'Edit number', 'nera-instant-win-threshold' ); ?>"></span>
					<span class="dashicons dashicons-controls-pause nera-iwt-deactivate-held nera-iwt-act--deactivate" role="button" title="<?php echo esc_attr__( 'Deactivate', 'nera-instant-win-threshold' ); ?>" aria-label="<?php echo esc_attr__( 'Deactivate', 'nera-instant-win-threshold' ); ?>"></span>
					<span class="dashicons dashicons-randomize nera-iwt-run-held-draw nera-iwt-act--draw" role="button" title="<?php echo esc_attr__( 'Run draw', 'nera-instant-win-threshold' ); ?>" aria-label="<?php echo esc_attr__( 'Run draw', 'nera-instant-win-threshold' ); ?>"></span>
				</span>
			</div>
		</div>
	</td>
	<?php
}

add_action( 'lty_instant_winner_rule_column_data', 'nera_iwt_admin_rule_column_cell', 5, 2 );

/**
 * Admin: “Add rule” popup fields (moved to top of modal via JS).
 */
function nera_iwt_admin_popup_fields() {
	global $post;

	$product = ( $post instanceof WP_Post && 'product' === $post->post_type ) ? wc_get_product( $post->ID ) : null;
	if ( ! $product instanceof WC_Product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
		$product = null;
	}

	$labels = nera_iwt_public_rule_type_labels_for_admin_select( '', $product );
	?>
	<div class="nera-iwt-rule-visibility-popup-fields nera-iwt-rule-visibility-fields lty-instant-winner-rule-column">
		<p class="nera-iwt-popup-field-row">
			<label class="nera-iwt-popup-field-label"><b><?php esc_html_e( 'Rule type', 'nera-instant-win-threshold' ); ?></b></label>
			<select class="nera-iwt-public-rule-type">
				<?php foreach ( $labels as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php /* Do not add lty-instant-winner-rule-column here: LFW calls .show() on that class in the modal and would force these rows visible. */ ?>
		<p class="nera-iwt-popup-field-row nera-iwt-row-schedule nera-iwt-popup-conditional-row">
			<label class="nera-iwt-popup-field-label"><b><?php esc_html_e( 'Schedule at', 'nera-instant-win-threshold' ); ?></b></label>
			<input type="datetime-local" class="nera-iwt-schedule-at" value="" step="60" />
		</p>
		<p class="nera-iwt-popup-field-row nera-iwt-row-schedule nera-iwt-popup-conditional-row">
			<label class="nera-iwt-popup-field-label"><b><?php esc_html_e( 'Schedule End', 'nera-instant-win-threshold' ); ?></b></label>
			<span class="nera-iwt-schedule-end-field">
				<input type="datetime-local" class="nera-iwt-schedule-end" value="" step="60" />
				<button
					type="button"
					class="button button-small nera-iwt-schedule-end-clear"
					aria-label="<?php echo esc_attr__( 'Clear schedule end', 'nera-instant-win-threshold' ); ?>"
				>&times;</button>
			</span>
		</p>
		<p class="nera-iwt-popup-field-row nera-iwt-row-ticket-pct nera-iwt-popup-conditional-row">
			<label class="nera-iwt-popup-field-label"><b><?php esc_html_e( 'Ticket sold (%)', 'nera-instant-win-threshold' ); ?></b></label>
			<input type="number" min="0" max="100" step="1" inputmode="numeric" class="nera-iwt-ticket-pct" value="0" />
		</p>
	</div>
	<?php
}

add_action( 'lty_instant_winner_rule_popup_column_data', 'nera_iwt_admin_popup_fields', 1 );

/**
 * Enqueue admin script for modal + table behaviour and AJAX payload.
 *
 * @param string $hook_suffix Current admin page.
 */
function nera_iwt_admin_enqueue_rule_visibility( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}

	global $post;
	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}

	$js  = NERA_IWT_PLUGIN_DIR . 'assets/admin-rule-visibility.js';
	$css = NERA_IWT_PLUGIN_DIR . 'assets/admin-rule-visibility.css';
	if ( ! is_readable( $js ) ) {
		return;
	}

	wp_enqueue_style(
		'nera-iwt-admin-rule-visibility',
		plugins_url( 'assets/admin-rule-visibility.css', NERA_IWT_PLUGIN_FILE ),
		array( 'lty-admin' ),
		is_readable( $css ) ? (string) filemtime( $css ) : '1.0.0'
	);

	$js_deps = array( 'jquery' );
	if ( wp_script_is( 'wc-admin-product-meta-boxes', 'registered' ) ) {
		$js_deps[] = 'wc-admin-product-meta-boxes';
	}

	wp_enqueue_script(
		'nera-iwt-admin-rule-visibility',
		plugins_url( 'assets/admin-rule-visibility.js', NERA_IWT_PLUGIN_FILE ),
		$js_deps,
		(string) filemtime( $js ),
		true
	);

	$product      = wc_get_product( $post->ID );
	$is_lottery   = $product instanceof WC_Product && function_exists( 'lty_is_lottery_product' ) && lty_is_lottery_product( $product );
	$product_id   = $is_lottery ? (int) $product->get_id() : 0;
	$cap_js       = 0;
	if ( $is_lottery && function_exists( 'nera_iwt_get_configured_ticket_pool_max' ) ) {
		$cap_js = (int) nera_iwt_get_configured_ticket_pool_max( $product );
	}

	$l10n_package = array(
		'sequentialTicketConflictMsgSchedule' => nera_iwt_message_sequential_ticket_pattern_conflict( NERA_IWT_RULE_TYPE_SCHEDULE ),
		'sequentialTicketConflictMsgTicketPct' => nera_iwt_message_sequential_ticket_pattern_conflict( NERA_IWT_RULE_TYPE_TICKET_PCT ),
		'maxTicketNumberCap'          => $cap_js > 0 ? $cap_js : 0,
		'ticketRangeInvalidMsg'       => __( 'Ticket Number must be between {min} and {max} (inclusive).', 'nera-instant-win-threshold' ),
		'schedulePrizeTypeEnabled'    => nera_iwt_is_schedule_prize_type_enabled() ? 1 : 0,
		'productHasPctOrScheduleRules' => 0,
		'productTicketGenerationIsAutomatic' => 0,
		'ticketGenConflictMsg'        => '',
		'instantWinTicketRangeNote'   => '',
		'ajaxUrl'                     => admin_url( 'admin-ajax.php' ),
		'activateHeldNonce'           => wp_create_nonce( 'nera_iwt_activate_held' ),
		'activateHeldConfirmAuto'     => __( 'Activate this held-back prize on a system-picked unsold ticket number? The winning number stays hidden until a customer buys it.', 'nera-instant-win-threshold' ),
		'activateHeldConfirmTyped'    => __( 'Activate this held-back prize on the ticket number you entered? It must be an unsold ticket, and the number stays hidden from the public page.', 'nera-instant-win-threshold' ),
		'deactivateHeldConfirm'       => __( 'Deactivate this held-back prize and clear its ticket number? It will no longer be winnable until re-activated.', 'nera-instant-win-threshold' ),
		'runHeldDrawConfirm'          => __( 'Draw a random winner from the sold tickets for this held-back prize and award it now? This awards a real prize and cannot be undone.', 'nera-instant-win-threshold' ),
		'runHeldDrawDone'             => __( 'Winner drawn:', 'nera-instant-win-threshold' ),
		'gridLoading'                 => __( 'Loading tickets…', 'nera-instant-win-threshold' ),
		'gridTitle'                   => __( 'Set the winning ticket', 'nera-instant-win-threshold' ),
		'modalTicketLabel'            => __( 'Winning ticket number', 'nera-instant-win-threshold' ),
		'modalTicketPlaceholder'      => __( 'leave blank = system picks', 'nera-instant-win-threshold' ),
		'modalHint'                   => __( 'Leave blank to let the system pick a definitely-unsold number.', 'nera-instant-win-threshold' ),
		'modalGridLabel'              => __( 'Or pick from the grid:', 'nera-instant-win-threshold' ),
		'modalCancel'                 => __( 'Cancel', 'nera-instant-win-threshold' ),
		'modalActivate'               => __( 'Activate', 'nera-instant-win-threshold' ),
		'holdGroupButton'             => __( 'Hold all in group', 'nera-instant-win-threshold' ),
		'holdGroupConfirm'            => __( 'Set every prize in group “%s” to Held-back? You can still change individual prizes afterwards, then Save.', 'nera-instant-win-threshold' ),
		'holdGroupResult'             => __( '%d prize(s) set to Held-back — remember to Save.', 'nera-instant-win-threshold' ),
		'heldEnabled'                 => function_exists( 'nera_iwt_is_held_prize_type_enabled' ) && nera_iwt_is_held_prize_type_enabled() ? 1 : 0,
		'heldDrawEnabled'             => function_exists( 'nera_iwt_held_draw_enabled' ) && nera_iwt_held_draw_enabled() ? 'yes' : 'no',
		'heldSettings'                => array(
			'autoStored'  => $is_lottery ? (string) get_post_meta( $product_id, 'nera_iwt_held_autotrigger_pct', true ) : '',
			'warnStored'  => $is_lottery ? (string) get_post_meta( $product_id, 'nera_iwt_held_warn_pct', true ) : '',
			'autoDefault' => (int) ( defined( 'NERA_IWT_HELD_AUTOTRIGGER_PCT' ) ? NERA_IWT_HELD_AUTOTRIGGER_PCT : 90 ),
			'title'       => __( 'Held-back settings', 'nera-instant-win-threshold' ),
			'autoLabel'   => __( 'Auto-activate at % sold', 'nera-instant-win-threshold' ),
			'warnLabel'   => __( 'Warn at % sold', 'nera-instant-win-threshold' ),
			'note'        => sprintf(
				/* translators: %d: default auto-activation percent */
				__( 'Leave blank for the defaults — auto-activate at %1$d%% sold, warn 10%% below that. Saved when you click “Update”; warn %% must be below auto %%.', 'nera-instant-win-threshold' ),
				(int) ( defined( 'NERA_IWT_HELD_AUTOTRIGGER_PCT' ) ? NERA_IWT_HELD_AUTOTRIGGER_PCT : 90 )
			),
		),
		'heldGenericError'            => __( 'Sorry, that action could not be completed. Please try again.', 'nera-instant-win-threshold' ),
	);

	if ( $is_lottery && function_exists( 'nera_iwt_get_effective_ticket_start_for_validation' ) && function_exists( 'nera_iwt_get_instant_win_ticket_upper_bound' ) ) {
		$pool_min = nera_iwt_get_effective_ticket_start_for_validation( $product );
		$pool_max = nera_iwt_get_instant_win_ticket_upper_bound( $product );
		if ( $pool_max < $pool_min ) {
			$pool_max = $pool_min;
		}
		$l10n_package['instantWinTicketRangeNote'] = sprintf(
			/* translators: 1: minimum numeric ticket, 2: maximum numeric ticket */
			__( 'Numeric instant-win ticket numbers must fall within the ticket pool for this product (from the effective Ticket Starting Number through the Lottery Maximum Tickets cap). Current allowed numeric range for this product: %1$d–%2$d (inclusive).', 'nera-instant-win-threshold' ),
			$pool_min,
			$pool_max
		);
	}

	if ( $is_lottery && function_exists( 'nera_iwt_product_has_ticket_pct_or_schedule_rules' ) ) {
		$l10n_package['productHasPctOrScheduleRules'] = nera_iwt_product_has_ticket_pct_or_schedule_rules( $product_id ) ? 1 : 0;
	}
	if ( $is_lottery && function_exists( 'nera_iwt_product_has_automatic_ticket_generation' ) ) {
		$l10n_package['productTicketGenerationIsAutomatic'] = nera_iwt_product_has_automatic_ticket_generation( $product ) ? 1 : 0;
	}
	if ( function_exists( 'nera_iwt_message_ticket_generation_conflict_rules' ) ) {
		$l10n_package['ticketGenConflictMsg'] = nera_iwt_message_ticket_generation_conflict_rules( $product_id );
	}

	wp_localize_script(
		'nera-iwt-admin-rule-visibility',
		'neraIwtAdmin',
		$l10n_package
	);
}

add_action( 'admin_enqueue_scripts', 'nera_iwt_admin_enqueue_rule_visibility', 99 );

// ---------------------------------------------------------------------------
// REST-specific log-ID fetcher — schedule prizes included (client filters them).
// ---------------------------------------------------------------------------

/**
 * Log IDs for the instant-wins REST payload — full CMS list for the current relist.
 *
 * Schedule and ticket-sold-% metadata are attached per prize so the storefront can
 * present every configured rule; visibility styling is left to the theme/Vue layer.
 *
 * @param int $product_id WooCommerce product ID.
 * @return int[]
 */
function nera_iwt_get_rest_instant_winner_log_ids( $product_id ) {
	return nera_iwt_get_all_instant_winner_log_ids_for_product( $product_id );
}
